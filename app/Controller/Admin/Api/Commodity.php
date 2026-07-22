<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Client;
use App\Util\Date;
use App\Util\Ini;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Commodity extends Manage
{
    #[Inject]
    private Query $query;

    private const BATCH_SETTING_FIELDS = [
        'api_status',
        'password_status',
        'coupon',
        'inventory_hidden',
        'recommend',
        'shared_sync',
        'shared_amount_sync',
        'shared_config_sync',
    ];

    /**
     * @param mixed $value
     * @return int[]
     */
    private function commodityIds(mixed $value): array
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
                throw new JSONException('商品 ID 必须是正整数');
            }
            if ($id <= 0) {
                throw new JSONException('商品 ID 必须是正整数');
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 500) {
            throw new JSONException('单次最多操作 500 个商品');
        }
        return $ids;
    }

    /**
     * Resolve every relationship which would be changed or orphaned by a
     * physical commodity deletion. Historical records are deliberately treated
     * as blockers; administrators should delist or hide used commodities.
     *
     * @param int[] $requestedIds
     * @param bool $lock
     * @return array{commodity_ids:int[], names:array, commodity_count:int, card_count:int, sold_card_count:int, order_count:int, coupon_count:int, merchant_mapping_count:int, ticket_count:int, commodity_group_count:int, commodity_group_names:array, can_delete:bool}
     * @throws JSONException
     */
    private function commodityDeleteImpact(array $requestedIds, bool $lock = false): array
    {
        if ($requestedIds === []) {
            throw new JSONException('你还没有选择商品');
        }

        $query = \App\Model\Commodity::query()
            ->whereIn('id', $requestedIds)
            ->orderBy('id')
            ->select(['id', 'name']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $commodities = $query->get();
        if ($commodities->count() !== count($requestedIds)) {
            throw new JSONException('部分商品不存在，请刷新后重试');
        }
        $commodityIds = $commodities->pluck('id')->map(static fn($id): int => (int)$id)->all();
        if ($commodityIds === []) {
            throw new JSONException('所选商品不存在');
        }

        $cardQuery = \App\Model\Card::query()->whereIn('commodity_id', $commodityIds);
        $cardCount = (int)(clone $cardQuery)->count();
        $soldCardCount = (int)(clone $cardQuery)->where(function (Builder $builder) {
            $builder->where('status', 1)->orWhereNotNull('order_id');
        })->count();
        $orderCount = (int)\App\Model\Order::query()->whereIn('commodity_id', $commodityIds)->count();
        $couponCount = (int)\App\Model\Coupon::query()->whereIn('commodity_id', $commodityIds)->count();
        $merchantMappingCount = (int)\App\Model\UserCommodity::query()->whereIn('commodity_id', $commodityIds)->count();
        $ticketCount = (int)\App\Model\Ticket::query()->whereIn('commodity_id', $commodityIds)->count();

        // CommodityGroup stores references in JSON and therefore has no
        // database foreign key. The final delete path locks the complete,
        // ordered group scan after locking commodities. CommodityGroup::save()
        // uses the same Commodity -> CommodityGroup order, so a concurrent edit
        // cannot create an orphan between this scan and the physical deletion.
        $commodityGroupQuery = \App\Model\CommodityGroup::query()
            ->orderBy('id')
            ->select(['id', 'name', 'commodity_list']);
        if ($lock) {
            $commodityGroupQuery->lockForUpdate();
        }
        $commodityIdLookup = array_fill_keys($commodityIds, true);
        $referencingCommodityGroups = $commodityGroupQuery->get()->filter(static function ($commodityGroup) use ($commodityIdLookup): bool {
            $references = $commodityGroup->commodity_list;
            if (!is_array($references)) {
                $references = [$references];
            }
            foreach ($references as $reference) {
                if (is_int($reference)) {
                    $referenceId = $reference;
                } elseif (is_string($reference) && ctype_digit(trim($reference))) {
                    $referenceId = (int)trim($reference);
                } else {
                    continue;
                }
                if ($referenceId > 0 && isset($commodityIdLookup[$referenceId])) {
                    return true;
                }
            }
            return false;
        })->values();
        $commodityGroupCount = $referencingCommodityGroups->count();
        $canDelete = ($cardCount + $orderCount + $couponCount + $merchantMappingCount + $ticketCount + $commodityGroupCount) === 0;

        return [
            'commodity_ids' => $commodityIds,
            'names' => $commodities->pluck('name')->take(3)->values()->all(),
            'commodity_count' => count($commodityIds),
            'card_count' => $cardCount,
            'sold_card_count' => $soldCardCount,
            'order_count' => $orderCount,
            'coupon_count' => $couponCount,
            'merchant_mapping_count' => $merchantMappingCount,
            'ticket_count' => $ticketCount,
            'commodity_group_count' => $commodityGroupCount,
            'commodity_group_names' => $referencingCommodityGroups->pluck('name')->take(3)->values()->all(),
            'can_delete' => $canDelete,
        ];
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\Commodity::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));

        $data = $this->query->get($get, function (Builder $builder) use ($map) {
            if (isset($map['display_scope'])) {
                if ($map['display_scope'] == 1) {
                    $builder = $builder->where("owner", 0);
                } elseif ($map['display_scope'] == 2) {
                    if (isset($map['user_id']) && $map['user_id'] > 0) {
                        $builder = $builder->where("owner", $map['user_id']);
                    } else {
                        $builder = $builder->where("owner", "!=", 0);
                    }
                }
            }

            return $builder->with(['shared', 'category', 'owner' => function (Relation $relation) {
                $relation->with(['business' => function (Relation $relation) {
                    $relation->select(['id', 'user_id', 'subdomain', 'topdomain']);
                }])->select(["id", "username", "avatar"]);
            }])->withCount([
                'card as card_count' => function (Builder $builder) {
                    $builder->where("status", 0);
                },
                'card as card_success_count' => function (Builder $builder) {
                    $builder->where("status", 1);
                },
                //商品总盈利
                'order as order_all_amount' => function (Builder $relation) {
                    $relation->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_all_amount"));
                },
                //过去7天内盈利
                'order as order_week_amount' => function (Builder $relation) {
                    $relation->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_week_amount"));
                },
                //昨日盈利
                'order as order_yesterday_amount' => function (Builder $relation) {
                    $relation->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_yesterday_amount"));
                },
                //今日盈利
                'order as order_today_amount' => function (Builder $relation) {
                    $relation->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_today_amount"));
                }
            ]);
        });

        $clientUrl = Client::getUrl();
        //无限极分类完整路径：一次性加载分类扁平映射，循环内复用避免 N+1
        $categoryFlatMap = $data['list'] ? \App\Model\Category::flatMap() : [];
        foreach ($data['list'] as &$val) {
            $url = $clientUrl;
            if ($val['owner'] && $val['owner']['business']) {
                if ($val['owner']['business']['subdomain']) {
                    $url = "https://" . $val['owner']['business']['subdomain'];
                }
                if ($val['owner']['business']['topdomain']) {
                    $url = "https://" . $val['owner']['business']['topdomain'];
                }
            }
            $val['share_url'] = $url . "/item/{$val['id']}";
            //顶级分类 -> 子分类 -> 商品所属分类
            $val['category_path'] = \App\Model\Category::resolvePath((int)($val['category_id'] ?? 0), $categoryFlatMap);
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
        $raw = $request->post(flags: Filter::NORMAL);
        $allowed = [
            'id', 'category_id', 'name', 'description', 'cover', 'factory_price', 'price', 'user_price',
            'status', 'api_status', 'delivery_way', 'delivery_auto_mode', 'delivery_message', 'contact_type',
            'password_status', 'sort', 'coupon', 'shared_id', 'shared_code', 'shared_premium',
            'shared_premium_type', 'seckill_status', 'seckill_start_time', 'seckill_end_time', 'draft_status',
            'draft_premium', 'inventory_hidden', 'leave_message', 'recommend', 'send_email', 'only_user',
            'purchase_count', 'widget', 'level_price', 'level_disable', 'minimum', 'maximum', 'shared_sync',
            'config', 'hide', 'stock', 'inventory_sync', 'shared_amount_sync', 'shared_config_sync',
            'pay_intercept',
            'dock_g_id', 'dock_mode', 'dock_mode_value', 'dock_lucky_decimal', 'dock_sync_price',
            'dock_sync_content', 'dock_sync_title', 'dock_sync_now',
            'asyn_request_status', 'asyn_request_type', 'asyn_request_url', 'asyn_request_template',
        ];
        $map = array_intersect_key($raw, array_flip($allowed));
        $id = isset($map['id']) ? (int)$map['id'] : 0;
        $current = $id > 0 ? \App\Model\Commodity::query()->find($id) : null;
        if ($id > 0 && !$current) {
            throw new JSONException('商品不存在');
        }

        // create new
        if ($id === 0) {
            if (trim((string)($map['name'] ?? '')) === '') {
                throw new JSONException("商品名称不能为空哦(｡￫‿￩｡)");
            }
            if (!isset($map['category_id']) || (int)$map['category_id'] <= 0) {
                throw new JSONException('请选择商品分类');
            }
            if (!isset($map['price'], $map['user_price']) || (float)$map['price'] < 0 || (float)$map['user_price'] < 0) {
                throw new JSONException("商品单价不能低于0元哦(｡￫‿￩｡)");
            }
        } elseif (array_key_exists('name', $map) && trim((string)$map['name']) === '') {
            throw new JSONException('商品名称不能为空');
        }

        //如果选择了别人平台
        if (array_key_exists('shared_id', $map) && (int)$map['shared_id'] !== 0) {
            $map['delivery_way'] = 0;
            if (trim((string)($map['shared_code'] ?? '')) === '') {
                throw new JSONException("您选择了对接别人店铺，所以要填写商品对接代码哦(｡￫‿￩｡)");
            }
        }

        if (isset($map['seckill_status']) && (int)$map['seckill_status'] === 1) {
            if (empty($map['seckill_start_time']) || empty($map['seckill_end_time'])) {
                throw new JSONException("您开启了秒杀功能，所以请指定秒杀的开始时间和结束时间哦(｡￫‿￩｡)");
            }
            if (strtotime($map['seckill_end_time']) < strtotime($map['seckill_start_time'])) {
                throw new JSONException("秒杀结束时间不能低于秒杀开始时间哦，请认真指定秒杀结束时间(｡￫‿￩｡)");
            }
        }

        if (isset($map['draft_status']) && (int)$map['draft_status'] === 1) {
            if (!array_key_exists('draft_premium', $map) || $map['draft_premium'] === "") {
                throw new JSONException("您开启了预选卡密功能，请填写预选时的溢价(｡￫‿￩｡)");
            }
        }

        //解析配置文件
        if (!empty($map['config'])) {
            Ini::toArray($map['config']);
        }

        foreach (['factory_price', 'price', 'user_price', 'shared_premium', 'draft_premium'] as $moneyField) {
            if (!array_key_exists($moneyField, $map) || $map[$moneyField] === '') {
                continue;
            }
            $value = filter_var($map[$moneyField], FILTER_VALIDATE_FLOAT);
            if ($value === false || !is_finite((float)$value) || (float)$value < 0) {
                throw new JSONException('商品金额必须是大于等于 0 的有效数字');
            }
        }
        if (array_key_exists('stock', $map) && $map['stock'] !== '') {
            $stock = filter_var($map['stock'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 2147483647],
            ]);
            if ($stock === false) {
                throw new JSONException('商品库存必须是有效的非负整数');
            }
            $map['stock'] = $stock;
        }

        $save = new Save(\App\Model\Commodity::class);
        $save->setMap($map, $allowed);
        if (array_key_exists('config', $map)) {
            $save->addForceMap('config', $map['config'] ?? '');
        }
        if ($id === 0) {
            $save->addForceMap('owner', 0);
            $save->addForceMap('code', strtoupper(Str::generateRandStr(16)));
        } else {
            $save->disableAddable();
        }
        $save->enableCreateTime();
        $owner = $current ? (int)$current->owner : 0;
        $saved = DB::transaction(function () use ($save, $map, $id, $owner) {
            // Category deletion uses the same Category -> Commodity lock order.
            // Holding the target category row until the commodity write commits
            // prevents a create/move from racing a physical category deletion.
            if (array_key_exists('category_id', $map)) {
                $category = \App\Model\Category::query()
                    ->where('owner', $owner)
                    ->where('id', (int)$map['category_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$category) {
                    throw new JSONException('商品分类不存在或不属于同一创建者');
                }
            }

            if ($id > 0) {
                $lockedCommodity = \App\Model\Commodity::query()
                    ->where('id', $id)
                    ->where('owner', $owner)
                    ->lockForUpdate()
                    ->first();
                if (!$lockedCommodity) {
                    throw new JSONException('商品不存在');
                }
            }

            return $this->query->save($save);
        });
        if (!$saved) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[修改/新增]商品");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $requestedIds = $this->commodityIds($_POST['list'] ?? []);
        $impact = DB::transaction(function () use ($requestedIds): array {
            $impact = $this->commodityDeleteImpact($requestedIds, true);
            if (!$impact['can_delete']) {
                throw new JSONException(
                    "所选商品已有业务数据，禁止物理删除。关联卡密 {$impact['card_count']} 张、订单 {$impact['order_count']} 笔、优惠券 {$impact['coupon_count']} 张、商户映射 {$impact['merchant_mapping_count']} 条、工单 {$impact['ticket_count']} 条、商品分组 {$impact['commodity_group_count']} 个；请先解除关联，或改为下架/隐藏商品。"
                );
            }

            $expectedDeleteCount = count($impact['commodity_ids']);
            $deleted = \App\Model\Commodity::query()->whereIn('id', $impact['commodity_ids'])->delete();
            if ($deleted !== $expectedDeleteCount) {
                throw new JSONException('商品删除数量异常，操作已回滚，请刷新后重试');
            }
            return $impact;
        });

        ManageLog::log($this->getManage(), "[删除]未使用商品，共计：{$impact['commodity_count']}");
        return $this->json(200, '（＾∀＾）移除成功', ['count' => $impact['commodity_count']]);
    }

    /**
     * Read-only impact preview for the irreversible delete dialog.
     * @return array
     * @throws JSONException
     */
    public function deleteImpact(): array
    {
        $impact = $this->commodityDeleteImpact($this->commodityIds($_POST['list'] ?? []));
        unset($impact['commodity_ids']);
        return $this->json(data: $impact);
    }

    /**
     * @return array
     */
    public function status(): array
    {
        $list = $this->commodityIds($_POST['list'] ?? []);
        $rawStatus = (string)($_POST['status'] ?? '');
        if ($list === [] || !in_array($rawStatus, ['0', '1'], true)) {
            throw new JSONException('商品状态请求参数不正确');
        }
        $status = (int)$rawStatus;
        $count = \App\Model\Commodity::query()->whereIn('id', $list)->update(['status' => $status]);
        ManageLog::log($this->getManage(), "[批量更新]商品启停状态，共计：{$count}");
        return $this->json(200, $count > 0 ? '商品状态已经更新' : '商品状态无需更新', ['count' => $count]);
    }


    /**
     * @return array
     */
    public function fastEnable(): array
    {
        $raw = $this->request->post();
        $list = $this->commodityIds($raw['list'] ?? []);
        if ($list === []) {
            throw new JSONException('请至少选择一个商品');
        }

        $changes = [];
        foreach (self::BATCH_SETTING_FIELDS as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $value = (string)$raw[$field];
            if ($value === '' || $value === '-1' || $value === 'keep') {
                continue;
            }
            if (!in_array($value, ['0', '1'], true)) {
                throw new JSONException('批量设置参数不正确');
            }
            $changes[$field] = (int)$value;
        }
        if ($changes === []) {
            throw new JSONException('你还没有选择任何需要修改的设置');
        }

        $sharedFields = ['shared_sync', 'shared_amount_sync', 'shared_config_sync'];
        $sharedChanges = array_intersect_key($changes, array_flip($sharedFields));
        $baseChanges = array_diff_key($changes, array_flip($sharedFields));

        $result = DB::transaction(function () use ($list, $baseChanges, $sharedChanges): array {
            $selected = \App\Model\Commodity::query()->whereIn('id', $list)->lockForUpdate()->pluck('id');
            $selectedIds = $selected->map(static fn($id): int => (int)$id)->all();
            if ($selectedIds === []) {
                throw new JSONException('所选商品不存在');
            }

            $baseCount = $baseChanges === []
                ? 0
                : \App\Model\Commodity::query()->whereIn('id', $selectedIds)->update($baseChanges);
            $sharedCount = $sharedChanges === []
                ? 0
                : \App\Model\Commodity::query()
                    ->whereIn('id', $selectedIds)
                    ->where('shared_id', '>', 0)
                    ->update($sharedChanges);

            return [
                'selected_count' => count($selectedIds),
                'updated_count' => max($baseCount, $sharedCount),
                'shared_updated_count' => $sharedCount,
            ];
        });

        ManageLog::log(
            $this->getManage(),
            "[批量设置]商品 {$result['selected_count']} 个，字段：" . implode(',', array_keys($changes))
        );
        return $this->json(200, '更新成功', $result);
    }
}
