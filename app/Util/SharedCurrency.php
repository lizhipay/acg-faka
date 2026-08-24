<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Util\Decimal;

/**
 * 店铺共享的跨币种换算。
 *
 * 对接协议本身不换算金额（上下游各自报自己的数字），当上游站点的货币与本站不同
 * 时（例：本站 USD、上游 CNY），所有从上游拉回来的金额必须在【协议边界】统一换算成
 * 本站货币——也就是 App\Service\Bind\Shared 的 items/item/inventory/valuation/draft
 * 出口。只在某一个消费方（比如自动货源插件）换算是错的：核心的 syncRemoteItem 会在
 * 商品详情页被访问时自动重同步价格，没换算的路径会把价格刷回原始数字，两边打架。
 *
 * 换算系数（factor）= 1 单位上游货币 值多少 本站货币：
 *   - 店铺填了结算汇率（currency_rate > 0）→ 直接用它（语义即 factor）；
 *   - 上游货币 == 本站货币 → 1（不换算，零开销旁路）；
 *   - 上游货币 == CNY → 1 / 站点汇率（站点汇率 = 1 本站货币值多少 CNY，
 *     见 App\Util\Currency::rate()，由汇率同步插件维护）；
 *   - 其他组合没有可靠的自动汇率来源 → 必须填结算汇率，否则在金额出口抛出
 *     明确的配置错误（宁可报错也不能按错误的数字卖货）。
 *
 * 旧库没有 currency 列时 Eloquent 读到 null → 按 CNY 处理；CNY 站点 + CNY 上游
 * factor 恒为 1，升级后行为逐字节不变。
 */
final class SharedCurrency
{
    /** 金额字段（商品行 / inventory 结果里出现的都在这） */
    private const MONEY_KEYS = ['price', 'user_price', 'factory_price', 'draft_premium'];

    /** 配置参数里存「绝对金额」的段（sku 是加价额，同样要换算） */
    private const CONFIG_FLAT_SECTIONS = ['category', 'wholesale', 'category_factory'];
    private const CONFIG_NESTED_SECTIONS = ['sku', 'category_wholesale'];

    /**
     * 纯函数版系数解析（可离线测试）。
     *
     * @param string $upCurrency 上游货币代码（空按 CNY）
     * @param string $manualRate 店铺填的结算汇率（1 上游货币 = ? 本站货币），空/0 = 自动
     * @param string $siteCode 本站货币代码
     * @param string $siteRate 站点汇率（1 本站货币 = ? CNY）
     * @return string|null bcmath 字符串系数；null = 无法自动解析
     */
    public static function resolveFactor(string $upCurrency, string $manualRate, string $siteCode, string $siteRate): ?string
    {
        $upCurrency = strtoupper(trim($upCurrency));
        if ($upCurrency === '') {
            $upCurrency = Currency::DEFAULT_CODE;
        }

        $manualRate = trim($manualRate);
        if ($manualRate !== '' && is_numeric($manualRate) && (float)$manualRate > 0) {
            return self::normalizeFactor($manualRate);
        }

        if ($upCurrency === strtoupper(trim($siteCode))) {
            return '1';
        }

        if ($upCurrency === Currency::DEFAULT_CODE) {
            //上游 CNY：1 CNY = 1/站点汇率 本站货币
            if (!is_numeric($siteRate) || (float)$siteRate <= 0) {
                return null;
            }
            return self::normalizeFactor(bcdiv('1', $siteRate, 6));
        }

        return null;
    }

    private static function normalizeFactor(string $factor): string
    {
        //6 位小数截断后为 0 视为不可用（比如汇率填了 0.0000001）
        $factor = bcadd($factor, '0', 6);
        return bccomp($factor, '0', 6) === 1 ? $factor : '0';
    }

    /**
     * 店铺的换算系数。'1' = 无需换算；抛异常 = 需要换算但配置不完整。
     *
     * @throws \Kernel\Exception\JSONException
     */
    public static function factor(\App\Model\Shared $shared): string
    {
        //不做静态缓存：本函数会跑在常驻守护里，缓存会让「管理员刚改的汇率」到重启才生效。
        //计算本身只有字符串比较和一次 bcdiv，每轮每店铺调用一次的成本可以忽略。
        $factor = self::resolveFactor(
            (string)($shared->currency ?? ''),
            (string)($shared->currency_rate ?? ''),
            Currency::code(),
            Currency::rate()
        );

        if ($factor === null || $factor === '0') {
            $upCurrency = strtoupper(trim((string)($shared->currency ?? ''))) ?: Currency::DEFAULT_CODE;
            throw new \Kernel\Exception\JSONException(
                "店铺[{$shared->name}]的货币为 {$upCurrency}，与本站 " . Currency::code()
                . " 之间没有可用汇率：请到「店铺共享」编辑该店铺，填写结算汇率（1 {$upCurrency} = ? " . Currency::code() . "）"
            );
        }
        return $factor;
    }

