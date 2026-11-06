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
     * 支付插件日志目录。
     *
     * 以前日志写在 app/Pay/<handle>/runtime.log，也就是站点根目录里的插件目录下，
     * 能不能被直接下载全看伪静态里那条 .log 拒绝规则有没有抄全。放到 runtime/ 下以后，
     * 跟其他缓存、锁文件一样由 ^runtime/ 这条最基础的规则挡住，目录里再放一份拒绝访问的 .htaccess 兜底；
     * 日志也不再混在插件目录里，打包、覆盖升级插件时不会被带走或冲掉。
     */
    private const LOG_DIRECTORY = '/runtime/log/pay';

    /**
     * 日志里按字段名整段打码的字段：密钥、签名、卡密（订单的 secret 字段）之类。
     */
    private const LOG_SECRET_FIELD = '/(?:key|secret|token|password|passwd|pwd|private|credential|sign|cert|pem|salt)/i';

    /**
     * 日志里按字段名保留首尾、中间打码的字段：买家联系方式、订单号、网关流水号之类。
     * 留首尾是为了排查回调时还能对上是哪一笔。
     */
    private const LOG_MASK_FIELD = '/(?:contact|e?mail|mobile|phone|qq|open_?id|buyer_(?:id|logon_id|user_id|email|open_id)|payer|account|trade_no|tradeno|order_?(?:no|id|sn)|out_trade|transaction_id|trade_id)/i';

    /**
     * @param string $handle
     * @return string|null
     */
    public static function logPath(string $handle): ?string
    {
        //handle 会变成文件名，跟 renderTemplate 一样在用到它的地方把关
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $handle)) {
            return null;
        }
        return BASE_PATH . self::LOG_DIRECTORY . '/' . $handle . '.log';
    }

    /**
     * 写入前打码。插件传进来的通常是回调报文原文（query string、JSON、print_r/var_export），
     * 按 "字段名 分隔符 值" 的形式识别；后台查看日志时还会再按配置里的密钥值打一遍码。
     *
     * @param string $message
     * @return string
     */
    public static function redact(string $message): string
    {
        //head：可选引号/方括号包住的字段名 + 分隔符（=、:、=>），后面跟双引号、单引号或裸值
        $pattern = '/(?<![A-Za-z0-9_.\-])(?<head>(?<q>["\']?)\[?(?<name>[A-Za-z_][A-Za-z0-9_.\-]*)\]?\k<q>\s*(?:=>|=|:)\s*)'
            . '(?:"(?<dq>(?:[^"\\\\]|\\\\.)*)"|\'(?<sq>[^\']*)\'|(?<raw>[^\s&,;"\'<>{}\[\]()?=]+=*))/';

        return (string)preg_replace_callback($pattern, static function (array $m): string {
            if (preg_match(self::LOG_SECRET_FIELD, $m['name'])) {
                $secret = true;
            } elseif (preg_match(self::LOG_MASK_FIELD, $m['name'])) {
                $secret = false;
            } else {
                return $m[0];
            }

            if (($m['raw'] ?? '') !== '') {
                [$quote, $value] = ['', $m['raw']];
            } elseif (($m['sq'] ?? '') !== '') {
                [$quote, $value] = ["'", $m['sq']];
            } elseif (($m['dq'] ?? '') !== '') {
                [$quote, $value] = ['"', $m['dq']];
            } else {
                return $m[0];
            }

            return $m['head'] . $quote . ($secret ? '[REDACTED]' : self::mask($value)) . $quote;
        }, $message);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function mask(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 4) {
            return '***';
        }
        $keep = $length >= 12 ? 4 : ($length >= 7 ? 2 : 1);
        return mb_substr($value, 0, $keep) . '***' . mb_substr($value, -$keep);
    }

    /**
     * @param string $handle
     * @param string $type
     * @param string $message
     */
    public static function log(string $handle, string $type, string $message): void
    {
        $path = self::logPath($handle);
        if ($path === null) {
            return;
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
        if (!is_file($directory . '/.htaccess')) {
            @file_put_contents($directory . '/.htaccess', "<IfModule mod_authz_core.c>\n Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n Deny from all\n</IfModule>\n");
            @file_put_contents($directory . '/index.html', '');
        }

        file_put_contents($path, "[{$type}][" . Date::current() . "]:" . self::redact($message) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}