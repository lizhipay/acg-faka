<?php

namespace Yurun\PaySDK\Lib;

class XML
{
    public static function fromString($string)
    {
        // PHP8中已经废弃
        if (\PHP_VERSION_ID >= 80000) {
            return (array) simplexml_load_string($string, null, \LIBXML_NOCDATA | \LIBXML_COMPACT);
        }

        // 填补 php <= 5.4 的安全漏洞：https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=23_5
        // 记录旧值
        $oldValue = libxml_disable_entity_loader(true);
        // xml解析
        $result = (array) simplexml_load_string($string, null, \LIBXML_NOCDATA | \LIBXML_COMPACT);
        // 恢复旧值，防止系统中其它需要用到实体加载的地方失效
        if (false === $oldValue)
        {
            libxml_disable_entity_loader(false);
        }

        return $result;
    }

    public static function toString($data)
    {
        $result = '<xml>';
        if (\is_object($data))
        {
            $_data = ObjectToArray::parse($data);
        }
        else
        {
            $_data = &$data;
        }
        foreach ($_data as $key => $value)
        {
            if (!is_scalar($value))
            {
                if (\is_object($value) && method_exists($value, 'toString'))
                {
                    $value = $value->toString();
                    if (null === $value)
                    {
                        continue;
                    }
                }
                elseif (null !== $value)
                {
                    $value = json_encode($value);
                }
                else
                {
                    continue;
                }
            }
            $result .= "<{$key}><![CDATA[{$value}]]></{$key}>";
        }

        return $result . '</xml>';
    }
}
