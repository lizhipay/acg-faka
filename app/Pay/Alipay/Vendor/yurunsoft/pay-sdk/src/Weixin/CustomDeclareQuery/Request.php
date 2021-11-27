<?php

namespace Yurun\PaySDK\Weixin\CustomDeclareQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-海关报关查询请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'cgi-bin/mch/customs/customdeclarequery';

    /**
     * 商户系统内部订单号，要求32个字符内，只能是数字、大小写字母_-|*@ ，且在同一个商户号下唯一。
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 微信支付返回的订单号.
     *
     * @var string
     */
    public $transaction_id;

    /**
     * 商户子订单号.
     *
     * @var string
     */
    public $sub_order_no;

    /**
     * 微信子订单号.
     *
     * @var string
     */
    public $sub_order_id;

    /**
     * 海关
     * NO 无需上报海关
     * GUANGZHOU_ZS 广州（总署版）
     * GUANGZHOU_HP_GJ 广州黄埔国检（需推送订单至黄埔国检的订单需分别推送广州（总署版）和广州黄埔国检，即需要请求两次报关接口）
     * GUANGZHOU_NS_GJ 广州南沙国检（需推送订单至南沙国检的订单需分别推送广州（总署版）和广州南沙国检，即需要请求两次报关接口）
     * HANGZHOU_ZS 杭州（总署版）
     * NINGBO 宁波
     * ZHENGZHOU_BS 郑州（保税物流中心）
     * CHONGQING 重庆
     * XIAN 西安
     * SHANGHAI_ZS 上海（总署版）
     * SHENZHEN 深圳
     * ZHENGZHOU_ZH_ZS 郑州综保（总署版）
     * TIANJIN 天津
     * BEIJING 北京.
     *
     * @var string
     */
    public $customs;

    public function __construct()
    {
        $this->needNonceStr = $this->needSignType = false;
        $this->signType = 'MD5';
        parent::__construct();
    }
}
