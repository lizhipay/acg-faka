<?php
/**
 * Created by PhpStorm.
 * User: Mr_GooN
 * Date: 2017/10/19
 * Time: 11:26
 */
namespace Mrgoon\AliyunSmsSdk;

class Autoload {
    public static function config()
    {
        /**
         * include config.php
         */
        if (!defined("SMS_PATH")) {
            define("SMS_PATH", dirname(__FILE__) . '/');
        }

        include( SMS_PATH . 'Config.php');
    }
}