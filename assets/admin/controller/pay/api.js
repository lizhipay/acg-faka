!function () {
    let table, plugins = [], pluginMaps = [];

    let layIndex = layer.load(1, {
        shade: [0.3, '#fff']
    });


    $.ajaxSettings.async = false;
    //加载支付
    $.post('/admin/api/pay/getPlugins', res => {
        layer.close(layIndex);
        res?.data?.list?.forEach(item => {
            plugins[item.id] = item;
        });
        pluginMaps = res?.data?.list;
    });
    $.ajaxSettings.async = true;

    let handles = [];
    pluginMaps.forEach(item => {
        handles.push({id: item.id, name: item.info.name});
    });

    let getType = function (handle, code) {
        if (handle == null) {
            return '-';
        }

        if (!plugins[handle]) {
            return '-';
        }

        return plugins[handle].info.options[code];
    }

    let getPluginName = function (handle) {
        if (handle == null) {
            return '-';
        }

        if (!plugins[handle]) {
            return '-';
        }

        return `<span class="table-item"><img src="${plugins[handle]?.icon}" class="table-item-icon"><span class="table-item-name">${plugins[handle]?.info?.name}</span></span>`;
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
                            required: true
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
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/api/pay/data", "#pay-table");
    table.setUpdate("/admin/api/pay/save");
    table.setColumns([
        {checkbox: true},
        {
            field: 'name', title: '支付名称', formatter: (_, __) => format.pay(__)
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
                    return '<span class="a-badge a-badge-success" >￥' + item.cost + '</span>';
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
                        message.ask("是否删除此支付接口？", () => {
                            util.post('/admin/api/pay/del', {list: [row.id]}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
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


    $('.btn-app-create').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加支付接口`);
    });


    $('.btn-app-del').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个支付方式进行操作！");
            return;
        }

        message.ask("您确定要删除已经选中的支付方式吗？这是不可恢复的操作！", () => {
            util.post("/admin/api/pay/del", {list: data}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });
}();