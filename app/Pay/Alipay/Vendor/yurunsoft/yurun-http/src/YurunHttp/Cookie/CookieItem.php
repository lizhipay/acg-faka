<?php

namespace Yurun\Util\YurunHttp\Cookie;

/**
 * Cookie 项.
 */
class CookieItem
{
    /**
     * 名称.
     *
     * @var string
     */
    public $name;

    /**
     * 值
     *
     * @var string
     */
    public $value;

    /**
     * 过期时间戳.
     *
     * @var int
     */
    public $expires = 0;

    /**
     * 路径.
     *
     * @var string
     */
    public $path = '/';

    /**
     * 域名.
     *
     * @var string
     */
    public $domain = '';

    /**
     * 是否 https.
     *
     * @var bool
     */
    public $secure = false;

    /**
     * 是否禁止 js 操作该 Cookie.
     *
     * @var bool
     */
    public $httpOnly = false;

    /**
     * @param string $name
     * @param string $value
     * @param int    $expires
     * @param string $path
     * @param string $domain
     * @param bool   $secure
     * @param bool   $httpOnly
     */
    public function __construct($name, $value, $expires = 0, $path = '/', $domain = '', $secure = false, $httpOnly = false)
    {
        $this->name = $name;
        $this->value = $value;
        $this->expires = (int) $expires;
        $this->path = $path;
        $this->domain = $domain;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
    }

    /**
     * 获取新实例对象
     *
     * @param array $data
     *
     * @return static
     */
    public static function newInstance($data)
    {
        $object = new static('', '');
        foreach ($data as $k => $v)
        {
            $object->$k = $v;
        }

        return $object;
    }

    /**
     * 从 Set-Cookie 中解析.
     *
     * @param string $setCookieContent
     *
     * @return static|null
     */
    public static function fromSetCookie($setCookieContent)
    {
        if (preg_match_all('/;?\s*((?P<name>[^=;]+)=(?P<value>[^;]+)|((?P<name2>[^=;]+)))/', $setCookieContent, $matches) > 0)
        {
            $name = $matches['name'][0];
            $value = $matches['value'][0];
            unset($matches['name'][0], $matches['value'][0]);
            $data = array_combine(array_map('strtolower', $matches['name']), $matches['value']);
            if (isset($data['']))
            {
                unset($data['']);
            }
            if (isset($data['max-age']))
            {
                $expires = time() + $data['max-age'];
            }
            elseif (isset($data['expires']))
            {
                $expires = strtotime($data['expires']);
            }
            else
            {
                $expires = null;
            }
            foreach ($matches['name2'] as $boolItemName)
            {
                if ('' !== $boolItemName)
                {
                    $data[strtolower($boolItemName)] = true;
                }
            }
            $object = new static($name, $value, $expires, isset($data['path']) ? $data['path'] : '/', isset($data['domain']) ? $data['domain'] : '', isset($data['secure']) ? $data['secure'] : false, isset($data['httponly']) ? $data['httponly'] : false);

            return $object;
        }
        else
        {
            return null;
        }
    }
}
