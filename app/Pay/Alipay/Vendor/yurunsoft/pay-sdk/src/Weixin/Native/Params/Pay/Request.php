<?php

namespace Yurun\PaySDK\Weixin\Native\Params\Pay;

use Yurun\PaySDK\Weixin\Native\Params\SceneInfo;
use Yurun\PaySDK\Weixin\Params\PayRequestBase;

/**
 * 微信支付-扫码支付-下单请求类.
 */
class Request extends PayRequestBase
{
    /**
     * 场景信息.
     *
     * @var \Yurun\PaySDK\Weixin\Native\Params\SceneInfo
     */
    public $scene_info;

    /**
     * 微信用户在商户对应appid下的唯一标识.
     *
     * @var string
     */
    public $openid;

    /**
     * 微信用户在子商户appid下的唯一标识。
     *
     * @var string
     */
    public $sub_openid;

    public function __construct()
    {
        $this->trade_type = 'NATIVE';
        $this->scene_info = new SceneInfo();
        parent::__construct();
    }
}
