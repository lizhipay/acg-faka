const _Dict = new class _Dict extends _DictUtil {
    constructor() {
        super();
        this.dictUrl = "/admin/api/dict/get?dict=";
        this.data = {
            "_common_eye": [
                {
                    id: 0,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-eye-slash"></i> 隐藏`, "a-badge-danger")
                },
                {id: 1, "name": format.badge(`<i class="fa-duotone fa-regular fa-eye"></i> 显示`, "a-badge-success")}
            ],
            "_common_status": [
                {id: 1, "name": format.badge("已启用", "a-badge-success")},
                {id: 0, "name": format.badge("未启用", "a-badge-danger")}
            ],
            "_common_device": [
                {id: 0, "name": `<i class="fa-duotone fa-regular fa-window"></i> PC`},
                {id: 1, "name": `<i class="fa-duotone fa-regular fa-robot"></i> 安卓`},
                {id: 1, "name": `<i class="fa-duotone fa-regular fa-apple-whole"></i> IOS`},
                {id: 1, "name": `<i class="fa-duotone fa-regular fa-tablet"></i> iPad`},
            ],
            "_user_status": [
                {
                    "id": 1,
                    "name": `<span class="text-success"><i class="fa-duotone fa-regular fa-circle-check text-success"></i> 正常</span>`
                },
                {
                    "id": 0,
                    "name": `<span class="text-danger"><i class="fa-duotone fa-regular fa-circle-xmark text-danger"></i> 封禁</span>`
                }
            ],
            "_recharge_order_status": [
                {
                    "id": 1,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-circle-check"></i> 已支付`, "a-badge-success")
                },
                {
                    "id": 0,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-xmark"></i> 未支付`, "a-badge-danger")
                }
            ],
            "_bill_status": [
                {"id": 1, "name": format.badge(`收入`, "a-badge-success")},
                {"id": 0, "name": format.badge(`支出`, "a-badge-danger")}
            ],
            "_bill_currency_type": [
                {
                    "id": 1,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-circle-yen"></i> 硬币`, "a-badge-success")
                },
                {
                    "id": 0,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-wallet"></i> 余额`, "a-badge-primary")
                }
            ],
            "_business_level_status": [
                {id: 0, "name": format.badge("关闭", "a-badge-danger")},
                {id: 1, "name": format.badge("启用", "a-badge-success")}
            ],
            "_cash_status": [
                {id: 0, "name": format.badge("等待处理", "a-badge-warning")},
                {id: 1, "name": format.badge("成功", "a-badge-success")},
                {id: 2, "name": format.badge("失败", "a-badge-danger")},
            ],
            "_cash_type": [
                {id: 0, "name": format.badge("自动结算", "a-badge-success")},
                {id: 1, "name": format.badge("手动提交", "a-badge-primary")},
            ],
            "_cash_card": [
                {id: 0, "name": format.badge("支付宝", "a-badge-primary")},
                {id: 1, "name": format.badge("微信", "a-badge-success")},
                {id: 2, "name": format.badge("钱包余额", "a-badge-info")},
            ],
            "_contact_type": [
                {id: 0, "name": format.color("任意", "#de27ba")},
                {id: 1, "name": format.color("手机", "green")},
                {id: 2, "name": format.color("邮箱", "blue")},
                {id: 3, "name": format.color("QQ", "#f3e343")},
            ],
            "_commodity_status": [
                {id: 1, "name": format.badge("已上架", "a-badge-success")},
                {id: 0, "name": format.badge("已下架", "a-badge-danger")}
            ],
            "_commodity_api_status": [
                {id: 1, "name": format.color("已启用", "green")},
                {id: 0, "name": format.color("未启用", "red")}
            ],
            "_commodity_delivery_way": [
                {id: 0, "name": format.color("自动发货", "green")},
                {id: 1, "name": format.color("手动/插件发货", "blue")},
            ],
            "_commodity_delivery_auto_mode": [
                {id: 0, "name": format.color("旧卡先发", "green")},
                {id: 1, "name": format.color("随机发卡", "blue")},
                {id: 2, "name": format.color("新卡先发", "red")},
            ],
            "_card_status": [
                {id: 0, "name": format.badge("未出售", "a-badge-success")},
                {id: 1, "name": format.badge("已出售", "a-badge-dark")},
                {id: 2, "name": format.badge("已锁定", "a-badge-danger")},
            ],
            "_coupon_mode": [
                {id: 0, "name": format.badge("金额", "a-badge-success")},
                {id: 1, "name": format.badge("百分比", "a-badge-primary")},
            ],
            "_coupon_status": [
                {id: 0, "name": format.badge("正常使用", "a-badge-success")},
                {id: 1, "name": format.badge("已失效", "a-badge-dark")},
                {id: 2, "name": format.badge("已锁定", "a-badge-danger")},
            ],
            "_order_status": [
                {id: 1, "name": format.badge("已支付", "a-badge-success")},
                {id: 0, "name": format.badge("未支付", "a-badge-danger")},
            ],
            "_order_delivery_status": [
                {id: 1, "name": format.badge("已发货", "a-badge-success")},
                {id: 0, "name": format.badge("未发货", "a-badge-danger")},
            ],
            "_order_delivery_way": [
                {id: 0, "name": format.badge("自动发货", "a-badge-success")},
                {id: 1, "name": format.badge("手动/插件发货", "a-badge-primary")},
            ],
            "_shared_type": [
                {id: 0, "name": format.badge("异次元(V3.0)", "a-badge-success")},
                {id: 1, "name": format.badge("萌次元(V4.0)", "a-badge-primary")},
            ],
            "_manage_type": [
                {id: 1, name: "<b style='color: #d0b728;'>超级管理员</b>"},
                {id: 2, name: "<b style='color: #3d84ef;'>白班</b>"},
                {id: 3, name: "<b style='color: #3d84ef;'>夜班</b>"},
            ],
            "_pay_equipment" : [
                {id: 0, name: `<span class="a-badge  a-badge-success"><i class="fa-duotone fa-regular fa-earth-europe text-success"></i> 通用</span>`},
                {id: 1, name: `<span class="a-badge  a-badge-info"><i class="fa-duotone fa-regular fa-mobile-signal text-info"></i> 移动端</span>`},
                {id: 2, name: `<span class="a-badge  a-badge-primary"><i class="fa-duotone fa-regular fa-desktop text-primary"></i> PC端</span>`},
                {id: 3, name: `<span class="a-badge  a-badge-primary"><i class="fa-duotone fa-regular fa-comment text-primary"></i> 微信</span>`},
            ],
            "_store_plugin_type" : [
                {id : 0 , name: `<span class='a-badge a-badge-primary'><i class="fa-duotone fa-regular fa-puzzle-piece-simple"></i> 通用扩展</span>`},
                {id : 1 , name: `<span class='a-badge a-badge-success'><i class="fa-duotone fa-regular fa-envelope-open-dollar"></i> 支付扩展</span>`},
                {id : 2 , name: `<span class='a-badge a-badge-info'><i class="fa-duotone fa-regular fa-browser"></i> 网站模版</span>`},
            ],
            "_store_plugin_owner" : [
                {id : 7 , name: `企业版应用`},
                {id : 1 , name: `官方应用`},
                {id : 2 , name: `第三方应用`},
                {id : 4 , name: `通用插件`},
                {id : 5 , name: `支付接口`},
                {id : 6 , name: `主题/模版`},
                {id : 3 , name: `免费应用`},
            ],
            "_developer_plugin_status" : [
                {id : 0 , name : `<span class="a-badge a-badge-warning"><i class="fa-duotone fa-regular fa-clock-one-thirty"></i> 开发中</span>`},
                {id : 1 , name : `<span class="a-badge a-badge-success"><i class="fa-duotone fa-regular fa-badge-check"></i> 已上架</span>`},
                {id : 2 , name : `<span class="a-badge a-badge-dark"><i class="fa-duotone fa-regular fa-badge-check"></i> 审核不通过</span>`},
                {id : 3 , name : `<span class="a-badge a-badge-danger"><i class="fa-duotone fa-regular fa-badge-check"></i> 审核中</span>`}
            ]
        };
    }
}