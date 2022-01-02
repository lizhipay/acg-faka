<?php
declare(strict_types=1);

namespace App\View\User\Theme\Cartoon;

use App\Consts\Render;

/**
 * Interface Config
 * @package App\View\User\Theme\Cartoon
 */
interface Config
{
    /**
     * 介绍信息
     */
    const INFO = [
        "NAME" => "默认模板",
        "AUTHOR" => "荔枝",
        "VERSION" => "1.0.1",
        "WEB_SITE" => "#",
        "DESCRIPTION" => "默认模板",
        "RENDER" => Render::ENGINE_SMARTY
    ];

    /**
     * 配置信息
     */
    const SUBMIT = [
        [
            "title" => "模板风格",
            "name" => "style",
            "type" => "select",
            "dict" => [
                ["id" => 0, "name" => "会员中心-二次元风(默认)"],
                ["id" => 1, "name" => "会员中心-商务风"],
                ["id" => 2, "name" => "会员中心-二次元风(高端)"],
            ],
            "placeholder" => "请选择",
            "default" => 0
        ],
        [
            "title" => "缓存",
            "name" => "cache",
            "type" => "switch",
            "text" => "开启",
            "tips" => "浏览器本地缓存，缓存时间60秒"
        ],
        [
            "title" => "缓存时间",
            "name" => "cache_expire",
            "type" => "input",
            "placeholder" => "缓存过期时间，推荐60秒",
            "default" => 60
        ],
        [
            "title" => "ICP备案号",
            "name" => "icp",
            "type" => "input",
            "placeholder" => "填写后将会在店铺底部显示ICP备案号，不填写则不显示。"
        ],
    ];

    /**
     * 模板文件重定向，不需要修改的直接删除
     */
    const THEME = [
        "INDEX" => "Index/Index.html", //卡网首页
        "CLOSED" => "Index/Closed.html", //维护页面
        "QUERY" => "Index/Query.html", //订单查询
        "LOGIN" => "Authentication/Login.html", //用户登录
        "REGISTER" => "Authentication/Register.html", //用户注册
        "FORGET_EMAIL" => "Authentication/ForgetEmail.html", //用户找回密码-邮箱
        "FORGET_PHONE" => "Authentication/ForgetPhone.html", //用户找回密码-手机
        "RECHARGE" => "User/Recharge.html", //会员-充值中心
        "BILL" => "User/Bill.html", //会员-我的账单
        "BUSINESS" => "User/Business.html", //会员-我的店铺
        "CATEGORY" => "User/Category.html", //会员-商品分类
        "COMMODITY" => "User/Commodity.html", //会员-我的商品
        "CARD" => "User/Card.html", //会员-卡密管理
        "COUPON" => "User/Coupon.html", //会员-优惠卷管理
        "CASH" => "User/Cash.html", //会员-硬币兑现
        "CASH_RECORD" => "User/CashRecord.html", //会员-兑现记录
        "PERSONAL" => "User/Personal.html", //会员-个人资料
        "EMAIL" => "User/Email.html", //会员-邮箱
        "PHONE" => "User/Phone.html", //会员-手机
        "PASSWORD" => "User/Password.html", //会员-密码设置
        "ORDER" => "User/Order.html", //会员-密码设置
        "AGENT_MEMBER" => "Agent/Member.html", //推广代理-我的下级
    ];

}