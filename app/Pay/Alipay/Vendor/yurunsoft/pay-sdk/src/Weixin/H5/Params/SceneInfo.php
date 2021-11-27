<?php

namespace Yurun\PaySDK\Weixin\H5\Params;

use Yurun\PaySDK\Traits\JSONParams;

/**
 * 微信支付-H5支付场景信息类.
 */
class SceneInfo
{
    use JSONParams;

    /**
     * 场景类型，必传
     * 可选值：IOS、Android、Wap.
     *
     * @var string
     */
    public $type;

    /**
     * 应用名，iOS和Android必传.
     *
     * @var string
     */
    public $app_name;

    /**
     * 苹果bundle_id.
     *
     * @var string
     */
    public $bundle_id;

    /**
     * 安卓包名.
     *
     * @var string
     */
    public $package_name;

    /**
     * WAP网站URL地址
     *
     * @var string
     */
    public $wap_url;

    /**
     * WAP 网站名.
     *
     * @var string
     */
    public $wap_name;

    public function toString()
    {
        $data = [
            'type'	 => $this->type,
        ];
        switch ($this->type)
        {
            case 'IOS':
                $data['app_name'] = $this->app_name;
                $data['bundle_id'] = $this->bundle_id;
                break;
            case 'Android':
                $data['app_name'] = $this->app_name;
                $data['package_name'] = $this->package_name;
                break;
            case 'Wap':
                $data['wap_url'] = $this->wap_url;
                $data['wap_name'] = $this->wap_name;
                break;
            default:
                return '';
        }

        return json_encode(['h5_info' => $data]);
    }
}
