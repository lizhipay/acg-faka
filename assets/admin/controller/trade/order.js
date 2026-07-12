!function () {
    let table, _createForms = [], _createSearchs = [];

    table = new Table("/admin/api/order/data", "#order-table");

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/order/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: false,
                            name: "secret",
                            type: "textarea",
                            placeholder: "填写要发货的信息",
                            height: 300
                        }
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "580px",
            done: () => {
                table.refresh();
            }
        });
    }


    table.setColumns([
        {checkbox: true}
        , {
            field: 'trade_no', title: '订单号'
        }
        , {
            field: 'owner', title: '客户', formatter: (_, __) => mdUserCell(_)
        }
        , {
            field: 'commodity', title: '商品', formatter: (_, __) => {
                const c = _ || {};
                const cover = c.cover
                    ? `<img src="${c.cover}" class="md-commodity-cell__cover" alt="">`
                    : `<span class="md-commodity-cell__cover md-commodity-cell__cover--ph"><i class="fa-duotone fa-regular fa-image"></i></span>`;
                const ownerObj = __.user || __.substation_user;
                const owner = (ownerObj && ownerObj.username) ? ownerObj.username : '主站';
                return `<div class="md-commodity-cell md-commodity-cell--sm">${cover}<div class="md-commodity-cell__text"><span class="md-commodity-cell__name">${c.name || ''}</span><span class="md-commodity-cell__sub">${owner}</span></div></div>`;
            }
        }
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
        , {
            field: 'card_num', title: '数量/金额', formatter: (_, __) => {
                const amt = parseFloat(__.amount) || 0;
                const amountHtml = amt > 0
                    ? `<span class="md-pair__v" style="color:var(--md-success);font-weight:600">¥${format.amountRemoveTrailingZeros(amt)}</span>`
                    : `<span class="md-pair__v md-pair__v--muted">¥0</span>`;
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__k">数量</span><span class="md-pair__v">${__.card_num ?? '-'}</span></div><div class="md-pair__row"><span class="md-pair__k">金额</span>${amountHtml}</div></div>`;
            }
        }
        , {
            field: 'commodity.delivery_way', title: '发货方式', dict: "_order_delivery_way"
        }
        , {
            field: 'pay', title: '支付方式', formatter: format.pay
        }
        , {
            field: 'status', title: '支付状态', dict: "_order_status"
        }
        , {
            field: 'delivery_status', title: '发货状态', dict: "_order_delivery_status"
        }
        , {
            field: 'cost', title: '手续费/佣金', formatter: (_, __) => {
                const fee = parseFloat(__.cost) || 0;
                const rebate = parseFloat(__.rebate) || 0;
                if (fee <= 0 && rebate <= 0) return '-';
                const fmt = v => '¥' + format.amountRemoveTrailingZeros(v);
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__k">手续费</span><span class="md-pair__v" style="color:var(--md-info)">${fmt(fee)}</span></div><div class="md-pair__row"><span class="md-pair__k">佣金</span><span class="md-pair__v md-pair__v--muted">${fmt(rebate)}</span></div></div>`;
            }
        }
        , {
            field: 'rent', title: '消耗成本'
        }
        , {
            field: 'promote', title: '推广人/分成', formatter: (_, __) => {
                if (!_) return '-';
                const name = _.username || '';
                const avatar = _.avatar
                    ? `<img src="${_.avatar}" class="md-user-cell__avatar" alt="">`
                    : `<span class="md-user-cell__avatar md-user-cell__avatar--ph">${(name.charAt(0) || '?').toUpperCase()}</span>`;
                const divide = parseFloat(__.divide_amount) || 0;
                const sub = divide > 0
                    ? `<span class="md-user-cell__sub" style="color:var(--md-success);font-weight:600">分成 ¥${format.amountRemoveTrailingZeros(divide)}</span>`
                    : `<span class="md-user-cell__sub">分成 ¥0</span>`;
                return `<div class="md-user-cell">${avatar}<div class="md-user-cell__text"><span class="md-user-cell__name">${name}</span>${sub}</div></div>`;
            }
        }
        , {
            field: 'secret', title: '卡密信息', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => _?.commodity?.delivery_way === 0 && _.delivery_status == 1,
                    click: (event, value, map, index) => {
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
                    }
                },
                {
                    icon: `fa-duotone fa-regular fa-truck-ramp-box`,
                    class: "text-success",
                    title: "手动发货",
                    show: _ => _?.commodity?.delivery_way === 1,
                    click: (event, value, map, index) => {
                        modal(`${util.icon("fa-duotone fa-regular fa-truck-ramp-box")} 发货内容`, map);
                    }
                },

            ]
        }
        , {
            field: 'widget', title: '控件', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => {
                        let parse = JSON.parse(_.widget);
                        if (!parse || parse.length == 0) {
                            return false;
                        }
                        return true;
                    },
                    click: (event, value, map, index) => {
                        let parse = JSON.parse(map.widget);
                        if (!parse) {
                            return;
                        }
                        const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        let rows = '';
                        for (const k in parse) {
                            rows += `<div class="md-detail__row"><span class="md-detail__label">${esc(parse[k].cn)}</span><span class="md-detail__value">${esc(parse[k].value ?? '-')}</span></div>`;
                        }
                        layer.open({
                            type: 1,
                            shadeClose: true,
                            title: `${util.icon("fa-duotone fa-regular fa-diamonds-4")} 控件信息`,
                            content: `<div class="md-detail" style="padding:6px 14px 14px;"><div class="md-detail__body">${rows}</div></div>`,
                            area: util.isPc() ? "460px" : ["100%", "100%"]
                        });
                    }
                }
            ]
        }
    ]);

    // 双击订单号 → MUI 详情弹窗（取代原 hover 黑色浮层 setFloatMessage）
    table.setColumnDetail({
        column: 'trade_no',
        trigger: 'dblclick',
        header: false,
        title: (row) => row.trade_no,
        fields: [
        {field: 'contact', title: '联系方式'},
        {field: 'password', title: '查询密码'},
        {field: 'create_time', title: '下单时间'},
        {field: 'pay_time', title: '支付时间'},
        {field: 'create_ip', title: '客户IP'},
        {field: 'create_device', title: '设备', dict: "_common_device"},
        {field: 'card.secret', title: '预选卡密'},
        {field: 'coupon.code', title: '优惠券'}
        ]
    });

    table.setSearch([
        {title: "订单号", name: "equal-trade_no", type: "input"},
        {title: "商品ID", name: "equal-commodity_id", type: "input"},
        {title: "卡密信息(模糊)", name: "search-secret", type: "input"},
        {title: "联系方式", name: "equal-contact", type: "input"},
        {title: "支付状态", name: "equal-status", type: "select", dict: "_order_status"},
        {title: "发货状态", name: "equal-delivery_status", type: "select", dict: "_order_delivery_status"},
        {title: "支付方式", name: "equal-pay_id", type: "select", dict: "pay,id,name"},
        {
            title: "下单设备",
            name: "equal-create_device",
            type: "select",
            dict: "_common_device",
        },
        {title: "IP地址", name: "equal-create_ip", type: "input"},
        {title: "会员ID，0=访客", name: "equal-owner", type: "input"},
        {title: "商户ID，0=系统", name: "equal-user_id", type: "input"},
        {title: "下单时间", name: "between-create_time", type: "date"},
    ]);
    table.setState("status", "_order_status");

    table.onResponse(res => {
        $('.order_count').html(Number(res.data.total || 0).toLocaleString('en-US'));
        $('.order_amount').html('￥' + Number(res.data.order_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('.order_cost').html('￥' + Number(res.data.order_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    });

    table.render();

    $('.clear').click(() => {
        util.post({
            url: "/admin/api/order/clear",
            done: res => {
                message.success(res.msg);
                table.refresh();
            }
        });
    });


    $('.btn-app-export').click(function () {

        component.popup({
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-file-export") + " 导出订单",
                    form: [
                        {
                            name: "custom",
                            type: "custom",
                            complete: (obj, dom) => {
                                dom.html('<div style="margin-bottom: 25px;color: #27bd27;font-weight: bolder;">导出程序将根据您通过查询功能筛选出的订单进行导出。如果您填写了导出数量，将导出指定数量的订单；如果您未填写数量，则将导出您筛选的全部订单。</div>');
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
                                {id: 1, name: "删除导出的订单（高危/物理删除）"},
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

                let url = "/admin/api/order/export?" + query + "&equal-" + state.field + "=" + state.value;
                if (data.export_status == 1) {
                    message.dangerPrompt("您正在执行高风险的订单导出操作，需要注意此操作是物理删除，绝对上的无法恢复。", "我确认导出并删除订单", () => {
                        window.open(url);
                    });
                } else {
                    window.open(url);
                }
            },
        });
    });
}();