!function () {

    const table = new Table("/admin/api/rechargeOrder/data", "#recharge-order-table");
    table.setColumns([
        {checkbox: true}
        , {
            field: 'trade_no', title: '订单号'
        }
        , {
            field: 'user', title: '会员', formatter: (_, __) => mdUserCell(_)
        }
        , {
            field: 'amount', title: '充值金额', formatter: _ => format.money(_, "green")
        }
        , {
            field: 'pay', title: '支付方式', formatter: format.pay
        }
        , {
            field: 'create_time', title: '下单时间'
        }
        , {
            field: 'create_ip', title: '客户IP'
        }
        , {
            field: 'status', title: '支付状态', dict: '_recharge_order_status'
        }
        , {
            field: 'create_time', title: '支付时间'
        }
        , {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-circle-check',
                    class: "text-success",
                    title: "补单",
                    show: _ => _.status === 0,
                    click: (event, value, row, index) => {
                        message.ask("您正在进行补单操作，是否继续？", () => {
                            util.post("/admin/api/rechargeOrder/success", {id: row.id}, res => {
                                message.success("补单成功")
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        }
    ]);

    table.onResponse(_ => {
        $('.order_count').html(Number(_.data.total || 0).toLocaleString('en-US'));
        $('.order_amount').html('￥' + Number(_.data.order_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    });

    table.setSearch([
        {title: "订单号", name: "equal-trade_no", type: "input"},
        {title: "支付方式", name: "equal-pay_id", type: "select", dict: "pay->id!=1,id,name"},
        {title: "IP地址", name: "equal-create_ip", type: "input"},
        {title: "搜索会员", name: "equal-user_id", type: "remoteSelect", dict: "user,id,username"},
        {title: "下单时间", name: "between-create_time", type: "date"},
    ]);

    table.setState("status", "_recharge_order_status");

    table.render();

    $('.clear').click(() => {
        util.get("/admin/api/rechargeOrder/clear", () => {
            message.success("已清理");
            table.refresh();
        });
    });

    $('.btn-app-export').click(function () {

        component.popup({
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-file-export") + " 导出充值订单",
                    form: [
                        {
                            name: "custom",
                            type: "custom",
                            complete: (obj, dom) => {
                                dom.html('<div style="margin-bottom: 25px;color: #27bd27;font-weight: bolder;">导出程序将根据您通过查询功能筛选出的充值订单进行导出。如果您填写了导出数量，将导出指定数量的充值订单；如果您未填写数量，则将导出您筛选的全部充值订单。</div>');
                            }
                        },
                        {
                            title: "导出数量",
                            name: "export_num",
                            type: "input",
                            placeholder: "导出数量，填写0或不填表示全部导出。"
                        },
                        {
                            title: "导出后执行",
                            name: "export_status",
                            type: "radio",
                            dict: [
                                {id: 0, name: "不执行任何操作"},
                                {id: 1, name: "删除导出的充值订单（高危/物理删除）"},
                            ]
                        }
                    ]
                }
            ],
            height: "auto",
            width: "580px",
            assign: {},
            confirmText: "开始导出",
            maxmin: false,
            autoPosition: true,
            submit: (data, index) => {
                let searchData = table.getSearchData();
                let state = table.getState();
                let query = util.objectToQueryString(Object.assign(searchData, data));

                layer.close(index);

                let url = "/admin/api/rechargeOrder/export?" + query + "&equal-" + state.field + "=" + state.value;
                if (data.export_status == 1) {
                    message.dangerPrompt("您正在执行高风险的充值订单导出操作，需要注意此操作是物理删除，绝对上的无法恢复。", "我确认导出并删除充值订单", () => {
                        window.open(url);
                    });
                } else {
                    window.open(url);
                }
            },
        });
    });
}();