    /** 是否恒等换算（factor==1 时所有转换函数都应零开销直返） */
    public static function isIdentity(string $factor): bool
    {
        return bccomp($factor, '1', 6) === 0;
    }

    /**
     * 单个金额换算：half-up 保留两位（与 Currency::toCny 同口径）。
     * 非数字原样返回，绝不把脏数据变成 0。
     */
    public static function amount(mixed $value, string $factor): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }
        //is_numeric 接受的形态比 bcmath 宽得多：科学计数法（1.0E-6）、前后空白、
        //前导加号、".5" 这类缺整数位的写法……上游配置里这些脏数字真实存在（实测 pgid），
        //不先规范成 bcmath 认的十进制就会 ValueError 炸掉整轮同步
        $raw = trim((string)$value);
        if (!preg_match('/^-?\d+(\.\d+)?$/D', $raw)) {
            $raw = sprintf('%.6F', (float)$raw);
        }
        $converted = (new Decimal($raw, 6))->mul($factor)->add('0.005')->getAmount(2);
        //两位小数的表示下限：有价的东西换算后不足一分按一分（与 Currency::toCny 的
        //网关下限同哲学）。四舍五入到 0.00 意味着"0 元在售、上游收真钱"，是纯资损
        if (bccomp($converted, '0.01', 2) < 0 && (float)$raw > 0) {
            return '0.01';
        }
        return $converted;
    }

    /**
     * 配置参数（Ini 文本或数组）里的价格段换算。保持传入类型返回；
     * 解析失败原样返回（脏配置交给后续入口报错，这里不吞不改）。
     */
    public static function config(mixed $config, string $factor): mixed
    {
        if (self::isIdentity($factor) || $config === null || $config === '') {
            return $config;
        }

        $isString = !is_array($config);
        try {
            $parsed = $isString ? Ini::toArray((string)$config) : $config;
        } catch (\Throwable $e) {
            return $config;
        }
        if (!is_array($parsed)) {
            return $config;
        }

        foreach (self::CONFIG_FLAT_SECTIONS as $section) {
            if (!isset($parsed[$section]) || !is_array($parsed[$section])) {
                continue;
            }
            foreach ($parsed[$section] as $key => $value) {
                if (is_numeric($value)) {
                    $parsed[$section][$key] = self::amount($value, $factor);
                }
            }
        }
        foreach (self::CONFIG_NESTED_SECTIONS as $section) {
            if (!isset($parsed[$section]) || !is_array($parsed[$section])) {
                continue;
            }
            foreach ($parsed[$section] as $group => $options) {
                if (!is_array($options)) {
                    continue;
                }
                foreach ($options as $key => $value) {
                    if (is_numeric($value)) {
                        $parsed[$section][$group][$key] = self::amount($value, $factor);
                    }
                }
            }
        }

        try {
            return $isString ? Ini::toConfig($parsed) : $parsed;
        } catch (\Throwable $e) {
            return $config;
        }
    }

    /**
     * 商品行（items 树的 child / item 详情 / inventory 结果）换算。
     */
    public static function item(array $item, string $factor): array
    {
        if (self::isIdentity($factor)) {
            return $item;
        }
        foreach (self::MONEY_KEYS as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                $item[$key] = self::amount($item[$key], $factor);
            }
        }
        if (array_key_exists('config', $item)) {
            $item['config'] = self::config($item['config'], $factor);
        }
        return $item;
    }

    /**
     * items() 的整棵分类树换算。
     */
    public static function tree(array $tree, string $factor): array
    {
        if (self::isIdentity($factor)) {
            return $tree;
        }
        foreach ($tree as $index => $group) {
            if (!is_array($group) || !isset($group['children']) || !is_array($group['children'])) {
                continue;
            }
            foreach ($group['children'] as $childIndex => $child) {
                if (is_array($child)) {
                    $tree[$index]['children'][$childIndex] = self::item($child, $factor);
                }
            }
        }
        return $tree;
    }

    /**
     * 递归换算结构里所有 draft_premium 字段（预选卡列表 / 单卡详情）。
     */
    public static function draftPremiums(array $data, string $factor): array
    {
        if (self::isIdentity($factor)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if ($key === 'draft_premium' && is_numeric($value)) {
                $data[$key] = self::amount($value, $factor);
            } elseif (is_array($value)) {
                $data[$key] = self::draftPremiums($value, $factor);
            }
        }
        return $data;
    }

}
