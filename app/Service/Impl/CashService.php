<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Model\Bill;
use App\Model\User;
use App\Service\Cash;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Class CashService
 * @package App\Service\Impl
 */
class CashService implements Cash
{

    /**
     * @param float $amount
     */
    public function settlement(float $amount): void
    {
        $users = User::query()->where("coin", ">=", $amount)->get();
        $date = Date::current();
        foreach ($users as $user) {
            try {
                DB::transaction(function () use ($date, $user) {
                    $usr = User::query()->find($user->id);
                    $cash = new \App\Model\Cash();
                    $cash->user_id = $usr->id;
                    $cash->amount = $usr->coin;
                    $cash->type = 0;
                    $cash->card = $usr->settlement;
                    $cash->create_time = $date;
                    $cash->cost = 0;
                    $cash->status = 0;
                    //创建扣款订单
                    Bill::create($usr, $usr->coin, \App\Model\Bill::TYPE_SUB, "自动结算", 1);
                    $cash->save();
                });
            } catch (\Exception $e) {
            }
        }
    }
}