<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Commodity;
use App\Model\Order;
use Kernel\Exception\JSONException;

/**
 * Per-member purchase limit (commodity.purchase_count), counted in items, not orders.
 * Items held = paid orders plus unpaid orders still inside the payment window, so an
 * expired unpaid order stops holding the quota and one large order cannot exceed it.
 * Refunded orders (#1145) are fully undone and release their quota.
 */
class PurchaseLimit
{
    /** Unpaid orders older than this no longer hold quota (same threshold as the admin "clear unpaid orders" action). */
    public const HOLD = 1800;

    /**
     * Items of the commodity this member already holds.
     */
    public static function held(int $owner, int $commodityId): int
    {
        return (int)Order::query()
            ->where("owner", $owner)
            ->where("commodity_id", $commodityId)
            ->unrefunded()
            ->where(function ($query) {
                $query->where("status", 1)->orWhere(function ($query) {
                    $query->where("status", 0)->where("create_time", ">=", date("Y-m-d H:i:s", time() - self::HOLD));
                });
            })
            ->sum("card_num");
    }

    /**
     * Items the member may still buy; null when the commodity has no limit or the buyer is a guest.
     */
    public static function left(Commodity $commodity, int $owner): ?int
    {
        $limit = (int)$commodity->purchase_count;
        if ($limit <= 0 || $owner <= 0) {
            return null;
        }
        return max(0, $limit - self::held($owner, (int)$commodity->id));
    }

    /**
     * @throws JSONException
     */
    public static function assert(Commodity $commodity, int $owner, int $num): void
    {
        $left = self::left($commodity, $owner);
        if ($left === null || $num <= $left) {
            return;
        }
        $limit = (int)$commodity->purchase_count;
        throw new JSONException($left > 0 ? "该商品每人限购{$limit}件，你还能购买{$left}件" : "该商品每人限购{$limit}件，你已经买满了");
    }
}
