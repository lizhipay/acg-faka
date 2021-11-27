<?php

namespace Yurun\PaySDK\Lib;

class Util
{
    private function __construct()
    {
    }

    /**
     * 获取北京时间的时间戳.
     *
     * 此方法可以无视默认时区设置，获取到真实准确的时间戳
     *
     * @return int
     */
    public static function getBeijingTime()
    {
        return strtotime(gmdate('Y-m-d H:i:s')) + 8 * 60 * 60;
    }
}
