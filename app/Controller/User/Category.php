<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class, Business::class])]
class Category extends User
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->theme("商品分类", "CATEGORY", "User/Category.html");
    }
}