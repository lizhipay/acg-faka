<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property string $wallet_address
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
     * 这些列绝不能随 toArray()/toJson() 外泄（后台会员列表 /admin/api/user/data 会整行序列化）：
     * 密码哈希+salt 泄露可离线爆破；app_key 泄露可被用来经对接 API 冒用会员下单/扣余额。
     * 注意 $hidden 只作用于 toArray/jsonSerialize：模板 #{$user.app_key}、resetKey 走 ArrayAccess/属性
     * 读取不受影响，会员本人仍能看到自己的 app_key；JWT 签名读 $user->password 也照常。
     * @var string[]
     */
    protected $hidden = ['password', 'salt', 'app_key', 'totp_secret', 'totp_recovery'];

    /**
     * @var string[]
     */
    protected $appends = ['group'];

    /**
     * @return UserGroup|null
     */
    public function getGroupAttribute(): ?UserGroup
    {
        return UserGroup::get((float)$this->attributes['recharge']);
    }

    /**
     * @return HasOne|null
     */
    public function parent(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "pid");
    }

    /**
     * @return HasOne|null
     */
    public function businessLevel(): ?HasOne
    {
        return $this->hasOne(BusinessLevel::class, "id", "business_level");
    }

    /**
     * @return HasOne|null
     */
    public function business(): ?HasOne
    {
        return $this->hasOne(Business::class, "user_id", "id");
    }
}