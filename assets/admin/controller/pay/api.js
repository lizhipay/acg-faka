!function () {
    const namespace = '.mdPayApiController';
    let table, plugins = [], handles = [];
    let deletePreviewPending = false, deletePending = false;
    let controllerActive = true, pluginRequest = null;
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    const safeImageUrl = (value, fallback = '/favicon.ico') => {
        const source = String(value ?? '').trim();
        if (!source) return fallback;
        try {
            const resolved = new URL(source, window.location.href);
            return ['http:', 'https:'].includes(resolved.protocol) ? resolved.href : fallback;
        } catch (error) {
            return fallback;
        }
    };

    if (typeof window.__mdPayApiDestroy === 'function') window.__mdPayApiDestroy();

    const deletePayments = list => {
        if (deletePending) return;
        deletePending = true;
        util.post({
            url: '/admin/api/pay/del',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.success(res?.msg || i18n('操作完成'));
                table.refresh();
            },
            error: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.error(res?.msg || i18n('支付接口删除已被阻止'));
            },
            fail: () => {
                if (!controllerActive) return;
                deletePending = false;
                message.error('网络异常，未执行删除');
            }
        });
    };

    const restorePayments = list => {
        if (deletePending) return;
        deletePending = true;
        util.post({
            url: '/admin/api/pay/restore',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.success(res?.msg || i18n('已恢复'));
                table.refresh();
            },
            error: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.error(res?.msg || i18n('恢复失败'));
            },
            fail: () => {
                if (!controllerActive) return;
                deletePending = false;
                message.error('网络异常，未执行恢复');
            }
        });
    };

    const confirmPayDelete = list => {
        if (deletePreviewPending || deletePending) return;
        deletePreviewPending = true;
        util.post({
            url: '/admin/api/pay/deleteImpact',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                deletePreviewPending = false;
                const impact = res?.data || {};
                const count = Number(impact.payment_count || 0);
                const names = Array.isArray(impact.names) && impact.names.length
                    ? impact.names.map(escapeHtml).join(i18n('、'))
                    : i18n('所选支付接口');
                const more = count > Number(impact.names?.length || 0) ? ` ${i18n('等')} ${count} ${i18n('个接口')}` : '';
                const orderDetail = `${i18n('商品订单')} ${Number(impact.order_count || 0)} ${i18n('笔（已支付')} ${Number(impact.paid_order_count || 0)}、${i18n('未支付')} ${Number(impact.pending_order_count || 0)}）`;
                const rechargeDetail = `${i18n('充值订单')} ${Number(impact.recharge_count || 0)} ${i18n('笔（已支付')} ${Number(impact.paid_recharge_count || 0)}、${i18n('未支付')} ${Number(impact.pending_recharge_count || 0)}）`;
                const nameLine = (arr, total) => {
                    const shown = (Array.isArray(arr) ? arr : []).map(escapeHtml).join(i18n('、'));
                    return shown + (total > (arr?.length || 0) ? ` ${i18n('等')} ${total} ${i18n('个')}` : '');
                };

                if (impact.can_proceed !== true) {
                    const archivedOnly = Number(impact.already_archived_count || 0) > 0
                        && Number(impact.missing_count || 0) === 0
                        && Number(impact.built_in_count || 0) === 0
                        && Number(impact.commodity_enabled_count || 0) === 0
                        && Number(impact.recharge_enabled_count || 0) === 0;
                    message.alert(
                        `<div style="text-align:left;line-height:1.8;">
                            <div><b>${i18n('所选接口：')}</b>${names}${more}</div>
                            <div style="margin-top:10px;">${orderDetail}<br>${rechargeDetail}</div>
                            <div>${i18n('内置接口')} ${Number(impact.built_in_count || 0)} ${i18n('个；已失效选项')} ${Number(impact.missing_count || 0)} ${i18n('个；已归档')} ${Number(impact.already_archived_count || 0)} ${i18n('个。')}</div>
                            <div>${i18n('仍启用商品下单')} ${Number(impact.commodity_enabled_count || 0)} ${i18n('个；仍启用余额充值')} ${Number(impact.recharge_enabled_count || 0)} ${i18n('个。')}</div>
                            <div class="mt-2 text-danger">${archivedOnly ? i18n('所选接口均已归档，无需重复操作。') : i18n('已阻止操作：内置接口不可移除；仍在启用中的接口请先停用。')}</div>
                        </div>`,
                        'warning'
                    );
                    return;
                }

                const deleteCount = Number(impact.delete_count || 0);
                const archiveCount = Number(impact.archive_count || 0);
                const sections = [];
                if (deleteCount > 0) {
                    sections.push(`<div><b class="text-danger">${i18n('将永久删除')} ${deleteCount} ${i18n('个（无历史引用）：')}</b>${nameLine(impact.delete_names, deleteCount)}</div>`);
                }
                if (archiveCount > 0) {
                    sections.push(`<div><b class="text-warning">${i18n('将转为归档')} ${archiveCount} ${i18n('个（有历史订单/充值引用）：')}</b>${nameLine(impact.archive_names, archiveCount)}</div>`);
                }
                if (Number(impact.already_archived_count || 0) > 0) {
                    sections.push(`<div>${i18n('已归档（跳过）')} ${Number(impact.already_archived_count)} ${i18n('个。')}</div>`);
                }

                message.ask(
                    `<div style="text-align:left;line-height:1.8;">
                        ${sections.join('')}
                        <div style="margin-top:10px;">${orderDetail}<br>${rechargeDetail}</div>
                        <div class="mt-2 text-muted">${i18n('归档的接口不再出现在列表和前台，历史订单显示不受影响，可在「已归档」筛选中随时恢复；永久删除无法恢复。')}</div>
                    </div>`,
                    () => deletePayments(list),
                    i18n('确认移除支付接口？'),
                    i18n('确认移除')
                );
            },
            error: res => {
                if (!controllerActive) return;
                deletePreviewPending = false;
                message.error(res?.msg || i18n('无法计算删除影响，已阻止删除'));
            },
            fail: () => {
                if (!controllerActive) return;
                deletePreviewPending = false;
                message.error('网络异常，无法预览删除影响，已阻止删除');
            }
        });
    };

    const loadPlugins = async () => {
        const layIndex = layer.load(1, {
            shade: [0.3, 'var(--md-surface)']
        });

        try {
            pluginRequest = $.ajax({
                type: 'post',
                url: '/admin/api/pay/getPlugins',
                async: true,
                dataType: 'json'
            });
            const res = await pluginRequest;
            if (!controllerActive) return false;
            if (res?.code !== 200) {
                throw new Error(res?.msg || i18n('支付插件加载失败'));
            }

            const list = Array.isArray(res?.data?.list) ? res.data.list : [];
            plugins = [];
            list.forEach(item => {
                if (!item || item.id == null) return;
                plugins[item.id] = item;
            });
            handles = list
                .filter(item => item && item.id != null)
                .map(item => ({
                    id: item.id,
                    name: item?.info?.name || item?.name || `${i18n('支付插件')} ${item.id}`
                }));
        } catch (error) {
            if (!controllerActive || error?.statusText === 'abort') return false;
            plugins = [];
            handles = [];
            message.error(error?.message || i18n('支付插件加载失败，请刷新后重试'));
        } finally {
            pluginRequest = null;
            layer.close(layIndex);
        }
        return controllerActive;
    };

    let getType = function (handle, code) {
        if (handle == null) {
            return '-';
        }

        if (!plugins[handle]) {
            return '-';
        }

        return escapeHtml(plugins[handle]?.info?.options?.[code] ?? '-');
    }

    let getPluginName = function (handle) {
        if (handle == null) {
            return '-';
        }

        if (!plugins[handle]) {
            return '-';
        }

        const icon = escapeHtml(safeImageUrl(plugins[handle]?.icon));
        const name = escapeHtml(plugins[handle]?.info?.name ?? '');
        return `<div class="md-plugin"><img src="${icon}" class="md-plugin__icon" alt=""><span class="md-plugin__name">${name}</span></div>`;
    }


    const modal = (title, assign = {}) => {

        let codeOptions = [];

        if (assign?.handle && assign?.code) {
            const plg = plugins[assign?.handle];
            if (plg) {
                for (const index in plg?.info?.options) {
                    codeOptions.push({
                        id: index,
                        name: plg?.info?.options[index]
                    })
                }
            }
        }

        component.popup({
            submit: '/admin/api/pay/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            height: 64,
                            required: true
                        },
                        {
                            title: "支付名称",
                            name: "name",
                            required: true,
                            type: "input",
                            placeholder: "请输入支付方式名称"
                        },
                        {
                            title: "支付插件",
                            name: "handle",
                            type: "select",
                            dict: handles,
                            required: true,
                            placeholder: "请选择支付插件",
                            tips: assign?.id ? i18n('已有支付接口不能更换所属插件；如需更换，请新建接口。') : '',
                            default: 0,
                            change: (form, value) => {
                                const plg = plugins[value];
                                if (plg) {
                                    form.clearComponent("code");
                                    for (const index in plg?.info?.options) {
                                        form.addRadio("code", index, plg?.info?.options[index], assign?.code == index);
                                    }
                                    form.show("code");
                                } else {
                                    form.clearComponent("code");
                                    form.hide("code");
                                }
                            }
                        },
                        {
                            title: "支付方式",
                            name: "code",
                            type: "radio",
                            dict: codeOptions,
                            hide: !assign?.code,
                            required: true,
                            tips: assign?.id ? i18n('可切换当前插件支持的其他支付方式。') : ''
                        },
                        {
                            title: "显示终端",
                            name: "equipment",
                            type: "radio",
                            dict: "_pay_equipment",
                            default: 0
                        },
                        {
                            title: "下单手续费",
                            name: "cost",
                            type: "input",
                            placeholder: "不设置手续费请留空",
                            tips: i18n("单笔固定：每笔订单固定手续费") + "<br>" + i18n("百分比：使用小数代替，比如0.01")
                        },
                        {
                            title: "手续费模式",
                            name: "cost_type",
                            type: "radio",
                            dict: [
                                {id: 0, name: "单笔固定"},
                                {id: 1, name: "百分比(使用小数代替)"}
                            ],
                            default: 0
                        },
                        {title: "商品下单", name: "commodity", type: "switch", text: "启用"},
                        {title: "会员充值", name: "recharge", type: "switch", text: "启用"},
                        {title: "显示排序", name: "sort", type: "input", placeholder: "越小显示靠前"},
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            content: {
                css: {
                    height: "auto",
                    overflow: "inherit"
                }
            },
            height: "auto",
            width: "680px",
            renderComplete: unique => {
                const $root = $('.' + unique);
                $root.find('input[name="cost"]').attr({inputmode: 'decimal', autocomplete: 'off'});
                $root.find('input[name="sort"]').attr({inputmode: 'numeric', autocomplete: 'off'});
                if (!assign?.id) return;
                const $locked = $root.find('select[name="handle"]');
                $locked.prop('disabled', true).attr({'aria-disabled': 'true', 'data-pay-identifier-locked': 'true'});
                $locked.next('.layui-form-select').addClass('layui-disabled').css('pointer-events', 'none');
                $root.find('.component-handle .layui-form-select').addClass('layui-disabled').css('pointer-events', 'none');
            },
            done: () => {
                table.refresh();
            }
        });
    }

    const initializeTable = () => {
        table = new Table("/admin/api/pay/data", "#pay-table");
        table.setUpdate("/admin/api/pay/save");
        table.setColumns([
            {checkbox: true, formatter: (_, row) => ({disabled: Number(row.id) === 1})},
            {
                field: 'name', title: '支付名称', formatter: (_, __) => {
                    const icon = escapeHtml(safeImageUrl(__.icon));
                    const name = escapeHtml(__.name ?? '');
                    const archivedBadge = Number(__.archived) === 1 ? `<span class="a-badge a-badge-warning" style="margin-left:8px;">${i18n('已归档')}</span>` : '';
                    return `<div class="md-pay"><img src="${icon}" class="md-pay__icon" alt=""><span class="md-pay__name">${name}</span>${archivedBadge}</div>`;
                }
            }
        , {
            field: 'plugin', title: '所属插件', formatter: function (val, item) {
                if (item.id == 1) {
                    return '-';
                }
                return getPluginName(item.handle);
            }
        }
        , {
            field: 'cost', title: '手续费', formatter: function (val, item) {
                if (item.id == 1) {
                    return '-';
                }
                if (item.cost == 0) {
                    return '<span class="a-badge a-badge-danger" >' + i18n('未启用') + '</span>';
                }
                if (item.cost_type == 0) {
                    return '<span class="a-badge a-badge-success" >￥' + escapeHtml(item.cost) + '</span>';
                } else {
                    return '<span class="a-badge a-badge-primary" >' + (item.cost * 100) + '%</span>';
                }
            }
        }
        , {
            field: 'create_time', title: '创建时间', show: _ => _.id != 1
        }
        , {
            field: 'type', title: '支付方式', formatter: function (val, item) {
                if (item.id == 1) {
                    return '-';
                }
                return '<span class="a-badge a-badge-success">' + getType(item.handle, item.code) + '</span>';
            }
        },
        {
            field: 'equipment',
            title: '终端控制',
            show: _ => _.id != 1,
            dict: "_pay_equipment",
            reload: true
        }, {
            field: 'commodity', title: '商品下单', show: _ => _.id != 1, type: "switch", text: "开启|关闭", reload: true
        }
        , {
            field: 'recharge', title: '余额充值', show: _ => _.id != 1, type: "switch", text: "开启|关闭", reload: true
        }, {field: 'sort', title: '排序(越小越前)', show: _ => _.id != 1, sort: true, type: "input", reload: true}
        ,
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    show: item => item.id != 1 && Number(item.archived) !== 1,
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + i18n("修改支付接口"), row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    show: item => item.id != 1 && Number(item.archived) !== 1,
                    click: (event, value, row, index) => {
                        confirmPayDelete([row.id]);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-rotate-left',
                    class: 'text-success',
                    title: '恢复',
                    show: item => Number(item.archived) === 1,
                    click: (event, value, row, index) => {
                        message.ask(
                            `${i18n('恢复后，接口')} <b>${escapeHtml(row.name ?? '')}</b> ${i18n('将回到接口列表并处于停用状态，需手动重新启用。确认恢复吗？')}`,
                            () => restorePayments([row.id]),
                            i18n('确认恢复支付接口？'),
                            i18n('确认恢复')
                        );
                    }
                }
            ]
        },
        ]);
        table.setSearch([
            {title: "支付名称", name: "search-name", type: "input"},
            {
                title: "商品下单-状态", name: "equal-commodity", type: "select", dict: "_common_status"
            },
            {
                title: "余额充值-状态", name: "equal-recharge", type: "select", dict: "_common_status"
            },
            {
                title: "接口状态", name: "equal-archived", type: "select", dict: [
                    {id: 0, name: "使用中"},
                    {id: 1, name: "已归档"}
                ]
            }
        ]);
        table.setState("handle", handles);

        table.render();


        $('.btn-app-create').off(namespace).on('click' + namespace, function () {
            modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> ${i18n('添加支付接口')}`);
        });


        $('.btn-app-del').off(namespace).on('click' + namespace, () => {
            let data = table.getSelectionIds();
            if (data.length == 0) {
                layer.msg(i18n("请至少勾选1个支付方式进行操作！"));
                return;
            }

            confirmPayDelete(data);
        });
    };

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        deletePreviewPending = false;
        deletePending = false;
        if (pluginRequest && typeof pluginRequest.abort === 'function') pluginRequest.abort();
        if (typeof Swal !== 'undefined') Swal.close();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        $('.btn-app-create, .btn-app-del').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (window.__mdPayApiDestroy === destroy) delete window.__mdPayApiDestroy;
    }

    window.__mdPayApiDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
    loadPlugins().then(function (ready) {
        if (ready && controllerActive) initializeTable();
    });
}();
