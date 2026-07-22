!function () {

    const table = new Table("/admin/api/cash/data", "#cash-table");
    const controllerLayers = new Set();
    let controllerActive = true;
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
        .off('pjax:beforeReplace.mdUserCashController')
        .one('pjax:beforeReplace.mdUserCashController', () => {
            controllerActive = false;
            $('.settlement').off('.mdUserCashController');
            controllerLayers.forEach(index => layer.close(index));
            controllerLayers.clear();
            if (typeof Swal !== 'undefined') Swal.close();
        });
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const cashCardNames = ['支付宝', '微信', '余额', 'USDT (TRC20)'];
    let cashDecisionPending = false;
    let settlementPending = false;
    const detailRow = (label, value) => `<div class="md-detail__row"><span class="md-detail__label">${escapeHtml(label)}</span><span class="md-detail__value">${value || '-'}</span></div>`;
    const cashCopyButton = (key, label, value, mobile) => {
        const normalized = String(value ?? '').trim();
        if (!mobile || !normalized || normalized === '-') return '';
        return `<button type="button" class="md-cash-copy-button" data-cash-copy="${key}" aria-label="复制${escapeHtml(label)}"><span class="material-icons-outlined" aria-hidden="true">content_copy</span></button>`;
    };
    const cashCopyableValue = (html, key, label, value, mobile) => `<span class="md-cash-copy-value"><span class="md-cash-copy-value__text">${html || '-'}</span>${cashCopyButton(key, label, value, mobile)}</span>`;
    const cashAccountValue = map => {
        const user = map.user || {};
        const card = Number(map.card);
        if (card === 1) return user.wechat ? '微信收款码' : '-';
        return [user.alipay, '', '站内余额', user.wallet_address][card] || '-';
    };
    const cashAccountCopyValue = map => {
        const user = map.user || {};
        const card = Number(map.card);
        if (card === 0) return user.alipay || '';
        if (card === 1) return user.wechat || '';
        if (card === 3) return user.wallet_address || '';
        return '';
    };
    const cashAccountContent = (map, mobile = false) => {
        const user = map.user || {};
        const recipient = String(user.nicename || '').trim();
        const account = [user.alipay, '', '站内余额', user.wallet_address][Number(map.card)] || '';
        const accountCopyValue = cashAccountCopyValue(map);
        const accountHtml = Number(map.card) === 1 && user.wechat
            ? '<div class="md-cash-wechat-qr" data-cash-wechat-qr></div>'
            : escapeHtml(account || '-');
        return `<div class="md-detail md-cash-payment-detail">
            <div class="md-detail__header">${mdUserCell({avatar: escapeHtml(user.avatar || '/favicon.ico'), username: escapeHtml(user.username || '未知会员'), id: escapeHtml(user.id)})}</div>
            <div class="md-detail__body">
                ${detailRow('提现金额', `<strong class="text-success">¥ ${escapeHtml(map.amount)}</strong>`)}
                ${detailRow('收款方式', escapeHtml(cashCardNames[Number(map.card)] || '未知方式'))}
                ${detailRow('收款人', cashCopyableValue(escapeHtml(recipient || '-'), 'recipient', '收款人', recipient, mobile))}
                ${detailRow('收款账号', cashCopyableValue(accountHtml, 'account', '收款账号', accountCopyValue, mobile))}
                ${detailRow('提交时间', escapeHtml(map.create_time || '-'))}
            </div>
        </div>`;
    };

    table.setColumns([
        {
            field: 'user', title: '会员', formatter: (_, __) => mdUserCell(_)
        }
        , {
            field: 'amount', title: '提现金额', formatter: _ => format.money(_, "green")
        }
        , {
            field: 'type', title: '结算类型', dict: "_cash_type"
        }
        , {
            field: 'card', title: '收款方式', dict: "_cash_card"
        }
        , {
            field: 'cost', title: '手续费', formatter: _ => format.money(_, "red")
        }
        , {
            field: 'status', title: '状态', dict: "_cash_status"
        }
        , {
            field: 'message', title: 'MSG'
        }
        , {
            field: 'create_time', title: '提现时间'
        }
        , {
            field: 'arrive_time', title: '处理时间'
        }
        , {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-circle-check',
                    class: "text-success",
                    title: "打款",
                    show: _ => _.status === 0,
                    click: (event, value, map, index) => {
                        const mobile = mobileAdminEnabled();
                        openControllerLayer({
                            type: 1,
                            title: mobile ? '确认打款' : '<i class="fa-duotone fa-regular fa-money-bill-transfer"></i> 提现打款',
                            content: cashAccountContent(map, mobile),
                            area: mobile ? ['100%', 'auto'] : '480px',
                            offset: mobile ? 'b' : 'auto',
                            skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--sheet md-cash-payment-layer' : 'md-cash-payment-layer',
                            maxmin: false,
                            resize: false,
                            move: !mobile,
                            anim: mobile && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? -1 : (mobile ? 2 : 0),
                            btn: mobile ? ['确认已打款', '取消'] : ['<i class="fa-duotone fa-regular fa-sack-dollar"></i> 已打款', '<i class="fa-duotone fa-regular fa-xmark"></i> 取消'],
                            success: layero => {
                                if (map.card == 1 && map?.user?.wechat) {
                                    $(layero).find('[data-cash-wechat-qr]').qrcode({
                                        render: "canvas",
                                        width: 180,
                                        height: 180,
                                        text: map.user.wechat
                                    });
                                }
                                const copyValues = {
                                    recipient: String(map?.user?.nicename || '').trim(),
                                    account: String(cashAccountCopyValue(map) || '').trim()
                                };
                                $(layero).find('[data-cash-copy]').on('click', function (copyEvent) {
                                    copyEvent.preventDefault();
                                    copyEvent.stopPropagation();
                                    const key = String($(this).attr('data-cash-copy') || '');
                                    const copyValue = copyValues[key];
                                    if (!copyValue) return;
                                    const label = key === 'recipient' ? '收款人' : '收款账号';
                                    util.copyTextToClipboard(
                                        copyValue,
                                        () => message.success(`${label}已复制`),
                                        () => message.error(`复制${label}失败，请长按内容复制`)
                                    );
                                });
                            },
                            yes: layerIndex => {
                                const submitPaid = () => {
                                    if (cashDecisionPending || !controllerActive) return;
                                    cashDecisionPending = true;
                                    util.post({
                                        url: "/admin/api/cash/decide",
                                        data: {id: map.id, status: 0, message: ''},
                                        done: () => {
                                            cashDecisionPending = false;
                                            if (!controllerActive) return;
                                            table.refresh();
                                            message.success("提现申请已标记为已付款");
                                            layer.close(layerIndex);
                                        },
                                        error: res => {
                                            cashDecisionPending = false;
                                            if (controllerActive) message.error(res?.msg || '提现申请处理失败');
                                        },
                                        fail: () => {
                                            cashDecisionPending = false;
                                            if (controllerActive) message.error('网络异常，提现状态未改变');
                                        }
                                    });
                                };
                                const user = map.user || {};
                                Swal.fire({
                                    title: '确认已完成打款',
                                    html: `<div style="text-align:left;line-height:1.8;">
                                        <div><b>会员：</b>${escapeHtml(user.username || '未知会员')}（ID ${escapeHtml(user.id ?? '-')}）</div>
                                        <div><b>申请金额：</b>¥${escapeHtml(map.amount ?? '0')}</div>
                                        <div><b>收款方式：</b>${escapeHtml(cashCardNames[Number(map.card)] || '未知方式')}</div>
                                        <div><b>收款人：</b>${escapeHtml(user.nicename || '-')}</div>
                                        <div><b>收款账号：</b>${escapeHtml(cashAccountValue(map))}</div>
                                        <div style="margin-top:10px;color:#d14343;">请先在对应渠道完成真实打款。确认后只会把申请标记为已付款，无法在本页面一键撤销。</div>
                                    </div>`,
                                    icon: 'warning',
                                    showCancelButton: true,
                                    cancelButtonText: '返回核对',
                                    confirmButtonText: '确认已完成打款'
                                }).then(result => {
                                    if (result.isConfirmed === true || result.value === true) submitPaid();
                                });
                            }
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-circle-exclamation',
                    class: "text-danger",
                    title: "驳回",
                    show: _ => _.status === 0,
                    click: (event, value, map, index) => {
                        if (cashDecisionPending || !controllerActive) return;
                        message.prompt({
                            title: '<i class="fa-duotone fa-regular fa-circle-exclamation"></i> ' + i18n("驳回理由"),
                            width: mobileAdminEnabled() ? 'calc(100vw - 28px)' : 320,
                            inputAttributes: {
                                maxlength: '64',
                                autocomplete: 'off',
                                enterkeyhint: 'done'
                            },
                            confirmButtonText: i18n("确认驳回"),
                            inputValidator: function (value) {
                                const reason = String(value || '').trim();
                                if (!reason) return '请输入驳回内容';
                                if (Array.from(reason).length > 64) return '驳回理由不能超过 64 个字';
                            }
                        }).then(res => {
                            if (res.isConfirmed !== true || cashDecisionPending || !controllerActive) return;
                            cashDecisionPending = true;
                            util.post({
                                url: '/admin/api/cash/decide',
                                data: {id: map.id, status: 1, message: String(res.value || '').trim()},
                                done: () => {
                                    cashDecisionPending = false;
                                    if (!controllerActive) return;
                                    table.refresh();
                                    message.success('提现申请已驳回，款项已退回会员账户');
                                },
                                error: response => {
                                    cashDecisionPending = false;
                                    if (controllerActive) message.error(response?.msg || '提现申请驳回失败');
                                },
                                fail: () => {
                                    cashDecisionPending = false;
                                    if (controllerActive) message.error('网络异常，提现状态未改变');
                                }
                            });
                        });
                    }
                },
            ]
        }
    ]);

    table.setSearch([
        {title: "搜索会员", name: "equal-user_id", type: "remoteSelect", dict: "user,id,username"},
        {
            title: "类型", name: "equal-type", type: "select", dict: "_cash_type"
        }, {
            title: "收款方式", name: "equal-card", type: "select", dict: "_cash_card"
        }, {
            title: "状态", name: "equal-status", type: "select", dict: "_cash_status"
        },
        {title: "提交时间", name: "between-create_time", type: "date"},
    ]);

    table.setState("status", "_cash_status");

    table.render();


    $('.settlement').off('.mdUserCashController').on('click.mdUserCashController', () => {
        message.prompt({
            title: '请输入最低结算账户余额',
            input: 'number',
            inputAttributes: {min: '0.01', step: '0.01', inputmode: 'decimal'},
            confirmButtonText: '开始自动结算',
            inputValidator: value => {
                if (String(value).trim() === '' || !Number.isFinite(Number(value)) || Number(value) <= 0) return '请输入大于 0 的有效金额';
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const amount = Number(result.value);
            const submitSettlement = () => {
                if (settlementPending || !controllerActive) return;
                settlementPending = true;
                util.post({
                    url: '/admin/api/cash/settlement',
                    data: {amount: amount},
                    done: () => {
                        settlementPending = false;
                        if (!controllerActive) return;
                        table.refresh();
                        message.success('自动结算已完成');
                    },
                    error: res => {
                        settlementPending = false;
                        if (controllerActive) message.error(res?.msg || '自动结算未能完成');
                    },
                    fail: () => {
                        settlementPending = false;
                        if (controllerActive) message.error('网络异常，自动结算未提交');
                    }
                });
            };
            Swal.fire({
                title: '确认批量自动结算',
                html: `<div style="text-align:left;line-height:1.8;">
                    <div><b>最低账户余额：</b>¥${escapeHtml(amount.toFixed(2))}</div>
                    <div><b>匹配范围：</b>所有站内余额大于或等于该金额的会员</div>
                    <div style="margin-top:10px;color:#d14343;">提交后会批量创建提现记录并扣减符合条件会员的余额，可能影响多名会员，无法在本页面一键撤销。</div>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                cancelButtonText: '返回修改',
                confirmButtonText: '确认批量结算'
            }).then(confirmResult => {
                if (confirmResult.isConfirmed === true || confirmResult.value === true) submitSettlement();
            });
        });
    });
}();
