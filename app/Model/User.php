<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $phone
 * @property string $qq
 * @property string $password
 * @property string $salt
 * @property string $app_key
 * @property string $avatar
 * @property float $balance
 * @property float $coin
 * @property float $total_coin
 * @property int $integral
 * @property string $create_time
 * @property string $login_time
 * @property string $last_login_time
 * @property string $login_ip
 * @property string $last_login_ip
 * @property int $pid
 * @property int $status
 * @property int $business_level
 * @property float $recharge
 * @property int $settlement
 * @property string $nicename
 * @property string $alipay
 * @property string $wechat
 */
class User extends Model
{
    /**
     * @var string
     */
    protected $table = 'user';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'settlement' => 'integer', 'business_level' => 'integer', 'balance' => 'float', 'coin' => 'float', 'total_coin' => 'float', 'integral' => 'integer', 'pid' => 'integer', 'recharge' => 'float', 'status' => 'integer'];

    /**
     * @var string[]
     */
    protected $appends = ['group'];

    /**
     * @return \App\Model\UserGroup|null
     */
    public function getGroupAttribute(): ?UserGroup
    {
        return UserGroup::get((float)$this->attributes['recharge']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne|null
     */
    public function parent(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "pid");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne|null
     */
    public function businessLevel(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BusinessLevel::class, "id", "business_level");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne|null
     */
    public function business(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Business::class, "user_id", "id");
    }
}