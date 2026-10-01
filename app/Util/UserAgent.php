<?php
declare(strict_types=1);

namespace App\Util;

/**
 * User-Agent 友好解析：把原始 UA 串翻成「浏览器（版本）· 系统」。
 * 面向用户的界面只展示这里的结果，绝不直出原始 UA。
 */
class UserAgent
{
    /**
     * @return array{type:string,os:string,browser:string,version:string,browser_full:string,label:string,label_full:string}
     */
    public static function describe(string $ua): array
    {
        $ua = trim($ua);
        if ($ua === '') {
            return ['type' => 'desktop', 'os' => '未知', 'browser' => '未知', 'version' => '', 'browser_full' => '未知', 'label' => '未知', 'label_full' => '未知'];
        }

        $type = preg_match('/iPad|Tablet/i', $ua) ? 'tablet'
            : (preg_match('/Android|iPhone|Mobile/i', $ua) ? 'mobile' : 'desktop');

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => $type === 'mobile' ? '移动设备' : '电脑',
        };

        [$browser, $version] = self::browser($ua);
        $browserFull = $version !== '' ? ($browser . ' ' . $version) : $browser;

        return [
            'type' => $type,
            'os' => $os,
            'browser' => $browser,
            'version' => $version,
            'browser_full' => $browserFull,
            'label' => trim($os . ' · ' . $browser),
            'label_full' => trim($browserFull . ' · ' . $os),
        ];
    }

    /** @return array{0:string,1:string} [名称, 主版本号] */
    private static function browser(string $ua): array
    {
        if (preg_match('/Edg(?:A|iOS)?\/(\d+)/i', $ua, $m)) return ['Edge', $m[1]];
        if (preg_match('/OPR\/(\d+)/i', $ua, $m) || preg_match('/Opera\/(\d+)/i', $ua, $m)) return ['Opera', $m[1]];
        if (preg_match('/(?:CriOS|Chrome)\/(\d+)/i', $ua, $m)) return ['Chrome', $m[1]];
        if (preg_match('/(?:FxiOS|Firefox)\/(\d+)/i', $ua, $m)) return ['Firefox', $m[1]];
        if (preg_match('/Version\/(\d+)[.\d]*.*Safari/i', $ua, $m)) return ['Safari', $m[1]];
        if (preg_match('/Safari\//i', $ua)) return ['Safari', ''];
        return ['浏览器', ''];
    }
}
