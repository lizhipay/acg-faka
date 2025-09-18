!function () {
    const table = new Table("/user/api/purchaseRecord/data", "#bill-table");

    table.setColumns([
        {
            field: 'trade_no', title: '订单号'
        }
        , {
            field: 'commodity', title: '商品', formatter: format.item
        }
        , {
            field: 'sku', title: 'SKU', formatter: (_, __) => {
                let d = ``;

                if (__.race) {
                    d += format.badge(`分类:${__.race}`, "a-badge-info");
                }

                if (!util.isEmptyOrNotJson(__.sku)) {
                    for (const skuKey in __.sku) {
                        d += format.badge(`${skuKey}:${__.sku[skuKey]}`, "a-badge-info");
                    }
                }

                return d ? format.badgeGroup(d) : "-";
            }
        }
        , {
            field: 'card_num', title: '数量'
        }
        , {
            field: 'amount', title: '金额', formatter: _ => format.money(_, "green")
        }
        , {
            field: 'commodity.delivery_way', title: '发货方式', dict: "_order_delivery_way"
        }
        , {
            field: 'pay', title: '支付方式', formatter: format.pay
        }
        , {
            field: 'status', title: '付款状态', dict: "_order_status"
        }
        , {
            field: 'delivery_status', title: '发货状态', dict: "_order_delivery_status"
        }
        , {
            field: 'leave_message', title: '商家留言', formatter: function (val, item) {
                if (!item.commodity) {
                    return "-";
                }

                if (item.status != 1 || !item.commodity.leave_message) {
                    return "-";
                }

                return '<span style="padding: 5px;">' + item.commodity.leave_message + '</span>';
            }
        }
        , {
            field: 'secret', title: '宝贝信息', formatter: function (val, item) {
                if (!item.secret) {
                    return "-";
                }

                if (item.status != 1) {
                    return '-';
                }

                return '<textarea class="secret">' + item.secret + '</textarea><div><a href="/user/personal/secretDownload?id=' + item.id + '" target="_blank" class="secret-download"><i class="layui-icon">&#xe601;</i> 下载宝贝到本地(TXT)</a></div>';
            }
        }

    ]);
    table.enableCardView();

    table.setSearch([
        {title: "订单号", name: "equal-trade_no" , default: util.getParam('tradeNo'), type: "input"},
        {title: "下单时间", name: "between-create_time", type: "date"}
    ]);

    table.setState("status", "_order_status");
    table.render();
}();