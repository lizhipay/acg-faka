!function () {
    const table = new Table("/user/api/cash/record", "#cash-table");

    table.setColumns([
        {
            field: 'amount', title: '硬币', formatter: function (val, item) {
                return '<b style="color: green;">￥' + item.amount + '</b>';
            }
        }
        , {
            field: 'type', title: '类型', dict: "_cash_order_type"
        }
        , {
            field: 'card', title: '到账钱包', dict: "_cash_wallet_type"
        }
        , {
            field: 'status', title: '状态', dict: "_cash_order_status"
        }
        ,
        {
            field: 'message', title: 'MSG'
        }
        ,
        {
            field: 'cost', title: '手续费'
        }
        , {
            field: 'create_time', title: '提交时间'
        }
        , {
            field: 'arrive_time', title: '到账时间'
        }
    ]);


    table.setSearch([
        {
            title: "到账方式", name: "equal-card", type: "select", dict: "_cash_order_type"
        },
        {
            title: "钱包类型", name: "equal-type", type: "select", dict: "_cash_wallet_type"
        },
        {title: "提交时间", name: "between-create_time", type: "date"}
    ]);

    table.setState("status", "_cash_order_status");
    table.render();
}();