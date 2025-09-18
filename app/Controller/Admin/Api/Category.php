<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Client;
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
        $map = $request->post(flags: Filter::NORMAL);
        $save = new Save(\App\Model\Category::class);
        $save->setMap($map);
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
        $list = (array)$_POST['list'];
        $del = new Delete(\App\Model\Category::class, $list);
        $count = $this->query->delete($del);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        //删除所有商品
        foreach ($list as $id) {
            \App\Model\Commodity::query()->where("category_id", $id)->delete();
        }

        ManageLog::log($this->getManage(), "[删除]商品分类");
        return $this->json(200, '（＾∀＾）移除成功');
    }

    /**
     * @return array
     */
    public function status(): array
    {
        $list = (array)$_POST['list'];
        $status = (int)$_POST['status'];
        \App\Model\Category::query()->whereIn('id', $list)->update(['status' => $status]);

        ManageLog::log($this->getManage(), "[批量更新]商品分类状态，STATUS：" . $status);
        return $this->json(200, '分类状态已经更新');
    }
}