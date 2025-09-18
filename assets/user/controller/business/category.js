!function () {
    let table;
    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/user/api/category/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "父级分类",
                            name: "pid",
                            type: "treeSelect",
                            dict: "category?tree=true",
                            placeholder: "父级分类，可不选"
                        },
                        {
                            title: "图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/user/api/upload/send',
                            photoAlbumUrl: '/user/api/upload/get',
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
                        {
                            title: "排序",
                            name: "sort",
                            type: "input",
                            default: 1000,
                            placeholder: "最低1000起，值越小，排名越靠前哦~"
                        },
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                    ]
                }
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

    table = new Table("/user/api/category/data", "#category-table");
    table.setUpdate("/user/api/category/save");
    table.setTree(1);
    table.setColumns([
        {field: 'icon', title: '', type: "image", style: "border-radius:25%;", width: 28},
        {
            field: 'name', title: '分类名称'
        }
        , {field: 'sort', title: '排序(越小越前)', sort: true, type: "input", reload: true}
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
            field: 'status', title: '状态', type: "switch", text: "启用|停用", reload: true
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
                            util.post('/user/api/category/del', {id: row.id}, res => {
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
        {title: "分类名称", name: "search-name", type: "input"}
    ]);
    table.setState("status", "_common_status");
    table.render();


    $('.button-add').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加分类`);
    });
}();