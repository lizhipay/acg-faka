<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Consts\Manage as ManageConst;
use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Order extends Manage
{
    #[Inject]
    private Query $query;

    private const MAX_EXPORT_COUNT = 5000;
    private const EXPORT_PREVIEW_TTL = 180;

    /**
     * @param array<string,mixed> $raw
     * @return array{export_num:int,export_status:int}
     * @throws JSONException
     */
    private function exportOptions(array $raw): array
    {
        $rawExportNum = $raw['export_num'] ?? 0;
        $exportNum = ($rawExportNum === '' || $rawExportNum === null)
            ? 0
            : filter_var($rawExportNum, FILTER_VALIDATE_INT);
        if ($exportNum === false || $exportNum < 0 || $exportNum > self::MAX_EXPORT_COUNT) {
            throw new JSONException('导出数量必须是 0 到 ' . self::MAX_EXPORT_COUNT . ' 的整数');
        }

        $exportStatus = filter_var($raw['export_status'] ?? 0, FILTER_VALIDATE_INT);
        if ($exportStatus === false || !in_array($exportStatus, [0, 1], true)) {
            throw new JSONException('导出后操作不正确');
        }

        return [
            'export_num' => (int)$exportNum,
            'export_status' => (int)$exportStatus,
        ];
    }

    /**
     * Build the exact, allow-listed filter scope shared by preview and export.
     * Unknown filter fields are rejected instead of being silently ignored and
     * accidentally broadening a destructive export.
     *
     * @param array<string,mixed> $raw
     * @return array{0:Builder,1:bool}
     * @throws JSONException
     */
    private function orderExportQuery(array $raw): array
    {
        $query = \App\Model\Order::query();
        $hasFilter = false;
        $handled = [];

        $stringFilters = [
            'equal-trade_no' => ['trade_no', '=', 128],
            'search-secret' => ['secret', 'like', 255],
            'equal-contact' => ['contact', '=', 255],
            'equal-create_ip' => ['create_ip', '=', 64],
        ];
        foreach ($stringFilters as $key => [$column, $operator, $maxLength]) {
            $handled[] = $key;
            $value = trim((string)($raw[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $maxLength) {
                throw new JSONException('订单导出筛选内容过长');
            }
            $hasFilter = true;
            $query->where($column, $operator, $operator === 'like' ? '%' . $value . '%' : $value);
        }

        $integerFilters = [
            'equal-commodity_id' => ['commodity_id', 0, null],
            'equal-status' => ['status', 0, 1],
            'equal-delivery_status' => ['delivery_status', 0, 1],
            'equal-pay_id' => ['pay_id', 0, null],
            'equal-create_device' => ['create_device', 0, 3],
            'equal-owner' => ['owner', 0, null],
            'equal-user_id' => ['user_id', 0, null],
        ];
        foreach ($integerFilters as $key => [$column, $minimum, $maximum]) {
            $handled[] = $key;
            $value = $raw[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < $minimum || ($maximum !== null && $integer > $maximum)) {
                throw new JSONException('订单导出筛选条件不正确');
            }
            $hasFilter = true;
            $query->where($column, $integer);
        }

        $extensionFilters = [
            'equal-dock_status' => ['dock_status', 0, 4],
            'equal-dock_order_status' => ['dock_order_status', 0, 6],
        ];
        foreach ($extensionFilters as $key => [$column, $minimum, $maximum]) {
            $handled[] = $key;
            $value = $raw[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if (!DB::schema()->hasColumn('order', $column)) {
                throw new JSONException('当前扩展筛选条件不可用，请刷新页面后重试');
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < $minimum || $integer > $maximum) {
                throw new JSONException('订单扩展筛选条件不正确');
            }
            $hasFilter = true;
            $query->where($column, $integer);
        }

        $dateValues = [];
        foreach (['betweenStart-create_time' => '>=', 'betweenEnd-create_time' => '<='] as $key => $operator) {
            $handled[] = $key;
            $value = trim((string)($raw[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                throw new JSONException('订单下单时间筛选不正确');
            }
            $dateValues[$key] = $timestamp;
            $hasFilter = true;
            $query->where('create_time', $operator, $value);
        }
        if (isset($dateValues['betweenStart-create_time'], $dateValues['betweenEnd-create_time'])
            && $dateValues['betweenStart-create_time'] > $dateValues['betweenEnd-create_time']) {
            throw new JSONException('订单下单时间起点不能晚于终点');
        }

        foreach ($raw as $key => $value) {
            if ((is_scalar($value) && trim((string)$value) === '') || $value === null) {
                continue;
            }
            if (preg_match('/^(?:equal|search|betweenStart|betweenEnd)-/', (string)$key)
                && !in_array((string)$key, $handled, true)) {
                throw new JSONException('当前导出不支持页面中的某个筛选条件，请清除该条件后重试');
            }
        }

        return [$query, $hasFilter];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array{export_num:int,export_status:int} $options
     * @return array{rows:mixed,total:int,count:int,has_filter:bool}
     * @throws JSONException
     */
    private function orderExportSelection(array $raw, array $options, bool $withRelations = false, bool $lock = false): array
    {
        [$query, $hasFilter] = $this->orderExportQuery($raw);
        $total = (int)(clone $query)->count();
        if ($total === 0) {
            throw new JSONException('当前筛选没有可导出的订单');
        }

        $count = $options['export_num'] > 0 ? min($options['export_num'], $total) : $total;
        if ($count > self::MAX_EXPORT_COUNT) {
            throw new JSONException('当前范围过大，请增加筛选或填写不超过 ' . self::MAX_EXPORT_COUNT . ' 的导出数量');
        }

        $selection = (clone $query)->orderByDesc('id')->limit($count);
        if ($lock) {
            $selection->lockForUpdate();
        }
        if ($withRelations) {
            $selection->with([
                'coupon' => static function (Relation $relation) {
                    $relation->select(['id', 'code']);
                },
                'owner' => static function (Relation $relation) {
                    $relation->select(['id', 'username']);
                },
                'user' => static function (Relation $relation) {
                    $relation->select(['id', 'username']);
                },
                'commodity' => static function (Relation $relation) {
                    $relation->select(['id', 'name']);
                },
                'pay' => static function (Relation $relation) {
                    $relation->select(['id', 'name']);
                },
                'promote' => static function (Relation $relation) {
                    $relation->select(['id', 'username']);
                },
                'substationUser' => static function (Relation $relation) {
                    $relation->select(['id', 'username']);
                },
            ]);
        } else {
            $selection->select(['id', 'status', 'delivery_status']);
        }

        $rows = $selection->get();
        if ($rows->count() !== $count) {
            throw new JSONException('订单数据已变化，请重新预览导出范围');
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'count' => $count,
            'has_filter' => $hasFilter,
        ];
    }

    /** @param int[] $ids */
    private function orderExportFingerprint(array $ids): string
    {
        return hash('sha256', implode(',', $ids));
    }

    private function orderExportTokenKey(): string
    {
        $manage = $this->getManage();
        if (!$manage) {
            throw new JSONException('管理员会话已失效，请刷新后重试');
        }
        return hash('sha256', 'order-export-preview-v1|' . (string)$manage->password, true);
    }

    /** @param int[] $ids @param array{export_num:int,export_status:int} $options */
    private function issueOrderExportToken(array $ids, array $options): string
    {
        $now = time();
        $payload = [
            'fingerprint' => $this->orderExportFingerprint($ids),
            'count' => count($ids),
            'export_num' => $options['export_num'],
            'export_status' => $options['export_status'],
            'manage_id' => (int)($this->getManage()?->id ?? 0),
            'session' => hash('sha256', (string)($_COOKIE[ManageConst::SESSION] ?? '')),
            'iat' => $now,
            'exp' => $now + self::EXPORT_PREVIEW_TTL,
        ];
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new JSONException('无法生成订单导出预览凭证');
        }
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, $this->orderExportTokenKey());
    }

    /** @param int[] $ids @param array{export_num:int,export_status:int} $options */
    private function verifyOrderExportToken(mixed $token, array $ids, array $options): void
    {
        if (!is_string($token) || $token === '' || substr_count($token, '.') !== 1) {
            throw new JSONException('请先预览并确认订单导出范围');
        }
        [$body, $signature] = explode('.', $token, 2);
        $expectedSignature = hash_hmac('sha256', $body, $this->orderExportTokenKey());
        if (!preg_match('/^[a-f0-9]{64}$/D', $signature) || !hash_equals($expectedSignature, $signature)) {
            throw new JSONException('订单导出预览凭证无效，请重新预览');
        }

        $encoded = strtr($body, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new JSONException('订单导出预览凭证无效，请重新预览');
        }
        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new JSONException('订单导出预览凭证无效，请重新预览');
        }
        if (!is_array($payload)
            || (int)($payload['iat'] ?? 0) > time() + 30
            || (int)($payload['exp'] ?? 0) < time()
            || (int)($payload['exp'] ?? 0) > (int)($payload['iat'] ?? 0) + self::EXPORT_PREVIEW_TTL
            || (int)($payload['manage_id'] ?? 0) !== (int)($this->getManage()?->id ?? 0)
            || !hash_equals((string)($payload['session'] ?? ''), hash('sha256', (string)($_COOKIE[ManageConst::SESSION] ?? '')))
            || (int)($payload['count'] ?? 0) !== count($ids)
            || (int)($payload['export_num'] ?? -1) !== $options['export_num']
            || (int)($payload['export_status'] ?? -1) !== $options['export_status']
            || !hash_equals((string)($payload['fingerprint'] ?? ''), $this->orderExportFingerprint($ids))) {
            throw new JSONException('订单导出范围或数据已变化，请重新预览');
        }
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\Order::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $raw = [];
        $data = $this->query->get($get, function (Builder $builder) use (&$raw) {
            $raw['order_amount'] = (clone $builder)->sum("amount");
            $raw['order_cost'] = (clone $builder)->sum("cost");
            return $builder->with([
                'coupon' => function (Relation $relation) {
                    $relation->select(["id", "code"]);
                },
                'owner' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'user' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "name", "cover", "price", "delivery_way", "contact_type"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                },
                //推广者
                'promote' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                //分站订单
                'substationUser' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'card'
            ]);
        });

        return $this->json(data: array_merge($data, $raw));
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $id = filter_var($map['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new JSONException('订单 ID 不正确，请刷新后重试');
        }
        $secret = (string)($map['secret'] ?? '');
        $normalizedSecret = trim($secret);
        if ($normalizedSecret === '' || $normalizedSecret === '0') {
            throw new JSONException('请填写有效的发货内容，不能仅为空白或“0”');
        }
        $overwriteConfirmed = filter_var(
            $map['overwrite_confirmed'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) === true;

        DB::transaction(function () use ($id, $secret, $overwriteConfirmed): void {
            $order = \App\Model\Order::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (!$order) {
                throw new JSONException('订单不存在，请刷新后重试');
            }
            if ((int)$order->status !== 1) {
                throw new JSONException('仅已支付订单可以手动发货');
            }

            $commodity = \App\Model\Commodity::query()
                ->where('id', (int)$order->commodity_id)
                ->lockForUpdate()
                ->first(['id', 'delivery_way']);
            if (!$commodity) {
                throw new JSONException('订单对应商品不存在，无法手动发货');
            }
            if ((int)$commodity->delivery_way !== 1) {
                throw new JSONException('该订单不是手动发货商品，不能修改发货内容');
            }

            $hasExistingDelivery = (int)$order->delivery_status === 1
                || trim((string)$order->secret) !== '';
            if ($hasExistingDelivery && !$overwriteConfirmed) {
                throw new JSONException('此订单已有发货记录，请明确确认覆盖后重试');
            }

            $order->secret = $secret;
            $order->delivery_status = 1;
            if (!$order->save()) {
                throw new JSONException('发货失败，请重试');
            }
        });

        ManageLog::log($this->getManage(), "[手动发货]({$id})修改了发货信息");
        return $this->json(200, '（＾∀＾）发货成功');
    }


    /**
     * @return array
     */
    public function clear(): array
    {
        \App\Model\Order::query()
            ->where("create_time", "<", date("Y-m-d H:i:s", time() - 1800))
            ->where("status", 0)->delete();

        ManageLog::log($this->getManage(), "进行了一键清理无用商品订单操作");
        return $this->json(200, '（＾∀＾）清理完成');
    }


    /**
     * Read-only impact preview for order export and optional physical deletion.
     * @return array
     * @throws JSONException
     */
    public function exportImpact(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('订单导出预览只接受 POST 请求');
        }

        $raw = (array)$this->request->post();
        $options = $this->exportOptions($raw);
        $selection = $this->orderExportSelection($raw, $options);
        $rows = $selection['rows'];
        $ids = $rows->pluck('id')->map(static fn($id): int => (int)$id)->all();

        return $this->json(data: [
            'count' => $selection['count'],
            'total' => $selection['total'],
            'has_filter' => $selection['has_filter'],
            'paid_count' => $rows->filter(static fn($row): bool => (int)$row->status === 1)->count(),
            'unpaid_count' => $rows->filter(static fn($row): bool => (int)$row->status === 0)->count(),
            'delivered_count' => $rows->filter(static fn($row): bool => (int)$row->delivery_status === 1)->count(),
            'undelivered_count' => $rows->filter(static fn($row): bool => (int)$row->delivery_status === 0)->count(),
            'export_status' => $options['export_status'],
            'preview_token' => $this->issueOrderExportToken($ids, $options),
            'expires_in' => self::EXPORT_PREVIEW_TTL,
            'max_count' => self::MAX_EXPORT_COUNT,
        ]);
    }

    /** @param iterable<mixed> $rows */
    private function buildOrderCsv(iterable $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new JSONException('无法创建订单导出文件');
        }

        try {
            if (fwrite($stream, "\xEF\xBB\xBF") === false || fputcsv($stream, [
                '订单号',
                '金额',
                '商品名称',
                '数量',
                '支付方式',
                '下单时间',
                '下单IP',
                '下单设备',
                '支付时间',
                '订单状态',
                '联系方式',
                '发货状态',
                '优惠券',
                '客户',
                '推广人',
                '分站',
                '分站手续费',
                '接口手续费',
                '推广分成',
                '返利',
            ]) === false) {
                throw new JSONException('无法写入订单导出文件');
            }

            foreach ($rows as $row) {
                $d = $row->toArray();
                $deviceText = match ((int)($d['create_device'] ?? 0)) {
                    1 => '安卓',
                    2 => 'IOS',
                    3 => 'iPad',
                    default => 'PC',
                };
                $statusText = match ((int)($d['status'] ?? 0)) {
                    0 => '未支付',
                    1 => '已支付',
                    default => '未知',
                };
                $deliveryStatusText = match ((int)($d['delivery_status'] ?? 0)) {
                    0 => '未发货',
                    1 => '已发货',
                    default => '未知',
                };

                if (fputcsv($stream, [
                    (string)($d['trade_no'] ?? ''),
                    (string)($d['amount'] ?? 0),
                    (string)($d['commodity']['name'] ?? ''),
                    (string)($d['card_num'] ?? 0),
                    (string)($d['pay']['name'] ?? ''),
                    (string)($d['create_time'] ?? ''),
                    (string)($d['create_ip'] ?? ''),
                    $deviceText,
                    (string)($d['pay_time'] ?? ''),
                    $statusText,
                    (string)($d['contact'] ?? ''),
                    $deliveryStatusText,
                    (string)($d['coupon']['code'] ?? ''),
                    (string)($d['owner']['username'] ?? ''),
                    (string)($d['promote']['username'] ?? ''),
                    (string)($d['user']['username'] ?? ''),
                    (string)($d['cost'] ?? 0),
                    (string)($d['pay_cost'] ?? 0),
                    (string)($d['divide_amount'] ?? 0),
                    (string)($d['rebate'] ?? 0),
                ]) === false) {
                    throw new JSONException('无法写入订单导出文件');
                }
            }

            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new JSONException('无法读取订单导出文件');
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Export the exact signed preview scope via POST. Physical deletion is
     * atomic and any failure aborts the download instead of being swallowed.
     * @return string
     * @throws JSONException
     */
    public function export(): string
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('订单导出只接受 POST 请求');
        }
        set_time_limit(120);

        $raw = (array)$this->request->post();
        $options = $this->exportOptions($raw);
        $expectedCount = filter_var($raw['expected_count'] ?? null, FILTER_VALIDATE_INT);
        if ($expectedCount === false || $expectedCount < 1 || $expectedCount > self::MAX_EXPORT_COUNT) {
            throw new JSONException('请先预览并确认本次订单导出数量');
        }

        $result = DB::transaction(function () use ($raw, $options, $expectedCount): array {
            $selection = $this->orderExportSelection($raw, $options, true, $options['export_status'] === 1);
            $rows = $selection['rows'];
            $ids = $rows->pluck('id')->map(static fn($id): int => (int)$id)->all();

            if ($selection['count'] !== (int)$expectedCount) {
                throw new JSONException('订单数量已变化，请重新预览导出范围');
            }
            $this->verifyOrderExportToken($raw['preview_token'] ?? null, $ids, $options);

            if ($options['export_status'] === 1) {
                $requiredConfirmation = '确认永久删除' . $selection['count'] . '笔订单';
                if (!is_string($raw['delete_confirmation'] ?? null)
                    || !hash_equals($requiredConfirmation, trim((string)$raw['delete_confirmation']))) {
                    throw new JSONException('请完成高危确认后再导出并删除订单');
                }
            }

            $content = $this->buildOrderCsv($rows);
            if ($options['export_status'] === 1) {
                $deleted = \App\Model\Order::query()->whereIn('id', $ids)->delete();
                if ($deleted !== $selection['count']) {
                    throw new JSONException('订单删除数量不一致，已取消本次导出与删除，请重新预览');
                }
            }

            $effect = $options['export_status'] === 1 ? '导出并永久删除' : '导出';
            ManageLog::log($this->getManage(), "[订单导出]{$effect}订单，共计：{$selection['count']}");
            return ['content' => $content, 'count' => $selection['count']];
        });

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="订单导出-' . Date::current('YmdHis') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        return $result['content'];
    }
}
