!function () {

    const table = new Table("/admin/api/rechargeOrder/data", "#recharge-order-table");
    table.setColumns([
        {checkbox: true}
        , {
            field: 'trade_no', title: '订单号'
        }
        , {
            field: 'user', title: '会员', formatter: format.user
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
        $('.order_count').html(_.data.total);
        $('.order_amount').html(_.data.order_amount);
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
}();