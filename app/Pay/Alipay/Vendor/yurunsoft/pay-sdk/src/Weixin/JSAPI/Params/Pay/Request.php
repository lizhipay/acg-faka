<?php

namespace Yurun\PaySDK\Weixin\JSAPI\Params\Pay;

use Yurun\PaySDK\Weixin\JSAPI\Params\SceneInfo;
use Yurun\PaySDK\Weixin\Params\PayRequestBase;

/**
 * 微信支付-公众号支付-下单请求类.
 */
class Request extends PayRequestBase
{
    /**
     * 场景信息.
     *
     * @var \Yurun\PaySDK\Weixin\JSAPI\Params\SceneInfo
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
        $this->trade_type = 'JSAPI';
        $this->scene_info = new SceneInfo();
        parent::__construct();
    }
}
