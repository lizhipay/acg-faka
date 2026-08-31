<?php
declare(strict_types=1);

namespace App\Util;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * 新版本引入的字段，给老库补上。
 *
 * 本项目没有迁移系统：kernel/Install/Install.sql 只服务全新安装，
 * 老站升级是覆盖文件，数据库不会自动跟着变。而商品列表的查询是显式列出字段的，
 * 少一列就是整页 500，所以必须自愈。
 *
 * hasColumn() 每次都要查一次 information_schema，不能每个请求都跑，
 * 所以补完在 runtime 下打个标记，之后只花一次 is_file()。
 */
final class Schema
{
    private const MARK_DIR = BASE_PATH . '/runtime/schema';

    /** @var array<string, true> 本次请求内已确认过的，避免重复 is_file */
    private static array $checked = [];

    /**
     * @param string $table 不带前缀的表名
     * @param string $column 列名
     * @param callable(Blueprint): void $define 列定义
     */
    public static function ensureColumn(string $table, string $column, callable $define): void
    {
        $key = $table . '.' . $column;
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . str_replace('.', '_', $key);
        if (is_file($mark)) {
            return;
        }

        try {
            if (!Manager::schema()->hasColumn($table, $column)) {
                Manager::schema()->table($table, $define);
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        } catch (\Throwable $e) {
            // 补列失败不能让页面挂掉：可能是数据库账号没有 ALTER 权限。
            // 不写标记，下次请求再试；真正的报错会由后续查询自己抛出来。
        }
    }

    /** 商品标签（#807）。列表与详情都要读，所以两边入口都得先叫一声 */
    public static function ensureCommodityTags(): void
    {
        self::ensureColumn('commodity', 'tags', static function (Blueprint $table): void {
            $table->string('tags', 1000)->nullable()->comment('商品标签：JSON [{text,color}]');
        });
    }

    /** 店铺共享的对方货币与结算汇率：非 CNY 站点接入 CNY 货源时按此换算金额 */
    public static function ensureSharedCurrency(): void
    {
        self::ensureColumn('shared', 'currency', static function (Blueprint $table): void {
            $table->string('currency', 8)->default('CNY')->comment('上游站点货币代码');
        });
        self::ensureColumn('shared', 'currency_rate', static function (Blueprint $table): void {
            $table->decimal('currency_rate', 18, 6)->default(0)->comment('结算汇率：1 上游货币 = ? 本站货币；0 = 按站点汇率自动');
        });
    }

    /** @var array<string, bool> 本次请求内已确认过的表 */
    private static array $tableKnown = [];

    /**
     * 表是否存在（带缓存）。
     *
     * 给「新版本引入的整张表」用：老站升级只覆盖文件，工单（3.5.1）、商品分组（3.1.3）
     * 这类表在升级不完整的库里可能整个缺失，业务查询前先问一声，缺了就按零引用降级，
     * 别让整个功能 500（issue #837）。
     *
     * 「存在」永久缓存（表建出来就不会消失）；「不存在」只缓存在请求内，
     * 升级补表后下一个请求立即生效。
     *
     * @param string $table 不带前缀的表名
     * @return bool
     */
    public static function tableExists(string $table): bool
    {
        if (isset(self::$tableKnown[$table])) {
            return self::$tableKnown[$table];
        }

        $mark = self::MARK_DIR . '/table_' . $table;
        if (is_file($mark)) {
            return self::$tableKnown[$table] = true;
        }

        try {
            $exists = Manager::schema()->hasTable($table);
        } catch (\Throwable $e) {
            //探测本身失败（权限等）按存在处理：真正的报错让业务查询自己抛，别在这里吞掉线索
            $exists = true;
        }

        if ($exists) {
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        }

        return self::$tableKnown[$table] = $exists;
    }
}
