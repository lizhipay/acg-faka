<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property float $amount
 * @property float $cost
 * @property int $commodity_id
 * @property int $card_id
 * @property string $contact
 * @property int $create_device
 * @property string $create_ip
 * @property string $create_time
 * @property int $delivery_status
 * @property int $id
 * @property int $owner
 * @property int $user_id
 * @property int $card_num
 * @property string $password
 * @property int $pay_id
 * @property string $pay_time
 * @property string $pay_url
 * @property string $secret
 * @property int $status
 * @property int $from
 * @property int $substation_user_id
 * @property string $trade_no
 * @property string $widget
 * @property string|null $leave_message 发货留言快照（下单时从商品复制，见 issue #813）
 * @property float $rent
 * @property float $rebate
 * @property float $premium
 * @property float $divide_amount
 * @property string $race
 * @property string $request_no
 * @property array $sku
 */
class Order extends Model
{
    /**
     * @var string
     */
    protected $table = "order";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['amount' => 'float', 'gateway_amount' => 'float', 'cost' => 'float', 'rebate' => 'float', 'divide_amount' => 'float', 'rent' => 'float', 'premium' => 'float', 'user_id' => 'integer', 'substation_user_id' => 'integer', 'from' => 'integer', 'commodity_id' => 'integer', 'card_id' => 'integer', 'card_num' => 'integer', 'create_device' => 'integer', 'delivery_status' => 'integer', 'id' => 'integer', 'owner' => 'integer', 'pay_id' => 'integer', 'status' => 'integer', 'sku' => 'json'];

    /**
     * 当前商户作为供货方或实际销售分站时可见。
     */
    public function scopeVisibleToMerchant(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $scope) use ($userId) {
            $scope->where('user_id', $userId)
                ->orWhere('substation_user_id', $userId);
        });
    }

    /**
     * 仅限真实供货方，用于交付内容和发货等敏感能力。
     */
    public function scopeSuppliedByMerchant(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function owner(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function user(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }

    public function commodity(): ?HasOne
    {
        return $this->hasOne(Commodity::class, "id", "commodity_id");
    }

    public function pay(): ?HasOne
    {
        return $this->hasOne(Pay::class, "id", "pay_id");
    }

    public function card(): ?HasOne
    {
        return $this->hasOne(Card::class, "id", "card_id");
    }

    public function promote(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "from");
    }

    public function substationUser(): HasOne
    {
        return $this->hasOne(User::class, "id", "substation_user_id");
    }

    //优惠卷
    public function coupon(): ?HasOne
    {
        return $this->hasOne(Coupon::class, "id", "coupon_id");
    }

    /**
     * 解析订单要展示的发货留言。
     *
     * 优先用下单时拍的快照(leave_message)；3.5.9 之前的老订单没有快照，
     * 回退到商品表当前的留言，保证升级后历史订单不会突然变空白。见 issue #813
     *
     * @param mixed $snapshot 订单上的快照
     * @param mixed $commodityMessage 商品表当前的留言
     * @return string|null
     */
    public static function resolveLeaveMessage(mixed $snapshot, mixed $commodityMessage): ?string
    {
        //发货留言是站长自填、买家可见的文案（订单上存的是下单那一刻的商品快照，不是买家输入），
        //这里是它给买家的唯一汇聚点，统一翻译，各出口不必各自记得(issue #832)
        $snapshot = is_string($snapshot) ? trim($snapshot) : '';
        if ($snapshot !== '') {
            return lang($snapshot, "dyn");
        }
        $fallback = is_string($commodityMessage) ? trim($commodityMessage) : '';
        return $fallback !== '' ? lang($fallback, "dyn") : null;
    }
}
