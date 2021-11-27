<?php

namespace Yurun\PaySDK\AlipayApp\Fund\UniTransfer;

class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 商家侧唯一订单号，由商家自定义。对于不同转账请求，商家需保证该订单号在自身系统唯一。
     * @var string
     */
    public $out_biz_no;

    /**
     * 订单总金额，单位为元，精确到小数点后两位，STD_RED_PACKET产品取值范围[0.01,100000000]；
     * TRANS_ACCOUNT_NO_PWD产品取值范围[0.1,100000000]
     * @var string
     */
    public $trans_amount;

    /**
     * 业务产品码，
     * 单笔无密转账到支付宝账户固定为:TRANS_ACCOUNT_NO_PWD；
     *  收发现金红包固定为:STD_RED_PACKET;
     * @var string
     */
    public $product_code;

    /**
     * 描述特定的业务场景，可传的参数如下：
     * DIRECT_TRANSFER：单笔无密转账到支付宝，B2C现金红包;
     * PERSONAL_COLLECTION：C2C现金红包-领红包
     * @var string
     */
    public $biz_scene;

    /**
     * 转账业务的标题，用于在支付宝用户的账单里显示
     * @var string
     */
    public $order_title;

    /**
     * 原支付宝业务单号。C2C现金红包-红包领取时，传红包支付时返回的支付宝单号；B2C现金红包、单笔无密转账到支付宝不需要该参数。
     * @var string
     */
    public $original_order_id;

    /**
     * 收款方信息
     * @var array<\Yurun\PaySDK\AlipayApp\Fund\UniTransfer\PayeeInfoParams>
     */
    public $payee_info = array();

    /**
     * 业务备注
     * @var string
     */
    public $remark;

    /**
     * 转账业务请求的扩展参数，支持传入的扩展参数如下：
     * sub_biz_scene 子业务场景，红包业务必传，取值REDPACKET，C2C现金红包、B2C现金红包均需传入
     * @return array
     */
    public $business_params = array();

    public function toString()
    {
        $obj = (array)$this;

        if (empty($obj['payee_info'])) {
            unset($obj['payee_info']);
        } else {
            $obj['payee_info'] = \json_encode($obj['payee_info']);
        }

        if (empty($obj['business_params'])) {
            unset($obj['business_params']);
        } else {
            $obj['business_params'] = \json_encode($obj['business_params']);
        }

        foreach ($obj as $key => $value) {
            if (null === $value) {
                unset($obj[$key]);
            }
        }

        return \json_encode($obj);
    }
}
