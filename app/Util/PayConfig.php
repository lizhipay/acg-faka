<?php
declare(strict_types=1);

namespace App\Util;


class PayConfig
{

    /**
     * @param string $handle
     * @return bool
     */
    public static function isValid(string $handle): bool
    {
        return is_file(BASE_PATH . '/app/Pay/' . $handle . '/Impl/Pay.php');
    }

    /**
     * @param string $handle
     * @return array|null
     */
    public static function config(string $handle): ?array
    {
        return require(BASE_PATH . '/app/Pay/' . $handle . '/Config/Config.php');
    }

    /**
     * @param string $handle
     * @return array|null
     */
    public static function info(string $handle): ?array
    {
        $path = BASE_PATH . '/app/Pay/' . $handle . '/Config/Info.php';

        if (!file_exists($path)) {
            return null;
        }

        return require($path);
    }


    /**
     * 本地收银台模板的相对路径，不安全或不存在时返回 null。
     *
     * code 在这里会变成文件名，所以把关必须放在这儿——不能指望后台保存时的校验：
     * 自定义 code 是发给支付网关的参数，本来就不该被"能不能当文件名"这件事绑架，
     * 而且历史数据、插件自带的声明都可能不合这个规矩。安全要在真正用到它的地方守。
     *
     * @param string $handle
     * @param string $code
     * @return string|null
     */
    public static function renderTemplate(string $handle, string $code): ?string
    {
        //只有能安全充当路径片段的才可能对应一个本地模板；其余（比如站长自填的网关 code）
        //本来就走跳转支付，没有本地视图，直接当"视图不存在"处理
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $handle)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,31}$/D', $code) || str_contains($code, '..')) {
            return null;
        }

        $relative = $handle . '/View/' . $code . '.html';
        $root = realpath(BASE_PATH . '/app/Pay');
        $path = realpath(BASE_PATH . '/app/Pay/' . $relative);

        //再确认解析后的真实路径没跑出 app/Pay（软链、大小写差异之类的兜底）
        if ($root === false || $path === false || !is_file($path)
            || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $relative;
    }


    /**
     * @param string $handle
     * @param string $type
     * @param string $message
     */
    public static function log(string $handle, string $type, string $message): void
    {
        $path = BASE_PATH . "/app/Pay/{$handle}/runtime.log";
        file_put_contents($path, "[{$type}][" . Date::current() . "]:" . $message . PHP_EOL, FILE_APPEND);
    }
}