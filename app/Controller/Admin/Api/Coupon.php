<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Coupon extends Manage
{
    #[Inject]
    private Query $query;

    private const MAX_BATCH_COUNT = 500;
    private const MAX_EXPORT_COUNT = 5000;

    /**
     * @param mixed $value
     * @return int[]
     * @throws JSONException
     */
    private function couponIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $candidate) {
            if (is_int($candidate)) {
                $id = $candidate;
            } elseif (is_string($candidate) && ctype_digit(trim($candidate))) {
                $id = (int)trim($candidate);
            } else {
                throw new JSONException('优惠卷 ID 必须是正整数');
            }
            if ($id <= 0) {
                throw new JSONException('优惠卷 ID 必须是正整数');
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_BATCH_COUNT) {
            throw new JSONException('单次最多操作 ' . self::MAX_BATCH_COUNT . ' 张优惠卷');
        }
        return $ids;
    }

    /**
     * @param int[] $requestedIds
     * @param bool $lock
     * @return array{coupon_ids:int[],coupon_count:int,normal_count:int,used_count:int,locked_count:int,trade_no_count:int,order_reference_count:int,can_delete:bool}
     * @throws JSONException
     */
    private function couponDeleteImpact(array $requestedIds, bool $lock = false): array
    {
        if ($requestedIds === []) {
            throw new JSONException('请至少选择一张优惠卷');
        }

        $query = \App\Model\Coupon::query()
            ->whereIn('id', $requestedIds)
            ->select(['id', 'status', 'trade_no']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $coupons = $query->get();
        if ($coupons->count() !== count($requestedIds)) {
            throw new JSONException('部分优惠卷不存在，请刷新后重试');
        }

        $ids = $coupons->pluck('id')->map(static fn($id): int => (int)$id)->all();
        $normalCount = $coupons->filter(static fn($coupon): bool => (int)$coupon->status === 0)->count();
        $usedCount = $coupons->filter(static fn($coupon): bool => (int)$coupon->status === 1)->count();
        $lockedCount = $coupons->filter(static fn($coupon): bool => (int)$coupon->status === 2)->count();
        $tradeNoCount = $coupons->filter(static fn($coupon): bool => trim((string)$coupon->trade_no) !== '')->count();
        $orderReferenceCount = (int)\App\Model\Order::query()->whereIn('coupon_id', $ids)->count();

        return [
            'coupon_ids' => $ids,
            'coupon_count' => count($ids),
            'normal_count' => $normalCount,
            'used_count' => $usedCount,
            'locked_count' => $lockedCount,
            'trade_no_count' => $tradeNoCount,
            'order_reference_count' => $orderReferenceCount,
            'can_delete' => $usedCount === 0 && $tradeNoCount === 0 && $orderReferenceCount === 0,
        ];
    }

    /**
     * Build the exact export scope from a strict POST-only filter whitelist.
     * Coupon codes and exact-code filters must never be placed in URLs.
     *
     * @param array $raw
     * @return array{0:Builder,1:bool}
     * @throws JSONException
     */
    private function couponExportQuery(array $raw): array
    {
        $allowedKeys = [
            'coupon_code_secret',
            'equal-note',
            'equal-money',
            'equal-owner',
            'equal-category_id',
            'equal-commodity_id',
            'equal-race',
            'equal-status',
            'expected_count',
        ];
        foreach ($raw as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $allowedKeys, true)) {
                continue;
            }
            if (str_starts_with($key, 'equal-sku-')) {
                $skuKey = substr($key, strlen('equal-sku-'));
                if (preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fff}]{1,32}$/u', $skuKey)) {
                    continue;
                }
            }
            throw new JSONException('优惠卷导出包含未允许的筛选条件');
        }

        $query = \App\Model\Coupon::query();
        $hasFilter = false;
        foreach ([
            // The sensitive key name is deliberate: RequestLogger masks fields
            // containing "secret", so an exact coupon code cannot enter DEBUG logs.
            'coupon_code_secret' => ['code', 32],
            'equal-note' => ['note', 32],
            'equal-race' => ['race', 32],
        ] as $key => [$column, $maxLength]) {
            $value = $raw[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new JSONException('优惠卷导出筛选条件不正确');
            }
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $maxLength) {
                throw new JSONException('优惠卷导出筛选条件过长');
            }
            $hasFilter = true;
            $query->where($column, $value);
        }

        $rawMoney = $raw['equal-money'] ?? '';
        if ($rawMoney !== '' && $rawMoney !== null) {
            if (!is_scalar($rawMoney) || !is_numeric($rawMoney)) {
                throw new JSONException('优惠卷面值筛选不正确');
            }
            $money = (float)$rawMoney;
            if (!is_finite($money) || $money <= 0 || $money > 99999999.99) {
                throw new JSONException('优惠卷面值筛选不正确');
            }
            $hasFilter = true;
            $query->where('money', $money);
        }

        foreach ([
            'equal-owner' => 'owner',
            'equal-category_id' => 'category_id',
            'equal-commodity_id' => 'commodity_id',
            'equal-status' => 'status',
        ] as $key => $column) {
            $value = $raw[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new JSONException('优惠卷导出筛选条件不正确');
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < 0 || ($column === 'status' && !in_array($integer, [0, 1, 2], true))) {
                throw new JSONException('优惠卷导出筛选条件不正确');
            }
            $hasFilter = true;
            $query->where($column, $integer);
        }

        foreach ($raw as $key => $value) {
            $key = (string)$key;
            if (!str_starts_with($key, 'equal-sku-') || $value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new JSONException('SKU 筛选条件不正确');
            }
            $skuKey = substr($key, strlen('equal-sku-'));
            $skuValue = trim((string)$value);
            if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fff}]{1,32}$/u', $skuKey) || mb_strlen($skuValue) > 64) {
                throw new JSONException('SKU 筛选条件不正确');
            }
            if ($skuValue === '') {
                continue;
            }
            $hasFilter = true;
            $query->where('sku->' . $skuKey, $skuValue);
        }

        return [$query, $hasFilter];
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\Coupon::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);

        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with([
                'owner:id,username,avatar',
                'commodity:id,name,cover',
                'category:id,name'
            ]);
        });

        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $prefix = strtoupper(trim((string)($_POST['prefix'] ?? '')));
        $note = trim((string)($_POST['note'] ?? ''));
        $commodityId = (int)($_POST['commodity_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $expireTime = trim((string)($_POST['expire_time'] ?? ''));
        $rawMoney = $_POST['money'] ?? null;
        $num = (int)($_POST['num'] ?? 0);
        $life = (int)($_POST['life'] ?? 0);
        $mode = (int)($_POST['mode'] ?? -1);
        $raceGetMode = (int)($_POST['race_get_mode'] ?? 0);
        $race = trim((string)($raceGetMode === 0 ? ($_POST['race'] ?? '') : ($_POST['race_input'] ?? '')));
        $sku = is_array($_POST['sku'] ?? null) ? $_POST['sku'] : [];

        if (!is_numeric($rawMoney)) {
            throw new JSONException("ಠ_ಠ请输入有效的优惠卷面值");
        }
        $money = (float)$rawMoney;
        if (!is_finite($money) || $money <= 0) {
            throw new JSONException("ಠ_ಠ请输入优惠卷价格");
        }
        if (!in_array($mode, [0, 1], true)) {
            throw new JSONException("请选择正确的抵扣模式");
        }
        if ($mode === 1 && $money > 1) {
            throw new JSONException("百分比抵扣必须大于 0 且小于或等于 1");
        }
        if ($mode === 0 && $money > 99999999.99) {
            throw new JSONException("金额抵扣超出允许范围");
        }

        if ($prefix !== '' && !preg_match('/^[A-Z0-9_-]{1,16}$/', $prefix)) {
            throw new JSONException("优惠卷前缀仅支持 1 到 16 位字母、数字、下划线或短横线");
        }
        if (mb_strlen($note) > 32) {
            throw new JSONException("备注信息最多 32 个字符");
        }
        if (mb_strlen($race) > 32) {
            throw new JSONException("商品种类最多 32 个字符");
        }

        if ($commodityId > 0 && $categoryId > 0) {
            throw new JSONException("商品和商品分类只能选择一个抵扣范围");
        }

        if ($expireTime !== '') {
            $expireTimestamp = strtotime($expireTime);
            if ($expireTimestamp === false || $expireTimestamp <= time()) {
                throw new JSONException("ಠ_ಠ优惠卷的过期时间必须晚于当前时间");
            }
        }

        if ($num < 1 || $num > 1000) {
            throw new JSONException("每次只能生成 1 到 1000 张优惠卷");
        }
        if ($life < 1 || $life > 1000000) {
            throw new JSONException("可用次数必须是 1 到 1000000 之间的整数");
        }
        $date = Date::current();
        $result = DB::transaction(function () use (
            $categoryId,
            $commodityId,
            $num,
            $prefix,
            $date,
            $expireTime,
            $money,
            $note,
            $life,
            $mode,
            $sku,
            $race
        ): array {
            if ($categoryId > 0) {
                $category = \App\Model\Category::query()
                    ->where('owner', 0)
                    ->where('id', $categoryId)
                    ->lockForUpdate()
                    ->first();
                if (!$category) {
                    throw new JSONException('所选商品分类不存在');
                }
            }
            if ($commodityId > 0) {
                $commodity = \App\Model\Commodity::query()
                    ->where('owner', 0)
                    ->where('id', $commodityId)
                    ->lockForUpdate()
                    ->first();
                if (!$commodity) {
                    throw new JSONException('所选商品不存在');
                }
            }

            $success = 0;
            $error = 0;
            $codes = '';
            for ($i = 0; $i < $num; $i++) {
                $voucher = new \App\Model\Coupon();
                $voucher->code = $prefix . strtoupper(Str::generateRandStr(16));
                $voucher->commodity_id = $commodityId;
                $voucher->category_id = $categoryId;
                $voucher->owner = 0;
                $voucher->create_time = $date;
                if ($expireTime !== '') {
                    $voucher->expire_time = $expireTime;
                }
                $voucher->money = $money;
                $voucher->status = 0;
                $voucher->note = $note;
                $voucher->life = $life;
                $voucher->mode = $mode;
                $voucher->sku = $sku;
                if ($race !== '') {
                    $voucher->race = $race;
                }
                try {
                    $voucher->save();
                    $success++;
                    $codes .= $voucher->code . PHP_EOL;
                } catch (\Exception) {
                    $error++;
                }
            }

            return ['success' => $success, 'error' => $error, 'code' => $codes];
        });

        ManageLog::log($this->getManage(), "[生成优惠卷]成功:{$result['success']}张，失败：{$result['error']}张");
        return $this->json(
            200,
            "生成完毕，成功:{$result['success']}张，失败：{$result['error']}张",
            $result
        );
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function edit(): array
    {
        $id = (int)($_POST['id'] ?? 0);
        $status = (int)($_POST['status'] ?? -1);
        if ($id <= 0 || !in_array($status, [0, 2], true)) {
            throw new JSONException("请求参数不正确");
        }
        $coupon = \App\Model\Coupon::query()->find($id);
        if (!$coupon) {
            throw new JSONException("优惠卷不存在");
        }
        if ((int)$coupon->status === 1) {
            throw new JSONException("已使用的优惠卷不能修改状态");
        }
        $coupon->status = $status;
        $coupon->save();

        ManageLog::log($this->getManage(), "[修改优惠卷]编辑了优惠卷信息");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function lock(): array
    {
        $list = $this->couponIds($_POST['list'] ?? []);
        if ($list === []) {
            throw new JSONException("请选择要锁定的优惠卷");
        }
        $count = \App\Model\Coupon::query()->whereIn('id', $list)->where('status', 0)->update(['status' => 2]);

        ManageLog::log($this->getManage(), "[锁定优惠卷]批量锁定了优惠卷，共计：" . $count);
        return $this->json(200, $count > 0 ? '锁定成功' : '没有可锁定的优惠卷', [
            'count' => $count,
            'requested_count' => count($list),
        ]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function unlock(): array
    {
        $list = $this->couponIds($_POST['list'] ?? []);
        if ($list === []) {
            throw new JSONException("请选择要解锁的优惠卷");
        }
        $count = \App\Model\Coupon::query()->whereIn('id', $list)->where('status', 2)->update(['status' => 0]);

        ManageLog::log($this->getManage(), "[解锁优惠卷]批量解锁了优惠卷，共计：" . $count);
        return $this->json(200, $count > 0 ? '解锁成功' : '没有可解锁的优惠卷', [
            'count' => $count,
            'requested_count' => count($list),
        ]);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $requestedIds = $this->couponIds($_POST['list'] ?? []);
        $impact = DB::transaction(function () use ($requestedIds): array {
            $impact = $this->couponDeleteImpact($requestedIds, true);
            if (!$impact['can_delete']) {
                throw new JSONException(
                    "所选优惠卷中包含 {$impact['used_count']} 张已使用优惠卷、{$impact['trade_no_count']} 张带最后使用订单号的优惠卷，另有 {$impact['order_reference_count']} 笔订单引用；为保护历史记录，已阻止删除。"
                );
            }

            $referencedCouponIds = \App\Model\Order::query()
                ->select('coupon_id')
                ->whereNotNull('coupon_id');
            $deleted = \App\Model\Coupon::query()
                ->whereIn('id', $impact['coupon_ids'])
                ->where('status', '!=', 1)
                ->where(static function (Builder $builder): void {
                    $builder->whereNull('trade_no')->orWhere('trade_no', '');
                })
                ->whereNotIn('id', $referencedCouponIds)
                ->delete();
            if ($deleted !== $impact['coupon_count']) {
                throw new JSONException('优惠卷数据或订单引用已变化，未执行删除，请重新预览');
            }
            return $impact;
        });

        ManageLog::log($this->getManage(), "[批量删除]删除未使用优惠卷，共计：{$impact['coupon_count']}");
        return $this->json(200, '（＾∀＾）移除成功', ['count' => $impact['coupon_count']]);
    }

    /**
     * Read-only impact preview used before physical coupon deletion.
     * @return array
     * @throws JSONException
     */
    public function deleteImpact(): array
    {
        $impact = $this->couponDeleteImpact($this->couponIds($_POST['list'] ?? []));
        unset($impact['coupon_ids']);
        return $this->json(data: $impact);
    }

    /**
     * Read-only preview for the sensitive coupon-code export.
     * @return array
     * @throws JSONException
     */
    public function exportImpact(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('优惠卷导出预览只接受 POST 请求');
        }
        $raw = (array)$this->request->post();
        [$query, $hasFilter] = $this->couponExportQuery($raw);
        $total = (int)(clone $query)->count();
        if ($total === 0) {
            throw new JSONException('当前筛选没有可导出的优惠卷');
        }
        if ($total > self::MAX_EXPORT_COUNT) {
            throw new JSONException('当前范围过大，请增加筛选；单次最多导出 ' . self::MAX_EXPORT_COUNT . ' 张优惠卷');
        }

        $statusCounts = [0 => 0, 1 => 0, 2 => 0];
        foreach ((clone $query)->get(['status']) as $coupon) {
            $status = (int)$coupon->status;
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status]++;
            }
        }

        return $this->json(data: [
            'count' => $total,
            'total' => $total,
            'has_filter' => $hasFilter,
            'normal_count' => $statusCounts[0],
            'used_count' => $statusCounts[1],
            'locked_count' => $statusCounts[2],
            'max_count' => self::MAX_EXPORT_COUNT,
        ]);
    }

    /**
     * Export coupon codes using POST so codes and exact filters never enter URLs.
     * @return string
     * @throws JSONException
     */
    public function export(): string
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('优惠卷导出只接受 POST 请求');
        }
        $raw = (array)$this->request->post();
        $expectedCount = filter_var($raw['expected_count'] ?? null, FILTER_VALIDATE_INT);
        if ($expectedCount === false || $expectedCount < 1 || $expectedCount > self::MAX_EXPORT_COUNT) {
            throw new JSONException('请先预览并确认本次导出数量');
        }

        [$query] = $this->couponExportQuery($raw);
        $total = (int)(clone $query)->count();
        if ($total === 0) {
            throw new JSONException('当前筛选没有可导出的优惠卷');
        }
        if ($total > self::MAX_EXPORT_COUNT) {
            throw new JSONException('当前范围过大，请增加筛选；单次最多导出 ' . self::MAX_EXPORT_COUNT . ' 张优惠卷');
        }
        if ($total !== (int)$expectedCount) {
            throw new JSONException('优惠卷数量已变化，请重新预览导出范围');
        }

        $codes = (clone $query)->orderBy('id', 'asc')->pluck('code')->map(static fn($code): string => (string)$code)->all();
        if (count($codes) !== $total) {
            throw new JSONException('优惠卷数据已变化，请重新预览导出范围');
        }
        $content = implode(PHP_EOL, $codes) . PHP_EOL;

        ManageLog::log($this->getManage(), "[优惠卷导出]导出优惠卷，共计：{$total}");
        header('Content-Type:text/plain; charset=UTF-8');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=coupons-' . $total . '-' . date('Ymd-His') . '.txt');
        return $content;
    }
}
