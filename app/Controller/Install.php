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
        $data['ext']['date'] = extension_loaded("date");
        $data['ext']['json'] = extension_loaded("json");
        $data['ext']['session'] = extension_loaded("session");
        $data['ext']['zip'] = extension_loaded("zip");
        //bcmath：Kernel\Util\Decimal 全靠它做金额运算，订单/充值/分站/商品都在用，缺了会直接致命错误
        $data['ext']['bcmath'] = extension_loaded("bcmath") && function_exists("bcadd");


        $data['install'] = true;
        $data['missing'] = [];

        if ($data['php_version'] < 8) {
            $data['install'] = false;
        }

        //不因为 PHP 版本不达标就跳过扩展检查，一次把所有缺的都列出来，免得站长改完版本再回来发现还缺扩展
        foreach ($data['ext'] as $name => $ext) {
            if (!$ext) {
                $data['install'] = false;
                $data['missing'][] = $name;
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

        $host = $map['host'] == '' ? 'localhost' : $map['host'];

        $email = $map['email'];
        $nickname = $map['nickname'];
        $login_password = $map['login_password'];

        if (!Validation::email($email)) {
            throw new JSONException("管理员邮箱格式不正确");
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
        SQL::import($sqlFile . ".tmp", $host, $map['database'], $map['username'], $map['password'], $map['prefix']);

        //请求日志密钥：随机生成后只存库。这里还不能用 Eloquent（连接是用安装前的
        //配置建的），所以拿刚刚验证过的这套凭据直连写入。失败不影响安装——没有密钥
        //就是不记请求日志，不会退回明文。
        try {
            $pdo = new \PDO('mysql:dbname=' . $map['database'] . ';host=' . $host, $map['username'], $map['password']);
            $stmt = $pdo->prepare('INSERT INTO `' . $map['prefix'] . 'config` (`key`, `value`) VALUES (?, ?)');
            $stmt->execute(['request_log_key', base64_encode(random_bytes(32))]);
        } catch (\Throwable $e) {
        }

        //设置数据库账号密码
        setConfig([
            'driver' => 'mysql',
            'host' => $host,
            'database' => $map['database'],
            'username' => $map['username'],
            'password' => $map['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $map['prefix']
        ], BASE_PATH . "/config/database.php");

        Opcache::invalidate(BASE_PATH . "/config/database.php");

        unlink($sqlFile . ".tmp");
        file_put_contents(BASE_PATH . '/kernel/Install/Lock', "");

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