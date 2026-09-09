<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 商品的连带清理。
 *
 * 原来只长在 Admin\Api\Commodity 里（private），于是「删分类」想连商品一起删时
 * 没得复用——两处各写一套的结果必然是口径分叉。抽出来，谁删商品都走这一份。
 */
final class CommodityPurge
{
    /**
     * 连带删除商品名下的全部关联数据。
     *
     * 顺序是有讲究的：先删子表再删主表，否则 order_option / ticket_message
     * 会变成谁也引用不到的孤儿行。整个过程由 del() 的事务包着，中途失败全回滚。
     *
     * !! 这会真的删掉订单和工单 !!
     * 也就是说该商品的销售与售后历史一并消失，账单统计里对不上。
     * 这是产品上明确选择的行为（删商品即清干净），不是疏漏。
     */
    public static function cascade(array $commodityIds): void
    {
        //与 commodityDeleteImpact 同一套缺表降级：老库缺哪张就跳过哪张（issue #837）
        $orderIds = \App\Model\Order::query()
            ->whereIn('commodity_id', $commodityIds)
            ->pluck('id')
            ->map(static fn($id): int => (int)$id)
            ->all();
        if ($orderIds !== [] && \App\Util\Schema::tableExists('order_option')) {
            \App\Model\OrderOption::query()->whereIn('order_id', $orderIds)->delete();
        }

        $hasTicket = \App\Util\Schema::tableExists('ticket');
        if ($hasTicket) {
            $ticketIds = \App\Model\Ticket::query()
                ->whereIn('commodity_id', $commodityIds)
                ->pluck('id')
                ->map(static fn($id): int => (int)$id)
                ->all();
            if ($ticketIds !== [] && \App\Util\Schema::tableExists('ticket_message')) {
                \App\Model\TicketMessage::query()->whereIn('ticket_id', $ticketIds)->delete();
            }
        }

        \App\Model\Order::query()->whereIn('commodity_id', $commodityIds)->delete();
        if ($hasTicket) {
            \App\Model\Ticket::query()->whereIn('commodity_id', $commodityIds)->delete();
        }
        \App\Model\Card::query()->whereIn('commodity_id', $commodityIds)->delete();
        \App\Model\Coupon::query()->whereIn('commodity_id', $commodityIds)->delete();
        if (\App\Util\Schema::tableExists('user_commodity')) {
            \App\Model\UserCommodity::query()->whereIn('commodity_id', $commodityIds)->delete();
        }

        if (\App\Util\Schema::tableExists('commodity_group')) {
            self::detachFromCommodityGroups($commodityIds);
        }
    }

    /**
     * 商品分组把成员存成 JSON 数组，没有外键，只能逐个读出来重写。
     * 删的是「成员引用」不是分组本身 —— 分组里通常还挂着别的商品。
     */
    private static function detachFromCommodityGroups(array $commodityIds): void
    {
        $lookup = array_fill_keys($commodityIds, true);

        foreach (\App\Model\CommodityGroup::query()->orderBy('id')->lockForUpdate()->get() as $group) {
            $references = $group->commodity_list;
            if (!is_array($references)) {
                $references = [$references];
            }

            $kept = [];
            $changed = false;
            foreach ($references as $reference) {
                if (is_int($reference)) {
                    $referenceId = $reference;
                } elseif (is_string($reference) && ctype_digit(trim($reference))) {
                    $referenceId = (int)trim($reference);
                } else {
                    $kept[] = $reference;
                    continue;
                }
                if (isset($lookup[$referenceId])) {
                    $changed = true;
                    continue;
                }
                $kept[] = $reference;
            }

            if ($changed) {
                $group->commodity_list = array_values($kept);
                $group->save();
            }
        }
    }
}
