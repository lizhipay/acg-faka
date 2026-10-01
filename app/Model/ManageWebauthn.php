<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * 后台 passkey(WebAuthn) 凭证。
 *
 * @property int $id
 * @property int $manage_id
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
class ManageWebauthn extends Model
{
    protected $table = 'manage_webauthn';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'manage_id' => 'integer',
        'sign_count' => 'integer',
    ];
}
