<?php
declare (strict_types=1);

namespace App\Model;


use App\Util\Context;
use Illuminate\Database\Eloquent\Model;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Binary;
use Kernel\Util\File;

/**
 * @property int $id
 * @property string $key
 * @property string $value
 */
class Config extends Model
{
    /**
     * @var string
     */
    protected $table = 'config';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer'];

    private const CACHE_FILE = BASE_PATH . "/runtime/config";

    /**
     * @return int
     * @throws RuntimeException
     */
    public static function getSessionExpire(): int
    {
        $expire = self::get("session_expire") ?: (86400 * 30);
        if ($expire < 120) {
            return 86400 * 30;
        }
        return (int)$expire;
    }


    /**
     * 为了方便，在这里直接静态get
     * @param string $key
     * @return string
     * @throws RuntimeException
     */
    public static function get(string $key): string
    {
        $cacheKey = "_DB_CONFIG_" . $key;
        $cache = Context::get($cacheKey);

        if ($cache) {
            return (string)$cache;
        }

        $configs = File::read(self::CACHE_FILE, function (string $contents) {
            return Binary::inst()->unpack($contents);
        }) ?: [];

        if (isset($configs[$key])) {
            Context::set($cacheKey, $configs[$key]);
            return (string)$configs[$key];
        }
        $cfg = Config::query()->where("key", $key)->first();
        if (!$cfg) {
            return "";
        }

        File::writeForLock(self::CACHE_FILE, function (string $contents) use ($cfg, $key) {
            $configs = Binary::inst()->unpack($contents) ?: [];
            $configs[$key] = $cfg->value;
            return Binary::inst()->pack($configs);
        });
        //存储
        Context::set($cacheKey, $cfg->value);

        return (string)$cfg->value;
    }

    /**
     * @return array
     */
    public static function list(): array
    {
        $cfg = Config::query()->get();
        $list = [];
        foreach ($cfg as $item) {
            $list[$item->key] = $item->value;
        }
        return $list;
    }


    /**
     * @param string $key
     * @param string|int $value
     * @throws RuntimeException
     */
    public static function put(string $key, string|int $value): void
    {
        $cfg = Config::query()->where("key", $key)->first();
        if (!$cfg) {
            $cfg = new Config();
            $cfg->key = $key;
        }
        $cfg->value = $value;
        $cfg->save();

        File::writeForLock(self::CACHE_FILE, function (string $contents) use ($cfg, $key) {
            $configs = Binary::inst()->unpack($contents);
            $configs[$key] = $cfg->value;
            return Binary::inst()->pack($configs);
        });
    }

}