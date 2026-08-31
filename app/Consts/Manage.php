<?php
declare(strict_types=1);

namespace App\Consts;

/**
 * Interface Manage
 * @package App\Consts
 */
interface Manage
{
    /**
     * 管理员SESSION会话名称
     */
    const SESSION = "MANAGE_USER";

    /**
     * 当前请求已验证的管理员设备会话。
     */
    const SESSION_RECORD = "MANAGE_SESSION_RECORD";
}
