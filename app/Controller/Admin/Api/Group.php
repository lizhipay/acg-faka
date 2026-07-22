<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\UserGroup;
use App\Service\Query;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Group extends Manage
{
    #[Inject]
    private Query $query;

    /**
     * @param array<mixed> $raw
     * @return array{0:int, 1:array<string, string>}
     * @throws JSONException
     */
    private function validatedSaveMap(array $raw): array
    {
        $allowed = ['id', 'name', 'icon', 'recharge'];
        foreach (array_keys($raw) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new JSONException('会员等级保存请求包含未授权字段');
            }
        }

        $id = 0;
        if (array_key_exists('id', $raw) && $raw['id'] !== '' && $raw['id'] !== null) {
            $candidate = $raw['id'];
            if (
                (!is_int($candidate) && !(is_string($candidate) && preg_match('/^\d{1,10}$/D', trim($candidate))))
                || (int)$candidate < 0
                || (int)$candidate > 4294967295
            ) {
                throw new JSONException('会员等级 ID 格式不正确');
            }
            $id = (int)$candidate;
        }

        if ($id === 0) {
            foreach (['name' => '等级名称', 'icon' => '等级图标', 'recharge' => '累计元气'] as $field => $label) {
                if (!array_key_exists($field, $raw) || $raw[$field] === '' || $raw[$field] === null) {
                    throw new JSONException($label . '不能为空');
                }
            }
        }

        $map = [];
        if (array_key_exists('name', $raw)) {
            if (!is_scalar($raw['name'])) {
                throw new JSONException('等级名称格式不正确');
            }
            $name = trim((string)$raw['name']);
            if (
                $name === ''
                || mb_strlen($name, 'UTF-8') > 32
                || preg_match('//u', $name) !== 1
                || preg_match('/[\x00-\x1F\x7F<>]/u', $name)
            ) {
                throw new JSONException('等级名称必须是 1–32 个不含 HTML 的字符');
            }
            $map['name'] = $name;
        }

        if (array_key_exists('icon', $raw)) {
            if (!is_scalar($raw['icon'])) {
                throw new JSONException('等级图标地址格式不正确');
            }
            $icon = trim((string)$raw['icon']);
            $parts = $icon === '' ? false : parse_url($icon);
            $local = is_array($parts)
                && str_starts_with($icon, '/')
                && !str_starts_with($icon, '//')
                && !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])
                && preg_match('#(?:^|/)\.\.(?:/|$)#', (string)($parts['path'] ?? '')) !== 1;
            $remote = is_array($parts)
                && filter_var($icon, FILTER_VALIDATE_URL) !== false
                && isset($parts['scheme'], $parts['host'])
                && in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
                && !isset($parts['user'], $parts['pass']);
            if (
                strlen($icon) > 128
                || preg_match('/[\x00-\x1F\x7F\\\\\'"<>]/', $icon)
                || (!$local && !$remote)
            ) {
                throw new JSONException('等级图标必须是有效的站内路径或 HTTP(S) 图片地址');
            }
            $map['icon'] = $icon;
        }

        if (array_key_exists('recharge', $raw)) {
            if (!is_scalar($raw['recharge'])) {
                throw new JSONException('累计元气格式不正确');
            }
            $recharge = trim((string)$raw['recharge']);
            if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $recharge)) {
                throw new JSONException('累计元气必须是 0–999999999999.99 之间、最多两位小数的数值');
            }
            $map['recharge'] = $recharge;
        }

        if ($map === []) {
            throw new JSONException('没有可保存的会员等级字段');
        }

        return [$id, $map];
    }

    /**
     * @return array
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(UserGroup::class);
        $get->setWhere($map);
        $get->setOrderBy("recharge", "desc");
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }

    /**
     * @param int $id
     * @return array
     * @throws JSONException
     */
    public function commodityGroupData(int $id): array
    {
        $group = UserGroup::query()->find($id);

        if (!$group) {
            throw new JSONException("会员等级不存在");
        }

        $discountConfig = $group?->discount_config ?: [];

        $collection = \App\Model\CommodityGroup::query()->get(["id", "name"]);
        $data = $collection->toArray();


        foreach ($data as &$item) {
            if (isset($discountConfig[$item['id']])) {
                $item['value'] = $discountConfig[$item['id']];
            } else {
                $item['value'] = 100;
            }
        }

        return $this->json(200, data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function save(): array
    {
        [$id, $map] = $this->validatedSaveMap($_POST);

        DB::transaction(function () use ($id, $map): void {
            if ($id > 0) {
                $group = UserGroup::query()
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();
                if (!$group) {
                    throw new JSONException('会员等级不存在，请刷新后重试');
                }
            } else {
                $group = new UserGroup();
            }

            if (array_key_exists('recharge', $map)) {
                $duplicate = UserGroup::query()
                    ->where('recharge', $map['recharge'])
                    ->when($id > 0, static fn($query) => $query->where('id', '!=', $id))
                    ->lockForUpdate()
                    ->first(['id']);
                if ($duplicate) {
                    throw new JSONException('已有相同累计元气的会员等级');
                }
            }

            foreach ($map as $field => $value) {
                $group->$field = $value;
            }
            if (!$group->save()) {
                throw new JSONException('保存失败，请稍后重试');
            }
        });

        ManageLog::log($this->getManage(), "[新增/修改]会员等级");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function setDiscountConfig(Request $request): array
    {
        $groupId = $request->post("group_id", Filter::INTEGER);
        $id = $request->post("id", Filter::INTEGER);
        $value = $request->post("value", Filter::FLOAT);
        if (!$groupId || !$id) {
            throw new JSONException("参数不全");
        }
        if ($value < 0) {
            throw new JSONException("折扣不能小于0%");
        }

        DB::transaction(function () use ($groupId, $id, $value): void {
            // Keep the same CommodityGroup -> UserGroup lock order as group
            // deletion so a concurrent discount save cannot recreate stale JSON.
            $commodityGroup = \App\Model\CommodityGroup::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->first(['id']);
            if (!$commodityGroup) {
                throw new JSONException('商品分组不存在，请刷新后重试');
            }

            $group = UserGroup::query()
                ->where('id', $groupId)
                ->lockForUpdate()
                ->first();
            if (!$group) {
                throw new JSONException("未找到该会员等级");
            }

            $discountConfig = is_array($group->discount_config) ? $group->discount_config : [];
            $discountConfig[$id] = $value;
            $group->discount_config = $discountConfig;
            if (!$group->save()) {
                throw new JSONException('商品折扣保存失败，请重试');
            }
        });

        return $this->json();
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        $delete = new Delete(UserGroup::class, [$_POST['id']]);
        $count = $this->query->delete($delete);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }
        ManageLog::log($this->getManage(), "[删除]会员等级");
        return $this->json(200, '（＾∀＾）移除成功');
    }
}
