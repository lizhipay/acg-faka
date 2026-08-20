const _Dict = new class _Dict extends _DictUtil {
    constructor() {
        super();
        this.dictUrl = "/admin/api/dict/get?dict=";
        this.data = {
            "_common_eye": [
                {
                    id: 0,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-eye-slash"></i> ${i18n('隐藏')}`, "a-badge-danger")
                },
                {id: 1, "name": format.badge(`<i class="fa-duotone fa-regular fa-eye"></i> ${i18n('显示')}`, "a-badge-success")}
            ],
            "_common_status": [
                {id: 1, "name": format.badge(i18n("已启用"), "a-badge-success")},
                {id: 0, "name": format.badge(i18n("未启用"), "a-badge-danger")}
            ],
            "_common_device": [
                {id: 0, "name": `<i class="fa-duotone fa-regular fa-window"></i> PC`},
                {id: 1, "name": `<i class="fa-duotone fa-regular fa-robot"></i> ${i18n('安卓')}`},
                {id: 2, "name": `<i class="fa-duotone fa-regular fa-apple-whole"></i> IOS`},
                {id: 3, "name": `<i class="fa-duotone fa-regular fa-tablet"></i> iPad`},
            ],
            "_user_status": [
                {"id": 1, "name": format.badge(i18n("正常"), "a-badge-success")},
                {"id": 0, "name": format.badge(i18n("封禁"), "a-badge-danger")}
            ],
            "_recharge_order_status": [
                {
                    "id": 1,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-circle-check"></i> ${i18n('已支付')}`, "a-badge-success")
                },
                {
                    "id": 0,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-xmark"></i> ${i18n('未支付')}`, "a-badge-danger")
                }
            ],
            "_bill_status": [
                {"id": 1, "name": format.badge(`${i18n('收入')}`, "a-badge-success")},
                {"id": 0, "name": format.badge(`${i18n('支出')}`, "a-badge-danger")}
            ],
            "_bill_currency_type": [
                {
                    "id": 1,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-circle-yen"></i> ${i18n('硬币')}`, "a-badge-success")
                },
                {
                    "id": 0,
                    "name": format.badge(`<i class="fa-duotone fa-regular fa-wallet"></i> ${i18n('余额')}`, "a-badge-primary")
                }
            ],
            "_business_level_status": [
                {id: 0, "name": format.badge(i18n("关闭"), "a-badge-danger")},
                {id: 1, "name": format.badge(i18n("启用"), "a-badge-success")}
            ],
            "_cash_status": [
                {id: 0, "name": format.badge(i18n("等待处理"), "a-badge-warning")},
                {id: 1, "name": format.badge(i18n("成功"), "a-badge-success")},
                {id: 2, "name": format.badge(i18n("失败"), "a-badge-danger")},
            ],
            "_cash_type": [
                {id: 0, "name": format.badge(i18n("自动结算"), "a-badge-success")},
                {id: 1, "name": format.badge(i18n("手动提交"), "a-badge-primary")},
            ],
            "_cash_card": [
                {id: 0, "name": format.badge(i18n("支付宝"), "a-badge-primary")},
                {id: 1, "name": format.badge(i18n("微信"), "a-badge-success")},
                {id: 3, "name": format.badge("USDT(TRC20)", "a-badge-success")},
                {id: 2, "name": format.badge(i18n("钱包余额"), "a-badge-info")},
            ],
            "_ticket_status": [
                {id: 0, "name": format.badge(`<i class="fa-duotone fa-regular fa-clock"></i> ${i18n('待客服')}`, "a-badge-warning")},
                {id: 1, "name": format.badge(`<i class="fa-duotone fa-regular fa-comment"></i> ${i18n('等待用户')}`, "a-badge-primary")},
                {id: 2, "name": format.badge(`<i class="fa-duotone fa-regular fa-circle-check"></i> ${i18n('已解决')}`, "a-badge-success")},
                {id: 3, "name": format.badge(`<i class="fa-duotone fa-regular fa-lock-keyhole"></i> ${i18n('已关闭')}`, "a-badge-dark")},
            ],
            "_ticket_type": [
                {id: 0, "name": format.badge(i18n("售前咨询"), "a-badge-primary")},
                {id: 1, "name": format.badge(i18n("售后支持"), "a-badge-info")},
            ],
            "_ticket_priority": [
                {id: 0, "name": format.badge(i18n("低优先级"), "a-badge-light")},
                {id: 1, "name": format.badge(i18n("中优先级"), "a-badge-warning")},
                {id: 2, "name": format.badge(i18n("高优先级"), "a-badge-danger")},
            ],
            "_contact_type": [
                {id: 0, "name": format.color(i18n("任意"), "#de27ba")},
                {id: 1, "name": format.color(i18n("手机"), "green")},
                {id: 2, "name": format.color(i18n("邮箱"), "blue")},
                {id: 3, "name": format.color("QQ", "#f3e343")},
            ],
            "_commodity_status": [
                {id: 1, "name": format.badge(i18n("已上架"), "a-badge-success")},
                {id: 0, "name": format.badge(i18n("已下架"), "a-badge-danger")}
            ],
            "_commodity_api_status": [
                {id: 1, "name": format.color(i18n("已启用"), "green")},
                {id: 0, "name": format.color(i18n("未启用"), "red")}
            ],
            "_commodity_delivery_way": [
                {id: 0, "name": format.color(i18n("自动发货"), "green")},
                {id: 1, "name": format.color(i18n("手动/插件发货"), "blue")},
            ],
            "_commodity_delivery_auto_mode": [
                {id: 0, "name": format.color(i18n("旧卡先发"), "green")},
                {id: 1, "name": format.color(i18n("随机发卡"), "blue")},
                {id: 2, "name": format.color(i18n("新卡先发"), "red")},
            ],
            "_card_status": [
                {id: 0, "name": format.badge(i18n("未出售"), "a-badge-success")},
                {id: 1, "name": format.badge(i18n("已出售"), "a-badge-dark")},
                {id: 2, "name": format.badge(i18n("已锁定"), "a-badge-danger")},
            ],
            "_coupon_mode": [
                {id: 0, "name": format.badge(i18n("金额"), "a-badge-success")},
                {id: 1, "name": format.badge(i18n("百分比"), "a-badge-primary")},
            ],
            "_coupon_status": [
                {id: 0, "name": format.badge(i18n("正常使用"), "a-badge-success")},
                {id: 1, "name": format.badge(i18n("已失效"), "a-badge-dark")},
                {id: 2, "name": format.badge(i18n("已锁定"), "a-badge-danger")},
            ],
            "_order_status": [
                {id: 1, "name": format.badge(i18n("已支付"), "a-badge-success")},
                {id: 0, "name": format.badge(i18n("未支付"), "a-badge-danger")},
            ],
            "_order_delivery_status": [
                {id: 1, "name": format.badge(i18n("已发货"), "a-badge-success")},
                {id: 0, "name": format.badge(i18n("未发货"), "a-badge-danger")},
            ],
            "_order_delivery_way": [
                {id: 0, "name": format.badge(i18n("自动发货"), "a-badge-success")},
                {id: 1, "name": format.badge(i18n("手动/插件发货"), "a-badge-primary")},
            ],
            "_shared_type": [
                {id: 0, "name": format.badge(i18n("异次元(V3.1.2 重构后全新版)"), "a-badge-success")},
                {id: 2, "name": format.badge(i18n("异次元(V3.1.1 之前旧版)"), "a-badge-primary")},
                {id: 1, "name": format.badge(i18n("萌次元(V4.0)"), "a-badge-primary")}
            ],
            "_manage_type": [
                {id: 1, name: "<b style='color: #d0b728;'>" + i18n('超级管理员') + "</b>"},
                {id: 2, name: "<b style='color: #3d84ef;'>" + i18n('白班') + "</b>"},
                {id: 3, name: "<b style='color: #3d84ef;'>" + i18n('夜班') + "</b>"},
            ],
            "_pay_equipment": [
                {
                    id: 0,
                    name: `<span class="a-badge  a-badge-success"><i class="fa-duotone fa-regular fa-earth-europe text-success"></i> ${i18n('通用')}</span>`
                },
                {
                    id: 1,
                    name: `<span class="a-badge  a-badge-info"><i class="fa-duotone fa-regular fa-mobile-signal text-info"></i> ${i18n('移动端')}</span>`
                },
                {
                    id: 2,
                    name: `<span class="a-badge  a-badge-primary"><i class="fa-duotone fa-regular fa-desktop text-primary"></i> PC${i18n('端')}</span>`
                },
                {
                    id: 3,
                    name: `<span class="a-badge  a-badge-primary"><i class="fa-duotone fa-regular fa-comment text-primary"></i> ${i18n('微信')}</span>`
                },
            ],
            "_store_plugin_type": [
                {
                    id: 0,
                    name: `<span class='a-badge a-badge-primary'><i class="fa-duotone fa-regular fa-puzzle-piece-simple"></i> ${i18n('通用扩展')}</span>`
                },
                {
                    id: 1,
                    name: `<span class='a-badge a-badge-success'><i class="fa-duotone fa-regular fa-envelope-open-dollar"></i> ${i18n('支付扩展')}</span>`
                },
                {
                    id: 2,
                    name: `<span class='a-badge a-badge-info'><i class="fa-duotone fa-regular fa-browser"></i> ${i18n('网站模版')}</span>`
                },
            ],
            "_store_plugin_owner": [
                {id: 7, name: `${i18n('企业版应用')}`},
                {id: 1, name: `${i18n('官方应用')}`},
                {id: 2, name: `${i18n('第三方应用')}`},
                {id: 4, name: `${i18n('通用插件')}`},
                {id: 5, name: `${i18n('支付接口')}`},
                {id: 6, name: `${i18n('主题')}/${i18n('模版')}`},
                {id: 3, name: `${i18n('免费应用')}`}
            ],
            "_developer_plugin_status": [
                {
                    id: 0,
                    name: `<span class="a-badge a-badge-warning"><i class="fa-duotone fa-regular fa-clock-one-thirty"></i> ${i18n('开发中')}</span>`
                },
                {
                    id: 1,
                    name: `<span class="a-badge a-badge-success"><i class="fa-duotone fa-regular fa-badge-check"></i> ${i18n('已上架')}</span>`
                },
                {
                    id: 2,
                    name: `<span class="a-badge a-badge-dark"><i class="fa-duotone fa-regular fa-badge-check"></i> ${i18n('审核不通过')}</span>`
                },
                {
                    id: 3,
                    name: `<span class="a-badge a-badge-danger"><i class="fa-duotone fa-regular fa-badge-check"></i> ${i18n('审核中')}</span>`
                }
            ],
            //更新包审核状态：已上架插件提交更新后的审核进度（开发者中心搜索用）
            "_developer_plugin_audit_review_status": [
                {id: 0, name: `${i18n('暂未提交')}`},
                {id: 1, name: `${i18n('审核中')}`},
                {id: 2, name: `${i18n('审核通过')}`},
                {id: 3, name: `${i18n('驳回申请')}`}
            ]
        };
    }
}
