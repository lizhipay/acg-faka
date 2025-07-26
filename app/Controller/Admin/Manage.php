<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Interceptor\ManageSession;
use App\Interceptor\Super;
use App\Util\File;
use Kernel\Annotation\Interceptor;

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

        return "----------------------------------<br>程序已经成功执行完毕。如果上述信息没有显示任何异常，说明您的系统状态良好，无任何风险。若有任何异常信息出现，则说明系统中的病毒已被自动检测并清除。";
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function set(): string
    {
        return $this->render("个人设置", "Manage/Set.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    #[Interceptor(Super::class)]
    public function index(): string
    {
        return $this->render("管理员", "Manage/Manage.html");
    }
}