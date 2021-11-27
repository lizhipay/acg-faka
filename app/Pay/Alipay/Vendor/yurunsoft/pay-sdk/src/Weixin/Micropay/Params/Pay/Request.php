<?php

namespace Yurun\PaySDK\Weixin\Micropay\Params\Pay;

use Yurun\PaySDK\Weixin\Micropay\Params\SceneInfo;
use Yurun\PaySDK\Weixin\Params\PayRequestBase;

/**
 * 微信支付-刷卡支付-下单请求类.
 */
class Request extends PayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/micropay';

    /**
     * 扫码支付授权码，设备读取用户微信中的条码或者二维码信息.
     *
     * @var string
     */
    public $auth_code;

    /**
     * 场景信息.
     *
     * @var \Yurun\PaySDK\Weixin\Micropay\Params\SceneInfo
     */
    public $scene_info;

    public function __construct()
    {
        $this->scene_info = new SceneInfo();
        parent::__construct();
    }
}
