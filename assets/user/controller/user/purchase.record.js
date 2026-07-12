!function () {
    const table = new Table("/user/api/purchaseRecord/data", "#bill-table");

    // 查看卡密弹窗(对标后台 trade/order.js:查看卡密):代码块 + 复制 + 下载
    const openSecret = (map) => {
        const secret = map.secret ?? '';
        const escaped = String(secret).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        layer.open({
            type: 1,
            title: `${util.icon("fa-duotone fa-regular fa-eye")} 查看卡密`,
            area: util.isPc() ? '480px' : ["100%", "100%"],
            shadeClose: true,
            content: `<div class="md-secret"><div class="md-secret__code">${escaped}</div><div class="md-secret__bar"><button type="button" class="md-secret__btn" data-act="copy">${util.icon("fa-duotone fa-regular fa-copy")} 复制</button><button type="button" class="md-secret__btn md-secret__btn--primary" data-act="download">${util.icon("fa-duotone fa-regular fa-download")} 下载</button></div></div>`,
            success: (layero) => {
                layero.find('[data-act="copy"]').on('click', () => {
                    util.copyTextToClipboard(secret, () => message.success('卡密已复制'));
                });
                layero.find('[data-act="download"]').on('click', () => {
                    const blob = new Blob([secret], {type: 'text/plain;charset=utf-8'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `卡密_${map.trade_no || 'export'}.txt`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                });
            }
        });
    };

    table.setColumns([
        {field: 'trade_no', title: '订单号'}
        , {field: 'commodity', title: '商品', formatter: format.item}
        , {
            field: 'sku', title: '类别/SKU', formatter: (_, __) => {
                const race = (__.race && __.race !== '-') ? __.race : '';
                const hasSku = !util.isEmptyOrNotJson(__.sku);
                if (!race && !hasSku) return '-';
                let rows = `<div class="md-pair__row"><span class="md-pair__k">类别</span><span class="md-pair__v">${race || '-'}</span></div>`;
                if (hasSku) {
                    let badges = '';
                    for (const x in __.sku) badges += format.badge(`${x}: ${__.sku[x]}`, "a-badge-info");
                    rows += `<div class="md-pair__row"><span class="md-pair__k">SKU</span><span class="md-pair__v">${format.badgeGroup(badges)}</span></div>`;
                }
                return `<div class="md-pair">${rows}</div>`;
            }
        }
        , {field: 'card_num', title: '数量'}
        , {field: 'amount', title: '金额', formatter: _ => format.money(_, "green")}
        , {field: 'pay', title: '支付方式', formatter: format.pay}
        , {field: 'status', title: '付款状态', dict: "_order_status"}
        , {field: 'delivery_status', title: '发货状态', dict: "_order_delivery_status"}
        , {
            field: 'secret', title: '操作', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看卡密",
                    show: _ => _.status == 1 && !!_.secret,
                    click: (event, value, map, index) => openSecret(map)
                }
            ]
        }
    ]);

    table.setSearch([
        {title: "订单号", name: "equal-trade_no", default: util.getParam('tradeNo'), type: "input"},
        {title: "下单时间", name: "between-create_time", type: "date"}
    ]);

    table.setState("status", "_order_status");
    table.render();
}();
