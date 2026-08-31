!function () {
    const namespace = '.mdUserBillController';
    let controllerActive = true;
    let table;

    if (typeof window.__mdUserBillDestroy === 'function') window.__mdUserBillDestroy();

    table = new Table("/admin/api/bill/data", "#bill-table");
    table.setColumns([
        {
            field: 'owner', title: '会员', formatter: (_, __) => mdUserCell(_)
        }
        , {
            field: 'amount',
            title: '金额',
            formatter: (_, __) => __.type === 0 ? format.money(_, "red") : format.money(_, "green")
        }
        , {
            field: 'balance', title: '余额', formatter: _ => format.money(_, "primary")
        }
        , {
            field: 'type', title: '收支类型' , dict: "_bill_status"
        }
        , {
            field: 'currency', title: '货币类型' , dict: "_bill_currency_type"
        }
        , {
            field: 'log', title: '交易信息'
        }
        , {
            field: 'create_time', title: '交易时间'
        }
    ]);



    table.setSearch([
        {title: "搜索会员", name: "equal-owner", type: "remoteSelect" , dict: "user,id,username"},
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
        {title: "交易详情", name: "search-log", type: "input", inputmode: 'search', enterkeyhint: 'search'},
        {title: "交易时间", name: "between-create_time", type: "date"}
    ]);
    table.setState("type", "_bill_status");



    table.render();

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdUserBillDestroy === destroy) delete window.__mdUserBillDestroy;
    }

    window.__mdUserBillDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
