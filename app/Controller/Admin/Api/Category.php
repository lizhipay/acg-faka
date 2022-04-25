<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Client;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

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
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Category::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setOrder('sort', 'asc');
        $queryTemplateEntity->setWith(['owner' => function (Relation $relation) {
            $relation->select(["id", "username", "avatar"]);
        }]);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();

        foreach ($data['data'] as $key => $val) {
            $data['data'][$key]['share_url'] = Client::getUrl() . "?code=" . urlencode(base64_encode("a={$val['id']}"));
        }

        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $_POST;
        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Category::class);
        $createObjectEntity->setMap($map);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[新增/修改]商品分类");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $list = (array)$_POST['list'];
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\Category::class);
        $deleteBatchEntity->setList($list);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

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