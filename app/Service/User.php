<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Impl\UserService;
use Kernel\Annotation\Bind;

#[Bind(class: UserService::class)]
interface User
{
}