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
            throw new JSONException("????????????????????????????????????????????????");
        }

        ManageLog::log($this->getManage(), "[??????/??????]????????????");
        return $this->json(200, '???????????????????????????');
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
            throw new JSONException("????????????????????????");
        }

        foreach ($list as $id) {
            \App\Model\Commodity::query()->where("category_id", $id)->delete();
        }

        ManageLog::log($this->getManage(), "[??????]????????????");
        return $this->json(200, '???????????????????????????');
    }

    /**
     * @return array
     */
    public function status(): array
    {
        $list = (array)$_POST['list'];
        $status = (int)$_POST['status'];
        \App\Model\Category::query()->whereIn('id', $list)->update(['status' => $status]);

        ManageLog::log($this->getManage(), "[????????????]?????????????????????STATUS???" . $status);
        return $this->json(200, '????????????????????????');
    }
}