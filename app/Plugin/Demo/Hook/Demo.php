<?php
declare(strict_types=1);

namespace App\Plugin\Demo\Hook;


use App\Controller\Base\View\ManagePlugin;
use Kernel\Annotation\Hook;

class Demo extends ManagePlugin
{

    /**
     * @throws \Kernel\Exception\ViewException
     */
    #[Hook(point: \App\Consts\Hook::ADMIN_VIEW_MENU)]
    public function say()
    {
        echo $this->render(null, "Menu.html");
    }

}