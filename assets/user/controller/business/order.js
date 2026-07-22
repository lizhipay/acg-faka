!function () {
    const table = new Table("/user/api/commodityOrder/data", "#order-table");
    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const safeInlineHtml = value => window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === 'function'
        ? window.SeattleTheme.safeInlineHtml(value)
        : escapeHtml(value);
    const parseWidget = value => {
        try {
            const parsed = typeof value === 'string' ? JSON.parse(value) : value;
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            return null;
        }
    };
    const hasPermission = (row, permission) => row?.merchant_permissions?.[permission] === true;
    const deviceLabel = (value, row) => {
        if (!hasPermission(row, 'view_purchase_info')) return '受保护';
        return ({
            0: `${util.icon('fa-duotone fa-regular fa-window')} PC`,
            1: `${util.icon('fa-duotone fa-regular fa-robot')} 安卓`,
            2: `${util.icon('fa-duotone fa-regular fa-apple-whole')} IOS`,
            3: `${util.icon('fa-duotone fa-regular fa-tablet')} iPad`
        })[Number(value)] || '未知设备';
    };
    const safeItem = item => {
        if (!item) return '-';
        const image = item.cover ? `<img src="${escapeHtml(item.cover)}" class="table-item-icon" alt="">` : '';
        return `<span class="table-item">${image}<span class="table-item-name">${safeInlineHtml(item.name || '未命名商品')}</span></span>`;
    };
    const safeUser = item => {
        if (!item) return '<span class="text-gray">访客</span>';
        const image = item.avatar ? `<img src="${escapeHtml(item.avatar)}" class="table-item-icon" alt="">` : '';
        return `<span class="table-item table-item-user">${image}<span class="table-item-name">${escapeHtml(item.username || '会员')}</span></span>`;
    };
    const safePay = item => {
        if (!item) return '-';
        const image = item.icon ? `<img src="${escapeHtml(item.icon)}" class="item-icon" alt="">` : '';
        return `<span class="pay-item">${image}<span class="item-name">${escapeHtml(item.name || '未知方式')}</span></span>`;
    };
    const openSecret = map => {
        const secret = String(map && map.secret || '');
        const tradeNo = String(map && map.trade_no || '');
        layer.open({
            type: 1,
            title: `${util.icon("fa-duotone fa-regular fa-eye")} 查看交付内容`,
            area: util.isPc() ? '520px' : ["100%", "100%"],
            shadeClose: true,
            content: `<div class="md-secret"><div class="md-secret__meta"><span class="a-badge a-badge-info">订单 ${escapeHtml(tradeNo || '—')}</span><span class="a-badge a-badge-success">只读内容</span></div><pre class="md-secret__code">${escapeHtml(secret || '暂无交付内容')}</pre><div class="md-secret__bar"><button type="button" class="md-secret__btn md-secret__btn--primary" data-order-secret-copy>${util.icon("fa-duotone fa-regular fa-copy")} 复制交付内容</button></div></div>`,
            success: layerObject => {
                $(layerObject).find('[data-order-secret-copy]').on('click', () => {
                    util.copyTextToClipboard(secret, () => message.success('交付内容已复制'), () => message.error('复制失败，请手动选择内容'));
                });
            }
        });
    };

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/user/api/commodityOrder/delivery',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: false,
                            name: "secret",
                            type: "textarea",
                            placeholder: "填写要发货的信息",
                            height: 300
                        }
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "580px",
            done: () => {
                table.refresh();
            }
        });
    }

    table.setColumns([
        {
            field: 'trade_no', title: '订单号', class: 'nowrap', width: 190
        }
        , {
            field: 'owner', title: '会员', class: 'nowrap', width: 130, formatter: safeUser
        }
        , {
            field: 'commodity', title: '商品', class: 'nowrap', width: 180, formatter: safeItem
        }
        , {
            field: 'sku', title: 'SKU', class: 'nowrap', width: 180, formatter: (_, __) => {
                let d = ``;

                if (__.race) {
                    d += format.badge(`分类:${escapeHtml(__.race)}`, "a-badge-info");
                }

                if (!util.isEmptyOrNotJson(__.sku)) {
                    for (const skuKey in __.sku) {
                        d += format.badge(`${escapeHtml(skuKey)}:${escapeHtml(__.sku[skuKey])}`, "a-badge-info");
                    }
                }

                return d ? format.badgeGroup(d) : "-";
            }
        }
        , {
            field: 'card_num', title: '数量', class: 'nowrap', width: 70
        }
        , {
            field: 'amount', title: '金额', class: 'nowrap', width: 90, formatter: _ => format.money(_, "green")
        }
        , {
            field: 'commodity.delivery_way', title: '发货方式', dict: "_order_delivery_way", class: 'nowrap', width: 100
        }
        , {
            field: 'pay', title: '支付方式', class: 'nowrap', width: 130, formatter: safePay
        }
        , {
            field: 'status', title: '支付状态', dict: "_order_status", class: 'nowrap', width: 100
        }
        , {
            field: 'delivery_status', title: '发货状态', dict: "_order_delivery_status", class: 'nowrap', width: 100
        }
        , {
            field: 'secret', title: '交付内容', type: "button", class: 'nowrap', width: 190, buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => hasPermission(_, 'view_secret') && Number(_?.commodity?.delivery_way) === 0 && Number(_.delivery_status) === 1,
                    click: (event, value, map, index) => {
                        openSecret(map);
                    }
                },
                {
                    icon: `fa-duotone fa-regular fa-truck-ramp-box`,
                    class: "text-success",
                    title: "手动发货",
                    show: _ => hasPermission(_, 'delivery') && Number(_.status) === 1 && Number(_?.commodity?.delivery_way) === 1,
                    click: (event, value, map, index) => {
                        modal(`${util.icon("fa-duotone fa-regular fa-truck-ramp-box")} 发货内容`, map);
                    }
                },

            ]
        }
        , {
            field: 'widget', title: '购买信息', type: "button", class: 'nowrap', width: 100, buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => {
                        if (!hasPermission(_, 'view_purchase_info')) return false;
                        const parsed = parseWidget(_.widget);
                        return !!(parsed && Object.keys(parsed).length);
                    },
                    click: (event, value, map, index) => {
                        const parsed = parseWidget(map.widget);
                        if (!parsed || !Object.keys(parsed).length) {
                            message.error('该订单没有可展示的购买信息');
                            return;
                        }
                        const rows = Object.values(parsed).map(item => {
                            const label = item && typeof item === 'object' ? item.cn : '';
                            const content = item && typeof item === 'object' ? item.value : item;
                            return `<tr><th>${escapeHtml(label || '字段')}</th><td>${escapeHtml(content == null ? '—' : content)}</td></tr>`;
                        }).join('');
                        layer.open({
                            type: 1,
                            shadeClose: true,
                            title: `${util.icon('fa-duotone fa-regular fa-rectangle-list')} 购买信息`,
                            content: `<div class="more-table"><table class="layui-table"><tbody>${rows}</tbody></table></div>`,
                            area: util.isPc() ? "480px" : ["100%", "100%"]
                        });
                    }
                }
            ]
        },
    ]);

    table.setFloatMessage([
        {
            field: 'contact', title: '联系方式'
        },
        {
            field: 'password', title: '查询密码'
        },
        {
            field: 'create_time', title: '下单时间'
        },
        {
            field: 'pay_time', title: '支付时间'
        }
        , {
            field: 'create_ip', title: '客户IP'
        }
        , {
            field: 'create_device', title: '设备', formatter: deviceLabel
        }
        , {
            field: 'card.secret', title: '预选卡密'
        }
        , {
            field: 'coupon.code', title: '优惠券'
        }
    ]);

    table.setSearch([
        {title: "订单号", name: "equal-trade_no", type: "input"},
        {title: "商品ID", name: "equal-commodity_id", type: "input"},
        {title: "卡密信息(模糊)", name: "search-secret", type: "input"},
        {title: "联系方式", name: "equal-contact", type: "input"},
        {title: "发货状态", name: "equal-delivery_status", type: "select", dict: "_order_delivery_status"},
        {
            title: "下单设备",
            name: "equal-create_device",
            type: "select",
            dict: "_common_device",
        },
        {title: "IP地址", name: "equal-create_ip", type: "input"},
        {title: "会员ID，0=访客", name: "equal-owner", type: "input"},
        {title: "下单时间", name: "between-create_time", type: "date"},
    ]);
    table.setState("status", "_order_status");
    table.render();
}();
