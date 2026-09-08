<?php
declare(strict_types=1);

namespace App\Controller;


use App\Controller\Base\API\User;
use App\Service\App;
use App\Util\Client;
use App\Util\Opcache;
use App\Util\Str;
use App\Util\Validation;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Util\SQL;
use Kernel\Util\View;

class Install extends User
{

    #[Inject]
    private App $app;

    /**
     * 安装向导要求的最低 MySQL / MariaDB 版本。
     * Install.sql 里有 JSON 列：MySQL 5.7.8 起才有 JSON 类型，MariaDB 是 10.2.7 起。
     */
    private const MIN_MYSQL = '5.7.8';
    private const MIN_MARIADB = '10.2.7';

    /**
     * 伪静态探测
     * @return array
     */
    public function rewrite(): array
    {
        return $this->json(200, "success");
    }


    /**
     * @return string
     */
    public function step(): string
    {
        if (file_exists(BASE_PATH . '/kernel/Install/Lock')) {
            Client::redirect("/", lang("程序已经安装过了，正在返回首页"), 3);
        }

        $env = $this->environment();

        //页面在安装阶段拿不到数据库，翻译全在浏览器里做。这里只把「事实」交给它：
        //环境检测结果、服务端猜的语言（来自 Cookie / Accept-Language）、是否在容器里。
        $boot = [
            'version' => $env['version'],
            'lang' => \Kernel\Util\Lang::get(),
            'env' => $env,
        ];

        return View::render("Install.html", [
            'version' => $env['version'],
            //JSON 直接落在 <script type="application/json"> 里，HEX_TAG 防 </script> 提前闭合
            'boot' => json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
        ]);
    }

    /**
     * 环境重检。页面上的「重新检测」和失败时的自动轮询都打这里，不用整页刷新。
     * @return array
     */
    public function env(): array
    {
        if (file_exists(BASE_PATH . '/kernel/Install/Lock')) {
            throw new JSONException("已安装");
        }
        return $this->json(200, null, $this->environment());
    }

    /**
     * 收集环境事实：PHP 版本、扩展、目录可写、是否容器。
     * @return array
     */
    private function environment(): array
    {
        $phpVersion = phpversion();

        $ext = [
            'gd' => extension_loaded("gd"),
            'curl' => extension_loaded("curl"),
            'pdo' => extension_loaded("PDO"),
            'pdo_mysql' => extension_loaded("pdo_mysql"),
            'date' => extension_loaded("date"),
            'json' => extension_loaded("json"),
            'session' => extension_loaded("session"),
            'zip' => extension_loaded("zip"),
            //bcmath：Kernel\Util\Decimal 全靠它做金额运算，订单/充值/分站/商品都在用，缺了会直接致命错误
            'bcmath' => extension_loaded("bcmath") && function_exists("bcadd"),
        ];

        //安装最后一步要写这几个地方；权限不够以前只会在点「立即安装」之后才炸，现在提前列出来。
        $writable = [];
        foreach (['config', 'runtime', 'kernel/Install', 'assets/cache'] as $rel) {
            $path = BASE_PATH . '/' . $rel;
            if (file_exists($path)) {
                $writable[$rel] = is_writable($path);
            } else {
                //目录还没建（比如 assets/cache），看父目录能不能建它
                $writable[$rel] = is_writable(dirname($path));
            }
        }

        $missing = [];
        foreach ($ext as $name => $ok) {
            if (!$ok) {
                $missing[] = $name;
            }
        }
        $unwritable = array_keys(array_filter($writable, static fn(bool $ok): bool => !$ok));

        $phpOk = version_compare($phpVersion, '8.0.0', '>=');

        return [
            'version' => config("app")['version'],
            'php' => ['version' => $phpVersion, 'ok' => $phpOk, 'min' => '8.0'],
            'ext' => $ext,
            'writable' => $writable,
            'missing' => $missing,
            'unwritable' => $unwritable,
            //不因为 PHP 版本不达标就跳过扩展检查，一次把所有缺的都列出来，免得站长改完版本再回来发现还缺扩展
            'install' => $phpOk && $missing === [] && $unwritable === [],
            //容器里数据库地址通常是 compose 的服务名而不是 127.0.0.1，页面据此换默认值和提示
            'docker' => is_file('/.dockerenv') || is_file('/run/.containerenv'),
            //官方镜像自带 MySQL，连接信息由 compose 通过环境变量注入，向导直接填好
            'prefill' => $this->prefill(),
        ];
    }

