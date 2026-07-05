<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Interceptor\Super;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Str;
use App\Util\Validation;
use Illuminate\Database\Eloquent\Builder;
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
        $get = new Get(\App\Model\Manage::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("type", "!=", 0);
        });
        return $this->json(data: $data);
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

        $save = new Save(\App\Model\Manage::class);
        $save->setMap($map);
        $save->enableCreateTime();
        $save = $this->query->save($save);
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
        $delete = new Delete(\App\Model\Manage::class, $_POST['list']);
        $count = $this->query->delete($delete);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "删除了管理员(ID:" . implode(",", (array)($_POST['list'] ?? [])) . ")");
        return $this->json(200, '（＾∀＾）移除成功');
    }

    /**
     * @return array
     * @throws JSONException
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


    /**
     * 谷歌验证器：当前账号是否已绑定
     * @return array
     */
    public function googleStatus(): array
    {
        return $this->json(data: ["bound" => !empty($this->getManage()->google_secret)]);
    }

    /**
     * 谷歌验证器：生成待绑定密钥（不落库，前端据 uri 生成二维码/手动录入）
     * @return array
     */
    public function googleSecret(): array
    {
        $manage = $this->getManage();
        $secret = \App\Util\Totp::generateSecret();
        $issuer = (string)(\App\Model\Config::get("shop_name") ?: "ACGFAKA");
        $uri = \App\Util\Totp::keyUri($secret, (string)$manage->email, $issuer);
        return $this->json(data: ["secret" => $secret, "uri" => $uri]);
    }

    /**
     * 谷歌验证器：绑定（校验一次动态码后保存密钥）
     * @return array
     * @throws JSONException
     */
    public function googleBind(): array
    {
        $manage = $this->getManage();
        if (!empty($manage->google_secret)) {
            //已绑定不允许直接覆盖，必须先解绑（防止绕过 UI 直接调接口换绑）
            throw new JSONException("已绑定，请先解绑后再重新绑定");
        }
        $secret = strtoupper(trim((string)$this->request->post("secret")));
        $code = (string)$this->request->post("code");
        if (!preg_match('/^[A-Z2-7]{16,64}$/', $secret)) {
            throw new JSONException("密钥格式错误");
        }
        if (!\App\Util\Totp::verify($secret, $code)) {
            throw new JSONException("验证码错误，请确认手机时间已同步后重试");
        }
        $manage->google_secret = $secret;
        $manage->save();
        ManageLog::log($manage, "绑定了谷歌验证器");
        return $this->json(200, "绑定成功");
    }

    /**
     * 谷歌验证器：解绑（校验当前动态码后清除）
     * @return array
     * @throws JSONException
     */
    public function googleUnbind(): array
    {
        $manage = $this->getManage();
        if (empty($manage->google_secret)) {
            throw new JSONException("尚未绑定谷歌验证器");
        }
        if (!\App\Util\Totp::verify((string)$manage->google_secret, (string)$this->request->post("code"))) {
            throw new JSONException("验证码错误");
        }
        $manage->google_secret = null;
        $manage->save();
        ManageLog::log($manage, "解绑了谷歌验证器");
        return $this->json(200, "已解绑");
    }
}