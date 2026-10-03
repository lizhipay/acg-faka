<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Category;
use App\Model\Commodity;
use App\Model\User;

/**
 * 后台「商品管理」左侧的分类树：主站分类在上、商家（分站）分类按商家分组，
 * 每个节点的数量含全部下级分类的商品；点父分类时连同全部下级一起筛选。
 */
class CategoryTree
{
    /** 显示范围：整站 / 仅主站 / 仅商家（与商品列表的 display_scope 一致） */
    public const SCOPE_ALL = 0;
    public const SCOPE_MAIN = 1;
    public const SCOPE_MERCHANT = 2;

    /**
     * 分类自身及全部下级分类的 ID。数据异常（父子成环）时靠已访问集合防死循环。
     * @return int[]
     */
    public static function descendantIds(int $categoryId): array
    {
        $children = [];
        foreach (Category::query()->get(['id', 'pid']) as $row) {
            $children[(int)$row->pid][] = (int)$row->id;
        }

        $seen = [];
        $queue = [$categoryId];
        while ($queue) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }
        return array_keys($seen);
    }

    /**
     * @param int $scope SCOPE_*
     * @param int $userId 仅商家时指定的商家，0 = 全部商家
     * @return array{total:int, main:array, merchants:array}
     */
    public static function forAdmin(int $scope, int $userId = 0): array
    {
        $counts = Commodity::query()
            ->selectRaw('category_id, COUNT(*) AS total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id')
            ->all();

        $nodes = [];
        $owners = [];
        //编辑弹窗需要的字段一并带上（与分类管理页同一份表单），省一次请求
        $site = Client::getUrl();
        $rows = Category::query()->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'pid', 'owner', 'status', 'hide', 'icon', 'sort', 'user_level_config']);
        foreach ($rows as $row) {
            $id = (int)$row->id;
            $nodes[$id] = [
                'id' => $id,
                'name' => (string)$row->name,
                'pid' => (int)$row->pid,
                'owner' => (int)$row->owner,
                'status' => (int)$row->status,
                'hide' => (int)$row->hide,
                'icon' => (string)$row->icon,
                'sort' => (int)$row->sort,
                'user_level_config' => (string)$row->user_level_config,
                'share_url' => $site . "/cat/{$id}",
                'count' => (int)($counts[$id] ?? 0),
                'children' => [],
            ];
            $owners[$id] = [(int)$row->owner, (int)$row->pid];
        }

        //先挂子节点（保持 sort 顺序），再按归属收集顶级节点
        $roots = [];
        foreach ($owners as $id => [$owner, $pid]) {
            if ($pid > 0 && $pid !== $id && isset($nodes[$pid])) {
                $nodes[$pid]['children'][] = &$nodes[$id];
            } else {
                $roots[$owner][] = &$nodes[$id];
            }
        }
        foreach ($roots as &$list) {
            foreach ($list as &$root) {
                self::sumUp($root, 0);
            }
            unset($root);
        }
        unset($list);

        $main = $scope === self::SCOPE_MERCHANT ? [] : self::detach($roots[0] ?? []);

        $merchants = [];
        if ($scope !== self::SCOPE_MAIN) {
            $ownerIds = array_values(array_filter(array_keys($roots), static fn($owner) => $owner > 0
                && ($scope !== self::SCOPE_MERCHANT || $userId <= 0 || $owner === $userId)));
            $names = $ownerIds ? User::query()->whereIn('id', $ownerIds)->pluck('username', 'id')->all() : [];
            foreach ($ownerIds as $owner) {
                $children = self::detach($roots[$owner]);
                $merchants[] = [
                    'id' => $owner,
                    'name' => (string)($names[$owner] ?? ('#' . $owner)),
                    'count' => array_sum(array_column($children, 'count')),
                    'children' => $children,
                ];
            }
            usort($merchants, static fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));
        }

        $total = Commodity::query();
        if ($scope === self::SCOPE_MAIN) {
            $total->where('owner', 0);
        } elseif ($scope === self::SCOPE_MERCHANT) {
            $userId > 0 ? $total->where('owner', $userId) : $total->where('owner', '!=', 0);
        }

        return ['total' => $total->count(), 'main' => $main, 'merchants' => $merchants];
    }

    /**
     * 自下而上累加数量（含下级）。深度兜底防异常数据。
     */
    private static function sumUp(array &$node, int $depth): int
    {
        if ($depth > 100) {
            return $node['count'];
        }
        foreach ($node['children'] as &$child) {
            $node['count'] += self::sumUp($child, $depth + 1);
        }
        unset($child);
        return $node['count'];
    }

    /**
     * 断开引用，得到可安全 json 输出的普通数组。
     */
    private static function detach(array $nodes): array
    {
        return json_decode(json_encode($nodes, JSON_UNESCAPED_UNICODE), true) ?: [];
    }
}
