<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * 会员 passkey(WebAuthn) 凭证。
 *
 * @property int $id
 * @property int $user_id
 * @property string $credential_id 凭证ID(base64url)
 * @property string $public_key 凭证公钥(PEM)
 * @property int $sign_count 签名计数器
 * @property string|null $transports
 * @property string|null $aaguid
 * @property string $name 凭证标签
 * @property string $created_time
 * @property string|null $last_used_time
 * @property string|null $last_used_ip
 */
class UserWebauthn extends Model
{
    protected $table = 'user_webauthn';

    public $timestamps = false;

    protected $hidden = ['public_key'];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'sign_count' => 'integer',
    ];
}
