!function () {
    let table;
    const namespace = '.mdBusinessLevelController';
    let controllerActive = true;
    const escapeHtml = value => String(value == null ? '-' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '/favicon.ico';
        } catch (error) {
            return '/favicon.ico';
        }
    };
    const setInputMeta = (unique, name, attributes) => {
        const input = document.querySelector(`.${unique} [name="${name}"]`);
        if (!input) return;
        Object.entries(attributes).forEach(([key, value]) => input.setAttribute(key, value));
    };

    if (typeof window.__mdBusinessLevelDestroy === 'function') window.__mdBusinessLevelDestroy();

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/businessLevel/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "等级图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            height: 64,
                            required: true
                        },
                        {
                            title: "等级名称",
                            name: "name",
                            type: "input",
                            placeholder: "请输入等级名称",
                            inputmode: "text",
                            enterkeyhint: "next",
                            required: true
                        },
                        {
                            title: "供货商手续费",
                            name: "cost",
                            type: "input",
                            placeholder: "请使用小数表达百分比",
                            tips: "商户可以发布自己的商品，那么卖出的商品，就会通过这个费率被系统扣除一定的费用，也就是手续费。",
                            inputmode: "decimal",
                            enterkeyhint: "next",
                            required: true
                        },
                        {
                            title: "供货权限",
                            name: "supplier",
                            type: "switch",
                            text: "开启",
                            tips: "开启后，该等级的商户拥有供货权限。"
                        },
                        {
                            title: "分站权限",
                            name: "substation",
                            type: "switch",
                            text: "开启",
                            tips: "开启后，商户则拥有子站权限，可以使用子站功能。"
                        },
                        {
                            title: "绑定独立域名",
                            name: "top_domain",
                            type: "switch",
                            text: "开启",
                            tips: "开启后，商户的店铺可以绑定顶级域名，关闭后则只能使用子域名。"
                        },
                        {
                            title: "购买价格",
                            name: "price",
                            type: "input",
                            placeholder: "请输入该等级的购买价格",
                            inputmode: "decimal",
                            enterkeyhint: "done",
                            required: true
                        },
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "580px",
            renderComplete: unique => {
                setInputMeta(unique, 'name', {
                    inputmode: 'text',
                    enterkeyhint: 'next',
                    autocomplete: 'off'
                });
                setInputMeta(unique, 'cost', {
                    inputmode: 'decimal',
                    enterkeyhint: 'next',
                    autocomplete: 'off'
                });
                setInputMeta(unique, 'price', {
                    inputmode: 'decimal',
                    enterkeyhint: 'done',
                    autocomplete: 'off'
                });
            },
            done: () => {
                if (controllerActive) table.refresh();
            }
        });
    }

    table = new Table("/admin/api/businessLevel/data", "#business-level-table");
    table.setUpdate("/admin/api/businessLevel/save");

    table.setColumns([
        {
            field: 'name', title: '等级名称', formatter: (_, __) => {
                const icon = escapeHtml(safeImageUrl(__.icon));
                return `<div class="md-plugin"><img src="${icon}" class="md-plugin__icon" alt=""><span class="md-plugin__name">${escapeHtml(__.name)}</span></div>`;
            }
        }
        , {
            field: 'price', title: '购买价格', formatter: _ => format.money(_, "green")
        }
        , {
            field: 'cost', title: '供货商手续费'
        }
        , {
            field: 'supplier', title: '供货权限', type: "switch" , text :"ON|OFF"
        }
        , {
            field: 'substation', title: '分站权限', type: "switch" , text :"ON|OFF"
        }
        , {
            field: 'top_domain', title: '绑定独立域名', type: "switch" , text :"ON|OFF"
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square text-primary',
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改等级", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        const mobile = mobileAdminEnabled();
                        const prompt = mobile
                            ? '<div style="text-align:left;line-height:1.8">' +
                              '<p style="margin:0 0 8px">删除后该商户等级将永久消失，请先确认没有商户仍在使用此等级。</p>' +
                              '<div><b>等级：</b>' + escapeHtml(row.name) + '</div>' +
                              '<div><b>购买价格：</b>¥' + escapeHtml(row.price) + '</div>' +
                              '<p style="margin:8px 0 0;color:#d63b3b;font-weight:700">该操作不可撤销。</p></div>'
                            : '是否删除该等级？';
                        message.ask(prompt, () => {
                            util.post("/admin/api/businessLevel/del", {list: [row.id]}, () => {
                                if (!controllerActive) return;
                                table.refresh();
                                message.success("删除成功");
                            })
                        }, mobile ? '确认删除商户等级' : '您确定吗？', mobile ? '确认删除' : '确定');
                    }
                }
            ]
        },
    ]);
    table.render();

    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加等级`);
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdBusinessLevelDestroy === destroy) delete window.__mdBusinessLevelDestroy;
    }

    window.__mdBusinessLevelDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
