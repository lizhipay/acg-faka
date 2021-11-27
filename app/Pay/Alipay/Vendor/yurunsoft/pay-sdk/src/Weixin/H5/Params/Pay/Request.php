<?php

namespace Yurun\PaySDK\Weixin\H5\Params\Pay;

use Yurun\PaySDK\Weixin\H5\Params\SceneInfo;
use Yurun\PaySDK\Weixin\Params\PayRequestBase;

/**
 * 微信支付-H5支付请求类.
 */
class Request extends PayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/unifiedorder';

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

    /**
     * 场景信息.
     *
     * @var \Yurun\PaySDK\Weixin\H5\Params\SceneInfo
     */
    public $scene_info;

    public function __construct()
    {
        $this->trade_type = 'MWEB';
        $this->scene_info = new SceneInfo();
        parent::__construct();
    }
}
