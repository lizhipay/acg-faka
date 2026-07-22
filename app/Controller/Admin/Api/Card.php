<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Date;
use App\Util\Ini;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;
use Kernel\Waf\Firewall;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Card extends Manage
{
    #[Inject]
    private Query $query;

    private const MAX_BATCH_COUNT = 500;
    private const MAX_EXPORT_COUNT = 5000;

    /**
     * @param mixed $value
     * @return int[]
     */
    private function cardIds(mixed $value): array
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
                throw new JSONException('卡密 ID 必须是正整数');
            }
            if ($id <= 0) {
                throw new JSONException('卡密 ID 必须是正整数');
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_BATCH_COUNT) {
            throw new JSONException('单次最多操作 ' . self::MAX_BATCH_COUNT . ' 张卡密');
        }
        return $ids;
    }

    /**
     * @param int[] $requestedIds
     * @param bool $lock
     * @return array{card_ids:int[], card_count:int, sold_count:int, locked_count:int, linked_count:int, order_reference_count:int, can_delete:bool}
     * @throws JSONException
     */
    private function cardDeleteImpact(array $requestedIds, bool $lock = false): array
    {
        if ($requestedIds === []) {
            throw new JSONException('你还没有选择卡密');
        }
        $query = \App\Model\Card::query()
            ->whereIn('id', $requestedIds)
            ->select(['id', 'status', 'order_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $cards = $query->get();
        if ($cards->count() !== count($requestedIds)) {
            throw new JSONException('部分卡密不存在，请刷新后重试');
        }
        $ids = $cards->pluck('id')->map(static fn($id): int => (int)$id)->all();
        if ($ids === []) {
            throw new JSONException('所选卡密不存在');
        }
        $soldCount = $cards->filter(static fn($card): bool => (int)$card->status === 1)->count();
        $lockedCount = $cards->filter(static fn($card): bool => (int)$card->status === 2)->count();
        $linkedCount = $cards->filter(static fn($card): bool => (int)$card->order_id > 0)->count();
        $orderReferenceCount = (int)\App\Model\Order::query()->whereIn('card_id', $ids)->count();

        return [
            'card_ids' => $ids,
            'card_count' => count($ids),
            'sold_count' => $soldCount,
            'locked_count' => $lockedCount,
            'linked_count' => $linkedCount,
            'order_reference_count' => $orderReferenceCount,
            'can_delete' => $soldCount === 0
                && $lockedCount === 0
                && $linkedCount === 0
                && $orderReferenceCount === 0,
        ];
    }

    /**
     * Build a strict, export-only filter query. Exact or fuzzy card secrets stay
     * in the POST body and never enter the URL or browser history.
     *
     * @param array $raw
     * @return array{0:Builder,1:bool}
     * @throws JSONException
     */
    private function cardExportQuery(array $raw): array
    {
        $query = \App\Model\Card::query();
        $hasFilter = false;
        $stringFilters = [
            'equal-secret' => ['secret', '='],
            'search-secret' => ['secret', 'like'],
            'equal-note' => ['note', '='],
            'equal-race' => ['race', '='],
        ];
        foreach ($stringFilters as $key => [$column, $operator]) {
            $value = trim((string)($raw[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $hasFilter = true;
            $query->where($column, $operator, $operator === 'like' ? '%' . $value . '%' : $value);
        }

        foreach (['equal-owner' => 'owner', 'equal-commodity_id' => 'commodity_id', 'equal-status' => 'status'] as $key => $column) {
            $value = $raw[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < 0 || ($column === 'status' && !in_array($integer, [0, 1, 2], true))) {
                throw new JSONException('卡密导出筛选条件不正确');
            }
            $hasFilter = true;
            $query->where($column, $integer);
        }

        foreach (['betweenStart-create_time' => '>=', 'betweenEnd-create_time' => '<='] as $key => $operator) {
            $value = trim((string)($raw[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (strtotime($value) === false) {
                throw new JSONException('卡密入库时间筛选不正确');
            }
            $hasFilter = true;
            $query->where('create_time', $operator, $value);
        }

        foreach ($raw as $key => $value) {
            if (!str_starts_with((string)$key, 'equal-sku-') || $value === '' || $value === null) {
                continue;
            }
            $skuKey = substr((string)$key, strlen('equal-sku-'));
            if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fff}]{1,32}$/u', $skuKey)) {
                throw new JSONException('SKU 筛选条件不正确');
            }
            $hasFilter = true;
            $query->where('sku->' . $skuKey, (string)$value);
        }

        return [$query, $hasFilter];
    }

    /**
     * @param array $raw
     * @return array{export_num:int,export_status:int,note:?string}
     * @throws JSONException
     */
    private function exportOptions(array $raw): array
    {
        $exportNumRaw = $raw['export_num'] ?? 0;
        $exportNum = ($exportNumRaw === '' || $exportNumRaw === null)
            ? 0
            : filter_var($exportNumRaw, FILTER_VALIDATE_INT);
        if ($exportNum === false || $exportNum < 0 || $exportNum > self::MAX_EXPORT_COUNT) {
            throw new JSONException('单次最多导出 ' . self::MAX_EXPORT_COUNT . ' 张卡密');
        }
        $exportStatus = filter_var($raw['export_status'] ?? 0, FILTER_VALIDATE_INT);
        if ($exportStatus === false || !in_array($exportStatus, [0, 1, 3], true)) {
            throw new JSONException('“导出后删除”已被移除，请使用带关联保护的独立删除操作');
        }
        $note = trim((string)($raw['note'] ?? ''));
        if (mb_strlen($note) > 64) {
            throw new JSONException('导出备注最多 64 个字符');
        }
        return [
            'export_num' => (int)$exportNum,
            'export_status' => (int)$exportStatus,
            'note' => $note === '' ? null : $note,
        ];
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\Card::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with([
                'owner' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar"]);
                },
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "cover", "name"]);
                },
                'order' => function (Relation $relation) {
                    $relation->select(["id", "trade_no"]);
                }
            ]);
        });

        return $this->json(data: $data);
    }

    /**
     * @param int $commodityId
     * @return array
     * @throws JSONException
     */
    public function sku(int $commodityId): array
    {
        $commodity = \App\Model\Commodity::query()->find($commodityId);
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $config = Ini::toArray($commodity->config ?: "");

        return $this->json(data: $config);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $commodityId = $request->post("commodity_id", Filter::INTEGER);
        $raceGetMode = $request->post("race_get_mode", Filter::INTEGER);
        $race = $raceGetMode == 1 ? $request->post("race_input", Filter::NORMAL) : $request->post("race", Filter::NORMAL);
        $sku = $request->post("sku", Filter::NORMAL) ?: [];
        $cardType = $request->post("card_type", Filter::INTEGER);

        if ($commodityId == 0) {
            throw new JSONException('(`･ω･´)请选择商品');
        }

        $rawCards = $request->unsafePost("secret");
        if (!is_string($rawCards)) {
            throw new JSONException('(`･ω･´)卡密信息格式不正确');
        }
        // PHP has already URL-decoded form fields once. Keep literal strings
        // such as "%0A" intact instead of decoding them into extra card rows.
        $cards = trim((string)Firewall::instance()->xssKillerLiteral($rawCards));

        //进行批量插入
        if ($cards == '') {
            throw new JSONException('(`･ω･´)请至少添加1条卡密信息哦');
        }

        $cards = preg_split('/\r\n|\n|\r/', $cards) ?: [];
        $count = count($cards);

        $success = 0;
        $error = 0;
        $date = Date::current();

        $unique = (bool)$_POST['unique'];

        foreach ($cards as $card) {
            $cardt = trim(trim($card), PHP_EOL);
            if ($cardt == "") {
                $error++; //error ++
                continue;
            }

            $cardObj = new \App\Model\Card();

            if ($cardType == 0) {
                $cardObj->secret = $cardt;
            } else {
                //分割
                $list = explode("║", $cardt);
                if (count($list) < 2) {
                    $error++; //error ++
                    continue;
                }
                $cardObj->secret = trim($list[0]);

                //预选信息
                if (isset($list[1])) {
                    $cardObj->draft = trim($list[1]);
                }

                //独立加价
                if (isset($list[2])) {
                    $cardObj->draft_premium = (float)trim($list[2]);
                }

                //预选成本
                if (isset($list[3])) {
                    $cardObj->cost = (float)trim($list[3]);
                }
            }

            if ($unique) {
                if (\App\Model\Card::query()->where("owner", 0)->where("secret", $cardObj->secret)->first()) {
                    $error++; //error ++
                    continue;
                }
            }

            $cardObj->commodity_id = $commodityId;
            $cardObj->owner = 0;
            if (isset($_POST['note'])) {
                $cardObj->note = $_POST['note'];
            }
            $cardObj->status = 0;


            $cardObj->sku = $sku;
            $cardObj->create_time = $date;

            if ($race) {
                $cardObj->race = $race;
            }

            try {
                $cardObj->save();
                $success++;
            } catch (\Exception $e) {
                $error++; //error ++
            }
        }


        ManageLog::log($this->getManage(), "[导入卡密]共计导入:{$count}张卡密，成功:{$success}张，失败：{$error}张");
        return $this->json(200, "共计导入:{$count}张卡密，成功:{$success}张，失败：{$error}张");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function edit(): array
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new JSONException('卡密不存在');
        }
        $card = \App\Model\Card::query()->find($id);
        if (!$card) {
            throw new JSONException('卡密不存在');
        }

        $allowed = ['secret', 'draft', 'draft_premium', 'cost', 'note'];
        $map = array_intersect_key($_POST, array_flip($allowed));
        if ($map === []) {
            throw new JSONException('没有可保存的卡密字段');
        }
        if (array_key_exists('secret', $map)) {
            $secret = trim((string)$map['secret']);
            if ($secret === '' || mb_strlen($secret) > 760) {
                throw new JSONException('卡密信息不能为空且最多 760 个字符');
            }
            $map['secret'] = $secret;
        }
        if (array_key_exists('draft', $map) && mb_strlen((string)$map['draft']) > 255) {
            throw new JSONException('预告内容最多 255 个字符');
        }
        if (array_key_exists('note', $map) && mb_strlen((string)$map['note']) > 64) {
            throw new JSONException('备注最多 64 个字符');
        }
        foreach (['draft_premium', 'cost'] as $moneyField) {
            if (!array_key_exists($moneyField, $map)) {
                continue;
            }
            if ($map[$moneyField] === '') {
                $map[$moneyField] = 0;
                continue;
            }
            $value = filter_var($map[$moneyField], FILTER_VALIDATE_FLOAT);
            if ($value === false || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 99999999.99) {
                throw new JSONException('卡密加价和成本必须是有效的非负金额');
            }
            $map[$moneyField] = (float)$value;
        }

        foreach ($map as $field => $value) {
            $card->$field = $value;
        }
        if (!$card->save()) {
            throw new JSONException('保存失败');
        }
        ManageLog::log($this->getManage(), "[修改卡密]编辑了卡密信息");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @return array
     */
    public function lock(): array
    {
        $list = $this->cardIds($_POST['list'] ?? []);
        if ($list === []) {
            throw new JSONException('请至少选择一张卡密');
        }
        $count = \App\Model\Card::query()->whereIn('id', $list)->where('status', 0)->update(['status' => 2]);
        ManageLog::log($this->getManage(), "[锁定卡密]批量锁定卡密，共计：{$count}");
        return $this->json(200, $count > 0 ? '锁定成功' : '没有可锁定的卡密', ['count' => $count]);
    }

    /**
     * @return array
     */
    public function unlock(): array
    {
        $list = $this->cardIds($_POST['list'] ?? []);
        if ($list === []) {
            throw new JSONException('请至少选择一张卡密');
        }
        $count = \App\Model\Card::query()->whereIn('id', $list)->where('status', 2)->update(['status' => 0]);
        ManageLog::log($this->getManage(), "[解锁卡密]批量解锁卡密，共计：{$count}");
        return $this->json(200, $count > 0 ? '解锁成功' : '没有可解锁的卡密', ['count' => $count]);
    }

    /**
     * @return array
     */
    public function sell(): array
    {
        $list = $this->cardIds($_POST['list'] ?? []);
        if ($list === []) {
            throw new JSONException('请至少选择一张卡密');
        }
        $count = DB::transaction(function () use ($list): int {
            $cards = \App\Model\Card::query()
                ->whereIn('id', $list)
                ->lockForUpdate()
                ->get(['id', 'status', 'order_id']);
            if ($cards->count() !== count($list)) {
                throw new JSONException('部分卡密不存在，请刷新后重试');
            }
            $invalid = $cards->filter(static fn($card): bool => (int)$card->status !== 0 || (int)$card->order_id > 0)->count();
            if ($invalid > 0) {
                throw new JSONException('只能标记“未出售”卡密；锁定卡密请先显式解锁');
            }
            return \App\Model\Card::query()->whereIn('id', $list)->where('status', 0)->update([
                'status' => 1,
                'purchase_time' => Date::current(),
            ]);
        });
        ManageLog::log($this->getManage(), "[出售卡密]手动标记已出售，共计：{$count}");
        return $this->json(200, '操作成功', ['count' => $count]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $requestedIds = $this->cardIds($_POST['list'] ?? []);
        $impact = DB::transaction(function () use ($requestedIds): array {
            $impact = $this->cardDeleteImpact($requestedIds, true);
            if (!$impact['can_delete']) {
                throw new JSONException(
                    "所选卡密中包含 {$impact['sold_count']} 张已售卡密、{$impact['locked_count']} 张锁定卡密、" .
                    "{$impact['linked_count']} 张已关联订单卡密，另有 {$impact['order_reference_count']} 笔订单引用；为保护占用状态和历史记录，已阻止删除。"
                );
            }
            $deleted = \App\Model\Card::query()->whereIn('id', $impact['card_ids'])->delete();
            if ($deleted === 0) {
                throw new JSONException('没有移除任何数据');
            }
            return $impact;
        });

        ManageLog::log($this->getManage(), "[批量删除]删除未使用卡密，共计：{$impact['card_count']}");
        return $this->json(200, '（＾∀＾）移除成功', ['count' => $impact['card_count']]);
    }

    /**
     * Read-only impact preview used before card deletion.
     * @return array
     * @throws JSONException
     */
    public function deleteImpact(): array
    {
        $impact = $this->cardDeleteImpact($this->cardIds($_POST['list'] ?? []));
        unset($impact['card_ids']);
        return $this->json(data: $impact);
    }


    /**
     * Read-only preview for the sensitive card export operation.
     * @return array
     * @throws JSONException
     */
    public function exportImpact(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('卡密导出预览只接受 POST 请求');
        }
        $raw = $this->request->post();
        $options = $this->exportOptions($raw);
        [$query, $hasFilter] = $this->cardExportQuery($raw);
        $total = (int)(clone $query)->count();
        if ($total === 0) {
            throw new JSONException('当前筛选没有可导出的卡密');
        }
        $count = $options['export_num'] > 0 ? min($options['export_num'], $total) : $total;
        if ($count > self::MAX_EXPORT_COUNT) {
            throw new JSONException('当前范围过大，请增加筛选或填写不超过 ' . self::MAX_EXPORT_COUNT . ' 的导出数量');
        }

        $rows = (clone $query)
            ->orderBy('id', 'asc')
            ->limit($count)
            ->get(['id', 'status', 'order_id']);
        $statusCounts = [0 => 0, 1 => 0, 2 => 0];
        foreach ($rows as $row) {
            $status = (int)$row->status;
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status]++;
            }
        }
        if ($options['export_status'] === 3 && ($statusCounts[1] > 0 || $statusCounts[2] > 0)) {
            throw new JSONException('标记已售仅允许导出“未出售”卡密，请先调整筛选条件');
        }

        return $this->json(data: [
            'count' => $count,
            'total' => $total,
            'has_filter' => $hasFilter,
            'available_count' => $statusCounts[0],
            'sold_count' => $statusCounts[1],
            'locked_count' => $statusCounts[2],
            'will_change_note' => $options['note'] !== null,
            'export_status' => $options['export_status'],
            'max_count' => self::MAX_EXPORT_COUNT,
        ]);
    }

    /**
     * Export card secrets using POST so filters and secrets never enter URLs.
     * Mutating follow-up modes run atomically; physical deletion is forbidden.
     * @return string
     * @throws JSONException
     */
    public function export(): string
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('卡密导出只接受 POST 请求');
        }
        $raw = $this->request->post();
        $options = $this->exportOptions($raw);
        [$query] = $this->cardExportQuery($raw);
        $total = (int)(clone $query)->count();
        if ($total === 0) {
            throw new JSONException('当前筛选没有可导出的卡密');
        }
        $count = $options['export_num'] > 0 ? min($options['export_num'], $total) : $total;
        if ($count > self::MAX_EXPORT_COUNT) {
            throw new JSONException('当前范围过大，请增加筛选或填写不超过 ' . self::MAX_EXPORT_COUNT . ' 的导出数量');
        }

        $result = DB::transaction(function () use ($raw, $options, $count): array {
            // Rebuild inside the transaction so the same validated scope is
            // locked immediately before any optional status/note update.
            [$lockedQuery] = $this->cardExportQuery($raw);
            $rows = $lockedQuery
                ->orderBy('id', 'asc')
                ->limit($count)
                ->lockForUpdate()
                ->get(['id', 'secret', 'status', 'order_id']);
            if ($rows->count() !== $count) {
                throw new JSONException('卡密数据已变化，请重新预览导出范围');
            }

            $ids = $rows->pluck('id')->map(static fn($id): int => (int)$id)->all();
            if ($options['export_status'] === 3) {
                $invalid = $rows->filter(static fn($row): bool => (int)$row->status !== 0 || (int)$row->order_id > 0)->count();
                if ($invalid > 0) {
                    throw new JSONException('标记已售仅允许处理未出售且未关联订单的卡密');
                }
            }

            if ($options['note'] !== null) {
                \App\Model\Card::query()->whereIn('id', $ids)->update(['note' => $options['note']]);
            }
            if ($options['export_status'] === 1) {
                \App\Model\Card::query()->whereIn('id', $ids)->where('status', 0)->update(['status' => 2]);
            } elseif ($options['export_status'] === 3) {
                \App\Model\Card::query()->whereIn('id', $ids)->where('status', 0)->update([
                    'status' => 1,
                    'purchase_time' => Date::current(),
                ]);
            }

            return [
                'content' => $rows->pluck('secret')->implode(PHP_EOL) . PHP_EOL,
                'count' => $rows->count(),
            ];
        });

        $effect = match ($options['export_status']) {
            1 => '并锁定未出售卡密',
            3 => '并标记卡密已售',
            default => '仅下载',
        };
        ManageLog::log($this->getManage(), "[卡密导出]{$effect}，共计：{$result['count']}");
        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=cards-' . $result['count'] . '-' . date('Ymd-His') . '.txt');
        return $result['content'];
    }
}
