<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class Cash extends User
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        $count = \App\Model\Cash::query()->where("status", 0)->where("user_id", $this->getUser()->id)->count();
        return $this->theme("硬币兑现", "CASH", "User/Cash.html", ["processing" => $count]);
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function record(): string
    {
        return $this->theme("兑现记录", "CASH_RECORD", "User/CashRecord.html");
    }
}