<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * 会员对接白名单 IP。开启「资金操作二次验证」后，对接接口（店铺共享 / API）只放行这里登记的来源用余额下单。
 *
 * @property int $id
 * @property int $user_id
 * @property string $ip 规范化后的单个 IP 或 CIDR 网段（1.2.3.4、1.2.3.0/24、2001:db8::/48）
 * @property string $note
 * @property string $create_time
 * @property string|null $last_used_time
 */
class UserIpWhitelist extends Model
{
    protected $table = "user_ip_whitelist";

    public $timestamps = false;

    protected $casts = ['id' => 'integer', 'user_id' => 'integer'];
}
