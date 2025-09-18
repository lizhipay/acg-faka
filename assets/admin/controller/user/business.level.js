!function () {
    let table;

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/businessLevel/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "等级图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            height: 64,
                            required: true
                        },
                        {title: "等级名称", name: "name", type: "input", placeholder: "请输入等级名称"},
                        {
                            title: "供货商手续费",
                            name: "cost",
                            type: "input",
                            placeholder: "请使用小数表达百分比",
                            tips: "商户可以发布自己的商品，那么卖出的商品，就会通过这个费率被系统扣除一定的费用，也就是手续费。"
                        },
                        {
                            title: "供货权限",
                            name: "supplier",
                            type: "switch",
                            text: "开启",
                            tips: "开启后，该等级的商户拥有供货权限。"
                        },
                        {
                            title: "分站权限",
                            name: "substation",
                            type: "switch",
                            text: "开启",
                            tips: "开启后，商户则拥有子站权限，可以使用子站功能。"
                        },
                        {
                            title: "绑定独立域名",
                            name: "top_domain",
                            type: "switch",
                            text: "开启",
                            tips: "开启后，商户的店铺可以绑定顶级域名，关闭后则只能使用子域名。"
                        },
                        {title: "购买价格", name: "price", type: "input", placeholder: "请输入该等级的购买价格"},
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "580px",
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/api/businessLevel/data", "#business-level-table");
    table.setUpdate("/admin/api/businessLevel/save");

    table.setColumns([
        {
            field: 'name', title: '等级名称', formatter: (_, __) => format.group(__)
        }
        , {
            field: 'price', title: '购买价格', formatter: _ => format.money(_, "green")
        }
        , {
            field: 'cost', title: '供货商手续费'
        }
        , {
            field: 'supplier', title: '供货权限', type: "switch" , text :"ON|OFF"
        }
        , {
            field: 'substation', title: '分站权限', type: "switch" , text :"ON|OFF"
        }
        , {
            field: 'top_domain', title: '绑定独立域名', type: "switch" , text :"ON|OFF"
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square text-primary',
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改等级", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask("是否删除该等级？", () => {
                            util.post("/admin/api/businessLevel/del", {id: row.id}, () => {
                                table.refresh();
                                message.success("删除成功");
                            })
                        });
                    }
                }
            ]
        },
    ]);
    table.render();

    $('.btn-app-create').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加等级`);
    });
}();