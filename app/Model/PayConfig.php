<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * 支付插件的一套配置（一个插件可以有多套，对应多个商户号）。
 *
 * config 存扁平JSON对象，键取自插件自己的 Config/Submit 定义，与旧的 Config/Config.php 同形，
 * 所以插件里的 $this->config['pid'] 写法不用改。读写统一走 \App\Util\PayProfile。
 *
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property string|null $config
 * @property int $sort
 * @property string $create_time
 * @property string|null $update_time
 */
class PayConfig extends Model
{
    /**
     * @var string
     */
    protected $table = "pay_config";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'sort' => 'integer'];
}
