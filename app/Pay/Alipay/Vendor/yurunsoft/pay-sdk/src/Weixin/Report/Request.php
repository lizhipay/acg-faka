<?php

namespace Yurun\PaySDK\Weixin\Report;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-交易保障错误提交请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'payitil/report';

    /**
     * 微信支付分配的终端设备号，商户自定义.
     *
     * @var string
     */
    public $device_info;

    /**
     * 接口URL
     * 报对应的接口的完整URL，类似：
     * https://api.mch.weixin.qq.com/pay/unifiedorder
     * 对于刷卡支付，为更好的和商户共同分析一次业务行为的整体耗时情况，对于两种接入模式，请都在门店侧对一次刷卡支付进行一次单独的整体上报，上报URL指定为：
     * https://api.mch.weixin.qq.com/pay/micropay/total
     * 关于两种接入模式具体可参考本文档章节：刷卡支付商户接入模式
     * 其它接口调用仍然按照调用一次，上报一次来进行。
     *
     * @var string
     */
    public $interface_url;

    /**
     * 接口耗时情况，单位为毫秒
     * 没错，就这破字段后面加了下划线
     *
     * @var int
     */
    public $execute_time_;

    /**
     * 返回状态码
     * SUCCESS/FAIL
     * 此字段是通信标识，非交易标识，交易是否成功需要查看trade_state来判断.
     *
     * @var string
     */
    public $return_code;

    /**
     * 返回信息，如非空，为错误原因
     * 签名失败
     * 参数格式校验错误.
     *
     * @var string
     */
    public $return_msg;

    /**
     * 业务结果
     * SUCCESS/FAIL.
     *
     * @var string
     */
    public $result_code;

    /**
     * 错误代码
     * ORDERNOTEXIST—订单不存在
     * SYSTEMERROR—系统错误.
     *
     * @var string
     */
    public $err_code;

    /**
     * 错误代码描述.
     *
     * @var string
     */
    public $err_code_des;

    /**
     * 商户订单号
     * 商户系统内部的订单号,商户可以在上报时提供相关商户订单号方便微信支付更好的提高服务质量。
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 访问接口IP
     * 发起接口调用时的机器IP.
     *
     * @var string
     */
    public $user_ip;

    /**
     * 商户上报时间
     * 系统时间，格式为yyyyMMddHHmmss，如2009年12月27日9点10分10秒表示为20091227091010。
     *
     * @var string
     */
    public $time;

    /**
     * POS机采集的交易信息列表，一般用于批量上传POS机信息
     * 使用JSON格式的数组，每条交易包含：
     * 1. out_trade_no 商户订单号
     * 2. begin_time 交易开始时间（扫码时间)
     * 3. end_time 交易完成时间
     * 4. state 交易结果
     *                 OK   -成功
     *                 FAIL -失败
     *                 CANCLE-取消
     * 5. err_msg 自定义的错误描述信息.
     *
     * @var \Yurun\PaySDK\Weixin\Report\Trades
     */
    public $trades;

    public function __construct()
    {
        parent::__construct();
        $this->allowReport = false;
        $this->_isSyncVerify = false;
    }
}
