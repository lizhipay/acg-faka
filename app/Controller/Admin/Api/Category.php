<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Consts\Manage as ManageConst;
use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Client;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

/**
 * Class Category
 * @package App\Controller\Admin\Api
 */
#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Category extends Manage
{
    #[Inject]
    private Query $query;

    /**
     * @param mixed $value
     * @return int[]
     */
    private function categoryIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $candidate) {
            if (is_int($candidate)) {
                $id = $candidate;
            } elseif (is_string($candidate) && ctype_digit(trim($candidate))) {
                $id = (int)trim($candidate);
            } else {
                throw new JSONException('商品分类 ID 必须是正整数');
            }
            if ($id <= 0) {
                throw new JSONException('商品分类 ID 必须是正整数');
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        if (count($ids) > 500) {
            throw new JSONException('单次最多操作 500 个商品分类');
        }
        return $ids;
    }

    /**
     * Calculate the impact of deleting only explicitly selected categories.
     * Unselected descendants and every historical/business reference are hard
     * blockers and are never silently cascaded away.
     *
     * @param int[] $requestedIds
     * @return array<string,mixed>
     * @throws JSONException
     */
    private function safeCategoryDeleteImpact(array $requestedIds, bool $lock = false): array
    {
        if ($requestedIds === []) {
            throw new JSONException('你还没有选择商品分类');
        }
        sort($requestedIds, SORT_NUMERIC);

        $categoryQuery = \App\Model\Category::query()->select(['id', 'pid']);
        if ($lock) {
            $categoryQuery->lockForUpdate();
        }
        $categories = $categoryQuery->get();
        $existing = [];
        $children = [];
        foreach ($categories as $category) {
            $id = (int)$category->id;
            $pid = (int)$category->pid;
            $existing[$id] = $pid;
            $children[$pid][] = $id;
        }
        foreach ($requestedIds as $id) {
            if (!array_key_exists($id, $existing)) {
                throw new JSONException('部分商品分类不存在，请刷新后重试');
            }
        }

        $selected = array_fill_keys($requestedIds, true);
        $visited = $selected;
        $queue = $requestedIds;
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($children[$id] ?? [] as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $queue[] = $childId;
            }
        }
        $unselectedDescendantIds = array_values(array_diff(array_map('intval', array_keys($visited)), $requestedIds));
        sort($unselectedDescendantIds, SORT_NUMERIC);

        // Resolve explicit leaf-to-root batches so the self-FK cascade cannot
        // expand the selected scope.
        $remaining = $selected;
        $deleteLevels = [];
        while ($remaining !== []) {
            $leaves = [];
            foreach (array_keys($remaining) as $id) {
                $hasSelectedChild = false;
                foreach ($children[$id] ?? [] as $childId) {
                    if (isset($remaining[$childId])) {
                        $hasSelectedChild = true;
                        break;
                    }
                }
                if (!$hasSelectedChild) {
                    $leaves[] = (int)$id;
                }
            }
            if ($leaves === []) {
                break;
            }
            sort($leaves, SORT_NUMERIC);
            $deleteLevels[] = $leaves;
            foreach ($leaves as $id) {
                unset($remaining[$id]);
            }
        }
        $hierarchyCycleCount = count($remaining);

        $categoryEdges = [];
        foreach ($requestedIds as $id) {
            $categoryEdges[$id] = (int)$existing[$id];
        }
        ksort($categoryEdges, SORT_NUMERIC);

        $commodityQuery = \App\Model\Commodity::query()
            ->whereIn('category_id', $requestedIds)
            ->select(['id', 'category_id']);
        if ($lock) {
            $commodityQuery->lockForUpdate();
        }
        $commodities = $commodityQuery->get();
        $commodityIds = $commodities->pluck('id')->map(static fn($id): int => (int)$id)->all();
        sort($commodityIds, SORT_NUMERIC);

        $couponQuery = \App\Model\Coupon::query()
            ->whereIn('category_id', $requestedIds)
            ->select(['id', 'status', 'trade_no']);
        if ($lock) {
            $couponQuery->lockForUpdate();
        }
        $coupons = $couponQuery->get();
        $couponIds = $coupons->pluck('id')->map(static fn($id): int => (int)$id)->all();
        sort($couponIds, SORT_NUMERIC);

        $userCategoryQuery = \App\Model\UserCategory::query()
            ->whereIn('category_id', $requestedIds)
            ->select('id');
        if ($lock) {
            $userCategoryQuery->lockForUpdate();
        }
        $userCategoryIds = $userCategoryQuery->pluck('id')->map(static fn($id): int => (int)$id)->all();
        sort($userCategoryIds, SORT_NUMERIC);

        // Config is MyISAM in legacy installs. del() holds Config's shared
        // application writer barrier around this snapshot and the transaction.
        $configReferences = \App\Model\Config::query()
            ->where('key', 'default_category')
            ->whereIn('value', array_map('strval', $requestedIds))
            ->get(['id', 'value']);
        $configReferenceIds = $configReferences->pluck('id')->map(static fn($id): int => (int)$id)->all();
        sort($configReferenceIds, SORT_NUMERIC);

        // ThirdDockManage stores its target category inside a serialized rule
        // payload instead of a foreign-key column. The plugin may be disabled
        // while its table and rules still remain, so core category deletion
        // must protect those references without booting or enabling the plugin.
        $thirdDockRuleIds = [];
        try {
            if (DB::schema()->hasTable('third_dock_rules')) {
                $thirdDockRuleQuery = DB::table('third_dock_rules')->select(['id', 'settings']);
                if ($lock) {
                    $thirdDockRuleQuery->lockForUpdate();
                }
                $categoryLookup = array_fill_keys($requestedIds, true);
                foreach ($thirdDockRuleQuery->get() as $rule) {
                    $settings = @unserialize((string)($rule->settings ?? ''), ['allowed_classes' => false]);
                    if (is_array($settings)
                        && isset($settings['category_id'])
                        && is_numeric($settings['category_id'])
                        && isset($categoryLookup[(int)$settings['category_id']])) {
                        $thirdDockRuleIds[] = (int)$rule->id;
                    }
                }
                $thirdDockRuleIds = array_values(array_unique($thirdDockRuleIds));
                sort($thirdDockRuleIds, SORT_NUMERIC);
            }
        } catch (\Throwable) {
            // Failing open here could leave a disabled plugin rule pointing at
            // a physically deleted category. Make the destructive operation
            // unavailable until the reference check can be completed.
            throw new JSONException('无法检查 ThirdDockManage 分类引用，已阻止删除');
        }

        $blockerCount = count($unselectedDescendantIds)
            + $hierarchyCycleCount
            + count($commodityIds)
            + count($couponIds)
            + count($userCategoryIds)
            + count($configReferenceIds)
            + count($thirdDockRuleIds);

        return [
            // Internal snapshot fields; deleteImpact() removes these from JSON.
            'category_ids' => $requestedIds,
            'category_edges' => $categoryEdges,
            'delete_levels' => $deleteLevels,
            'unselected_descendant_ids' => $unselectedDescendantIds,
            'commodity_ids' => $commodityIds,
            'coupon_ids' => $couponIds,
            'user_category_ids' => $userCategoryIds,
            'config_reference_ids' => $configReferenceIds,
            'third_dock_rule_ids' => $thirdDockRuleIds,

            'category_count' => count($requestedIds),
            'unselected_descendant_count' => count($unselectedDescendantIds),
            'hierarchy_cycle_count' => $hierarchyCycleCount,
            'commodity_count' => count($commodityIds),
            'coupon_count' => count($couponIds),
            'used_coupon_count' => $coupons->filter(static fn($coupon): bool => (int)$coupon->status === 1 || trim((string)$coupon->trade_no) !== '')->count(),
            'user_category_count' => count($userCategoryIds),
            'config_reference_count' => count($configReferenceIds),
            'third_dock_rule_count' => count($thirdDockRuleIds),
            'can_delete' => $blockerCount === 0,
        ];
    }

    /** @param array<string,mixed> $impact */
    private function categoryImpactFingerprint(array $impact): string
    {
        $snapshot = [];
        foreach ([
            'category_ids', 'category_edges', 'delete_levels', 'unselected_descendant_ids', 'commodity_ids',
            'coupon_ids', 'user_category_ids', 'config_reference_ids', 'third_dock_rule_ids',
        ] as $key) {
            $snapshot[$key] = $impact[$key] ?? [];
        }
        try {
            return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new JSONException('无法生成删除影响快照');
        }
    }

    private function categoryDeleteTokenKey(): string
    {
        $manage = $this->getManage();
        if (!$manage) {
            throw new JSONException('管理员会话已失效，请刷新后重试');
        }
        return hash('sha256', 'category-delete-preview-v1|' . (string)$manage->password, true);
    }

    /** @param int[] $requestedIds @param array<string,mixed> $impact */
    private function issueCategoryDeleteToken(array $requestedIds, array $impact): string
    {
        sort($requestedIds, SORT_NUMERIC);
        $now = time();
        $payload = [
            'ids' => $requestedIds,
            'snapshot' => $this->categoryImpactFingerprint($impact),
            'manage_id' => (int)($this->getManage()?->id ?? 0),
            'session' => hash('sha256', (string)($_COOKIE[ManageConst::SESSION] ?? '')),
            'iat' => $now,
            'exp' => $now + 180,
        ];
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new JSONException('无法生成删除预览凭证');
        }
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, $this->categoryDeleteTokenKey());
    }

    /** @param int[] $requestedIds @return array<string,mixed> */
    private function verifyCategoryDeleteToken(mixed $token, array $requestedIds): array
    {
        if (!is_string($token) || $token === '' || substr_count($token, '.') !== 1) {
            throw new JSONException('请先预览删除影响，再确认删除');
        }
        [$body, $signature] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $body, $this->categoryDeleteTokenKey());
        if (!preg_match('/^[a-f0-9]{64}$/D', $signature) || !hash_equals($expected, $signature)) {
            throw new JSONException('删除预览凭证无效，请重新预览');
        }

        $encoded = strtr($body, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new JSONException('删除预览凭证无效，请重新预览');
        }
        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new JSONException('删除预览凭证无效，请重新预览');
        }
        if (!is_array($payload)) {
            throw new JSONException('删除预览凭证无效，请重新预览');
        }

        $tokenIds = $this->categoryIds($payload['ids'] ?? []);
        sort($tokenIds, SORT_NUMERIC);
        sort($requestedIds, SORT_NUMERIC);
        $now = time();
        if (
            $tokenIds !== $requestedIds
            || !is_string($payload['snapshot'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $payload['snapshot'])
            || (int)($payload['manage_id'] ?? 0) !== (int)($this->getManage()?->id ?? 0)
            || !hash_equals(hash('sha256', (string)($_COOKIE[ManageConst::SESSION] ?? '')), (string)($payload['session'] ?? ''))
            || (int)($payload['iat'] ?? 0) > $now + 5
            || (int)($payload['iat'] ?? 0) < $now - 180
            || (int)($payload['exp'] ?? 0) < $now
        ) {
            throw new JSONException('删除预览已过期或范围不一致，请重新预览');
        }
        return $payload;
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\Category::class);
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));
        $data = $this->query->get($get, function (Builder $builder) use ($map) {
            if (isset($map['user_id']) && $map['user_id'] > 0) {
                $builder = $builder->where("owner", $map['user_id']);
            } else {
                $builder = $builder->where("owner", 0);
            }

            return $builder->with(['owner' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            }]);
        });

        foreach ($data['list'] as &$item) {
            $item['share_url'] = Client::getUrl() . "/cat/{$item['id']}";
        }

        return $this->json(data: $data);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function save(Request $request): array
    {
        $raw = $request->post(flags: Filter::NORMAL);
        $allowed = ['id', 'pid', 'icon', 'name', 'sort', 'hide', 'status', 'user_level_config'];
        $map = array_intersect_key($raw, array_flip($allowed));
        $id = isset($map['id']) ? (int)$map['id'] : 0;
        $current = $id > 0 ? \App\Model\Category::query()->find($id) : null;
        if ($id > 0 && !$current) {
            throw new JSONException("分类不存在");
        }

        if (($id === 0 && !isset($map['name'])) || (isset($map['name']) && trim((string)$map['name']) === '')) {
            throw new JSONException("分类名称不能为空");
        }

        foreach (['status', 'hide'] as $booleanField) {
            if (!array_key_exists($booleanField, $map)) {
                continue;
            }
            $value = (string)$map[$booleanField];
            if (!in_array($value, ['0', '1'], true)) {
                throw new JSONException("分类状态参数不正确");
            }
            $map[$booleanField] = (int)$value;
        }
        if (isset($map['sort'])) {
            $sort = filter_var($map['sort'], FILTER_VALIDATE_INT);
            if ($sort === false || $sort < 0 || $sort > 65535) {
                throw new JSONException("分类排序必须是 0 到 65535 的整数");
            }
            $map['sort'] = $sort;
        }

        $hasParent = array_key_exists('pid', $map);
        $parentId = $hasParent ? (int)$map['pid'] : null;
        if ($hasParent && $parentId > 0) {
            $owner = $current ? (int)$current->owner : 0;
            $parent = \App\Model\Category::query()->where('owner', $owner)->find($parentId);
            if (!$parent) {
                throw new JSONException("父级分类不存在或不属于同一创建者");
            }
            if ($id > 0 && $parentId === $id) {
                throw new JSONException("分类不能设为自己的子分类");
            }

            $visited = [];
            while ($parent) {
                $parentKey = (int)$parent->id;
                if (isset($visited[$parentKey])) {
                    throw new JSONException("分类层级存在循环，请重新选择父级分类");
                }
                $visited[$parentKey] = true;
                if ($id > 0 && $parentKey === $id) {
                    throw new JSONException("不能选择当前分类的子分类作为父级");
                }
                $nextId = (int)$parent->pid;
                if ($nextId <= 0) {
                    break;
                }
                $parent = \App\Model\Category::query()->where('owner', $owner)->find($nextId);
                if (!$parent) {
                    throw new JSONException("父级分类层级无效，请重新选择");
                }
            }
        }

        unset($map['pid']);
        $save = new Save(\App\Model\Category::class);
        $save->setMap($map, $allowed);
        if ($hasParent) {
            $save->addForceMap('pid', $parentId > 0 ? $parentId : null);
        }
        if ($id > 0) {
            $save->disableAddable();
        }
        $save->enableCreateTime();
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[新增/修改]商品分类");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        $requestedIds = $this->categoryIds($_POST['list'] ?? []);
        $preview = $this->verifyCategoryDeleteToken($_POST['preview_token'] ?? null, $requestedIds);
        // Config is MyISAM on legacy installations. Hold its application-level
        // writer lock across the InnoDB transaction so default_category cannot
        // acquire a new reference between the locked snapshot and commit.
        $impact = \App\Model\Config::withExclusiveLock(function () use ($requestedIds, $preview): array {
            return DB::transaction(function () use ($requestedIds, $preview): array {
                $impact = $this->safeCategoryDeleteImpact($requestedIds, true);
                if (!hash_equals((string)$preview['snapshot'], $this->categoryImpactFingerprint($impact))) {
                    throw new JSONException('分类或业务引用在预览后发生变化，未执行删除，请重新预览');
                }
                if (!$impact['can_delete']) {
                    throw new JSONException('分类存在子分类或业务引用，已阻止删除');
                }

                $deletedCategories = 0;
                foreach ($impact['delete_levels'] as $ids) {
                    $deletedCategories += \App\Model\Category::query()->whereIn('id', $ids)->delete();
                }
                if ($deletedCategories !== $impact['category_count']) {
                    throw new JSONException('分类层级发生变化，未执行删除，请重新预览');
                }
                return $impact;
            });
        });

        ManageLog::log(
            $this->getManage(),
            "[删除]空商品分类，分类：{$impact['category_count']}"
        );
        return $this->json(200, '（＾∀＾）移除成功', [
            'category_count' => $impact['category_count'],
            'commodity_count' => 0,
        ]);
    }

    /**
     * Read-only impact preview used by the mobile irreversible-action dialog.
     * @return array
     * @throws JSONException
     */
    public function deleteImpact(): array
    {
        $requestedIds = $this->categoryIds($_POST['list'] ?? []);
        $impact = $this->safeCategoryDeleteImpact($requestedIds);
        $token = $impact['can_delete'] ? $this->issueCategoryDeleteToken($requestedIds, $impact) : null;
        unset(
            $impact['category_ids'],
            $impact['category_edges'],
            $impact['delete_levels'],
            $impact['unselected_descendant_ids'],
            $impact['commodity_ids'],
            $impact['coupon_ids'],
            $impact['user_category_ids'],
            $impact['config_reference_ids'],
            $impact['third_dock_rule_ids']
        );
        $impact['preview_token'] = $token;
        $impact['preview_expires_in'] = $token === null ? 0 : 180;
        return $this->json(data: $impact);
    }

    /**
     * @return array
     */
    public function status(): array
    {
        $list = $this->categoryIds($_POST['list'] ?? []);
        $rawStatus = (string)($_POST['status'] ?? '');
        if ($list === [] || !in_array($rawStatus, ['0', '1'], true)) {
            throw new JSONException("分类状态请求参数不正确");
        }
        $status = (int)$rawStatus;

        $categories = \App\Model\Category::query()->get(['id', 'pid', 'owner']);
        $byId = [];
        $children = [];
        foreach ($categories as $category) {
            $id = (int)$category->id;
            $byId[$id] = [
                'pid' => (int)$category->pid,
                'owner' => (int)$category->owner,
            ];
            $children[(int)$category->pid][] = $id;
        }

        $targets = [];
        foreach ($list as $rootId) {
            if (!isset($byId[$rootId])) {
                continue;
            }
            $owner = $byId[$rootId]['owner'];
            if ($status === 0) {
                $queue = [$rootId];
                while ($queue !== []) {
                    $id = array_shift($queue);
                    if (isset($targets[$id]) || !isset($byId[$id]) || $byId[$id]['owner'] !== $owner) {
                        continue;
                    }
                    $targets[$id] = true;
                    foreach ($children[$id] ?? [] as $childId) {
                        $queue[] = $childId;
                    }
                }
                continue;
            }

            $id = $rootId;
            $visited = [];
            while ($id > 0 && isset($byId[$id])) {
                if (isset($visited[$id]) || $byId[$id]['owner'] !== $owner) {
                    throw new JSONException("分类层级无效，无法启用");
                }
                $visited[$id] = true;
                $targets[$id] = true;
                $id = $byId[$id]['pid'];
            }
        }
        if ($targets === []) {
            throw new JSONException("没有可更新的分类");
        }

        \App\Model\Category::query()->whereIn('id', array_keys($targets))->update(['status' => $status]);

        ManageLog::log($this->getManage(), "[批量更新]商品分类状态，STATUS：{$status}，分类：" . count($targets));
        return $this->json(200, '分类状态已经更新');
    }
}
