<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Consts\Manage as ManageConst;
use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\UserRecharge;
use App\Service\Query;
use App\Service\Recharge;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class RechargeOrder extends Manage
{
    private const MAX_EXPORT_COUNT = 5000;
    private const EXPORT_PREVIEW_TTL = 180;

    #[Inject]
    private Query $query;

    #[Inject]
    private Recharge $recharge;

    /**
     * @param array<string,mixed> $raw
     * @return array{export_num:int,export_status:int}
     * @throws JSONException
     */
    private function exportOptions(array $raw): array
    {
        $exportNum = filter_var($raw['export_num'] ?? null, FILTER_VALIDATE_INT);
        if ($exportNum === false || $exportNum < 1 || $exportNum > self::MAX_EXPORT_COUNT) {
            throw new JSONException('导出数量必须是 1 到 ' . self::MAX_EXPORT_COUNT . ' 的整数');
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
     * @param array<string,mixed> $raw
     * @return array{0:Builder,1:bool}
     * @throws JSONException
     */
    private function exportQuery(array $raw): array
    {
        $query = UserRecharge::query();
        $hasFilter = false;
        $handled = [];

        $stringFilters = [
            'equal-trade_no' => ['trade_no', 128],
            'equal-create_ip' => ['create_ip', 64],
        ];
        foreach ($stringFilters as $key => [$column, $maxLength]) {
            $handled[] = $key;
            $rawValue = $raw[$key] ?? '';
            if (!is_scalar($rawValue) && $rawValue !== null) {
                throw new JSONException('充值订单导出筛选条件不正确');
            }
            $value = trim((string)$rawValue);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $maxLength) {
                throw new JSONException('充值订单导出筛选内容过长');
            }
            $hasFilter = true;
            $query->where($column, $value);
        }

        $integerFilters = [
            'equal-pay_id' => ['pay_id', 1, null],
            'equal-user_id' => ['user_id', 1, null],
            'equal-status' => ['status', 0, 1],
        ];
        foreach ($integerFilters as $key => [$column, $minimum, $maximum]) {
            $handled[] = $key;
            $value = $raw[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < $minimum || ($maximum !== null && $integer > $maximum)) {
                throw new JSONException('充值订单导出筛选条件不正确');
            }
            $hasFilter = true;
            $query->where($column, (int)$integer);
        }

        $dates = [];
        foreach (['betweenStart-create_time' => '>=', 'betweenEnd-create_time' => '<='] as $key => $operator) {
            $handled[] = $key;
            $rawValue = $raw[$key] ?? '';
            if (!is_scalar($rawValue) && $rawValue !== null) {
                throw new JSONException('充值订单下单时间筛选不正确');
            }
            $value = trim((string)$rawValue);
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                throw new JSONException('充值订单下单时间筛选不正确');
            }
            $dates[$key] = $timestamp;
            $hasFilter = true;
            $query->where('create_time', $operator, $value);
        }
        if (isset($dates['betweenStart-create_time'], $dates['betweenEnd-create_time'])
            && $dates['betweenStart-create_time'] > $dates['betweenEnd-create_time']) {
            throw new JSONException('充值订单下单时间起点不能晚于终点');
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
    private function exportSelection(array $raw, array $options, bool $withRelations = false, bool $lock = false): array
    {
        [$query, $hasFilter] = $this->exportQuery($raw);
        $total = (int)(clone $query)->count();
        if ($total === 0) {
            throw new JSONException('当前筛选没有可导出的充值订单');
        }

        $count = min($options['export_num'], $total);
        $selection = (clone $query)->orderByDesc('id')->limit($count);
        if ($lock) {
            $selection->lockForUpdate();
        }
        if ($withRelations) {
            $selection->with([
                'user' => static function (Relation $relation) {
                    $relation->select(['id', 'username']);
                },
                'pay' => static function (Relation $relation) {
                    $relation->select(['id', 'name']);
                },
            ]);
        }

        $rows = $selection->get();
        if ($rows->count() !== $count) {
            throw new JSONException('充值订单数据已变化，请重新预览导出范围');
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'count' => $count,
            'has_filter' => $hasFilter,
        ];
    }

    /** @param int[] $ids */
    private function exportFingerprint(array $ids): string
    {
        return hash('sha256', implode(',', $ids));
    }

    /** @param iterable<mixed> $rows */
    private function exportStateFingerprint(iterable $rows): string
    {
        $state = [];
        foreach ($rows as $row) {
            $state[] = [
                (int)$row->id,
                (int)$row->status,
                (string)$row->amount,
                (int)$row->user_id,
                (int)$row->pay_id,
                (string)$row->trade_no,
                (string)$row->create_time,
                (string)$row->create_ip,
                (string)$row->pay_time,
            ];
        }
        try {
            return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new JSONException('无法校验充值订单导出数据');
        }
    }

    private function exportTokenKey(): string
    {
        $manage = $this->getManage();
        if (!$manage) {
            throw new JSONException('管理员会话已失效，请刷新后重试');
        }
        return hash('sha256', 'recharge-order-export-preview-v1|' . (string)$manage->password, true);
    }

    /** @param int[] $ids @param iterable<mixed> $rows @param array{export_num:int,export_status:int} $options */
    private function issueExportToken(array $ids, iterable $rows, array $options): string
    {
        $now = time();
        $payload = [
            'fingerprint' => $this->exportFingerprint($ids),
            'state_fingerprint' => $this->exportStateFingerprint($rows),
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
            throw new JSONException('无法生成充值订单导出预览凭证');
        }
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, $this->exportTokenKey());
    }

    /** @param int[] $ids @param iterable<mixed> $rows @param array{export_num:int,export_status:int} $options */
    private function verifyExportToken(mixed $token, array $ids, iterable $rows, array $options): void
    {
        if (!is_string($token) || $token === '' || substr_count($token, '.') !== 1) {
            throw new JSONException('请先预览并确认充值订单导出范围');
        }
        [$body, $signature] = explode('.', $token, 2);
        $expectedSignature = hash_hmac('sha256', $body, $this->exportTokenKey());
        if (!preg_match('/^[a-f0-9]{64}$/D', $signature) || !hash_equals($expectedSignature, $signature)) {
            throw new JSONException('充值订单导出预览凭证无效，请重新预览');
        }

        $encoded = strtr($body, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new JSONException('充值订单导出预览凭证无效，请重新预览');
        }
        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new JSONException('充值订单导出预览凭证无效，请重新预览');
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
            || !hash_equals((string)($payload['fingerprint'] ?? ''), $this->exportFingerprint($ids))
            || !hash_equals((string)($payload['state_fingerprint'] ?? ''), $this->exportStateFingerprint($rows))) {
            throw new JSONException('充值订单导出范围或数据已变化，请重新预览');
        }
    }

    private function csvText(mixed $value): string
    {
        $text = (string)($value ?? '');
        return preg_match('/^[=+\-@\t\r]/u', $text) ? "'" . $text : $text;
    }

    /** @param iterable<mixed> $rows */
    private function buildCsv(iterable $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new JSONException('无法创建充值订单导出文件');
        }

        try {
            if (fwrite($stream, "\xEF\xBB\xBF") === false || fputcsv($stream, [
                '订单号',
                '金额',
                '会员',
                '支付方式',
                '下单时间',
                '下单IP',
                '支付时间',
                '支付状态',
            ]) === false) {
                throw new JSONException('无法写入充值订单导出文件');
            }

            foreach ($rows as $row) {
                $data = $row->toArray();
                $statusText = match ((int)($data['status'] ?? 0)) {
                    0 => '未支付',
                    1 => '已支付',
                    default => '未知',
                };
                if (fputcsv($stream, [
                    $this->csvText($data['trade_no'] ?? ''),
                    (string)($data['amount'] ?? 0),
                    $this->csvText($data['user']['username'] ?? ''),
                    $this->csvText($data['pay']['name'] ?? ''),
                    $this->csvText($data['create_time'] ?? ''),
                    $this->csvText($data['create_ip'] ?? ''),
                    $this->csvText($data['pay_time'] ?? ''),
                    $statusText,
                ]) === false) {
                    throw new JSONException('无法写入充值订单导出文件');
                }
            }

            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new JSONException('无法读取充值订单导出文件');
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(UserRecharge::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $raw = [];

        $data = $this->query->get($get, function (Builder $builder) use (&$raw) {
            $raw['order_amount'] = (clone $builder)->sum("amount");

            return $builder->with([
                'user' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                }
            ]);
        });

        return $this->json(data: array_merge($raw, $data));
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function success(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('充值补单只接受 POST 请求');
        }
        $id = filter_var($this->request->post('id'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new JSONException('订单编号不正确');
        }

        $tradeNo = DB::transaction(function () use ($id): string {
            $order = UserRecharge::query()->where('id', (int)$id)->lockForUpdate()->first();
            if (!$order) {
                throw new JSONException('订单不存在');
            }

            if ((int)$order->status !== 0) {
                throw new JSONException('该订单已支付，无法再次补单');
            }

            $user = \App\Model\User::query()->where('id', (int)$order->user_id)->lockForUpdate()->first();
            if (!$user) {
                throw new JSONException('订单会员不存在，无法补单');
            }

            // orderSuccess() 会重新读取订单会员；同一事务中的会员行锁可防止并发余额更新丢失。
            $this->recharge->orderSuccess($order);
            return (string)$order->trade_no;
        });

        ManageLog::log($this->getManage(), "充值订单->手动补单，订单号：{$tradeNo}");
        return $this->json(200, "已手动确认");
    }


    /**
     * @return array
     */
    public function clear(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('充值订单清理只接受 POST 请求');
        }
        $count = UserRecharge::query()
            ->where("create_time", "<", date("Y-m-d H:i:s", time() - 1800))
            ->where("status", 0)->delete();

        ManageLog::log($this->getManage(), "充值订单->清理30分钟前未支付订单，共计：{$count}");
        return $this->json(200, '清理完成', ['count' => (int)$count]);
    }

    /**
     * Read-only impact preview for recharge-order export and optional deletion.
     * @return array
     * @throws JSONException
     */
    public function exportImpact(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('充值订单导出预览只接受 POST 请求');
        }

        $raw = (array)$this->request->post();
        $options = $this->exportOptions($raw);
        $selection = $this->exportSelection($raw, $options);
        $rows = $selection['rows'];
        $ids = $rows->pluck('id')->map(static fn($id): int => (int)$id)->all();

        return $this->json(data: [
            'count' => $selection['count'],
            'total' => $selection['total'],
            'has_filter' => $selection['has_filter'],
            'paid_count' => $rows->filter(static fn($row): bool => (int)$row->status === 1)->count(),
            'unpaid_count' => $rows->filter(static fn($row): bool => (int)$row->status === 0)->count(),
            'export_status' => $options['export_status'],
            'preview_token' => $this->issueExportToken($ids, $rows, $options),
            'expires_in' => self::EXPORT_PREVIEW_TTL,
            'max_count' => self::MAX_EXPORT_COUNT,
        ]);
    }


    /**
     * Export the exact signed preview scope via POST. Deletion is atomic and
     * any mismatch or failure aborts the download.
     * @return string
     * @throws JSONException
     */
    public function export(): string
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('充值订单导出只接受 POST 请求');
        }
        set_time_limit(120);

        $raw = (array)$this->request->post();
        $options = $this->exportOptions($raw);
        $expectedCount = filter_var($raw['expected_count'] ?? null, FILTER_VALIDATE_INT);
        if ($expectedCount === false || $expectedCount < 1 || $expectedCount > self::MAX_EXPORT_COUNT) {
            throw new JSONException('请先预览并确认本次充值订单导出数量');
        }

        $result = DB::transaction(function () use ($raw, $options, $expectedCount): array {
            $selection = $this->exportSelection($raw, $options, true, $options['export_status'] === 1);
            $rows = $selection['rows'];
            $ids = $rows->pluck('id')->map(static fn($id): int => (int)$id)->all();

            if ($selection['count'] !== (int)$expectedCount) {
                throw new JSONException('充值订单数量已变化，请重新预览导出范围');
            }
            $this->verifyExportToken($raw['preview_token'] ?? null, $ids, $rows, $options);

            if ($options['export_status'] === 1) {
                $requiredConfirmation = '确认永久删除' . $selection['count'] . '笔充值订单';
                if (!is_string($raw['delete_confirmation'] ?? null)
                    || !hash_equals($requiredConfirmation, trim((string)$raw['delete_confirmation']))) {
                    throw new JSONException('请完成高危确认后再导出并删除充值订单');
                }
            }

            $content = $this->buildCsv($rows);
            if ($options['export_status'] === 1) {
                $deleted = UserRecharge::query()->whereIn('id', $ids)->delete();
                if ($deleted !== $selection['count']) {
                    throw new JSONException('充值订单删除数量不一致，已取消本次导出与删除，请重新预览');
                }
            }

            $effect = $options['export_status'] === 1 ? '导出并永久删除' : '导出';
            ManageLog::log($this->getManage(), "[充值订单导出]{$effect}订单，共计：{$selection['count']}");
            return ['content' => $content, 'count' => $selection['count']];
        });

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="充值订单导出-' . Date::current("YmdHis") . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        return $result['content'];
    }

}
