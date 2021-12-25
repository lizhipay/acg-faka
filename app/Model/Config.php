<?php
declare (strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;
use Kernel\Exception\JSONException;

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


    /**
     * 为了方便，在这里直接静态get
     * @param string $key
     * @return string
     * @throws \Kernel\Exception\JSONException
     */
    public static function get(string $key): string
    {
        $cfg = Config::query()->where("key", $key)->first();
        if (!$cfg) {
            throw new JSONException("没有找到该配置选项");
        }
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
    }

}