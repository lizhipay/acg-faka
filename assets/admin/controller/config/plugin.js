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
        component.popup({
            submit: '/admin/api/plugin/setConfig',
            tab: [
                {
                    name: title,
                    form: assign.PLUGIN_SUBMIT
                },
            ],
            assign: assign,
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
                return `<span class="table-item"><img src="${item?.icon}" class="table-item-icon"><span class="table-item-name">${item?.NAME}</span></span>`;
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
            field: 'operation', title: '控制', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-circle-stop ',
                    class: "text-danger",
                    title: "停用",
                    show: item => item.PLUGIN_CONFIG && item.PLUGIN_CONFIG.STATUS == 1,
                    click: (event, value, row, index) => {
                        util.post("/admin/api/plugin/setConfig", {id: row.id, STATUS: 0}, res => {
                            table.refresh();
                            $('.plugin-state[data-id=' + row.id + ']').removeClass("badge-light-success").addClass("badge-light-danger").html("已停止");
                            layer.msg(res.msg);
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-circle-play',
                    class: 'text-success',
                    title: '启用',
                    show: item => item.PLUGIN_CONFIG && (item.PLUGIN_CONFIG?.STATUS == 0 || !item.PLUGIN_CONFIG?.STATUS) ,
                    click: (event, value, row, index) => {
                        util.post("/admin/api/plugin/setConfig", {id: row.id, STATUS: 1}, res => {
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
                        modal(util.icon("fa-duotone fa-regular fa-gear") + row.NAME, row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-bug',
                    title: '日志',
                    click: (event, value, row, index) => {
                        let mapItem = row, logPid = _LogPid = util.generateRandStr(16);
                        util.post('/admin/api/plugin/getPluginLog', {handle: mapItem.id}, res => {
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
                                    util.post('/admin/api/plugin/ClearPluginLog', {handle: mapItem.id}, res => {
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
                                                url: '/admin/api/plugin/getPluginLog',
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
            field: 'wiki', title: '文档手册', formatter: function (val, item) {
                if (!item.wiki) {
                    return '-';
                }
                return '<a class="badge badge-light-primary" href="' + item.wiki + '" target="_blank">查看文档</a>';
            }
        }
        , {
            field: 'version', title: '<span id="updateNum">版本号</span>', formatter: function (val, item) {
                return '<span class="badge badge-light">' + item.VERSION + '</span>' + pluginUpdate.renderButton(item.id, item.VERSION);
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
                    }, `<b style="color: #1589e4;"><i class="fa-duotone fa-regular fa-sparkles"></i> ${row.NAME}</b> <span style="color: #0a84ff;font-size: 14px;">${row.VERSION}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span style="color: green;font-size: 14px;">${plugin.version}</span>`, "立即更新")


                }
            }
        }

        , {
            field: 'DESCRIPTION',
            title: '简介',
            class: "break-spaces"
        }
        , {
            field: 'author', title: '作者', formatter: function (val, item) {
                if (item.AUTHOR == "#" || !item.AUTHOR) {
                    return '-';
                }
                return '<span class="badge badge-light"><i class="fa-duotone fa-regular fa-circle-user"></i> ' + item.AUTHOR + '</span>';
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
                                type: 0
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
    table.setSearch([
        {title: "搜索插件..", name: "keywords", type: "input"}
    ]);
    table.setState("status", [
        {id: 0, name: "未运行"},
        {id: 1, name: "正在运行"}
    ]);
    table.onResponse(() => {
        pluginUpdate.updateNum = 0;
        $(`#updateNum`).html("版本号");
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
        const startLoadIndex = layer.load(2, {shade: ['0.3', '#fff']});
        util.timer(() => {
            return new Promise(resolve => {
                $startIns.html(`正在启动 ${index}/${plugins.length}`);
                const plugin = plugins[index];
                index++;
                if (plugin && (plugin?.PLUGIN_CONFIG?.STATUS == 0 || !plugin?.PLUGIN_CONFIG?.hasOwnProperty("STATUS"))) {
                    util.post({
                        url: "/admin/api/plugin/setConfig",
                        data: {id: plugin.id, STATUS: 1},
                        done: res => {
                            $('.plugin-state[data-id=' + plugin.id + ']').removeClass("badge-light-danger").addClass("badge-light-success").html("已启动");
                            resolve(true);
                        },
                        error: () => {
                            resolve(true);
                        },
                        fail: () => {
                            resolve(true);
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
                layer.close(startLoadIndex);
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
        const $stopIns = $('.plugin-stop span');
        let index = 0;
        const startLoadIndex = layer.load(2, {shade: ['0.3', '#fff']});
        util.timer(() => {
            return new Promise(resolve => {
                $stopIns.html(`正在停止 ${index}/${plugins.length}`);
                const plugin = plugins[index];
                index++;
                if (plugin && plugin?.PLUGIN_CONFIG?.STATUS == 1) {
                    util.post({
                        url: "/admin/api/plugin/setConfig",
                        data: {id: plugin.id, STATUS: 0},
                        done: res => {
                            $('.plugin-state[data-id=' + plugin.id + ']').removeClass("badge-light-success").addClass("badge-light-danger").html("已停止");
                            resolve(true);
                        },
                        error: () => {
                            resolve(true);
                        },
                        fail: () => {
                            resolve(true);
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
                layer.close(startLoadIndex);
                resolve(false);
            });
        }, 300, true);
    });


    $('.plugin-update-all').click(() => {
        const $updateIns = $('.plugin-update-all span');

        message.ask("是否将全部插件更新至最新版？", () => {

            util.get("/admin/api/plugin/getPlugins", res => {

                let index = 0;
                const startLoadIndex = layer.load(2, {shade: ['0.3', '#fff']});

                util.timer(() => {
                    return new Promise(resolve => {
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
                                        resolve(true);
                                    },
                                    error: () => {
                                        resolve(true);
                                    },
                                    fail: () => {
                                        resolve(true);
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
                        layer.close(startLoadIndex);
                        resolve(false);
                        window.location.reload();
                    });
                }, 300, true);
            });
        });
    });
}();