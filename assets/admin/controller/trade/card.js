!function () {
    let table, _createForms = [], _createSearchs = [];
    const uploadCard = () => {
        component.popup({
            submit: '/admin/api/card/save',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-folder-arrow-up") + " 上传卡密",
                    form: [
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
                                _createForms.forEach(k => _.removeForm(k));
                                if (__ > 0) {
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
                            placeholder: "备注信息(可空)，方便查询某次添加的卡密"
                        },
                        {
                            title: "卡密类型",
                            name: "card_type",
                            type: "radio",
                            dict: [
                                {id: 0, name: "普通卡密"},
                                {id: 1, name: "账号/预告"}
                            ],
                            change: (form, val) => {
                                if (val == 0) {
                                    form.show("general_card");
                                    form.hide("account_card");
                                } else {
                                    form.hide("general_card");
                                    form.show("account_card");
                                }
                            }
                        },
                        {
                            title: false,
                            name: "general_card",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="card no-shadow transparent h-100  border-0">
        <div class="card-body p-4">
          <p class="text-muted">一行一个库存卡密，内容随意。买家购买后直接获得该行内容。</p>
          <div class="translucent border rounded p-3">
            <div class="fw-bold mb-2 small text-uppercase text-secondary">示例</div>
<pre class="mb-0" style="white-space: pre-wrap; word-break: break-all;">
ABCDEF-GHIJK-LMNOP
VIP-2025-0821-XYZ
</pre>
          </div>
        </div>
      </div>`);
                            }
                        },
                        {
                            title: false,
                            hide: true,
                            name: "account_card",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(` <div class="card no-shadow transparent h-100 shadow border-0">
        <div class="card-body">
           
          <p class="text-muted mb-3">
            一行一个，必须使用 <code>║</code> 分隔，结构为：  
            <span class="text-dark fw-bold">卡密本体 ║ 预告信息 ║ 自选加价金额(可选)</span>
          </p>

          <ul class="list-unstyled small mb-3">
            <li class="mb-1"><span class="a-badge a-badge-dark me-1">卡密本体</span> 买家付款后实际获得的完整内容</li>
            <li class="mb-1"><span class="a-badge a-badge-success me-1">预告信息</span> 买家下单时可见，用于自选</li>
            <li><span class="a-badge a-badge-warning text-dark me-1">自选加价金额</span> 选填，不写默认为 0</li>
          </ul>

          <div class="translucent border rounded p-3">
            <div class="fw-bold mb-2 small text-uppercase text-secondary">示例</div>
<pre class="mb-0" style="white-space: pre-wrap; word-break: break-all;">
账号:testname--密码:testpassword123║大区:神境之地--等级:100║5.5
ACC_US_12M_9F2K-7QPA-88XZ║地区:美区·时长:12个月║20
ACC_JP_6M_0KLD-22MM-PP31║地区:日区·时长:6个月
</pre>
          </div>

          <div class="alert alert-warning mt-3 mb-0 small">
            ⚠️ 必须使用特殊符号 <strong>“║”</strong>（U+2551），不要用普通竖线“|”
          </div>
        </div>
      </div>`);
                            }
                        },
                        {
                            title: false,
                            name: "secret",
                            type: "textarea",
                            placeholder: "卡密信息，一行一个",
                            height: 200
                        },
                        {
                            title: "去除重复",
                            name: "unique",
                            type: "switch",
                            text: "启用（保持数据唯一，会占用CPU资源）"
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: () => {
                table.refresh();
            }
        });
    }
    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/card/edit',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "卡密信息",
                            name: "secret",
                            type: "textarea",
                            placeholder: "卡密信息",
                            required: true
                        },
                        {
                            title: "预告内容",
                            name: "draft",
                            type: "textarea",
                            placeholder: "非自选类型卡密请留空",
                        },
                        {
                            title: "自选加价",
                            name: "draft_premium",
                            type: "number",
                            placeholder: "非自选类型卡密请留空",
                        },
                        {
                            title: "备注信息",
                            name: "note",
                            type: "input",
                            placeholder: "备注信息(可空)，方便查询某次添加的卡密"
                        },
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            maxmin: false,
            height: "auto",
            width: "580px",
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/api/card/data", "#card-table");
    table.setUpdate("/admin/api/card/edit");
    table.setColumns([
        {checkbox: true},
        {
            field: 'secret', title: '卡密信息'
        },
        {
            field: 'draft', title: '预告内容'
        },
        {
            field: 'draft_premium', title: '预选加价', formatter: _ => format.money(_)
        }
        , {
            field: 'commodity', title: '商品', formatter: format.item
        }
        , {
            field: 'race', title: '类别'
        }
        , {field: 'create_time', title: '创建时间'}
        , {field: 'note', title: '备注信息'}
        , {
            field: 'status', title: '状态', dict: "_card_status"
        }
        , {
            field: 'purchase_time', title: '出售时间'
        }
        , {
            field: 'order.trade_no', title: '订单号'
        }
        , {
            field: 'sku', title: 'SKU', formatter: _ => {
                if (!util.isEmptyOrNotJson(_)) {
                    let h = ``;
                    for (const x in _) {
                        h += format.badge(`${x}: ${_[x]}`, "a-badge-info");
                    }
                    return format.badgeGroup(h);
                }
                return "-";
            }
        }
        , {
            field: 'owner', title: '所属者', formatter: format.owner
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-success",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改卡密", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole',
                    class: "text-primary",
                    show: _ => _.status == 0,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/card/edit', {id: row.id, status: 2}, res => {
                            message.success(`【${row.secret}】已锁定`);
                            table.refresh();
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole-open',
                    class: "text-success",
                    show: _ => _.status == 2,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/card/edit', {id: row.id, status: 0}, res => {
                            message.success(`【${row.secret}】已解锁`);
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        message.ask("您正在进行删除卡密操作，此操作无法撤销！", () => {
                            util.post('/admin/api/card/del', {list: [row.id]}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);
    table.setPagination(15, [15, 30, 50, 100])
    table.setSearch([
        {title: "卡密信息(精确搜索,速度快)", name: "equal-secret", type: "input"},
        {title: "卡密信息(模糊搜索,速度慢)", name: "search-secret", type: "input"},
        {title: "备注信息", name: "equal-note", type: "input"},
        {title: "卡密所属会员ID，0=系统", name: "equal-owner", type: "input"},
        {title: "入库时间", name: "between-create_time", type: "date"},
        {
            title: "查询商品",
            name: "equal-commodity_id",
            type: "select",
            dict: "commodity->owner=0 and delivery_way=0 and (shared_id is null or shared_id=0),id,name",
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
        {title: "商品类别", name: "equal-race", type: "select", hide: true},
    ]);
    table.setState("status", "_card_status");
    table.render();


    $('.btn-app-create').click(function () {
        uploadCard();
    });


    $('.btn-app-del').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密再进行操作！");
            return;
        }
        message.ask("注意，删除卡密后无法恢复", () => {
            util.post("/admin/api/card/del", {list: data}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });

    $('.btn-app-lock').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密进行操作！");
            return;
        }

        message.ask("您确定要锁定选中的卡密吗？", () => {
            util.post("/admin/api/card/lock", {list: data}, res => {
                message.success("全部锁定成功")
                table.refresh();
            });
        });
    });

    $('.btn-app-unlock').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密进行操作！");
            return;
        }
        message.ask("您确定要锁定选中的卡密吗？", () => {
            util.post("/admin/api/card/unlock", {list: data}, res => {
                message.success("全部解锁成功")
                table.refresh();
            });
        });
    });


    $('.btn-app-sell').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密进行操作！");
            return;
        }
        message.ask("您确定要手动出售选中的卡密吗？", () => {
            util.post("/admin/api/card/sell", {list: data}, res => {
                message.success("全部状态修改成功")
                table.refresh();
            });
        });
    });


    $('.btn-app-export').click(function () {

        component.popup({
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-file-export") + " 导出卡密",
                    form: [
                        {
                            name: "custom",
                            type: "custom",
                            complete: (obj, dom) => {
                                dom.html('<div style="margin-bottom: 25px;color: #27bd27;font-weight: bolder;">导出程序将根据您通过查询功能筛选出的卡密进行导出。如果您填写了导出数量，将导出指定数量的卡密；如果您未填写数量，则将导出您筛选的全部卡密。</div>');
                            }
                        },
                        {
                            title: "导出数量",
                            name: "export_num",
                            type: "input",
                            placeholder: "导出数量，填写0或不填表示全部导出。"
                        }, {
                            title: "导出备注",
                            name: "note",
                            type: "input",
                            placeholder: "导出备注",
                            tips: "导出时，自动修改卡密备注，后期方便可查询导出了那些卡密，留空则不修改"
                        },
                        {
                            title: "导出后执行",
                            name: "export_status",
                            type: "radio",
                            dict: [
                                {id: 0, name: "不执行任何操作"},
                                {id: 1, name: "锁定导出的卡密"},
                                {id: 2, name: "删除导出的卡密（高危）"},
                                {id: 3, name: "将卡密状态改【已售】"},
                            ]
                        }
                    ]
                }
            ],
            height: "auto",
            width: "480px",
            assign: {},
            confirmText: "开始导出",
            maxmin: false,
            autoPosition: true,
            submit: (data, index) => {
                let searchData = table.getSearchData();
                let state = table.getState();
                let query = util.objectToQueryString(Object.assign(searchData, data));

                layer.close(index);

                let url = "/admin/api/card/export?" + query + "&equal-" + state.field + "=" + state.value;
                if (data.export_status == 2) {
                    message.dangerPrompt("您正在执行高风险的卡密导出操作，需要注意此操作无法恢复数据。如果您只是希望卡密不再可见，我们建议您选择锁定导出的卡密。", "我确认导出并删除卡密", () => {
                        window.open(url);
                    });
                } else {
                    window.open(url);
                }
            },
        });
    });


}();