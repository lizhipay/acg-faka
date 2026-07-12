!function () {
    // 查看卡密弹窗(复用共享 .md-secret,与 purchase.record.js / 后台 trade/order.js 同源)
    const openSecret = (secret, trade) => {
        secret = secret ?? '';
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
                    a.download = `卡密_${trade || 'export'}.txt`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                });
            }
        });
    };

    // 最近购买 = 与「购买记录」完全同款表格(同数据接口/同列/同 formatter/同查看卡密),
    // 只取最近 5 条,不要搜索栏、状态切换、分页条(卡头已有「全部记录」入口)
    const table = new Table("/user/api/purchaseRecord/data", "#recent-buy-table");

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
                    click: (event, value, map, index) => openSecret(map.secret, map.trade_no)
                }
            ]
        }
    ]);

    table.setPagination(5, [5]);
    table.render();
}();
