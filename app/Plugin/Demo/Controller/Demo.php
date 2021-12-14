<?php
declare(strict_types=1);

namespace App\Plugin\Demo\Controller;


use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

/**
 * Class Demo
 * @package App\Plugin\Demo\Controller
 */
#[Interceptor(ManageSession::class)]
class Demo extends ManagePlugin
{

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function test(): string
    {
        return $this->render(title: '插件DEMO', template: 'Demo.html', controller: true);
    }
}