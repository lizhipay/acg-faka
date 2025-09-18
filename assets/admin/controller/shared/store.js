!function () {
    let table;

    table = new Table("/admin/api/store/data", "#shared-store-table");

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/store/save',
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
                            required: true
                        },
                        {
                            title: "商户密钥", name: "app_key", type: "input", placeholder: "请输入商户密钥",
                            required: true
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            assign: assign,
            width: "580px",
            done: (res) => {
                table.refresh();
            }
        });
    }


    table.setColumns([
        {checkbox: true}
        , {
            field: 'name', title: '店铺名称', formatter: (a, b) => {
                return `<span class="table-item"><img src="${b.domain}/favicon.ico" class="table-item-icon"><span class="table-item-name">${a}</span></span>`;
            }
        }, {
            field: 'domain', title: '店铺地址' , formatter : format.link
        }, {
            field: 'balance', title: '余额(缓存)', formatter: _ => format.money(_, "green")
        }, {
            field: 'status', title: '状态', formatter: function (val, item) {
                return '<span class="connect-' + item.id + '"><span class="badge badge-light-primary">连接中..</span></span>'
            }
        }, {
            field: 'type', title: '协议', dict: "_shared_type"
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-link',
                    tips: "接入货源",
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        util.post("/admin/api/store/items", {id: row.id}, res => {
                            let items = {};

                            res.data.forEach(value => {
                                value.children.forEach(item => {
                                    items[item.id] = item;
                                });
                            });


                            component.popup({
                                submit: (result, index) => {
                                    if (result.auth.length == 0) {
                                        layer.msg("至少选择一个远端店铺的商品");
                                        return;
                                    }

                                    result.items = [];

                                    result.auth.forEach(itemId => {
                                        if (parseInt(itemId) == 0) {
                                            return;
                                        }
                                        let item = items[itemId];
                                        result.items.push(item);
                                    });

                                    delete result.auth;

                                    result.store_id = row.id;

                                    util.post('/admin/api/store/addItem?storeId=' + row.id, result, res => {
                                        message.alert(res.msg, "success")
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
                            util.post('/admin/api/store/del', {list: [row.id]}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);
    table.setPagination(15, [15, 30]);

    table.onComplete((a, b, c) => {
        c?.data?.list?.forEach(item => {
            $.post("/admin/api/store/connect", {id: item.id}, run => {
                let ins = $(".connect-" + item.id);
                if (run.code == 200) {
                    ins.html(format.badge("正常", "a-badge-success"));
                    $(".items-" + item.id).show();
                } else {
                    ins.html(format.badge(run.msg, "a-badge-danger"));
                }
            });
        });
    });
    table.render();


    $('.btn-app-create').click(function () {
        modal(`${util.icon("fa-duotone fa-regular fa-link")} 添加远端店铺`);
    });
}();