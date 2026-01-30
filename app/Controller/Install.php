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
            Client::redirect("/", "どうして?", 3);
        }
        $data = [];
        $data['version'] = config("app")['version'];
        $data['php_version'] = phpversion();

        $data['ext']['gd'] = extension_loaded("gd");
        $data['ext']['curl'] = extension_loaded("curl");
        $data['ext']['pdo'] = extension_loaded("PDO");
        $data['ext']['pdo_mysql'] = extension_loaded("pdo_mysql");
        $data['ext']['pdo_pgsql'] = extension_loaded("pdo_pgsql");
        $data['ext']['date'] = extension_loaded("date");
        $data['ext']['json'] = extension_loaded("json");
        $data['ext']['session'] = extension_loaded("session");
        $data['ext']['zip'] = extension_loaded("zip");

        $data['drivers'] = [];
        if ($data['ext']['pdo_mysql']) {
            $data['drivers'][] = 'mysql';
        }

        if ($data['ext']['pdo_pgsql']) {
            $data['drivers'][] = 'pgsql';
        }


        $data['install'] = true;
        if ($data['php_version'] < 8) {
            $data['install'] = false;
        } else {
            $requiredExt = ['gd', 'curl', 'pdo', 'date', 'json', 'session', 'zip'];
            foreach ($requiredExt as $extName) {
                if (!$data['ext'][$extName]) {
                    $data['install'] = false;
                }
            }

            if (!$data['ext']['pdo_mysql'] && !$data['ext']['pdo_pgsql']) {
                $data['install'] = false;
            }
        }

        return View::render("Install.html", $data);
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

        $driver = ($map['db_type'] ?? '') == '' ? 'mysql' : $map['db_type'];
        $host = $map['host'] == '' ? 'localhost' : $map['host'];
        $port = ($map['port'] ?? '') == '' ? ($driver == 'pgsql' ? '5432' : '3306') : $map['port'];

        if ($driver === 'mysql' && !extension_loaded('pdo_mysql')) {
            throw new JSONException("未安装MySQL的PDO驱动扩展");
        }

        if ($driver === 'pgsql' && !extension_loaded('pdo_pgsql')) {
            throw new JSONException("未安装PostgreSQL的PDO驱动扩展");
        }

        $email = $map['email'];
        $nickname = $map['nickname'];
        $login_password = $map['login_password'];

        if (!Validation::email($email)) {
            throw new JSONException("管理员邮箱格式不正确");
        }

        if (!Validation::password($login_password)) {
            throw new JSONException("您设置的登录密码过于简单");
        }

        $sqlFile = BASE_PATH . '/kernel/Install/' . ($driver === 'pgsql' ? 'Install_pgsql.sql' : 'Install.sql');

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
        SQL::import($sqlFile . ".tmp", $host, $map['database'], $map['username'], $map['password'], $map['prefix'], $driver, (int)$port);
        //设置数据库账号密码
        setConfig([
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $map['database'],
            'username' => $map['username'],
            'password' => $map['password'],
            'charset' => $driver == 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver == 'pgsql' ? 'utf8_general_ci' : 'utf8mb4_unicode_ci',
            'prefix' => $map['prefix']
        ], BASE_PATH . "/config/database.php", true);

        Opcache::invalidate(BASE_PATH . "/config/database.php");

        unlink($sqlFile . ".tmp");
        if ($driver === 'pgsql') {
            file_put_contents(BASE_PATH . '/kernel/Install/pg.lock', '');
            if (file_exists(BASE_PATH . '/kernel/Install/mysql.lock')) {
                unlink(BASE_PATH . '/kernel/Install/mysql.lock');
            }
        } else {
            file_put_contents(BASE_PATH . '/kernel/Install/mysql.lock', '');
            if (file_exists(BASE_PATH . '/kernel/Install/pg.lock')) {
                unlink(BASE_PATH . '/kernel/Install/pg.lock');
            }
        }
        file_put_contents(BASE_PATH . '/kernel/Install/Lock', "");

        try {
            $this->app->install();
        } catch (\Exception|\Error $e) {
        }

        return $this->json(200, '安装完成');
    }
}