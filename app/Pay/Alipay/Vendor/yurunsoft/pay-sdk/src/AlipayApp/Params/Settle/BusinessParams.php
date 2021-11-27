<?php

namespace Yurun\PaySDK\AlipayApp\Params\Settle;

/**
 * 支付宝统一收单交易结算接口业务参数类.
 */
class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 结算请求流水号 开发者自行生成并保证唯一性.
     *
     * @var string
     */
    public $out_request_no;

    /**
     * 支付宝订单号.
     *
     * @var string
     */
    public $trade_no;

    /**
     * 分账明细信息.
     *
     * @var array<\Yurun\PaySDK\AlipayApp\Params\Settle\RoyaltyParameter>
     */
    public $royalty_parameters = [];

    /**
     * 操作员id.
     *
     * @var string
     */
    public $operator_id;

    public function toString()
    {
        $obj = (array) $this;
        if (empty($obj['royalty_parameters']))
        {
            unset($obj['royalty_parameters']);
        }
        else
        {
            $obj['royalty_parameters'] = json_encode($obj['royalty_parameters']);
        }

        return json_encode($obj);
    }
}
