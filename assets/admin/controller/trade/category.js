!function () {
    let table;
    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/category/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            name: "user_level_config",
                            type: "textarea",
                            hide: true
                        },
                        {
                            title: "父级分类",
                            name: "pid",
                            type: "treeSelect",
                            dict: "category->owner=0,id,name",
                            placeholder: "父级分类，可不选"
                        },
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
                            title: "分类名称",
                            name: "name",
                            type: "textarea",
                            height: 38,
                            placeholder: "请输入分类名称",
                            required: true
                        },
                        {title: "排序", name: "sort", type: "input", placeholder: "值越小，排名越靠前哦~"},
                        {
                            title: "隐藏分类",
                            name: "hide",
                            type: "switch",
                            text: "是",
                            default: 0,
                            tips: "隐藏分类后，游客将看不见该分类，但你可以通过右侧的《会员等级》来进行对指定的会员等级显示。"
                        },
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-user") + " 会员等级",
                    form: [
                        {
                            name: "user",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="mcy-card"><table id="category-group-table"></table></div>`);

                                util.get("/admin/api/group/data", res => {
                                    let raw = form.getData("user_level_config");
                                    let configStr = raw ? decodeURIComponent(raw) : "{}";
                                    let config = {};

                                    try {
                                        config = JSON.parse(configStr);
                                        if (typeof config != "object") {
                                            config = {};
                                        }
                                    } catch (e) {
                                        config = {};
                                    }

                                    for (let i = 0; i < res.list.length; i++) {
                                        res.list[i]['show'] = config[res.list[i].id]?.show ? 1 : 0;
                                    }

                                    const groupTable = new Table(res.list, dom.find('#category-group-table'));

                                    groupTable.setColumns([
                                        {
                                            field: 'name',
                                            title: '会员',
                                            class: 'nowrap',
                                            formatter: (_, __) => format.group(__)
                                        },
                                        {
                                            field: 'show',
                                            title: '绝对显示',
                                            type: 'switch',
                                            text: "启用|关闭",
                                            change: (_, __) => {
                                                config[__.id] = {"show": _};
                                                form.setTextarea("user_level_config", JSON.stringify(config));
                                            }
                                        }
                                    ]);
                                    groupTable.render();
                                });
                            }
                        }
                    ]
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

    table = new Table("/admin/api/category/data", "#category-table");
    table.setUpdate("/admin/api/category/save");
    table.setTree(3);
    table.setColumns([
        {checkbox: true},
        {field: 'icon', title: '', type: "image", style: "border-radius:25%;", width: 28},
        {
            field: 'owner', title: '创建者', formatter: format.owner
        },
        {
            field: 'name', title: '分类名称'
        }
        ,  {field: 'sort', title: '排序(越小越前)', sort: true, type: "input", reload: true}
        , {
            field: 'share_url', title: '推广链接', type: "button", buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-copy',
                    class: "text-primary",
                    title: "复制",
                    click: (event, value, row, index) => {
                        util.copyTextToClipboard(row.share_url, () => {
                            message.success("复制成功");
                        });
                    }
                },
            ]
        }
        , {
            field: 'hide', title: '隐藏', type: "switch", text: "隐藏|未隐藏"
        }
        , {
            field: 'status', title: '状态', type: "switch", text: "启用|停用"
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改分类", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask("是否删除此分类？", () => {
                            util.post('/admin/api/category/del', {list: [row.id]}, res => {
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
        {
            title: "商家，默认主站",
            name: "user_id",
            type: "remoteSelect",
            dict: "user->business_level>0,id,username"
        },
        {title: "分类名称", name: "search-name", type: "input"}
    ]);
    table.setState("status", "_common_status");

    table.render();


    $('.btn-app-create').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加分类`);
    });

    $('.btn-app-del').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个商品分类进行操作！");
            return;
        }

        message.ask("注意，删除分类后无法恢复", () => {
            util.post("/admin/api/category/del", {list: data}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });

    $('.start').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个分类进行操作！");
            return;
        }
        message.ask(null, () => {
            util.post("/admin/api/category/status", {list: data, status: 1}, res => {
                message.success("启用成功");
                table.refresh();
            });
        });
    });

    $('.stop').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个分类进行操作！");
            return;
        }
        message.ask(null, () => {
            util.post("/admin/api/category/status", {list: data, status: 0}, res => {
                message.success("停用成功");
                table.refresh();
            });
        });
    });


}();