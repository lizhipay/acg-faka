<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\Business;
use App\Model\Config as CFG;
use App\Model\ManageLog;
use App\Service\Email;
use App\Service\Query;
use App\Service\Sms;
use App\Util\Client;
use App\Util\Date;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;
use PHPMailer\PHPMailer\PHPMailer;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Config extends Manage
{

    #[Inject]
    private Query $query;

    #[Inject]
    private Sms $sms;

    #[Inject]
    private Email $email;

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws \Throwable
     */
    public function setting(Request $request): array
    {
        $post = $request->post(flags: Filter::NORMAL);
        $keys = ["closed_message", "background_mobile_url", "closed", "username_len", "user_theme", "user_mobile_theme", "background_url", "shop_name", "title", "description", "keywords", "registered_state", "registered_type", "registered_verification", "registered_phone_verification", "registered_email_verification", "login_verification", "forget_type", "notice", "trade_verification", "session_expire"]; //全部字段
        $inits = ["closed", "registered_state", "registered_type", "registered_verification", "registered_phone_verification", "registered_email_verification", "login_verification", "forget_type", "trade_verification", "session_expire"]; //需要初始化的字段

        $file = $post['logo'];
        if ($file != '/favicon.ico') {
            @copy(BASE_PATH . $file, BASE_PATH . '/favicon.ico');
            @unlink(BASE_PATH . $file);
        }
        try {
            if (isset($post['ip_get_mode'])) {
                Client::setClientMode((int)$post['ip_get_mode']);
            }

            foreach ($keys as $index => $key) {
                if (in_array($key, $inits)) {
                    if (!isset($post[$key])) {
                        $post[$key] = 0;
                    }
                }
                CFG::put($key, $post[$key]);
            }
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        _plugin_start($post['user_theme'], true);
        ManageLog::log($this->getManage(), "修改了网站设置");
        return $this->json(200, '保存成功');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function other(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $keys = ["recharge_min", "commodity_recommend", "commodity_name", "recharge_max", "cname", "default_category", "callback_domain", "recharge_welfare_config", "recharge_welfare", "substation_display", "domain", "service_url", "service_qq", "cash_type_alipay", "cash_type_wechat", "cash_type_balance", "cash_cost", "cash_min"]; //全部字段
        $inits = ["recharge_min", "commodity_recommend", "recharge_max", "recharge_welfare", "substation_display", "cash_type_alipay", "cash_type_wechat", "cash_type_balance", "cash_cost", "cash_min", "default_category"]; //需要初始化的字段

        if (!empty($map['recharge_welfare_config'])) {
            $explode = explode(PHP_EOL, trim($map['recharge_welfare_config'], PHP_EOL));
            foreach ($explode as $item) {
                $def = explode("-", $item);
                if (count($def) != 2) {
                    throw new JSONException("充值赠送配置规则表达式错误");
                }
            }
        }

        try {
            foreach ($keys as $index => $key) {
                if (in_array($key, $inits)) {
                    if (!isset($map[$key])) {
                        $map[$key] = 0;
                    }
                }
                CFG::put($key, $map[$key]);
            }
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了其他设置");
        return $this->json(200, '保存成功');
    }


    /**
     * @return array
     * @throws RuntimeException
     */
    public function setSubstationDisplayList(): array
    {
        $userId = (int)$_POST['id'];
        $type = (int)$_POST['type'];
        $list = json_decode(CFG::get("substation_display_list"), true);
        if ($type == 0) {
            //添加过滤
            if (!in_array($userId, $list)) {
                $list[] = $userId;
            }
        } else {
            //解除过滤
            if (($key = array_search($userId, $list)) !== false) {
                unset($list[$key]);
                $list = array_values($list);
            }
        }

        ManageLog::log($this->getManage(), "修改了子站显示列表");
        CFG::put("substation_display_list", json_encode($list));
        return $this->json(200, "成功", $list);
    }

    /**
     * @throws JSONException
     */
    public function sms(): array
    {
        try {
            CFG::put("sms_config", json_encode($_POST));
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了短信配置");
        return $this->json(200, '保存成功');
    }

    /**
     * @throws JSONException
     */
    public function email(): array
    {
        try {
            CFG::put("email_config", json_encode($_POST));
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了邮件配置");
        return $this->json(200, '保存成功');
    }


    public function smsTest(): array
    {
        $this->sms->sendCaptcha($_POST['phone'], Sms::CAPTCHA_REGISTER);

        ManageLog::log($this->getManage(), "测试了短信发送");
        return $this->json(200, "短信发送成功");
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function emailTest(): array
    {
        $shopName = CFG::get("shop_name");
        $result = $this->email->send($_POST['email'], $shopName . "-手动测试邮件", '测试邮件，发送时间：' . Date::current());
        if (!$result) {
            throw new JSONException("发送失败");
        }
        ManageLog::log($this->getManage(), "测试了邮件发送");
        return $this->json(200, "成功!");
    }

    /**
     * @return array
     */
    public function getBusiness(): array
    {
        $get = new Get(Business::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with(['user' => function (Relation $relation) {
                $relation->with(['businessLevel'])->select(["id", "business_level", "username", "avatar"]);
            }]);
        });
        return $this->json(data: $data);
    }
}