<?php
declare (strict_types=1);

namespace App\Controller\Admin;

use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Log extends Manage
{

    #[Interceptor(Owner::class)]
    public function index(): string
    {
        return $this->render("操作日志", "Manage/Log.html");
    }
}