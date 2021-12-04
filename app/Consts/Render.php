<?php
declare(strict_types=1);

namespace App\Consts;

interface Render
{
    /**
     * SMARTY渲染
     */
    const  ENGINE_SMARTY = 0x0;


    /**
     * PHP原生渲染
     */
    const ENGINE_PHP = 0x1;
}