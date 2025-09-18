<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\User::class)]
interface User
{
}