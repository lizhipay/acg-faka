!function () {
    const table = new Table("/user/api/commodityOrder/data", "#order-table");

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
        {checkbox: true}
        , {
            field: 'trade_no', title: '订单号'
        }
        , {
            field: 'owner', title: '会员', formatter: format.user
        }
        , {
            field: 'commodity', title: '商品', formatter: format.item
        }
        , {
            field: 'sku', title: 'SKU', formatter: (_, __) => {
                let d = ``;

                if (__.race) {
                    d += format.badge(`分类:${__.race}`, "a-badge-info");
                }

                if (!util.isEmptyOrNotJson(__.sku)) {
                    for (const skuKey in __.sku) {
                        d += format.badge(`${skuKey}:${__.sku[skuKey]}`, "a-badge-info");
                    }
                }

                return d ? format.badgeGroup(d) : "-";
            }
        }
        , {
            field: 'card_num', title: '数量'
        }
        , {
            field: 'amount', title: '金额', formatter: _ => format.money(_, "green")
        }
        , {
            field: 'commodity.delivery_way', title: '发货方式', dict: "_order_delivery_way"
        }
        , {
            field: 'pay', title: '支付方式', formatter: format.pay
        }
        , {
            field: 'status', title: '支付状态', dict: "_order_status"
        }
        , {
            field: 'delivery_status', title: '发货状态', dict: "_order_delivery_status"
        }
        , {
            field: 'secret', title: '卡密信息', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => _?.commodity?.delivery_way === 0 && _.delivery_status == 1,
                    click: (event, value, map, index) => {
                        layer.open({
                            type: 1,
                            title: `${util.icon("fa-duotone fa-regular fa-eye")} 查看卡密`,
                            area: util.isPc() ? ['420px', '420px'] : ["100%", "100%"],
                            content: '<textarea class="layui-input" style="padding: 15px;height: 100%;">' + map.secret + '</textarea>'
                        });
                    }
                },
                {
                    icon: `fa-duotone fa-regular fa-truck-ramp-box`,
                    class: "text-success",
                    title: "手动发货",
                    show: _ => _?.commodity?.delivery_way === 1,
                    click: (event, value, map, index) => {
                        modal(`${util.icon("fa-duotone fa-regular fa-truck-ramp-box")} 发货内容`, map);
                    }
                },

            ]
        }
        , {
            field: 'widget', title: '控件', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => {
                        let parse = JSON.parse(_.widget);
                        if (!parse || parse.length == 0) {
                            return false;
                        }
                        return true;
                    },
                    click: (event, value, map, index) => {
                        let html = '<div style="padding: 10px;" class="more-table">\n' +
                            '        <table class="layui-table">\n' +
                            '            <tbody class="widget-container">\n' +
                            '            </tbody>\n' +
                            '        </table>\n' +
                            '    </div>';
                        let parse = JSON.parse(map.widget);
                        if (!parse) {
                            return;
                        }
                        layer.open({
                            type: 1,
                            shadeClose: true,
                            title: '<i class="fa-duotone fa-regular fa-diamonds-4"></i> <span style="color: gray;">Widget</span>',
                            content: html,
                            area: util.isPc() ? "420px" : ["100%", "100%"],
                            success: () => {
                                for (const parseKey in parse) {
                                    $('.widget-container').append('<tr><td>' + parse[parseKey].cn + '</td><td>' + parse[parseKey].value + '</td></tr>');
                                }
                            }
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
            field: 'create_device', title: '设备', dict: "_common_device"
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