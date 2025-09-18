!function () {
    const table = new Table("/user/api/agentMember/data", "#member-table");

    table.setColumns([
        {field: 'id', title: 'ID', width: 80}
        , {field: 'avatar', title: '用户名', formatter: (_, __) => format.user(__)}
        , {field: 'group', title: '会员等级', formatter: _ => format.group(_)}
        , {field: 'email', title: '邮箱'}
        , {field: 'phone', title: '手机号'}
        , {field: 'qq', title: 'QQ'}
        , {field: 'balance', title: '余额', formatter: _ => format.money(_, "green"), sort: true}
        , {field: 'recharge', title: '总充值', sort: true}
        , {field: 'coin', title: '硬币', formatter: _ => format.money(_, "#447cf3"), sort: true}
        , {
            field: 'create_time', title: '注册时间'
        }
        , {field: 'status', title: '状态', dict: "_user_status"}
        , {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-envelope-open-dollar ',
                    title: "转账",
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        component.popup({
                            submit: '/user/api/agentMember/transfer',
                            tab: [
                                {
                                    name: "<i class='fa-duotone fa-regular fa-envelope-open-dollar'></i> 转账",
                                    form: [
                                        {title: false, name: "amount", type: "input", placeholder: "请输入金额"}
                                    ]
                                }
                            ],
                            assign: {id: row.id},
                            autoPosition: true,
                            height: "auto",
                            width: "320px",
                            maxmin: false,
                            done: () => {
                                table.refresh();
                            },
                            confirmText: `<i class="fa-duotone fa-regular fa-circle-check"></i>确认转账`
                        });
                    }
                }
            ]
        },
    ]);


    table.setSearch([
        {
            title: "会员ID", name: "equal-id", type: "input"
        }
    ]);

    table.setState("status", "_user_status");
    table.render();
}();