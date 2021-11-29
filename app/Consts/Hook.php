<?php
declare(strict_types=1);

namespace App\Consts;


interface Hook
{
    //挂载点 app\View\Admin\Footer.html
    const ADMIN_VIEW_FOOTER = 0x1;
    //挂载点 app\View\Admin\Header.html
    const ADMIN_VIEW_HEADER = 0x2;
    //挂载点 app\View\Admin\Header.html
    const ADMIN_VIEW_MENU = 0x3;
}