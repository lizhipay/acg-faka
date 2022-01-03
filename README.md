<br>
<p align="center">
<a><img src="https://travis-ci.org/laravel/framework.svg" alt="Build Status"></a>
<a><img src="https://poser.pugx.org/laravel/framework/license.svg" alt="License"></a>
</p>

## 快速体验
- 后台演示：[http://162.14.111.118:81/admin](http://162.14.111.118:81/admin)  账号：demo@demo.com 密码：123456
- 前台演示：[http://162.14.111.118:81](http://162.14.111.118:81) 账号：为了明天美好而战斗 密码：123456

## 关于异次元发卡系统

异次元发卡系统乃`荔枝发卡系统3.0`完全从0代码的重构版本，原生php开发，数据库底层使用`Eloquent ORM`，模板渲染使用`Smarty3.1`以及`PHP原生渲染`，会话保持全程使用`session`，下面是简单功能介绍，还有更多细节无法一一介绍，需要你自己下载并安装才能体验。

- 支付系统，拥有强悍的插件扩展能力，现目今已经支持全网任意平台，任意支付渠道。
- 云更新，如果系统升级新版本，你无需进行繁琐操作，只需要在你的店铺后台就可以无缝完成升级。
- 商品销售，支持商品配图、会员价、游客价、邮件通知、卡密预选（用户可以预选自己想购买的那个账号或者卡号）、API对接、强制登录购买、强悍的自定义控件功能、限时秒杀、批发优惠、优惠卷、等众多功能。
- 分站系统，前台用户可以开通分站，分站可以独立运行，也可以卖主站商品，有点类似商业发卡了。
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

## 程序安装教程

- 在安装之前，请检查你的系统环境，`php>=8.0`，`MySQL版本>=5.6[不推荐5.6后续升级可能会有问题，推荐5.7或者8.0]`，因为使用了大量的PHP8注解以及PHP8的新特性，所以php版本不得不从8.0起，这里还需要注意。
- 将源码下载至你的服务器
- 以上步骤完成后，然后配置伪静态，Apache无需配置，根目录已经有.htaccess文件了，但如果你是Nginx，则需要配置伪静态。
- 下面是Nginx伪静态规则：
```
location / {
      if (!-e $request_filename){
              rewrite ^(.*)$ /index.php?s=$1 last; break;
      }
}
```
- 配置完成后，访问你的首页，即可开始安装。
- 安装完成后，后台地址是：`https://你的域名/admin`
## 版本更新记录
<p>所有更新都支持手动升级，但是推荐你还是使用自动升级，如果你当前版本并不是最新版本的上个版本，那么你必须依次按照版本进行手动升级，直到最新版本为止。</p>
<p style="color: red;">手动升级方法：下载升级包后，有两个文件夹，file文件夹里的文件复制到根目录覆盖替换即可，更新包里面的update.sql如果有内容，表示这个版本需要更新数据库某些地方，需要你手动复制到你的数据库执行一次。</p>


- 2022/01/04：[0.5.5-beta.zip](https://download.acged.cc/faka/version/0.5.5-beta.zip)
- 2022/01/04：[0.5.4-beta.zip](https://download.acged.cc/faka/version/0.5.4-beta.zip)
- 2022/01/03：[0.5.3-beta.zip](https://download.acged.cc/faka/version/0.5.3-beta.zip)
- 2022/01/02：[0.5.2-beta.zip](https://download.acged.cc/faka/version/0.5.2-beta.zip)
- 2022/01/01：[0.5.1-beta.zip](https://download.acged.cc/faka/version/0.5.1-beta.zip)
- 2022/01/01：[0.5.0-beta.zip](https://download.acged.cc/faka/version/0.5.0-beta.zip)
- 2021/12/30：[0.4.9-beta.zip](https://download.acged.cc/faka/version/0.4.9-beta.zip)
- 2021/12/30：[0.4.8-beta.zip](https://download.acged.cc/faka/version/0.4.8-beta.zip)
- 2021/12/30：[0.4.7-beta.zip](https://download.acged.cc/faka/version/0.4.7-beta.zip)
- 2021/12/30：[0.4.6-beta.zip](https://download.acged.cc/faka/version/0.4.6-beta.zip)
- 2021/12/28：[0.4.5-beta.zip](https://download.acged.cc/faka/version/0.4.5-beta.zip)
- 2021/12/26：[0.4.4-beta.zip](https://download.acged.cc/faka/version/0.4.4-beta.zip)
- 2021/12/26：[0.4.3-beta.zip](https://download.acged.cc/faka/version/0.4.3-beta.zip)
- 2021/12/26：[0.4.2-beta.zip](https://download.acged.cc/faka/version/0.4.2-beta.zip)
- 2021/12/21：[0.4.1-beta.zip](https://download.acged.cc/faka/version/0.4.1-beta.zip)
- 2021/12/20：[0.4.0-beta.zip](https://download.acged.cc/faka/version/0.4.0-beta.zip)
- 2021/12/19：[0.3.9-beta.zip](https://download.acged.cc/faka/version/0.3.9-beta.zip)
- 2021/12/16：[0.3.8-beta.zip](https://download.acged.cc/faka/version/0.3.8-beta.zip)
- 2021/12/15：[0.3.7-beta.zip](https://download.acged.cc/faka/version/0.3.7-beta.zip)
- 2021/12/14：[0.3.6-beta.zip](https://download.acged.cc/faka/version/0.3.6-beta.zip)
- 2021/12/14：[0.3.5-beta.zip](https://download.acged.cc/faka/version/0.3.5-beta.zip)
- 2021/12/14：[0.3.4-beta.zip](https://download.acged.cc/faka/version/0.3.4-beta.zip)
- 2021/12/14：[0.3.3-beta.zip](https://download.acged.cc/faka/version/0.3.3-beta.zip)
- 2021/12/12：[0.3.2-beta.zip](https://download.acged.cc/faka/version/0.3.2-beta.zip)
- 2021/12/11：[0.3.1-beta.zip](https://download.acged.cc/faka/version/0.3.1-beta.zip)
- 2021/12/11：[0.2.9-beta.zip](https://download.acged.cc/faka/version/0.2.9-beta.zip)
- 2021/12/10：[0.2.8-beta.zip](https://download.acged.cc/faka/version/0.2.8-beta.zip)
- 2021/12/09：[0.2.7-beta.zip](https://download.acged.cc/faka/version/0.2.7-beta.zip)
- 2021/12/08：[0.2.6-beta.zip](https://download.acged.cc/faka/version/0.2.6-beta.zip)
- 2021/12/07：[0.2.5-beta.zip](https://download.acged.cc/faka/version/0.2.5-beta.zip)
- 2021/12/07：[0.2.4-beta.zip](https://download.acged.cc/faka/version/0.2.4-beta.zip)
- 2021/12/07：[0.2.3-beta.zip](https://download.acged.cc/faka/version/0.2.3-beta.zip)
- 2021/12/07：[0.2.2-beta.zip](https://download.acged.cc/faka/version/0.2.2-beta.zip)
- 2021/12/07：[0.2.1-beta.zip](https://download.acged.cc/faka/version/0.2.1-beta.zip)
- 2021/12/07：[0.2.0-beta.zip](https://download.acged.cc/faka/version/0.2.0-beta.zip)
- 2021/12/06：[0.1.9-beta.zip](https://download.acged.cc/faka/version/0.1.9-beta.zip)
- 2021/12/06：[0.1.8-beta.zip](https://download.acged.cc/faka/version/0.1.8-beta.zip)
- 2021/12/06：[0.1.7-beta.zip](https://download.acged.cc/faka/version/0.1.7-beta.zip)
- 2021/12/06：[0.1.6-beta.zip](https://download.acged.cc/faka/version/0.1.6-beta.zip)
- 2021/12/06：[0.1.5-beta.zip](https://download.acged.cc/faka/version/0.1.5-beta.zip)
- 2021/12/05：[0.1.4-beta.zip](https://download.acged.cc/faka/version/0.1.4-beta.zip)
- 2021/12/04：[0.1.3-beta.zip](https://download.acged.cc/faka/version/0.1.3-beta.zip)
- 2021/12/04：[0.1.2-beta.zip](https://download.acged.cc/faka/version/0.1.2-beta.zip)
- 2021/12/03：[0.1.1-beta.zip](https://download.acged.cc/faka/version/0.1.1-beta.zip)
- 2021/12/02：[0.1.0-beta.zip](https://download.acged.cc/faka/version/0.1.0-beta.zip)
- 2021/11/30：[0.0.9-beta.zip](https://download.acged.cc/faka/version/0.0.9-beta.zip)
- 2021/11/30：[0.0.8-beta.zip](https://download.acged.cc/faka/version/0.0.8-beta.zip)
- 2021/11/29：[0.0.7-beta.zip](https://download.acged.cc/faka/version/0.0.7-beta.zip)
- 2021/11/29：[0.0.6-beta.zip](https://download.acged.cc/faka/version/0.0.6-beta.zip)
- 2021/11/28：[0.0.5-beta.zip](https://download.acged.cc/faka/version/0.0.5-beta.zip)
- 2021/11/28：[0.0.4-beta.zip](https://download.acged.cc/faka/version/0.0.4-beta.zip)
- 2021/11/28：[0.0.3-beta.zip](https://download.acged.cc/faka/version/0.0.3-beta.zip)
- 2021/11/28：[0.0.2-beta.zip](https://download.acged.cc/faka/version/0.0.2-beta.zip)
- 2021/11/27：发布0.0.1-beta测试版本。
## 更多支持
- 交流QQ群：128215049
