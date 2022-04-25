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
        $manageLog->content = $content;
        $manageLog->create_time = Date::current();
        $manageLog->create_ip = Client::getAddress();
        $manageLog->ua = Client::getUserAgent();
        $manageLog->risk = $manage->last_login_ip != $manageLog->create_ip ? 1 : 0;
        $manageLog->save();
    }
}