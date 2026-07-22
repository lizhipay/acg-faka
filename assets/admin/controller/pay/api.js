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
            done: () => {
                if (!controllerActive) return;
                deletePending = false;
                message.success("删除成功");
                table.refresh();
            },
            error: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.error(res?.msg || '支付接口删除已被阻止');
            },
            fail: () => {
                if (!controllerActive) return;
                deletePending = false;
                message.error('网络异常，未执行删除');
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
                    ? impact.names.map(escapeHtml).join('、')
                    : '所选支付接口';
                const more = count > Number(impact.names?.length || 0) ? ` 等 ${count} 个接口` : '';
                const orderDetail = `商品订单 ${Number(impact.order_count || 0)} 笔（已支付 ${Number(impact.paid_order_count || 0)}、未支付 ${Number(impact.pending_order_count || 0)}）`;
                const rechargeDetail = `充值订单 ${Number(impact.recharge_count || 0)} 笔（已支付 ${Number(impact.paid_recharge_count || 0)}、未支付 ${Number(impact.pending_recharge_count || 0)}）`;

                if (impact.can_delete !== true) {
                    message.alert(
                        `<div style="text-align:left;line-height:1.8;">
                            <div><b>所选接口：</b>${names}${more}</div>
                            <div style="margin-top:10px;">${orderDetail}<br>${rechargeDetail}</div>
                            <div>内置接口 ${Number(impact.built_in_count || 0)} 个；已失效选项 ${Number(impact.missing_count || 0)} 个。</div>
                            <div>仍启用商品下单 ${Number(impact.commodity_enabled_count || 0)} 个；仍启用余额充值 ${Number(impact.recharge_enabled_count || 0)} 个。</div>
                            <div class="mt-2 text-danger">系统已阻止物理删除。请先停用接口；已被历史订单或充值记录引用的接口必须保留。</div>
                        </div>`,
                        'warning'
                    );
                    return;
                }

                message.ask(
                    `<div style="text-align:left;line-height:1.8;">
                        <div><b>将永久删除：</b>${names}${more}</div>
                        <div style="margin-top:10px;">${orderDetail}<br>${rechargeDetail}</div>
                        <div class="mt-2 text-danger">已确认这些接口均已停用且无历史引用。删除后无法恢复。</div>
                    </div>`,
                    () => deletePayments(list),
                    '确认永久删除支付接口？',
                    '确认删除'
                );
            },
            error: res => {
                if (!controllerActive) return;
                deletePreviewPending = false;
                message.error(res?.msg || '无法计算删除影响，已阻止删除');
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
                throw new Error(res?.msg || '支付插件加载失败');
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
                    name: item?.info?.name || item?.name || `支付插件 ${item.id}`
                }));
        } catch (error) {
            if (!controllerActive || error?.statusText === 'abort') return false;
            plugins = [];
            handles = [];
            message.error(error?.message || '支付插件加载失败，请刷新后重试');
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
                            tips: assign?.id ? '已有支付接口不能更换所属插件；如需更换，请新建接口。' : '',
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
                            tips: assign?.id ? '可切换当前插件支持的其他支付方式。' : ''
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
                            tips: "单笔固定：每笔订单固定手续费<br>百分比：使用小数代替，比如0.01"
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
                    return `<div class="md-pay"><img src="${icon}" class="md-pay__icon" alt=""><span class="md-pay__name">${name}</span></div>`;
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
                    return '<span class="a-badge a-badge-danger" >未启用</span>';
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
                    show: item => item.id != 1,
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改支付接口", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    show: item => item.id != 1,
                    click: (event, value, row, index) => {
                        confirmPayDelete([row.id]);
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
            }
        ]);
        table.setState("handle", handles);

        table.render();


        $('.btn-app-create').off(namespace).on('click' + namespace, function () {
            modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加支付接口`);
        });


        $('.btn-app-del').off(namespace).on('click' + namespace, () => {
            let data = table.getSelectionIds();
            if (data.length == 0) {
                layer.msg("请至少勾选1个支付方式进行操作！");
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
