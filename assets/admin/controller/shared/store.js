!function () {
    let table, _LogPid, mobileRefreshTimer;
    let connectGeneration = 0;
    const namespace = '.mdSharedStoreController';
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const controllerLayers = new Set();
    const controllerRequests = new Set();
    let controllerActive = true;
    const trackRequest = request => {
        if (!request || typeof request.always !== 'function') return request;
        controllerRequests.add(request);
        request.always(() => controllerRequests.delete(request));
        return request;
    };
    const openControllerLayer = options => {
        const originalEnd = options.end;
        let index;
        try {
            index = layer.open({
                ...options,
                end: function () {
                    controllerLayers.delete(index);
                    if (typeof originalEnd === 'function') return originalEnd.apply(this, arguments);
                }
            });
        } catch (error) {
            if (typeof originalEnd === 'function') originalEnd();
            throw error;
        }
        if (controllerActive) controllerLayers.add(index); else layer.close(index);
        return index;
    };
    const storeId = value => {
        const id = Number(value);
        return Number.isSafeInteger(id) && id > 0 ? id : 0;
    };
    if (typeof window.__mdSharedStoreDestroy === 'function') window.__mdSharedStoreDestroy();
    const openExternal = value => {
        if (!value) return false;
        const source = /^[a-z][a-z0-9+.-]*:/i.test(value) ? value : 'https://' + value;
        try {
            const url = new URL(source, window.location.origin);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return false;
            window.open(url.href, '_blank', 'noopener,noreferrer');
            return true;
        } catch (error) {
            return false;
        }
    };
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const plainMessage = value => {
        const template = document.createElement('template');
        template.innerHTML = String(value ?? '');
        return (template.content.textContent || '').trim();
    };
    const normalizeHttpUrl = value => {
        const raw = String(value || '').trim();
        if (!/^https?:\/\//i.test(raw)) return null;
        try {
            const url = new URL(raw);
            return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url : null;
        } catch (error) {
            return null;
        }
    };
    const renderStoreName = (name, domain) => {
        const safeName = escapeHtml(name || '-');
        const base = normalizeHttpUrl(domain);
        if (!base) {
            return `<span class="table-item"><span class="table-item-icon material-icons-outlined" aria-hidden="true">storefront</span><span class="table-item-name">${safeName}</span></span>`;
        }
        const favicon = new URL('/favicon.ico', base).href;
        return `<span class="table-item"><img src="${escapeHtml(favicon)}" class="table-item-icon" alt=""><span class="table-item-name">${safeName}</span></span>`;
    };
    const renderStoreLink = domain => {
        const label = escapeHtml(domain || '-');
        const url = normalizeHttpUrl(domain);
        return url ? `<a href="${escapeHtml(url.href)}" target="_blank" rel="noopener noreferrer">${label}</a>` : label;
    };

    table = new Table("/admin/api/store/data", "#shared-store-table");

    const refreshMobile = reason => {
        if (!controllerActive) return;
        clearTimeout(mobileRefreshTimer);
        mobileRefreshTimer = setTimeout(() => {
            if (!controllerActive) return;
            if (!table || table.isDestroyed || typeof table.getMobileSnapshot !== 'function' || !table.$table) {
                return;
            }
            if (typeof table.refreshMobile === 'function') {
                table.refreshMobile(reason);
                return;
            }
            const snapshot = table.getMobileSnapshot(reason);
            const payload = {table, snapshot, reason};
            const event = $.Event('admin:table:update');
            event.detail = payload;
            table.$table.trigger(event, [payload]);
        }, 0);
    };

    const modal = (title, assign = {}) => {
        const editing = Number(assign && assign.id) > 0;
        let submitting = false;
        // Only non-sensitive, editable values are allowed into Form.assign.
        // app_key is intentionally absent even if a stale caller still has it.
        const safeAssign = editing ? {
            id: Number(assign.id),
            type: Number(assign.type),
            domain: String(assign.domain || ''),
            app_id: String(assign.app_id || '')
        } : {};
        component.popup({
            submit: (data, index) => {
                if (!controllerActive || submitting) return;
                submitting = true;
                util.post({
                    url: '/admin/api/store/save',
                    data: data,
                    done: res => {
                        if (!controllerActive) return;
                        if (index !== undefined && index !== null) layer.close(index);
                        message.success(res?.msg && res.msg !== 'success' ? (plainMessage(res.msg) || '店铺已保存') : '店铺已保存');
                        if (table && !table.isDestroyed) table.refresh();
                    },
                    error: res => {
                        submitting = false;
                        if (controllerActive) message.error(plainMessage(res?.msg) || '店铺保存失败，请检查地址和凭据。');
                    },
                    fail: () => {
                        submitting = false;
                        if (controllerActive) message.error('网络异常，店铺资料未保存。');
                    }
                });
            },
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "协议",
                            name: "type",
                            type: "select",
                            placeholder: "请选择协议",
                            dict: "_shared_type",
                            default: 0,
                            required: true
                        },
                        {
                            title: "店铺地址",
                            name: "domain",
                            type: "input",
                            placeholder: "需要带http://或者https://(推荐,如果支持)",
                            required: true
                        },
                        {
                            title: "商户ID", name: "app_id", type: "input", placeholder: "请输入商户ID",
                            required: true,
                            regex: {
                                value: '^[A-Za-z0-9._:@-]{1,32}$',
                                message: '商户ID必须是 1–32 位字母、数字或 . _ : @ -'
                            }
                        },
                        {
                            title: "商户密钥",
                            name: "app_key",
                            type: "password",
                            placeholder: editing ? "不修改请留空" : "请输入商户密钥",
                            required: !editing,
                            regex: {
                                value: '^[^\\s\\x00-\\x1F\\x7F]{1,64}$',
                                message: '商户密钥必须是 1–64 位且不能包含空白字符'
                            }
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            assign: safeAssign,
            width: "580px",
            renderComplete: unique => {
                const $form = $('.' + unique);
                $form.find('input[name="domain"]').attr({
                    inputmode: 'url',
                    autocomplete: 'url',
                    autocapitalize: 'none',
                    spellcheck: 'false',
                    maxlength: '128'
                });
                $form.find('input[name="app_id"]').attr({
                    autocomplete: 'off',
                    autocapitalize: 'none',
                    spellcheck: 'false',
                    maxlength: '32'
                });
                $form.find('input[name="app_key"]')
                    .attr({
                        type: 'password',
                        autocomplete: 'new-password',
                        autocapitalize: 'none',
                        spellcheck: 'false',
                        maxlength: '64'
                    })
                    .val('');
            }
        });
    }


    table.setColumns([
        {checkbox: true}
        , {
            field: 'name', title: '店铺名称', formatter: (a, b) => {
                return renderStoreName(a, b?.domain);
            }
        }, {
            field: 'domain', title: '店铺地址', formatter: renderStoreLink
        }, {
            field: 'balance', title: '余额(缓存)', formatter: _ => format.money(_, "var(--md-success)")
        }, {
            field: 'status', title: '状态', formatter: function (val, item) {
                if (item.__mobileConnectStatus) {
                    return format.badge(
                        escapeHtml(item.__mobileConnectStatus.message),
                        item.__mobileConnectStatus.success ? "a-badge-success" : "a-badge-danger"
                    );
                }
                return '<span class="connect-' + item.id + '"><span class="badge badge-light-primary">连接中..</span></span>'
            }
        }, {
            field: 'type', title: '协议', dict: "_shared_type"
        },
        {
            field: 'operation', class: "nowrap", title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-arrows-rotate',
                    tips: "一键同步此店铺下的所有本地商品数据",
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        const id = storeId(row?.id);
                        if (!id) {
                            message.error('店铺编号无效，请刷新页面后重试');
                            return;
                        }
                        let logPid = _LogPid = util.generateRandStr(16);

                        trackRequest($.get(`/admin/api/store/getSyncRemoteLog?id=${id}`)).done(response => {
                            if (!controllerActive) return;
                            if (response?.code !== 200) {
                                message.error(plainMessage(response?.msg) || '同步日志读取失败');
                                return;
                            }
                            const data = response?.data || {};
                            const mobile = mobileAdminEnabled();
                            let syncing = false;
                            let $logText = null;
                            openControllerLayer({
                                type: 1,
                                shade: 0.4,
                                shadeClose: true,
                                title: '<i class="fa-duotone fa-regular fa-ban-bug"></i> 同步日志',
                                btn: [util.icon("fa-duotone fa-regular fa-arrows-rotate") + "<span class='sync-item-btn'>开始同步</span>", util.icon(`fa-duotone fa-regular fa-broom-wide`) + "清空日志", util.icon("fa-duotone fa-regular fa-xmark") + "关闭"],
                                content: '<textarea class="log-textarea form-control" style="width:100%;height:100%;resize:none;"></textarea>',
                                area: mobile ? ["100%", "100%"] : ["860px", "660px"],
                                skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task admin-mobile-layer-popup--danger-action md-store-sync-log-layer' : 'md-store-sync-log-layer',
                                maxmin: !mobile,
                                resize: !mobile,
                                move: !mobile,
                                btn1: (index, layero) => {
                                    if (syncing) {
                                        layer.msg("同步任务正在进行，请勿重复提交");
                                        return false;
                                    }
                                    const startSync = () => {
                                        if (!controllerActive || _LogPid !== logPid || syncing) return;
                                        syncing = true;
                                        layer.msg("开始同步，请观察日志..");
                                        layero.find('.sync-item-btn').html("正在同步..");
                                        trackRequest($.post(`/admin/api/store/syncRemote?id=${id}`))
                                            .done(res => {
                                                if (!controllerActive || _LogPid !== logPid) return;
                                                if (res?.code === 200) {
                                                    layer.msg(escapeHtml(plainMessage(res?.msg) || "同步任务已结束"));
                                                } else {
                                                    message.error(plainMessage(res?.msg) || "同步任务执行失败，请检查同步日志");
                                                }
                                            })
                                            .fail((xhr, status) => {
                                                if (!controllerActive || _LogPid !== logPid || status === 'abort') return;
                                                message.error("网络异常，无法确认同步任务状态，请检查同步日志后再操作");
                                            })
                                            .always(() => {
                                                syncing = false;
                                                if (!controllerActive || _LogPid !== logPid) return;
                                                layero.find('.sync-item-btn').html("开始同步");
                                            });
                                    };
                                    if (mobileAdminEnabled()) {
                                        message.ask('同步会批量更新该远端店铺关联的本地商品数据。确认现在开始吗？', startSync, '确认同步商品？', '开始同步');
                                    } else {
                                        startSync();
                                    }
                                    return false;
                                },
                                btn2: (index, layero) => {
                                    message.ask('清空后，当前店铺的全部同步日志将被永久删除，且无法恢复。确认继续吗？', () => {
                                        if (!controllerActive || _LogPid !== logPid) return;
                                        trackRequest($.post(`/admin/api/store/clearSyncRemoteLog?id=${id}`))
                                            .done(res => {
                                                if (!controllerActive || _LogPid !== logPid) return;
                                                if (res?.code !== 200) {
                                                    message.error(plainMessage(res?.msg) || '同步日志清空失败');
                                                    return;
                                                }
                                                layer.msg("日志已清空");
                                                if ($logText) $logText.val("");
                                            })
                                            .fail((xhr, status) => {
                                                if (!controllerActive || _LogPid !== logPid || status === 'abort') return;
                                                message.error('网络异常，同步日志未清空');
                                            });
                                    }, '确认清空同步日志？', '确认清空');
                                    return false;
                                },
                                success: (layero, index) => {
                                    $logText = layero.find('.log-textarea');
                                    $logText.val(data?.log ?? '');
                                    util.timer(() => {
                                        return new Promise(resolve => {
                                            if (!controllerActive || _LogPid !== logPid) {
                                                resolve(false);
                                                return;
                                            }
                                            trackRequest($.get(`/admin/api/store/getSyncRemoteLog?id=${id}`, res => {
                                                if (!controllerActive || _LogPid !== logPid) {
                                                    resolve(false);
                                                    return;
                                                }
                                                const nextLog = res?.data?.log ?? '';
                                                if ($logText && nextLog != $logText.val()) {
                                                    $logText.val(nextLog);
                                                }
                                                resolve(true);
                                            })).fail(() => resolve(controllerActive && _LogPid === logPid));
                                        });
                                    }, 1500);
                                },
                                end: () => {
                                    $logText = null;
                                    if (_LogPid === logPid) _LogPid = null;
                                }
                            });
                        }).fail((xhr, status) => {
                            if (!controllerActive || status === 'abort') return;
                            message.error('网络异常，无法读取同步日志');
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-link',
                    tips: "接入货源",
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        const id = storeId(row?.id);
                        if (!id) {
                            message.error('店铺编号无效，请刷新页面后重试');
                            return;
                        }
                        util.post("/admin/api/store/items", {id: id}, res => {
                            if (!controllerActive) return;
                            if (!Array.isArray(res?.data)) {
                                message.error('远端商品数据格式不正确，已阻止接入');
                                return;
                            }
                            const items = new Map();
                            let importSubmitting = false;

                            res.data.forEach(group => {
                                (Array.isArray(group?.children) ? group.children : []).forEach(item => {
                                    items.set(String(item.id), item);
                                });
                            });


                            component.popup({
                            submit: (result, index) => {
                                    if (!controllerActive) return;
                                    if (importSubmitting) {
                                        layer.msg("货源正在接入，请勿重复提交");
                                        return;
                                    }
                                    const selectedItems = Array.isArray(result.auth) ? result.auth : [];
                                    if (selectedItems.length === 0) {
                                        layer.msg("至少选择一个远端店铺的商品");
                                        return;
                                    }

                                    result.item_codes = [];

                                    selectedItems.forEach(itemId => {
                                        const item = items.get(String(itemId));
                                        const code = item && typeof item.code !== 'object' ? String(item.code ?? '').trim() : '';
                                        if (code) result.item_codes.push(code);
                                    });

                                    result.item_codes = Array.from(new Set(result.item_codes));
                                    if (result.item_codes.length === 0) {
                                        layer.msg("所选远端商品已失效，请刷新后重新选择");
                                        return;
                                    }

                                    delete result.auth;

                                    importSubmitting = true;
                                    util.post({
                                        url: '/admin/api/store/addItem?storeId=' + id,
                                        data: result,
                                        done: res => {
                                            if (!controllerActive) return;
                                            layer.close(index);
                                            message.alert(escapeHtml(plainMessage(res?.msg) || '货源接入完成'), "success");
                                        },
                                        error: res => {
                                            importSubmitting = false;
                                            if (controllerActive) message.error(plainMessage(res?.msg) || "货源接入失败");
                                        },
                                        fail: () => {
                                            importSubmitting = false;
                                            if (controllerActive) message.error("网络异常，货源未接入");
                                        }
                                    });
                                },
                                tab: [
                                    {
                                        name: util.icon("fa-duotone fa-regular fa-link") + " 接入货源",
                                        form: [
                                            {
                                                title: "商品分类",
                                                name: "category_id",
                                                type: "treeSelect",
                                                placeholder: "请选择商品分类",
                                                dict: `category->owner=0,id,name,pid&tree=true`,
                                                required: true,
                                                parent: false
                                            },
                                            {
                                                title: "远端图片本地化",
                                                name: "image_download",
                                                type: "switch",
                                                tips: "启用后，导入对方商品时，会自动将对方所有图片资源下载至本地"
                                            },
                                            {
                                                title: "远端信息同步",
                                                name: "shared_sync",
                                                type: "switch",
                                                tips: "启用后，远端商品信息会实时同步本地，远端价发生变化会立即同步"
                                            },
                                            {
                                                title: "远端价格同步",
                                                name: "shared_amount_sync",
                                                type: "switch",
                                                tips: "启用后，远端的价格会实时同步本地商品"
                                            },
                                            {
                                                title: "远端配置同步",
                                                name: "shared_config_sync",
                                                type: "switch",
                                                tips: "启用后，远端的商品配置会实时同步本地商品（如种类，SKU）"
                                            },
                                            {
                                                title: "立即上架",
                                                name: "shelves",
                                                type: "switch",
                                                tips: "开启后，入库完毕后会立即上架"
                                            },
                                            {
                                                title: "加价模式",
                                                name: "premium_type",
                                                type: "radio",
                                                dict: [
                                                    {id: 0, name: "普通金额加价"},
                                                    {id: 1, name: "百分比加价(99%的人选择)"}
                                                ],
                                                default: 1,
                                                required: true
                                            },
                                            {
                                                title: "加价数额",
                                                name: "premium",
                                                type: "input",
                                                placeholder: "加价金额/百分比(小数代替)",
                                                required: true
                                            },
                                            {title: "远程商品", name: "auth", type: "treeCheckbox", dict: res.data}
                                        ]
                                    }
                                ],
                                assign: {},
                                autoPosition: true,
                                height: "auto",
                                width: "780px",
                                renderComplete: unique => {
                                    $('.' + unique + ' input[name="premium"]').attr({
                                        inputmode: 'decimal',
                                        autocomplete: 'off'
                                    });
                                },
                                done: () => {

                                }
                            });
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + " 修改远端店铺", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        message.ask("您确定要移除此远端店铺吗，此操作无法恢复", () => {
                            if (!controllerActive) return;
                            const id = storeId(row?.id);
                            if (!id) {
                                message.error('店铺编号无效，请刷新页面后重试');
                                return;
                            }
                            util.post('/admin/api/store/del', {list: [id]}, res => {
                                if (!controllerActive) return;
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-earth-asia text-primary',
                    class: 'admin-mobile-operation-only text-primary',
                    title: '访问店铺',
                    show: row => mobileAdminEnabled() && Boolean(row.domain),
                    click: (event, value, row) => openExternal(row.domain)
                }
            ]
        },
    ]);
    table.setPagination(15, [15, 30]);
    table.setSearch([
        {title: '店铺名称', name: 'search-name', type: 'input'},
        {title: '店铺地址', name: 'search-domain', type: 'input'},
        {title: '协议', name: 'equal-type', type: 'select', dict: '_shared_type'}
    ]);

    table.onComplete((a, b, c) => {
        const generation = ++connectGeneration;
        c?.data?.list?.forEach(item => {
            const id = storeId(item?.id);
            if (!id) return;
            trackRequest($.post("/admin/api/store/connect", {id: id}))
                .done(run => {
                if (!controllerActive || generation !== connectGeneration) return;
                let ins = $(".connect-" + id);
                if (run.code == 200) {
                    item.__mobileConnectStatus = {success: true, message: "正常"};
                    ins.html(format.badge("正常", "a-badge-success"));
                    $(".items-" + id).show();
                } else {
                    const failure = plainMessage(run?.msg) || "连接失败";
                    item.__mobileConnectStatus = {success: false, message: failure};
                    ins.html(format.badge(escapeHtml(failure), "a-badge-danger"));
                }
                refreshMobile('store-connect');
                })
                .fail((xhr, status) => {
                    if (!controllerActive || generation !== connectGeneration || status === 'abort') return;
                    item.__mobileConnectStatus = {success: false, message: "连接请求失败"};
                    $(".connect-" + id).html(format.badge("连接请求失败", "a-badge-danger"));
                    refreshMobile('store-connect-error');
                });
        });
    });
    table.render();


    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`${util.icon("fa-duotone fa-regular fa-link")} 添加远端店铺`);
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        connectGeneration++;
        _LogPid = null;
        clearTimeout(mobileRefreshTimer);
        mobileRefreshTimer = null;
        $('.btn-app-create').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        controllerRequests.forEach(request => {
            try { request.abort(); } catch (error) {}
        });
        controllerRequests.clear();
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (typeof Swal !== 'undefined') Swal.close();
        if (window.__mdSharedStoreDestroy === destroy) delete window.__mdSharedStoreDestroy;
    }

    window.__mdSharedStoreDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
