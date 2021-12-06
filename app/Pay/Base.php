<?php
declare(strict_types=1);

namespace App\Pay;

use App\Util\PayConfig;
use GuzzleHttp\Client;

/**
 * Class Base
 * @package App\Pay
 */
abstract class Base
{

    /**
     * 支付金额
     * @var float
     */
    public float $amount;

    /**
     * 订单信息
     * @var string
     */
    public string $tradeNo;

    /**
     * 配置信息
     * @var array
     */
    public array $config;

    /**
     * 回调地址
     * @var string
     */
    public string $callbackUrl;

    /**
     * 跳转地址
     * @var string
     */
    public string $returnUrl;

    /**
     * 客户IP地址
     * @var string
     */
    public string $clientIp;

    /**
     * 通道编码
     * @var string
     */
    public string $code;

    /**
     * @var string
     */
    public string $handle;

    /**
     * @param string $message
     */
    protected function log(string $message): void
    {
        PayConfig::log($this->handle, "TRADE", $message);
    }

    /**
     * 创建GUZZ HTTP CLIENT
     * @return Client
     */
    protected function http(): Client
    {
        return new Client(["verify" => false]);
    }
}