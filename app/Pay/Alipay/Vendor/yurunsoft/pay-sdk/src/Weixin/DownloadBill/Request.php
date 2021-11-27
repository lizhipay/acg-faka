<?php

namespace Yurun\PaySDK\Weixin\DownloadBill;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-下载对账单请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/downloadbill';

    /**
     * 微信支付分配的终端设备号.
     *
     * @var string
     */
    public $device_info;

    /**
     * 下载对账单的日期，格式：20140603.
     *
     * @var string
     */
    public $bill_date;

    /**
     * 账单类型
     * ALL，返回当日所有订单信息，默认值
     * SUCCESS，返回当日成功支付的订单
     * REFUND，返回当日退款订单
     * RECHARGE_REFUND，返回当日充值退款订单（相比其他对账单多一栏“返还手续费”）.
     *
     * @var string
     */
    public $bill_type;

    /**
     * 压缩账单
     * 非必传参数，固定值：GZIP，返回格式为.gzip的压缩包账单。不传则默认为数据流形式。
     *
     * @var string
     */
    public $tar_type;

    public function __construct()
    {
        parent::__construct();
        $this->_isSyncVerify = false;
    }
}
