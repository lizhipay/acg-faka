<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Interceptor\Super;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Str;
use App\Util\Validation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Manage extends \App\Controller\Base\API\Manage
{

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    #[Interceptor(Super::class, Interceptor::TYPE_API)]
    public function data(): array
    {
        $map = $_POST;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Manage::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWhereRaw("type!=0");
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }


    /**
     * @return array
     * @throws JSONException
     */
    #[Interceptor(Super::class, Interceptor::TYPE_API)]
    public function save(): array
    {
        $map = $_POST;

        if (!$map['email'] || !Validation::email($map['email'])) {
            throw new JSONException("邮箱格式不正确");
        }

        $salt = Str::generateRandStr();

        if ((int)$map['id'] == 0) {
            if (!$map['password']) {
                throw new JSONException("请设置密码");
            }
        } else {
            $user = \App\Model\Manage::query()->find($map['id']);
            $salt = $user->salt;
        }

        if ($map['password']) {
            if (!Validation::password($map['password'])) {
                throw new JSONException("密码太过简单");
            }
            $map['salt'] = $salt;
            $map['password'] = Str::generatePassword($map['password'], $map['salt']);
        }

        if (!in_array($map['type'], [1, 2, 3])) {
            throw new JSONException("账号类型有问题");
        }

        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Manage::class);
        $createObjectEntity->setMap($map);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);

        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[新增/修改]管理员({$map['email']})");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    #[Interceptor(Super::class, Interceptor::TYPE_API)]
    public function del(): array
    {
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\Manage::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "删除了管理员");
        return $this->json(200, '（＾∀＾）移除成功');
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function set(): array
    {
        $map = $_POST;
        $manage = \App\Model\Manage::query()->find($this->getManage()->id);

        $manage->avatar = $map['avatar'];
        $manage->nickname = $map['nickname'];

        if ($map['password']) {
            if (!Validation::password($map['password'])) {
                throw new JSONException("密码太过于简单");
            }

            if ($map['password'] != $map['re_password']) {
                throw new JSONException("两次密码输入不一致");
            }

            if (Str::generatePassword((string)$map['old_password'], $manage->salt) != $manage->password) {
                throw new JSONException("原密码输入不正确");
            }

            $manage->password = Str::generatePassword($map['password'], $manage->salt);
        }

        $manage->save();

        ManageLog::log($this->getManage(), "修改了密码");
        return $this->json(200, "修改成功");
    }
}