    /**
     * 测试数据库连接。
     *
     * 以前唯一的反馈点是「立即安装」之后的报错，而且报的是整段 PDO 异常。这里把失败原因
     * 拆成机器可读的 reason，页面按它给出对应的修法；连接成功还会顺带告诉页面：
     * 版本够不够、同前缀下有没有表（Install.sql 会 DROP 它们，必须让站长看到再点）。
     *
     * 返回永远是 code=200，结果在 data 里 —— 这样文案完全由页面按语言渲染，
     * 不走 json() 里的 lang()（安装阶段没有翻译表）。
     *
     * @return array
     * @throws JSONException
     */
    public function testDatabase(): array
    {
        if (file_exists(BASE_PATH . '/kernel/Install/Lock')) {
            throw new JSONException("已安装");
        }

        $in = $this->databaseInput();
        if (isset($in['error'])) {
            return $this->json(200, null, ['ok' => false, 'reason' => 'invalid', 'field' => $in['error']]);
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            //连不上的地址别让页面干等：5 秒够局域网和公网 MySQL 握手了
            \PDO::ATTR_TIMEOUT => 5,
        ];

        //第一步不带库名连，把「连不上」「账号错」和「库不存在」分开
        try {
            $pdo = new \PDO("mysql:host={$in['host']};port={$in['port']};charset=utf8mb4", $in['username'], $in['password'], $options);
        } catch (\PDOException $e) {
            return $this->json(200, null, [
                'ok' => false,
                'reason' => $this->classifyPdoError($e),
                'message' => $this->cleanPdoMessage($e->getMessage()),
            ]);
        }

        $versionRaw = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $version = $this->parseServerVersion($versionRaw);

        $exists = (bool)$this->scalar($pdo, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$in['database']]);
        $created = false;

