!function () {
    let table, _LogPid;
    const pluginUpdate = {
        items: null,
        updateNum: 0,
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
        renderButton(key, version) {
            let plugin = this.getPlugin(key);
            if (!plugin) {
                return "";
            }
            if (version != plugin.version) {
                this.updateNum++;

                $('#updateNum').html('<b style="color:red;">[' + this.updateNum + ']个插件需要更新</b>');

                return ' <span style="cursor: pointer;" class="badge badge-light-success updatePlugin">更新->' + plugin.version + '</span>';
            }
            return "";
        }
    }

    const modal = (title, assign = {}) => {
        let submit = [];
        if (typeof assign.submit === "object") {
            submit = [
                {
                    name: title,
                    form: assign.submit
                }
            ];
        } else if (typeof assign.submit === "string" && assign.submit.trim() != "") {
            submit = eval(assign.submit);
        }

        component.popup({
            submit: `/admin/api/pay/setPluginConfig?id=${assign.id}`,
            tab: submit,
            assign: assign?.config ?? [],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/api/pay/getPlugins", "#pay-plugin-table");
    table.setColumns([
        {
            field: 'plugin_name', title: '插件名称', formatter: function (val, item) {
                return `<div class="md-plugin"><img src="${item?.icon}" class="md-plugin__icon" alt=""><span class="md-plugin__name">${item?.info?.name ?? ''}</span></div>`;
            }
        }
        , {
            field: 'operation', class: "nowrap", title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-gear',
                    class: 'text-primary',
                    title: '配置',
                    show: item => item.hasOwnProperty('submit') && item.submit.length > 0,
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-gear") + " " + row?.info?.name, row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-bug',
                    title: '日志',
                    click: (event, value, row, index) => {
                        let mapItem = row, logPid = _LogPid = util.generateRandStr(16);
                        util.post('/admin/api/pay/getPluginLog', {handle: mapItem.id}, res => {
                            layer.open({
                                type: 1,
                                shade: 0.4,
                                shadeClose: true,
                                title: '<i class="fa-duotone fa-regular fa-ban-bug"></i> 日志',
                                btn: ["清空日志", "关闭"],
                                content: '<textarea class="log-textarea" style="width: 100%;height: 100%;border: none;color: grey;padding: 5px;">' + res.data.log + '</textarea>',
                                area: util.isPc() ? ["860px", "660px"] : ["100%", "100%"],
                                maxmin: true,
                                yes: (index, layero) => {
                                    util.post('/admin/api/pay/ClearPluginLog', {handle: mapItem.id}, res => {
                                        layer.msg("日志已清空");
                                    });
                                },
                                success: (layero, index) => {
                                    util.timer(() => {
                                        return new Promise(resolve => {
                                            if (_LogPid !== logPid) {
                                                resolve(false);
                                                return;
                                            }
                                            util.post({
                                                url: '/admin/api/pay/getPluginLog',
                                                data: {handle: mapItem.id},
                                                loader: false,
                                                done: res => {
                                                    if (res.data.log != $('.log-textarea').html()) {
                                                        $('.log-textarea').html(res.data.log);
                                                    }
                                                    resolve(true);
                                                }
                                            });
                                        });
                                    }, 1500);
                                },
                                end: () => {
                                    _LogPid = null;
                                }
                            });
                        });
                    }
                }
            ]
        }
        , {
            field: 'version',
            class: "nowrap",
            title: '<span id="updateNum">版本号</span>',
            formatter: function (val, item) {
                return '<span class="md-version">v' + item?.info?.version + '</span>' + pluginUpdate.renderButton(item.id, item?.info?.version);
            }
            ,
            events: {
                'click .updatePlugin': function (event, value, row, index) {
                    let plugin = pluginUpdate.getPlugin(row.id);

                    if (!plugin) {
                        message.error("初始化更新失败，请刷新页面重试");
                        return;
                    }

                    message.ask(plugin?.update_content?.replace(/\n/, "<br>"), () => {
                        util.post('/admin/api/app/upgrade', {
                            plugin_key: row.id,
                            type: plugin.type,
                            plugin_id: plugin.id
                        }, res => {
                            message.info(res.msg);
                            if (res.code == 200) {
                                window.location.reload();
                            }
                        });
                    }, `<b style="color: #1589e4;"><i class="fa-duotone fa-regular fa-sparkles"></i> ${row?.info?.name}</b> <span style="color: #0a84ff;font-size: 14px;">${row?.info?.version}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span style="color: green;font-size: 14px;">${plugin.version}</span>`, "立即更新")
                }
            }
        }
        , {
            field: 'options', title: '功能', formatter: function (val, item) {
                let list = [];
                for (const key in item.info.options) {
                    list.push(format.badge(item.info.options[key], "a-badge-success"));
                }
                return list.length ? format.badgeGroup(list.join("")) : "-";
            }
        }
        , {
            field: 'info.description',
            title: '简介',
            class: "break-spaces"
        },
        {
            field: 'config.top',
            title: 'TOP',
            class: "nowrap",
            type: "switch",
            text: "置顶|无",
            reload: true,
            change: (state, row) => {
                util.post(`/admin/api/pay/setPluginConfig?id=${row.id}`, {top: state}, done => {
                    table.$table.bootstrapTable('refresh', {
                        silent: true, pageNumber: 1
                    });
                });
            }
        },
        {
            field: 'author', title: '作者', formatter: function (val, item) {
                if (item?.info?.author == "#" || !item?.info?.author) {
                    return '-';
                }
                return '<span class="md-author"><i class="fa-duotone fa-regular fa-user"></i>' + item?.info?.author + '</span>';
            }
        }
        , {
            field: 'uninstall', title: '卸载', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask(`你想要卸载<b style="color: mediumvioletred;">${row.NAME}</b>吗，该操作会清空插件所有数据，且无法恢复，请慎重操作！`, () => {
                            util.post('/admin/api/app/uninstall', {
                                plugin_key: row.id,
                                type: 1
                            }, res => {
                                message.success("卸载成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        }
    ]);

    table.onResponse(() => {
        pluginUpdate.updateNum = 0;
        $(`#updateNum`).html("版本号");
    });

    table.disablePagination();
    table.render();
}();