!function () {
    let table, _LogPid;
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const controllerLayers = new Set();
    let controllerActive = true;
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
    const trackControllerLayer = index => {
        if (controllerActive) controllerLayers.add(index); else layer.close(index);
        return index;
    };
    const closeControllerLayer = index => {
        controllerLayers.delete(index);
        layer.close(index);
    };
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const normalizeHttpUrl = value => {
        const source = String(value ?? '').trim();
        if (!source || /[\u0000-\u0020\u007f-\u009f\\]/.test(source)) return null;
        if (!/^(?:https?:\/\/|\/\/|\/(?!\/))/i.test(source)) return null;
        try {
            const url = new URL(source, window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url : null;
        } catch (error) {
            return null;
        }
    };
    const sanitizePluginDisplayHtml = value => {
        const template = document.createElement('template');
        template.innerHTML = String(value ?? '');
        const allowedTags = new Set(['A', 'B', 'STRONG', 'SPAN', 'IMG', 'BR', 'EM', 'I', 'U', 'S', 'SMALL', 'CODE']);
        const dangerousTags = new Set([
            'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'TEMPLATE',
            'NOSCRIPT', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'OPTION',
            'META', 'LINK', 'BASE', 'VIDEO', 'AUDIO', 'CANVAS', 'FRAME', 'FRAMESET'
        ]);
        const normalizeColor = value => {
            const probe = document.createElement('span');
            probe.style.color = String(value ?? '').trim();
            return probe.style.color;
        };
        const walk = node => {
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                const tag = String(child.tagName || '').toUpperCase();
                if (!allowedTags.has(tag)) {
                    if (dangerousTags.has(tag)) {
                        child.remove();
                    } else {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                    }
                    return;
                }

                const color = ['A', 'B', 'STRONG', 'SPAN', 'EM', 'I', 'U', 'S', 'SMALL', 'CODE'].includes(tag)
                    ? normalizeColor(child.style.color)
                    : '';
                const href = tag === 'A' ? normalizeHttpUrl(child.getAttribute('href')) : null;
                const src = tag === 'IMG' ? normalizeHttpUrl(child.getAttribute('src')) : null;
                const title = String(child.getAttribute('title') || '').slice(0, 200);
                const alt = String(child.getAttribute('alt') || '').slice(0, 200);
                Array.from(child.attributes).forEach(attribute => child.removeAttribute(attribute.name));

                if (color) child.style.color = color;
                if (tag === 'A') {
                    if (!href) {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                        return;
                    }
                    child.setAttribute('href', href.href);
                    if (title) child.setAttribute('title', title);
                    child.setAttribute('target', '_blank');
                    child.setAttribute('rel', 'noopener noreferrer nofollow');
                    child.setAttribute('referrerpolicy', 'no-referrer');
                }
                if (tag === 'IMG') {
                    if (!src) {
                        child.remove();
                        return;
                    }
                    child.setAttribute('src', src.href);
                    child.setAttribute('alt', alt);
                    if (title) child.setAttribute('title', title);
                    child.setAttribute('loading', 'lazy');
                    child.setAttribute('decoding', 'async');
                    child.setAttribute('referrerpolicy', 'no-referrer');
                    child.style.maxWidth = '100%';
                    child.style.maxHeight = '72px';
                    child.style.width = 'auto';
                    child.style.height = 'auto';
                    child.style.objectFit = 'contain';
                    child.style.verticalAlign = 'middle';
                }
                walk(child);
            });
        };
        walk(template.content);
        return template.innerHTML.trim();
    };
    const pluginDisplayText = value => {
        const template = document.createElement('template');
        template.innerHTML = sanitizePluginDisplayHtml(value);
        return (template.content.textContent || '').trim();
    };
    const openExternal = value => {
        const url = normalizeHttpUrl(value);
        if (!url) return false;
        window.open(url.href, '_blank', 'noopener,noreferrer');
        return true;
    };
    $(document)
        .off('pjax:beforeReplace.mdConfigPluginController')
        .one('pjax:beforeReplace.mdConfigPluginController', () => {
            controllerActive = false;
            _LogPid = null;
            controllerLayers.forEach(index => layer.close(index));
            controllerLayers.clear();
            if (typeof Swal !== 'undefined') Swal.close();
        });
    const pluginUpdate = {
        items: null,
        updateNum: 0,
        countedKeys: new Set(),
        init() {
            if (!this.items) {
                let items = localStorage.getItem("pluginVersions");
                if (items) {
                    this.items = JSON.parse(items);
                } else {
                    this.items = {};
                }
            }
        },
        getPlugin(key) {
            this.init();
            if (!this.items || !this.items.hasOwnProperty(key)) {
                return null;
            }
            return this.items[key];
        },
        getAvailable(key, version) {
            const plugin = this.getPlugin(key);
            return plugin && version != plugin.version ? plugin : null;
        },
        renderButton(key, version) {
            let plugin = this.getAvailable(key, version);
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
        }, `<b class="text-primary"><i class="fa-duotone fa-regular fa-sparkles"></i> ${escapeHtml(pluginDisplayText(row.NAME) || row.id)}</b> <span class="text-primary" style="font-size:14px;">${escapeHtml(row.VERSION)}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span class="text-success" style="font-size:14px;">${escapeHtml(plugin.version)}</span>`, "立即更新");
    };

    const modal = (title, assign = {}) => {
        let submit = [];
        if (typeof assign.PLUGIN_SUBMIT === "object") {
            submit = [
                {
                    name: title,
                    form: assign.PLUGIN_SUBMIT
                }
            ];
        } else if (typeof assign.PLUGIN_SUBMIT === "string" && assign.PLUGIN_SUBMIT.trim() != "") {
            submit = eval(assign.PLUGIN_SUBMIT);
        }


        component.popup({
            submit: '/admin/api/plugin/setConfig?id=' + assign.id,
            tab: submit,
            assign: assign?.PLUGIN_CONFIG ?? [],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/api/plugin/getPlugins", "#plugin-table");
    table.setColumns([
        {checkbox: true},
        {
            field: 'plugin_name', title: '插件名称', formatter: function (val, item) {
                const icon = normalizeHttpUrl(item?.icon);
                const iconHtml = icon ? `<img src="${escapeHtml(icon.href)}" class="md-plugin__icon" alt="">` : '<span class="md-plugin__icon material-icons-outlined" aria-hidden="true">extension</span>';
                const name = sanitizePluginDisplayHtml(item?.NAME) || escapeHtml(item?.id || '未命名插件');
                return `<div class="md-plugin">${iconHtml}<span class="md-plugin__name">${name}</span></div>`;
            }
        }
        , {
            field: 'status', title: '状态', formatter: function (val, item) {
                if (item.PLUGIN_CONFIG && item.PLUGIN_CONFIG.STATUS == 1) {
                    return '<span class="badge badge-light-success plugin-state" data-id="' + item.id + '">运行中</span>';
                }
                return '<span class="badge badge-light-danger plugin-state" data-id="' + item.id + '">未启用</span>';
            }
        }
        , {
            field: 'operation', title: '控制', class: "nowrap", type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-circle-stop ',
                    class: "text-danger",
                    title: "停用",
                    show: item => item.PLUGIN_CONFIG && item.PLUGIN_CONFIG.STATUS == 1,
                    click: (event, value, row, index) => {
                        const stopPlugin = () => {
                            if (!controllerActive) return;
                            util.post("/admin/api/plugin/setConfig", {id: row.id, STATUS: 0}, res => {
                                if (!controllerActive) return;
                                table.refresh();
                                $('.plugin-state[data-id=' + row.id + ']').removeClass("badge-light-success").addClass("badge-light-danger").html("已停止");
                                layer.msg(res.msg);
                            });
                        };
                        if (mobileAdminEnabled()) {
                            message.ask(`停用后，插件 <b class="text-danger">${escapeHtml(pluginDisplayText(row.NAME) || row.id)}</b> 的相关功能将立即停止。确认继续吗？`, stopPlugin, '确认停用插件？', '确认停用');
                        } else {
                            stopPlugin();
                        }
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-circle-play',
                    class: 'text-success',
                    title: '启用',
                    show: item => item.PLUGIN_CONFIG && (item.PLUGIN_CONFIG?.STATUS == 0 || !item.PLUGIN_CONFIG?.STATUS),
                    click: (event, value, row, index) => {
                        util.post("/admin/api/plugin/setConfig", {id: row.id, STATUS: 1}, res => {
                            if (!controllerActive) return;
                            table.refresh();
                            $('.plugin-state[data-id=' + row.id + ']').removeClass("badge-light-danger").addClass("badge-light-success").html("已启动");
                            layer.msg(res.msg);
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-gear',
                    class: 'text-primary',
                    title: '配置',
                    show: item => item.hasOwnProperty('PLUGIN_SUBMIT') && item.PLUGIN_SUBMIT.length > 0,
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-gear") + escapeHtml(pluginDisplayText(row.NAME) || row.id), row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-bug',
                    title: '日志',
                    click: (event, value, row, index) => {
                        let mapItem = row, logPid = _LogPid = util.generateRandStr(16);
                        util.post('/admin/api/plugin/getPluginLog', {handle: mapItem.id}, res => {
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
                                skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task admin-mobile-layer-popup--danger-action md-plugin-log-layer' : 'md-plugin-log-layer',
                                maxmin: !mobile,
                                resize: !mobile,
                                move: !mobile,
                                yes: (index, layero) => {
                                    message.ask('清空后，当前插件的全部日志将被永久删除，且无法恢复。确认继续吗？', () => {
                                        if (!controllerActive || _LogPid !== logPid) return;
                                        util.post('/admin/api/plugin/ClearPluginLog', {handle: mapItem.id}, res => {
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
                                                url: '/admin/api/plugin/getPluginLog',
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
                        const plugin = pluginUpdate.getPlugin(row.id);
                        return mobileAdminEnabled() && Boolean(plugin) && row.VERSION != plugin.version;
                    },
                    click: (event, value, row) => runPluginUpdate(row)
                },
                {
                    icon: 'fa-duotone fa-regular fa-file-lines text-primary',
                    class: 'admin-mobile-operation-only text-primary',
                    title: '查看文档',
                    show: row => mobileAdminEnabled() && Boolean(row.wiki),
                    click: (event, value, row) => openExternal(row.wiki)
                }
            ]
        }
        , {
            field: 'wiki', title: 'Wiki', formatter: function (val, item) {
                if (!item.wiki) {
                    return '-';
                }
                const wiki = normalizeHttpUrl(item.wiki);
                return wiki ? '<a class="badge badge-light-primary" href="' + escapeHtml(wiki.href) + '" target="_blank" rel="noopener noreferrer">文档</a>' : '-';
            }
        }
        , {
            field: 'version',
            class: "nowrap",
            title: '<span id="updateNum">版本号</span>',
            formatter: function (val, item) {
                return '<span class="md-version">v' + escapeHtml(item.VERSION) + '</span>' + pluginUpdate.renderButton(item.id, item.VERSION);
            }
            ,
            events: {
                'click .updatePlugin': function (event, value, row, index) {
                    runPluginUpdate(row);
                }
            }
        }

        , {
            field: 'DESCRIPTION',
            title: '简介',
            class: "break-spaces",
            formatter: value => sanitizePluginDisplayHtml(value) || '-'
        },
        {
            field: 'PLUGIN_CONFIG.top',
            title: 'TOP',
            class: "nowrap",
            type: "switch",
            text: "置顶|无",
            reload: true,
            change: (state, row) => {
                util.post('/admin/api/plugin/setConfig?id=' + row.id, {top: state}, done => {
                    table.$table.bootstrapTable('refresh', {
                        silent: true, pageNumber: 1
                    });
                });
            }
        },
        {
            field: 'author', title: '作者', formatter: function (val, item) {
                if (item.AUTHOR == "#" || !item.AUTHOR) {
                    return '-';
                }
                return '<span class="md-author"><i class="fa-duotone fa-regular fa-user"></i>' + escapeHtml(item.AUTHOR) + '</span>';
            }
        }
        , {
            field: 'uninstall', title: '卸载', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask(`你想要卸载 <b class="text-danger">${escapeHtml(pluginDisplayText(row.NAME) || row.id)}</b> 吗，该操作会清空插件所有数据，且无法恢复，请慎重操作！`, () => {
                            if (!controllerActive) return;
                            util.post('/admin/api/app/uninstall', {
                                plugin_key: row.id,
                                type: 0
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
    table.setSearch([
        {title: "搜索插件..", name: "keywords", type: "input"}
    ]);
    table.setState("status", [
        {id: 0, name: "未运行"},
        {id: 1, name: "正在运行"}
    ]);
    table.onResponse(response => {
        pluginUpdate.updateNum = 0;
        pluginUpdate.countedKeys.clear();
        $(`#updateNum`).html("版本号");
        (response?.data?.list ?? []).forEach(item => {
            const available = pluginUpdate.getAvailable(item.id, item.VERSION);
            item.__adminMobilePluginUpdateVersion = available?.version ?? '';
        });
    });
    table.disablePagination();
    table.render();


    $('.plugin-start').click(() => {
        let plugins = table.getSelections();
        if (plugins.length == 0) {
            layer.msg("请至少勾选1个插件进行操作！");
            return;
        }
        const $startIns = $('.plugin-start span');

        let index = 0;
        const startLoadIndex = trackControllerLayer(layer.load(2, {shade: [0.3, 'var(--md-surface)']}));
        util.timer(() => {
            return new Promise(resolve => {
                if (!controllerActive) {
                    resolve(false);
                    return;
                }
                $startIns.html(`正在启动 ${index}/${plugins.length}`);
                const plugin = plugins[index];
                index++;
                if (plugin && (plugin?.PLUGIN_CONFIG?.STATUS == 0 || !plugin?.PLUGIN_CONFIG?.hasOwnProperty("STATUS"))) {
                    util.post({
                        url: "/admin/api/plugin/setConfig",
                        data: {id: plugin.id, STATUS: 1},
                        done: res => {
                            if (controllerActive) $('.plugin-state[data-id=' + plugin.id + ']').removeClass("badge-light-danger").addClass("badge-light-success").html("已启动");
                            resolve(controllerActive);
                        },
                        error: () => {
                            resolve(controllerActive);
                        },
                        fail: () => {
                            resolve(controllerActive);
                        },
                        loader: false
                    });
                    return;
                } else if (plugin && plugin?.PLUGIN_CONFIG?.STATUS != 0) {
                    resolve(true);
                    return;
                }

                table.refresh();
                $startIns.html(`启动插件`);
                closeControllerLayer(startLoadIndex);
                resolve(false);
            });
        }, 300, true);
    });

    $('.plugin-stop').click(() => {
        let plugins = table.getSelections();
        if (plugins.length == 0) {
            layer.msg("请至少勾选1个插件进行操作！");
            return;
        }
        const stopPlugins = () => {
            const $stopIns = $('.plugin-stop span');
            let index = 0;
            const startLoadIndex = trackControllerLayer(layer.load(2, {shade: [0.3, 'var(--md-surface)']}));
            util.timer(() => {
                return new Promise(resolve => {
                    if (!controllerActive) {
                        resolve(false);
                        return;
                    }
                    $stopIns.html(`正在停止 ${index}/${plugins.length}`);
                    const plugin = plugins[index];
                    index++;
                    if (plugin && plugin?.PLUGIN_CONFIG?.STATUS == 1) {
                        util.post({
                            url: "/admin/api/plugin/setConfig",
                            data: {id: plugin.id, STATUS: 0},
                            done: res => {
                                if (controllerActive) $('.plugin-state[data-id=' + plugin.id + ']').removeClass("badge-light-success").addClass("badge-light-danger").html("已停止");
                                resolve(controllerActive);
                            },
                            error: () => {
                                resolve(controllerActive);
                            },
                            fail: () => {
                                resolve(controllerActive);
                            },
                            loader: false
                        });
                        return;
                    } else if (plugin && plugin?.PLUGIN_CONFIG?.STATUS != 1) {
                        resolve(true);
                        return;
                    }

                    table.refresh();
                    $stopIns.html(`停止插件`);
                    closeControllerLayer(startLoadIndex);
                    resolve(false);
                });
            }, 300, true);
        };
        if (mobileAdminEnabled()) {
            message.ask(`将停止已选中的 ${plugins.length} 个插件，相关功能会立即不可用。确认继续吗？`, stopPlugins, '确认批量停用？', '确认停用');
        } else {
            stopPlugins();
        }
    });


    $('.plugin-update-all').click(() => {
        const $updateIns = $('.plugin-update-all span');

        message.ask("是否将全部插件更新至最新版？", () => {
            if (!controllerActive) return;

            util.get("/admin/api/plugin/getPlugins", res => {
                if (!controllerActive) return;

                let index = 0;
                const startLoadIndex = trackControllerLayer(layer.load(2, {shade: [0.3, 'var(--md-surface)']}));

                util.timer(() => {
                    return new Promise(resolve => {
                        if (!controllerActive) {
                            resolve(false);
                            return;
                        }
                        $updateIns.html(`正在检查并更新 ${index}/${res?.list?.length}`);
                        const plugin = res?.list[index];

                        index++;
                        if (plugin) {
                            const pluginNew = pluginUpdate.getPlugin(plugin?.PLUGIN_NAME);
                            if (!pluginNew) {
                                resolve(true);
                                return;
                            }

                            if (plugin.VERSION != pluginNew.version) {
                                util.post({
                                    url: '/admin/api/app/upgrade',
                                    data: {
                                        plugin_key: plugin.id,
                                        type: plugin.type,
                                        plugin_id: pluginNew.id
                                    },
                                    done: () => {
                                        resolve(controllerActive);
                                    },
                                    error: () => {
                                        resolve(controllerActive);
                                    },
                                    fail: () => {
                                        resolve(controllerActive);
                                    },
                                    loader: false
                                });

                                return;
                            }
                            resolve(true);
                            return;
                        }

                        table.refresh();
                        $updateIns.html(`一键更新全部插件`);
                        closeControllerLayer(startLoadIndex);
                        resolve(false);
                        if (controllerActive) window.location.reload();
                    });
                }, 300, true);
            });
        });
    });
}();
