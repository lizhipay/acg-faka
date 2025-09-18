!function () {
    let table, _createForms = [];
    const modal = (title, assign = {}) => {

        const owner = assign?.owner ? assign?.owner.id : 0;

        component.popup({
            submit: '/admin/api/commodity/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "商品分类",
                            name: "category_id",
                            type: "treeSelect",
                            placeholder: "请选择商品分类",
                            dict: `category->owner=${owner},id,name,pid&tree=true`,
                            required: true,
                            parent: false
                        },
                        {
                            title: "商品图标",
                            name: "cover",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            required: true
                        },
                        {
                            title: "商品名称",
                            name: "name",
                            type: "textarea",
                            height: 38,
                            placeholder: "商品名称",
                            required: true
                        },
                        {
                            title: "零售价",
                            name: "price",
                            type: "input",
                            placeholder: "零售价",
                            tips: "零售价，游客看到的价格，0=免费",
                            required: true
                        },
                        {
                            title: "会员零售价",
                            name: "user_price",
                            type: "input",
                            tips: "会员零售价，登录后看到的价格，0=免费",
                            placeholder: "会员零售价",
                            required: true
                        },
                        {title: "排序", name: "sort", type: "input", placeholder: "排序，越小越靠前"},
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-truck") + " 发货设置",
                    form: [
                        {
                            title: "发货方式",
                            name: "delivery_way",
                            type: "radio",
                            placeholder: "请选择",
                            dict: "_commodity_delivery_way",
                            default: 0,
                            required: true,
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.show("delivery_message");
                                    _.hide("delivery_auto_mode");
                                    _.show("stock");
                                } else {
                                    _.hide("delivery_message");
                                    _.show("delivery_auto_mode");
                                    _.hide("stock");
                                }
                            },
                            complete: (_, __) => {
                                _.triggerOtherPopupChange("delivery_way", __);
                            }
                        },
                        {
                            title: "卡密排序",
                            name: "delivery_auto_mode",
                            type: "radio",
                            dict: "_commodity_delivery_auto_mode",
                            default: 0,
                            hide: true
                        },
                        {
                            title: "虚拟库存",
                            name: "stock",
                            type: "number",
                            placeholder: "虚拟库存数量",
                            default: 10000000,
                            tips: "虚拟库存，每购买1次，则减1，直到为0，商品就会已售罄",
                            hide: true
                        },
                        {
                            title: "发货信息",
                            name: "delivery_message",
                            type: "textarea",
                            placeholder: "手动发货信息，可以是一些固定的卡密或者软件下载链接等..",
                            height: 100,
                            hide: true
                        },
                        {
                            title: "发货留言",
                            name: "leave_message",
                            type: "textarea",
                            placeholder: "当用户购买商品后，该留言会显示在订单中",
                            height: 80,
                            tips: "当用户购买商品后，该留言会显示在订单中"
                        },
                        {
                            title: "联系方式",
                            name: "contact_type",
                            type: "radio",
                            dict: "_contact_type",
                            default: 0,
                            required: true
                        },
                        {
                            title: "邮件发送",
                            name: "send_email",
                            type: "switch",
                            text: "启用",
                            tips: "用户购买商品后，会将卡密信息发送至邮箱，仅联系方式为邮箱状态下有效。"
                        },
                        {
                            title: "查询密码",
                            name: "password_status",
                            type: "switch",
                            text: "启用",
                            tips: "开启后，下单时需要设置查询订单的密码，更强的保护用户隐私"
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-pen-field") + " 控件",
                    form: [
                        {
                            name: "widget",
                            type: "widget",
                            height: 660
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-circle-info") + " 商品介绍",
                    form: [
                        {
                            title: false,
                            name: "description",
                            type: "editor",
                            placeholder: "介绍一下你的商品..",
                            required: true,
                            uploadUrl: '/admin/api/upload/send',
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-shop-lock") + " 商品限制",
                    form: [
                        {
                            title: "最低购买数量",
                            name: "minimum",
                            type: "input",
                            tips: "单次最低购买数量，0=不限制，默认0",
                            default: 0,
                            placeholder: "单次最低购买数量"
                        },
                        {
                            title: "最大购买数量",
                            name: "maximum",
                            type: "input",
                            tips: "单次最大购买数量，0=不限制，默认0",
                            default: 0,
                            placeholder: "单次最大购买数量"
                        },
                        {title: "优惠卷", name: "coupon", type: "switch"},
                        {
                            title: "限时秒杀",
                            name: "seckill_status",
                            type: "switch",
                            text: "启用",
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.show('seckill_start_time');
                                    _.show('seckill_end_time');
                                } else {
                                    _.hide('seckill_start_time');
                                    _.hide('seckill_end_time');
                                }
                            },
                            complete: (_, __) => {
                                _.triggerOtherPopupChange("seckill_status", __);
                            }
                        },
                        {
                            title: "秒杀开始时间",
                            name: "seckill_start_time",
                            type: "date",
                            placeholder: "开始时间",
                            hide: true
                        },
                        {
                            title: "秒杀结束时间",
                            name: "seckill_end_time",
                            type: "date",
                            placeholder: "结束时间",
                            hide: true
                        },
                        {
                            title: "卡密预选",
                            name: "draft_status",
                            type: "switch",
                            text: "启用",
                            tips: "顾名思义，意思就是顾客在购买时，可以预先选择想要购买的那个卡密，一般针对于出售游戏账号等用途。",
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.show('draft_premium');
                                } else {
                                    _.hide('draft_premium');
                                }
                            },
                            complete: (_, __) => {
                                _.triggerOtherPopupChange("draft_status", __);
                            }
                        },
                        {
                            title: "预选加价",
                            name: "draft_premium",
                            type: "input",
                            tips: "如果用户使用预选功能，则会加价购买",
                            placeholder: "加价金额",
                            hide: true
                        },
                        {
                            title: "仅限会员购买",
                            name: "only_user",
                            type: "switch",
                            text: "启用",
                            tips: "用户必须登录后才能购买商品"
                        },
                        {
                            title: "会员限购",
                            name: "purchase_count",
                            type: "input",
                            placeholder: "0代表不限制",
                            tips: "0代表不限制，如果限制了购买数量，那么用户必须登录才能购买",
                            default: 0,
                        },
                        {
                            title: "API对接",
                            name: "api_status",
                            type: "switch",
                            text: "启用",
                            tips: "如果你需要别人的店铺来对接这个商品，那么你就需要开启该选项"
                        },
                        {
                            title: "隐藏库存",
                            name: "inventory_hidden",
                            type: "switch",
                            text: "启用",
                            tips: "该功能开启后，库存会被隐藏"
                        },
                        {
                            title: "隐藏商品",
                            name: "hide",
                            type: "switch",
                            text: "是",
                            default: 0,
                            tips: "隐藏商品后，游客将看不见该商品，但你可以通过下面的《会员配置》来进行对指定的会员等级显示。"
                        },
                        {
                            title: "禁用折扣",
                            name: "level_disable",
                            type: "switch",
                            text: "禁用",
                            tips: "如果禁用会员等级后，那么登录用户购买该商品将无法应用会员折扣，不包含会员价。"
                        },
                        {
                            title: "首页推荐",
                            name: "recommend",
                            type: "switch",
                            text: "上推荐",
                            tips: "该功能需要在'网站设置'->'其他设置'中开启首页推荐功能才会显示"
                        }
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-gears") + " 配置参数",
                    form: [
                        {title: false, name: "config", type: "textarea", placeholder: "配置参数", height: 480},
                        {
                            title: false, name: "config_tips", type: "custom", complete: (_, __) => {
                                __.html(`<b style='color: red;'>配置参数里面包括了商品种类，多SKU等高阶功能，详细使用方法请查看文档：<a href='https://faka.wiki/#/zh-cn/goods-config' target='_blank'>https://faka.wiki/#/zh-cn/goods-config</a></b>`);
                            }
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-user-group") + " 会员等级",
                    form: [
                        {
                            name: "level_price",
                            type: "textarea",
                            hide: true
                        },
                        {
                            name: "group_user", type: "custom", complete: (form, __) => {
                                __.html(`<div class="mcy-card"><table id="commodity-group-table"></table></div>`);

                                util.get("/admin/api/group/data", res => {
                                    let raw = form.getData("level_price");
                                    let configStr = raw ? decodeURIComponent(raw) : "{}";
                                    let config = {};

                                    try {
                                        config = JSON.parse(configStr);
                                        if (typeof config != "object") {
                                            config = {};
                                        }
                                    } catch (e) {
                                        config = {};
                                    }

                                    res.list.forEach(item => {
                                        const cfg = config[item.id] || {};
                                        item.show = cfg.show ? 1 : 0;
                                        item.amount = cfg.amount ?? "";
                                    });

                                    const groupTable = new Table(res.list, __.find('#commodity-group-table'));

                                    groupTable.setColumns([
                                        {
                                            field: 'name',
                                            title: '会员',
                                            class: 'nowrap',
                                            formatter: (_, __) => format.group(__)
                                        },
                                        {
                                            field: 'amount',
                                            title: '自定义单价',
                                            type: 'input',
                                            change: (_, __) => {
                                                let num = Number(_);
                                                if ((!isNaN(num) && num >= 0) || _ === "-") {
                                                    config[__.id] = config[__.id] || {};
                                                    config[__.id]['amount'] = num;
                                                    if (_ === "" || _ === "-") {
                                                        delete config[__.id]['amount'];
                                                    }
                                                    form.setTextarea("level_price", JSON.stringify(config));
                                                } else {
                                                    layer.msg("自定义单价无法低于0，并且仅支持数字");
                                                }
                                            }
                                        },
                                        {
                                            field: 'show',
                                            title: '绝对显示',
                                            type: 'switch',
                                            text: "启用|关闭",
                                            change: (_, __) => {
                                                config[__.id] = config[__.id] || {};
                                                config[__.id]['show'] = _;
                                                form.setTextarea("level_price", JSON.stringify(config));
                                            }
                                        },
                                        {
                                            field: 'operation', title: '配置参数', type: 'button', buttons: [
                                                {
                                                    icon: 'fa-duotone fa-regular fa-gear',
                                                    class: "text-primary",
                                                    click: (event, value, row, index) => {
                                                        component.popup({
                                                            submit: (_, __) => {
                                                                config[row.id] = config[row.id] || {};
                                                                config[row.id]['config'] = _.config;
                                                                form.setTextarea("level_price", JSON.stringify(config));
                                                                layer.close(__);
                                                            },
                                                            tab: [
                                                                {
                                                                    name: util.icon("fa-duotone fa-regular fa-gears") + " 配置参数",
                                                                    form: [
                                                                        {
                                                                            title: false,
                                                                            name: "config",
                                                                            type: "textarea",
                                                                            placeholder: "配置参数",
                                                                            default: config[row.id]?.config ?? "",
                                                                            height: 480
                                                                        },
                                                                        {
                                                                            title: false,
                                                                            name: "config_tips",
                                                                            type: "custom",
                                                                            complete: (_, __) => {
                                                                                __.html(`<b style='color: red;'>配置参数里面包括了商品种类，多SKU等高阶功能，详细使用方法请查看文档：<a href='https://faka.wiki/#/zh-cn/goods-config' target='_blank'>https://faka.wiki/#/zh-cn/goods-config</a></b>`);
                                                                            }
                                                                        },
                                                                    ]
                                                                },
                                                            ],
                                                            autoPosition: true,
                                                            height: "auto",
                                                            width: "680px"
                                                        });
                                                    }
                                                }
                                            ]
                                        },
                                    ]);
                                    groupTable.render();
                                });
                            }
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-link") + " 远程对接",
                    form: [
                        {
                            title: "对接平台",
                            name: "shared_id",
                            type: "select",
                            placeholder: "本地商品",
                            dict: "shared,id,name",
                            change: (_, __) => {
                                if (__ > 0 && __ != null) {
                                    _.show("shared_code");
                                    _.show("shared_premium_type");
                                    _.show("shared_premium");
                                    _.show("shared_sync");
                                } else {
                                    _.hide("shared_code");
                                    _.hide("shared_premium_type");
                                    _.hide("shared_premium");
                                    _.hide("shared_sync");
                                }
                            },
                            complete: (_, __) => {
                                _.triggerOtherPopupChange("shared_id", __);
                            }
                        },
                        {
                            title: "对接代码",
                            name: "shared_code",
                            type: "input",
                            tips: "上游商品的对接代码",
                            placeholder: "对接代码",
                            hide: true
                        },
                        {
                            title: "远端信息同步",
                            name: "shared_sync",
                            type: "switch",
                            tips: "启用后，远端商品信息会实时同步本地，远端价发生变化会立即同步",
                            hide: true
                        },
                        {
                            title: "加价模式",
                            name: "shared_premium_type",
                            type: "radio",
                            dict: [
                                {id: 0, name: "普通金额加价"},
                                {id: 1, name: "百分比加价"}
                            ],
                            default: 0,
                            hide: true
                        },
                        {
                            title: "自动加价",
                            name: "shared_premium",
                            type: "input",
                            default: "0.00",
                            hide: true
                        }
                    ]
                },
            ],
            assign: assign,
            autoPosition: true,
            content: {
                css: {
                    height: "auto",
                    overflow: "inherit"
                }
            },
            height: "auto",
            width: "1000px",
            done: () => {
                table.refresh();
            }
        });
    }

    const uploadCard = (commodityId) => {
        component.popup({
            submit: '/admin/api/card/save',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-folder-arrow-up") + " 上传卡密",
                    form: [
                        {
                            title: false,
                            name: "commodity_id",
                            type: "input",
                            default: commodityId,
                            hide: true,
                            complete: (_, __) => {
                                _.setRadio("race_get_mode", 0, true);
                                _.setInput("race_input", "");

                                _.hide("race");
                                _.hide("race_input");
                                _.clearComponent("race");
                                _.hide("race_get_mode");
                                _createForms.forEach(k => _.removeForm(k));

                                util.get(`/admin/api/card/sku?commodityId=${commodityId}`, data => {
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
                            title: "卡密信息",
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

    table = new Table("/admin/api/commodity/data", "#commodity-table");
    table.setUpdate("/admin/api/commodity/save");
    table.setColumns([
        {checkbox: true}
        , {
            field: 'category.name', title: '分类'
        }
        , {
            field: 'name', title: '商品名称', formatter: (_, __) => format.item(__)
        }
        , {
            field: 'card_count', title: '库存', formatter: function (val, item) {
                if (item.delivery_way == 0) {
                    return item.card_count + ` <a class='add-card' data-id='${item.id}' style='color: green;' href='javascript:void(0);'>加卡</a>`;
                }
                return item.stock;
            }
        }
        , {field: 'price', title: '零售价'}
        , {field: 'user_price', title: '会员价'}
        , {field: 'order_today_amount', title: '今日'}
        , {field: 'order_yesterday_amount', title: '昨日'}
        , {field: 'order_week_amount', title: '本周'}
        , {field: 'order_all_amount', title: '全部'}
        , {field: 'sort', title: '排序'}
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
            field: 'owner', title: '商家', formatter: format.owner
        }
        , {
            field: 'shared', title: '对接平台', formatter: format.shared
        }
        , {
            field: 'status', title: '状态', type: "switch", text: "上架|下架", reload: true, class: "nowrap"
        },
        {
            field: 'recommend', title: '推荐', type: "switch", text: "已推荐|未推荐", reload: true, class: "nowrap"
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改商品", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-copy',
                    class: "text-warning",
                    click: (event, value, row, index) => {
                        const clone = {...row};
                        delete clone.id;
                        delete clone.shared_code;
                        delete clone.code;
                        delete clone.shared_id;
                        delete clone.shared;
                        modal(util.icon("fa-duotone fa-regular fa-copy me-1") + "克隆商品", clone);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        message.ask("是否删除此商品？", () => {
                            util.post('/admin/api/commodity/del', {list: [row.id]}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);

    table.setFloatMessage([
        {field: 'id', title: '商品ID'},
        {
            field: 'card_success_count', title: '已出售'
        },
        {
            field: 'api_status', title: 'API对接', dict: "_commodity_api_status"
        },
        {
            field: 'delivery_way', title: '发货方式', dict: "_commodity_delivery_way"
        },
        {
            field: 'delivery_auto_mode', title: '出库顺序', dict: "_commodity_delivery_auto_mode"
        },
        {
            field: 'code', title: '对接代码'
        },
        {
            field: 'contact_type', title: '联系方式', dict: "_contact_type"
        },
        {
            field: 'password_status', title: '订单密码', dict: "_commodity_api_status"
        },
        {
            field: 'coupon', title: '优惠卷', dict: "_commodity_api_status"
        },
        {
            field: 'shared_sync', title: '价格同步(对接)', dict: "_commodity_api_status"
        },
        {
            field: 'inventory_sync', title: '数量同步(对接)', dict: "_commodity_api_status"
        },
        {
            field: 'seckill_status', title: '商品秒杀', dict: "_commodity_api_status"
        },
        {
            field: 'seckill_start_time', title: '秒杀时间(开始)'
        },
        {
            field: 'seckill_end_time', title: '秒杀时间(结束)'
        },
        {
            field: 'draft_status', title: '预选卡密', dict: "_commodity_api_status"
        },
        {
            field: 'draft_premium', title: '预选加价'
        },
        {
            field: 'inventory_hidden', title: '隐藏库存', dict: "_commodity_api_status"
        },
        {
            field: 'send_email', title: '发送邮件', dict: "_commodity_api_status"
        },
        {
            field: 'only_user', title: '仅限会员购买(需登录)', dict: "_commodity_api_status"
        },
        {
            field: 'purchase_count', title: '限购数量(需登录)'
        },
        {
            field: 'minimum', title: '单次最低购买数量'
        },
        {
            field: 'level_disable', title: '禁用所有折扣(含优惠券)', dict: "_commodity_api_status"
        },
        {
            field: 'hide', title: '隐藏商品', dict: "_commodity_api_status"
        },
        {
            field: 'create_time', title: '创建时间'
        },
    ]);

    table.setSearch([
        {
            title: "显示范围：整站",
            name: "display_scope",
            type: "select",
            dict: [
                {id: 1, name: "仅主站"},
                {id: 2, name: "仅商家"}
            ],
            change: (search, val) => {
                if (val == 2) {
                    search.show("user_id");
                } else {
                    search.hide("user_id");
                    search.treeSelectReload("equal-category_id", "category->owner=0,id,name,pid&tree=true");
                }
            }
        },
        {
            title: "搜索商家",
            name: "user_id",
            hide: true,
            type: "remoteSelect",
            dict: "user->business_level>0,id,username",
            change: (search, value, selected) => {
                if (selected) {
                    search.treeSelectReload("equal-category_id", `category->owner=${value},id,name,pid&tree=true`);
                } else {
                    search.treeSelectReload("equal-category_id", "category,id,name,pid&tree=true");
                }
            }
        },
        {
            title: "商品分类",
            name: "equal-category_id",
            type: "treeSelect",
            dict: "category,id,name,pid&tree=true",
            search: true
        },
        {title: "商品名称(模糊搜索)", name: "search-name", type: "input"},
        {title: "对接平台", name: "equal-shared_id", type: "select", dict: "shared,id,name", search: true},
    ]);
    table.setState("status", "_commodity_status");

    table.render();


    $('.btn-app-create').click(function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加商品`);
    });

    $('.delist').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个商品进行操作！");
            return;
        }

        message.ask("您确定要下架选中的商品吗？", () => {
            util.post("/admin/api/commodity/status", {list: data, status: 0}, res => {
                message.success("全部下架完成");
                table.refresh();
            });
        });
    });


    $('.listed').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个商品进行操作！");
            return;
        }
        message.ask("您确定要上架选中的商品吗？", () => {
            util.post("/admin/api/commodity/status", {list: data, status: 1}, res => {
                message.success("全部上架完成");
                table.refresh();
            });
        });
    });


    $('.btn-app-del').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个商品进行操作！");
            return;
        }
        message.ask("您确定要删除已经选中的商品吗？这是不可恢复的操作！", () => {
            util.post("/admin/api/commodity/del", {list: data}, () => {
                message.success("全部删除成功");
                table.refresh();
            });
        });
    });


    $('.handle').click(() => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个商品进行操作！");
            return;
        }

        let join = data.join(",");

        component.popup({
            submit: '/admin/api/commodity/fastEnable',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-sliders") + " 批量操作",
                    form: [
                        {
                            title: "",
                            name: "list",
                            type: "input",
                            hide: true,
                            default: join
                        },
                        {
                            title: "启用API对接",
                            name: "api_status",
                            type: "switch",
                            text: "启用"
                        },
                        {
                            title: "下单密码",
                            name: "password_status",
                            type: "switch",
                            text: "启用"
                        },
                        {
                            title: "优惠卷",
                            name: "coupon",
                            type: "switch",
                            text: "启用"
                        },
                        {
                            title: "隐藏库存",
                            name: "inventory_hidden",
                            type: "switch",
                            text: "启用"
                        },
                        {
                            title: "推荐商品",
                            name: "recommend",
                            type: "switch",
                            text: "启用"
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "320px",
            maxmin: false,
            done: () => {
                table.refresh();
            }
        });
    });

    $(document).off("click", ".add-card").on("click", ".add-card", function () {
        const id = $(this).data("id");
        uploadCard(id);
    });

}();