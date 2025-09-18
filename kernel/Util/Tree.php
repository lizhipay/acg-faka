<?php
declare(strict_types=1);

namespace Kernel\Util;
class Tree
{
    /**
     * @param array $array
     * @param string $primaryKey
     * @param string $parentKey
     * @param string $childrenName
     * @return array
     */
    public static function generate(array $array, string $primaryKey = 'id', string $parentKey = 'pid', string $childrenName = 'children'): array
    {
        $items = [];
        foreach ($array as $row) {
            $row = (array)$row;
            $items[$row[$primaryKey]] = $row;
        }
        $tree = [];
        foreach ($items as $k => $item) {
            if (isset($items[$item[$parentKey]])) {
                $items[$item[$parentKey]][$childrenName][] = &$items[$k];
            } else {
                $tree[] = &$items[$k];
            }
        }
        return $tree;
    }


    /**
     * @param array $items
     * @return array
     */
    public static function character(array $items): array
    {
        $handle = function (array $item, array &$result, int $level = 1, int $a = 0, int &$b = 0) use (&$handle): void {
            $isChildren = (isset($item['children']) && is_array($item['children']));
            $children = $isChildren ? $item['children'] : [];
            if ($isChildren) {
                unset($item['children']);
            }
            $item['tree'] = ["name" => $level == 0 ? "├" . $item['name'] : ($a != 0 && $a == $b ? "└" : "├") . str_repeat("──", $level) . $item['name'] , "level" => $level];
            $result[] = $item;
            $b++;
            if (count($children) > 0) {
                foreach ($children as $c) {
                    $handle($c, $result, $level + 1, $a, $b);
                }
            }
        };

        $result = [];
        $items = self::generate($items);
        foreach ($items as $item) {
            $count = 0;
            $handle($item, $result, 0, self::childrenNums($item), $count);
        }
        return $result;
    }

    /**
     * @param array $item
     * @return int
     */
    public static function childrenNums(array $item): int
    {
        $count = 0;
        if (isset($item['children']) && is_array($item['children'])) {
            $count += count($item['children']);
            foreach ($item['children'] as $child) {
                $count += self::childrenNums($child);
            }
        }
        return $count;
    }
}