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

    /**
     * 会员登录会话表（与后台 manage_session 同构）。老站升级时 version/3.8.0/update.php 会建，
     * 这里在签发会话前再兜一次，避免升级不完整时登录 500。用一次性标记，之后只花一次 is_file。
     */
    public static function ensureUserSessionTable(): void
    {
        $key = 'user_session';
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . $key;
        if (is_file($mark)) {
            return;
        }

        try {
            $schema = Manager::schema();
            if (!$schema->hasTable('user_session')) {
                $foreignPrefix = (string)Manager::connection()->getTablePrefix();
                $schema->create('user_session', function (Blueprint $table) use ($foreignPrefix): void {
                    $table->engine = 'InnoDB';
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_general_ci';
                    $table->bigIncrements('id')->comment('主键id');
                    $table->unsignedInteger('user_id')->comment('会员id');
                    $table->char('session_hash', 64)->comment('会话标识SHA-256哈希');
                    $table->string('device_type', 16)->comment('设备类型');
                    $table->string('device_name', 96)->comment('设备名称');
                    $table->string('user_agent', 512)->comment('登录User-Agent');
                    $table->string('login_ip', 45)->comment('登录IP');
                    $table->string('last_ip', 45)->comment('最近IP');
                    $table->dateTime('created_time')->comment('登录时间');
                    $table->dateTime('last_seen_time')->comment('最近活跃时间');
                    $table->dateTime('expires_time')->comment('过期时间');
                    $table->dateTime('revoked_time')->nullable()->default(null)->comment('撤销时间');
                    $table->unique('session_hash', 'session_hash');
                    $table->index(['user_id', 'revoked_time', 'expires_time'], 'user_active');
                    $table->index('last_seen_time', 'last_seen_time');
                    $table->foreign('user_id', $foreignPrefix . 'user_session_ibfk_1')
                        ->references('id')->on('user')->onDelete('cascade')->onUpdate('restrict');
                });
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        } catch (\Throwable $e) {
            //建表失败（权限不足等）不写标记，下次再试；真正的错误交由后续查询暴露
        }
    }

    /** 会员两步验证列（TOTP 密钥 + 备用恢复码）。登录与安全设置读写前先兜一次。 */
    public static function ensureUserTotp(): void
    {
        self::ensureColumn('user', 'totp_secret', static function (Blueprint $table): void {
            $table->string('totp_secret', 64)->nullable()->default(null)->comment('两步验证TOTP密钥(Base32)');
        });
        self::ensureColumn('user', 'totp_recovery', static function (Blueprint $table): void {
            $table->text('totp_recovery')->nullable()->comment('两步验证备用恢复码(bcrypt哈希JSON)');
        });
        self::ensureColumn('user', 'fund_2fa', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('fund_2fa')->default(0)->comment('资金操作二次验证：0=关，1=开');
        });
    }

    /**
     * 会员安全稽核日志表（user_log）。写入点在登录/安全设置/资金操作等处，首次写入前兜底建表。
     * 与 kernel/Install/Install.sql、version/3.8.1/update.php 三处定义保持一致。
     */
    public static function ensureUserLogTable(): void
    {
        $key = 'user_log';
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . $key;
        if (is_file($mark)) {
            return;
        }

        try {
            $schema = Manager::schema();
            if (!$schema->hasTable('user_log')) {
                $foreignPrefix = (string)Manager::connection()->getTablePrefix();
                $schema->create('user_log', function (Blueprint $table) use ($foreignPrefix): void {
                    $table->engine = 'InnoDB';
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_general_ci';
                    $table->bigIncrements('id')->comment('主键id');
                    $table->unsignedInteger('user_id')->comment('会员id');
                    $table->string('username', 32)->default('')->comment('会员用户名快照');
                    $table->string('action', 32)->comment('事件类型码');
                    $table->string('content', 255)->default('')->comment('日志详情');
                    $table->dateTime('create_time')->comment('创建时间');
                    $table->string('create_ip', 64)->comment('IP地址');
                    $table->string('ua', 255)->nullable()->default(null)->comment('浏览器UA');
                    $table->unsignedTinyInteger('risk')->default(0)->comment('风险：0=正常，1=异常');
                    $table->index('user_id', 'user_id');
                    $table->index('create_time', 'create_time');
                    $table->index('action', 'action');
                    $table->index('risk', 'risk');
                    $table->foreign('user_id', $foreignPrefix . 'user_log_ibfk_1')
                        ->references('id')->on('user')->onDelete('cascade')->onUpdate('restrict');
                });
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        } catch (\Throwable $e) {
            //建表失败（权限不足等）不写标记，下次再试
        }
    }

    /**
     * 会员对接白名单 IP 表（user_ip_whitelist）。白名单读写与对接下单放行判定前兜底建表。
     * 与 kernel/Install/Install.sql、version/3.8.2/update.php 三处定义保持一致。
     */
    public static function ensureUserIpWhitelistTable(): void
    {
        $key = 'user_ip_whitelist';
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . $key;
        if (is_file($mark)) {
            return;
        }

        try {
            $schema = Manager::schema();
            if (!$schema->hasTable('user_ip_whitelist')) {
                $foreignPrefix = (string)Manager::connection()->getTablePrefix();
                $schema->create('user_ip_whitelist', function (Blueprint $table) use ($foreignPrefix): void {
                    $table->engine = 'InnoDB';
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_general_ci';
                    $table->increments('id')->comment('主键id');
                    $table->unsignedInteger('user_id')->comment('会员id');
                    $table->string('ip', 64)->comment('IP或CIDR网段(规范化)');
                    $table->string('note', 32)->default('')->comment('备注');
                    $table->dateTime('create_time')->comment('添加时间');
                    $table->dateTime('last_used_time')->nullable()->default(null)->comment('最近一次放行时间');
                    $table->unique(['user_id', 'ip'], 'user_ip');
                    $table->foreign('user_id', $foreignPrefix . 'user_ip_whitelist_ibfk_1')
                        ->references('id')->on('user')->onDelete('cascade')->onUpdate('restrict');
                });
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        } catch (\Throwable $e) {
            //建表失败（权限不足等）不写标记，下次再试
        }
    }

    /**
     * File-card archives (card_file). Ensured before uploads and stocking; identical to
     * kernel/Install/Install.sql and version/3.8.4/update.php.
     */
    public static function ensureCardFileTable(): void
    {
        $key = 'card_file';
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . $key;
        if (is_file($mark)) {
            return;
        }

        try {
            $schema = Manager::schema();
            if (!$schema->hasTable('card_file')) {
                $schema->create('card_file', function (Blueprint $table): void {
                    $table->engine = 'InnoDB';
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_general_ci';
                    $table->increments('id')->comment('主键id');
                    $table->unsignedInteger('card_id')->nullable()->default(null)->comment('卡密id：NULL=已上传未入库');
                    $table->unsignedInteger('order_id')->nullable()->default(null)->comment('发货订单id');
                    $table->unsignedInteger('owner')->default(0)->comment('所属会员：0=系统');
                    $table->char('token', 48)->comment('下载令牌');
                    $table->string('path', 160)->comment('存储路径(runtime/card-file 下的随机文件名)');
                    $table->string('name', 255)->comment('原文件名');
                    $table->unsignedBigInteger('size')->default(0)->comment('文件大小(字节)');
                    $table->char('hash', 64)->comment('SHA-256');
                    $table->unsignedInteger('downloads')->default(0)->comment('下载次数');
                    $table->dateTime('last_download_time')->nullable()->default(null)->comment('最近下载时间');
                    $table->dateTime('create_time')->comment('上传时间');
                    $table->unique('token', 'token');
                    $table->unique('card_id', 'card_id');
                    $table->index('order_id', 'order_id');
                    $table->index('owner', 'owner');
                    $table->index('hash', 'hash');
                    $table->index('create_time', 'create_time');
                });
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
            self::$tableKnown['card_file'] = true;
        } catch (\Throwable $e) {
            // Not marked: retried on the next request (missing CREATE privilege etc.).
        }
    }

    /** 后台会话闲置锁屏所需的最近活动时间列。签发/校验会话前兜一次。 */
    public static function ensureManageSessionActivity(): void
    {
        self::ensureColumn('manage_session', 'last_active_time', static function (Blueprint $table): void {
            $table->dateTime('last_active_time')->nullable()->default(null)->comment('最近活动时间(闲置锁屏用)');
        });
    }

    /**
     * 后台 passkey(WebAuthn) 凭证表。注册/登入/解锁读写前先兜一次建表。
     * 与 kernel/Install/Install.sql、version/3.8.1/update.php 三处定义保持一致。
     */
    public static function ensureManageWebauthnTable(): void
    {
        $key = 'manage_webauthn';
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . $key;
        if (is_file($mark)) {
            return;
        }

        try {
            $schema = Manager::schema();
            if (!$schema->hasTable('manage_webauthn')) {
                $foreignPrefix = (string)Manager::connection()->getTablePrefix();
                $schema->create('manage_webauthn', function (Blueprint $table) use ($foreignPrefix): void {
                    $table->engine = 'InnoDB';
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_general_ci';
                    $table->bigIncrements('id')->comment('主键id');
                    $table->unsignedInteger('manage_id')->comment('管理员id');
                    $table->string('credential_id', 255)->comment('凭证ID(base64url)');
                    $table->text('public_key')->comment('凭证公钥(PEM)');
                    $table->unsignedBigInteger('sign_count')->default(0)->comment('签名计数器');
                    $table->string('transports', 128)->nullable()->default(null)->comment('传输方式');
                    $table->string('aaguid', 64)->nullable()->default(null)->comment('认证器AAGUID');
                    $table->string('name', 64)->default('')->comment('凭证标签');
                    $table->dateTime('created_time')->comment('创建时间');
                    $table->dateTime('last_used_time')->nullable()->default(null)->comment('最近使用时间');
                    $table->string('last_used_ip', 45)->nullable()->default(null)->comment('最近使用IP');
                    $table->unique('credential_id', 'credential_id');
                    $table->index('manage_id', 'manage_id');
                    $table->foreign('manage_id', $foreignPrefix . 'manage_webauthn_ibfk_1')
                        ->references('id')->on('manage')->onDelete('cascade')->onUpdate('restrict');
                });
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        } catch (\Throwable $e) {
            //建表失败（权限不足等）不写标记，下次再试
        }
    }

    /**
     * 会员 passkey(WebAuthn) 凭证表（与后台 manage_webauthn 同构）。注册/登录读写前先兜一次建表。
     * 与 kernel/Install/Install.sql、version/3.8.1/update.php 三处定义保持一致。
     */
    public static function ensureUserWebauthnTable(): void
    {
        $key = 'user_webauthn';
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . $key;
        if (is_file($mark)) {
            return;
        }

        try {
            $schema = Manager::schema();
            if (!$schema->hasTable('user_webauthn')) {
                $foreignPrefix = (string)Manager::connection()->getTablePrefix();
                $schema->create('user_webauthn', function (Blueprint $table) use ($foreignPrefix): void {
                    $table->engine = 'InnoDB';
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_general_ci';
                    $table->bigIncrements('id')->comment('主键id');
                    $table->unsignedInteger('user_id')->comment('会员id');
                    $table->string('credential_id', 255)->comment('凭证ID(base64url)');
                    $table->text('public_key')->comment('凭证公钥(PEM)');
                    $table->unsignedBigInteger('sign_count')->default(0)->comment('签名计数器');
                    $table->string('transports', 128)->nullable()->default(null)->comment('传输方式');
                    $table->string('aaguid', 64)->nullable()->default(null)->comment('认证器AAGUID');
                    $table->string('name', 64)->default('')->comment('凭证标签');
                    $table->dateTime('created_time')->comment('创建时间');
                    $table->dateTime('last_used_time')->nullable()->default(null)->comment('最近使用时间');
                    $table->string('last_used_ip', 45)->nullable()->default(null)->comment('最近使用IP');
                    $table->unique('credential_id', 'credential_id');
                    $table->index('user_id', 'user_id');
                    $table->foreign('user_id', $foreignPrefix . 'user_webauthn_ibfk_1')
                        ->references('id')->on('user')->onDelete('cascade')->onUpdate('restrict');
                });
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        } catch (\Throwable $e) {
            //建表失败（权限不足等）不写标记，下次再试
        }
    }

    /** 商品标签（#807）。列表与详情都要读，所以两边入口都得先叫一声 */
    public static function ensureCommodityTags(): void
    {
        self::ensureColumn('commodity', 'tags', static function (Blueprint $table): void {
            $table->string('tags', 1000)->nullable()->comment('商品标签：JSON [{text,color}]');
        });
    }

    public static function ensureCommodityControl(): void
    {
        self::ensureColumn('commodity', 'substation_disable', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('substation_disable')->default(0)->comment('禁止分站销售：0=否，1=是');
        });
        self::ensureColumn('commodity', 'ban', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('ban')->default(0)->comment('平台下架：0=否，1=是');
        });
        self::ensureColumn('commodity', 'ban_reason', static function (Blueprint $table): void {
            $table->string('ban_reason', 255)->charset('utf8mb4')->collation('utf8mb4_general_ci')->nullable()->comment('平台下架原因');
        });
    }

    /** 对接商品「远端发货留言同步」：开启后接入与同步时把上游的发货留言写进本地商品（#972，3.8.6） */
    public static function ensureCommoditySharedLeaveMessage(): void
    {
        self::ensureColumn('commodity', 'shared_leave_message_sync', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('shared_leave_message_sync')->default(0)->comment('同步远端发货留言：0=关，1=开');
        });
    }

    /** 分站加价规则：全店默认（business）与分类规则（user_category），没有单独设置加价的主站商品按它们加价（#975，3.8.6） */
    public static function ensureSubstationPremiumRules(): void
    {
        self::ensureColumn('business', 'premium', static function (Blueprint $table): void {
            $table->unsignedInteger('premium')->default(0)->comment('全店默认加价百分比：0=不加价');
        });
        self::ensureColumn('business', 'premium_rounding', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('premium_rounding')->default(0)->comment('全店默认加价取整：0=不取整，1=四舍五入到整元，2=向上取整到整元');
        });
        self::ensureColumn('user_category', 'premium', static function (Blueprint $table): void {
            $table->unsignedInteger('premium')->default(0)->comment('分类加价百分比：0=沿用全店规则');
        });
        self::ensureColumn('user_category', 'rounding', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('rounding')->default(0)->comment('分类加价取整：0=不取整，1=四舍五入到整元，2=向上取整到整元');
        });
    }

    /**
     * 订单退款（#1145，3.8.6）：退款并入核心，统计口径一律排除已退款订单。
     * refund_status 列以前由「订单退款」插件建（同名同类型），这里只补缺的列。
     */
    public static function ensureOrderRefund(): void
    {
        self::ensureColumn('order', 'refund_status', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('refund_status')->default(0)->comment('退款状态：0=未退款，1=已退款到会员余额');
        });
        self::ensureColumn('order', 'refund_amount', static function (Blueprint $table): void {
            $table->decimal('refund_amount', 10, 2)->unsigned()->default(0)->comment('退回会员余额的金额');
        });
        self::ensureColumn('order', 'refund_time', static function (Blueprint $table): void {
            $table->dateTime('refund_time')->nullable()->comment('退款时间');
        });
    }

    /**
     * 对接下单失败挂起（#1172，3.8.6）：钱收到了但上游没给货，订单记为已付款、待发货，原因记在这里。
     * 只能在事务外调用（补列是 DDL，会隐式提交）。
     */
    public static function ensureOrderSharedError(): void
    {
        self::ensureColumn('order', 'shared_error', static function (Blueprint $table): void {
            $table->string('shared_error', 255)->nullable()->comment('向上游下单失败的原因：非空=已付款但没取到货，等待重新向上游取货');
        });
    }

    /** 支付接口单笔限额：含手续费的实付金额超出范围时拒绝下单/充值（#1129，3.8.6） */
    public static function ensurePayAmountLimit(): void
    {
        self::ensureColumn('pay', 'min_amount', static function (Blueprint $table): void {
            $table->decimal('min_amount', 10, 2)->unsigned()->default(0)->comment('单笔最低支付金额(含手续费)：0=不限');
        });
        self::ensureColumn('pay', 'max_amount', static function (Blueprint $table): void {
            $table->decimal('max_amount', 10, 2)->unsigned()->default(0)->comment('单笔最高支付金额(含手续费)：0=不限');
        });
    }

    /** 手动发货商品「付款即发货」：付款后直接把发货信息作为卡密发出、订单即为已发货（3.8.3） */
    public static function ensureCommodityDeliveryAuto(): void
    {
        self::ensureColumn('commodity', 'delivery_auto', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('delivery_auto')->default(0)->comment('手动发货付款即发货：0=否，1=是')->after('delivery_message');
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

    /**
     * 上游协议代次（店铺共享）。
     *
     * `/shared/commodity/item` 的入参与返回形状在 3.1.2 变过，`stock`/`draft`/`valuation`
     * 三个端点也是那之后才有的。每次都先打一发新端点再吃 404 的话，商品详情页每次访问
     * 都要多一次往返；探明一次记在店铺档案上，之后直奔正确的那条路。
     * 0=未探明，1=3.1.2 及以后，2=3.1.1 及更老。
     */
    public static function ensureSharedProtocol(): void
    {
        self::ensureColumn('shared', 'protocol', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('protocol')->default(0)->comment('上游协议代次：0=未探明，1=3.1.2+，2=3.1.1及更老');
        });
    }

    /**
     * 共享店铺的成本价基准：加价模板选「成本价」时，要不要按上游给本账号的拿货价加价。
     * 老店铺补列后是 0，价格口径不变，由站长逐家打开；新接入的店铺由保存接口默认设成 1。
     */
    public static function ensureSharedFactoryBase(): void
    {
        self::ensureColumn('shared', 'factory_base', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('factory_base')->default(0)->comment('成本价基准：0=按上游商品价加价，1=按账号拿货价加价');
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