        if (!$exists) {
            if (($_POST['create'] ?? '') !== '1') {
                return $this->json(200, null, [
                    'ok' => false,
                    'reason' => 'unknown_db',
                    'server' => $version,
                ]);
            }
            //站长明确点了「为我创建」。库名已经过白名单校验，反引号包起来即可。
            try {
                $pdo->exec('CREATE DATABASE `' . $in['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
                $created = true;
            } catch (\PDOException $e) {
                return $this->json(200, null, [
                    'ok' => false,
                    'reason' => 'create_denied',
                    'message' => $this->cleanPdoMessage($e->getMessage()),
                    'server' => $version,
                ]);
            }
        }

        //同前缀下已有多少张表：Install.sql 会 DROP 它们，这个数字必须让站长亲眼看到
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $in['prefix']) . '%';
        $prefixed = (int)$this->scalar($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?', [$in['database'], $like]);
        $total = (int)$this->scalar($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$in['database']]);

        return $this->json(200, null, [
            'ok' => true,
            'reason' => $version['ok'] ? 'ok' : 'version',
            'server' => $version,
            'created' => $created,
            'tables' => ['prefixed' => $prefixed, 'total' => $total],
        ]);
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function submit(): array
    {
        if (file_exists(BASE_PATH . '/kernel/Install/Lock')) {
            throw new JSONException("您已经安装过了，如果想重新安装，请删除" . '/kernel/Install/Lock' . '文件，即可重新安装!');
        }
        $map = $_POST;

        foreach ($map as $k => $v) {
            $map[$k] = trim((string)$v);
        }

        $db = $this->databaseInput();
        if (isset($db['error'])) {
            //页面自己会拦，走到这里只可能是绕过页面直接打接口
            throw new JSONException("数据库参数不合法：" . $db['error']);
        }
        $host = $db['host'];
        $port = $db['port'];

        $email = $map['email'] ?? '';
        $nickname = $map['nickname'] ?? '';
        $login_password = $map['login_password'] ?? '';

        if (!Validation::email($email)) {
            throw new JSONException("管理员邮箱格式不正确");
        }

        if ($nickname === '') {
            throw new JSONException("昵称不能为空");
        }

        if (!Validation::password($login_password)) {
            throw new JSONException("您设置的登录密码过于简单");
        }

        $sqlFile = BASE_PATH . '/kernel/Install/Install.sql';

        $salt = Str::generateRandStr(32);
        $pw = Str::generatePassword($login_password, $salt);

        $sqlSrc = (string)file_get_contents($sqlFile);
        $sqlSrc = str_replace('__MANAGE_EMAIL__', $email, $sqlSrc);
        $sqlSrc = str_replace('__MANAGE_PASSWORD__', $pw, $sqlSrc);
        $sqlSrc = str_replace('__MANAGE_SALT__', $salt, $sqlSrc);
        $sqlSrc = str_replace('__MANAGE_NICKNAME__', $nickname, $sqlSrc);

        if (file_put_contents($sqlFile . ".tmp", $sqlSrc) === false) {
            throw new JSONException("没有写入权限，请检查权限是否足够");
        }

        //导入数据库
        SQL::import($sqlFile . ".tmp", $host, $db['database'], $db['username'], $db['password'], $db['prefix'], $port);

        //请求日志密钥：随机生成后只存库。这里还不能用 Eloquent（连接是用安装前的
        //配置建的），所以拿刚刚验证过的这套凭据直连写入。失败不影响安装——没有密钥
        //就是不记请求日志，不会退回明文。
        try {
            $pdo = new \PDO("mysql:dbname={$db['database']};host={$host};port={$port}", $db['username'], $db['password']);
            $stmt = $pdo->prepare('INSERT INTO `' . $db['prefix'] . 'config` (`key`, `value`) VALUES (?, ?)');
            $stmt->execute(['request_log_key', base64_encode(random_bytes(32))]);
            $stmt->execute(['csp_nonce_secret', base64_encode(random_bytes(32))]);
        } catch (\Throwable $e) {
        }

        //设置数据库账号密码
        setConfig([
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $db['database'],
            'username' => $db['username'],
            'password' => $db['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $db['prefix']
        ], BASE_PATH . "/config/database.php");

        Opcache::invalidate(BASE_PATH . "/config/database.php");

        unlink($sqlFile . ".tmp");
        file_put_contents(BASE_PATH . '/kernel/Install/Lock', "");

        //配置文件是刚写好的，但本次请求里的 Eloquent 连接还是 Kernel 启动时用**空配置**建的那条。
        //不重新绑定就直接 DB::table()，语言包导入必然连不上、被下面的 catch 静默吞掉 ——
        //表现是安装显示成功，但 lang 表一条译文都没有，装完切到英文/日文整个后台还是中文。
        $this->rebindDatabase($db, $host, $port);

        //导入随版本分发的多语言词包（失败不影响安装，可在后台"语言翻译"页重建）
        try {
            $this->importLanguagePacks();
        } catch (\Throwable $e) {
        }

        try {
            $this->app->install();
        } catch (\Exception|\Error $e) {
        }

        return $this->json(200, '安装完成');
    }

    /**
     * 内置数据库的连接信息（官方镜像 / compose 注入的环境变量）。
     *
     * **故意不包含密码**：这一页在装完之前是公开可访问的，没必要把密码渲染进 HTML。
     * 页面只要把密码留空，提交时带上 use_builtin=1，由服务端自己去环境变量里取。
     *
     * @return array 没有内置数据库时返回空数组
     */
    private function prefill(): array
    {
        $builtin = $this->builtin();
        if ($builtin === null) {
            return [];
        }

        unset($builtin['password']);
        return $builtin;
    }

    /**
     * 解析内置数据库的完整连接信息（含密码）。
     *
     * 密码优先读 *_FILE 指向的文件 —— compose 里那是首次启动随机生成、
     * 挂进来的密钥卷，镜像本身不带任何默认密码。
     *
     * @return array|null 没配置就返回 null
     */
    private function builtin(): ?array
    {
        $get = static function (string $key): string {
            $v = getenv($key);
            return is_string($v) ? trim($v) : '';
        };

        $host = $get('ACG_DB_HOST');
        $database = $get('ACG_DB_DATABASE');
        $username = $get('ACG_DB_USERNAME');
        if ($host === '' || $database === '' || $username === '') {
            return null;
        }

        $password = '';
        $file = $get('ACG_DB_PASSWORD_FILE');
        if ($file !== '' && is_readable($file)) {
            $password = trim((string)file_get_contents($file));
        }
        if ($password === '') {
            $password = $get('ACG_DB_PASSWORD');
        }

        $port = (int)($get('ACG_DB_PORT') ?: '3306');

        return [
            'host' => $host,
            'port' => $port > 0 && $port <= 65535 ? $port : 3306,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'prefix' => $get('ACG_DB_PREFIX') ?: 'acg_',
        ];
    }

    /**
     * 用刚写入的凭据重建全局 Eloquent 连接。
     *
     * setAsGlobal() 会替换 Capsule 的静态实例，Kernel\Util\Lang 里的
     * `use Illuminate\Database\Capsule\Manager as DB` 之后就走这条新连接了。
     *
     * @param array $db databaseInput() 的返回值
     * @param string $host
     * @param int $port
     * @return void
     */
    private function rebindDatabase(array $db, string $host, int $port): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $db['database'],
            'username' => $db['username'],
            'password' => $db['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $db['prefix'],
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    /**
     * 读取并校验数据库连接参数。库名和前缀会原样拼进 DSN 与 SQL 标识符里，
     * 必须白名单：`x;unix_socket=…` 这种库名能把 DSN 改掉。
     * @return array 合法时是参数数组，否则 ['error' => 字段名]
     */
    private function databaseInput(): array
    {
        //页面没动过任何一项、密码也留空时会带上这个标记：整套连接信息由服务端从
        //环境变量取，密码始终不经过浏览器。
        if (($_POST['use_builtin'] ?? '') === '1') {
            $builtin = $this->builtin();
            if ($builtin !== null) {
                return $builtin;
            }
        }

        $get = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));

        $host = $get('host');
        if ($host === '') {
            $host = 'localhost';
        }
        if (!preg_match('/^[A-Za-z0-9_\-.]{1,255}$/', $host)) {
            return ['error' => 'host'];
        }

        $portRaw = $get('port');
        $port = $portRaw === '' ? 3306 : (int)$portRaw;
        if (!preg_match('/^\d{0,5}$/', $portRaw) || $port < 1 || $port > 65535) {
            return ['error' => 'port'];
        }

        $database = $get('database');
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $database)) {
            return ['error' => 'database'];
        }

