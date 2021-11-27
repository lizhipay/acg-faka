<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Online\DownloadSettlement;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外在线支付-结算文件下载请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'forex_liquidation_file';

    /**
     * 交易的开始日期、格式为YYYYMMDD.
     *
     * @var string
     */
    public $start_date;

    /**
     * 交易的结束日期、格式为YYYYMMDD.
     *
     * @var string
     */
    public $end_date;

    public function __construct()
    {
        $this->_method = 'GET';
    }
}
