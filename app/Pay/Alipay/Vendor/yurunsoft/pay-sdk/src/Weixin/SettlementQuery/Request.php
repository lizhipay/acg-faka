<?php

namespace Yurun\PaySDK\Weixin\SettlementQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-结算查询请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/settlementquery';

    /**
     * 微信支付分配的子商户号.
     *
     * @var string
     */
    public $sub_mch_id;

    /**
     * 结算状态
     * 1 - 已结算查询
     * 2 - 未结算查询.
     *
     * @var int
     */
    public $usetag;

    /**
     * 返回的查询结果从这个偏移量开始取记录，从1开始.
     *
     * @var int
     */
    public $offset;

    /**
     * 返回的最大记录条数，一般不超过10条为佳。
     *
     * @var int
     */
    public $limit;

    /**
     * 开始日期，格式为yyyyMMdd.
     *
     * @var string
     */
    public $date_start;

    /**
     * 结束日期，格式为yyyyMMdd.
     *
     * @var string
     */
    public $date_end;

    public function __construct()
    {
        parent::__construct();
        $this->_isSyncVerify = $this->needSignType = false;
        $this->signType = 'MD5';
    }
}
