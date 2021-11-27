<?php

namespace Yurun\PaySDK\Weixin\Params;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-支付请求基类.
 */
class PayRequestBase extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/unifiedorder';

    /**
     * 终端设备号(门店号或收银设备ID)，注意：PC网页或公众号内支付请传"WEB".
     *
     * @var string
     */
    public $device_info = 'WEB';

    /**
     * 商品简单描述，该字段须严格按照规范传递，具体请见https://pay.weixin.qq.com/wiki/doc/api/H5.php?chapter=4_2.
     *
     * @var string
     */
    public $body;

    /**
     * 商品详细描述，对于使用单品优惠的商户，改字段必须按照规范上传，详见https://pay.weixin.qq.com/wiki/doc/api/danpin.php?chapter=9_102&index=2.
     *
     * @var \Yurun\PaySDK\Weixin\Params\Detail
     */
    public $detail;

    /**
     * 附加数据，在查询API和支付通知中原样返回，该字段主要用于商户携带订单的自定义数据.
     *
     * @var string
     */
    public $attach;

    /**
     * 商户系统内部的订单号,32个字符内、可包含字母, 其他说明见https://pay.weixin.qq.com/wiki/doc/api/H5.php?chapter=4_2.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 符合ISO 4217标准的三位字母代码，默认人民币：CNY，其他值列表详见https://pay.weixin.qq.com/wiki/doc/api/app/app.php?chapter=4_2.
     *
     * @var string
     */
    public $fee_type = 'CNY';

    /**
     * 订单总金额，单位为分，详见https://pay.weixin.qq.com/wiki/doc/api/H5.php?chapter=4_2.
     *
     * @var int
     */
    public $total_fee;

    /**
     * 必须传正确的用户端IP
     * APP和网页支付提交用户端ip，Native支付填调用微信支付API的机器IP。
     *
     * @var string
     */
    public $spbill_create_ip;

    /**
     * 订单生成时间，格式为yyyyMMddHHmmss，如2009年12月25日9点10分10秒表示为20091225091010。
     *
     * @var string
     */
    public $time_start;

    /**
     * 订单失效时间，格式为yyyyMMddHHmmss，如2009年12月27日9点10分10秒表示为20091227091010。
     * 注意：最短失效时间间隔必须大于5分钟
     *
     * @var string
     */
    public $time_expire;

    /**
     * 订单优惠标记(商品标记)
     * 商品标记，代金券或立减优惠功能的参数，说明详见https://pay.weixin.qq.com/wiki/doc/api/tools/sp_coupon.php?chapter=12_1.
     *
     * @var string
     */
    public $goods_tag;

    /**
     * 异步接收微信支付结果通知的回调地址，通知url必须为外网可访问的url，不能携带参数。
     *
     * @var string
     */
    public $notify_url;

    /**
     * 交易类型，取值如下：JSAPI，NATIVE，APP等.
     *
     * @var string
     */
    public $trade_type;

    /**
     * 指定支付方式
     * no_credit--指定不能使用信用卡支付.
     *
     * @var string
     */
    public $limit_pay;

    /**
     * 开发票入口开放标识.
     *
     * Y，传入Y时，支付成功消息和支付详情页将出现开票入口。需要在微信支付商户平台或微信公众平台开通电子发票功能，传此字段才可生效
     *
     * @var string
     */
    public $receipt;

    /**
     * 是否需要分账.
     *
     * Y-是，需要分账
     * N-否，不分账
     * 字母要求大写，不传默认不分账
     *
     * @var string
     */
    public $profit_sharing;

    public function __construct()
    {
        $this->detail = new Detail();
    }
}
