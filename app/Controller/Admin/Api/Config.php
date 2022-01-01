<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Service\Query;
use App\Util\Date;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use App\Model\Config as CFG;
use Kernel\Exception\JSONException;
use Mrgoon\AliSms\AliSms;
use PHPMailer\PHPMailer\PHPMailer;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Config extends Manage
{

    #[Inject]
    private Query $query;

    #[Inject]
    private AliSms $sms;

    #[Inject]
    private PHPMailer $mailer;

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function setting(): array
    {
        $keys = ["closed_message", "closed", "user_theme", "background_url", "shop_name", "title", "description", "keywords", "registered_state", "registered_type", "registered_verification", "registered_phone_verification", "registered_email_verification", "login_verification", "forget_type", "notice", "trade_verification"]; //全部字段
        $inits = ["closed", "registered_state", "registered_type", "registered_verification", "registered_phone_verification", "registered_email_verification", "login_verification", "forget_type", "trade_verification"]; //需要初始化的字段

        $file = $_POST['logo'];
        if ($file != '/favicon.ico') {
            @copy(BASE_PATH . $file, BASE_PATH . '/favicon.ico');
            @unlink(BASE_PATH . $file);
        }
        try {
            foreach ($keys as $index => $key) {
                if (in_array($key, $inits)) {
                    if (!isset($_POST[$key])) {
                        $_POST[$key] = 0;
                    }
                }
                CFG::put($key, $_POST[$key]);
            }
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        return $this->json(200, '保存成功');
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function other(): array
    {
        $keys = ["recharge_min", "recharge_max", "cname", "default_category", "recharge_welfare_config", "recharge_welfare", "promote_rebate_v1", "promote_rebate_v2", "promote_rebate_v3", "substation_display", "domain", "service_url", "service_qq", "cash_type_alipay", "cash_type_wechat", "cash_cost", "cash_min"]; //全部字段
        $inits = ["recharge_min", "recharge_max", "recharge_welfare", "substation_display", "cash_type_alipay", "cash_type_wechat", "cash_cost", "cash_min", "default_category"]; //需要初始化的字段

        if (!empty($_POST['recharge_welfare_config'])) {
            $explode = explode(PHP_EOL, trim($_POST['recharge_welfare_config'], PHP_EOL));
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
                    if (!isset($_POST[$key])) {
                        $_POST[$key] = 0;
                    }
                }
                CFG::put($key, $_POST[$key]);
            }
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        return $this->json(200, '保存成功');
    }


    /**
     * @throws \Kernel\Exception\JSONException
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
        CFG::put("substation_display_list", json_encode($list));
        return $this->json(200, "成功", $list);
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function sms(): array
    {
        try {
            CFG::put("sms_config", json_encode($_POST));
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }
        return $this->json(200, '保存成功');
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function email(): array
    {
        try {
            CFG::put("email_config", json_encode($_POST));
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }
        return $this->json(200, '保存成功');
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function smsTest(): array
    {
        $smsConfig = json_decode(\App\Model\Config::get("sms_config"), true);

        $config = [
            'access_key' => $smsConfig['accessKeyId'],
            'access_secret' => $smsConfig['accessKeySecret'],
            'sign_name' => $smsConfig['signName'],
        ];

        $response = $this->sms->sendSms($_POST['phone'], $smsConfig['templateCode'], ['code' => mt_rand(100000, 666666)], $config);

        if ($response->Message != "OK") {
            throw new JSONException($response->Message);
        }

        return $this->json(200, "短信发送成功");
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function emailTest(): array
    {
        try {
            $config = json_decode(\App\Model\Config::get("email_config"), true);
            $shopName = CFG::get("shop_name");
            $mail = $this->mailer;
            $mail->CharSet = 'UTF-8';
            $mail->IsSMTP();
            $mail->SMTPDebug = 0;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = 'ssl';
            $mail->Host = $config['smtp'];
            $mail->Port = $config['port'];
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SetFrom($config['username'], $shopName); // 邮箱，昵称
            $mail->AddAddress($_POST['email']);
            $mail->Subject = $shopName . "-手动测试邮件";
            $mail->MsgHTML('测试邮件，发送时间：' . Date::current());
            $result = $mail->Send();
        } catch (\Exception $e) {
            throw new JSONException("发送失败");
        }

        if (!$result) {
            throw new JSONException("发送失败");
        }

        return $this->json(200, "成功!");
    }


    /**
     * @return array
     */
    public function getBusiness(): array
    {
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Business::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWith(['user' => function (Relation $relation) {
            $relation->with(['businessLevel'])->select(["id", "business_level", "username", "avatar"]);
        }]);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }
}