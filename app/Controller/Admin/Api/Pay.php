<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\PayEntity;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\Config as ConfigModel;
use App\Model\ManageLog;
use App\Model\Order;
use App\Model\Pay as PayModel;
use App\Model\UserRecharge;
use App\Service\Query;
use App\Util\Client;
use App\Util\Currency;
use App\Util\Date;
use App\Util\PayFactory;
use App\Util\PayProfile;
use App\Util\PayTest;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
class Pay extends Manage
{

    #[Inject]
    private \App\Service\Pay $pay;

    #[Inject]
    private Query $query;

    private const MAX_BATCH_COUNT = 100;
    //拨测会在网关那边产生真实待支付订单，金额封顶
    private const MAX_TEST_AMOUNT = 100;
    private const SAVE_FIELDS = [
        'name', 'icon', 'code', 'commodity', 'recharge', 'handle', 'pay_config_id', 'sort', 'equipment', 'cost', 'cost_type',
    ];

    /**
     * @param mixed $value
     * @return int[]
     * @throws JSONException
     */
    private function paymentIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $rawId) {
            if ($rawId === '' || $rawId === null) {
                continue;
            }
            if (!is_scalar($rawId) || !preg_match('/^\d+$/D', trim((string)$rawId))) {
                throw new JSONException('支付接口 ID 格式不正确');
            }
            $id = (int)$rawId;
            if ($id < 1 || $id > 4294967295) {
                throw new JSONException('支付接口 ID 超出有效范围');
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_BATCH_COUNT) {
            throw new JSONException('单次最多操作 ' . self::MAX_BATCH_COUNT . ' 个支付接口');
        }
        return $ids;
    }

    /**
     * @param mixed $value
     * @return int
     * @throws JSONException
     */
    private function paymentId(mixed $value): int
    {
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            return 0;
        }
        $ids = $this->paymentIds([$value]);
        return $ids[0] ?? 0;
    }

    /**
     * @param mixed $value
     * @param string $label
     * @param int $min
     * @param int $max
     * @return int
     * @throws JSONException
     */
    private function integerValue(mixed $value, string $label, int $min, int $max): int
    {
        if (!is_scalar($value) || !preg_match('/^-?\d+$/D', trim((string)$value))) {
            throw new JSONException("{$label}格式不正确");
        }
        $integer = (int)$value;
        if ($integer < $min || $integer > $max) {
            throw new JSONException("{$label}超出有效范围");
        }
        return $integer;
    }

    /**
     * @param mixed $value
     * @param string $label
     * @return string
     * @throws JSONException
     */
    private function scalarString(mixed $value, string $label): string
    {
        if (!is_scalar($value)) {
            throw new JSONException("{$label}格式不正确");
        }
        return trim((string)$value);
    }

    /**
     * @param array $raw
     * @param PayModel|null $existing
     * @return array
     * @throws JSONException
     */
    private function paymentSaveMap(array $raw, ?PayModel $existing): array
    {
        $allowed = array_merge(['id'], self::SAVE_FIELDS);
        foreach (array_keys($raw) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new JSONException('支付接口保存请求包含未授权字段');
            }
        }

        if ($existing && (int)$existing->id === 1) {
            throw new JSONException('系统内置余额接口无法修改');
        }

        if ($existing && array_key_exists('handle', $raw)) {
            $incomingHandle = $this->scalarString($raw['handle'], '支付插件');
            if ($incomingHandle !== (string)$existing->handle) {
                throw new JSONException('已有支付接口的所属插件不可更改');
            }
            unset($raw['handle']);
        }

        unset($raw['id']);
        $map = [];

        if (array_key_exists('name', $raw)) {
            $name = $this->scalarString($raw['name'], '支付名称');
            $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : preg_match_all('/./us', $name);
            if ($name === '' || $length === false || $length > 16 || preg_match('/[\x00-\x1F\x7F<>]/u', $name)) {
                throw new JSONException('支付名称必须是 1–16 个不含 HTML 的字符');
            }
            $map['name'] = $name;
        }

        if (array_key_exists('icon', $raw)) {
            $icon = $this->scalarString($raw['icon'], '支付图标');
            if (
                $icon === ''
                || strlen($icon) > 255
                || preg_match('/[\x00-\x20\x7F<>"\']/u', $icon)
                || str_starts_with($icon, '//')
                || !preg_match('~^(?:/|https?://)~i', $icon)
            ) {
                throw new JSONException('支付图标必须是站内绝对路径或 HTTP(S) 地址');
            }
            $map['icon'] = $icon;
        }

        if (array_key_exists('handle', $raw)) {
            $handle = $this->scalarString($raw['handle'], '支付插件');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $handle)) {
                throw new JSONException('支付插件标识不正确');
            }
            $map['handle'] = $handle;
        }

        if (array_key_exists('code', $raw)) {
            if (!is_scalar($raw['code'])) {
                throw new JSONException('支付方式格式不正确');
            }
            $code = (string)$raw['code'];
            if (trim($code) === '' || strlen($code) > 32 || preg_match('/[\x00-\x1F\x7F<>"\']/u', $code)) {
                throw new JSONException('支付方式代码不正确');
            }
            $map['code'] = $code;
        }

        foreach (['commodity' => '商品下单状态', 'recharge' => '余额充值状态', 'cost_type' => '手续费模式'] as $field => $label) {
            if (array_key_exists($field, $raw)) {
                $map[$field] = $this->integerValue($raw[$field], $label, 0, 1);
            }
        }
        if (array_key_exists('equipment', $raw)) {
            $map['equipment'] = $this->integerValue($raw['equipment'], '显示终端', 0, 2);
        }
        if (array_key_exists('sort', $raw)) {
            $map['sort'] = $this->integerValue($raw['sort'], '显示排序', 0, 65535);
        }

        if (array_key_exists('pay_config_id', $raw)) {
            $map['pay_config_id'] = $this->integerValue($raw['pay_config_id'], '支付配置', 0, 4294967295);
        }
        if (array_key_exists('cost', $raw)) {
            $cost = $this->scalarString($raw['cost'], '手续费');
            $cost = $cost === '' ? '0' : $cost;
            if (!preg_match('/^\d{1,7}(?:\.\d{1,3})?$/D', $cost) || (float)$cost > 9999999.999) {
                throw new JSONException('手续费必须是不超过 3 位小数的非负数');
            }
            $map['cost'] = $cost;
        }

        if (!$existing) {
            foreach (['name' => '支付名称', 'icon' => '支付图标', 'handle' => '支付插件', 'code' => '支付方式'] as $field => $label) {
                if (!array_key_exists($field, $map)) {
                    throw new JSONException("请填写{$label}");
                }
            }
            $map += ['commodity' => 0, 'recharge' => 0, 'sort' => 0, 'equipment' => 0, 'cost' => '0', 'cost_type' => 0, 'pay_config_id' => 0];
        }

        if (!$existing || array_key_exists('code', $map)) {
            $effectiveHandle = (string)($map['handle'] ?? $existing?->handle ?? '');
            $effectiveCode = (string)($map['code'] ?? $existing?->code ?? '');
            $plugin = $this->pay->getPluginInfo($effectiveHandle);
            $options = $plugin['info']['options'] ?? null;
            if (!is_array($options)) {
                throw new JSONException('支付插件不存在');
            }

            //允许填插件没声明的 code——有些网关的通道插件作者没列全，站长得能自己补。
            //这里不再按"能不能当文件名"去卡它：自定义 code 走的是跳转支付，
            //只是发给网关的一个参数。本地收银台那条路的路径安全由
            //PayConfig::renderTemplate() 自己把关（它会拒掉一切不安全的 code）。
        }

        //支付配置：必须存在，且必须属于这个插件——否则就是拿别家的商户凭据去收款
        if (array_key_exists('pay_config_id', $map)) {
            $effectiveHandle = (string)($map['handle'] ?? $existing?->handle ?? '');
            $configId = (int)$map['pay_config_id'];
            if ($configId <= 0) {
                throw new JSONException('请选择支付配置');
            }
            if (!PayProfile::exists($effectiveHandle, $configId)) {
                throw new JSONException('支付配置不存在或不属于所选插件');
            }
        }

        $effectiveCostType = (int)($map['cost_type'] ?? $existing?->cost_type ?? 0);
        $effectiveCost = (float)($map['cost'] ?? $existing?->cost ?? 0);
        if ($effectiveCostType === 1 && $effectiveCost > 1) {
            throw new JSONException('百分比手续费请使用 0–1 之间的小数');
        }

        //归档接口不允许重新启用（避免出现"前台可用但列表看不见"的悬空状态），先恢复再启用
        if ($existing && (int)$existing->archived === 1 && ((int)($map['commodity'] ?? 0) === 1 || (int)($map['recharge'] ?? 0) === 1)) {
            throw new JSONException('该接口已归档，请先在「已归档」列表中恢复后再启用');
        }
        if ($map === []) {
            throw new JSONException('没有可保存的支付接口字段');
        }
        return $map;
    }

    /**
     * 删除影响分析：按"有无历史引用"把所选接口分成两组——无引用的物理删除、
     * 有引用的转为归档（issue #789：历史引用只应保住订单展示，不应把弃用接口永远钉在列表里）。
     * @param int[] $requestedIds
     * @param bool $lock
     * @return array
     * @throws JSONException
     */
    private function paymentDeleteImpact(array $requestedIds, bool $lock = false): array
    {
        if ($requestedIds === []) {
            throw new JSONException('你还没有选择支付接口');
        }

        $paymentQuery = PayModel::query()
            ->whereIn('id', $requestedIds)
            ->orderBy('id')
            ->select(['id', 'name', 'commodity', 'recharge', 'archived']);
        if ($lock) {
            $paymentQuery->lockForUpdate();
        }
        $payments = $paymentQuery->get();
        $paymentIds = $payments->pluck('id')->map(static fn($id): int => (int)$id)->all();

        if ($lock && $paymentIds !== []) {
            // The pay_id indexes make these point/range locks inexpensive and
            // prevent the reference set from changing while delete is decided.
            foreach ($paymentIds as $paymentId) {
                Order::query()->where('pay_id', $paymentId)->select('id')->lockForUpdate()->first();
                UserRecharge::query()->where('pay_id', $paymentId)->select('id')->lockForUpdate()->first();
            }
        }

        //按接口统计引用（每表一条 GROUP BY，SUM(status=1) 顺带拿已支付数）
        $orderStats = $paymentIds === [] ? collect() : Order::query()
            ->whereIn('pay_id', $paymentIds)
            ->selectRaw('pay_id, COUNT(*) as total, SUM(status = 1) as paid')
            ->groupBy('pay_id')
            ->get()
            ->keyBy('pay_id');
        $rechargeStats = $paymentIds === [] ? collect() : UserRecharge::query()
            ->whereIn('pay_id', $paymentIds)
            ->selectRaw('pay_id, COUNT(*) as total, SUM(status = 1) as paid')
            ->groupBy('pay_id')
            ->get()
            ->keyBy('pay_id');

        $orderCount = 0;
        $paidOrderCount = 0;
        $rechargeCount = 0;
        $paidRechargeCount = 0;
        $deleteIds = [];
        $deleteNames = [];
        $archiveIds = [];
        $archiveNames = [];
        $alreadyArchivedCount = 0;

        foreach ($payments as $payment) {
            $paymentId = (int)$payment->id;
            $orders = (int)($orderStats->get($paymentId)?->total ?? 0);
            $recharges = (int)($rechargeStats->get($paymentId)?->total ?? 0);
            $orderCount += $orders;
            $paidOrderCount += (int)($orderStats->get($paymentId)?->paid ?? 0);
            $rechargeCount += $recharges;
            $paidRechargeCount += (int)($rechargeStats->get($paymentId)?->paid ?? 0);

            if ($paymentId === 1 || (int)$payment->commodity === 1 || (int)$payment->recharge === 1) {
                continue; //内置/仍启用的接口整体阻断，不参与分组
            }
            if ($orders === 0 && $recharges === 0) {
                $deleteIds[] = $paymentId;
                $deleteNames[] = (string)$payment->name;
            } elseif ((int)$payment->archived === 1) {
                $alreadyArchivedCount++;
            } else {
                $archiveIds[] = $paymentId;
                $archiveNames[] = (string)$payment->name;
            }
        }

        $builtInCount = $payments->filter(static fn($payment): bool => (int)$payment->id === 1)->count();
        $commodityEnabledCount = $payments->filter(static fn($payment): bool => (int)$payment->commodity === 1)->count();
        $rechargeEnabledCount = $payments->filter(static fn($payment): bool => (int)$payment->recharge === 1)->count();
        $missingCount = count($requestedIds) - count($paymentIds);

        return [
            'delete_ids' => $deleteIds,
            'archive_ids' => $archiveIds,
            'requested_count' => count($requestedIds),
            'payment_count' => count($paymentIds),
            'missing_count' => $missingCount,
            'names' => $payments->pluck('name')->take(5)->map(static fn($name): string => (string)$name)->all(),
            'built_in_count' => $builtInCount,
            'order_count' => $orderCount,
            'paid_order_count' => $paidOrderCount,
            'pending_order_count' => $orderCount - $paidOrderCount,
            'recharge_count' => $rechargeCount,
            'paid_recharge_count' => $paidRechargeCount,
            'pending_recharge_count' => $rechargeCount - $paidRechargeCount,
            'commodity_enabled_count' => $commodityEnabledCount,
            'recharge_enabled_count' => $rechargeEnabledCount,
            'delete_count' => count($deleteIds),
            'delete_names' => array_slice($deleteNames, 0, 5),
            'archive_count' => count($archiveIds),
            'archive_names' => array_slice($archiveNames, 0, 5),
            'already_archived_count' => $alreadyArchivedCount,
            'can_proceed' => $missingCount === 0
                && $builtInCount === 0
                && $commodityEnabledCount === 0
                && $rechargeEnabledCount === 0
                && (count($deleteIds) + count($archiveIds)) > 0,
        ];
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        //归档接口默认不出现在列表；「已归档」筛选显式传 equal-archived=1 时才展示
        if (($map['equal-archived'] ?? '') === '') {
            $map['equal-archived'] = 0;
        }
        $get = new Get(\App\Model\Pay::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));
        $data = $this->query->get($get);

        //支付接口名是站长自己填的，前台结账页已走 i18n，这里一并翻译保持两端一致
        if (isset($data['list']) && is_array($data['list'])) {
            $data['list'] = \Kernel\Util\Lang::transList($data['list'], ['name']);
        }

        return $this->json(data: $data);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $raw = (array)$request->post(flags: Filter::NORMAL);
        $id = $this->paymentId($raw['id'] ?? null);
        $created = $id === 0;

        $payment = DB::transaction(function () use ($raw, $id, $created): PayModel {
            $payment = $created
                ? new PayModel()
                : PayModel::query()->lockForUpdate()->find($id);
            if (!$payment) {
                throw new JSONException('支付接口不存在');
            }

            $map = $this->paymentSaveMap($raw, $created ? null : $payment);
            foreach ($map as $field => $value) {
                $payment->$field = $value;
            }
            if ($created) {
                $payment->create_time = Date::current();
            }
            if (!$payment->save()) {
                throw new JSONException('保存失败，请检查信息填写是否完整');
            }
            return $payment;
        });

        $action = $created ? '新增' : '修改';
        ManageLog::log($this->getManage(), "[{$action}]支付接口 ID：{$payment->id}");
        return $this->json(200, '（＾∀＾）保存成功', ['id' => (int)$payment->id]);
    }


    /**
     * 移除支付接口：无历史引用的物理删除，有历史引用的转为归档（保住历史订单的支付方式展示）。
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $requestedIds = $this->paymentIds($_POST['list'] ?? []);
        $impact = DB::transaction(function () use ($requestedIds): array {
            $impact = $this->paymentDeleteImpact($requestedIds, true);
            if (!$impact['can_proceed']) {
                if ($impact['already_archived_count'] > 0 && $impact['missing_count'] === 0 && $impact['built_in_count'] === 0
                    && $impact['commodity_enabled_count'] === 0 && $impact['recharge_enabled_count'] === 0) {
                    throw new JSONException('所选接口均已归档，无需重复操作');
                }
                throw new JSONException(
                    "已阻止操作：内置接口 {$impact['built_in_count']} 个、不存在 {$impact['missing_count']} 个、" .
                    "仍启用商品下单 {$impact['commodity_enabled_count']} 个、仍启用余额充值 {$impact['recharge_enabled_count']} 个。" .
                    '请先停用接口再移除。'
                );
            }

            $deleted = 0;
            $archived = 0;
            if ($impact['delete_ids'] !== []) {
                $deleted = PayModel::query()
                    ->whereIn('id', $impact['delete_ids'])
                    ->where('id', '!=', 1)
                    ->where('commodity', 0)
                    ->where('recharge', 0)
                    ->delete();
                if ($deleted !== count($impact['delete_ids'])) {
                    throw new JSONException('支付接口状态或历史引用已变化，未执行操作，请重新预览');
                }
            }
            if ($impact['archive_ids'] !== []) {
                $archived = PayModel::query()
                    ->whereIn('id', $impact['archive_ids'])
                    ->where('id', '!=', 1)
                    ->where('commodity', 0)
                    ->where('recharge', 0)
                    ->where('archived', 0)
                    ->update(['archived' => 1]);
                if ($archived !== count($impact['archive_ids'])) {
                    throw new JSONException('支付接口状态或历史引用已变化，未执行操作，请重新预览');
                }
            }
            return $impact + ['deleted' => $deleted, 'archived' => $archived];
        });

        ManageLog::log($this->getManage(), "[移除]支付接口：物理删除 {$impact['deleted']} 个、归档 {$impact['archived']} 个");
        $parts = [];
        if ($impact['deleted'] > 0) {
            $parts[] = "删除 {$impact['deleted']} 个";
        }
        if ($impact['archived'] > 0) {
            $parts[] = "归档 {$impact['archived']} 个";
        }
        return $this->json(200, '（＾∀＾）已' . implode('、', $parts), [
            'deleted' => $impact['deleted'],
            'archived' => $impact['archived'],
        ]);
    }

    /**
     * 恢复归档的支付接口（恢复后仍处于停用状态，需手动启用）。
     * @return array
     * @throws JSONException
     */
    public function restore(): array
    {
        $ids = $this->paymentIds($_POST['list'] ?? []);
        if ($ids === []) {
            throw new JSONException('你还没有选择支付接口');
        }
        $count = PayModel::query()->whereIn('id', $ids)->where('archived', 1)->update(['archived' => 0]);
        ManageLog::log($this->getManage(), "[恢复]归档支付接口，共计：{$count}");
        return $this->json(200, '（＾∀＾）已恢复，接口目前处于停用状态，可重新启用', ['count' => $count]);
    }

    /**
     * Read-only impact preview required before an irreversible payment delete.
     * @return array
     * @throws JSONException
     */
    public function deleteImpact(): array
    {
        $impact = $this->paymentDeleteImpact($this->paymentIds($_POST['list'] ?? []));
        unset($impact['delete_ids'], $impact['archive_ids']);
        return $this->json(data: $impact);
    }

    /**
     * 获取插件列表
     * @return array
     */
    public function getPlugins(): array
    {
        $plugins = $this->pay->getPlugins();
        $appStore = (array)json_decode((string)file_get_contents(BASE_PATH . "/runtime/plugin/store.cache"), true);
        foreach ($plugins as $index => $plugin) {
            if (!array_key_exists($plugin["id"], $appStore)) {
                $plugins[$index]['icon'] = "/favicon.ico";
            } else {
                $plugins[$index]['icon'] = \App\Service\App::APP_URL . $appStore[$plugin["id"]]['icon'];
                if ($plugin['info']['version'] !== $appStore[$plugin['id']]["version"]) {
                    $plugins[$index]['have_update'] = true;
                }
            }
        }

        $plugins = array_values($plugins);

        usort($plugins, function ($a, $b) {
            $aTop = ($a['config']['top'] ?? 0) == 1 ? 1 : 0;
            $bTop = ($b['config']['top'] ?? 0) == 1 ? 1 : 0;
            return $bTop <=> $aTop;
        });

        usort($plugins, function ($a, $b) {
            return ($b['have_update'] ?? false) <=> ($a['have_update'] ?? false);
        });

        //支付插件的名称/简介/功能项来自各插件 Config/Info.php，属动态文案
        $plugins = \Kernel\Util\Lang::transList($plugins, [
            'info.name', 'info.description', 'info.options',
        ]);

        return $this->json(data: ["list" => $plugins]);
    }

    /**
     * 获取插件日志
     * @param string $handle
     * @return array
     */
    public function getPluginLog(string $handle): array
    {
        $pluginLog = $this->pay->getPluginLog($handle);
        return $this->json(200, 'success', ['log' => $pluginLog]);
    }

    /**
     * @param string $handle
     * @return array
     */
    public function ClearPluginLog(string $handle): array
    {
        if (!$this->pay->ClearPluginLog($handle)) {
            throw new JSONException('支付插件日志清理失败');
        }
        ManageLog::log($this->getManage(), "清空了支付插件({$handle})的日志");
        return $this->json(200, 'success');
    }

    /**
     * @throws JSONException
     */
    public function setPluginConfig(Request $request): array
    {
        $map = (array)$request->post(flags: Filter::NORMAL);
        $id = $request->get("id") ?: $request->post("id");
        if (!is_scalar($id) || trim((string)$id) === '') {
            throw new JSONException("插件不存在");
        }
        $id = trim((string)$id);

        if (isset($map['id'])) {
            unset($map['id']);
        }

        //指定写哪一套配置；不传就写默认配置档，保持插件列表页那个「配置」按钮的老行为
        $configId = $request->get("config_id") ?: $request->post("config_id");
        $configId = is_scalar($configId) && preg_match('/^\d+$/D', trim((string)$configId))
            ? (int)$configId
            : null;
        if (isset($map['config_id'])) {
            unset($map['config_id']);
        }

        $this->pay->savePluginConfig($id, $map, $configId);
        ManageLog::log($this->getManage(), "修改了支付插件({$id})的配置信息" . ($configId ? "[配置档#{$configId}]" : ''));
        return $this->json(200, '修改成功');
    }

    /**
     * 支付接口拨测：拿一个虚构订单号去真正调一次插件的 trade()，看配置对不对、网关通不通。
     *
     * 刻意不写订单表——拨测单混进真实订单里会污染统计和商品订单列表。代价是这笔单在本站
     * 没有对应订单，所以就算真付了钱，回调也会以"订单不存在"被拒，不会自动到账。
     * 它验证的是"下单这一步能不能走通"：密钥对不对、签名算法对不对、网关能不能连上。
     *
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function test(Request $request): array
    {
        $pay = PayModel::query()->find($this->paymentId($request->post("id")));

        if (!$pay) {
            throw new JSONException('支付接口不存在');
        }
        if ((int)$pay->id === 1 || (string)$pay->handle === '#system') {
            throw new JSONException('余额支付不经过第三方网关，无需拨测');
        }
        if ((int)$pay->archived === 1) {
            throw new JSONException('已归档的接口无法拨测，请先恢复');
        }

        $tradeNo = $this->scalarString($request->post("trade_no"), '订单号');
        if (!preg_match('/^[A-Za-z0-9_-]{6,32}$/D', $tradeNo)) {
            throw new JSONException('订单号请用 6-32 位的字母、数字、下划线或短横线');
        }

        $amount = $this->scalarString($request->post("amount"), '金额');
        if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $amount) || (float)$amount <= 0) {
            throw new JSONException('金额必须是大于 0、最多两位小数的数字');
        }
        //拨测会在网关那边生成一笔真实待支付订单，金额封顶，免得手滑打出个大数
        if ((float)$amount > self::MAX_TEST_AMOUNT) {
            throw new JSONException('拨测金额请控制在 ' . self::MAX_TEST_AMOUNT . ' 以内');
        }

        $clientDomain = Client::getUrl();
        $callbackDomain = trim((string)ConfigModel::get("callback_domain"), "/") ?: $clientDomain;

        //与真实下单同源：优先用后台配的自定义回调域名，没配才用当前访问域名
        $callbackUrl = $callbackDomain . '/user/api/order/callbackTest.' . $tradeNo;

        //站点货币 → CNY：与真实下单同一套换算，拨测面板因此也是验证汇率换算的窗口
        $gatewayAmount = sprintf('%.2f', Currency::toCny($amount));

        //先落一条待支付记录，回调回来才有地方落款、界面才能轮询到状态
        PayTest::put($tradeNo, [
            'pay_id' => (int)$pay->id,
            'pay_name' => (string)$pay->name,
            'handle' => (string)$pay->handle,
            'amount' => $amount,
            'gateway_amount' => $gatewayAmount,
            'status' => 'pending',
            'create_time' => Date::current()
        ]);

        try {
            $payObject = PayFactory::make(
                $pay,
                $tradeNo,
                (float)$gatewayAmount,
                //拨测走自己的回调地址，绝不借用真实回调——那条路上挂着发货和加余额。
                //取名 callbackTest 是为了让 Turnstile 的 'user/api/order/callback' 前缀豁免自动覆盖到它。
                $callbackUrl,
                $clientDomain . '/user/index/query?tradeNo=' . $tradeNo,
                Client::getAddress()
            );
            $trade = $payObject->trade();
        } catch (\Throwable $e) {
            PayTest::patch($tradeNo, ['status' => 'trade_failed', 'message' => $e->getMessage()]);
            //插件里什么异常都可能冒出来（网络超时、签名报错、SDK 抛错），
            //统统转成能看懂的文案回给后台，这本来就是个诊断工具
            ManageLog::log($this->getManage(), "拨测支付接口({$pay->name})失败：" . $e->getMessage());
            throw new JSONException('拨测失败：' . $e->getMessage());
        }

        if (!$trade instanceof PayEntity) {
            throw new JSONException('插件没有返回有效的支付信息，可能未正确实现接口');
        }

        PayTest::patch($tradeNo, ['status' => 'waiting', 'pay_url' => $trade->getUrl(), 'pay_type' => $trade->getType()]);
        ManageLog::log($this->getManage(), "拨测了支付接口({$pay->name})，订单号 {$tradeNo}，金额 {$amount}" . ($gatewayAmount !== sprintf('%.2f', (float)$amount) ? "（提交网关 CNY {$gatewayAmount}）" : ""));

        return $this->json(200, '拨测成功', [
            'trade_no' => $tradeNo,
            'amount' => $amount,
            'gateway_amount' => $gatewayAmount,
            'type' => $trade->getType(),
            'url' => $trade->getUrl(),
            'option' => $trade->getOption(),
            'pay_name' => (string)$pay->name,
            'callback_url' => $callbackUrl,
            //回调 IP 白名单是"一直等待支付中"最常见的原因：网关的回调会在
            //CallbackIpWhitelist::enforce() 那一步就被 403 掉，压根到不了业务代码。
            //把它挑明在界面上，省得站长对着转圈的状态条干等。
            'ip_whitelist' => (string)ConfigModel::get(\App\Util\CallbackIpWhitelist::ENABLED_CONFIG) === '1'
        ]);
    }

    /**
     * 拨测状态轮询：拨测单不进订单表，状态记在 runtime 下的临时文件里。
     *
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function testState(Request $request): array
    {
        $tradeNo = $this->scalarString($request->post("trade_no"), '订单号');
        if (!PayTest::isValidTradeNo($tradeNo)) {
            throw new JSONException('订单号格式不正确');
        }

        $record = PayTest::get($tradeNo);
        if ($record === null) {
            //一小时后自动过期，前端据此停止轮询
            return $this->json(200, 'success', ['status' => 'expired']);
        }

        return $this->json(200, 'success', [
            'status' => (string)($record['status'] ?? 'pending'),
            'message' => (string)($record['message'] ?? ''),
            'paid_amount' => (string)($record['paid_amount'] ?? ''),
            'pay_time' => (string)($record['pay_time'] ?? '')
        ]);
    }

    /**
     * 某支付插件的全部配置档
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function getPluginConfigs(Request $request): array
    {
        return $this->json(200, 'success', $this->pay->listPluginConfigs($this->pluginHandle($request)));
    }

    /**
     * 新建一套配置
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function createPluginConfig(Request $request): array
    {
        $handle = $this->pluginHandle($request);
        $name = $this->scalarString($request->post("name"), '配置名称');
        $id = $this->pay->createPluginConfig($handle, $name);
        ManageLog::log($this->getManage(), "为支付插件({$handle})新增了配置档[{$name}]");
        return $this->json(200, '添加成功', ['id' => $id]);
    }

    /**
     * 配置档改名
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function renamePluginConfig(Request $request): array
    {
        $handle = $this->pluginHandle($request);
        $id = $this->integerValue($request->post("config_id"), '支付配置', 1, 4294967295);
        $name = $this->scalarString($request->post("name"), '配置名称');
        $this->pay->renamePluginConfig($handle, $id, $name);
        ManageLog::log($this->getManage(), "把支付插件({$handle})的配置档#{$id}改名为[{$name}]");
        return $this->json(200, '修改成功');
    }

    /**
     * 删除一套配置
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function delPluginConfig(Request $request): array
    {
        $handle = $this->pluginHandle($request);
        $id = $this->integerValue($request->post("config_id"), '支付配置', 1, 4294967295);
        $this->pay->deletePluginConfig($handle, $id);
        ManageLog::log($this->getManage(), "删除了支付插件({$handle})的配置档#{$id}");
        return $this->json(200, '删除成功');
    }

    /**
     * 从请求里取插件名
     * @param Request $request
     * @return string
     * @throws JSONException
     */
    private function pluginHandle(Request $request): string
    {
        $handle = $request->post("handle") ?: $request->get("handle") ?: $request->get("id");
        if (!is_scalar($handle) || trim((string)$handle) === '') {
            throw new JSONException("插件不存在");
        }
        return trim((string)$handle);
    }
}
