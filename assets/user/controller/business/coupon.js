!function () {
    let table, _createForms = [], _createSearchs = [];
    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const safeInlineHtml = value => window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === 'function'
        ? window.SeattleTheme.safeInlineHtml(value)
        : escapeHtml(value);

    // 生成代券成功后的结果弹窗：成功/失败徽章 + 券码块 + 复制/下载
    const openCouponResult = (data = {}) => {
        const codes = String(data.code || '');
        const okN = Number(data.success) || 0;
        const failN = Number(data.error) || 0;
        const meta = `<span class="a-badge a-badge-success">成功 ${okN} 张</span>`
            + (failN > 0 ? `<span class="a-badge a-badge-danger">失败 ${failN} 张</span>` : '');
        layer.open({
            type: 1,
            title: `${util.icon("fa-duotone fa-regular fa-circle-check")} 代券生成成功`,
            area: util.isPc() ? '480px' : ["100%", "100%"],
            shadeClose: true,
            content: `<div class="md-secret"><div class="md-secret__meta">${meta}</div><div class="md-secret__code">${escapeHtml(codes)}</div><div class="md-secret__bar"><button type="button" class="md-secret__btn" data-act="copy">${util.icon("fa-duotone fa-regular fa-copy")} 复制</button><button type="button" class="md-secret__btn md-secret__btn--primary" data-act="download">${util.icon("fa-duotone fa-regular fa-download")} 下载</button></div></div>`,
            success: (layero) => {
                layero.find('[data-act="copy"]').on('click', () => {
                    util.copyTextToClipboard(codes, () => message.success('代券已复制'));
                });
                layero.find('[data-act="download"]').on('click', () => {
                    const blob = new Blob([codes], {type: 'text/plain;charset=utf-8'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `代券_${okN}张.txt`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                });
            }
        });
    };

    const createCoupon = () => {
        component.popup({
            submit: '/user/api/coupon/save',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-ticket") + " 生成代券",
                    form: [
                        {
                            title: "商品分类",
                            name: "category_id",
                            type: "select",
                            dict: "category",
                            placeholder: "对商品分类下的所有商品进行折扣，不选则全场",
                            search: true
                        },
                        {
                            title: "选择商品",
                            name: "commodity_id",
                            type: "select",
                            dict: "commodityAll",
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
                                _createForms = [];
                                if (__ > 0) {
                                    _.hide("category_id");
                                    util.get(`/user/api/card/sku?commodityId=${__}`, data => {
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
                            placeholder: "备注信息（可空），方便查询某次生成的代券"
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
                            placeholder: "超过该时间代券自动失效，不填代表永不过期"
                        },
                        {
                            title: "可用次数",
                            name: "life",
                            type: "input",
                            placeholder: "该代券可以使用的次数",
                            default: "1"
                        },
                        {
                            title: "券码前缀",
                            name: "prefix",
                            type: "input",
                            placeholder: "请输入代券代码前缀，可留空",
                            default: "ACG"
                        },
                        {title: "生成数量", name: "num", type: "input", placeholder: "请输入要生成的代券数量", default: 1}
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: (res) => {
                table.refresh();
                openCouponResult(res.data);
            }
        });
    }

    table = new Table("/user/api/coupon/data", "#coupon-table");
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
            field: 'code', title: '代券码', class: 'nowrap', width: 190
        }
        , {
            field: 'mode', title: '抵扣模式', dict: "_coupon_mode", class: 'nowrap', width: 100
        }
        , {
            field: 'money', title: '面值', class: 'nowrap', width: 90, formatter: (_, __) => {
                if (__.mode == 1) {
                    return format.badge((_ * 10) + "折", "a-badge-success");
                }
                return format.badge(`￥${_}`, "a-badge-primary");
            }
        }
        , {
            field: 'commodity', title: '抵扣范围', class: 'nowrap', width: 220, formatter: function (val, item) {
                if (!item.commodity && !item.category) {
                    return '<span class="text-danger">全场通用</span>';
                }

                if (!item.commodity && item.category) {
                    return '<span class="text-primary">商品分类 · </span>' + safeInlineHtml(item.category.name || '未命名分类');
                }

                let d = format.badge(safeInlineHtml(item.commodity.name), "a-badge-success");

                if (item.race) {
                    d += format.badge(`种类:${escapeHtml(item.race)}`, "a-badge-info");
                }

                if (!util.isEmptyOrNotJson(item.sku)) {
                    for (const skuKey in item.sku) {
                        d += format.badge(`${escapeHtml(skuKey)}:${escapeHtml(item.sku[skuKey])}`, "a-badge-info");
                    }
                }

                return d;
            }
        }

        , {
            field: 'expire_time', title: '到期时间', class: 'nowrap', width: 170, formatter: function (val, item) {
                if (!item.expire_time) {
                    return format.badge("永久", "a-badge-success");
                }
                return format.badge(escapeHtml(item.expire_time), "a-badge-warning");
            }
        }
        , {field: 'life', title: '剩余次数', class: 'nowrap', width: 90}
        , {field: 'use_life', title: '已使用次数', class: 'nowrap', width: 100}
        , {field: 'note', title: '备注信息', width: 160, formatter: value => escapeHtml(value || '-')}
        , {
            field: 'status', title: '状态', dict: "_coupon_status", class: 'nowrap', width: 90
        },
        {
            field: 'operation', title: '操作', type: 'button', class: 'nowrap', width: 220, buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole',
                    class: "text-primary",
                    title: "锁定",
                    show: _ => _.status == 0,
                    click: (event, value, row, index) => {
                        util.post('/user/api/coupon/edit', {id: row.id, status: 2}, res => {
                            message.success('代券已锁定');
                            table.refresh();
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole-open',
                    class: "text-success",
                    title: "解锁",
                    show: _ => _.status == 2,
                    click: (event, value, row, index) => {
                        util.post('/user/api/coupon/edit', {id: row.id, status: 0}, res => {
                            message.success('代券已解锁');
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    title: "删除",
                    click: (event, value, row, index) => {
                        message.ask("确定移除该代券吗？此操作无法恢复。", () => {
                            util.post('/user/api/coupon/del', {list: [row.id]}, res => {
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
        {title: "代券码", name: "equal-code", type: "input"},
        {title: "备注信息", name: "equal-note", type: "input"},
        {title: "代券面值", name: "equal-money", type: "input"},
        {title: "商品分类", name: "equal-category_id", type: "select", dict: "category", search: true},
        {
            title: "查询商品",
            name: "equal-commodity_id",
            type: "select",
            dict: "commodityAll",
            change: (_, __) => {
                _.hide("equal-race");
                _.selectClearOption("equal-race");
                _createSearchs.forEach(k => _.removeSearch(k));
                _createSearchs = [];
                if (__ > 0) {
                    util.get(`/user/api/card/sku?commodityId=${__}`, data => {
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

    $('.button-add').click(function () {
        createCoupon();
    });


    $('.button-del').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选 1 个代券再进行操作");
            return;
        }
        message.ask("确定删除选中的代券吗？此操作无法恢复。", () => {
            util.post("/user/api/coupon/del", {list: data}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });
    $('.button-lock').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选 1 个代券进行操作");
            return;
        }

        message.ask("确定锁定选中的代券吗？", () => {
            util.post("/user/api/coupon/lock", {list: data}, res => {
                message.success("全部锁定成功")
                table.refresh();
            });
        });
    });

    $('.button-unlock').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选 1 个代券进行操作");
            return;
        }
        message.ask("确定解锁选中的代券吗？", () => {
            util.post("/user/api/coupon/unlock", {list: data}, res => {
                message.success("全部解锁成功")
                table.refresh();
            });
        });
    });

    $('.button-export').click(function () {
        let searchData = util.objectToQueryString(table.getSearchData());
        let state = table.getState();
        window.open('/user/api/coupon/export?' + searchData + "&equal-" + state.field + "=" + state.value);
    });
}();
