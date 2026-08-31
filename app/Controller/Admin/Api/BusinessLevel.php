<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class BusinessLevel extends Manage
{
    private const SAVE_FIELDS = [
        'name',
        'icon',
        'cost',
        'supplier',
        'substation',
        'top_domain',
        'price',
    ];

    #[Inject]
    private Query $query;

    /**
     * @param array<mixed> $raw
     * @return array{0:int, 1:array<string, int|string>}
     * @throws JSONException
     */
    private function validatedSaveMap(array $raw): array
    {
        $allowed = array_merge(['id'], self::SAVE_FIELDS);
        foreach (array_keys($raw) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new JSONException('商户等级保存请求包含未授权字段');
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
                throw new JSONException('商户等级 ID 格式不正确');
            }
            $id = (int)$candidate;
        }

        if ($id === 0) {
            foreach (['name' => '等级名称', 'icon' => '等级图标', 'cost' => '供货商手续费', 'price' => '购买价格'] as $field => $label) {
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
                strlen($icon) > 255
                || preg_match('/[\x00-\x20\x7F\\\\\'"<>]/', $icon)
                || (!$local && !$remote)
            ) {
                throw new JSONException('等级图标必须是有效的站内路径或 HTTP(S) 图片地址');
            }
            $map['icon'] = $icon;
        }

        if (array_key_exists('cost', $raw)) {
            if (!is_scalar($raw['cost'])) {
                throw new JSONException('供货商手续费格式不正确');
            }
            $cost = trim((string)$raw['cost']);
            if (!preg_match('/^(?:0(?:\.\d{1,2})?|1(?:\.0{1,2})?)$/D', $cost)) {
                throw new JSONException('供货商手续费必须是 0–1 之间、最多两位小数的数值');
            }
            $map['cost'] = $cost;
        }

        if (array_key_exists('price', $raw)) {
            if (!is_scalar($raw['price'])) {
                throw new JSONException('购买价格格式不正确');
            }
            $price = trim((string)$raw['price']);
            if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $price)) {
                throw new JSONException('购买价格必须是 0–99999999.99 之间、最多两位小数的数值');
            }
            $map['price'] = $price;
        }

        foreach (['supplier' => '供货权限', 'substation' => '分站权限', 'top_domain' => '独立域名权限'] as $field => $label) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $value = $raw[$field];
            if (!is_scalar($value) || !in_array((string)$value, ['0', '1'], true)) {
                throw new JSONException($label . '只能是开启或关闭');
            }
            $map[$field] = (int)$value;
        }

        if ($map === []) {
            throw new JSONException('没有可保存的商户等级字段');
        }

        return [$id, $map];
    }

    /**
     * @param mixed $value
     * @return int[]
     * @throws JSONException
     */
    private function levelIds(mixed $value): array
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
                throw new JSONException('商户等级 ID 必须是正整数');
            }
            if ($id <= 0) {
                throw new JSONException('商户等级 ID 必须是正整数');
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw new JSONException('请至少选择一个商户等级');
        }
        if (count($ids) > 100) {
            throw new JSONException('单次最多删除 100 个商户等级');
        }
        return $ids;
    }

    /**
     * @return array
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\BusinessLevel::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy('price', 'asc');
        $data = $this->query->get($get);
        return $this->json(data: $data);
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
                $level = \App\Model\BusinessLevel::query()
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();
                if (!$level) {
                    throw new JSONException('商户等级不存在，请刷新后重试');
                }
            } else {
                $level = new \App\Model\BusinessLevel();
            }

            foreach ($map as $field => $value) {
                $level->$field = $value;
            }
            if (!$level->save()) {
                throw new JSONException('保存失败，请稍后重试');
            }
        });

        ManageLog::log($this->getManage(), "[新增/修改]商户等级");
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
        $requestedIds = $this->levelIds($_POST['list'] ?? []);
        $count = DB::transaction(function () use ($requestedIds): int {
            $levels = \App\Model\BusinessLevel::query()
                ->whereIn('id', $requestedIds)
                ->orderBy('id')
                ->select(['id'])
                ->lockForUpdate()
                ->get();
            if ($levels->count() !== count($requestedIds)) {
                throw new JSONException('部分商户等级不存在，请刷新后重试');
            }

            // Lock matching users as well as the selected levels. This keeps a
            // concurrent assignment from creating an orphan during deletion.
            $referencedUsers = \App\Model\User::query()
                ->whereIn('business_level', $requestedIds)
                ->orderBy('id')
                ->select(['id', 'business_level'])
                ->lockForUpdate()
                ->get();
            if ($referencedUsers->isNotEmpty()) {
                throw new JSONException(
                    '所选商户等级仍被 ' . $referencedUsers->count() . ' 名会员使用，禁止删除；请先调整会员等级或关闭对应店铺。'
                );
            }

            $deleted = \App\Model\BusinessLevel::query()->whereIn('id', $requestedIds)->delete();
            if ($deleted !== count($requestedIds)) {
                throw new JSONException('商户等级删除数量异常，操作已回滚，请刷新后重试');
            }
            return $deleted;
        });

        ManageLog::log($this->getManage(), "[删除]商户等级，共计：{$count}");
        return $this->json(200, '（＾∀＾）移除成功', ['count' => $count]);
    }
}
