<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\UserGroup;
use App\Service\Query;
use App\Util\Str;
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
    #[Inject]
    private Query $query;

    /**
     * @return array
     * @throws NotFoundException
     * @throws \ReflectionException
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
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function save(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        $save = new Save(\App\Model\CommodityGroup::class);
        $save->setMap($map);
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[新增/修改]商品分组");
        return $this->json(200, '（＾∀＾）保存成功');
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


        $result = [];

        foreach ($list as $category) {
            $id = Str::generateRandStr(16);
            $hasCheckedChildren = false;
            $children = [];

            if (!empty($category['children'])) {
                foreach ($category['children'] as $child) {
                    $isChecked = in_array($child['id'], $commodityList);
                    if ($isChecked) {
                        $hasCheckedChildren = true;
                    }

                    $children[] = [
                        'id' => $child['id'],
                        'name' => $child['name'],
                        'pid' => $id,
                        'checked' => $isChecked
                    ];
                }
            }

            // 一级分类
            $result[] = [
                'id' => $id,
                'name' => $category['name'],
                'pid' => 0,
                'checked' => $hasCheckedChildren
            ];

            // 合并子项
            $result = array_merge($result, $children);
        }


        return $this->json(data: ["list" => $result]);
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        $list = (array)$_POST['list'];
        $del = new Delete(\App\Model\CommodityGroup::class, $list);
        $count = $this->query->delete($del);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }
        ManageLog::log($this->getManage(), "[删除]商品分组");
        return $this->json(200, '（＾∀＾）移除成功');
    }
}