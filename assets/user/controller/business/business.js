!function () {
    let globalCategoryId, globalCategoryName, _ItemTable, _CateTable;

    const roundingDict = [
        {id: 0, name: "不取整（保留小数）"},
        {id: 1, name: "四舍五入到整元"},
        {id: 2, name: "向上取整到整元"}
    ];

    // "20% · 向上取整" style label for a markup rule.
    const premiumLabel = (premium, rounding) => {
        rounding = Number(rounding || 0);
        const mark = rounding === 1 ? ` · ${i18n('四舍五入')}` : (rounding === 2 ? ` · ${i18n('向上取整')}` : '');
        return `${premium}%${mark}`;
    };

    function _OpenBusinessGroup() {
        let groupId;

        $(`.business-group`).click(function () {
            groupId = $(this).data("id");
            $('.business-group').removeClass('checked');
            $(this).addClass('checked');
        });

        $(`.payButton`).click(() => {
            if (!groupId) {
                layer.msg(i18n("请先选择要开通的套餐"));
                return;
            }

            util.post("/user/api/business/purchase", {
                levelId: groupId
            }, res => {
                layer.msg(i18n("开通成功"));
                window.location.href = "/user/business/index";
            });
        });


        $('.save-config').click(() => {
            util.post("/user/api/business/saveConfig", util.arrayToObject($('.form-data').serializeArray()), res => {
                message.success("保存成功");
            });
        });

        $('.clipboard').click(function () {
            util.copyTextToClipboard($(this).data("text"), () => message.success("已复制 CNAME 地址"));
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
            const openPopup = rule => component.popup({
                submit: '/user/api/master/setCommodityAllPremium',
                tab: [
                    {
                        name: `<i class="fa-duotone fa-regular fa-hand-holding-dollar"></i> ${globalCategoryName ? `${i18n('分类加价：')}<span class="text-success">${globalCategoryName}</span>` : i18n("全店默认加价")}`,
                        form: [
                            {title: "cid", name: "category_id", type: "input", hide: true},
                            {
                                title: "加价百分比",
                                name: "premium",
                                type: "input",
                                placeholder: "0 = 不加价",
                                tips: globalCategoryId
                                    ? "这个分类下没有单独设置加价的商品按此加价，之后上架到这个分类的新商品也会自动套用；填 0 则沿用全店默认加价。"
                                    : "没有单独设置加价、所在分类也没有加价规则的商品按此加价，之后主站上架的新商品也会自动套用。比如市场价 100 元，填写 50，售价就是 150 元。"
                            },
                            {
                                title: "价格取整",
                                name: "rounding",
                                type: "select",
                                dict: roundingDict,
                                default: 0,
                                tips: "按百分比加价后价格出现小数时的处理方式，例如加价后 3.66 元：四舍五入 → 4 元、向上取整 → 4 元；加价后 3.20 元：四舍五入 → 3 元、向上取整 → 4 元。"
                            },
                            {
                                title: "统一覆盖",
                                name: "override",
                                type: "switch",
                                text: "覆盖|保留",
                                default: 1,
                                tips: globalCategoryId
                                    ? "开启：清掉这个分类里各商品单独设置的加价，统一按这条规则。关闭：单独设置过的商品保持原样。"
                                    : "开启：清掉各商品与各分类单独设置的加价，全店统一按这条规则。关闭：单独设置过的商品和分类保持原样。"
                            }
                        ]
                    }
                ],
                assign: {category_id: globalCategoryId, premium: rule.premium || '', rounding: rule.rounding || 0, override: 1},
                autoPosition: true,
                height: "auto",
                width: "480px",
                done: () => {
                    _ItemTable.refresh();
                    _CateTable.refresh();
                }
            });

            if (globalCategoryId) {
                const row = ($('#master_category').bootstrapTable('getData') || []).find(item => Number(item.id) === Number(globalCategoryId));
                openPopup({premium: row?.user_category?.premium, rounding: row?.user_category?.rounding});
                return;
            }
            util.post("/user/api/master/premiumRule", {}, res => openPopup(res.data || {}));
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
        const $mount = $('.notice-editor');
        if (!$mount.length) return;
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });
        $mount.html(EditorV2.buildHtml({name: 'notice', placeholder: i18n('填写店铺公告，支持 Markdown 语法')}));
        EditorV2.register($mount.get(0), {
            name: 'notice',
            uploadUrl: '/user/api/upload/send',
            height: 420,
            value: getVar('_business_notice_var') ?? ""
        });
    }


    function _CategoryDef() {
        _CateTable = new Table("/user/api/master/category", "#master_category");
        _CateTable.setTree(1);
        // 分类树要整棵渲染，接口不分页(#922)：分页会让父分类不在同一页的子分类直接消失
        _CateTable.disablePagination();
        _CateTable.setColumns([
            {field: 'icon', title: '#', type: "image"},
            {field: 'name', title: '主站分类名称'},
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
                field: 'premium', title: '分类加价', class: "normal", formatter: function (val, item) {
                    const rule = item.user_category;
                    if (!rule || !(Number(rule.premium) > 0)) {
                        return '-';
                    }
                    return format.badge(premiumLabel(rule.premium, rule.rounding), "a-badge-success");
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
                                        name: `${util.icon("fa-duotone fa-regular fa-gear")} ${i18n(row.name)}`,
                                        form: [
                                            {title: "cid", name: "category_id", type: "input", hide: true},
                                            {
                                                title: "自定义名称",
                                                name: "name",
                                                type: "textarea",
                                                height: 48,
                                                placeholder: "自定义分类名称，不填写代表使用主站的，支持各种HTML美化代码"
                                            },
                                            {
                                                title: "分类加价百分比",
                                                name: "premium",
                                                type: "input",
                                                placeholder: "0 = 沿用全店默认加价",
                                                tips: "这个分类下没有单独设置加价的商品按此加价，之后上架到这个分类的新商品也会自动套用。"
                                            },
                                            {title: "价格取整", name: "rounding", type: "select", dict: roundingDict, default: 0},
                                            {title: "状态", name: "status", type: "switch", text: "显示|隐藏", default: 1} // 首次设置还没有记录，默认显示，否则保存即隐藏(#922)
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
            {field: 'cover', title: '#', type: "image"},
            {field: 'name', title: '商品名称'}
            , {field: 'user_price', title: '会员价', class: "normal"}
            , {field: 'price', title: '游客价', class: "normal"}
            , {
                field: 'status',
                title: '状态',
                class: "normal",
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
                field: 'premium', title: '加价百分比', class: "normal", formatter: function (val, item) {
                    const rule = item.premium_rule;
                    if (!rule) {
                        return '-';
                    }
                    const label = premiumLabel(rule.premium, rule.rounding);
                    if (rule.source === 'category') {
                        return format.badge(`${label} · ${i18n('分类规则')}`, "a-badge-info");
                    }
                    if (rule.source === 'shop') {
                        return format.badge(`${label} · ${i18n('全店默认')}`, "a-badge-info");
                    }
                    return format.badge(label, "a-badge-success");
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
                                        name: `${util.icon("fa-duotone fa-regular fa-gear")} ${i18n(row.name)}`,
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
                                                title: "自定义商品介绍",
                                                name: "description",
                                                type: "textarea",
                                                height: 160,
                                                placeholder: "不填写代表使用主站的商品介绍，支持HTML",
                                                tips: "主站的介绍里可能带有主站自己的广告或联系方式，可以在这里改成你自己的。留空则继续沿用主站介绍。"
                                            },
                                            {
                                                title: "加价百分比",
                                                name: "premium",
                                                type: "input",
                                                placeholder: "0 = 按分类规则或全店默认加价",
                                                tips: "单独给这件商品设置加价，优先于分类规则和全店默认加价。比如市场价 100 元，填写 50，售价就是 100 + (100 × 0.5) = 150 元。"
                                            },
                                            {
                                                title: "价格取整",
                                                name: "rounding",
                                                type: "select",
                                                dict: roundingDict,
                                                default: 0,
                                                tips: "按百分比加价后价格出现小数时的处理方式。仅对设置了加价的商品生效。"
                                            },
                                            {title: "状态", name: "status", type: "switch", text: "显示|隐藏", default: 1} // 首次设置还没有记录，默认显示，否则保存即隐藏(#922)
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