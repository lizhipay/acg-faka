!function () {
    let table, _createForms = [], _createSearchs = [];
    const createCoupon = () => {
        component.popup({
            submit: '/admin/api/coupon/save',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-folder-arrow-up") + " 上传卡密",
                    form: [
                        {
                            title: "商品分类",
                            name: "category_id",
                            type: "select",
                            dict: "category->owner=0,id,name",
                            placeholder: "对商品分类下的所有商品进行折扣，不选则全场",
                            search: true
                        },
                        {
                            title: "选择商品",
                            name: "commodity_id",
                            type: "select",
                            dict: "commodity->owner=0 and delivery_way=0 and (shared_id is null or shared_id=0),id,name",
                            placeholder: "请选择商品",
                            search: true,
                            change: (_, __) => {
                                _.setRadio("race_get_mode", 0, true);
                                _.setInput("race_input", "");
                                _.hide("race");
                                _.hide("race_input");
                                _.clearComponent("race");
                                _.hide("race_get_mode");
                                _.setSelected("category_id", "");
                                _createForms.forEach(k => _.removeForm(k));
                                if (__ > 0) {
                                    _.hide("category_id");
                                    util.get(`/admin/api/card/sku?commodityId=${__}`, data => {
                                        if (!util.isEmptyOrNotJson(data?.category)) {
                                            let i = 0;
                                            for (const cKey in data.category) {
                                                _.addRadio("race", cKey, cKey, i === 0);
                                                i++;
                                            }
                                            _.show("race");
                                            _.show(`race_get_mode`);
                                        }
                                        if (!util.isEmptyOrNotJson(data?.sku)) {
                                            for (const sKey in data.sku) {
                                                let dict = [];
                                                for (const sk in data.sku[sKey]) {
                                                    dict.push({id: sk, name: sk});
                                                }
                                                _.createForm({
                                                    title: sKey,
                                                    name: `sku.${sKey}`,
                                                    type: "radio",
                                                    dict: dict
                                                }, "race", "after");
                                                _createForms.push(`sku-${sKey}`);
                                            }
                                        }
                                    });
                                } else {
                                    _.show("category_id");
                                }
                            }
                        },
                        {
                            title: "种类获取方法",
                            name: "race_get_mode",
                            type: "radio",
                            dict: [{id: 0, name: "自动获取"}, {id: 1, name: "手动填写(如独立设置了会员等级)"}],
                            hide: true,
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.hide("race");
                                    _.show("race_input");
                                } else {
                                    _.show("race");
                                    _.hide("race_input");
                                }
                            }
                        },
                        {
                            title: "商品种类",
                            name: "race_input",
                            type: "input",
                            placeholder: "请填写商品种类",
                            hide: true
                        },
                        {
                            title: "商品种类",
                            name: "race",
                            type: "radio",
                            placeholder: "商品类别，一般你用不着，而且不懂不要乱填哦，想用请查看说明文档",
                            hide: true
                        },
                        {
                            title: "备注信息",
                            name: "note",
                            type: "input",
                            placeholder: "备注信息(可空)，方便查询某次生成的优惠券"
                        },
                        {
                            title: "抵扣模式",
                            name: "mode",
                            type: "radio",
                            dict: [
                                {
                                    id: 0,
                                    name: "金额抵扣"
                                },
                                {
                                    id: 1,
                                    name: "百分比抵扣(按照商品价格)"
                                }
                            ],
                            default: 0
                        },
                        {
                            title: "面值(金额/百分比)",
                            name: "money",
                            type: "input",
                            placeholder: "金额或者百分比(小数代替范围：0~1)"
                        },
                        {
                            title: "过期时间",
                            name: "expire_time",
                            type: "date",
                            placeholder: "过了该时间优惠券自动失效，不填代表永不过期"
                        },
                        {
                            title: "可用次数",
                            name: "life",
                            type: "input",
                            placeholder: "该优惠券可以使用次数",
                            default: "1"
                        },
                        {
                            title: "卷码前缀",
                            name: "prefix",
                            type: "input",
                            placeholder: "请输入优惠券代码前缀，可留空",
                            default: "ACG"
                        },
                        {title: "生成数量", name: "num", type: "input", placeholder: "你想生成多少张优惠券", default: 1}
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: (res) => {
                table.refresh();

                layer.open({
                    type: 1,
                    title: "优惠券 [成功:" + res.data.success + "/失败:" + res.data.error + "]",
                    area: util.isPc() ? ['420px', '660px'] : ["100%", "100%"],
                    content: '<textarea class="layui-input" style="padding: 15px;height: 100%;line-height:18px;">' + res.data.code + '</textarea>'
                });
            }
        });
    }

    table = new Table("/admin/api/coupon/data", "#coupon-table");
    table.setUpdate("/admin/api/card/save");
    table.setFloatMessage([
        {field: 'create_time', title: '创建时间'}
        , {
            field: 'service_time', title: '使用时间'
        }
        , {
            field: 'trade_no', title: '订单号(最后使用)'
        }
    ]);
    table.setColumns([
        {checkbox: true},
        {
            field: 'code', title: '卷代码'
        }
        , {
            field: 'mode', title: '抵扣模式', dict: "_coupon_mode"
        }
        , {
            field: 'money', title: '面值', formatter: (_, __) => {
                if (__.mode == 1) {
                    return format.badge((_ * 10) + "折", "a-badge-success");
                }
                return format.badge(`￥${_}`, "a-badge-primary");
            }
        }
        , {
            field: 'commodity', title: '抵扣范围', formatter: function (val, item) {
                if (!item.commodity && !item.category) {
                    return '<span class="text-danger">全场通用</span>';
                }

                if (!item.commodity && item.category) {
                    return '<span class="text-primary">[商品分类] -> </span>' + item.category.name;
                }

                let d = format.badge(item.commodity.name, "a-badge-success");

                if (item.race) {
                    d += format.badge(`种类:${item.race}`, "a-badge-info");
                }

                if (!util.isEmptyOrNotJson(item.sku)) {
                    for (const skuKey in item.sku) {
                        d += format.badge(`${skuKey}:${item.sku[skuKey]}`, "a-badge-info");
                    }
                }

                return d;
            }
        }

        , {
            field: 'expire_time', title: '到期时间', formatter: function (val, item) {
                if (!item.expire_time) {
                    return format.badge("永久", "a-badge-success");
                }
                return format.badge(item.expire_time, "a-badge-warning");
            }
        }
        , {field: 'life', title: '剩余次数'}
        , {field: 'use_life', title: '已使用次数'}
        , {field: 'note', title: '备注信息'}
        , {
            field: 'status', title: '状态', dict: "_coupon_status"
        }
        , {
            field: 'owner', title: '所属者', formatter: format.owner
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole',
                    class: "text-primary",
                    show: _ => _.status == 0,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/coupon/edit', {id: row.id, status: 2}, res => {
                            message.success(`【${row.code}】已锁定`);
                            table.refresh();
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole-open',
                    class: "text-success",
                    show: _ => _.status == 2,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/coupon/edit', {id: row.id, status: 0}, res => {
                            message.success(`【${row.code}】已解锁`);
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        message.ask("您是否要移除该优惠券，这是无法恢复的？", () => {
                            util.post('/admin/api/coupon/del', {list: [row.id]}, res => {
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
        {title: "卷代码", name: "equal-code", type: "input"},
        {title: "备注信息", name: "equal-note", type: "input"},
        {title: "卷面值", name: "equal-money", type: "input"},
        {title: "会员ID，0=系统", name: "equal-owner", type: "input"},
        {title: "商品分类", name: "equal-category_id", type: "select", dict: "category,id,name", search: true},
        {
            title: "查询商品",
            name: "equal-commodity_id",
            type: "select",
            dict: "commodity,id,name",
            change: (_, __) => {
                _.hide("equal-race");
                _.selectClearOption("equal-race");
                _createSearchs.forEach(k => _.removeSearch(k));
                if (__ > 0) {
                    util.get(`/admin/api/card/sku?commodityId=${__}`, data => {
                        if (!util.isEmptyOrNotJson(data?.category)) {
                            let i = 0;
                            for (const cKey in data.category) {
                                _.selectAddOption("equal-race", cKey, cKey);
                                i++;
                            }
                            _.show("equal-race");
                        }
                        if (!util.isEmptyOrNotJson(data?.sku)) {
                            for (const sKey in data.sku) {
                                let dict = [];
                                for (const sk in data.sku[sKey]) {
                                    dict.push({id: sk, name: sk});
                                }
                                _.createSearch({
                                    title: sKey,
                                    name: `equal-sku-${sKey}`,
                                    type: "select",
                                    dict: dict
                                }, "equal-race", "after");
                                _createSearchs.push(`equal-sku-${sKey}`);
                            }
                        }
                    });
                }
            },
            search: true
        },
        {title: "商品种类", name: "equal-race", type: "select", hide: true},
    ]);
    table.setState("status", "_coupon_status");
    table.render();

    $('.btn-app-create').click(function () {
        createCoupon();
    });

    $('.btn-app-del').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个优惠券再进行操作！");
            return;
        }
        message.ask("您确定要删除已经选中的优惠券吗？这是不可恢复的操作！", () => {
            util.post("/admin/api/coupon/del", {list: data}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });
    $('.btn-app-lock').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个优惠券进行操作！");
            return;
        }

        message.ask("您确定要锁定选中的优惠券吗？", () => {
            util.post("/admin/api/coupon/lock", {list: data}, res => {
                message.success("全部锁定成功")
                table.refresh();
            });
        });
    });

    $('.btn-app-unlock').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个优惠券进行操作！");
            return;
        }
        message.ask("您确定要解锁选中的优惠券吗？", () => {
            util.post("/admin/api/coupon/unlock", {list: data}, res => {
                message.success("全部解锁成功")
                table.refresh();
            });
        });
    });

    $('.btn-app-export').click(function () {
        let searchData = util.objectToQueryString(table.getSearchData());
        let state = table.getState();
        console.log('/admin/api/coupon/export?' + searchData + "&equal-" + state.field + "=" + state.value);
        window.open('/admin/api/coupon/export?' + searchData + "&equal-" + state.field + "=" + state.value);
    });
}();