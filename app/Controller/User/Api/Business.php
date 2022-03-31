<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Bill;
use App\Model\BusinessLevel;
use App\Model\Config;
use App\Util\Date;
use App\Util\Validation;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Business extends User
{

    /**
     * @return array
     * @throws JSONException
     */
    public function purchase(): array
    {
        $levelId = (int)$_POST['levelId'];
        if ($levelId == 0) {
            throw new JSONException("请选择要购买的等级");
        }

        $level = BusinessLevel::query()->find($levelId);

        if (!$level) {
            throw new JSONException("该等级不存在");
        }

        $user = $this->getUser();
        $businessLevel = $user->businessLevel;
        if ($businessLevel) {
            //判断当前等级是否
            if ($businessLevel->id == $level->id || $businessLevel->price >= $level->price) {
                throw new JSONException("不能购买已有等级或比自己更低的等级");
            }
        }

        //开始扣费
        DB::transaction(function () use ($level, $user) {
            //扣费
            Bill::create($user, $level->price, Bill::TYPE_SUB, "购买商户等级", 0);
            $user->business_level = $level->id;
            $user->save();
            //新建店铺
            if (!\App\Model\Business::query()->where("user_id", $user->id)->first()) {
                $business = new \App\Model\Business();
                $business->user_id = $user->id;
                $business->create_time = Date::current();
                $business->master_display = 1;
                $business->save();
            }
        });

        return $this->json(200, "开通成功");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function saveConfig(): array
    {
        $level = $this->businessValidation();

        if (empty($_POST['shop_name'])) {
            throw new JSONException("店铺名称不能为空");
        }

        if (empty($_POST['title'])) {
            throw new JSONException("网站标题不能为空");
        }

        if (empty($_POST['service_qq'])) {
            throw new JSONException("客服QQ不能为空");
        }

        $business = \App\Model\Business::query()->where("user_id", $this->getUser()->id)->first();

        if (!$business) {
            throw new JSONException("无权限");
        }

        $cname = Config::get("cname");
        $configDomain = (array)explode(",", Config::get("domain"));

        if (!$business->subdomain && $_POST['subdomain']) {
            if (!in_array($_POST['suffix'], $configDomain)) {
                throw new JSONException("不支持该域名后缀");
            }
            $business->subdomain = trim(trim((string)$_POST['subdomain']), ".") . "." . $_POST['suffix'];

            if ($business->subdomain == $cname) {
                throw new JSONException("禁止使用CNAME域名");
            }
        }

        if (!$business->topdomain && $_POST['topdomain']) {
            $_POST['topdomain'] = trim($_POST['topdomain']);

            if ($level->top_domain != 1) {
                throw new JSONException("您当前不支持绑定顶级域名");
            }

            if (!Validation::domain($_POST['topdomain'])) {
                throw new JSONException("您绑定的域名格式不正确");
            }

            if (\App\Model\Business::query()->where("topdomain", $_POST['topdomain'])->first()) {
                throw new JSONException($_POST['topdomain'] . " 已被别人绑定过啦");
            }

            if ($_POST['topdomain'] == $cname) {
                throw new JSONException("禁止使用CNAME域名");
            }

            if (in_array($_POST['topdomain'], $configDomain)) {
                throw new JSONException("禁止使用主站域名");
            }

            $business->topdomain = $_POST['topdomain'];
        }

        $business->shop_name = $_POST['shop_name'];
        $business->title = $_POST['title'];
        $business->notice = $_POST['notice'];
        $business->service_qq = $_POST['service_qq'];
        $business->service_url = $_POST['service_url'];
        $business->master_display = (int)$_POST['master_display'];

        $business->save();

        return $this->json(200, "保存成功");
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function unbind(): array
    {
        $this->businessValidation();
        $type = (int)$_POST['type'];
        $business = \App\Model\Business::query()->where("user_id", $this->getUser()->id)->first(); //店铺信息
        if ($type == 0) {
            $business->subdomain = null;
        } else {
            $business->topdomain = null;
        }
        $business->save();
        return $this->json(200, "解绑成功");
    }
}