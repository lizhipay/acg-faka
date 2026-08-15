<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\Order;
use App\Model\Pay as PayModel;
use App\Model\UserRecharge;
use App\Service\Query;
use App\Util\Date;
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
    private const SAVE_FIELDS = [
        'name', 'icon', 'code', 'commodity', 'recharge', 'handle', 'sort', 'equipment', 'cost', 'cost_type',
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
            $map += ['commodity' => 0, 'recharge' => 0, 'sort' => 0, 'equipment' => 0, 'cost' => '0', 'cost_type' => 0];
        }

        if (!$existing || array_key_exists('code', $map)) {
            $effectiveHandle = (string)($map['handle'] ?? $existing?->handle ?? '');
            $effectiveCode = (string)($map['code'] ?? $existing?->code ?? '');
            $plugin = $this->pay->getPluginInfo($effectiveHandle);
            $options = $plugin['info']['options'] ?? null;
            if (!is_array($options) || !array_key_exists($effectiveCode, $options)) {
                throw new JSONException('支付插件不存在或不支持所选支付方式');
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

        $this->pay->savePluginConfig($id, $map);
        ManageLog::log($this->getManage(), "修改了支付插件({$id})的配置信息");
        return $this->json(200, '修改成功');
    }
}
