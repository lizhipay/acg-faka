!function () {
    let table, _createForms = [], _createSearchs = [];
    const namespace = '.mdTradeOrderController';
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const parseWidget = value => {
        const normalize = parsed => {
            if (Array.isArray(parsed)) return parsed.filter(item => item && typeof item === 'object');
            if (!parsed || typeof parsed !== 'object') return [];
            if (Object.prototype.hasOwnProperty.call(parsed, 'cn') || Object.prototype.hasOwnProperty.call(parsed, 'value')) return [parsed];
            return Object.values(parsed).filter(item => item && typeof item === 'object');
        };
        if (value && typeof value === 'object') return normalize(value);
        try {
            const parsed = JSON.parse(String(value || '[]'));
            return normalize(parsed);
        } catch (error) {
            return [];
        }
    };
    const controllerLayers = new Set();
    let controllerActive = true;
    let deliveryConfirmationOpen = false;

    if (typeof window.__mdTradeOrderDestroy === 'function') window.__mdTradeOrderDestroy();

    const openControllerLayer = options => {
        const originalEnd = options.end;
        let index;
        index = layer.open({
            ...options,
            end: function () {
                controllerLayers.delete(index);
                if (typeof originalEnd === 'function') return originalEnd.apply(this, arguments);
            }
        });
        if (controllerActive) controllerLayers.add(index); else layer.close(index);
        return index;
    };
    const mobileSheetOptions = skin => {
        const mobile = mobileAdminEnabled();
        return mobile ? {
            area: ['100%', 'auto'],
            offset: 'b',
            skin: `admin-mobile-layer-popup admin-mobile-layer-popup--sheet ${skin}`,
            maxmin: false,
            resize: false,
            move: false
        } : {
            skin: skin,
            maxmin: false,
            resize: false
        };
    };

    table = new Table("/admin/api/order/data", "#order-table");

    const mobileDeliverySubmit = order => {
        let confirming = false;
        let requesting = false;
        return (data, popupIndex) => {
            if (!controllerActive || confirming || requesting || deliveryConfirmationOpen) return;
            if (Number(order.status) !== 1) {
                message.warning('仅已支付订单可以手动发货，请刷新订单状态后重试。');
                return;
            }
            const secret = String(data.secret ?? '');
            const normalizedSecret = secret.trim();
            if (!normalizedSecret || normalizedSecret === '0') {
                message.warning('请填写有效的发货内容，不能仅为空白或“0”。');
                return;
            }
            const tradeNo = escapeHtml(order.trade_no || '-');
            const commodityName = escapeHtml(order?.commodity?.name || '未命名商品');
            const orderAmount = escapeHtml(order.amount ?? '0');
            const commodityPrice = order?.commodity?.price;
            const commodityPriceRow = commodityPrice === undefined || commodityPrice === null || commodityPrice === ''
                ? ''
                : `<div><b>商品标价：</b>¥${escapeHtml(commodityPrice)}</div>`;
            const secretLength = Array.from(secret).length;
            const overwrites = Number(order.delivery_status) === 1 || String(order.secret ?? '').trim() !== '';
            confirming = true;
            deliveryConfirmationOpen = true;
            Swal.fire({
                title: '确认手动发货',
                html: `<div style="text-align:left;line-height:1.8;">
                    <div><b>订单号：</b>${tradeNo}</div>
                    <div><b>商品：</b>${commodityName}</div>
                    <div><b>支付状态：</b><span style="color:#15803d;font-weight:600;">已支付</span></div>
                    ${commodityPriceRow}
                    <div><b>订单金额：</b>¥${orderAmount}</div>
                    <div><b>发货内容：</b>已填写 ${secretLength} 个字符</div>
                    <div style="margin-top:10px;color:#d14343;">${overwrites ? '此订单已有发货记录，本次提交会覆盖现有发货内容。' : '提交后订单会立即进入已发货状态，无法在本页面一键撤销。'}</div>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                cancelButtonText: '返回检查',
                confirmButtonText: '确认发货'
            }).then(result => {
                confirming = false;
                deliveryConfirmationOpen = false;
                if (!(result.isConfirmed === true || result.value === true) || !controllerActive || requesting) return;
                requesting = true;
                util.post({
                    url: '/admin/api/order/save',
                    data: Object.assign({}, data, {overwrite_confirmed: overwrites ? 1 : 0}),
                    done: res => {
                        requesting = false;
                        if (!controllerActive) return;
                        layer.close(popupIndex);
                        message.alert(!res.msg || res.msg === 'success' ? '订单发货信息已保存。' : res.msg, 'success');
                        table.refresh();
                    },
                    error: res => {
                        requesting = false;
                        if (controllerActive) message.alert(res?.msg || '订单发货信息未能保存。', 'error');
                    },
                    fail: () => {
                        requesting = false;
                        if (controllerActive) message.error('网络异常，发货信息未提交。');
                    }
                });
            });
        };
    };

    const modal = (title, assign = {}) => {
        component.popup({
            submit: mobileDeliverySubmit(assign),
            submitRoute: '/admin/api/order/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: false,
                            name: "secret",
                            type: "textarea",
                            placeholder: "填写要发货的信息",
                            required: true,
                            height: 300
                        }
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "580px",
            confirmText: '核对并发货',
            done: () => {
                if (controllerActive && table) table.refresh();
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
                        const mobile = mobileAdminEnabled();
                        const secret = map.secret ?? '';
                        const escaped = String(secret).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        openControllerLayer({
                            ...(mobile ? mobileSheetOptions('md-order-secret-layer') : {area: '480px'}),
                            type: 1,
                            title: `${util.icon("fa-duotone fa-regular fa-eye")} 查看卡密`,
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
                    show: _ => _?.commodity?.delivery_way === 1 && Number(_.status) === 1,
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
                    show: _ => parseWidget(_.widget).length > 0,
                    click: (event, value, map, index) => {
                        const mobile = mobileAdminEnabled();
                        const parse = parseWidget(map.widget);
                        if (!parse.length) return;
                        const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        let rows = '';
                        for (const k in parse) {
                            rows += `<div class="md-detail__row"><span class="md-detail__label">${esc(parse[k].cn)}</span><span class="md-detail__value">${esc(parse[k].value ?? '-')}</span></div>`;
                        }
                        openControllerLayer({
                            ...(mobile ? mobileSheetOptions('md-order-widget-layer') : {area: '460px'}),
                            type: 1,
                            shadeClose: true,
                            title: `${util.icon("fa-duotone fa-regular fa-diamonds-4")} 控件信息`,
                            content: mobile
                                ? `<div class="md-detail md-order-widget-detail"><div class="md-detail__body">${rows}</div></div>`
                                : `<div class="md-detail" style="padding:6px 14px 14px;"><div class="md-detail__body">${rows}</div></div>`
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

    $('.clear').off(namespace).on('click' + namespace, () => {
        util.post({
            url: "/admin/api/order/clear",
            done: res => {
                if (!controllerActive) return;
                message.success(res.msg);
                table.refresh();
            }
        });
    });


    const orderExportFormBody = payload => {
        const body = new URLSearchParams();
        Object.keys(payload || {}).forEach(key => {
            const value = payload[key];
            if (Array.isArray(value)) {
                value.forEach(item => body.append(`${key}[]`, item));
                return;
            }
            if (value !== undefined && value !== null) body.append(key, value);
        });
        return body.toString();
    };
    const postOrderExportRequest = async (url, payload) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: orderExportFormBody(payload)
        });
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            const json = await response.json();
            if (!response.ok || json.code !== 200) throw new Error(json.msg || '请求失败');
            return {json: json};
        }
        if (!response.ok) throw new Error('服务器无法完成订单导出');
        if (!contentType.includes('text/csv') && !contentType.includes('application/octet-stream')) {
            throw new Error('服务器返回的订单导出文件格式不正确');
        }
        return {blob: await response.blob()};
    };
    const downloadOrderExport = async (payload, impact) => {
        Loading.show();
        try {
            const result = await postOrderExportRequest('/admin/api/order/export', payload);
            if (!result.blob) throw new Error('服务器没有返回订单导出文件');
            if (!controllerActive) return;
            const count = Number(impact.count || 0);
            const url = URL.createObjectURL(result.blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `订单导出-${count}-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            message.success(Number(impact.export_status) === 1
                ? `已导出并永久删除 ${count} 笔订单`
                : `已安全导出 ${count} 笔订单`);
        } catch (error) {
            if (controllerActive) {
                message.alert(
                    error.message || '订单导出请求失败；若选择了永久删除，请刷新订单列表确认结果',
                    'error'
                );
            }
        } finally {
            Loading.hide();
        }
    };

    $('.btn-app-export').off(namespace).on('click' + namespace, function () {
        let previewPending = false;
        let downloadPending = false;

        component.popup({
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-file-export") + " 导出订单",
                    form: [
                        {
                            name: "custom",
                            type: "custom",
                            complete: (obj, dom) => {
                                dom.html('<div class="alert alert-warning mb-4"><b>订单导出</b><br>系统会先通过 POST 精确预览当前筛选范围，再生成文件。单次最多 5000 笔；选择“永久删除”后还必须完成高危确认。</div>');
                            }
                        },
                        {
                            title: "导出数量",
                            name: "export_num",
                            type: "input",
                            inputmode: "numeric",
                            enterkeyhint: "next",
                            placeholder: "0 或留空表示当前筛选范围内全部导出（最多 5000 笔）"
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
            assign: {export_num: 0, export_status: 0},
            confirmText: "预览导出范围",
            maxmin: false,
            autoPosition: true,
            submit: async (data, index) => {
                if (previewPending || downloadPending || !controllerActive) return;
                const rawExportNum = String(data.export_num ?? '').trim();
                const exportNum = rawExportNum === '' ? 0 : Number(rawExportNum);
                const exportStatus = Number(data.export_status ?? 0);
                if (!Number.isInteger(exportNum) || exportNum < 0 || exportNum > 5000) {
                    message.warning('导出数量必须是 0 到 5000 的整数');
                    return;
                }
                if (![0, 1].includes(exportStatus)) {
                    message.warning('请选择正确的导出后操作');
                    return;
                }

                const payload = Object.assign({}, table.getSearchData(), {
                    export_num: exportNum,
                    export_status: exportStatus
                });
                const state = table.getState();
                if (state.field && String(state.value ?? '') !== '') {
                    payload[`equal-${state.field}`] = state.value;
                }

                previewPending = true;
                Loading.show();
                try {
                    const preview = await postOrderExportRequest('/admin/api/order/exportImpact', payload);
                    if (!controllerActive) return;
                    const impact = preview.json?.data || {};
                    const count = Number(impact.count || 0);
                    const total = Number(impact.total || 0);
                    const previewToken = String(impact.preview_token || '');
                    if (!Number.isInteger(count) || count < 1 || !previewToken.includes('.')) {
                        throw new Error('服务器没有返回有效的订单导出范围');
                    }

                    const scope = impact.has_filter
                        ? '当前筛选条件'
                        : '<span style="color:#d32f2f;font-weight:700">未设置筛选条件</span>';
                    const limitText = count < total
                        ? `，按订单 ID 从新到旧导出其中 <b>${count} 笔</b>`
                        : `，本次导出 <b>${count} 笔</b>`;
                    const detail = `${scope}共命中 ${total} 笔${limitText}。<br><br>本次范围内：已支付 ${Number(impact.paid_count || 0)} 笔、未支付 ${Number(impact.unpaid_count || 0)} 笔；已发货 ${Number(impact.delivered_count || 0)} 笔、未发货 ${Number(impact.undelivered_count || 0)} 笔。`;
                    const proceed = deleteConfirmation => {
                        if (downloadPending || !controllerActive) return;
                        downloadPending = true;
                        layer.close(index);
                        const exportPayload = Object.assign({}, payload, {
                            expected_count: count,
                            preview_token: previewToken
                        });
                        if (deleteConfirmation) exportPayload.delete_confirmation = deleteConfirmation;
                        downloadOrderExport(exportPayload, impact).finally(() => {
                            downloadPending = false;
                        });
                    };

                    if (exportStatus === 1) {
                        const phrase = `确认永久删除${count}笔订单`;
                        message.dangerPrompt(
                            `${detail}<br><br><b style="color:#d32f2f">下载请求成功后，系统会物理删除上述 ${count} 笔订单及其历史记录，无法恢复。</b><br>删除失败时不会生成下载文件；但服务端确认成功后即完成删除，即使浏览器未保存文件也无法撤销。`,
                            phrase,
                            () => proceed(phrase)
                        );
                    } else {
                        message.ask(
                            `${detail}<br><br>本次只下载 CSV，不修改或删除订单。`,
                            () => proceed(''),
                            '确认导出订单',
                            '确认下载'
                        );
                    }
                } catch (error) {
                    if (controllerActive) message.alert(error.message || '无法预览订单导出范围', 'error');
                } finally {
                    previewPending = false;
                    Loading.hide();
                }
            },
        });
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        deliveryConfirmationOpen = false;
        $('.clear, .btn-app-export').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (typeof Swal !== 'undefined') Swal.close();
        if (typeof Loading !== 'undefined') Loading.hide();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeOrderDestroy === destroy) delete window.__mdTradeOrderDestroy;
    }

    window.__mdTradeOrderDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
