<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\UserGroup;
use App\Service\Query;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;


#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class CommodityGroup extends Manage
{
    private const MAX_COMMODITIES = 500;
    private const MAX_DELETE_GROUPS = 100;
    private const MAX_UNSIGNED_INT = 4294967295;

    #[Inject]
    private Query $query;

    /**
     * @param mixed $value
     * @return int[]
     * @throws JSONException
     */
    private function commodityIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            throw new JSONException('商品列表格式不正确');
        }

        $ids = [];
        foreach ($value as $candidate) {
            if (is_int($candidate)) {
                $id = $candidate;
            } elseif (is_string($candidate) && ctype_digit(trim($candidate))) {
                $id = (int)trim($candidate);
            } else {
                throw new JSONException('商品列表只能包含正整数 ID');
            }
            if ($id <= 0 || $id > self::MAX_UNSIGNED_INT) {
                throw new JSONException('商品列表只能包含正整数 ID');
            }
            $ids[$id] = $id;
            if (count($ids) > self::MAX_COMMODITIES) {
                throw new JSONException('单个商品分组最多可选择 ' . self::MAX_COMMODITIES . ' 个商品');
            }
        }

        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param mixed $value
     * @return int[]
     * @throws JSONException
     */
    private function deletionIds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new JSONException('请选择要删除的商品分组');
        }

        $ids = [];
        foreach ($value as $candidate) {
            if (is_int($candidate)) {
                $id = $candidate;
            } elseif (is_string($candidate) && ctype_digit(trim($candidate))) {
                $id = (int)trim($candidate);
            } else {
                throw new JSONException('商品分组 ID 格式不正确');
            }
            if ($id <= 0 || $id > self::MAX_UNSIGNED_INT) {
                throw new JSONException('商品分组 ID 格式不正确');
            }
            $ids[$id] = $id;
            if (count($ids) > self::MAX_DELETE_GROUPS) {
                throw new JSONException('单次最多删除 ' . self::MAX_DELETE_GROUPS . ' 个商品分组');
            }
        }

        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param array $config
     * @param int[] $ids
     * @return bool
     */
    private function usesAnyDiscount(array $config, array $ids): bool
    {
        foreach ($ids as $id) {
            if (array_key_exists($id, $config)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\CommodityGroup::class);
        $get->setWhere($map);
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        $rawId = $map['id'] ?? 0;
        if ($rawId === null || $rawId === '') {
            $id = 0;
        } elseif (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && ctype_digit(trim($rawId))) {
            $id = (int)trim($rawId);
        } else {
            throw new JSONException('商品分组 ID 格式不正确');
        }
        if ($id < 0 || $id > self::MAX_UNSIGNED_INT) {
            throw new JSONException('商品分组 ID 格式不正确');
        }

        $name = trim((string)($map['name'] ?? ''));
        if ($name === '') {
            throw new JSONException('分组名称不能为空');
        }
        $commodityIds = $this->commodityIds($map['commodity_list'] ?? []);

        $saved = DB::transaction(function () use ($id, $name, $commodityIds) {
            // Commodity deletion follows the same Commodity -> CommodityGroup
            // lock order. Sorting both sets makes concurrent group edits and
            // batch deletes deterministic and avoids leaving JSON references.
            if ($commodityIds !== []) {
                $lockedCommodityIds = \App\Model\Commodity::query()
                    ->whereIn('id', $commodityIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(static fn($commodityId): int => (int)$commodityId)
                    ->all();
                if ($lockedCommodityIds !== $commodityIds) {
                    throw new JSONException('部分商品不存在，请刷新商品列表后重试');
                }
            }

            if ($id > 0) {
                $lockedCommodityGroup = \App\Model\CommodityGroup::query()
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first(['id']);
                if (!$lockedCommodityGroup) {
                    throw new JSONException('商品分组不存在，请刷新后重试');
                }
            }

            $save = new Save(\App\Model\CommodityGroup::class);
            $save->setMap(['id' => $id, 'name' => $name], ['id', 'name']);
            $save->addForceMap('commodity_list', $commodityIds);
            if ($id > 0) {
                $save->disableAddable();
            }
            return $this->query->save($save);
        });
        if (!$saved) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[新增/修改]商品分组");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * Build the mobile-only category tree used by the product-group picker.
     * Only categories that contain products, plus their ancestor path, are
     * included so the selector stays focused while preserving true depth.
     *
     * @param array<int, array<string, mixed>> $list
     * @param array<int, mixed> $commodityList
     * @return array<int, array<string, mixed>>
     */
    private function mobileCommodityTree(array $list, array $commodityList): array
    {
        $categories = [];
        $categoryOrder = [];
        foreach ($list as $category) {
            $categoryId = (int)($category['id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $categories[$categoryId] = $category;
            $categoryOrder[] = $categoryId;
        }

        $included = [];
        foreach ($categoryOrder as $categoryId) {
            if (empty($categories[$categoryId]['children'])) {
                continue;
            }
            $currentId = $categoryId;
            $visited = [];
            while ($currentId > 0 && isset($categories[$currentId]) && !isset($visited[$currentId])) {
                $visited[$currentId] = true;
                $included[$currentId] = true;
                $currentId = (int)($categories[$currentId]['pid'] ?? 0);
            }
        }

        $treeIds = [];
        $categoryChildren = [];
        $roots = [];
        foreach ($categoryOrder as $categoryId) {
            if (!isset($included[$categoryId])) {
                continue;
            }
            $treeIds[$categoryId] = 'category_' . $categoryId;
        }
        foreach ($categoryOrder as $categoryId) {
            if (!isset($included[$categoryId])) {
                continue;
            }
            $parentId = (int)($categories[$categoryId]['pid'] ?? 0);
            if ($parentId > 0 && $parentId !== $categoryId && isset($included[$parentId])) {
                $categoryChildren[$parentId][] = $categoryId;
            } else {
                $roots[] = $categoryId;
            }
        }

        $selected = [];
        foreach ($commodityList as $commodityId) {
            $commodityId = (int)$commodityId;
            if ($commodityId > 0) {
                $selected[$commodityId] = true;
            }
        }

        $subtreeCommodityIds = [];
        $collectCommodityIds = function (int $categoryId, array $trail = []) use (
            &$collectCommodityIds,
            &$subtreeCommodityIds,
            $categories,
            $categoryChildren
        ): array {
            if (isset($subtreeCommodityIds[$categoryId])) {
                return $subtreeCommodityIds[$categoryId];
            }
            if (isset($trail[$categoryId])) {
                return [];
            }
            $trail[$categoryId] = true;
            $ids = [];
            foreach (($categories[$categoryId]['children'] ?? []) as $commodity) {
                $commodityId = (int)($commodity['id'] ?? 0);
                if ($commodityId > 0) {
                    $ids[$commodityId] = $commodityId;
                }
            }
            foreach (($categoryChildren[$categoryId] ?? []) as $childCategoryId) {
                foreach ($collectCommodityIds($childCategoryId, $trail) as $commodityId) {
                    $ids[$commodityId] = $commodityId;
                }
            }
            return $subtreeCommodityIds[$categoryId] = array_values($ids);
        };

        $result = [];
        $rendered = [];
        $rendering = [];
        $renderCategory = function (int $categoryId, int|string $parentTreeId, int $depth) use (
            &$renderCategory,
            &$result,
            &$rendered,
            &$rendering,
            $categories,
            $categoryChildren,
            $treeIds,
            $selected,
            $collectCommodityIds
        ): void {
            if (isset($rendered[$categoryId]) || isset($rendering[$categoryId])) {
                return;
            }
            $rendering[$categoryId] = true;
            $commodityIds = $collectCommodityIds($categoryId);
            $allSelected = $commodityIds !== [];
            foreach ($commodityIds as $commodityId) {
                if (!isset($selected[$commodityId])) {
                    $allSelected = false;
                    break;
                }
            }

            $result[] = [
                'id' => $treeIds[$categoryId],
                'name' => $categories[$categoryId]['name'],
                'pid' => $parentTreeId,
                'checked' => $allSelected,
                'node_type' => 'category',
                'tree_depth' => $depth,
                'category_id' => $categoryId,
            ];

            foreach (($categoryChildren[$categoryId] ?? []) as $childCategoryId) {
                $renderCategory($childCategoryId, $treeIds[$categoryId], $depth + 1);
            }
            foreach (($categories[$categoryId]['children'] ?? []) as $commodity) {
                $commodityId = (int)($commodity['id'] ?? 0);
                if ($commodityId <= 0) {
                    continue;
                }
                $result[] = [
                    'id' => $commodityId,
                    'name' => $commodity['name'],
                    'pid' => $treeIds[$categoryId],
                    'checked' => isset($selected[$commodityId]),
                    'node_type' => 'commodity',
                    'tree_depth' => $depth + 1,
                    'category_id' => $categoryId,
                ];
            }
            unset($rendering[$categoryId]);
            $rendered[$categoryId] = true;
        };

        foreach ($roots as $categoryId) {
            $renderCategory($categoryId, 0, 0);
        }
        foreach ($categoryOrder as $categoryId) {
            if (isset($included[$categoryId]) && !isset($rendered[$categoryId])) {
                $renderCategory($categoryId, 0, 0);
            }
        }
        return $result;
    }

    /**
     * @param int $id
     * @return array
     * @throws JSONException
     */
    public function list(int $id): array
    {
        $commodityGroup = \App\Model\CommodityGroup::query()->find($id);

        if (!$commodityGroup) {
            throw new JSONException("分组不存在");
        }

        $commodity = \App\Model\Category::with(['children'])->orderBy("sort", "asc")->get();
        $list = $commodity->toArray();

        $commodityList = $commodityGroup->commodity_list ?: [];

        if ((string)($_GET['mobile'] ?? '') === '1') {
            return $this->json(data: ["list" => $this->mobileCommodityTree($list, $commodityList)]);
        }

        $result = [];

        foreach ($list as $category) {
            $id = Str::generateRandStr(16);
            $hasCheckedChildren = 0;
            $children = [];

            if (!empty($category['children'])) {
                foreach ($category['children'] as $child) {
                    $isChecked = in_array($child['id'], $commodityList);
                    if ($isChecked) {
                        $hasCheckedChildren++;
                    }

                    $children[] = [
                        'id' => $child['id'],
                        'name' => $child['name'],
                        'pid' => $id,
                        'checked' => $isChecked
                    ];
                }

                // 一级分类
                $result[] = [
                    'id' => $id,
                    'name' => $category['name'],
                    'pid' => 0,
                    'checked' => count($category['children']) === $hasCheckedChildren
                ];
            }

            // 合并子项
            $result = array_merge($result, $children);
        }


        return $this->json(data: ["list" => $result]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function deleteImpact(): array
    {
        $ids = $this->deletionIds($_POST['list'] ?? null);
        $groups = \App\Model\CommodityGroup::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'name']);
        if ($groups->count() !== count($ids)) {
            throw new JSONException('部分商品分组不存在，请刷新后重试');
        }

        $affectedLevels = UserGroup::query()
            ->orderBy('id')
            ->get(['id', 'name', 'discount_config'])
            ->filter(function (UserGroup $level) use ($ids): bool {
                $config = is_array($level->discount_config) ? $level->discount_config : [];
                return $this->usesAnyDiscount($config, $ids);
            })
            ->values();

        return $this->json(data: [
            'group_count' => $groups->count(),
            'group_names' => $groups->pluck('name')->map(static fn($name): string => (string)$name)->all(),
            'affected_level_count' => $affectedLevels->count(),
            'affected_level_names' => $affectedLevels->pluck('name')->map(static fn($name): string => (string)$name)->all(),
        ]);
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        $ids = $this->deletionIds($_POST['list'] ?? null);
        $result = DB::transaction(function () use ($ids): array {
            $groups = \App\Model\CommodityGroup::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            if ($groups->count() !== count($ids)) {
                throw new JSONException('部分商品分组不存在，请刷新后重试');
            }

            $affectedLevelCount = 0;
            $levels = UserGroup::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'discount_config']);
            foreach ($levels as $level) {
                $config = is_array($level->discount_config) ? $level->discount_config : [];
                $changed = false;
                foreach ($ids as $id) {
                    if (array_key_exists($id, $config)) {
                        unset($config[$id]);
                        $changed = true;
                    }
                }
                if (!$changed) {
                    continue;
                }
                $level->discount_config = $config;
                if (!$level->save()) {
                    throw new JSONException('清理会员等级折扣失败，请重试');
                }
                $affectedLevelCount++;
            }

            $deleted = \App\Model\CommodityGroup::query()->whereIn('id', $ids)->delete();
            if ($deleted !== count($ids)) {
                throw new JSONException('商品分组删除不完整，请重试');
            }

            return ['count' => $deleted, 'affected_level_count' => $affectedLevelCount];
        });

        ManageLog::log($this->getManage(), "[删除]商品分组");
        return $this->json(200, '（＾∀＾）移除成功', $result);
    }
}
