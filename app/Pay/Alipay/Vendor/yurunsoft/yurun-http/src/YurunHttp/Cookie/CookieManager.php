<?php

namespace Yurun\Util\YurunHttp\Cookie;

use Yurun\Util\YurunHttp\Http\Psr7\Uri;

class CookieManager
{
    /**
     * Cookie 列表.
     *
     * @var \Yurun\Util\YurunHttp\Cookie\CookieItem[]
     */
    protected $cookieList;

    /**
     * 关联集合.
     *
     * @var array
     */
    protected $relationMap;

    /**
     * 自增ID
     * 会比当前列表长度+1.
     *
     * @var int
     */
    protected $autoIncrementId;

    /**
     * __construct.
     *
     * @param array $cookieList
     */
    public function __construct($cookieList = [])
    {
        $this->setCookieList($cookieList);
    }

    /**
     * 设置 Cookie 列表.
     *
     * @param array $cookieList
     *
     * @return void
     */
    public function setCookieList($cookieList)
    {
        $this->autoIncrementId = 1;
        $this->cookieList = [];
        $this->relationMap = [];
        foreach ($cookieList as $item)
        {
            $item = CookieItem::newInstance($item);
            $this->insertCookie($item);
        }
    }

    /**
     * 获取 Cookie 列表.
     *
     * @return array
     */
    public function getCookieList()
    {
        return $this->cookieList;
    }

    /**
     * 添加 Set-Cookie.
     *
     * @param string $setCookie
     *
     * @return \Yurun\Util\YurunHttp\Cookie\CookieItem
     */
    public function addSetCookie($setCookie)
    {
        $item = CookieItem::fromSetCookie($setCookie);
        if (($id = $this->findCookie($item)) > 0)
        {
            $this->updateCookie($id, $item);
        }
        else
        {
            $this->insertCookie($item);
        }

        return $item;
    }

    /**
     * 设置 Cookie.
     *
     * @param string $name
     * @param string $value
     * @param int    $expires
     * @param string $path
     * @param string $domain
     * @param bool   $secure
     * @param bool   $httpOnly
     *
     * @return \Yurun\Util\YurunHttp\Cookie\CookieItem
     */
    public function setCookie($name, $value, $expires = 0, $path = '/', $domain = '', $secure = false, $httpOnly = false)
    {
        $item = new CookieItem($name, $value, $expires, $path, $domain, $secure, $httpOnly);
        if (($id = $this->findCookie($item)) > 0)
        {
            $this->updateCookie($id, $item);
        }
        else
        {
            $this->insertCookie($item);
        }

        return $item;
    }

    /**
     * Cookie 数量.
     *
     * @return int
     */
    public function count()
    {
        return \count($this->cookieList);
    }

