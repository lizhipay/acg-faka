<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\Client;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $shop_name
 * @property string $title
 * @property string $notice
 * @property string $service_qq
 * @property string $service_url
 * @property string $subdomain
 * @property string $topdomain
 * @property string $create_time
 * @property int $master_display
 */
class Business extends Model
{
    /**
     * @var string
     */
    protected $table = "business";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'user_id' => 'integer', 'master_display' => 'integer'];

    /**
     * @return Business|mixed
     */
    public static function get(): ?Business
    {
        $domain = Client::getDomain();
        return self::query()->where("subdomain", $domain)->first() ?? self::query()->where("topdomain", $domain)->first();
    }

    /**
     * @return bool
     */
    public static function state(): bool
    {
        $domain = Client::getDomain();
        return self::query()->where("subdomain", $domain)->exists() || self::query()->where("topdomain", $domain)->exists();
    }


    public function user(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }
}