!function () {
    const table = new Table("/user/api/bill/data", "#bill-table");
    table.setColumns([
        {
            field: 'amount',
            title: '金额',
            sort: true,
            formatter: (_, __) => __.type === 0 ? format.money(_, "red") : format.money(_, "green")
        }
        , {
            field: 'balance',  sort: true, title: '余额', formatter: _ => format.money(_, "primary")
        }
        , {
            field: 'type', title: '收支类型', dict: "_bill_status"
        }
        , {
            field: 'currency', title: '货币类型', dict: "_bill_currency_type"
        }
        , {
            field: 'log', title: '交易信息'
        }
        , {
            field: 'create_time', title: '交易时间'
        }
    ]);


    table.setSearch([
        {
            title: "支出/收入", name: "equal-type", type: "select", dict: [
                {id: 0, name: "支出"},
                {id: 1, name: "收入"},
            ]
        }, {
            title: "钱包类型", name: "equal-currency", type: "select", dict: [
                {id: 0, name: "余额"},
                {id: 1, name: "硬币"},
            ]
        },
        {title: "交易详情", name: "search-log", type: "input"},
        {title: "交易时间", name: "between-create_time", type: "date"}
    ]);

    table.setState("type", "_bill_status");
    table.render();
}();