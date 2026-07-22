!function () {
    let table, _createSearchs = [];
    const namespace = '.mdTradeCardController';
    let searchSkuRevision = 0;
    let controllerActive = true;
    const exportControllers = new Set();
    const escapeHtml = value => $('<div>').text(String(value ?? '')).html();

    if (typeof window.__mdTradeCardDestroy === 'function') window.__mdTradeCardDestroy();

    const confirmCardDelete = (list, done) => {
        util.post('/admin/api/card/deleteImpact', {list: list}, res => {
            if (!controllerActive) return;
            const impact = res.data || {};
            if (impact.can_delete !== true) {
                message.alert(
                    `所选 ${Number(impact.card_count || list.length)} 张卡密中，包含 ${Number(impact.sold_count || 0)} 张已售卡密、${Number(impact.locked_count || 0)} 张锁定卡密、${Number(impact.linked_count || 0)} 张已关联订单卡密，另有 ${Number(impact.order_reference_count || 0)} 笔订单引用。系统已阻止删除，以保护占用状态和历史记录。`,
                    'warning'
                );
                return;
            }
            message.ask(
                `将永久删除 <b>${Number(impact.card_count || list.length)} 张未使用且未锁定的卡密</b>。<br><br>删除后无法恢复，确认继续吗？`,
                () => controllerActive && done(),
                '确认永久删除卡密',
                '确认删除'
            );
        });
    };
    const formBody = payload => {
        const body = new URLSearchParams();
        Object.keys(payload || {}).forEach(key => {
            const value = payload[key];
            if (Array.isArray(value)) {
                value.forEach(item => body.append(`${key}[]`, item));
                return;
            }
            if (value !== undefined && value !== null) body.append(key, value);
        });
        return body.toString();
    };
    const postExportRequest = async (url, payload) => {
        const requestController = new AbortController();
        exportControllers.add(requestController);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: formBody(payload),
                signal: requestController.signal
            });
            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                const json = await response.json();
                if (!response.ok || json.code !== 200) throw new Error(json.msg || '请求失败');
                return {json: json};
            }
            if (!response.ok) throw new Error('服务器无法完成卡密导出');
            return {blob: await response.blob()};
        } finally {
            exportControllers.delete(requestController);
        }
    };
    const downloadCardExport = async (payload, count) => {
        Loading.show();
        try {
            const result = await postExportRequest('/admin/api/card/export', payload);
            if (!controllerActive) return;
            if (!result.blob) throw new Error('服务器没有返回导出文件');
            const url = URL.createObjectURL(result.blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `卡密导出-${count}-${new Date().toISOString().slice(0, 10)}.txt`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            message.success(`已安全导出 ${count} 张卡密`);
        } catch (error) {
            if (controllerActive && error?.name !== 'AbortError') message.alert(error.message || '导出失败', 'error');
        } finally {
            Loading.hide();
        }
    };
    const uploadCard = () => {
        let skuRevision = 0;
        const createForms = [];
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
                            required: true,
                            change: (_, __) => {
                                const revision = ++skuRevision;
                                _.setRadio("race_get_mode", 0, true);
                                _.setInput("race_input", "");

                                _.hide("race");
                                _.hide("race_input");
                                _.clearComponent("race");
                                _.hide("race_get_mode");
                                createForms.forEach(k => _.removeForm(k));
                                createForms.length = 0;
                                if (__ > 0) {
                                    util.get(`/admin/api/card/sku?commodityId=${__}`, data => {
                                        if (!controllerActive || _.isDestroyed || revision !== skuRevision) return;
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
                                                createForms.push(`sku-${sKey}`);
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
                                dom.html(`<div class="uc-cardtip">
          <p>一行一个库存卡密，内容随意。买家购买后直接获得该行内容。</p>
          <div class="uc-cardtip__label">示例</div>
          <pre class="uc-cardtip__code">ABCDEF-GHIJK-LMNOP
VIP-2025-0821-XYZ</pre>
        </div>`);
                            }
                        },
                        {
                            title: false,
                            hide: true,
                            name: "account_card",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="uc-cardtip">
          <p>一行一个，必须使用 <code>║</code> 分隔，结构为：<b>卡密本体 ║ 预告信息 ║ 自选加价金额(可选) ║ 自选加价成本(可选)</b></p>
          <ul class="uc-cardtip__legend">
            <li><span class="a-badge a-badge-dark">卡密本体</span><span>买家付款后实际获得的完整内容</span></li>
            <li><span class="a-badge a-badge-success">预告信息</span><span>买家下单时可见，用于自选</span></li>
            <li><span class="a-badge a-badge-warning">自选加价金额</span><span>选填，不写默认为 0</span></li>
            <li><span class="a-badge a-badge-primary">自选加价成本</span><span>选填，不写默认为 0</span></li>
          </ul>
          <div class="uc-cardtip__label">示例</div>
          <pre class="uc-cardtip__code">账号:testname--密码:testpassword123║大区:神境之地--等级:100║5.5║2.5
ACC_US_12M_9F2K-7QPA-88XZ║地区:美区·时长:12个月║20║8
ACC_JP_6M_0KLD-22MM-PP31║地区:日区·时长:6个月</pre>
          <div class="uc-cardtip__warn"><span class="material-icons-outlined">warning_amber</span><span>必须使用特殊符号 <strong>║</strong>（U+2551），不要用普通竖线 |</span></div>
        </div>`);
                            }
                        },
                        {
                            title: false,
                            name: "secret",
                            type: "textarea",
                            placeholder: "卡密信息，一行一个",
                            required: true,
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
                if (controllerActive && table) table.refresh();
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
                            title: "自选成本",
                            name: "cost",
                            type: "number",
                            placeholder: "非自选类型卡密请留空",
                            tips: "用来统计利润，如果你自选的卡密有成本，则需要填写"
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
                if (controllerActive && table) table.refresh();
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
            field: 'draft_premium', title: '预选加价/成本', formatter: (_, __) => {
                const premium = parseFloat(__.draft_premium) || 0;
                const cost = parseFloat(__.cost) || 0;
                if (premium <= 0 && cost <= 0) return '-';
                const fmt = v => '¥' + format.amountRemoveTrailingZeros(v);
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__k">加价</span><span class="md-pair__v" style="color:var(--md-success);font-weight:600">${fmt(premium)}</span></div><div class="md-pair__row"><span class="md-pair__k">成本</span><span class="md-pair__v md-pair__v--muted">${fmt(cost)}</span></div></div>`;
            }
        }
        , {
            field: 'commodity', title: '商品', formatter: (_, __) => {
                const c = _ || {};
                const cover = c.cover
                    ? `<img src="${c.cover}" class="md-commodity-cell__cover" alt="">`
                    : `<span class="md-commodity-cell__cover md-commodity-cell__cover--ph"><i class="fa-duotone fa-regular fa-image"></i></span>`;
                const owner = (__.owner && __.owner.username) ? __.owner.username : '主站';
                return `<div class="md-commodity-cell md-commodity-cell--sm">${cover}<div class="md-commodity-cell__text"><span class="md-commodity-cell__name">${c.name || ''}</span><span class="md-commodity-cell__sub">${owner}</span></div></div>`;
            }
        }
        , {
            field: 'race', title: '类别/SKU', formatter: (_, __) => {
                const race = (__.race && __.race !== '-') ? __.race : '';
                const hasSku = !util.isEmptyOrNotJson(__.sku);
                if (!race && !hasSku) return '-';
                let rows = `<div class="md-pair__row"><span class="md-pair__k">类别</span><span class="md-pair__v">${race || '-'}</span></div>`;
                if (hasSku) {
                    let badges = '';
                    for (const x in __.sku) badges += format.badge(`${x}: ${__.sku[x]}`, "a-badge-info");
                    rows += `<div class="md-pair__row"><span class="md-pair__k">SKU</span><span class="md-pair__v">${format.badgeGroup(badges)}</span></div>`;
                }
                return `<div class="md-pair">${rows}</div>`;
            }
        }
        , {
            field: 'create_time', title: '创建/出售时间', formatter: (_, __) => {
                const sold = __.purchase_time
                    ? `<span class="md-pair__v">${__.purchase_time}</span>`
                    : `<span class="md-pair__v md-pair__v--muted">未出售</span>`;
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__k">创建</span><span class="md-pair__v">${__.create_time || '-'}</span></div><div class="md-pair__row"><span class="md-pair__k">出售</span>${sold}</div></div>`;
            }
        }
        , {field: 'note', title: '备注信息'}
        , {
            field: 'status', title: '状态', dict: "_card_status"
        }
        , {
            field: 'order.trade_no', title: '订单号'
        }
        , {
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
                        util.post('/admin/api/card/lock', {list: [row.id]}, res => {
                            if (!controllerActive || !table) return;
                            message.success(`【${row.secret}】已锁定`);
                            table.refresh();
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole-open',
                    class: "text-success",
                    show: _ => _.status == 2,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/card/unlock', {list: [row.id]}, res => {
                            if (!controllerActive || !table) return;
                            message.success(`【${row.secret}】已解锁`);
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        confirmCardDelete([row.id], () => {
                            util.post('/admin/api/card/del', {list: [row.id]}, res => {
                                if (!controllerActive || !table) return;
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
                const revision = ++searchSkuRevision;
                _.hide("equal-race");
                _.selectClearOption("equal-race");
                _createSearchs.forEach(k => _.removeSearch(k));
                _createSearchs.length = 0;
                if (__ > 0) {
                    util.get(`/admin/api/card/sku?commodityId=${__}`, data => {
                        if (!controllerActive || _.isDestroyed || revision !== searchSkuRevision) return;
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


    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        uploadCard();
    });


    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密再进行操作！");
            return;
        }
        confirmCardDelete(data, () => {
            util.post("/admin/api/card/del", {list: data}, res => {
                if (!controllerActive || !table) return;
                message.success(`已删除 ${Number(res.data?.count || data.length)} 张卡密`)
                table.refresh();
            });
        });
    });

    $('.btn-app-lock').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密进行操作！");
            return;
        }

        message.ask(`确认锁定选中的 <b>${data.length} 张卡密</b>吗？锁定后未出售卡密将暂时不可用。`, () => {
            util.post("/admin/api/card/lock", {list: data}, res => {
                if (!controllerActive || !table) return;
                message.success(`已锁定 ${Number(res.data?.count || 0)} 张卡密`)
                table.refresh();
            });
        }, '确认锁定卡密', '确认锁定');
    });

    $('.btn-app-unlock').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密进行操作！");
            return;
        }
        message.ask(`确认解锁选中的 <b>${data.length} 张卡密</b>吗？解锁后卡密将恢复可用。`, () => {
            util.post("/admin/api/card/unlock", {list: data}, res => {
                if (!controllerActive || !table) return;
                message.success(`已解锁 ${Number(res.data?.count || 0)} 张卡密`)
                table.refresh();
            });
        }, '确认解锁卡密', '确认解锁');
    });


    $('.btn-app-sell').off(namespace).on('click' + namespace, () => {
        let selected = table.getSelections();
        let data = selected.map(item => item.id);
        if (data.length == 0) {
            layer.msg("请至少勾选1个卡密进行操作！");
            return;
        }
        const invalid = selected.filter(item => Number(item.status) !== 0 || Number(item.order_id || 0) > 0);
        if (invalid.length > 0) {
            message.warning(`选中的卡密中有 ${invalid.length} 张不是“未出售”状态；锁定卡密请先解锁，已售卡密不能重复处理`);
            return;
        }
        message.ask(`将把选中的 <b>${data.length} 张未出售卡密</b>永久标记为已售。<br><br>此操作不会生成真实订单、会立即移出可售库存，而且没有恢复入口；锁定卡密必须先显式解锁。`, () => {
            util.post("/admin/api/card/sell", {list: data}, res => {
                if (!controllerActive || !table) return;
                message.success(`已标记 ${Number(res.data?.count || data.length)} 张卡密为已售`)
                table.refresh();
            });
        }, '确认标记卡密已售', '确认标记已售');
    });


    $('.btn-app-export').off(namespace).on('click' + namespace, function () {
        component.popup({
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-file-export") + " 导出卡密",
                    form: [
                        {
                            name: "custom",
                            type: "custom",
                            complete: (obj, dom) => {
                                dom.closest("form").addClass("md-card-export-form");
                                dom.html('<div class="alert alert-warning mb-4"><b>敏感数据导出</b><br>系统只会按当前查询条件导出，并在下载前显示精确命中数量。筛选条件和卡密内容使用 POST 传输，不会写入浏览器地址与历史记录。单次最多 5000 张。</div>');
                            }
                        },
                        {
                            title: "导出数量",
                            name: "export_num",
                            type: "number",
                            placeholder: "0 或留空表示当前筛选范围内全部导出（最多 5000 张）"
                        }, {
                            title: "导出备注",
                            name: "note",
                            type: "input",
                            placeholder: "导出备注",
                            tips: "填写后会批量修改本次导出卡密的备注；留空则保持原备注"
                        },
                        {
                            title: "导出后执行",
                            name: "export_status",
                            type: "radio",
                            dict: [
                                {id: 0, name: "仅下载，不改变状态"},
                                {id: 1, name: "下载后锁定未出售卡密"},
                                {id: 3, name: "下载后永久标记为已售（高危）"},
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
            submit: async (data, index) => {
                const exportNum = data.export_num === '' ? 0 : Number(data.export_num);
                if (!Number.isInteger(exportNum) || exportNum < 0 || exportNum > 5000) {
                    message.warning('导出数量必须是 0 到 5000 的整数');
                    return;
                }
                const payload = Object.assign({}, table.getSearchData(), data);
                const state = table.getState();
                if (state.field && String(state.value ?? '') !== '') {
                    payload[`equal-${state.field}`] = state.value;
                }

                Loading.show();
                try {
                    const preview = await postExportRequest('/admin/api/card/exportImpact', payload);
                    if (!controllerActive) return;
                    const impact = preview.json?.data || {};
                    const scope = impact.has_filter
                        ? '当前筛选条件'
                        : '<span style="color:#d32f2f;font-weight:700">未设置筛选条件</span>';
                    const statusText = Number(impact.export_status) === 1
                        ? '下载后锁定其中未出售卡密'
                        : (Number(impact.export_status) === 3 ? '下载后永久标记全部卡密为已售' : '仅下载，不改变状态');
                    const noteText = impact.will_change_note
                        ? `并将备注改为“${escapeHtml(data.note)}”`
                        : '保持原备注';
                    const detail = `${scope}共命中 ${Number(impact.total || 0)} 张，本次导出 <b>${Number(impact.count || 0)} 张</b><br><br>未出售 ${Number(impact.available_count || 0)} 张、已售 ${Number(impact.sold_count || 0)} 张、锁定 ${Number(impact.locked_count || 0)} 张<br><br>${statusText}；${noteText}。`;
                    const proceed = () => {
                        if (!controllerActive) return;
                        layer.close(index);
                        downloadCardExport(payload, Number(impact.count || 0));
                    };
                    if (Number(impact.export_status) === 3) {
                        const phrase = `确认标记已售并导出${Number(impact.count || 0)}张卡密`;
                        message.dangerPrompt(`${detail}<br><br>标记已售不会生成真实订单，且没有恢复入口。`, phrase, proceed);
                    } else {
                        message.ask(detail, proceed, '确认导出敏感卡密', '确认下载');
                    }
                } catch (error) {
                    if (controllerActive && error?.name !== 'AbortError') message.alert(error.message || '无法预览导出范围', 'error');
                } finally {
                    Loading.hide();
                }
            },
        });
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        searchSkuRevision += 1;
        _createSearchs.length = 0;
        exportControllers.forEach(requestController => requestController.abort());
        exportControllers.clear();
        $('.btn-app-create, .btn-app-del, .btn-app-lock, .btn-app-unlock, .btn-app-sell, .btn-app-export').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (typeof Swal !== 'undefined') Swal.close();
        if (typeof Loading !== 'undefined') Loading.hide();
        if (window.__mdTradeCardDestroy === destroy) delete window.__mdTradeCardDestroy;
    }

    window.__mdTradeCardDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);

}();
