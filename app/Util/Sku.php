<?php
declare(strict_types=1);

namespace App\Util;

/**
 * SKU 键名校验。
 *
 * SKU 名（如「机身颜色」「套餐-月付」「カラー」）由站长在商品里自定义，可以是任意文案。
 * 旧实现用「枚举允许字符」的白名单正则（只放行 A-Za-z0-9_ 和基本区汉字），
 * 结果连字符、括号、日文假名等常见写法全被判为非法，导致卡密/优惠券按 SKU 筛选
 * 时直接导不出（issue #797）。
 *
 * 这里改为「排除注入字符」：键名会被拼进 Eloquent 的 JSON 路径（sku->键名），
 * 只需挡住能改变路径语义或闭合引号的字符，其余正常文案一律放行。
 */
class Sku
{
    /** 键名最大长度（字符） */
    public const MAX_KEY_LENGTH = 32;

    /**
     * 键名是否可安全用于 `sku->{$key}` 的 JSON 路径查询。
     *
     * 拒绝：空串、超长、控制字符、引号/反引号/反斜杠（闭合与转义）、
     * `->`（Eloquent 的 JSON 路径分隔符，会造成意料外的嵌套）、
     * `[` `]`（数组下标语法）。
     *
     * @param string $key
     * @return bool
     */
    public static function isValidKey(string $key): bool
    {
        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
            return false;
        }

        if (str_contains($key, '->')) {
            return false;
        }

        return preg_match('/[\x00-\x1F\x7F"\'`\\\\\[\]]/u', $key) !== 1;
    }
}
