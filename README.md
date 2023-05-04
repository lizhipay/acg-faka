<p align="center">
  <a href="https://faka.wiki/">
    <img src="https://acged.cc/svg/logo.png" width="120" height="120" style="border-radius: 20px;" alt="异次元店铺系统">
  </a>
</p>

<br>
<p align="center">
<span>
<img src="https://acged.cc/svg/php.svg" alt="php8.0,8.1">
</span>
<span>
<img src="https://acged.cc/svg/mysql-version.svg" alt="mysql5.6+">
</span>
<span><img src="https://acged.cc/svg/license.svg" alt="license"></span>
</p>

## 郑重声明
> 本系统开源仅仅是为了新手学习开发商城为目的，所以请不要在没有相关运营资质情况下搭建外网进行运营，万不可触犯法律底线。

## 快速体验
- 后台演示：[http://162.14.111.118:91/admin](http://162.14.111.118:91/admin)  账号：demo@demo.com 密码：123456
- 前台演示：[http://162.14.111.118:91](http://162.14.111.118:91) 账号：为了明天美好而战斗 密码：123456
- 文档地址：[https://faka.wiki](https://faka.wiki)

## 功能简介

- 支付系统，拥有强悍的插件扩展能力，现目今已经支持全网任意平台，任意支付渠道。
- 云更新，如果系统升级新版本，你无需进行繁琐操作，只需要在你的店铺后台就可以无缝完成升级。
- 商品销售，支持商品配图、会员价、游客价、邮件通知、卡密预选（用户可以预选自己想购买的那个账号或者卡号）、API对接、强制登录购买、强悍的自定义控件功能、限时秒杀、批发优惠、优惠卷、等众多功能。
- 分站系统，前台用户可以开通分站，分站可以独立运行，也可以卖主站商品，有点类似商业店铺了。
- 会员系统，会员/商户融为一体，支持会员等级，以及商户等级完全自定义，以及商品可自定义会员等级对应价格。
- 推广/代理系统，拥有三级分销返佣功能，注册账号即实现自动发展下级。
- 共享店铺系统，可以在后台直接对接别人的店铺，通过扣除余额来进行无感知进货。
- 应用商店，拥有众多插件以及模板，让你的店铺变得格外强大。
- 界面美观，完美支持PC和手机，真正的内外二次元文化。
- 强悍的扩展能力，你可以通过本程序在几分钟之内快速的实现你任意想实现的在线购物功能，例子如下： 
  - 游戏方面，物品购买即时到玩家背包
  - 商业软件余额充值
  - 商业软件自动授权
  - 论坛/社区VIP自动开通
  - 只要你想得到，没有做不到。
- 还有更多强大的功能，需要安装自己发掘。至此，介绍完毕。

## 安装教程

- 在安装之前，请检查你的系统环境，`php>=8.0`，`MySQL版本>=5.6[不推荐5.6后续升级可能会有问题，推荐5.7或者8.0]`，因为使用了大量的PHP8注解以及PHP8的新特性，所以php版本不得不从8.0起，这里还需要注意。
- 将源码下载至你的服务器、或者使用composer下载源码：`composer create-project lizhipay/acg-faka:dev-main`
- 以上步骤完成后，然后配置伪静态，Apache无需配置，根目录已经有.htaccess文件了，但如果你是Nginx，则需要配置伪静态。
- 下面是Nginx伪静态规则：
```
location / {
      if (!-e $request_filename){
              rewrite ^(.*)$ /index.php?s=$1 last; break;
      }
}
```
- Windows IIS服务器环境，可以使用下面伪静态规则：
```
<rules>
	<rule name="acg_rewrite" stopProcessing="true">
		<match url="^(.*)$"/>
		<conditions logicalGrouping="MatchAll">
			<add input="{HTTP_HOST}" pattern="^(.*)$"/>
			<add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true"/>
			<add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true"/>
		</conditions>
		<action type="Rewrite" url="index.php?s={R:1}"/>
	</rule>
</rules>
```
- 配置完成后，访问你的首页，即可开始安装
- 安装完成后，后台地址是：`https://你的域名/admin`

## 更多支持
- 交流QQ群：823266410
- [Telegram](https://t.me/acgshop)