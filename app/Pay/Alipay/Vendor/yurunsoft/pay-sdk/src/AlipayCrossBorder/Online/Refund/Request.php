<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Online\Refund;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外在线支付-退款请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'forex_refund';

    /**
     * 外部退款请求的ID.
     *
     * @var string
     */
    public $out_return_no;

    /**
     * 境外商户交易号（确保在境外商户系统中唯一）.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 外币退款金额.
     *
     * @var float
     */
    public $return_amount;

    /**
     * 外币币种.
     *
     * @var string
     */
    public $currency;

    /**
     * YYYYMMDDHHMMSS 北京时间(+8).
     *
     * @var string
     */
    public $gmt_return;

    /**
     * 人民币退款金额.
     *
     * @var float
     */
    public $return_rmb_amount;

    /**
     * 退款原因.
     *
     * @var string
     */
    public $reason;

    /**
     * 产品代码
     * 网站支付: NEW_OVERSEAS_SELLER
     * 手机浏览器或支付宝钱包支付: NEW_WAP_OVERSEAS_SELLER.
     *
     * @var string
     */
    public $product_code;

    /**
     * 分账信息.
     *
     * @var array<\Yurun\PaySDK\AlipayCrossBorder\Params\SplitFundInfo>
     */
    public $split_fund_info = [];

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['split_fund_info']))
        {
            unset($obj['split_fund_info']);
        }
        else
        {
            $obj['split_fund_info'] = json_encode($obj['split_fund_info']);
        }

        return $obj;
    }
}
