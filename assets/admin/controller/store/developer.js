!function () {
    let pluginUnbindTable, proUnbindTable, _GroupPrice;
    const table = new Table("/admin/api/app/developerPlugins", "#dev-plugin-table");
    const $StoreContent = $(`.store-content`).parent();

    function _Modal() {
        component.popup({
            submit: '/admin/api/app/developerCreatePlugin',
            tab: [
                {
                    name: `${util.icon("fa-duotone fa-regular fa-layer-plus")} 创建插件`,
                    form: [
                        {
                            title: "插件图标",
                            name: "icon",
                            type: "image",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            placeholder: "120*120",
                            required: true,
                            width: 60
                        },
                        {
                            title: "插件标识",
                            name: "plugin_key",
                            required: true,
                            type: "input",
                            placeholder: "插件唯一标识，仅支持字母，也就是你插件文件夹的名字"
                        },
                        {
                            title: "插件名字",
                            name: "plugin_name",
                            required: true,
                            type: "input",
                            placeholder: "插件名称"
                        },

                        {
                            title: "插件类型",
                            name: "type",
                            required: true,
                            type: "radio",
                            dict: "_store_plugin_type",
                            default: 0
                        },
                        {
                            title: "免费组",
                            name: "group",
                            type: "radio",
                            dict: [
                                {id: 0, name: "不启用"},
                                {id: 1, name: "专业版/企业版免费使用"},
                                {id: 2, name: "企业版免费使用"},
                            ],
                            default: 0
                        },
                        {
                            title: "版本号",
                            name: "version",
                            type: "input",
                            placeholder: "版本号",
                            required: true,
                            default: "1.0.0"
                        },
                        {
                            title: "插件简介",
                            name: "description",
                            type: "textarea",
                            placeholder: "插件简介，60字内",
                            required: true,
                            height: 100
                        },
                        {
                            title: "插件官网",
                            name: "web_site",
                            type: "input",
                            placeholder: "可以是插件演示地址，或者您的个人博客，如果是非法网站将会被替换成#"
                        },
                        {
                            title: "插件价格",
                            name: "price",
                            type: "input",
                            placeholder: "可忽略不填，自动默认免费"
                        },
                    ]
                },
            ],

            autoPosition: true,
            height: "auto",
            confirmText: `${util.icon("fa-duotone fa-regular fa-layer-plus")} 确认提交`,
            width: "680px",
            done: () => {
                table.refresh();
            }
        });
    }


    util.post({
        url: "/admin/api/app/service",
        loader: false,
        done: res => {
            if (res?.data?.id <= 0 || res?.data?.developer == 0) {
                window.location.href = "/admin/store/home";
                return;
            }

            $StoreContent.show();
            table.setColumns([
                {
                    field: 'plugin_name', title: '应用名称', formatter: function (val, item) {
                        return `<span class="table-item"><img src="${item?.icon}" class="table-item-icon"><span class="table-item-name">${item?.plugin_name}</span></span>`;

                        return `<span class="a-badge a-badge-dark"><img src="${item.icon}"  style="width: 18px;border-radius: 5px;margin-top: -2px"> ${item.plugin_name}</span>`
                    }
                }
                ,
                {
                    field: 'plugin_key', title: '标识'
                }
                ,
                {
                    field: 'type', title: '类型', dict: '_store_plugin_type'
                }
                ,
                {
                    field: 'description', title: '简介'
                },
                {
                    field: 'web_site', title: '官网', formatter: format.link
                },
                {
                    field: 'version', title: '版本', formatter: function (val, item) {
                        return '<span class="a-badge a-badge-secondary">' + item.version + '</span>';
                    }
                },
                {
                    field: 'price', title: '市场售价', formatter: function (val, item) {
                        if (item.price == 0) {
                            return format.badge(`免费`, "a-badge-success");
                        }

                        let html = " <span class='a-badge a-badge-danger'>￥" + item.price + "</span> ";
                        if (item.group == 1) {
                            html += format.badge(`专业版免费`, "a-badge-primary");
                            html += format.badge(`企业版免费`, "a-badge-success");
                        }

                        if (item.group == 2) {
                            html += format.badge(`企业版免费`, "a-badge-success");
                        }
                        return `<span class="a-badge-group nowrap">${html}</span>`;
                    }
                },
                {
                    field: 'status', title: '状态', dict: "_developer_plugin_status"
                },
                {

                    field: 'operation', title: '', type: 'button', buttons: [
                        {
                            icon: 'fa-duotone fa-regular fa-circle-dollar',
                            title: "定价",
                            show: item => item.status != 2,
                            class: "text-success",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: '/admin/api/app/developerPluginPriceSet',
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-circle-dollar")} 市场定价`,
                                            form: [
                                                {
                                                    title: false,
                                                    name: "price",
                                                    type: "input",
                                                    placeholder: "市场出售价格，0=免费"
                                                }
                                            ]
                                        },

                                    ],
                                    assign: row,
                                    autoPosition: true,
                                    maxmin: false,
                                    height: "auto",
                                    width: "280px",
                                    done: () => {
                                        table.refresh();
                                    }
                                });
                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-cloud-arrow-up',
                            title: "上传安装包",
                            show: item => item.status == 0,
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: '/admin/api/app/developerCreateKit',
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 上传安装包`,
                                            form: [
                                                {
                                                    title: false,
                                                    name: "resource",
                                                    uploadUrl: '/admin/api/upload/send',
                                                    type: "file",
                                                    exts: "zip",
                                                    acceptMime: ".zip",
                                                    placeholder: "点击上传或拖动文件(.zip)",
                                                    tips: "插件安装包请直接在您插件根目录进行打包，而不是将插件文件夹也一起打包上来，并且仅支持zip打包方式，请勿设置压缩包密码，如果插件带数据库，请将数据库安装SQL命令写到install.sql中(sql文件中不要带注释)，并且放置在插件根目录"
                                                },
                                            ]
                                        },

                                    ],
                                    assign: row,
                                    autoPosition: true,
                                    height: "auto",
                                    confirmText: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 确认提交`,
                                    width: "380px",
                                    done: () => {
                                        table.refresh();
                                    }
                                });
                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-arrows-rotate',
                            title: "更新插件",
                            show: item => item.status == 1,
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: '/admin/api/app/developerUpdatePlugin',
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 上传更新包`,
                                            form: [
                                                {
                                                    title: false,

                                                    name: "audit_resource",
                                                    uploadUrl: '/admin/api/upload/send',
                                                    type: "file",
                                                    exts: "zip",
                                                    acceptMime: ".zip",
                                                    placeholder: "点击上传或拖动文件(.zip)",
                                                    tips: '更新包说明，如果带有更新数据库的情况下，请仔细编写update.sql放置插件更新包的根目录（请使用SQL命令检测当前更改项是否可以更改再去更改,否则产生错误将使插件更新失败，并且该update.sql应该从最初始版本累计，sql文件中不要带注释），如果是支付扩展或者通用扩展，请一定要删除配置文件Config.php'
                                                },
                                                {
                                                    title: "版本号",
                                                    name: "audit_version",
                                                    type: "input",
                                                    placeholder: "这个更新包内Info信息中的版本号，请填写一致"
                                                },
                                                {
                                                    title: "更新内容",
                                                    name: "audit_update_content",
                                                    type: "textarea",
                                                    height: 200,
                                                    placeholder: "必填，否则会导致插件无法更新"
                                                },
                                            ]
                                        },
                                    ],

                                    assign: row,
                                    autoPosition: true,
                                    height: "auto",
                                    confirmText: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 确认提交`,
                                    width: "580px",
                                    done: () => {
                                        table.refresh();
                                    }
                                });
                            }
                        }
                    ]
                }
            ]);

            table.setPagination(20, [20, 50, 100, 200]);
            table.render();

            $('.developerCreatePlugin').click(() => {
                _Modal();
            });
        },
        error: () => {
            window.location.href = "/admin/store/home";
        },
        fail: () => {
            window.location.href = "/admin/store/home";
        }
    });
}();