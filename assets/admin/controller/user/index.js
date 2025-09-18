!function () {
    const table = new Table("/admin/api/user/data", "#user-table");
    const marketViewDom = `<div style="padding: 0px;" class="more-table"><table class="layui-table"><tbody><tr><td colspan="2" style="text-align: center;"><img src="[avatar]"                                                                 style="height: 80px;width:80px;border-radius: 100%;box-shadow: 1px 1px 10px 1px #ed9b9bb3;"></td></tr><tr><td>店铺名称</td><td>[shop_name]</td></tr><tr><td>浏览器标题</td><td>[title]</td></tr><tr><td>店铺公告</td><td>[notice]</td></tr><tr><td>客服QQ</td><td>[service_qq]</td></tr><tr><td>客服链接</td><td><a href="[service_url]" target="_blank">[service_url]</a></td></tr><tr><td>子域名</td><td><a href="//[subdomain]" target="_blank">[subdomain]</a></td></tr><tr><td>绑定域名</td><td><a href="//[topdomain]" target="_blank">[topdomain]</a></td></tr><tr><td>主站商品</td><td>[master_display]</td></tr><tr><td>创建时间</td><td>[create_time]</td></tr><tr><td>今日交易</td><td>[today_order_amount]</td></tr><tr><td>昨日交易</td><td>[yesterday_order_amount]</td></tr><tr><td>本周交易</td><td>[week_order_amount]</td></tr><tr><td>本月交易</td><td>[month_order_amount]</td></tr><tr><td>总交易</td><td>[total_order_amount]</td></tr></tbody></table></div>`;

    const modal = function (title, a = {}) {
        let values = {...a};

        delete values.password;
        values?.group && (values.group_id = values.group.id);
        values?.business_level && (values.business_level = values.business_level.id);

        component.popup({
            submit: '/admin/api/user/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "头像", name: "avatar", type: "image",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            placeholder: "请选择图片", width: 64, height: 64
                        },
                        {title: "用户名", name: "username", type: "input", placeholder: "请输入用户名"},
                        {
                            title: "会员等级",
                            name: "group_id",
                            type: "select",
                            dict: "user_group,id,name",
                            placeholder: "请选择"
                        },
                        {
                            title: "商户等级",
                            name: "business_level",
                            type: "select",
                            dict: "business_level,id,name",
                            placeholder: "暂未开通"
                        },
                        {title: "邮箱", name: "email", type: "input", placeholder: "请输入邮箱"},
                        {title: "手机", name: "phone", type: "input", placeholder: "请输入手机号"},
                        {title: "QQ", name: "qq", type: "input", placeholder: "请输入QQ号"},
                        {title: "登录密码", name: "password", type: "input", placeholder: "不修改请留空"},
                        {title: "上级ID", name: "pid", type: "input", placeholder: "请输入上级ID"},
                        {title: "状态", name: "status", type: "switch", text: "正常"},
                    ]
                }
            ],
            assign: values,
            autoPosition: true,
            height: "auto",
            width: "520px",
            done: () => {
                table.refresh();
            }
        });
    }
    table.setColumns([
        {checkbox: true},
        {field: 'id', title: 'ID', width: 80}
        , {field: 'avatar', title: '用户名', formatter: (_, __) => format.user(__)}
        , {field: 'group', title: '会员等级', formatter: _ => format.group(_)}
        , {field: 'email', title: '邮箱'}
        , {field: 'phone', title: '手机号'}
        , {field: 'qq', title: 'QQ'}
        , {field: 'balance', title: '余额', formatter: _ => format.money(_, "green"), sort: true}
        , {field: 'recharge', title: '元气', sort: true}
        , {field: 'coin', title: '硬币', formatter: _ => format.money(_, "#447cf3"), sort: true}
        , {
            field: 'business_level', title: '商户信息', formatter: (_, __) => {
                if (!_) {
                    return '-';
                }
                const did = util.generateRandStr();

                $(document).on("click", `.${did}`, () => {
                    util.get(`/admin/api/user/statistics?id=${_.id}`, data => {


                        component.popup({
                            submit: false,
                            maxmin: false,
                            tab: [
                                {
                                    name: `<i class="fa-duotone fa-regular fa-face-viewfinder"></i> 查看商家`,
                                    form: [
                                        {
                                            title: false, name: "custom", type: "custom", complete: (form, dom) => {

                                                dom.html(marketViewDom.replace("[avatar]", __.avatar ? __.avatar : '/favicon.ico')
                                                    .replace("[shop_name]", __.business.shop_name ? __.business.shop_name : "-")
                                                    .replace("[title]", __.business.title ? __.business.title : "-")
                                                    .replace("[notice]", __.business.notice ? __.business.notice : "-")
                                                    .replace("[service_qq]", __.business.service_qq ? __.business.service_qq : "-")
                                                    .replaceAll("[service_url]", __.business.service_url ? __.business.service_url : "-")
                                                    .replaceAll("[subdomain]", __.business.subdomain ? __.business.subdomain : "-")
                                                    .replaceAll("[topdomain]", __.business.topdomain ? __.business.topdomain : "-")
                                                    .replace("[create_time]", __.business.create_time)
                                                    .replace("[master_display]", __.business.master_display == 1 ? "<span style='color: green;'>显示</span>" : "<span style='color: green;'>隐藏</span>")
                                                    .replace("[today_order_amount]", data.today_order_amount)
                                                    .replace("[yesterday_order_amount]", data.yesterday_order_amount)
                                                    .replace("[week_order_amount]", data.week_order_amount)
                                                    .replace("[month_order_amount]", data.month_order_amount)
                                                    .replace("[total_order_amount]", data.total_order_amount));

                                            }
                                        },
                                    ]
                                }
                            ],
                            autoPosition: true,
                            height: "auto",
                            width: "520px"
                        });

                    });
                });

                return `${_.name} <a class="text-primary ${did}" href="javascript:void(0);">详细</a>`
            }
        }
        , {field: 'parent', title: '上级', formatter: format.user}
        , {field: 'status', title: '状态', dict: "_user_status"}
        , {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-envelope-open-dollar text-success',
                    tips: "余额操作",
                    click: (event, value, row, index) => {
                        component.popup({
                            submit: '/admin/api/user/recharge',
                            tab: [
                                {
                                    name: "<i class='fa-duotone fa-regular fa-envelope-open-dollar'></i> 余额充值",
                                    form: [
                                        {
                                            title: "类型",
                                            name: "action",
                                            type: "radio",
                                            placeholder: "请选择",
                                            default: 1,
                                            dict: [
                                                {id: 1, name: "<b style='color: green;'>充值</b>"},
                                                {id: 0, name: "<b style='color: red;'>扣费</b>"},
                                            ]
                                        },
                                        {title: "金额", name: "amount", type: "input", placeholder: "请输入金额"},
                                        {title: "原因", name: "log", type: "input", placeholder: "请输入原因"},
                                        {title: "元气累计", name: "total", type: "switch", text: "是", default: 1},
                                    ]
                                }
                            ],
                            assign: {id: row.id},
                            autoPosition: true,
                            height: "auto",
                            width: "520px",
                            maxmin: false,
                            done: () => {
                                table.refresh();
                            }
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-coins text-warning',
                    tips: "硬币操作",
                    click: (event, value, row, index) => {
                        component.popup({
                            submit: '/admin/api/user/coin',
                            tab: [
                                {
                                    name: `<i class="fa-duotone fa-regular fa-coins"></i> 硬币充值`,
                                    form: [
                                        {
                                            title: "类型",
                                            name: "action",
                                            type: "radio",
                                            placeholder: "请选择",
                                            default: 1,
                                            dict: [
                                                {id: 1, name: "<b style='color: green;'>充值</b>"},
                                                {id: 0, name: "<b style='color: red;'>扣费</b>"},
                                            ]
                                        },
                                        {title: "金额", name: "amount", type: "input", placeholder: "请输入硬币数量"},
                                        {title: "原因", name: "log", type: "input", placeholder: "请输入原因"},
                                        {title: "元气累计", name: "total", type: "switch", text: "是", default: 1}
                                    ]
                                }
                            ],
                            assign: {id: row.id},
                            autoPosition: true,
                            height: "auto",
                            width: "520px",
                            maxmin: false,
                            done: () => {
                                table.refresh();
                            }
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square text-primary',
                    tips: '修改',
                    click: (event, value, row, index) => {
                        modal(`<i class="fa-duotone fa-regular fa-user-pen"></i> 修改用户`, row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-heart-circle-check text-success',
                    tips: '启用此用户',
                    show: _ => _.status === 0,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/user/save', {id: row.id, status: 1}, res => {
                            message.success("启用成功");
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-ban',
                    tips: '禁用此用户',
                    show: _ => _.status === 1,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/user/save', {id: row.id, status: 0}, res => {
                            message.info("已禁用");
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    tips: '删除此用户',
                    click: (event, value, row, index) => {

                        message.ask("您正在删除会员，该操作无法撤回，是否还要继续？", () => {
                            util.post("/admin/api/user/del", {list: [row.id]}, () => {
                                message.success("删除成功");
                                table.refresh();
                            })
                        });
                    }
                }
            ]
        },

    ]);



    table.setFloatMessage([
        {field: 'nicename', title: '真实姓名'}
        , {field: 'total_coin', title: '总硬币'}
        , {field: 'create_time', title: '注册时间'}
        , {field: 'login_time', title: '登录时间'}
        , {field: 'login_ip', title: '最后登录IP'}
        , {field: 'last_login_time', title: '上次登录时间'}
        , {field: 'last_login_ip', title: '上次登录IP'}
        , {field: 'alipay', title: '支付宝'}
        , {
            field: 'wechat',
            title: '微信收款码',
            formatter: function (val, item) {
                if (!val) {
                    return '-';
                }
                $(document).off('click', `.wxqrcode-click-${item.id}`);
                $(document).on('click', `.wxqrcode-click-${item.id}`, function () {
                    layer.open({
                        type: 1,
                        title: false,
                        closeBtn: 0, //不显示关闭按钮
                        anim: 5,
                        area: ['245px', '245px'],
                        shadeClose: true, //开启遮罩关闭
                        content: '<div class="wxqrcode-' + item.id + '" style="padding: 22px 20px 20px 24px;overflow: hidden;"></div>',
                        success: () => {
                            $('.wxqrcode-' + item.id).qrcode({
                                render: "canvas",
                                width: 200,
                                height: 200,
                                text: item.wechat
                            });
                        }
                    });
                });
                return `<a href="javascript:void(0);" class="text-primary wxqrcode-click-${item.id}">查看</a>`;
            }
        }
        , {
            field: 'settlement',
            title: '结算方式',
            dict: [
                {id: 0, name: `<span class="text-primary">支付宝</span>`},
                {id: 1, name: `<span class="text-success">微信</span>`},
            ]
        }
    ]);


    table.setSearch([
        {title: "用户名", name: "search-username", type: "input"},
        {title: "邮箱", name: "equal-email", type: "input"},
        {title: "手机号", name: "equal-phone", type: "input"},
        {title: "QQ号", name: "equal-qq", type: "input"},
        {title: "IP地址", name: "equal-login_ip", type: "input"},
        {title: "上级ID", name: "equal-pid", type: "remoteSelect", dict: "user,id,username"}
    ]);
    table.setState("status", "_user_status");

    table.render();

    $('.handle').click(() => {
        let selections = table.getSelectionIds();
        if (selections.length == 0) {
            message.error("请至少勾选1个会员进行操作！");
            return;
        }

        let join = selections.join(",");

        component.popup({
            submit: '/admin/api/user/fastUpdateUserGroup',
            tab: [
                {
                    name: `<i class="fa-duotone fa-regular fa-user-pen"></i> 批量修改会员等级`,
                    form: [
                        {
                            title: "",
                            name: "list",
                            type: "input",
                            hide: true,
                            default: join
                        },
                        {
                            title: "会员等级",
                            name: "group_id",
                            type: "select",
                            dict: "user_group,id,name",
                            placeholder: "请选择"
                        }
                    ]
                }
            ],
            assign: {},
            autoPosition: true,
            content: {
                css: {
                    height: "auto",
                    overflow: "inherit"
                }
            },
            maxmin: false,
            height: "auto",
            width: "520px",
            done: () => {
                table.refresh();
            }
        });
    });

    $('.btn-app-del').click(() => {
        let selections = table.getSelectionIds();
        if (selections.length == 0) {
            message.error("请至少勾选1个会员进行操作！");
            return;
        }

        message.ask('您确定要删除已经选中的用户吗？这是不可恢复的操作！', function () {
            util.post("/admin/api/user/del", {list: selections}, res => {
                message.success("全部删除完毕");
                table.refresh();
            });
        });
    });
}();