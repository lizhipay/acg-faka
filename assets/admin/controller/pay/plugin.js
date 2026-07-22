!function () {
    let table, _LogPid;
    const namespace = '.mdPayPluginController';
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '/favicon.ico';
        } catch (error) {
            return '/favicon.ico';
        }
    };
    const isSensitiveConfigName = name => /(?:key|secret|token|password|passwd|private|credential|signature|sign|cert|pem|salt)/i.test(String(name || ''));
    const secureConfigForms = (value, configured = {}) => {
        if (Array.isArray(value)) return value.map(item => secureConfigForms(item, configured));
        if (!value || typeof value !== 'object') return value;
        const secured = {};
        Object.keys(value).forEach(key => {
            secured[key] = secureConfigForms(value[key], configured);
        });
        if (isSensitiveConfigName(secured.name)) {
            const hasStoredValue = configured?.[secured.name] === true;
            const isRequired = secured.required === true;
            if (secured.type === 'input' || secured.type === 'password') secured.type = 'password';
            secured.default = '';
            secured.required = isRequired && !hasStoredValue;
            secured.tips = hasStoredValue
                ? '敏感信息不会回显；留空表示保留已保存的值'
                : (isRequired ? '首次配置必须填写；保存后不会回显' : '敏感信息不会回显');
        }
        return secured;
    };
    const controllerLayers = new Set();
    let controllerActive = true;
    if (typeof window.__mdPayPluginDestroy === 'function') window.__mdPayPluginDestroy();
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
    const pluginUpdate = {
        items: null,
        updateNum: 0,
        countedKeys: new Set(),
        init() {
            if (!this.items) {
                let items = localStorage.getItem("pluginVersions");
                if (items) {
                    try {
                        const parsed = JSON.parse(items);
                        this.items = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
                    } catch (error) {
                        this.items = {};
                    }
                } else {
                    this.items = {};
                }
            }
        },
        getPlugin(key) {
            this.init();
            if (!this.items || !Object.prototype.hasOwnProperty.call(this.items, key)) {
                return null;
            }
            return this.items[key];
        },
        getAvailable(key, version) {
            const plugin = this.getPlugin(key);
            return plugin && version != plugin.version ? plugin : null;
        },
        renderButton(key, version) {
            const plugin = this.getAvailable(key, version);
            if (!plugin) {
                return "";
            }

            if (!this.countedKeys.has(key)) {
                this.countedKeys.add(key);
                this.updateNum++;
            }

            $('#updateNum').html('<b class="text-danger">[' + this.updateNum + ']个插件需要更新</b>');

            return ' <span style="cursor: pointer;" class="badge badge-light-success updatePlugin">更新-&gt;' + escapeHtml(plugin.version) + '</span>';
        }
    }

    const runPluginUpdate = row => {
        const plugin = pluginUpdate.getPlugin(row.id);
        if (!plugin) {
            message.error("初始化更新失败，请刷新页面重试");
            return;
        }
        const updateContent = escapeHtml(plugin?.update_content || '该更新没有提供说明').replace(/\n/g, '<br>');
        message.ask(updateContent, () => {
            if (!controllerActive) return;
            util.post('/admin/api/app/upgrade', {
                plugin_key: row.id,
                type: plugin.type,
                plugin_id: plugin.id
            }, res => {
                if (!controllerActive) return;
                message.info(res.msg);
                if (res.code == 200) window.location.reload();
            });
        }, `<b class="text-primary"><i class="fa-duotone fa-regular fa-sparkles"></i> ${escapeHtml(row?.info?.name)}</b> <span class="text-primary" style="font-size:14px;">${escapeHtml(row?.info?.version)}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span class="text-success" style="font-size:14px;">${escapeHtml(plugin.version)}</span>`, "立即更新");
    };

    const modal = (title, assign = {}) => {
        let submit = [];
        if (Array.isArray(assign.submit)) {
            submit = [
                {
                    name: title,
                    form: assign.submit
                }
            ];
        } else if (typeof assign.submit === "string" && assign.submit.trim() != "") {
            try {
                submit = eval(assign.submit);
            } catch (error) {
                message.error('支付插件配置定义无法解析，请联系插件作者');
                return;
            }
        }
        submit = secureConfigForms(submit, assign?.sensitive_configured ?? {});
        if (!Array.isArray(submit) || submit.length === 0) {
            message.error('该支付插件没有可配置项目');
            return;
        }

        component.popup({
            submit: `/admin/api/pay/setPluginConfig?id=${encodeURIComponent(assign.id)}`,
            tab: submit,
            assign: assign?.config ?? [],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: () => {
                if (controllerActive) table.refresh();
            }
        });
    }

    table = new Table("/admin/api/pay/getPlugins", "#pay-plugin-table");
    table.setColumns([
        {
            field: 'plugin_name', title: '插件名称', formatter: function (val, item) {
                return `<div class="md-plugin"><img src="${escapeHtml(safeImageUrl(item?.icon))}" class="md-plugin__icon" alt=""><span class="md-plugin__name">${escapeHtml(item?.info?.name)}</span></div>`;
            }
        }
        , {
            field: 'operation', class: "nowrap", title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-gear',
                    class: 'text-primary',
                    title: '配置',
                    show: item => Array.isArray(item?.submit)
                        ? item.submit.length > 0
                        : (typeof item?.submit === 'string' && item.submit.trim() !== ''),
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-gear") + " " + escapeHtml(row?.info?.name), row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-bug',
                    title: '日志',
                    click: (event, value, row, index) => {
                        let mapItem = row, logPid = _LogPid = util.generateRandStr(16);
                        util.post('/admin/api/pay/getPluginLog', {handle: mapItem.id}, res => {
                            if (!controllerActive) return;
                            const mobile = mobileAdminEnabled();
                            const initialLog = res?.data?.log ?? '';
                            let $logText = null;
                            openControllerLayer({
                                type: 1,
                                shade: 0.4,
                                shadeClose: true,
                                title: '<i class="fa-duotone fa-regular fa-ban-bug"></i> 日志',
                                btn: ["清空日志", "关闭"],
                                content: '<textarea class="log-textarea form-control" style="width:100%;height:100%;resize:none;"></textarea>',
                                area: mobile ? ["100%", "100%"] : ["860px", "660px"],
                                skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task admin-mobile-layer-popup--danger-action md-pay-plugin-log-layer' : 'md-pay-plugin-log-layer',
                                maxmin: !mobile,
                                resize: !mobile,
                                move: !mobile,
                                yes: (index, layero) => {
                                    message.ask('清空后，当前支付插件的全部日志将被永久删除，且无法恢复。确认继续吗？', () => {
                                        if (!controllerActive || _LogPid !== logPid) return;
                                        util.post('/admin/api/pay/ClearPluginLog', {handle: mapItem.id}, res => {
                                            if (!controllerActive || _LogPid !== logPid || !$logText) return;
                                            $logText.val('');
                                            layer.msg("日志已清空");
                                        });
                                    }, '确认清空日志？', '确认清空');
                                    return false;
                                },
                                success: (layero, index) => {
                                    $logText = layero.find('.log-textarea').first();
                                    $logText.val(initialLog);
                                    util.timer(() => {
                                        return new Promise(resolve => {
                                            if (!controllerActive || _LogPid !== logPid || !$logText) {
                                                resolve(false);
                                                return;
                                            }
                                            util.post({
                                                url: '/admin/api/pay/getPluginLog',
                                                data: {handle: mapItem.id},
                                                loader: false,
                                                done: res => {
                                                    if (!controllerActive || _LogPid !== logPid) {
                                                        resolve(false);
                                                        return;
                                                    }
                                                    const nextLog = res?.data?.log ?? '';
                                                    if (nextLog != $logText.val()) {
                                                        $logText.val(nextLog);
                                                    }
                                                    resolve(true);
                                                },
                                                error: () => resolve(controllerActive && _LogPid === logPid),
                                                fail: () => resolve(controllerActive && _LogPid === logPid)
                                            });
                                        });
                                    }, 1500);
                                },
                                end: () => {
                                    if (_LogPid === logPid) _LogPid = null;
                                    $logText = null;
                                }
                            });
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-arrows-rotate text-success',
                    class: 'admin-mobile-operation-only text-success',
                    title: '更新插件',
                    show: row => {
                        return mobileAdminEnabled() && Boolean(pluginUpdate.getAvailable(row.id, row?.info?.version));
                    },
                    click: (event, value, row) => runPluginUpdate(row)
                }
            ]
        }
        , {
            field: 'version',
            class: "nowrap",
            title: '<span id="updateNum">版本号</span>',
            formatter: function (val, item) {
                const currentVersion = item?.info?.version;
                return '<span class="md-version">v' + escapeHtml(currentVersion) + '</span>' + pluginUpdate.renderButton(item.id, currentVersion);
            }
            ,
            events: {
                'click .updatePlugin': function (event, value, row, index) {
                    runPluginUpdate(row);
                }
            }
        }
        , {
            field: 'options', title: '功能', formatter: function (val, item) {
                let list = [];
                for (const key in (item?.info?.options || {})) {
                    list.push(format.badge(escapeHtml(item.info.options[key]), "a-badge-success"));
                }
                return list.length ? format.badgeGroup(list.join("")) : "-";
            }
        }
        , {
            field: 'info.description',
            title: '简介',
            class: "break-spaces",
            formatter: value => escapeHtml(value)
        },
        {
            field: 'config.top',
            title: 'TOP',
            class: "nowrap",
            type: "switch",
            text: "置顶|无",
            reload: true,
            change: (state, row) => {
                util.post({
                    url: `/admin/api/pay/setPluginConfig?id=${encodeURIComponent(row.id)}`,
                    data: {top: state},
                    done: () => {
                        if (!controllerActive) return;
                        table.$table.bootstrapTable('refresh', {silent: true, pageNumber: 1});
                    },
                    error: res => {
                        if (!controllerActive) return;
                        message.error(res?.msg || '置顶状态保存失败');
                        table.refresh(true);
                    },
                    fail: () => {
                        if (!controllerActive) return;
                        message.error('网络异常，置顶状态未保存');
                        table.refresh(true);
                    }
                });
            }
        },
        {
            field: 'author', title: '作者', formatter: function (val, item) {
                if (item?.info?.author == "#" || !item?.info?.author) {
                    return '-';
                }
                return '<span class="md-author"><i class="fa-duotone fa-regular fa-user"></i>' + escapeHtml(item?.info?.author) + '</span>';
            }
        }
        , {
            field: 'uninstall', title: '卸载', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask(`你想要卸载 <b class="text-danger">${escapeHtml(row?.info?.name ?? row.id)}</b> 吗，该操作会清空插件所有数据，且无法恢复，请慎重操作！`, () => {
                            if (!controllerActive) return;
                            util.post('/admin/api/app/uninstall', {
                                plugin_key: row.id,
                                type: 1
                            }, res => {
                                if (!controllerActive) return;
                                message.success("卸载成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        }
    ]);

    table.onResponse(response => {
        pluginUpdate.updateNum = 0;
        pluginUpdate.countedKeys.clear();
        $(`#updateNum`).html("版本号");
        (response?.data?.list ?? []).forEach(item => {
            const available = pluginUpdate.getAvailable(item.id, item?.info?.version);
            item.__adminMobilePayUpdateVersion = available?.version ?? '';
        });
    });

    table.disablePagination();
    table.render();

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        _LogPid = null;
        $(document).off('pjax:beforeReplace' + namespace);
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (typeof Swal !== 'undefined') Swal.close();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdPayPluginDestroy === destroy) delete window.__mdPayPluginDestroy;
    }

    window.__mdPayPluginDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
