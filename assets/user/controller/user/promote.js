!function () {
    //推广链接:复制 + 二维码
    $('.clipboard').click(function () {
        util.copyTextToClipboard($(this).data("text"), () => {
            message.success("推广链接已复制");
        });
    });

    const $qr = $('#share-qrcode');
    if ($qr.length > 0 && typeof $qr.qrcode === "function") {
        $qr.qrcode({width: 108, height: 108, text: $qr.data("url")});
    }

    //商品预计收益表
    const table = new Table("/user/api/promote/data", "#promote-table");
    table.setColumns([
        {
            field: 'name', title: '商品', formatter: (_, __) => format.item(__)
        }
        , {
            field: 'race', title: '类别', formatter: _ => _ ? format.badge(_, "a-badge-info") : '<span style="opacity:.5;">标准</span>'
        }
        , {
            field: 'sku_count', title: 'SKU', formatter: (_, __) => {
                if (!_ || _ <= 0) {
                    return '-';
                }
                return `<a href="javascript:;" class="sku-detail" data-id="${__.id}" data-race="${__.race || ''}">${_} 组 <i class="fa-duotone fa-regular fa-circle-info"></i></a>`;
            }
        }
        , {
            field: 'guest_price', title: '游客成交价', formatter: _ => `￥${_}`
        }
        , {
            field: 'my_price', title: '我的拿货价', formatter: _ => `￥${_}`
        }
        , {
            field: 'profit', title: '预计收益', formatter: _ => format.money(_, parseFloat(_) < 0 ? "red" : "green")
        }
        , {
            field: 'rate', title: '收益率', formatter: _ => {
                const v = parseFloat(_);
                return format.badge(`${_}%`, v > 0 ? "a-badge-success" : (v < 0 ? "a-badge-danger" : "a-badge-dark"));
            }
        }
    ]);
    table.setSearch([
        {title: "商品名称", name: "search-name", type: "input"}
    ]);
    table.render();

    //SKU 明细弹层:每个选项的加价与收益影响
    $(document).off("click", ".sku-detail").on("click", ".sku-detail", function () {
        const id = $(this).data("id");
        const race = $(this).data("race");
        util.post('/user/api/promote/sku', {commodityId: id, race: race}, res => {
            const data = res.data;
            let html = `<div class="uc-skupop">`;
            html += `<div class="uc-skupop__meta">基准${data.race ? `（类别：${util.plainText(String(data.race))}）` : ''}预计收益：<b>￥${data.base_profit}</b></div>`;
            html += `<table class="uc-skupop__table"><thead><tr><td>SKU</td><td>选项</td><td>加价</td><td>游客价</td><td>拿货价</td><td>预计收益</td><td>收益变化</td></tr></thead><tbody>`;
            data.list.forEach(row => {
                const delta = parseFloat(row.delta);
                const deltaClass = delta > 0 ? 'up' : (delta < 0 ? 'down' : '');
                html += `<tr>
                    <td>${util.plainText(row.group)}</td>
                    <td>${util.plainText(row.option)}</td>
                    <td>+￥${row.premium}</td>
                    <td>￥${row.guest_price}</td>
                    <td>￥${row.my_price}</td>
                    <td><b>￥${row.profit}</b></td>
                    <td><span class="uc-skupop__delta ${deltaClass}">${row.delta}</span></td>
                </tr>`;
            });
            html += `</tbody></table></div>`;
            layer.open({
                type: 1,
                title: `${util.icon("fa-duotone fa-regular fa-layer-group")} SKU 收益明细`,
                area: util.isPc() ? ['680px', 'auto'] : ["100%", "100%"],
                content: html
            });
        });
    });
}();
