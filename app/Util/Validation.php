<?php
declare(strict_types=1);

namespace App\Util;


class Validation
{
    /**
     * @param string $username
     * @return bool
     */
    public static function username(string $username): bool
    {
        if (mb_strlen($username) < 6) {
            return false;
        }
        return true;
    }

    /**
     * @param string $email
     * @return bool
     */
    public static function email(string $email): bool
    {
        if (preg_match("/\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*/", $email)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $phone
     * @return bool
     */
    public static function phone(string $phone): bool
    {
        if (preg_match("/^(13[0-9]|14[5|7]|15[0|1|2|3|5|6|7|8|9]|18[0|1|2|3|5|6|7|8|9])\d{8}$/", $phone)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $password
     * @return bool
     */
    public static function password(string $password): bool
    {
        if (mb_strlen($password) < 6) {
            return false;
        }
        return true;
    }


    /**
     * 验证域名
     * @param string $domain
     * @return bool
     */
    public static function domain(string $domain): bool
    {
        if (preg_match("/^(?=^.{3,255}$)[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+$/", $domain)) {
            return true;
        }
        return false;
    }

}