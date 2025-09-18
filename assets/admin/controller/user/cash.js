!function () {

    const table = new Table("/admin/api/cash/data", "#cash-table");
    const cashAccountHtml = `<div style="padding: 10px;" class="more-table"><table class="layui-table"><tbody><tr><td colspan="2" style="text-align: center;"><img src="[avatar]" style="height: 80px;width:80px;border-radius: 100%;box-shadow: 1px 1px 10px 1px #ed9b9bb3;"></td></tr><tr><td>提现用户</td><td>[username]</td></tr><tr><td>提现金额</td><td>[amount]</td></tr><tr><td>收款方式</td><td>[card]</td></tr><tr><td>收款人</td><td>[nicename]</td></tr><tr><td>收款账号</td><td>[account]</td></tr></tbody></table></div>`;

    table.setColumns([
        {
            field: 'user', title: '会员', formatter: format.user
        }
        , {
            field: 'amount', title: '提现金额', formatter: _ => format.money(_, "green")
        }
        , {
            field: 'type', title: '结算类型', dict: "_cash_type"
        }
        , {
            field: 'card', title: '收款方式', dict: "_cash_card"
        }
        , {
            field: 'cost', title: '手续费', formatter: _ => format.money(_, "red")
        }
        , {
            field: 'status', title: '状态', dict: "_cash_status"
        }
        , {
            field: 'message', title: 'MSG'
        }
        , {
            field: 'create_time', title: '提现时间'
        }
        , {
            field: 'arrive_time', title: '处理时间'
        }
        , {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-circle-check',
                    class: "text-success",
                    title: "打款",
                    show: _ => _.status === 0,
                    click: (event, value, map, index) => {
                        layer.open({
                            type: 1,
                            title: '<i class="layui-icon">&#xe600;</i> CASH',
                            content: cashAccountHtml
                                .replace("[amount]", '<b style="color: green;">¥ ' + map.amount + '</b>')
                                .replace("[card]", map.card == 0 ? "支付宝" : "微信")
                                .replace("[create_time]", map.create_time)
                                .replace("[avatar]", map.user.avatar ? map.user.avatar : '/favicon.ico')
                                .replace("[username]", map.user.username)
                                .replace("[nicename]", map.user.nicename)
                                .replace("[account]", map.card == 0 ? map.user.alipay : "<div class='wx_qrcode'></div>")
                            ,
                            area: util.isPc() ? "420px" : ["100%", "100%"],
                            btn: ['<i class="fa-duotone fa-regular fa-sack-dollar"></i> 已打款', '<i class="fa-duotone fa-regular fa-xmark"></i> 取消'],
                            success: () => {
                                if (map.card == 1 && map?.user?.wechat) {
                                    $('.wx_qrcode').qrcode({
                                        render: "canvas",
                                        width: 100,
                                        height: 100,
                                        text: map.user.wechat
                                    });
                                }
                            },
                            yes: () => {
                                util.post("/admin/api/cash/decide", {
                                    id: map.id,
                                    status: 0
                                }, res => {
                                    table.refresh();
                                    message.success("成功");
                                    layer.closeAll();
                                });
                            }
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-circle-exclamation',
                    class: "text-danger",
                    title: "驳回",
                    show: _ => _.status === 0,
                    click: (event, value, map, index) => {
                        message.prompt({
                            title: '<i class="fa-duotone fa-regular fa-circle-exclamation"></i> ' + i18n("驳回理由"),
                            width: 320,
                            inputAttributes: {
                                onpaste: 'return false',
                                oncopy: 'return false'
                            },
                            confirmButtonText: i18n("确认驳回"),
                            inputValidator: function (value) {
                                return !value && "请输入驳回内容";
                            }
                        }).then(res => {
                            if (res.isConfirmed === true) {
                                util.post("/admin/api/cash/decide", {
                                    id: map.id,
                                    status: 1,
                                    message: res.value
                                }, res => {
                                    table.refresh();
                                });
                            }
                        });
                    }
                },
            ]
        }
    ]);

    table.setSearch([
        {title: "搜索会员", name: "equal-user_id", type: "remoteSelect" , dict: "user,id,username"},
        {
            title: "类型", name: "equal-type", type: "select", dict: "_cash_type"
        }, {
            title: "收款方式", name: "equal-card", type: "select", dict: "_cash_card"
        }, {
            title: "状态", name: "equal-status", type: "select", dict: "_cash_status"
        },
        {title: "提交时间", name: "between-create_time", type: "date"},
    ]);

    table.setState("status", "_cash_status");

    table.render();


    $('.settlement').click(() => {
        layer.prompt({
            title: '请输入最低结算账户余额',
            formType: 0
        }, function (amount, index) {
            util.post("/admin/api/cash/settlement", {
                amount: amount
            }, res => {
                table.refresh();
                layer.close(index);
                message.success("操作成功");
            });
        });
    });
}();