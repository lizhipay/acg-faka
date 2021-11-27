<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\ModifyStatus;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外到店支付-更新商户二维码状态请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.commerce.qrcode.modifyStatus';

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
     * 成功生成代码后返回的二维码值
     *
     * @var string
     */
    public $qrcode;

    /**
     * 状态
     * STOP: 停止二维码。如果用户扫描停止的二维码, 将通知他二维码无效。
     * RESTART: 二维码可以在重新启动后使用。
     * DELETE: 删除二维码。如果用户扫描删除的二维码, 他将被通知二维码是无效的。删除后无法重新启动代码。
     *
     * @var string
     */
    public $status;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['timestamp']))
        {
            $obj['timestamp'] = date('Y-m-d H:i:s');
        }

        return $obj;
    }
}
