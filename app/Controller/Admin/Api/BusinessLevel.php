<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class BusinessLevel extends Manage
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
        $save = new Save(\App\Model\BusinessLevel::class);
        $save->setMap($_POST);
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

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
        $delete = new Delete(\App\Model\BusinessLevel::class, $_POST['list']);
        $count = $this->query->delete($delete);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "[删除]商户等级");
        return $this->json(200, '（＾∀＾）移除成功');
    }
}