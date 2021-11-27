<?php

namespace Yurun\PaySDK\Alipay\Params\RefundPwd;

/**
 * 支付宝有密退款业务参数类.
 */
class BusinessParams
{
    /**
     * 卖家支付宝账号.
     *
     * @var string
     */
    public $seller_email;

    /**
     * 卖家用户ID
     * 登录时，seller_email和seller_user_id两者必填一个。如果两者都填，以seller_user_id为准。
     *
     * @var string
     */
    public $seller_user_id;

    /**
     * 退款请求时间
     * 格式为：yyyy-MM-dd HH:mm:ss。
     *
     * @var string
     */
    public $refund_date;

    /**
     * 退款批次号
     * 每进行一次即时到账批量退款，都需要提供一个批次号，通过该批次号可以查询这一批次的退款交易记录，对于每一个合作伙伴，传递的每一个批次号都必须保证唯一性。
     * 格式为：退款日期（8位）+流水号（3～24位）。
     * 不可重复，且退款日期必须是当天日期。流水号可以接受数字或英文字符，建议使用数字，但不可接受“000”。
     *
     * @var string
     */
    public $batch_no;

    /**
     * 总笔数
     * 即参数detail_data的值中，“#”字符出现的数量加1，最大支持1000笔（即“#”字符出现的最大数量为999个）。
     *
     * @var string
     */
    public $batch_num;

    /**
     * 单笔数据集
     * 退款请求的明细数据。
     * 格式详情参见下面的“单笔数据集参数说明”。
     *
     * @var string
     */
    public $detail_data;
}
