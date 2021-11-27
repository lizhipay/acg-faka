<?php

namespace Yurun\Util\YurunHttp\Traits;

use Yurun\Util\YurunHttp\Cookie\CookieManager;

trait TCookieManager
{
    /**
     * Cookie 管理器.
     *
     * @var \Yurun\Util\YurunHttp\Cookie\CookieManager
     */
    protected $cookieManager;

    /**
     * @return void
     */
    private function initCookieManager()
    {
        $this->cookieManager = new CookieManager();
    }

    /**
     * Get cookie 管理器.
     *
     * @return \Yurun\Util\YurunHttp\Cookie\CookieManager
     */
    public function getCookieManager()
    {
        return $this->cookieManager;
    }
}
