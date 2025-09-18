!function () {
    let table, CommodityGroupTable, CommodityListTable;

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/group/save',
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
                        {
                            title: "等级名称",
                            name: "name",
                            type: "input",
                            placeholder: "请输入等级名称",
                            required: true
                        },
                        {
                            title: "累计元气",
                            name: "recharge",
                            type: "input",
                            placeholder: "请输入累计元气",
                            tips: "当会员元气累计达到这个数量时，将会自动升级为该会员等级，元气=充值/消费1:1获得",
                            required: true
                        }
                    ]
                },
                {
                    name: `${util.icon("fa-duotone fa-regular fa-tags me-1")}商品折扣`,
                    hide: !assign.hasOwnProperty("id"),
                    form: [
                        {
                            title: false,
                            name: "discount_config",
                            type: "custom",
                            complete: (form, dom) => {
                                if (assign.id > 0) {
                                    form.show("discount_config");
                                    let tbody = ``;
                                    dom.html(`<div class="mcy-card"><table id="discount-config-table"></table></div>`);
                                    util.get("/admin/api/group/commodityGroupData?id=" + assign.id, data => {

                                        let discountTable = new Table(data, "#discount-config-table");


                                        discountTable.setColumns([
                                            {
                                                field: 'name', title: '商品分组'
                                            },
                                            {
                                                field: 'value',
                                                title: '折扣(百分比,如填写50,则商品价格×0.5)',
                                                type: "input"
                                            },
                                        ]);


                                        discountTable.setUpdate(data => {
                                            util.post({
                                                loader: false,
                                                url: "/admin/api/group/setDiscountConfig",
                                                data: {
                                                    group_id: assign.id,
                                                    id: data.id,
                                                    value: data.value
                                                },
                                                done: () => {
                                                    layer.msg("折扣已生效");
                                                }
                                            });


                                        });
                                        discountTable.render();
                                    });

                                }
                            }
                        }
                    ]
                },
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "480px",
            done: () => {
                table.refresh();
            }
        });
    }
    const CommodityGroupModal = (title, assign = {}) => {
            component.popup({
                submit: (_, __) => {
                    if (typeof CommodityGroupTable == "object") {
                        _.commodity_list = CommodityListTable?.getSelectionIds()?.filter(item => typeof item === 'number');
                    }
                    util.post("/admin/api/commodityGroup/save", _, () => {
                        message.success("保存成功");
                        CommodityGroupTable.refresh();
                        layer.close(__);
                    });
                },
                tab: [
                    {
                        name: title,
                        form: [
                            {title: "分组名称", name: "name", type: "input", placeholder: "请输入分组名称", required: true},
                            {
                                title: false,
                                name: "commodity_list1",
                                type: "custom",
                                hide: !assign?.id,
                                complete: (form, dom) => {
                                    if (assign.id > 0) {
                                        dom.html(`<div class="mcy-card"><table id="commodity-table"></table></div>`);
                                        CommodityListTable = new Table(`/admin/api/commodityGroup/list?id=${assign.id}`, dom.find("#commodity-table"));
                                        CommodityListTable.setTree(1);
                                        CommodityListTable.setSearch([
                                            {
                                                title: "商品关键词搜索",
                                                name: "keyword",
                                                type: "input",
                                                width: 320,
                                                align: 'center',
                                                change: (search, val) => {
                                                    CommodityListTable.fullTextSearch(val.toLowerCase());
                                                }
                                            }
                                        ], false);
                                        CommodityListTable.setColumns([
                                            {checkbox: true},
                                            {
                                                field: 'name',
                                                title: '商品名称',
                                                class: "nowrap"
                                            }
                                        ]);
                                        CommodityListTable.disablePagination();
                                        CommodityListTable.render();
                                    }
                                }
                            }
                        ]
                    }
                ],
                assign: assign,
                autoPosition: true,
                height: "auto",
                width: "720px",
                done: () => {
                    CommodityGroupTable.refresh();
                }
            });
    }

    table = new Table("/admin/api/group/data", "#user-group");
    table.setUpdate("/admin/api/group/save");
    table.setColumns([
        {
            field: 'name', title: '等级名称', formatter: (name, row) => {
                return format.group(row)
            }
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    title: "修改",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改等级", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    title: "删除",
                    click: (event, value, row, index) => {
                        message.ask("是否删除该等级？", () => {
                            util.post("/admin/api/group/del", {id: row.id}, () => {
                                table.refresh();
                                layer.msg("删除成功");
                            })
                        });
                    }
                }
            ]
        },
    ]);
    table.disablePagination();
    table.render();


    $('.btn-group-create').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加等级`);
    });


    $('.btn-commodity-group-create').click(function () {
        CommodityGroupModal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加商品分组`);
    });


    CommodityGroupTable = new Table("/admin/api/commodityGroup/data", "#commodity-group");
    CommodityGroupTable.setColumns([
        {
            field: 'name', title: '分组名称'
        },
        {
            field: 'count', title: '商品', formatter: (_, __) => __?.commodity_list?.length
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-gear',
                    class: "text-primary",
                    title: "设置",
                    click: (event, value, row, index) => {
                        CommodityGroupModal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改商品分组", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    title: "删除",
                    click: (event, value, row, index) => {
                        message.ask("您正在移除该商品分组，是否要继续？", () => {
                            util.post("/admin/api/commodityGroup/del", {list: [row.id]}, () => {
                                CommodityGroupTable.refresh();
                                message.success("删除成功");
                            })
                        });
                    }
                }
            ]
        }
    ]);
    CommodityGroupTable.disablePagination();
    CommodityGroupTable.render();

}();