    /**
     * 获取请求所需 Cookie 关联数组.
     *
     * @param \Psr\Http\Message\UriInterface $uri
     *
     * @return array
     */
    public function getRequestCookies($uri)
    {
        // @phpstan-ignore-next-line
        if (\defined('SWOOLE_VERSION') && \SWOOLE_VERSION < 4.4)
        {
            // Fix bug: https://github.com/swoole/swoole-src/pull/2644
            $result = json_decode('[]', true);
        }
        else
        {
            $result = [];
        }
        $uriDomain = Uri::getDomain($uri);
        $uriPath = $uri->getPath();
        $cookieList = &$this->cookieList;
        foreach ($this->relationMap as $relationDomain => $list1)
        {
            if ('' === $relationDomain || $this->checkDomain($uriDomain, $relationDomain))
            {
                foreach ($list1 as $path => $idList)
                {
                    if ($this->checkPath($uriPath, $path))
                    {
                        foreach ($idList as $id)
                        {
                            $cookieItem = $cookieList[$id];
                            if ((0 === $cookieItem->expires || $cookieItem->expires > time()) && (!$cookieItem->secure || 'https' === $uri->getScheme() || 'wss' === $uri->getScheme()))
                            {
                                $result[$cookieItem->name] = $cookieItem->value;
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 获取请求所需 Cookie 关联数组.
     *
     * @param \Psr\Http\Message\UriInterface $uri
     *
     * @return string
     */
    public function getRequestCookieString($uri)
    {
        $content = '';
        foreach ($this->getRequestCookies($uri) as $name => $value)
        {
            $content .= "{$name}={$value}; ";
        }

        return $content;
    }

    /**
     * 获取 CookieItem.
     *
     * @param string $name
     * @param string $domain
     * @param string $path
     *
     * @return \Yurun\Util\YurunHttp\Cookie\CookieItem|null
     */
    public function getCookieItem($name, $domain = '', $path = '/')
    {
        if (isset($this->relationMap[$domain][$path][$name]))
        {
            $id = $this->relationMap[$domain][$path][$name];

            return $this->cookieList[$id];
        }

        return null;
    }

    /**
     * 检查 uri 域名和 cookie 域名.
     *
     * @param string $uriDomain
     * @param string $cookieDomain
     *
     * @return bool
     */
    private function checkDomain($uriDomain, $cookieDomain)
    {
        return ($uriDomain === $cookieDomain)
                || (isset($cookieDomain[0]) && '.' === $cookieDomain[0] && substr($uriDomain, -\strlen($cookieDomain) - 1) === '.' . $cookieDomain)
                ;
    }

    /**
     * 检查 uri 路径和 cookie 路径.
     *
     * @param string $uriPath
     * @param string $cookiePath
     *
     * @return bool
     */
    private function checkPath($uriPath, $cookiePath)
    {
        $uriPath = rtrim($uriPath, '/');
        $cookiePath = rtrim($cookiePath, '/');
        if ($uriPath === $cookiePath)
        {
            return true;
        }
        $uriPathDSCount = substr_count($uriPath, '/');
        $cookiePathDSCount = substr_count($cookiePath, '/');
        if ('' === $uriPath)
        {
            $uriPath = '/';
        }
        if ('' === $cookiePath)
        {
            $cookiePath = '/';
        }
        if ($uriPathDSCount > $cookiePathDSCount)
        {
            if (version_compare(\PHP_VERSION, '7.0', '>='))
            {
                $path = \dirname($uriPath, $uriPathDSCount - $cookiePathDSCount);
            }
            else
            {
                $count = $uriPathDSCount - $cookiePathDSCount;
                $path = $uriPath;
                while ($count--)
                {
                    $path = \dirname($path);
                }
            }
            if ('\\' === \DIRECTORY_SEPARATOR && false !== strpos($path, \DIRECTORY_SEPARATOR))
            {
                $path = str_replace(\DIRECTORY_SEPARATOR, '/', $path);
            }

            return $path === $cookiePath;
        }
        else
        {
            return false;
        }
    }

    /**
     * 更新 Cookie 数据.
     *
     * @param int                                     $id
     * @param \Yurun\Util\YurunHttp\Cookie\CookieItem $item
     *
     * @return void
     */
    private function updateCookie($id, $item)
    {
        if (isset($this->cookieList[$id]))
        {
            $object = $this->cookieList[$id];
            // @phpstan-ignore-next-line
            foreach ($item as $k => $v)
            {
                $object->$k = $v;
            }
        }
    }

    /**
     * 插入 Cookie 数据.
     *
     * @param \Yurun\Util\YurunHttp\Cookie\CookieItem $item
     *
     * @return int
     */
    private function insertCookie($item)
    {
        $id = $this->autoIncrementId++;
        $this->cookieList[$id] = $item;
        $this->relationMap[$item->domain][$item->path][$item->name] = $id;

        return $id;
    }

    /**
     * 查找 Cookie ID.
     *
     * @param \Yurun\Util\YurunHttp\Cookie\CookieItem $item
     *
     * @return int|null
     */
    private function findCookie($item)
    {
        if (isset($this->relationMap[$item->domain][$item->path][$item->name]))
        {
            return $this->relationMap[$item->domain][$item->path][$item->name];
        }
        else
        {
            return null;
        }
    }
}
