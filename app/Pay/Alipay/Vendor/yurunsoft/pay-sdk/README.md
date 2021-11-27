# PaySDK

[![Latest Version](https://img.shields.io/packagist/v/yurunsoft/pay-sdk.svg)](https://packagist.org/packages/yurunsoft/pay-sdk)
[![Php Version](https://img.shields.io/badge/php-%3E=5.5-brightgreen.svg)](https://secure.php.net/)
[![IMI Doc](https://img.shields.io/badge/docs-passing-green.svg)](http://doc.yurunsoft.com/PaySDK)
[![IMI License](https://img.shields.io/github/license/Yurunsoft/PaySDK.svg)](https://github.com/Yurunsoft/PaySDK/blob/master/LICENSE)

## 介绍

PaySDK 是 PHP 集成支付 SDK ，集成了支付宝、微信支付的支付接口和其它相关接口的操作。

无框架依赖，支持所有框架，支持 Swoole 协程环境。

我们有完善的在线技术文档：[http://doc.yurunsoft.com/PaySDK](http://doc.yurunsoft.com/PaySDK)

API 文档：[https://apidoc.gitee.com/yurunsoft/PaySDK](https://apidoc.gitee.com/yurunsoft/PaySDK)

同时欢迎各位加入**宇润 PHP 全家桶技术支持群**：17916227 [![点击加群](https://pub.idqqimg.com/wpa/images/group.png "点击加群")](https://jq.qq.com/?_wv=1027&k=5wXf4Zq)，如有问题可以及时解答和修复。

大家在开发中肯定会对接各种各样的支付平台，我个人精力有限，欢迎各位来 [Github](https://github.com/Yurunsoft/PaySDK) 提交 PR，一起完善 PaySDK ，让它能够支持更多的支付平台，更加稳定可靠好用。

## 支持的支付接口

### 支付宝

* 即时到账-电脑网站支付（老）
* 即时到账-手机网站支付（老）
* 当面付
* 手机网站支付
* 电脑网站支付
* APP支付服务端
* 小程序支付
* 单笔转账到支付宝账户
* 海外支付（电脑网站、手机网站、APP、扫码）
* 海关报关
* 其它辅助交易接口（退款、查询等）

### 微信支付

* 刷卡支付
* 公众号支付
* 扫码支付
* APP支付
* H5支付
* 小程序支付
* 企业付款到零钱
* 企业付款到银行卡
* 海外支付（刷卡、公众号、扫码、APP）
* 海关报关
* 其它辅助交易接口（退款、查询等）

## 安装

在您的composer.json中加入配置：

`PHP >= 5.5`

```json
{
    "require": {
        "yurunsoft/pay-sdk": "~3.0"
    }
}
```

`PHP >= 5.4`

```json
{
    "require": {
        "yurunsoft/pay-sdk": "~2.0"
    }
}
```

> 3.x 版本支持 PHP >= 5.5，持续迭代维护中

> 2.x 版本支持 PHP >= 5.4，支持长期 BUG 维护，保证稳定可用，停止功能性更新

然后执行`composer update`命令。

## 代码示例

### 支付宝即时到账

```php
// SDK实例化，传入公共配置
$pay = new \Yurun\PaySDK\Alipay\SDK($params);

// 支付接口
$request = new \Yurun\PaySDK\Alipay\Params\Pay\Request;
$request->notify_url = ''; // 支付后通知地址（作为支付成功回调，这个可靠）
$request->return_url = ''; // 支付后跳转返回地址
$request->businessParams->seller_id = $GLOBALS['PAY_CONFIG']['appid']; // 卖家支付宝用户号
$request->businessParams->out_trade_no = 'test' . mt_rand(10000000,99999999); // 商户订单号
$request->businessParams->total_fee = 0.01; // 价格
$request->businessParams->subject = '测试商品'; // 商品标题

// 跳转到支付页面
// $pay->redirectExecute($request);

// 获取跳转url
$pay->prepareExecute($request, $url);
var_dump($url);
```

### 支付宝手机网站支付

```php
// SDK实例化，传入公共配置
$pay = new \Yurun\PaySDK\AlipayApp\SDK($params);

// 支付接口
$request = new \Yurun\PaySDK\AlipayApp\Wap\Params\Pay\Request;
$request->notify_url = ''; // 支付后通知地址（作为支付成功回调，这个可靠）
$request->return_url = ''; // 支付后跳转返回地址
$request->businessParams->out_trade_no = 'test' . mt_rand(10000000,99999999); // 商户订单号
$request->businessParams->total_amount = 0.01; // 价格
$request->businessParams->subject = '小米手机9黑色陶瓷尊享版'; // 商品标题

// 跳转到支付页面
// $pay->redirectExecute($request);

// 获取跳转url
$pay->prepareExecute($request, $url);
var_dump($url);
```

### 微信H5支付

```php
// SDK实例化，传入公共配置
$pay = new \Yurun\PaySDK\Weixin\SDK($params);

// 支付接口
$request = new \Yurun\PaySDK\Weixin\H5\Params\Pay\Request;
$request->body = 'test'; // 商品描述
$request->out_trade_no = 'test' . mt_rand(10000000,99999999); // 订单号
$request->total_fee = 1; // 订单总金额，单位为：分
$request->spbill_create_ip = '127.0.0.1'; // 客户端ip
$request->notify_url = ''; // 异步通知地址

// 调用接口
$result = $pay->execute($request);
if($pay->checkResult())
{
    // 跳转支付界面
    header('Location: ' . $result['mweb_url']);
}
else
{
    var_dump($pay->getErrorCode() . ':' . $pay->getError());
}
exit;
```

### Swoole 协程环境支持

在支付、退款异步通知中，需要赋值 `Swoole` 的 `Request` 和 `Response` 对象，或者遵循 PSR-7 标准的对象即可。

主流框架的 `Request` 和 `Response` 对象，一般都遵循 PSR-7 标准，可以直接使用。

#### imi 框架中使用

imi 是基于 PHP Swoole 的高性能协程应用开发框架，它支持 HttpApi、WebSocket、TCP、UDP 服务的开发。

在 Swoole 的加持下，相比 php-fpm 请求响应能力，I/O密集型场景处理能力，有着本质上的提升。

imi 框架拥有丰富的功能组件，可以广泛应用于互联网、移动通信、企业软件、云计算、网络游戏、物联网（IOT）、车联网、智能家居等领域。可以使企业 IT 研发团队的效率大大提升，更加专注于开发创新产品。

<https://www.imiphp.com/>

```php
/**
 * 这是一个在控制器中的动作方法
 * @Action
 */
public function test()
{
    $payNotify = new class extends \Yurun\PaySDK\Weixin\Notify\Pay
    {
        /**
         * 后续执行操作
         * @return void
         */
        protected function __exec()
        {

        }
    };
    $context = RequestContext::getContext();
    // 下面两行很关键
    $payNotify->swooleRequest = $context['request'];
    $payNotify->swooleResponse = $context['response'];

    $sdk->notify($payNotify);

    // 这句话必须填写
    return $payNotify->swooleResponse;
}
```

#### 其它框架（Swoole 对象）

```php
$payNotify = new class extends \Yurun\PaySDK\Weixin\Notify\Pay
{
    /**
     * 后续执行操作
     * @return void
     */
    protected function __exec()
    {

    }
};
// 下面两行很关键，$request、$response 从 request 中获取
// 或者查阅如何从你使用的框架中获取
$payNotify->swooleRequest = $request;
$payNotify->swooleResponse = $response;

$sdk->notify($payNotify);
```

#### 其它框架（PSR-7 对象）

```php
$payNotify = new class extends \Yurun\PaySDK\Weixin\Notify\Pay
{
    /**
     * 后续执行操作
     * @return void
     */
    protected function __exec()
    {

    }
};
// 目前主流 Swoole 基本都支持 PSR-7 标准的对象
// 所以可以直接传入，如何获取请查阅对应框架的文档
$payNotify->swooleRequest = $request;
$payNotify->swooleResponse = $response;

$sdk->notify($payNotify);

// 处理完成后需要将 $response 从控制器返回或者赋值给上下文
// 不同框架的操作不同，请自行查阅对应框架的文档
return $payNotify->swooleResponse;
```

## 商业服务

**服务内容：**

* 问题排查及咨询
* 代接支付
* 其它合作

如有需要加QQ：369124067（注明来意）

## 捐赠

<img src="https://raw.githubusercontent.com/Yurunsoft/PaySDK/master/res/pay.png"/>

开源不求盈利，多少都是心意，生活不易，随缘随缘……
