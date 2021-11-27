<?php

namespace Yurun\PaySDK\Lib;

class ObjectToArray
{
    /**
     * 对象转数组，不会出现非public属性.
     *
     * @param object $object
     *
     * @return array
     */
    public static function parse($object)
    {
        if (method_exists($object, 'toArray'))
        {
            return $object->toArray();
        }
        else
        {
            return get_object_vars($object);
        }
    }
}
