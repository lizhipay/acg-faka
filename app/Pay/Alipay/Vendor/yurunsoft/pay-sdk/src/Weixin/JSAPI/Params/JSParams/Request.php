<?php

namespace Yurun\PaySDK\Weixin\JSAPI\Params\JSParams;

use Yurun\PaySDK\Lib\Util;
use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-公众号支付-传递给JSSDK所需参数类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 下单接口（\Yurun\PaySDK\Weixin\JSAPI\Params\Pay\Request）返回的prepay_id值
     *
     * @var string
     */
    public $prepay_id;

    /**
     * 当调用SDK的execute时触发，返回true时不执行SDK中默认的执行逻辑.
     *
     * @param \Yurun\PaySDK\Base $sdk
     * @param string             $format 数据格式，json、xml等
     *
     * @return bool
     */
    public function __onExecute($sdk, $format)
    {
        $data = [
            'appId'			    => $sdk->publicParams->appID,
            'timeStamp'		 => (string) (Util::getBeijingTime()),
            'nonceStr'		  => md5(mt_rand()),
            'package'		   => 'prepay_id=' . $this->prepay_id,
            'signType'		  => $sdk->publicParams->sign_type,
        ];
        $data['paySign'] = $sdk->sign($data);
        $sdk->result = $data;

        return true;
    }
}
