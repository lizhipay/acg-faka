<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Interceptor\ManageSession;
use App\Interceptor\Super;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Manage extends \App\Controller\Base\View\Manage
{

    /**
     * @return string
     */
    public function clearHack(): string
    {
        $list = \App\Model\Manage::query()->where("avatar", "like", "%\"%")->get();

        foreach ($list as $item) {
            echo "<b style='color:red;font-size: 12px;'>检测到病毒并且自动修复和清除:</b><pre><code>" . htmlspecialchars((string)$item->avatar) . "</code></pre><br>";
            $item->avatar = "";
            $item->save();
        }

        $list = \App\Model\User::query()->where("avatar", "like", "%\"%")->get();

        foreach ($list as $item) {
            echo "<b style='color:red;font-size: 12px;'>检测到病毒并且自动修复和清除:</b><pre><code>" . htmlspecialchars((string)$item->avatar) . "</code></pre><br>";
            $item->delete();
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