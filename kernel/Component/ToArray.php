<?php
declare(strict_types=1);

namespace Kernel\Component;

trait ToArray
{
    /**
     * @return array
     */
    public function toArray(): array
    {
        $array = [];
        $reflectionClass = new \ReflectionClass($this);
        foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $property->setAccessible(true);
            $propertyName = $property->getName();
            $value = $property->getValue($this);
            $arrayName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
            if (is_object($value) && method_exists($value, 'toArray')) {
                $array[$arrayName] = $value->toArray();
            } elseif (is_array($value)) {
                $array[$arrayName] = array_map(function ($item) {
                    return is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : $item;
                }, $value);
            } else if ($value !== null) {
                $array[$arrayName] = $value;
            }
        }
        return $array;
    }
}