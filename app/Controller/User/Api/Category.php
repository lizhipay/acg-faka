<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\CreateObjectEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\UserCategory;
use App\Service\Query;
use App\Util\Client;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class, Business::class], Interceptor::TYPE_API)]
class Category extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = [];
        $map['equal-owner'] = $this->getUser()->id;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Category::class);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setOrder('sort', 'asc');
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();

        foreach ($data['data'] as $key => $val) {
            $data['data'][$key]['share_url'] = Client::getUrl() . "?code=" . urlencode(base64_encode("from=" . $this->getUser()->id . "&" . "a={$val['id']}"));
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
        $userId = $this->getUser()->id;

        if ((int)$map['id'] != 0) {
            $category = \App\Model\Category::query()->find($map['id']);
            if (!$category || $category->owner != $userId) {
                throw new JSONException("该分类不存在");
            }
        }

        if ((int)$map['sort'] < 1000) {
            throw new JSONException("排序最低设置1000");
        }

        if ((int)$map['sort'] > 60000) {
            throw new JSONException("排序最高设置60000");
        }

        $map['owner'] = $userId;
        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Category::class);
        $createObjectEntity->setMap($map);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     * @throws \Exception
     */
    public function del(): array
    {
        $id = (int)$_POST['id'];

        if ($id == 0) {
            throw new JSONException("请选择删除的分类");
        }

        $category = \App\Model\Category::query()->find($id);

        if (!$category) {
            throw new JSONException("分类不存在");
        }

        if ($category->owner != $this->getUser()->id) {
            throw new JSONException("该分类不属于你");
        }

        $category->delete();

        return $this->json(200, '（＾∀＾）移除成功');
    }
}