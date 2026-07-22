!function () {
    const table = new Table("/admin/api/user/data", "#user-table");
    const controllerLayers = new Set();
    const businessRows = new Map();
    let controllerActive = true;
    let accountConfirmationOpen = false;
    let groupUpdatePending = false;
    const openControllerLayer = options => {
        const originalEnd = options.end;
        let index;
        index = layer.open({
            ...options,
            end: function () {
                controllerLayers.delete(index);
                if (typeof originalEnd === 'function') return originalEnd.apply(this, arguments);
            }
        });
        if (controllerActive) controllerLayers.add(index); else layer.close(index);
        return index;
    };
    $(document)
        .off('pjax:beforeReplace.mdUserController')
        .one('pjax:beforeReplace.mdUserController', () => {
            controllerActive = false;
            controllerLayers.forEach(index => layer.close(index));
            controllerLayers.clear();
            businessRows.clear();
            $('.handle, .btn-app-del').off('.mdAdminUserToolbar');
            $(document).off('.mdUserBusinessDetails');
            if (accountConfirmationOpen && typeof Swal !== 'undefined') Swal.close();
            accountConfirmationOpen = false;
            groupUpdatePending = false;
        });
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const inputMeta = (name, attributes) => form => {
        $('.' + form.unique + ' input[name="' + name + '"]').attr(attributes);
    };
    const mobileAccountSubmit = (url, row, account) => {
        let confirming = false;
        let requesting = false;
        return (data, popupIndex) => {
            if (!controllerActive || confirming || requesting || accountConfirmationOpen) return;
            const action = String(data.action);
            if (!['0', '1'].includes(action)) {
                message.warning('请选择增加或扣减。');
                return;
            }
            const rawAmount = String(data.amount ?? '').trim();
            const amountNumber = Number(rawAmount);
            if (!/^\d+(?:\.\d{1,2})?$/.test(rawAmount) || !Number.isFinite(amountNumber) || amountNumber <= 0 || amountNumber > 99999999.99) {
                message.warning('请输入大于 0、最多两位小数且不超过 99999999.99 的数量。');
                return;
            }
            const amount = amountNumber.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
            const rawReason = String(data.log ?? '').trim();
            const reasonLength = Array.from(rawReason).length;
            if (reasonLength < 2 || reasonLength > 64) {
                message.warning('操作原因去除首尾空格后须为 2–64 个字。');
                return;
            }
            data = {...data, amount: amount, log: rawReason};
            const increase = action === '1';
            const direction = increase ? '增加' : '扣减';
            const username = escapeHtml(row.username || '未命名会员');
            const userId = escapeHtml(row.id ?? '-');
            const current = escapeHtml(row[account.field] ?? '0');
            const reason = escapeHtml(rawReason);
            const cumulativeLabel = account.totalLabel || '累计统计';
            const cumulative = increase
                ? (String(data.total) === '1' ? `计入${cumulativeLabel}` : `不计入${cumulativeLabel}`)
                : `扣减操作不影响${cumulativeLabel}`;
            confirming = true;
            accountConfirmationOpen = true;
            Swal.fire({
                title: `确认${direction}${account.label}`,
                html: `<div style="text-align:left;line-height:1.8;">
                    <div><b>会员：</b>${username}（ID ${userId}）</div>
                    <div><b>当前${account.label}：</b>${current}</div>
                    <div><b>变动方向：</b>${direction}</div>
                    <div><b>变动数量：</b>${escapeHtml(amount)}</div>
                    <div><b>累计统计：</b>${escapeHtml(cumulative)}</div>
                    <div><b>操作原因：</b>${reason}</div>
                    <div style="margin-top:10px;color:#d14343;">提交后会立即写入账单并改变会员${account.label}，无法在本页面一键撤销。</div>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                cancelButtonText: '返回修改',
                confirmButtonText: `确认${direction}`
            }).then(result => {
                confirming = false;
                accountConfirmationOpen = false;
                if (!(result.isConfirmed === true || result.value === true) || !controllerActive || requesting) return;
                requesting = true;
                util.post({
                    url: url,
                    data: data,
                    done: res => {
                        requesting = false;
                        if (!controllerActive) return;
                        layer.close(popupIndex);
                        message.alert(!res.msg || res.msg === 'success' ? '会员账户变动已保存。' : res.msg, 'success');
                        table.refresh();
                    },
                    error: res => {
                        requesting = false;
                        if (controllerActive) message.alert(res?.msg || '会员账户变动未能保存。', 'error');
                    },
                    fail: () => {
                        requesting = false;
                        if (controllerActive) message.error('网络异常，会员账户变动未提交。');
                    }
                });
            });
        };
    };
    const detailValue = value => {
        value = String(value ?? '').trim();
        return value ? escapeHtml(value) : '-';
    };
    const safeExternalLink = (value, hostOnly = false) => {
        const label = String(value ?? '').trim();
        if (!label) return '-';
        let href = label;
        if (hostOnly && !/^[a-z][a-z0-9+.-]*:/i.test(href) && !href.startsWith('//')) href = 'https://' + href;
        try {
            const parsed = new URL(href, window.location.origin);
            if (!['http:', 'https:'].includes(parsed.protocol)) return detailValue(label);
            return `<a href="${escapeHtml(parsed.href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(label)}</a>`;
        } catch (error) {
            return detailValue(label);
        }
    };
    const detailRow = (label, value) => `<div class="md-detail__row"><span class="md-detail__label">${escapeHtml(label)}</span><span class="md-detail__value">${value}</span></div>`;
    const renderBusinessDetail = (row, statistics) => {
        const business = row.business || {};
        const avatar = row.avatar || '/favicon.ico';
        const shopName = business.shop_name || row.username || '未命名商户';
        return `<div class="md-detail md-user-business-detail">
            <div class="md-detail__header">${mdUserCell({avatar: escapeHtml(avatar), username: escapeHtml(shopName), id: escapeHtml(row.id)})}</div>
            <div class="md-detail__body">
                ${detailRow('浏览器标题', detailValue(business.title))}
                ${detailRow('店铺公告', detailValue(business.notice))}
                ${detailRow('客服 QQ', detailValue(business.service_qq))}
                ${detailRow('客服链接', safeExternalLink(business.service_url))}
                ${detailRow('子域名', safeExternalLink(business.subdomain, true))}
                ${detailRow('绑定域名', safeExternalLink(business.topdomain, true))}
                ${detailRow('主站商品', business.master_display == 1 ? '<span class="text-success">显示</span>' : '<span class="text-muted">隐藏</span>')}
                ${detailRow('创建时间', detailValue(business.create_time))}
                ${detailRow('今日交易', detailValue(statistics.today_order_amount))}
                ${detailRow('昨日交易', detailValue(statistics.yesterday_order_amount))}
                ${detailRow('本周交易', detailValue(statistics.week_order_amount))}
                ${detailRow('本月交易', detailValue(statistics.month_order_amount))}
                ${detailRow('总交易', detailValue(statistics.total_order_amount))}
            </div>
        </div>`;
    };
    const renderLegacyBusinessDetail = (row, statistics) => {
        const business = row.business || {};
        const link = (value, hostOnly = false) => {
            const label = detailValue(value);
            if (label === '-') return label;
            return safeExternalLink(value, hostOnly);
        };
        return `<div style="padding:0;" class="more-table"><table class="layui-table"><tbody>
            <tr><td colspan="2" style="text-align:center;"><img src="${escapeHtml(row.avatar || '/favicon.ico')}" alt="" style="height:80px;width:80px;border-radius:100%;box-shadow:1px 1px 10px 1px #ed9b9bb3;"></td></tr>
            <tr><td>店铺名称</td><td>${detailValue(business.shop_name)}</td></tr>
            <tr><td>浏览器标题</td><td>${detailValue(business.title)}</td></tr>
            <tr><td>店铺公告</td><td>${detailValue(business.notice)}</td></tr>
            <tr><td>客服QQ</td><td>${detailValue(business.service_qq)}</td></tr>
            <tr><td>客服链接</td><td>${link(business.service_url)}</td></tr>
            <tr><td>子域名</td><td>${link(business.subdomain, true)}</td></tr>
            <tr><td>绑定域名</td><td>${link(business.topdomain, true)}</td></tr>
            <tr><td>主站商品</td><td>${business.master_display == 1 ? '<span style="color:green;">显示</span>' : '<span style="color:green;">隐藏</span>'}</td></tr>
            <tr><td>创建时间</td><td>${detailValue(business.create_time)}</td></tr>
            <tr><td>今日交易</td><td>${detailValue(statistics.today_order_amount)}</td></tr>
            <tr><td>昨日交易</td><td>${detailValue(statistics.yesterday_order_amount)}</td></tr>
            <tr><td>本周交易</td><td>${detailValue(statistics.week_order_amount)}</td></tr>
            <tr><td>本月交易</td><td>${detailValue(statistics.month_order_amount)}</td></tr>
            <tr><td>总交易</td><td>${detailValue(statistics.total_order_amount)}</td></tr>
        </tbody></table></div>`;
    };
    const openBusinessDetail = row => {
        const userId = Number(row?.id || 0);
        if (!userId || !row?.business_level || !row?.business) return;
        util.get(`/admin/api/user/statistics?id=${userId}`, statistics => {
            if (!controllerActive) return;
            const mobile = mobileAdminEnabled();
            component.popup({
                submit: false,
                maxmin: false,
                tab: [{
                    name: mobile
                        ? `<i class="fa-duotone fa-regular fa-store"></i> 商户详情`
                        : `<i class="fa-duotone fa-regular fa-face-viewfinder"></i> 查看商家`,
                    form: [{
                        title: false,
                        name: 'business_detail',
                        type: 'custom',
                        complete: (form, dom) => dom.html(mobile
                            ? renderBusinessDetail(row, statistics || {})
                            : renderLegacyBusinessDetail(row, statistics || {}))
                    }]
                }],
                autoPosition: true,
                height: 'auto',
                width: '520px'
            });
        });
    };
    const openWechatQr = (value, id) => {
        const mobile = mobileAdminEnabled();
        const targetClass = mobile ? `md-user-wechat-qr-${Number(id) || 0}` : `wxqrcode-${Number(id) || 0}`;
        openControllerLayer({
            ...(mobile ? {
                type: 1,
                title: '微信收款码',
                closeBtn: 1,
                anim: 2,
                area: ['100%', 'auto'],
                offset: 'b',
                maxmin: false,
                resize: false,
                move: false,
                shadeClose: true,
                skin: 'admin-mobile-layer-popup admin-mobile-layer-popup--sheet md-user-qr-layer',
                content: `<div class="md-user-wechat-qr ${targetClass}"></div>`
            } : {
                type: 1,
                title: false,
                closeBtn: 0,
                anim: 5,
                area: ['245px', '245px'],
                shadeClose: true,
                content: `<div class="${targetClass}" style="padding:22px 20px 20px 24px;overflow:hidden;"></div>`
            }),
            success: layero => {
                $(layero).find(`.${targetClass}`).qrcode({
                    render: 'canvas',
                    width: 200,
                    height: 200,
                    text: value
                });
            }
        });
    };

    $(document)
        .off('click.mdUserBusinessDetails', '.md-user-business-detail-trigger')
        .on('click.mdUserBusinessDetails', '.md-user-business-detail-trigger', function (event) {
            event.preventDefault();
            const row = businessRows.get(String($(this).attr('data-user-id') || ''));
            if (row) openBusinessDetail(row);
        })
        .off('click.mdAdminUserWechatQr', '.md-user-wechat-qr-trigger')
        .on('click.mdAdminUserWechatQr', '.md-user-wechat-qr-trigger', function () {
            const value = decodeURIComponent(String($(this).attr('data-wechat-code') || ''));
            if (value) openWechatQr(value, $(this).attr('data-user-id'));
        })
        .off('pjax:beforeReplace.mdAdminUserQr')
        .one('pjax:beforeReplace.mdAdminUserQr', () => {
            $(document).off('click.mdAdminUserWechatQr', '.md-user-wechat-qr-trigger');
        });

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
                        {
                            title: "用户名", name: "username", type: "input", placeholder: "请输入用户名",
                            inputmode: 'text', enterkeyhint: 'next',
                            complete: inputMeta('username', {autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false'})
                        },
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
                        {
                            title: "邮箱", name: "email", type: "input", placeholder: "请输入邮箱",
                            inputmode: 'email', enterkeyhint: 'next',
                            complete: inputMeta('email', {inputmode: 'email', autocomplete: 'email', autocapitalize: 'none', spellcheck: 'false'})
                        },
                        {
                            title: "手机", name: "phone", type: "input", placeholder: "请输入手机号",
                            inputmode: 'tel', enterkeyhint: 'next',
                            complete: inputMeta('phone', {inputmode: 'tel', autocomplete: 'tel'})
                        },
                        {
                            title: "QQ", name: "qq", type: "input", placeholder: "请输入QQ号",
                            inputmode: 'numeric', enterkeyhint: 'next',
                            complete: inputMeta('qq', {inputmode: 'numeric', autocomplete: 'off'})
                        },
                        {
                            title: "登录密码", name: "password", type: "password", placeholder: "不修改请留空",
                            enterkeyhint: 'next',
                            complete: inputMeta('password', {autocomplete: 'new-password', autocapitalize: 'none', spellcheck: 'false'})
                        },
                        {
                            title: "上级ID", name: "pid", type: "input", placeholder: "请输入上级ID",
                            inputmode: 'numeric', enterkeyhint: 'done',
                            complete: inputMeta('pid', {inputmode: 'numeric', autocomplete: 'off'})
                        },
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
        {field: 'id', title: 'ID', width: 80, visible: false}
        , {field: 'avatar', title: '用户名', formatter: (_, __) => mdUserCell(__)}
        , {field: 'group', title: '会员等级', formatter: _ => format.group(_)}
        , {field: 'email', title: '邮箱'}
        , {field: 'phone', title: '手机号'}
        , {field: 'qq', title: 'QQ'}
        , {field: 'balance', title: '余额', formatter: _ => format.money(_, "green"), sort: true}
        , {field: 'recharge', title: '元气', sort: true}
        , {field: 'coin', title: '硬币', formatter: _ => format.money(_, "#447cf3"), sort: true}
        , {
            field: 'business_level', title: '商户信息', formatter: (_, row) => {
                if (!_) return '-';
                if (mobileAdminEnabled()) return format.group(_);
                businessRows.set(String(row.id), row);
                return `${escapeHtml(_.name)} <a class="text-primary md-user-business-detail-trigger" data-user-id="${Number(row.id) || 0}" href="javascript:void(0);">详细</a>`;
            }
        }
        , {field: 'parent', title: '上级', formatter: (_, __) => mdUserCell(_)}
        , {field: 'status', title: '状态', dict: "_user_status"}
        , {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-envelope-open-dollar text-success',
                    tips: "余额操作",
                    click: (event, value, row, index) => {
                        component.popup({
                            submit: mobileAccountSubmit('/admin/api/user/recharge', row, {label: '余额', field: 'balance', totalLabel: '元气累计'}),
                            submitRoute: '/admin/api/user/recharge',
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
                                        {
                                            title: "金额", name: "amount", type: "input", placeholder: "请输入金额",
                                            inputmode: 'decimal', enterkeyhint: 'next',
                                            complete: inputMeta('amount', {inputmode: 'decimal', autocomplete: 'off'})
                                        },
                                        {
                                            title: "原因", name: "log", type: "input", placeholder: "请输入原因",
                                            inputmode: 'text', enterkeyhint: 'done',
                                            complete: inputMeta('log', {autocomplete: 'off', maxlength: '64'})
                                        },
                                        {title: "元气累计", name: "total", type: "switch", text: "是", default: 1},
                                    ]
                                }
                            ],
                            assign: {id: row.id},
                            autoPosition: true,
                            height: "auto",
                            width: "520px",
                            maxmin: false,
                            confirmText: '核对并提交',
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
                            submit: mobileAccountSubmit('/admin/api/user/coin', row, {label: '硬币', field: 'coin', totalLabel: '硬币累计'}),
                            submitRoute: '/admin/api/user/coin',
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
                                        {
                                            title: "金额", name: "amount", type: "input", placeholder: "请输入硬币数量",
                                            inputmode: 'decimal', enterkeyhint: 'next',
                                            complete: inputMeta('amount', {inputmode: 'decimal', autocomplete: 'off'})
                                        },
                                        {
                                            title: "原因", name: "log", type: "input", placeholder: "请输入原因",
                                            inputmode: 'text', enterkeyhint: 'done',
                                            complete: inputMeta('log', {autocomplete: 'off', maxlength: '64'})
                                        },
                                        {title: "硬币累计", name: "total", type: "switch", text: "是", default: 1}
                                    ]
                                }
                            ],
                            assign: {id: row.id},
                            autoPosition: true,
                            height: "auto",
                            width: "520px",
                            maxmin: false,
                            confirmText: '核对并提交',
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
                        message.ask(
                            `禁用后，会员“${escapeHtml(row.username || '未命名会员')}”（ID ${escapeHtml(row.id)}）将无法正常使用账户。确认禁用吗？`,
                            () => util.post('/admin/api/user/save', {id: row.id, status: 0}, () => {
                                message.info("已禁用");
                                table.refresh();
                            }),
                            '确认禁用会员',
                            '确认禁用'
                        );
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    tips: '删除此用户',
                    click: (event, value, row, index) => {

                        message.ask(`将永久删除会员“${escapeHtml(row.username || '未命名会员')}”（ID ${escapeHtml(row.id)}）及其关联数据，无法恢复。确认删除吗？`, () => {
                            util.post("/admin/api/user/del", {list: [row.id]}, () => {
                                message.success("删除成功");
                                table.refresh();
                            })
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-store text-primary',
                    title: '查看商户',
                    tips: '查看商户',
                    show: row => mobileAdminEnabled() && Boolean(row?.business_level && row?.business),
                    click: (event, value, row) => openBusinessDetail(row)
                }
            ]
        },

    ]);


    // 用户名列（头像单元格）双击 → MUI 详情弹窗；hover 提示「双击查看详细信息」
    table.setColumnDetail({
        column: 'avatar',
        trigger: 'dblclick',
        title: '会员详细信息',
        fields: [
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
                const attributes = `data-user-id="${Number(item.id) || 0}" data-wechat-code="${escapeHtml(encodeURIComponent(String(val)))}"`;
                if (!mobileAdminEnabled()) return `<a href="javascript:void(0);" class="text-primary md-user-wechat-qr-trigger" ${attributes}>查看</a>`;
                return `<button type="button" class="btn btn-sm btn-light-primary md-user-wechat-qr-trigger" ${attributes}>查看</button>`;
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
        ]
    });


    table.setSearch([
        {title: "用户名", name: "search-username", type: "input", inputmode: 'search', enterkeyhint: 'search'},
        {title: "会员等级", name: "equal-group_id", type: "select", dict: "user_group,id,name"},
        {title: "邮箱", name: "equal-email", type: "input", inputmode: 'email', enterkeyhint: 'search'},
        {title: "手机号", name: "equal-phone", type: "input", inputmode: 'tel', enterkeyhint: 'search'},
        {title: "QQ号", name: "equal-qq", type: "input", inputmode: 'numeric', enterkeyhint: 'search'},
        {title: "IP地址", name: "equal-login_ip", type: "input", inputmode: 'text', enterkeyhint: 'search'},
        {title: "上级ID", name: "equal-pid", type: "remoteSelect", dict: "user,id,username"}
    ]);
    table.setState("status", "_user_status");

    table.render();

    $('.handle').off('.mdAdminUserToolbar').on('click.mdAdminUserToolbar', () => {
        let selections = table.getSelectionIds();
        if (selections.length == 0) {
            message.error("请至少勾选1个会员进行操作！");
            return;
        }

        let join = selections.join(",");

        component.popup({
            submitRoute: '/admin/api/user/fastUpdateUserGroup',
            submit: (data, popupIndex) => {
                if (groupUpdatePending || !controllerActive) return;
                const groupId = Number(data.group_id || 0);
                if (!Number.isInteger(groupId) || groupId < 1) {
                    message.warning('请选择会员等级');
                    return;
                }
                message.ask(
                    `将修改已选中的 ${selections.length} 名会员等级，并立即影响对应等级规则。确认继续吗？`,
                    () => {
                        if (groupUpdatePending || !controllerActive) return;
                        groupUpdatePending = true;
                        util.post({
                            url: '/admin/api/user/fastUpdateUserGroup',
                            data: data,
                            done: response => {
                                groupUpdatePending = false;
                                if (!controllerActive) return;
                                layer.close(popupIndex);
                                message.success(response?.msg || '会员等级已更新');
                                table.refresh();
                            },
                            error: response => {
                                groupUpdatePending = false;
                                if (controllerActive) message.error(response?.msg || '会员等级更新失败');
                            },
                            fail: () => {
                                groupUpdatePending = false;
                                if (controllerActive) message.error('网络异常，会员等级未修改');
                            }
                        });
                    },
                    '确认批量修改会员等级',
                    '确认修改'
                );
            },
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

    $('.btn-app-del').off('.mdAdminUserToolbar').on('click.mdAdminUserToolbar', () => {
        let selections = table.getSelectionIds();
        if (selections.length == 0) {
            message.error("请至少勾选1个会员进行操作！");
            return;
        }

        message.ask(`将永久删除已选中的 ${selections.length} 名会员及其关联数据，无法恢复。确认删除吗？`, function () {
            util.post("/admin/api/user/del", {list: selections}, res => {
                message.success("全部删除完毕");
                table.refresh();
            });
        });
    });
}();
