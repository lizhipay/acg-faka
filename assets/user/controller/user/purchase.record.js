!function () {
    const table = new Table("/user/api/purchaseRecord/data", "#bill-table");

    const esc = (value) => String(value ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const subTime = (value) => {
        const text = String(value ?? '').trim();
        if (text === '' || text === '-' || text.startsWith('0000-00-00')) {
            return '';
        }
        return `<div class="md-pair__row"><span class="md-pair__v md-pair__v--muted" style="font-size:11px">${esc(text)}</span></div>`;
    };

    // 查看卡密弹窗(对标后台 trade/order.js:查看卡密):代码块 + 复制 + 下载
    const openSecret = (map) => {
        const secret = map.secret ?? '';
        const escaped2 = v => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const escaped = escaped2(secret);
        const leaveMessage = map?.commodity?.leave_message ? `<div style="margin-top:12px">${escaped2(map.commodity.leave_message)}</div>` : '';
        layer.open({
            type: 1,
            title: `${util.icon("fa-duotone fa-regular fa-eye")} ${i18n('查看卡密')}`,
            area: util.isPc() ? '480px' : ["100%", "100%"],
            shadeClose: true,
            content: `<div class="md-secret"><div class="md-secret__code">${escaped}</div><div class="md-secret__bar"><button type="button" class="md-secret__btn" data-act="copy">${util.icon("fa-duotone fa-regular fa-copy")} ${i18n('复制')}</button><button type="button" class="md-secret__btn md-secret__btn--primary" data-act="download">${util.icon("fa-duotone fa-regular fa-download")} ${i18n('下载')}</button></div>${leaveMessage}</div>`,
            success: (layero) => {
                layero.find('[data-act="copy"]').on('click', () => {
                    util.copyTextToClipboard(secret, () => message.success('卡密已复制'));
                });
                layero.find('[data-act="download"]').on('click', () => {
                    const blob = new Blob([secret], {type: 'text/plain;charset=utf-8'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `${i18n('卡密')}_${map.trade_no || 'export'}.txt`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                });
            }
        });
    };

    table.setColumns([
        {
            field: 'trade_no', title: '订单号', formatter: (value, row) => {
                const no = esc(value) || '-';
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__v">${no}</span></div>${subTime(row?.create_time)}</div>`;
            }
        }
        , {field: 'commodity', title: '商品', formatter: format.item}
        , {
            field: 'sku', title: '类别/SKU', formatter: (_, __) => {
                const race = (__.race && __.race !== '-') ? __.race : '';
                const hasSku = !util.isEmptyOrNotJson(__.sku);
                if (!race && !hasSku) return '-';
                let rows = `<div class="md-pair__row"><span class="md-pair__k">${i18n('类别')}</span><span class="md-pair__v">${i18n(race) || '-'}</span></div>`;
                if (hasSku) {
                    let badges = '';
                    for (const x in __.sku) badges += format.badge(`${i18n(x)}: ${i18n(__.sku[x])}`, "a-badge-info");
                    rows += `<div class="md-pair__row"><span class="md-pair__k">SKU</span><span class="md-pair__v">${format.badgeGroup(badges)}</span></div>`;
                }
                return `<div class="md-pair">${rows}</div>`;
            }
        }
        , {
            field: 'amount', title: '数量/金额', formatter: (value, row) => {
                const num = Number(row?.card_num ?? 0) || 0;
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__k">${i18n('数量')}</span><span class="md-pair__v">${num}</span></div>`
                    + `<div class="md-pair__row"><span class="md-pair__k">${i18n('金额')}</span><span class="md-pair__v">${format.money(value, "green")}</span></div></div>`;
            }
        }
        , {field: 'pay', title: '支付方式', formatter: format.pay}
        , {
            field: 'status', title: '付款状态', formatter: (value, row) => {
                const badge = _Dict.result("_order_status", value) ?? esc(value);
                const paid = Number(value) === 1 ? subTime(row?.pay_time) : '';
                return `<div class="md-pair"><div class="md-pair__row">${badge}</div>${paid}</div>`;
            }
        }
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
