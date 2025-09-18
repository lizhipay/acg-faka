!function () {
    let globalCategoryId, globalCategoryName, _ItemTable, _CateTable;

    function _OpenBusinessGroup() {
        let groupId;

        $(`.business-group`).click(function () {
            groupId = $(this).data("id");
            $('.business-group').removeClass('checked');
            $(this).addClass('checked');
        });

        $(`.payButton`).click(() => {
            if (!groupId) {
                layer.msg("请先选择要开通的套餐");
                return;
            }

            util.post("/user/api/business/purchase", {
                levelId: groupId
            }, res => {
                layer.msg("开通成功");
                window.location.href = "/user/business/index";
            });
        });


        $('.save-config').click(() => {
            util.post("/user/api/business/saveConfig", util.arrayToObject($('.form-data').serializeArray()), res => {
                message.success("保存成功");
            });
        });


        $('.category-show').click(function () {
            let status = $(this).data("state");
            util.post("/user/api/master/setCategoryAllStatus", {status: status}, res => {
                message.success("已生效");
                _CateTable.refresh();
            });
        });

        $('.commodity-show').click(function () {
            let status = $(this).data("state");
            util.post("/user/api/master/setCommodityAllStatus", {
                status: status,
                category_id: globalCategoryId
            }, res => {
                message.success("已生效");
                _ItemTable.refresh();
            });
        });


        $('.commodity-premium').click(function () {
            component.popup({
                submit: '/user/api/master/setCommodityAllPremium',
                tab: [
                    {
                        name: `<i class="fa-duotone fa-regular fa-hand-holding-dollar"></i> ${globalCategoryName ? `仅分类：<span class="text-success">${globalCategoryName}</span> 下的商品生效` : "全部商品"}`,
                        form: [
                            {title: "cid", name: "category_id", type: "input", hide: true},
                            {
                                title: "加价百分比",
                                name: "premium",
                                type: "input",
                                placeholder: "商品加价",
                                tips: "比如一个商品市场价 100 元，如果你填写了 50，售价就是：\n" +
                                    "100 + (100 × 0.5) = 150 元。\n" +
                                    "如果你的进货价是 70 元，那么最终利润就是：150 - 70 = 80 元。".replace("\n", "<br>")
                            }
                        ]
                    }
                ],
                assign: {category_id: globalCategoryId},
                autoPosition: true,
                height: "auto",
                width: "480px",
                done: () => {
                    _ItemTable.refresh();
                }
            });
        });


        $('.unbind-subdomain').click(function () {
            message.ask("您正在解绑子域名，解绑后，用户将无法再通过旧子域名访问您的店铺。", () => {
                util.post("/user/api/business/unbind", {type: 0}, res => {
                    message.success("解绑成功");
                    setTimeout(() => {
                        window.location.reload()
                    }, 500);
                })
            });
        });

        $('.unbind-topdomain').click(function () {
            message.ask("您正在解绑独立域名，解绑后，用户将无法再通过独立域名访问您的店铺。", () => {
                util.post("/user/api/business/unbind", {type: 1}, res => {
                    message.success("解绑成功");
                    setTimeout(() => {
                        window.location.reload()
                    }, 500);
                })
            });
        });

    }


    function _NoticeEditor() {
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });

        const wangEditor = window.wangEditor, uploadUrl = '/user/api/upload/send';
        const editor = new wangEditor(`.editor-container`);
        const textarea = $(`.text-container`);
        const editorContent = $('.editor-content');
        const editorWrapper = $('.editor-wrapper');
        const htmlContainer = $('.html-container');
        const business_notice_var = getVar(`_business_notice_var`);

        editor.config.onchange = function (html) {
            textarea.val(html);
        }

        editor.config.zIndex = 0;
        editor.config.uploadFileName = 'file';
        editor.config.uploadImgServer = uploadUrl + "?mime=image";
        editor.config.uploadImgAccept = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        editor.config.uploadImgMaxLength = 1;
        editor.config.uploadImgTimeout = 60 * 1000;
        editor.config.uploadImgMaxSize = 50 * 1024 * 1024;  //50M
        editor.config.uploadImgHooks = {
            customInsert: function (insertImgFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertImgFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("图片上传失败，文件可能过大");
            },
        }
        editor.config.uploadVideoServer = uploadUrl + "?mime=video";
        editor.config.uploadVideoName = 'file'
        editor.config.uploadVideoHooks = {
            customInsert: function (insertVideoFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertVideoFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("视频上传失败，文件可能过大");
            },
        }
        editor.config.height = 480;
        editor.create();

        editor.txt.html(business_notice_var);
        textarea.val(business_notice_var);


        $('.button-switch-notice').click(function () {
            let _obj = $(this);
            let type = _obj.attr("data-type");
            if (type == 0) {
                const toolbarWidth = $(`.editor-container .w-e-toolbar`).width();
                const heightDifference = toolbarWidth > 1000 ? 40 : 80;

                _obj.attr("data-type", 1);
                _obj.html('<i class="fa-duotone fa-regular fa-pen-paintbrush me-1"></i>' + i18n("写作"));
                editorWrapper.append(`<div id="notice-tmp-html" style="margin-top:10px;width:100%;height: ${480 + heightDifference}px"></div>`);
                const editor = ace.edit(`notice-tmp-html`, {
                    theme: "ace/theme/chrome",
                    mode: "ace/mode/html"
                });
                editor.getSession().setUseWrapMode(true);
                editor.setOption("showPrintMargin", false);
                editor.setValue(textarea.val());
                editor.getSession().on('change', function (delta) {
                    const currentContent = editor.getValue();
                    textarea.val(currentContent);
                });
                editorContent.hide();
                htmlContainer.fadeIn(150);
            } else {
                _obj.attr("data-type", 0);
                _obj.html('<i class="fa-duotone fa-regular fa-code me-1"></i>HTML');
                editor.txt.html(textarea.val());
                $(`#notice-tmp-html`).remove();
                editorContent.fadeIn(150);
            }
        });
    }


    function _CategoryDef() {
        _CateTable = new Table("/user/api/master/category", "#master_category");

        _CateTable.setColumns([
            {field: 'name', title: '主站名称', formatter: (_, __) => format.category(__)},
            {
                field: 'status',
                title: '状态',
                type: "button",
                buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-eye',
                        title: "显示",
                        show: item => !item.user_category || item.user_category.status == 1,
                        class: "text-success",
                        click: (event, value, row, index) => {
                            let values = row.user_category;

                            if (!values) {
                                values = {id: 0, category_id: row.id};
                            }

                            util.post("/user/api/master/setCategoryStatus", values, res => {
                                message.success("已生效");
                                _CateTable.refresh();
                            });
                        }
                    },
                    {
                        icon: 'fa-duotone fa-regular fa-eye-slash',
                        title: "隐藏",
                        show: item => item?.user_category && item?.user_category?.status == 0,
                        class: "text-danger",
                        click: (event, value, row, index) => {
                            let values = row.user_category;

                            if (!values) {
                                values = {id: 0, category_id: row.id};
                            }

                            util.post("/user/api/master/setCategoryStatus", values, res => {
                                message.success("已生效");
                                _CateTable.refresh();
                            });
                        }
                    },
                ]
            }
            , {
                field: 'user_name', title: '自定义名称', formatter: function (val, item) {
                    if (!item.user_category || !item.user_category.name) {
                        return '-';
                    }
                    return item?.user_category?.name;
                }
            },
            {
                field: 'operation',
                title: '',
                type: "button",
                buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-eye',
                        title: "查看商品",
                        class: "text-success",
                        click: (event, value, row, index) => {
                            globalCategoryId = row.id;
                            globalCategoryName = row.name;

                            _ItemTable.reload({
                                silent: false,
                                pageNumber: 1,
                                query: {category_id: row.id}
                            });
                        }
                    },
                    {
                        icon: 'fa-duotone fa-regular fa-gear',
                        title: "设置",
                        class: "text-primary",
                        click: (event, value, row, index) => {
                            let values = row.user_category;

                            if (!values) {
                                values = {category_id: row.id};
                            }

                            component.popup({
                                submit: '/user/api/master/setCategory',
                                tab: [
                                    {
                                        name: `${util.icon("fa-duotone fa-regular fa-gear")} ${row.name}`,
                                        form: [
                                            {title: "cid", name: "category_id", type: "input", hide: true},
                                            {
                                                title: "自定义名称",
                                                name: "name",
                                                type: "textarea",
                                                height: 48,
                                                placeholder: "自定义分类名称，不填写代表使用主站的，支持各种HTML美化代码"
                                            },
                                            {title: "状态", name: "status", type: "switch", text: "显示|隐藏"}
                                        ]
                                    }
                                ],
                                assign: values,
                                autoPosition: true,
                                height: "auto",
                                width: "480px",
                                done: () => {
                                    _CateTable.refresh();
                                }
                            });
                        }
                    },

                ]
            }
        ]);
        _CateTable.render();
    }


    function _ItemDef() {
        _ItemTable = new Table("/user/api/master/commodity", "#master_commodity");
        _ItemTable.setColumns([
            {field: 'name', title: '商品名称', formatter: (_, __) => format.item(__)}
            , {field: 'user_price', title: '会员价'}
            , {field: 'price', title: '游客价'}
            , {
                field: 'status',
                title: '状态',
                type: "button",
                buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-eye',
                        title: "显示",
                        show: item => !item.user_commodity || item.user_commodity.status == 1,
                        class: "text-success",
                        click: (event, value, row, index) => {
                            let values = row.user_commodity;

                            if (!values) {
                                values = {id: 0, commodity_id: row.id};
                            }

                            util.post("/user/api/master/setCommodityStatus", values, res => {
                                message.success("已生效");
                                _ItemTable.refresh();
                            });
                        }
                    },
                    {
                        icon: 'fa-duotone fa-regular fa-eye-slash',
                        title: "隐藏",
                        show: item => item?.user_commodity && item?.user_commodity?.status == 0,
                        class: "text-danger",
                        click: (event, value, row, index) => {
                            let values = row.user_commodity;

                            if (!values) {
                                values = {id: 0, commodity_id: row.id};
                            }

                            util.post("/user/api/master/setCommodityStatus", values, res => {
                                message.success("已生效");
                                _ItemTable.refresh();
                            });
                        }
                    },
                ]
            }
            , {
                field: 'user_name', title: '自定义名称', formatter: function (val, item) {
                    if (!item.user_commodity || !item.user_commodity.name) {
                        return '-';
                    }
                    return item?.user_commodity?.name;
                }
            }
            , {
                field: 'premium', title: '加价百分比', formatter: function (val, item) {
                    if (!item.user_commodity || item.user_commodity.premium == 0) {
                        return '-';
                    }
                    return format.badge(`${item.user_commodity.premium}%`, "a-badge-success");
                }
            },
            {
                field: 'operation',
                title: '',
                type: "button",
                buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-gear',
                        title: "设置",
                        class: "text-primary",
                        click: (event, value, row, index) => {
                            let values = row?.user_commodity;

                            if (!values) {
                                values = {commodity_id: row.id};
                            }

                            component.popup({
                                submit: '/user/api/master/setCommodity',
                                tab: [
                                    {
                                        name: `${util.icon("fa-duotone fa-regular fa-gear")} ${row.name}`,
                                        form: [
                                            {title: "cid", name: "commodity_id", type: "input", hide: true},
                                            {
                                                title: "自定义名称",
                                                name: "name",
                                                type: "textarea",
                                                height: 48,
                                                placeholder: "自定义商品名称，不填写代表使用主站的，支持HTML美化代码"
                                            },
                                            {
                                                title: "加价百分比",
                                                name: "premium",
                                                type: "input",
                                                placeholder: "商品加价",
                                                tips: "比如一个商品市场价 100 元，如果你填写了 50，售价就是：\n" +
                                                    "100 + (100 × 0.5) = 150 元。\n" +
                                                    "如果你的进货价是 70 元，那么最终利润就是：150 - 70 = 80 元。".replace("\n", "<br>")
                                            },
                                            {title: "状态", name: "status", type: "switch", text: "显示|隐藏"}
                                        ]
                                    }
                                ],
                                assign: values,
                                autoPosition: true,
                                height: "auto",
                                width: "480px",
                                done: () => {
                                    _ItemTable.refresh();
                                }
                            });
                        }
                    },

                ]
            }
        ]);
        _ItemTable.render();
    }


    _OpenBusinessGroup();
    _NoticeEditor();

    _CategoryDef();
    _ItemDef();
}();