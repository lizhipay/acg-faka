<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\Date;
use Illuminate\Database\Eloquent\Model;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Exception\JSONException;

/**
 * @property float $amount
 * @property float $balance
 * @property string $create_time
 * @property int $id
 * @property string $log
 * @property int $owner
 * @property int $type
 * @property int $currency
 */
class Bill extends Model
{
    const TYPE_ADD = 1;
    const TYPE_SUB = 0;

    /**
     * @var string
     */
    protected $table = "bill";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['amount' => 'float', 'balance' => 'float', 'id' => 'integer', 'owner' => 'integer', 'type' => 'integer', 'currency' => 'integer'];


    public function owner(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    /**
     * @param \App\Model\User $user
     * @param float $amount
     * @param int $type
     * @param string $log
     * @param int $currency
     * @param bool $total
     * @throws \Kernel\Exception\JSONException
     */
    #[NoReturn] public static function create(User $user, float $amount, int $type, string $log, int $currency = 0, bool $total = true): void
    {
        if ($currency == 0) {
            $user->balance = $type == 0 ? $user->balance - $amount : $user->balance + $amount;

            if ($user->balance < 0) {
                throw new JSONException("余额不足");
            }

            if ($type == self::TYPE_ADD && $total) {
                $user->recharge = $user->recharge + $amount;
            }
        } else {
            $user->coin = $type == 0 ? $user->coin - $amount : $user->coin + $amount;

            if ($user->coin < 0) {
                throw new JSONException("硬币不足");
            }

            if ($type == self::TYPE_ADD && $total) {
                $user->total_coin = $user->total_coin + $amount;
            }
        }

        $user->save();
        $bill = new self();
        $bill->owner = $user->id;
        $bill->amount = $amount;
        $bill->currency = $currency;
        $bill->balance = $currency == 0 ? $user->balance : $user->coin;
        $bill->type = $type;
        $bill->log = $log;
        $bill->create_time = Date::current();
        $bill->save();
    }
}