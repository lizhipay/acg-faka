<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use Kernel\Exception\JSONException;

final class CallbackIpWhitelist
{
    public const ENABLED_CONFIG = 'callback_ip_whitelist';
    public const RULES_CONFIG = 'callback_ip_whitelist_rules';

    private const MAX_RULES_LENGTH = 8192;
    private const MAX_RULES = 256;
    private const MAX_RULE_LENGTH = 128;

    /**
     * Normalize an administrator-supplied callback IP allowlist.
     *
     * Supported rules:
     * - exact IPv4/IPv6 addresses;
     * - IPv4/IPv6 CIDR ranges;
     * - trailing IPv4 wildcards, such as 203.0.113.*.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeRules(string $rules): string
    {
        if (strlen($rules) > self::MAX_RULES_LENGTH) {
            throw new \InvalidArgumentException('回调白名单 IP 规则总长度不能超过 8192 字节');
        }

        $rules = trim(str_replace(["\r\n", "\r"], "\n", $rules));
        if ($rules === '') {
            return '';
        }

        $normalized = [];
        $entryCount = 0;
        foreach (explode("\n", $rules) as $lineIndex => $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (strlen($rule) > self::MAX_RULE_LENGTH) {
                throw new \InvalidArgumentException('回调白名单第 ' . ($lineIndex + 1) . ' 行过长');
            }
            $entryCount++;
            if ($entryCount > self::MAX_RULES) {
                throw new \InvalidArgumentException('回调白名单最多允许 256 条 IP 规则');
            }

            try {
                $rule = str_contains($rule, '*')
                    ? self::normalizeWildcardRule($rule)
                    : self::normalizeAddressRule($rule);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException(
                    '回调白名单第 ' . ($lineIndex + 1) . ' 行格式不正确'
                );
            }
            $normalized[$rule] = true;
        }

        return implode("\n", array_keys($normalized));
    }

    public static function allows(string $clientIp, string $rules): bool
    {
        $clientIp = self::normalizeIp($clientIp);
        if ($clientIp === null) {
            return false;
        }

        try {
            $rules = self::normalizeRules($rules);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if ($rules === '') {
            return false;
        }

        foreach (explode("\n", $rules) as $rule) {
            if (str_contains($rule, '*')) {
                if (self::matchesWildcard($clientIp, $rule)) {
                    return true;
                }
                continue;
            }
            if (self::matchesAddressRule($clientIp, $rule)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @throws JSONException
     */
    public static function enforce(): void
    {
        $enabled = Config::get(self::ENABLED_CONFIG);
        if ($enabled === '' || $enabled === '0') {
            return;
        }

        $clientIp = Client::getAddress();
        if ($enabled === '1'
            && $clientIp !== ''
            && self::allows($clientIp, Config::get(self::RULES_CONFIG))) {
            return;
        }

        http_response_code(403);
        throw new JSONException('回调来源 IP 未通过白名单验证', 403);
    }

    private static function normalizeAddressRule(string $rule): string
    {
        if (substr_count($rule, '/') > 1) {
            throw new \InvalidArgumentException('invalid CIDR');
        }

        $parts = explode('/', $rule, 2);
        $ip = self::normalizeIp($parts[0]);
        if ($ip === null) {
            throw new \InvalidArgumentException('invalid IP');
        }
        if (!isset($parts[1])) {
            return $ip;
        }
        if (!preg_match('/^\d{1,3}$/D', $parts[1])) {
            throw new \InvalidArgumentException('invalid CIDR prefix');
        }

        $prefix = (int)$parts[1];
        $maxPrefix = str_contains($ip, ':') ? 128 : 32;
        if ($prefix < 1 || $prefix > $maxPrefix) {
            throw new \InvalidArgumentException('invalid CIDR range');
        }

        return self::networkAddress($ip, $prefix) . '/' . $prefix;
    }

    private static function normalizeWildcardRule(string $rule): string
    {
        if (str_contains($rule, '/') || str_contains($rule, ':')) {
            throw new \InvalidArgumentException('invalid wildcard');
        }

        $segments = explode('.', $rule);
        if (count($segments) !== 4) {
            throw new \InvalidArgumentException('invalid wildcard');
        }

        $wildcardSeen = false;
        $fixedSegments = 0;
        foreach ($segments as &$segment) {
            if ($segment === '*') {
                $wildcardSeen = true;
                continue;
            }
            if ($wildcardSeen || !ctype_digit($segment) || (int)$segment > 255) {
                throw new \InvalidArgumentException('invalid wildcard');
            }
            $segment = (string)(int)$segment;
            $fixedSegments++;
        }
        unset($segment);

        if (!$wildcardSeen || $fixedSegments === 0) {
            throw new \InvalidArgumentException('trust-all wildcard is not allowed');
        }
        return implode('.', $segments);
    }

    private static function normalizeIp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }

        $normalized = inet_ntop($packed);
        return $normalized === false ? null : $normalized;
    }

    private static function networkAddress(string $ip, int $prefix): string
    {
        $binary = inet_pton($ip);
        if ($binary === false) {
            throw new \InvalidArgumentException('invalid network address');
        }

        $length = strlen($binary);
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($remainingBits > 0) {
            $binary[$wholeBytes] = chr(
                ord($binary[$wholeBytes]) & ((0xff << (8 - $remainingBits)) & 0xff)
            );
            $wholeBytes++;
        }
        for ($index = $wholeBytes; $index < $length; $index++) {
            $binary[$index] = "\0";
        }

        $network = inet_ntop($binary);
        if ($network === false) {
            throw new \InvalidArgumentException('invalid network address');
        }
        return $network;
    }

    private static function matchesWildcard(string $clientIp, string $rule): bool
    {
        if (str_contains($clientIp, ':')) {
            return false;
        }

        $clientSegments = explode('.', $clientIp);
        $ruleSegments = explode('.', $rule);
        foreach ($ruleSegments as $index => $segment) {
            if ($segment === '*') {
                return true;
            }
            if (!isset($clientSegments[$index]) || $clientSegments[$index] !== $segment) {
                return false;
            }
        }
        return true;
    }

    private static function matchesAddressRule(string $clientIp, string $rule): bool
    {
        $parts = explode('/', $rule, 2);
        $network = self::normalizeIp($parts[0]);
        if ($network === null) {
            return false;
        }

        $clientBinary = inet_pton($clientIp);
        $networkBinary = inet_pton($network);
        if ($clientBinary === false
            || $networkBinary === false
            || strlen($clientBinary) !== strlen($networkBinary)) {
            return false;
        }
        if (!isset($parts[1])) {
            return hash_equals($networkBinary, $clientBinary);
        }

        $prefix = (int)$parts[1];
        $maxPrefix = strlen($clientBinary) * 8;
        if ($prefix < 1 || $prefix > $maxPrefix) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && !hash_equals(
            substr($networkBinary, 0, $wholeBytes),
            substr($clientBinary, 0, $wholeBytes)
        )) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($networkBinary[$wholeBytes]) & $mask)
            === (ord($clientBinary[$wholeBytes]) & $mask);
    }
}
