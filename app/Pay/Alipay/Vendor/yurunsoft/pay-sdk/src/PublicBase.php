<?php

namespace Yurun\PaySDK;

/**
 * 公共参数基类.
 */
abstract class PublicBase
{
    /**
     * 接口网关.
     *
     * @var string
     */
    public $apiDomain;

    /**
     * 支付平台分配给开发者的应用ID.
     *
     * @var string
     */
    public $appID;
}
