<?php

namespace Yurun\PaySDK\Weixin\QueryComment;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-查询订单评价请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'billcommentsp/batchquerycomment';

    /**
     * 开始时间
     * 按用户评论时间批量拉取的起始时间，格式为yyyyMMddHHmmss.
     *
     * @var string
     */
    public $begin_time;

    /**
     * 结束时间
     * 按用户评论时间批量拉取的结束时间，格式为yyyyMMddHHmmss.
     *
     * @var string
     */
    public $end_time;

    /**
     * 位移
     * 指定从某条记录的下一条开始返回记录。接口调用成功时，会返回本次查询最后一条数据的offset。商户需要翻页时，应该把本次调用返回的offset 作为下次调用的入参。注意offset是评论数据在微信支付后台保存的索引，未必是连续的.
     *
     * @var int
     */
    public $offset = 0;

    /**
     * 条数
     * 一次拉取的条数, 最大值是200，默认是200.
     *
     * @var int
     */
    public $limit;

    public function __construct()
    {
        parent::__construct();
        $this->_isSyncVerify = false;
        $this->needSignType = true;
        $this->signType = 'HMAC-SHA256';
    }
}
