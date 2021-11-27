<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR;

use Yurun\PaySDK\AlipayRequestBase;
use Yurun\PaySDK\Lib\ObjectToArray;

/**
 * 支付宝境外到店支付-创建商户二维码请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.commerce.qrcode.create';

    /**
     * 调用接口的北京时间，格式为yyyy-MM-dd HH:mm:ss.
     *
     * @var string
     */
    public $timestamp;

    /**
     * 支付宝将在 HTTP Post 方法中异步通知结果。
     *
     * @var string
     */
    public $notify_url;

    /**
     * 业务类型.
     *
     * @var string
     */
    public $biz_type = 'OVERSEASHOPQRCODE';

    /**
     * 业务数据.
     *
     * @var \Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR\BizData
     */
    public $biz_data;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
        $this->biz_data = new \Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR\BizData();
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['timestamp']))
        {
            $obj['timestamp'] = date('Y-m-d H:i:s');
        }
        $obj['biz_data'] = json_encode(ObjectToArray::parse($obj['biz_data']));

        return $obj;
    }
}
