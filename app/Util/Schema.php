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
}
