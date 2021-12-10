<br>
<p align="center">
<a><img src="https://travis-ci.org/laravel/framework.svg" alt="Build Status"></a>
<a><img src="https://poser.pugx.org/laravel/framework/license.svg" alt="License"></a>
</p>

## 快速体验
- 后台演示：[http://162.14.111.118:81/admin](http://162.14.111.118:81/admin)  账号：demo@demo.com 密码：123456
- 前台演示：[http://162.14.111.118:81](http://162.14.111.118:81) 账号：为了明天美好而战斗 密码：123456

## 关于异次元发卡系统

异次元发卡系统乃`荔枝发卡系统3.0`完全从0代码的重构版本，原生php开发，数据库底层使用`Eloquent ORM`组件，模板渲染使用`Smarty3.1`组件，会话保持使用`session`开发，欢迎各位使用以及转载。

- 云更新，为了去掉繁琐的后续版本更新又要下载又要升级数据库等等琐事，所以本程序实现了自动更新，一旦出现新版本，后台只需要点击按钮即可自动完成程序的所有无缝升级。
- 基础功能，卡密销售，后台添加商品，然后导入卡密，进行售卡。
- 分站店铺，前台用户可以搭建分站，分站可以独立运行，也可以卖主站商品，有点类似商业发卡了。
- 推广系统，拥有三级分销返佣功能。
- 共享店铺，可以对接别人网站，实现商品共享。
- 应用商店，开放式API，可以自行制作各种插件以及模板。
- 界面美观，完美支持PC和手机，真正的内外二次元文化。
- 还有更多强大的功能，需要安装自己发掘。至此，介绍完毕。

## 程序安装教程

- 在安装之前，请检查你的系统环境，`php>=8.0`，`MySQL版本>=5.7`，因为使用了大量的PHP8注解功能，所以php版本不得不从8.0起。
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


- 2021/12/11：[0.3.0-beta.zip](https://download.acged.cc/faka/version/0.3.0-beta.zip)
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
