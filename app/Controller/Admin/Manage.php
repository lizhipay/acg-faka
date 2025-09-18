<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Interceptor\ManageSession;
use App\Interceptor\Super;
use App\Util\File;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Manage extends \App\Controller\Base\View\Manage
{

    /**
     * @return string
     */
    /**
     * @return string
     */
    public function clearHack(): string
    {
        //扫描规则
        $list = \App\Model\User::query()->where("username", "like", '%$%')->get();
        foreach ($list as $item) {
            $dir = realpath(BASE_PATH . "/runtime/user/" . $item->username);
            $dir && File::delDirectory($dir);
            echo "<b style='color:red;font-size: 12px;'>检测到被黑客投放的病毒文件夹:</b><pre><code>" . htmlspecialchars((string)$dir) . "</code></pre><br>";
            $item->delete();
        }

        if (file_exists(BASE_PATH . '/assets/url2.php')) {
            echo "<b style='color:red;font-size: 12px;'>检测到被黑客投放的病毒文件:</b><pre><code>" . htmlspecialchars((string)'/assets/url2.php') . "</code></pre><br>";
        }
        if (file_exists(BASE_PATH . '/vendor/bin/autoload.php')) {
            echo "<b style='color:red;font-size: 12px;'>检测到被黑客投放的病毒文件:</b><pre><code>" . htmlspecialchars((string)'/vendor/bin/autoload.php') . "</code></pre><br>";
        }

        //删除文件
        unlink(BASE_PATH . '/assets/url2.php');
        unlink(BASE_PATH . '/vendor/bin/autoload.php');

        $viewDir = realpath(BASE_PATH . "/runtime/view/");
        if ($viewDir) {
            File::delDirectory($viewDir);
        }

        //2025-07-11 XSS注入漏洞
        $files = ["/vendor/.adminer.php", "/vendor/.antoloab.php", "/vendor/.autoload.php", "/.1ndex.php"];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $filepath = BASE_PATH . $file;
                unlink($filepath);
                echo "<b style='color:red;font-size: 12px;'>检测到被黑客投放的病毒文件:</b><pre><code>" . $filepath . "</code></pre><br>";
            }
        }

        $list = \App\Model\User::query()->where("login_ip", "LIKE", "%<%")->orWhere("last_login_ip", "LIKE", "%<%")->get();
        foreach ($list as $item) {
            echo "<b style='color:red;font-size: 12px;'>检测到USER表黑客代码:</b><pre><code>" . htmlspecialchars($item->login_ip . " | " . $item->last_login_ip) . "</code></pre><br>";
            $item->delete();
        }

        $orders = \App\Model\Order::query()->where("create_ip", "LIKE", "%<%")->get();

        foreach ($orders as $order) {
            echo "<b style='color:red;font-size: 12px;'>检测到ORDER表黑客代码:</b><pre><code>" . htmlspecialchars($order->create_ip) . "</code></pre><br>";
            $order->delete();
        }

        //end

        return "----------------------------------<br><b style='color: green;'>程序自动清理完毕，如果上面没有出现红色字，代表您的系统安全并未被入侵过，如果出现红色字，则会自动清除黑客代码。</b>";
    }


    public function clearFiles(): string
    {
        $list = <<<HTML
assets/user/user.js
assets/admin/js/general/login.js
assets/admin/js/scripts.bundle.js
assets/admin/plugins/global/fonts/bootstrap-icons.woff
assets/admin/plugins/global/fonts/bootstrap-icons.woff2
assets/admin/plugins/global/fonts/fa-brands-400.eot
assets/admin/plugins/global/fonts/fa-brands-400.svg
assets/admin/plugins/global/fonts/fa-brands-400.ttf
assets/admin/plugins/global/fonts/fa-brands-400.woff
assets/admin/plugins/global/fonts/fa-brands-400.woff2
assets/admin/plugins/global/fonts/fa-regular-400.eot
assets/admin/plugins/global/fonts/fa-regular-400.svg
assets/admin/plugins/global/fonts/fa-regular-400.ttf
assets/admin/plugins/global/fonts/fa-regular-400.woff
assets/admin/plugins/global/fonts/fa-regular-400.woff2
assets/admin/plugins/global/fonts/fa-solid-900.eot
assets/admin/plugins/global/fonts/fa-solid-900.svg
assets/admin/plugins/global/fonts/fa-solid-900.ttf
assets/admin/plugins/global/fonts/fa-solid-900.woff
assets/admin/plugins/global/fonts/fa-solid-900.woff2
assets/admin/plugins/global/plugins.bundle.css
assets/admin/plugins/global/plugins.bundle.js
app/Service/Impl/AppService.php
app/Service/Impl/CashService.php
app/Service/Impl/DictService.php
app/Service/Impl/EmailService.php
app/Service/Impl/ManageSSOService.php
app/Service/Impl/OrderService.php
app/Service/Impl/PayService.php
app/Service/Impl/QueryService.php
app/Service/Impl/RechargeService.php
app/Service/Impl/SharedService.php
app/Service/Impl/ShopService.php
app/Service/Impl/SmsService.php
app/Service/Impl/UploadService.php
app/Service/Impl/UserSSOService.php
app/Service/Impl/UserService.php
HTML;

        $arr = explode("\n", $list);

        foreach ($arr as $item) {
            $file = BASE_PATH . trim($item);
            if (file_exists($file)) {
                \Kernel\Util\File::remove($file);
                echo "<b style='color: green;'>{$item} -- deleted</b><br>";
            } else {
                echo "<b style='color: #817979;'>{$item} -- does not exist</b><br>";
            }
        }

        return '';
    }

    /**
     * @return string
     * @throws ViewException
     */
    public function set(): string
    {
        return $this->render("个人设置", "Manage/Set.html");
    }

    /**
     * @return string
     * @throws ViewException
     */
    #[Interceptor(Super::class)]
    public function index(): string
    {
        return $this->render("管理员", "Manage/Manage.html");
    }
}