        $username = $get('username');
        if ($username === '' || strlen($username) > 128) {
            return ['error' => 'username'];
        }

        $prefix = $get('prefix');
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix)) {
            return ['error' => 'prefix'];
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            //密码不 trim：有人的密码就是带空格的
            'password' => (string)($_POST['password'] ?? ''),
            'prefix' => $prefix,
        ];
    }

    private function scalar(\PDO $pdo, string $sql, array $params): mixed
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * 把 PDO 异常归到页面认识的几类：connect（地址/端口/网络）、auth（账号密码）、other。
     */
    private function classifyPdoError(\PDOException $e): string
    {
        $msg = $e->getMessage();
        //1045 = Access denied for user
        if (str_contains($msg, '1045') || stripos($msg, 'Access denied') !== false) {
            return 'auth';
        }
        //2002 = 连不上 socket / 2003 = 连不上 host:port / 2005 = 主机名解析失败 / 超时
        if (preg_match('/\b(2002|2003|2005|2006)\b/', $msg) || stripos($msg, 'timed out') !== false
            || stripos($msg, 'refused') !== false || stripos($msg, 'No such file') !== false
            || stripos($msg, 'getaddrinfo') !== false || stripos($msg, 'Name or service not known') !== false) {
            return 'connect';
        }
        return 'other';
    }

    /**
     * 去掉 "SQLSTATE[HY000] [2002] " 这种前缀，只留人话。
     */
    private function cleanPdoMessage(string $msg): string
    {
        return trim((string)preg_replace('/^SQLSTATE\[[A-Z0-9]+\]\s*(\[\d+\]\s*)?/', '', $msg));
    }

    /**
     * "8.0.36" / "10.6.12-MariaDB-1:10.6.12+maria~ubu2004" → 结构化版本 + 是否达标
     */
    private function parseServerVersion(string $raw): array
    {
        $mariadb = stripos($raw, 'mariadb') !== false;
        preg_match('/^(\d+\.\d+\.\d+)/', $raw, $m);
        $number = $m[1] ?? '0.0.0';
        $min = $mariadb ? self::MIN_MARIADB : self::MIN_MYSQL;
        return [
            'raw' => $raw,
            'flavor' => $mariadb ? 'MariaDB' : 'MySQL',
            'version' => $number,
            'min' => $min,
            'ok' => version_compare($number, $min, '>='),
        ];
    }

    /**
     * 导入 lang/{语言}.json 词包到翻译表并重建缓存。
     * 与 tools/i18n/import.php 同源，安装完成后自动执行一次。
     * @return void
     */
    private function importLanguagePacks(): void
    {
        foreach (\Kernel\Util\Lang::LANGS as $lang) {
            if ($lang === \Kernel\Util\Lang::SOURCE) {
                continue;
            }
            $file = BASE_PATH . "/assets/lang/{$lang}.json";
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $rows = [];
            foreach ($data as $source => $text) {
                if (is_array($text)) {
                    $text = $text['text'] ?? '';
                }
                if (!is_string($text) || trim($text) === '') {
                    continue;
                }
                $rows[] = [
                    'source' => (string)$source,
                    'lang' => $lang,
                    'text' => trim($text),
                    'status' => 2,
                    'scene' => 'tpl',
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                \Kernel\Util\Lang::storeBatch($chunk);
            }
        }
        \Kernel\Util\Lang::rebuild();
    }
}
