!function () {
    let table;
    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/manage/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "头像", name: "avatar", type: "image", uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get', placeholder: "请选择图片", width: 100
                        },
                        {title: "Email", name: "email", type: "input", placeholder: "请输入邮箱"},
                        {title: "呢称", name: "nickname", type: "input", placeholder: "请输入呢称"},
                        {title: "密码", name: "password", type: "input", placeholder: "请输入密码"},
                        {
                            title: "类型", name: "type", type: "radio", dict: "_manage_type", default: 1
                        },
                        {title: "备注", name: "note", type: "input", placeholder: "备注信息"},
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

    table = new Table("/admin/api/manage/data", "#manage-table");
    table.setUpdate("/admin/api/category/save");
    table.setColumns([
        {
            field: 'avatar', title: '管理员', formatter: function (val, item) {
                if (!item.avatar) {
                    item.avatar = "/favicon.ico";
                }
                return '<span class="badge badge-light-dark"><img src="' + item.avatar + '"  style="width: 18px;border-radius: 100%;"/> ' + item.nickname + '</span> '
            }
        }
        , {field: 'email', title: '邮箱'}
        , {
            field: 'status', title: '状态', formatter: function (val, item) {
                if (item.status == 1) {
                    return format.badge("正常", "a-badge-success");
                }
                return format.badge("禁用", "a-badge-danger");
            }
        }
        , {
            field: 'type', title: '类型', dict: "_manage_type"
        }
        , {field: 'note', title: '备注'}
        , {field: 'create_time', title: '创建时间'}
        , {field: 'login_time', title: '登录时间'}
        , {field: 'login_ip', title: '登录IP'}
        , {field: 'last_login_time', title: '上次登录时间'}
        , {field: 'last_login_ip', title: '上次登录IP'},

        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + " 修改管理员", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask("是否删除此管理员？", () => {
                            util.post('/admin/api/manage/del', {list: [row.id]}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        }
    ]);

    table.setState("status", "_manage_type");
    table.render();

    $('.btn-app-create').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 创建管理员`);
    });
}();