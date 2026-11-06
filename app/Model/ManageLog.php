<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\Client;
use App\Util\Date;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $content
 * @property string $create_ip
 * @property string $create_time
 * @property string $email
 * @property int $id
 * @property string $nickname
 * @property int $risk
 * @property string $ua
 */
class ManageLog extends Model
{
    /**
     * @var string
     */
    protected $table = "manage_log";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'risk' => 'integer'];


    /**
     * @param Manage $manage
     * @param string $content
     * @return void
     */
    public static function log(Manage $manage, string $content): void
    {
        $manageLog = new ManageLog();
        $manageLog->email = $manage->email;
        $manageLog->nickname = $manage->nickname;
        //content 是 varchar(128)：严格模式下超长会报错，而这时业务改动往往已经提交了
        $manageLog->content = mb_substr($content, 0, 128, 'UTF-8');
        $manageLog->create_time = Date::current();
        $manageLog->create_ip = Client::getAddress();
        $manageLog->ua = Client::getUserAgent();
        $manageLog->risk = $manage->last_login_ip != $manageLog->create_ip ? 1 : 0;
        $manageLog->save();
    }
}