!function () {
    'use strict';

    const namespace = '.mdRechargeOrderController';
    if (typeof window.__mdRechargeOrderDestroy === 'function') window.__mdRechargeOrderDestroy();

    let controllerActive = true;
    let supplementPending = false;
    let clearPending = false;
    let table = new Table('/admin/api/rechargeOrder/data', '#recharge-order-table');
    const fetchControllers = new Set();

    const escapeHtml = value => String(value == null ? '-' : value).replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));

    const formBody = payload => {
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

    const postExportRequest = async (url, payload) => {
        const abortController = new AbortController();
        fetchControllers.add(abortController);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: formBody(payload),
                signal: abortController.signal
            });
            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                const json = await response.json();
                if (!response.ok || json.code !== 200) throw new Error(json.msg || '请求失败');
                return {json: json};
            }
            if (!response.ok) throw new Error('服务器无法完成充值订单导出');
            if (!contentType.includes('text/csv') && !contentType.includes('application/octet-stream')) {
                throw new Error('服务器返回的充值订单导出文件格式不正确');
            }
            return {blob: await response.blob()};
        } finally {
            fetchControllers.delete(abortController);
        }
    };

    const downloadExport = async (payload, impact) => {
        Loading.show();
        try {
            const result = await postExportRequest('/admin/api/rechargeOrder/export', payload);
            if (!result.blob) throw new Error('服务器没有返回充值订单导出文件');
            if (!controllerActive) return;
            const count = Number(impact.count || 0);
            const url = URL.createObjectURL(result.blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `充值订单导出-${count}-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            message.success(Number(impact.export_status) === 1
                ? `已导出并永久删除 ${count} 笔充值订单`
                : `已安全导出 ${count} 笔充值订单`);
            table?.refresh();
        } catch (error) {
            if (controllerActive && error?.name !== 'AbortError') {
                message.alert(
                    error.message || '充值订单导出失败；若选择了永久删除，请刷新列表确认结果',
                    'error'
                );
            }
        } finally {
            Loading.hide();
        }
    };

    table.setColumns([
        {checkbox: true},
        {field: 'trade_no', title: '订单号'},
        {field: 'user', title: '会员', formatter: value => mdUserCell(value)},
        {field: 'amount', title: '充值金额', formatter: value => format.money(value, 'green')},
        {field: 'pay', title: '支付方式', formatter: format.pay},
        {field: 'create_time', title: '下单时间'},
        {field: 'create_ip', title: '客户IP'},
        {field: 'status', title: '支付状态', dict: '_recharge_order_status'},
        {field: 'pay_time', title: '支付时间'},
        {
            field: 'operation', title: '操作', type: 'button', buttons: [{
                icon: 'fa-duotone fa-regular fa-circle-check',
                class: 'text-success',
                title: '补单',
                show: row => row.status === 0,
                click: (event, value, row) => {
                    if (supplementPending || !controllerActive) return;
                    const user = row.user && typeof row.user === 'object' ? row.user : {};
                    const userLabel = user.username || user.name || (user.id ? `ID ${user.id}` : row.user || '-');
                    const payName = row.pay && typeof row.pay === 'object' ? row.pay.name : row.pay;
                    const prompt = '<div style="text-align:left;line-height:1.8">' +
                        '<p style="margin:0 0 8px">补单会把充值订单标记为已支付，并立即增加会员余额。</p>' +
                        '<div><b>订单号：</b>' + escapeHtml(row.trade_no) + '</div>' +
                        '<div><b>会员：</b>' + escapeHtml(userLabel) + '</div>' +
                        '<div><b>充值金额：</b>¥' + escapeHtml(row.amount) + '</div>' +
                        '<div><b>支付方式：</b>' + escapeHtml(payName) + '</div>' +
                        '<p style="margin:8px 0 0;color:#d63b3b;font-weight:700">该操作会真实入账且无法在本页面撤销，请核对无误。</p></div>';
                    message.ask(prompt, () => {
                        if (supplementPending || !controllerActive) return;
                        supplementPending = true;
                        util.post({
                            url: '/admin/api/rechargeOrder/success',
                            data: {id: row.id},
                            done: response => {
                                supplementPending = false;
                                if (!controllerActive) return;
                                message.success(response?.msg || '补单成功');
                                table.refresh();
                            },
                            error: response => {
                                supplementPending = false;
                                if (controllerActive) message.error(response?.msg || '补单失败');
                            },
                            fail: () => {
                                supplementPending = false;
                                if (controllerActive) message.error('网络异常，补单未提交');
                            }
                        });
                    }, '确认充值补单', '确认补单');
                }
            }]
        }
    ]);

    table.onResponse(response => {
        if (!controllerActive) return;
        $('.order_count').text(Number(response?.data?.total || 0).toLocaleString('zh-CN'));
        $('.order_amount').text('￥' + Number(response?.data?.order_amount || 0).toLocaleString('zh-CN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }));
    });

    table.setSearch([
        {title: '订单号', name: 'equal-trade_no', type: 'input', inputmode: 'text', enterkeyhint: 'search'},
        {title: '支付方式', name: 'equal-pay_id', type: 'select', dict: 'pay->id!=1,id,name'},
        {title: 'IP地址', name: 'equal-create_ip', type: 'input', inputmode: 'text', enterkeyhint: 'search'},
        {title: '搜索会员', name: 'equal-user_id', type: 'remoteSelect', dict: 'user,id,username'},
        {title: '下单时间', name: 'between-create_time', type: 'date'}
    ]);
    table.setState('status', '_recharge_order_status');
    table.render();

    $('.clear').off(namespace).on('click' + namespace, () => {
        if (clearPending || !controllerActive) return;
        message.ask(
            '只会物理删除 30 分钟前仍未支付的充值订单；已支付订单不会受影响。确认清理吗？',
            () => {
                if (clearPending || !controllerActive) return;
                clearPending = true;
                util.post({
                    url: '/admin/api/rechargeOrder/clear',
                    data: {},
                    done: response => {
                        clearPending = false;
                        if (!controllerActive) return;
                        const count = Math.max(0, Number(response?.data?.count || 0));
                        message.success(`已清理 ${count} 笔未支付充值订单`);
                        table.refresh();
                    },
                    error: response => {
                        clearPending = false;
                        if (controllerActive) message.error(response?.msg || '清理失败');
                    },
                    fail: () => {
                        clearPending = false;
                        if (controllerActive) message.error('网络异常，清理未提交');
                    }
                });
            },
            '清理未支付充值订单？',
            '确认清理'
        );
    });

    $('.btn-app-export').off(namespace).on('click' + namespace, () => {
        let previewPending = false;
        let downloadPending = false;

        component.popup({
            tab: [{
                name: util.icon('fa-duotone fa-regular fa-file-export') + ' 导出充值订单',
                form: [
                    {
                        name: 'custom',
                        type: 'custom',
                        complete: (form, dom) => {
                            dom.html('<div class="alert alert-warning mb-4"><b>充值订单导出</b><br>系统会先通过 POST 精确预览当前筛选范围，再生成文件。导出数量必须填写 1–5000；选择“永久删除”后还必须完成高危确认。</div>');
                        }
                    },
                    {
                        title: '导出数量',
                        name: 'export_num',
                        type: 'input',
                        required: true,
                        inputmode: 'numeric',
                        enterkeyhint: 'next',
                        placeholder: '请输入 1–5000 的整数',
                        complete: form => {
                            $('.' + form.unique + ' input[name="export_num"]').attr({
                                inputmode: 'numeric',
                                autocomplete: 'off',
                                enterkeyhint: 'next'
                            });
                        }
                    },
                    {
                        title: '导出后执行',
                        name: 'export_status',
                        type: 'radio',
                        default: 0,
                        dict: [
                            {id: 0, name: '仅下载，不修改订单'},
                            {id: 1, name: '下载并永久删除这些充值订单'}
                        ]
                    }
                ]
            }],
            height: 'auto',
            width: '580px',
            assign: {export_status: 0},
            confirmText: '预览导出范围',
            maxmin: false,
            autoPosition: true,
            submit: async (data, index) => {
                if (previewPending || downloadPending || !controllerActive) return;
                const rawExportNum = String(data.export_num ?? '').trim();
                const exportNum = Number(rawExportNum);
                const exportStatus = Number(data.export_status ?? 0);
                if (!/^\d+$/.test(rawExportNum) || !Number.isInteger(exportNum) || exportNum < 1 || exportNum > 5000) {
                    message.warning('导出数量必须是 1 到 5000 的整数');
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
                    const preview = await postExportRequest('/admin/api/rechargeOrder/exportImpact', payload);
                    if (!controllerActive) return;
                    const impact = preview.json?.data || {};
                    const count = Number(impact.count || 0);
                    const total = Number(impact.total || 0);
                    const previewToken = String(impact.preview_token || '');
                    if (!Number.isInteger(count) || count < 1 || !previewToken.includes('.')) {
                        throw new Error('服务器没有返回有效的充值订单导出范围');
                    }

                    const scope = impact.has_filter
                        ? '当前筛选条件'
                        : '<span style="color:#d32f2f;font-weight:700">未设置筛选条件</span>';
                    const limitText = count < total
                        ? `，按订单 ID 从新到旧导出其中 <b>${count} 笔</b>`
                        : `，本次导出 <b>${count} 笔</b>`;
                    const detail = `${scope}共命中 ${total} 笔${limitText}。<br><br>本次范围内：已支付 ${Number(impact.paid_count || 0)} 笔、未支付 ${Number(impact.unpaid_count || 0)} 笔。`;
                    const proceed = deleteConfirmation => {
                        if (downloadPending || !controllerActive) return;
                        downloadPending = true;
                        layer.close(index);
                        const exportPayload = Object.assign({}, payload, {
                            expected_count: count,
                            preview_token: previewToken
                        });
                        if (deleteConfirmation) exportPayload.delete_confirmation = deleteConfirmation;
                        downloadExport(exportPayload, impact).finally(() => {
                            downloadPending = false;
                        });
                    };

                    if (exportStatus === 1) {
                        const phrase = `确认永久删除${count}笔充值订单`;
                        message.dangerPrompt(
                            `${detail}<br><br><b style="color:#d32f2f">服务器成功生成文件后会物理删除上述 ${count} 笔充值订单，无法恢复。</b><br>删除失败时不会生成下载文件；服务端确认成功后即完成删除，即使浏览器未保存文件也无法撤销。`,
                            phrase,
                            () => proceed(phrase)
                        );
                    } else {
                        message.ask(
                            `${detail}<br><br>本次只下载 CSV，不修改或删除充值订单。`,
                            () => proceed(''),
                            '确认导出充值订单',
                            '确认下载'
                        );
                    }
                } catch (error) {
                    if (controllerActive && error?.name !== 'AbortError') {
                        message.alert(error.message || '无法预览充值订单导出范围', 'error');
                    }
                } finally {
                    previewPending = false;
                    Loading.hide();
                }
            }
        });
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        supplementPending = false;
        clearPending = false;
        $('.clear, .btn-app-export').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        fetchControllers.forEach(controller => controller.abort());
        fetchControllers.clear();
        if (typeof Swal !== 'undefined') Swal.close();
        if (typeof Loading !== 'undefined') Loading.hide();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdRechargeOrderDestroy === destroy) delete window.__mdRechargeOrderDestroy;
    }

    window.__mdRechargeOrderDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
