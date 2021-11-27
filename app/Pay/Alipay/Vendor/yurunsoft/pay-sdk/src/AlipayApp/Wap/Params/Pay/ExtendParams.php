<?php

namespace Yurun\PaySDK\AlipayApp\Wap\Params\Pay;

/**
 * 支付宝手机支付下单扩展参数类.
 */
class ExtendParams
{
    /**
     * 系统商编号，该参数作为系统商返佣数据提取的依据，请填写系统商签约协议的PID.
     *
     * @var string
     */
    public $sys_service_provider_id;

    /**
     * 是否发起实名校验
     * T：发起
     * F：不发起.
     *
     * @var string
     */
    public $needBuyerRealnamed;

    /**
     * 账务备注
     * 注：该字段显示在离线账单的账务备注中.
     *
     * @var string
     */
    public $TRANS_MEMO;

    /**
     * 花呗分期数（目前仅支持3、6、12）.
     *
     * @var string
     */
    public $hb_fq_num;

    /**
     * 卖家承担收费比例，商家承担手续费传入100，用户承担手续费传入0，仅支持传入100、0两种，其他比例暂不支持
     *
     * @var string
     */
    public $hb_fq_seller_percent;

    public function toArray()
    {
        if (null === $this->sys_service_provider_id && null === $this->hb_fq_num && null === $this->hb_fq_seller_percent && null === $this->needBuyerRealnamed && null === $this->TRANS_MEMO)
        {
            return null;
        }

        return (array) $this;
    }
}
