!function () {
    let table;
    const namespace = '.mdTradeCommodityController';
    let controllerActive = true;
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => $('<div>').text(String(value ?? '')).html();
    const commodityDeleteNames = (values, fallback) => Array.isArray(values) && values.length
        ? values.map(escapeHtml).join(i18n('、'))
        : escapeHtml(fallback || i18n('所选商品'));
    const commodityDeleteDetail = impact => {
        const groupNames = Array.isArray(impact.commodity_group_names) && impact.commodity_group_names.length
            ? `（${impact.commodity_group_names.map(escapeHtml).join('、')}）`
            : '';
        return `${i18n('卡密')} ${Number(impact.card_count || 0)} ${i18n('张、订单')} ${Number(impact.order_count || 0)} ${i18n('笔、优惠券')} ${Number(impact.coupon_count || 0)} ${i18n('张、商户映射')} ${Number(impact.merchant_mapping_count || 0)} ${i18n('条、工单')} ${Number(impact.ticket_count || 0)} ${i18n('条、商品分组')} ${Number(impact.commodity_group_count || 0)} ${i18n('个')}${groupNames}`;
    };
    const commodityDeleteSkipReason = impact => {
        const reasons = [];
        const blockedCount = Number(impact.blocked_count || 0);
        const missingCount = Number(impact.missing_count || 0);
        if (blockedCount > 0) {
            const names = commodityDeleteNames(impact.blocked_names, i18n('有关联业务的商品'));
            reasons.push(`${i18n('有关联业务')} ${blockedCount} ${i18n('个（')}${names}；${commodityDeleteDetail(impact)}）`);
        }
        if (missingCount > 0) {
            reasons.push(`${i18n('已不存在')} ${missingCount} ${i18n('个')}`);
        }
        return reasons.join('；') || i18n('没有可删除的商品');
    };
    const showCommodityBatchDeleteResult = response => {
        const result = response?.data || {};
        const deletedCount = Number(result.deleted_count ?? result.count ?? 0);
        const skippedCount = Number(result.skipped_count || 0);
        if (skippedCount > 0) {
            message.alert(
                `${i18n('批量删除完成：成功删除')} <b>${deletedCount} ${i18n('个')}</b>，${i18n('自动跳过')} <b>${skippedCount} ${i18n('个')}</b>。<br><br>${i18n('跳过原因：')}${commodityDeleteSkipReason(result)}`,
                'warning'
            );
            return;
        }
        message.success(`${i18n('已删除')} ${deletedCount} ${i18n('个商品')}`);
    };
    const batchSettingDefinitions = [
        {name: 'api_status', title: 'API 对接'},
        {name: 'password_status', title: '下单密码'},
        {name: 'coupon', title: '优惠券'},
        {name: 'inventory_hidden', title: '隐藏库存'},
        {name: 'recommend', title: '推荐商品'},
        {name: 'shared_sync', title: '远端信息同步', tips: '仅对远端商品生效'},
        {name: 'shared_amount_sync', title: '远端价格同步', tips: '仅对远端商品生效'},
        {name: 'shared_config_sync', title: '远端配置同步', tips: '仅对远端商品生效'},
    ];
    const batchSettingForm = definition => mobileAdminEnabled() ? {
        title: definition.title,
        name: definition.name,
        type: 'radio',
        default: 'keep',
        dict: [
            {id: 'keep', name: '保持原设置'},
            {id: 1, name: '开启'},
            {id: 0, name: '关闭'},
        ],
        tips: definition.tips
    } : {
        title: definition.title,
        name: definition.name,
        type: 'switch',
        text: '启用',
        tips: definition.tips
    };
    if (typeof window.__mdTradeCommodityDestroy === 'function') window.__mdTradeCommodityDestroy();
    const confirmCommodityDelete = (list, fallbackName, done) => {
        if (!controllerActive) return;
        util.post({
            url: '/admin/api/commodity/deleteImpact',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                const impact = res.data || {};
                const names = commodityDeleteNames(impact.names, fallbackName || i18n('所选商品'));
                const detail = commodityDeleteDetail(impact);
                const requestedCount = Number(impact.requested_count || list.length);
                const deletableCount = Number(impact.deletable_count || 0);
                const missingCount = Number(impact.missing_count || 0);
                const skippedCount = Number(impact.skipped_count || 0);
                if (list.length === 1 && missingCount > 0) {
                    message.alert('该商品已不存在，请刷新列表后重试。', 'warning');
                    return;
                }
                if (deletableCount < 1) {
                    message.alert(
                        `${i18n('所选')} ${requestedCount} ${i18n('个商品均无法物理删除，已全部自动跳过。')}<br><br>${i18n('跳过原因：')}${commodityDeleteSkipReason(impact)}`,
                        'warning'
                    );
                    return;
                }
                if (skippedCount > 0) {
                    const deletableNames = commodityDeleteNames(impact.deletable_names, i18n('可删除商品'));
                    message.ask(
                        `${i18n('选中')} <b>${requestedCount} ${i18n('个商品')}</b>，${i18n('将永久删除其中')} <b>${deletableCount} ${i18n('个')}</b>：${deletableNames}<br><br>${i18n('另外')} <b>${skippedCount} ${i18n('个无法删除的商品会自动跳过')}</b>。<br>${i18n('跳过原因：')}${commodityDeleteSkipReason(impact)}<br><br>${i18n('删除后无法恢复。')}`,
                        done,
                        i18n('确认删除可删除商品'),
                        i18n('开始删除')
                    );
                    return;
                }
                const hasRecords = Number(impact.order_count || 0) > 0 || Number(impact.ticket_count || 0) > 0;
                message.ask(
                    `${i18n('将永久删除')} <b>${Number(impact.commodity_count || list.length)} ${i18n('个商品')}</b>：${names}`
                    + `<br><br>${i18n('以及其名下全部关联数据：')}${detail}`
                    + (hasRecords ? `<br><br><b style="color:#d33">${i18n('其中订单与工单属于经营记录，删除后账单统计将无法追溯。')}</b>` : '')
                    + `<br><br>${i18n('此操作无法恢复。')}`,
                    done,
                    i18n('确认永久删除商品'),
                    i18n('确认删除')
                );
            },
            error: res => controllerActive && message.error(res?.msg || i18n('无法计算商品删除影响，已阻止删除')),
            fail: () => controllerActive && message.error('网络异常，无法预览商品删除影响，已阻止删除')
        });
    };
    const modal = (title, assign = {}) => {
        if (!controllerActive) return;
        let groupRevision = 0;

        //标签入库是 [{text,color}]，attribute 组件认的是 [{name,value}]，这里做一次转换
        try {
            const raw = typeof assign?.tags === 'string' ? JSON.parse(assign.tags || '[]') : (assign?.tags || []);
            assign = Object.assign({}, assign, {
                tags: JSON.stringify((raw || []).map(t => ({name: t.text ?? t.name ?? '', value: t.color ?? t.value ?? ''})))
            });
        } catch (e) {
            assign = Object.assign({}, assign, {tags: '[]'});
        }

        const owner = assign?.owner ? assign?.owner.id : 0;

        //加价模式：0=固定金额 1=百分比 2=套用加价模板。后两个字段（加价数额/加价模板）二选一显示。
        //radio 的 complete 只用来记住当前值——那会儿"对接平台"可能还是本地商品，整块本就该藏着。
        let sharedPremiumType = String(assign?.shared_premium_type ?? 0);
        const applySharedPremiumMode = (form, value, visible = true) => {
            sharedPremiumType = String(value ?? 0);
            if (!visible) return;
            const useTemplate = sharedPremiumType === '2';
            useTemplate ? form.hide("shared_premium") : form.show("shared_premium");
            useTemplate ? form.show("shared_premium_template") : form.hide("shared_premium_template");
        };

        component.popup({
            drawer: true,          // content-heavy product form → open as a right-side drawer
            fitTabs: true,         // 标签页多到一行放不下时自动加宽（插件还会往这里塞标签页）
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
                            inputmode: "decimal",
                            enterkeyhint: "next",
                            placeholder: "零售价",
                            tips: "零售价，游客看到的价格，0=免费",
                            required: true
                        },
                        {
                            title: "会员零售价",
                            name: "user_price",
                            type: "input",
                            inputmode: "decimal",
                            enterkeyhint: "next",
                            tips: "会员零售价，登录后看到的价格，0=免费",
                            placeholder: "会员零售价",
                            required: true
                        },
                        {
                            title: "成本价",
                            name: "factory_price",
                            type: "input",
                            inputmode: "decimal",
                            enterkeyhint: "next",
                            tips: "用来统计利润，需要给出真实成本价，如果商品有SKU，需要在配置参数中继续配置SKU的成本",
                            placeholder: "成本价"
                        },
                        {title: "排序", name: "sort", type: "input", inputmode: "numeric", enterkeyhint: "done", placeholder: "排序，越小越靠前"},
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                        {
                            title: "商品标签",
                            name: "tags",
                            type: "attribute",
                            allowEmpty: true,               //商品可以不设标签，允许一行不留
                            sortable: true,                 //可拖动排序，顺序即前台展示顺序
                            namePlaceholder: "标签文字，如：热销",
                            valueDict: [
                                {id: "red", name: "红色"},
                                {id: "orange", name: "橙色"},
                                {id: "green", name: "绿色"},
                                {id: "cyan", name: "青色"},
                                {id: "blue", name: "蓝色"},
                                {id: "purple", name: "紫色"},
                                {id: "pink", name: "粉色"},
                                {id: "gray", name: "灰色"}
                            ],
                            tips: "显示在商品列表卡片上，最多 5 个，每个最多 10 字；可拖动左侧手柄调整顺序"
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-truck") + i18n(" 发货设置"),
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
                    name: util.icon("fa-duotone fa-regular fa-pen-field") + i18n(" 控件"),
                    form: [
                        {
                            name: "widget",
                            type: "widget",
                            height: 660
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-circle-info") + i18n(" 商品介绍"),
                    form: [
                        {
                            title: false,
                            name: "description",
                            type: "editorv2",
                            placeholder: "介绍一下你的商品..",
                            required: true,
                            uploadUrl: '/admin/api/upload/send',
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-shop-lock") + i18n(" 商品限制"),
                    form: [
                        {
                            title: "最低购买数量",
                            name: "minimum",
                            type: "input",
                            inputmode: "numeric",
                            enterkeyhint: "next",
                            tips: "单次最低购买数量，0=不限制，默认0",
                            default: 0,
                            placeholder: "单次最低购买数量"
                        },
                        {
                            title: "最大购买数量",
                            name: "maximum",
                            type: "input",
                            inputmode: "numeric",
                            enterkeyhint: "next",
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
                            inputmode: "decimal",
                            enterkeyhint: "next",
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
                            inputmode: "numeric",
                            enterkeyhint: "done",
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
                    name: util.icon("fa-duotone fa-regular fa-gears") + i18n(" 配置参数"),
                    form: [
                        {title: false, name: "config", type: "textarea", placeholder: "配置参数", height: 480},
                        {
                            title: false, name: "config_tips", type: "custom", complete: (_, __) => {
                                __.html(`<b style='color: red;'>${i18n('配置参数里面包括了商品种类，多')}SKU${i18n('等高阶功能，详细使用方法请查看文档：')}<a href='https://faka.wiki/#/zh-cn/goods-config' target='_blank'>https://faka.wiki/#/zh-cn/goods-config</a></b>`);
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
                                const revision = ++groupRevision;
                                __.html(`<div class="mcy-card"><table id="commodity-group-table"></table></div>`);

                                util.get("/admin/api/group/data", res => {
                                    if (!controllerActive || form.isDestroyed || revision !== groupRevision) return;
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
                                                    layer.msg(i18n("自定义单价无法低于0，并且仅支持数字"));
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
                                                                                __.html(`<b style='color: red;'>${i18n('配置参数里面包括了商品种类，多')}SKU${i18n('等高阶功能，详细使用方法请查看文档：')}<a href='https://faka.wiki/#/zh-cn/goods-config' target='_blank'>https://faka.wiki/#/zh-cn/goods-config</a></b>`);
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
                    name: util.icon("fa-duotone fa-regular fa-link") + i18n(" 远程对接"),
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
                                    _.show("shared_sync");
                                    _.show("shared_amount_sync");
                                    _.show("shared_config_sync");
                                    //加价数额/加价模板二选一，由加价模式决定显示哪个
                                    applySharedPremiumMode(_, sharedPremiumType);
                                } else {
                                    _.hide("shared_code");
                                    _.hide("shared_premium_type");
                                    _.hide("shared_premium");
                                    _.hide("shared_premium_template");
                                    _.hide("shared_sync");
                                    _.hide("shared_amount_sync");
                                    _.hide("shared_config_sync");
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
                            tips: "启用后，远端商品信息会实时同步本地",
                            hide: true
                        },
                        {
                            title: "远端价格同步",
                            name: "shared_amount_sync",
                            type: "switch",
                            tips: "启用后，远端商品价格会实时同步本地，远端价发生变化会立即同步",
                            hide: true
                        },
                        {
                            title: "远端配置参数同步",
                            name: "shared_config_sync",
                            type: "switch",
                            tips: "启用后，远端商品配置参数会实时同步本地，比如SKU/种类这些参数",
                            hide: true
                        },
                        {
                            title: "加价模式",
                            name: "shared_premium_type",
                            type: "radio",
                            dict: [
                                {id: 0, name: "普通金额加价"},
                                {id: 1, name: "百分比加价"},
                                {id: 2, name: "套用加价模板"}
                            ],
                            default: 0,
                            hide: true,
                            change: applySharedPremiumMode,
                            complete: (form, value) => applySharedPremiumMode(form, value, false)
                        },
                        {
                            title: "自动加价",
                            name: "shared_premium",
                            type: "input",
                            inputmode: "decimal",
                            enterkeyhint: "done",
                            default: "0.00",
                            hide: true
                        },
                        {
                            title: "加价模板",
                            name: "shared_premium_template",
                            type: "select",
                            dict: "price_template,id,name",
                            placeholder: "请选择加价模板",
                            tips: "在【店铺共享 - 加价模板】里维护。模板能一次配好游客价、会员价、每个会员等级的价格并对价格取整；远端价变动时按同一个模板重算。这里一律以远端价为基准，模板里的「加价基准」不参与",
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
            width: "960px",
            done: () => {
                if (controllerActive && table) table.refresh();
            }
        });
    }

    const uploadCard = (commodityId) => {
        if (!controllerActive) return;
        let skuRevision = 0;
        const createForms = [];
        component.popup({
            submit: '/admin/api/card/save',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-folder-arrow-up") + i18n(" 上传卡密"),
                    form: [
                        {
                            title: false,
                            name: "commodity_id",
                            type: "input",
                            default: commodityId,
                            hide: true,
                            complete: (_, __) => {
                                const revision = ++skuRevision;
                                _.setRadio("race_get_mode", 0, true);
                                _.setInput("race_input", "");

                                _.hide("race");
                                _.hide("race_input");
                                _.clearComponent("race");
                                _.hide("race_get_mode");
                                createForms.forEach(k => _.removeForm(k));
                                createForms.length = 0;

                                util.get(`/admin/api/card/sku?commodityId=${commodityId}`, data => {
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
          <p>${i18n('一行一个库存卡密，内容随意。买家购买后直接获得该行内容。')}</p>
          <div class="uc-cardtip__label">${i18n('示例')}</div>
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
          <p>${i18n('一行一个，必须使用')} <code>║</code> ${i18n('分隔，结构为：')}<b>${i18n('卡密本体')} ║ ${i18n('预告信息')} ║ ${i18n('自选加价金额')}(${i18n('可选')}) ║ ${i18n('自选加价成本')}(${i18n('可选')})</b></p>
          <ul class="uc-cardtip__legend">
            <li><span class="a-badge a-badge-dark">${i18n('卡密本体')}</span><span>${i18n('买家付款后实际获得的完整内容')}</span></li>
            <li><span class="a-badge a-badge-success">${i18n('预告信息')}</span><span>${i18n('买家下单时可见，用于自选')}</span></li>
            <li><span class="a-badge a-badge-warning">${i18n('自选加价金额')}</span><span>${i18n('选填，不写默认为')} 0</span></li>
            <li><span class="a-badge a-badge-primary">${i18n('自选加价成本')}</span><span>${i18n('选填，不写默认为')} 0</span></li>
          </ul>
          <div class="uc-cardtip__label">${i18n('示例')}</div>
          <pre class="uc-cardtip__code">${i18n('账号')}:testname--${i18n('密码')}:testpassword123║${i18n('大区')}:${i18n('神境之地')}--${i18n('等级')}:100║5.5║2.5
ACC_US_12M_9F2K-7QPA-88XZ║${i18n('地区')}:${i18n('美区')}·${i18n('时长')}:12${i18n('个月')}║20║8
ACC_JP_6M_0KLD-22MM-PP31║${i18n('地区')}:${i18n('日区')}·${i18n('时长')}:6${i18n('个月')}</pre>
          <div class="uc-cardtip__warn"><span class="material-icons-outlined">warning_amber</span><span>${i18n('必须使用特殊符号')} <strong>║</strong>（U+2551），${i18n('不要用普通竖线')} |</span></div>
        </div>`);
                            }
                        },
                        {
                            title: "卡密信息",
                            name: "secret",
                            type: "textarea",
                            placeholder: "卡密信息，一行一个",
                            preserveLiteral: true,
                            height: 200,
                            required: true
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

    table = new Table("/admin/api/commodity/data", "#commodity-table");
    table.setUpdate("/admin/api/commodity/save");
    table.setColumns([
        {checkbox: true}
        , {
            field: 'name', title: '商品', formatter: (val, item) => {
                const cover = item.cover
                    ? `<img src="${item.cover}" data-id="${item.id}" class="render-image md-commodity-cell__cover" alt="${i18n('放大图片')}">`
                    : `<span class="md-commodity-cell__cover md-commodity-cell__cover--ph"><i class="fa-duotone fa-regular fa-image"></i></span>`;
                const path = Array.isArray(item.category_path) ? item.category_path : [];
                const sep = `<span class="md-commodity-cell__cat-sep">›</span>`;
                const cat = path.length
                    ? `<span class="md-commodity-cell__cat">${path.map(s => `<span class="md-commodity-cell__cat-seg">${s}</span>`).join(sep)}</span>`
                    : '';
                return `<div class="md-commodity-cell">${cover}<div class="md-commodity-cell__text"><span class="md-commodity-cell__name">${val ?? ''}</span>${cat}</div></div>`;
            }
        }
        , {
            field: 'card_count', title: '库存', class: "nowrap", formatter: function (val, item) {
                if (item.shared_id > 0) {
                    return '-';
                }
                if (item.delivery_way == 0) {
                    const count = `<a class='sku-stock-view' data-id='${item.id}' title='${i18n('点击查看')}SKU${i18n('详细库存')}' href='javascript:void(0);' style='color: var(--md-primary); font-weight: 600;'>${item.card_count}</a>`;
                    if (mobileAdminEnabled()) return count;
                    return count + ` <a class='add-card' data-id='${item.id}' style='color: green;' href='javascript:void(0);'>${i18n('加卡')}</a>`;
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
            field: 'owner', title: '商家', formatter: (_, __) => mdOwnerCell(_)
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
            field: 'operation', class: "action-col", title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + i18n("修改商品"), row);
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
                        modal(util.icon("fa-duotone fa-regular fa-copy me-1") + i18n("克隆商品"), clone);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        confirmCommodityDelete([row.id], row.name, () => {
                            util.post('/admin/api/commodity/del', {list: [row.id]}, res => {
                                if (!controllerActive || !table) return;
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-key text-success',
                    class: 'admin-mobile-operation-only text-success',
                    title: '添加卡密',
                    show: row => mobileAdminEnabled() && Number(row.shared_id || 0) <= 0 && Number(row.delivery_way) === 0,
                    click: (event, value, row) => uploadCard(row.id)
                }
            ]
        },
    ]);

    // 双击「商品」列 → MUI 详情弹窗；hover 提示「双击查看详细信息」（取代原「更多信息」按钮列）
    table.setColumnDetail({
        column: 'name',
        trigger: 'dblclick',
        header: false,
        title: (row) => row.name,
        fields: [
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
        ]
    });

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


    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> ${i18n('添加商品')}`);
    });

    $('.delist').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }

        message.ask("您确定要下架选中的商品吗？", () => {
            util.post("/admin/api/commodity/status", {list: data, status: 0}, res => {
                if (!controllerActive || !table) return;
                message.success("全部下架完成");
                table.refresh();
            });
        });
    });


    $('.listed').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }
        message.ask("您确定要上架选中的商品吗？", () => {
            util.post("/admin/api/commodity/status", {list: data, status: 1}, res => {
                if (!controllerActive || !table) return;
                message.success("全部上架完成");
                table.refresh();
            });
        });
    });


    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }
        confirmCommodityDelete(data, `${data.length} ${i18n('个商品')}`, () => {
            util.post("/admin/api/commodity/del", {list: data}, res => {
                if (!controllerActive || !table) return;
                showCommodityBatchDeleteResult(res);
                table.refresh();
            });
        });
    });


    $('.handle').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }

        let join = data.join(",");
        const useMobileBatchSettings = mobileAdminEnabled();
        let submitting = false;
        const submitMobileBatchSettings = (formData, index) => {
            if (submitting) return;
            const changes = batchSettingDefinitions.filter(item => {
                const value = String(formData[item.name] ?? 'keep');
                return value === '0' || value === '1';
            });
            if (!changes.length) {
                message.warning('请至少选择一项需要修改的设置');
                return;
            }
            const summary = changes.map(item => {
                const enabled = String(formData[item.name]) === '1';
                return `<li><b>${item.title}</b> → ${enabled ? i18n('开启') : i18n('关闭')}</li>`;
            }).join('');
            message.ask(
                `${i18n('将修改')} <b>${data.length} ${i18n('个商品')}</b>，${i18n('仅变更以下项目，其他设置保持原值：')}<ul style="text-align:left;margin:14px 0 0 22px">${summary}</ul>`,
                () => {
                    submitting = true;
                    util.post('/admin/api/commodity/fastEnable', formData, res => {
                        submitting = false;
                        if (!controllerActive || !table) return;
                        layer.close(index);
                        const count = Number(res.data?.selected_count || data.length);
                        message.success(`${i18n('已完成')} ${count} ${i18n('个商品的批量设置')}`);
                        table.refresh();
                    }, error => {
                        submitting = false;
                        if (!controllerActive) return;
                        message.alert(error.msg || i18n('批量设置失败'), 'error');
                    }, () => {
                        submitting = false;
                        if (!controllerActive) return;
                        message.error('网络错误，请稍后重试');
                    });
                },
                i18n('确认批量设置'),
                i18n('确认应用')
            );
        };

        component.popup({
            submit: useMobileBatchSettings ? submitMobileBatchSettings : '/admin/api/commodity/fastEnable',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-sliders") + i18n(" 批量设置"),
                    form: [
                        {
                            title: "",
                            name: "list",
                            type: "input",
                            hide: true,
                            default: join
                        },
                        ...batchSettingDefinitions.map(batchSettingForm)
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "320px",
            maxmin: false,
            done: () => {
                if (controllerActive && table) table.refresh();
            }
        });
    });

    $(document).off('click' + namespace, '.add-card').on('click' + namespace, '.add-card', function () {
        const id = $(this).data("id");
        uploadCard(id);
    });

    //SKU库存明细弹窗：动态拉取该商品按 类别/SKU 组合的库存统计
    //注意：util.get 的回调入参已经是 res.data 本身(与 util.post 不同)
    const openSkuStock = (commodityId) => {
        util.get(`/admin/api/card/skuStock?commodityId=${commodityId}`, data => {
            if (!controllerActive) return;
            const list = data?.list ?? [];
            if (!list.length) {
                layer.msg(i18n("该商品还没有入库卡密"));
                return;
            }
            const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            let rows = '';
            list.forEach(item => {
                let sku = item.sku;
                if (typeof sku === 'string') {
                    try {
                        sku = JSON.parse(sku);
                    } catch (e) {
                        sku = null;
                    }
                }
                let skuText = '-';
                if (sku && typeof sku === 'object') {
                    skuText = Object.keys(sku).map(k => `${k}: ${sku[k]}`).join(' / ') || '-';
                }
                const race = (item.race && item.race !== '-') ? item.race : '-';
                rows += `<tr><td>${esc(race)}</td><td>${esc(skuText)}</td><td style="color:var(--md-success);font-weight:600">${item.unsold}</td><td>${item.locked}</td><td>${item.sold}</td><td>${item.total}</td></tr>`;
            });
            layer.open({
                type: 1,
                title: `${util.icon("fa-duotone fa-regular fa-boxes-stacked")} SKU${i18n('库存')} - ${esc(String(data?.name ?? '').replace(/<[^>]*>/g, ''))}`,
                area: util.isPc() ? '640px' : ["100%", "100%"],
                shadeClose: true,
                content: `<div style="padding:16px;overflow:auto"><table class="table align-middle"><thead><tr><th>${i18n('类别')}</th><th>SKU</th><th>${i18n('未售')}</th><th>${i18n('锁定')}</th><th>${i18n('已售')}</th><th>${i18n('合计')}</th></tr></thead><tbody>${rows}</tbody></table></div>`
            });
        });
    };

    $(document).off('click' + namespace, '.sku-stock-view').on('click' + namespace, '.sku-stock-view', function () {
        openSkuStock($(this).data("id"));
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create, .delist, .listed, .btn-app-del, .handle').off(namespace);
        $(document).off(namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeCommodityDestroy === destroy) delete window.__mdTradeCommodityDestroy;
    }

    window.__mdTradeCommodityDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);

}();
