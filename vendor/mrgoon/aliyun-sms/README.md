# 介绍
阿里大于关闭了新用户渠道，迁移到阿里云短信服务上，然而阿里云短信服务在5月底更新了api。
在github上找了一下，轮子都是基于2016年的接口，非常难受。
自己造了一个轮子，亲测可用。

* 安装
` composer require mrgoon/aliyun-sms `

## 基于laravel框架的使用方法

* 加载
在config/app的providers中添加
` Mrgoon\AliSms\ServiceProvider::class `

同时，可以选择性添加aliases

控制台运行:
` php artisan vendor:publish `

根据新增的` aliyunsms.php ` 文件，在.env文件中添加环境变量：
``` 
ALIYUN_SMS_AK=your access key
ALIYUN_SMS_AS=your secret key
ALIYUN_SMS_SIGN_NAME=sign name
```

* 使用
```
$aliSms = new AliSms();
$response = $aliSms->sendSms('phone number', 'SMS_code', ['name'=> 'value in your template']);
//dump($response);
```

## 非laravel框架的使用方法

* 加载方式通过composer，不变
* 使用样例代码如下：

```
$config = [
        'access_key' => 'your access key',
        'access_secret' => 'your access secret',
        'sign_name' => 'your sign name',
    ];

    $aliSms = new Mrgoon\AliSms\AliSms();
    $response = $sms->sendSms('phone number', 'tempplate code', ['name'=> 'value in your template'], $config);
```
