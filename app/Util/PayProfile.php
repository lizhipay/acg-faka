<?php
declare(strict_types=1);

namespace App\Util;


use App\Model\Pay as PayModel;
use App\Model\PayConfig as PayConfigModel;
use Kernel\Exception\JSONException;
use Kernel\Util\Context;

/**
 * 支付配置档的读取入口。
 *
 * 3.5.9 之前一个支付插件只有一套配置，存在 app/Pay/{插件}/Config/Config.php 里，想给同一个插件
 * 挂两个商户号只能复制插件目录。现在配置是 pay_config 表里的行，支付接口用 pay.pay_config_id 指过去，
 * 下单和回调都从这里取值。
 *
 * 值的形状没变，仍是扁平数组，所以插件里的 $this->config['pid'] 写法一律不用改。
 */
class PayProfile
{
    /**
     * 单值缓存前缀，命中的是某个 handle 下某个配置档的完整值
     */
    private const CACHE_VALUE = 'pay_profile_value_';

    /**
     * 全表的 id/handle/name/sort 索引，只缓存一份，绝不含配置值
     */
    private const CACHE_LIST = 'pay_profile_list';

    /**
     * 支付接口 → 它该用的那套配置。下单与回调的唯一入口。
     *
     * @param PayModel $pay
     * @return array
     * @throws JSONException
     */
    public static function config(PayModel $pay): array
    {
        return self::resolve((string)$pay->handle, (int)$pay->pay_config_id);
    }

    /**
     * 取不到就抛错，绝不回退到别的配置档——静默换一个商户号收款，比下单直接失败严重得多。
     *
     * @param string $handle
     * @param int $configId
     * @return array
     * @throws JSONException
     */
    public static function resolve(string $handle, int $configId): array
    {
        $values = self::raw($handle, $configId);

        if ($values === null) {
            if ($handle !== '' && PayConfig::isValid($handle)) {
                PayConfig::log($handle, "CONFIG", "支付配置#{$configId}不存在或不属于该插件，已拒绝本次请求");
            }
            throw new JSONException("支付配置不存在，请在后台重新为该支付接口选择支付配置");
        }

        return $values;
    }

    /**
     * 原始值（含明文密钥）。查询固定带 handle 断言，所以就算有人手改数据库把 pay_config_id
     * 指到别的插件的配置上，也借不到那套凭据。
     *
     * @param string $handle
     * @param int $configId
     * @return array|null 配置档不存在时返回 null
     */
    public static function raw(string $handle, int $configId): ?array
    {
        if ($handle === '' || $configId <= 0) {
            return null;
        }

        $key = self::CACHE_VALUE . $handle . '_' . $configId;
        $cached = Context::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        if ($cached === false) {
            return null;
        }

        $row = PayConfigModel::query()
            ->where("id", $configId)
            ->where("handle", $handle)
            ->first(['config']);

        if (!$row) {
            Context::set($key, false);
            return null;
        }

        $values = json_decode((string)$row->config, true);
        $values = is_array($values) ? $values : [];
        Context::set($key, $values);

        return $values;
    }

    /**
     * 某插件的全部配置档，只含 id / name / sort，永远不含配置值。
     *
     * 第一次调用把整表索引一次性缓存下来，getPlugins() 那种 24 个插件的循环因此只花一条查询。
     *
     * @param string $handle
     * @return array [['id' => int, 'name' => string, 'sort' => int], ...]
     */
    public static function list(string $handle): array
    {
        $all = Context::get(self::CACHE_LIST);

        if (!is_array($all)) {
            $all = [];
            $rows = PayConfigModel::query()
                ->orderBy("sort", "asc")
                ->orderBy("id", "asc")
                ->get(['id', 'handle', 'name', 'sort']);

            foreach ($rows as $row) {
                $all[(string)$row->handle][] = [
                    'id' => (int)$row->id,
                    'name' => (string)$row->name,
                    'sort' => (int)$row->sort
                ];
            }

            Context::set(self::CACHE_LIST, $all);
        }

        return $all[$handle] ?? [];
    }

    /**
     * 配置档是否存在且属于该插件。管理端保存时的交叉校验用，不走缓存。
     *
     * @param string $handle
     * @param int $configId
     * @return bool
     */
    public static function exists(string $handle, int $configId): bool
    {
        if ($handle === '' || $configId <= 0) {
            return false;
        }

        return PayConfigModel::query()
            ->where("id", $configId)
            ->where("handle", $handle)
            ->exists();
    }

    /**
     * 清掉请求内缓存。新增/改名/删除/保存配置之后必须调一次，
     * 否则同一个请求里后面的读还是旧值。
     *
     * @param string|null $handle 只清某个插件的单值缓存；null 表示全清
     * @param int|null $configId
     * @return void
     */
    public static function flush(?string $handle = null, ?int $configId = null): void
    {
        Context::del(self::CACHE_LIST);

        if ($handle !== null && $configId !== null) {
            Context::del(self::CACHE_VALUE . $handle . '_' . $configId);
        }
    }
}
