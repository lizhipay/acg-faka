!function () {
    let table, _createForms = [], _createSearchs = [];
    const namespace = '.mdTradeOrderController';
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const parseWidget = value => {
        const normalize = parsed => {
            if (Array.isArray(parsed)) return parsed.filter(item => item && typeof item === 'object');
            if (!parsed || typeof parsed !== 'object') return [];
            if (Object.prototype.hasOwnProperty.call(parsed, 'cn') || Object.prototype.hasOwnProperty.call(parsed, 'value')) return [parsed];
            return Object.values(parsed).filter(item => item && typeof item === 'object');
        };
        if (value && typeof value === 'object') return normalize(value);
        try {
            const parsed = JSON.parse(String(value || '[]'));
            return normalize(parsed);
        } catch (error) {
            return [];
        }
    };
    const controllerLayers = new Set();
    let controllerActive = true;
    let deliveryConfirmationOpen = false;

    if (typeof window.__mdTradeOrderDestroy === 'function') window.__mdTradeOrderDestroy();

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
    const mobileSheetOptions = skin => {
        const mobile = mobileAdminEnabled();
        return mobile ? {
            area: ['100%', 'auto'],
            offset: 'b',
            skin: `admin-mobile-layer-popup admin-mobile-layer-popup--sheet ${skin}`,
            maxmin: false,
            resize: false,
            move: false
        } : {
            skin: skin,
            maxmin: false,
            resize: false
        };
    };

    table = new Table("/admin/api/order/data", "#order-table");

    // Deliveries with file cards: window.acgDelivery (ready.js) renders one download row per file.
    const DELIVERY_STYLE_ID = 'md-order-delivery-style';
    const hasDeliveryFiles = secret => Boolean(window.acgDelivery && typeof window.acgDelivery.hasFile === 'function' && window.acgDelivery.hasFile(secret));
    const ensureDeliveryStyle = () => {
        if (document.getElementById(DELIVERY_STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = DELIVERY_STYLE_ID;
        style.textContent = `
            .md-order-delivery{--acg-delivery-accent:var(--md-primary);--acg-delivery-on-accent:var(--md-on-primary);--acg-delivery-accent-soft:rgba(var(--md-primary-rgb),.12);
                --acg-delivery-border:var(--md-divider);--acg-delivery-surface:var(--md-hover-overlay);--acg-delivery-radius:var(--md-radius-lg);
                --acg-delivery-success:var(--md-success);color:var(--md-on-surface)}
            .md-order-delivery__body{max-height:min(56vh,460px);overflow:auto;overscroll-behavior:contain}
            .md-order-delivery .acg-delivery__text{padding:12px 14px;border:1px solid var(--md-divider);border-radius:var(--md-radius);background:var(--md-bg);
                font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.7;user-select:text;-webkit-user-select:text}
            .md-order-delivery .acg-delivery__name{color:var(--md-on-surface)}
            .md-order-delivery .acg-delivery__sub,.md-order-delivery .acg-delivery__url{color:var(--md-on-surface-med);opacity:1}
        `;
        document.head.appendChild(style);
    };
    const renderDelivery = (secret, meta) => {
        const files = window.acgDelivery.files(secret);
        const root = window.acgDelivery.render(secret, {meta: meta});
        root.querySelectorAll('.acg-delivery__file').forEach((row, index) => {
            const link = row.querySelector('.acg-delivery__download');
            if (!link) return;
            // Admin PJAX takes over every a[target!=_blank]; a download must stay a plain navigation.
            link.target = '_blank';
            const info = meta && files[index] ? meta[files[index].token] : null;
            if (info && Number(info.id) > 0) {
                // Admin copy of the file, so previewing does not count as the buyer's download.
                link.href = `/admin/api/cardFile/download?id=${Number(info.id)}`;
                link.rel = 'noopener';
            }
        });
        return root;
    };

    // Refund to the member's balance (#1145): owner only, the API is guarded by the Owner interceptor too.
    const isOwner = typeof getVar === 'function' && getVar('MANAGE_OWNER') === true;
    const REFUND_STYLE_ID = 'md-order-refund-style';
    const ensureRefundStyle = () => {
        if (document.getElementById(REFUND_STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = REFUND_STYLE_ID;
        style.textContent = `
            .md-refund{padding:14px 20px 18px;color:var(--md-on-surface);font-size:13px;line-height:1.6}
            .md-refund__meta{display:flex;flex-wrap:wrap;gap:4px 14px;color:var(--md-on-surface-med);font-size:12px;margin-bottom:12px}
            .md-refund__meta b{color:var(--md-on-surface);font-weight:600;font-variant-numeric:tabular-nums}
            .md-refund__hero{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:14px 16px;border-radius:var(--md-radius-lg);
                background:rgba(var(--md-primary-rgb),.08);margin-bottom:12px}
            .md-refund__hero-label{color:var(--md-on-surface-med);font-size:12px}
            .md-refund__hero-amount{font-size:24px;font-weight:700;color:var(--md-primary);font-variant-numeric:tabular-nums;letter-spacing:-.01em}
            .md-refund__seg{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:3px;border-radius:calc(var(--md-radius) + 3px);background:var(--md-hover-overlay);margin-bottom:6px}
            .md-refund__seg label{position:relative;margin:0;padding:8px 10px;border-radius:var(--md-radius);text-align:center;cursor:pointer;font-weight:600;
                color:var(--md-on-surface-med);transition:background .15s ease,color .15s ease,box-shadow .15s ease}
            .md-refund__seg input{position:absolute;opacity:0;pointer-events:none}
            .md-refund__seg label.is-on{background:var(--md-surface);color:var(--md-primary);box-shadow:0 1px 3px rgba(0,0,0,.12)}
            .md-refund__seg label:has(input:focus-visible){outline:2px solid var(--md-primary);outline-offset:1px}
            .md-refund__seg small{display:block;font-weight:500;font-size:11px;opacity:.75;font-variant-numeric:tabular-nums}
            .md-refund__hint{color:var(--md-on-surface-med);font-size:12px;margin:0 0 12px}
            .md-refund__title{font-size:12px;font-weight:600;color:var(--md-on-surface-med);margin:14px 0 6px;letter-spacing:.02em}
            .md-refund__list{list-style:none;margin:0;padding:0;border:1px solid var(--md-divider);border-radius:var(--md-radius-lg);overflow:hidden}
            .md-refund__item{display:flex;gap:10px;align-items:flex-start;padding:10px 12px}
            .md-refund__item + .md-refund__item{border-top:1px solid var(--md-divider)}
            .md-refund__icon{flex:none;width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:11px;margin-top:1px;
                background:rgba(var(--md-primary-rgb),.12);color:var(--md-primary)}
            .md-refund__item.is-short .md-refund__icon{background:rgba(var(--md-warning-rgb),.16);color:var(--md-warning)}
            .md-refund__body{flex:1;min-width:0}
            .md-refund__row{display:flex;justify-content:space-between;gap:10px}
            .md-refund__amount{font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}
            .md-refund__sub{color:var(--md-on-surface-med);font-size:12px}
            .md-refund__item.is-short .md-refund__sub{color:var(--md-warning)}
            .md-refund__skip{display:flex;gap:8px;align-items:flex-start;margin:8px 0 0;padding:8px 10px;border-radius:var(--md-radius);
                background:rgba(var(--md-warning-rgb),.10);font-size:12px;cursor:pointer;line-height:1.5}
            .md-refund__skip input{margin-top:3px;accent-color:var(--md-warning)}
            .md-refund__note{margin:12px 0 0;color:var(--md-on-surface-med);font-size:12px}
            .md-refund__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
            .md-refund__btn{border:0;border-radius:999px;padding:9px 18px;font-weight:600;font-size:13px;cursor:pointer;transition:filter .15s ease,opacity .15s ease}
            .md-refund__btn--ghost{background:var(--md-hover-overlay);color:var(--md-on-surface)}
            .md-refund__btn--primary{background:var(--md-error);color:#fff}
            .md-refund__btn:hover{filter:brightness(1.06)}
            .md-refund__btn:focus-visible{outline:2px solid var(--md-primary);outline-offset:2px}
            .md-refund__btn[disabled]{opacity:.45;cursor:not-allowed;filter:none}
            .admin-mobile-layer-popup .md-refund__actions{position:sticky;bottom:0;background:var(--md-surface);padding-top:10px}
            .admin-mobile-layer-popup .md-refund__btn{flex:1;padding:12px 18px}
        `;
        document.head.appendChild(style);
    };
    const refundMoney = value => format.currencySymbol() + format.amountRemoveTrailingZeros(Number(value || 0).toFixed(2));
    const openRefund = row => {
        util.post({
            url: '/admin/api/refund/preview',
            data: {order_id: row.id},
            done: res => showRefund(res.data || {})
        });
    };
    const showRefund = data => {
        ensureRefundStyle();
        const fee = parseFloat(data.pay_cost) || 0;
        const withoutFee = parseFloat(data.refund_without_fee) || 0;
        const hasChoice = fee > 0 && withoutFee > 0;
        const roleName = role => role === 'seller' ? i18n('卖家') : i18n('推广人');
        const buyer = escapeHtml(data.buyer?.username || '-');
        const items = [];
        items.push({key: 'balance'});
        if ((parseFloat(data.vigor) || 0) > 0) {
            items.push({
                html: `<div class="md-refund__row"><span>${i18n('扣回买家的元气')}</span><span class="md-refund__amount">${escapeHtml(format.amountRemoveTrailingZeros(Number(data.vigor).toFixed(2)))}</span></div>
                       <div class="md-refund__sub">${i18n('通道付款时按订单金额加过元气，退款一并扣回')}</div>`,
                icon: 'fa-bolt'
            });
        }
        (data.clawbacks || []).forEach(c => {
            const who = `${roleName(c.role)} ${escapeHtml(c.username || ('#' + c.user_id))}`;
            if (!c.exists) {
                items.push({html: `<div class="md-refund__row"><span>${who}</span><span class="md-refund__amount">-</span></div><div class="md-refund__sub">${i18n('账户已不存在，无从扣回')}</div>`, icon: 'fa-user-slash'});
                return;
            }
            items.push({
                short: !c.enough,
                role: c.role,
                html: `<div class="md-refund__row"><span>${i18n('扣回')}${who} ${i18n('的收益')}</span><span class="md-refund__amount">${i18n('硬币')} ${escapeHtml(format.amountRemoveTrailingZeros(Number(c.amount).toFixed(2)))}</span></div>
                       <div class="md-refund__sub">${c.enough
                    ? `${i18n('当前硬币')} ${escapeHtml(format.amountRemoveTrailingZeros(Number(c.coin).toFixed(2)))}`
                    : `${i18n('当前硬币只有')} ${escapeHtml(format.amountRemoveTrailingZeros(Number(c.coin).toFixed(2)))}${i18n('，不够扣回')}`}</div>
                       ${c.enough ? '' : `<label class="md-refund__skip"><input type="checkbox" data-refund-skip="${escapeHtml(c.role)}"><span>${i18n('仍然退款，这笔收益不扣回（差额由你承担）')}</span></label>`}`,
                icon: c.role === 'seller' ? 'fa-store' : 'fa-user-group'
            });
        });

        const content = `<div class="md-refund">
            <div class="md-refund__meta"><span>${i18n('订单')} <b>${escapeHtml(data.trade_no)}</b></span><span>${i18n('买家')} <b>${buyer}</b></span><span>${i18n('订单金额')} <b>${refundMoney(data.amount)}</b></span></div>
            <div class="md-refund__hero"><span class="md-refund__hero-label">${i18n('退回会员余额')}</span><span class="md-refund__hero-amount" data-refund-amount></span></div>
            ${hasChoice ? `<div class="md-refund__seg" role="radiogroup" aria-label="${escapeHtml(i18n('支付手续费'))}">
                <label class="is-on"><input type="radio" name="md-refund-fee" value="0" checked>${i18n('扣除手续费')}<small>${refundMoney(withoutFee)}</small></label>
                <label><input type="radio" name="md-refund-fee" value="1">${i18n('全额退款')}<small>${refundMoney(data.refund_full)}</small></label>
            </div>
            <p class="md-refund__hint">${i18n('其中支付手续费')} ${refundMoney(fee)}${i18n('，在用户付款时已进入支付通道，不会退回来。买家反悔一般扣除，发不出货建议全额退。')}</p>` : ''}
            <div class="md-refund__title">${i18n('退款后会同时')}</div>
            <ul class="md-refund__list">${items.map(item => `<li class="md-refund__item${item.short ? ' is-short' : ''}">
                <span class="md-refund__icon"><i class="fa-duotone fa-regular ${item.icon || 'fa-wallet'}"></i></span>
                <div class="md-refund__body">${item.key === 'balance'
                    ? `<div class="md-refund__row"><span>${i18n('退回')} ${buyer} ${i18n('的余额')}</span><span class="md-refund__amount" data-refund-amount></span></div><div class="md-refund__sub">${i18n('不计入元气')}</div>`
                    : item.html}</div></li>`).join('')}</ul>
            <p class="md-refund__note">${i18n('退款只退到会员余额，支付通道里的钱不会原路退回；退款后无法撤销。')}</p>
            <div class="md-refund__actions">
                <button type="button" class="md-refund__btn md-refund__btn--ghost" data-refund-cancel>${i18n('取消')}</button>
                <button type="button" class="md-refund__btn md-refund__btn--primary" data-refund-submit></button>
            </div>
        </div>`;

        const index = openControllerLayer({
            ...(mobileAdminEnabled() ? mobileSheetOptions('md-order-refund-layer') : {area: '480px', skin: 'md-order-refund-layer'}),
            type: 1,
            title: `${util.icon('fa-duotone fa-regular fa-money-bill-transfer')} ${i18n('退款到会员余额')}`,
            shadeClose: true,
            content: content,
            success: layero => {
                const $root = layero.find('.md-refund');
                const refundFee = () => hasChoice ? $root.find('input[name="md-refund-fee"]:checked').val() === '1' : true;
                const sync = () => {
                    const amount = refundFee() ? data.refund_full : (hasChoice ? data.refund_without_fee : data.refund_full);
                    $root.find('[data-refund-amount]').text(refundMoney(amount));
                    $root.find('.md-refund__seg label').each(function () {
                        $(this).toggleClass('is-on', $(this).find('input').prop('checked'));
                    });
                    const blocked = $root.find('[data-refund-skip]').toArray().some(box => !box.checked);
                    $root.find('[data-refund-submit]').prop('disabled', blocked).text(`${i18n('确认退款')} ${refundMoney(amount)}`);
                };
                $root.on('change', 'input', sync);
                $root.on('click', '[data-refund-cancel]', () => layer.close(index));
                $root.on('click', '[data-refund-submit]', function () {
                    const $btn = $(this).prop('disabled', true);
                    const skip = $root.find('[data-refund-skip]:checked').toArray().map(box => box.getAttribute('data-refund-skip'));
                    util.post({
                        url: '/admin/api/refund/make',
                        data: {order_id: data.id, refund_fee: refundFee() ? 1 : 0, skip: skip},
                        done: res => {
                            layer.close(index);
                            message.success(res.msg);
                            table.refresh();
                        },
                        error: res => {
                            message.error(res?.msg || i18n('退款失败'));
                            sync();
                        }
                    });
                });
                sync();
                $root.find(hasChoice ? 'input[name="md-refund-fee"]:checked' : '[data-refund-submit]').trigger('focus');
            }
        });
    };

    const mobileDeliverySubmit = order => {
        let confirming = false;
        let requesting = false;
        return (data, popupIndex) => {
            if (!controllerActive || confirming || requesting || deliveryConfirmationOpen) return;
            if (Number(order.status) !== 1) {
                message.warning('仅已支付订单可以手动发货，请刷新订单状态后重试。');
                return;
            }
            const secret = String(data.secret ?? '');
            const normalizedSecret = secret.trim();
            if (!normalizedSecret || normalizedSecret === '0') {
                message.warning('请填写有效的发货内容，不能仅为空白或“0”。');
                return;
            }
            const tradeNo = escapeHtml(order.trade_no || '-');
            const commodityName = escapeHtml(order?.commodity?.name || i18n('未命名商品'));
            const orderAmount = escapeHtml(order.amount ?? '0');
            const commodityPrice = order?.commodity?.price;
            const commodityPriceRow = commodityPrice === undefined || commodityPrice === null || commodityPrice === ''
                ? ''
                : `<div><b>${i18n('商品标价：')}</b>${format.currencySymbol()}${escapeHtml(commodityPrice)}</div>`;
            const secretLength = Array.from(secret).length;
            const overwrites = Number(order.delivery_status) === 1 || String(order.secret ?? '').trim() !== '';
            confirming = true;
            deliveryConfirmationOpen = true;
            Swal.fire({
                title: '确认手动发货',
                html: `<div style="text-align:left;line-height:1.8;">
                    <div><b>${i18n('订单号：')}</b>${tradeNo}</div>
                    <div><b>${i18n('商品：')}</b>${commodityName}</div>
                    <div><b>${i18n('支付状态：')}</b><span style="color:#15803d;font-weight:600;">${i18n('已支付')}</span></div>
                    ${commodityPriceRow}
                    <div><b>${i18n('订单金额：')}</b>${format.currencySymbol()}${orderAmount}</div>
                    <div><b>${i18n('发货内容：')}</b>${i18n('已填写')} ${secretLength} ${i18n('个字符')}</div>
                    <div style="margin-top:10px;color:#d14343;">${overwrites ? i18n('此订单已有发货记录，本次提交会覆盖现有发货内容。') : i18n('提交后订单会立即进入已发货状态，无法在本页面一键撤销。')}</div>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                cancelButtonText: i18n('返回检查'),
                confirmButtonText: i18n('确认发货')
            }).then(result => {
                confirming = false;
                deliveryConfirmationOpen = false;
                if (!(result.isConfirmed === true || result.value === true) || !controllerActive || requesting) return;
                requesting = true;
                util.post({
                    url: '/admin/api/order/save',
                    data: Object.assign({}, data, {overwrite_confirmed: overwrites ? 1 : 0}),
                    done: res => {
                        requesting = false;
                        if (!controllerActive) return;
                        layer.close(popupIndex);
                        message.alert(!res.msg || res.msg === 'success' ? i18n('订单发货信息已保存。') : res.msg, 'success');
                        table.refresh();
                    },
                    error: res => {
                        requesting = false;
                        if (controllerActive) message.alert(res?.msg || i18n('订单发货信息未能保存。'), 'error');
                    },
                    fail: () => {
                        requesting = false;
                        if (controllerActive) message.error('网络异常，发货信息未提交。');
                    }
                });
            });
        };
    };

    const modal = (title, assign = {}) => {
        component.popup({
            submit: mobileDeliverySubmit(assign),
            submitRoute: '/admin/api/order/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: false,
                            name: "secret",
                            type: "textarea",
                            placeholder: "填写要发货的信息",
                            required: true,
                            height: 300
                        }
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "580px",
            confirmText: i18n('核对并发货'),
            done: () => {
                if (controllerActive && table) table.refresh();
            }
        });
    }


    //已付款但向上游下单失败的对接订单（#1172）：先查后买、沿用同一个请求号，不会重复扣款
    const retrySharedDelivery = map => {
        const reason = String(map.shared_error ?? '').trim();
        message.ask(
            `<div style="text-align:left;line-height:1.75">${i18n('上次失败原因：')}<b>${escapeHtml(reason)}</b><br><br>${i18n('会先向上游查询这笔订单，查得到就直接取回；查不到才重新下单，并沿用同一个请求号，上游已经有这笔订单时不会重复扣款。')}</div>`,
            () => util.post('/admin/api/order/sharedRetry', {id: map.id}, res => {
                if (!controllerActive) return;
                message.success(res.msg || i18n('已取回发货内容'));
                table && table.refresh();
            }),
            i18n('重新向上游取货？'),
            i18n('重新取货')
        );
    };

    table.setColumns([
        {checkbox: true}
        , {
            field: 'trade_no',
            title: '订单号',
            formatter: (value, row) => {
                const tradeNo = String(value ?? '').trim();
                if (!tradeNo) return '-';
                //下单时间跟在订单号下面，对账当日销量时不用再一列列横着找（#795）
                const createTime = String(row?.create_time ?? '').trim();
                const time = createTime
                    ? `<span class="md-order-id__time" title="${i18n('下单时间')}">${escapeHtml(createTime)}</span>`
                    : '';
                //已付款但向上游下单失败、等待重新取货（#1172）：原因放在悬停提示里
                const sharedError = String(row?.shared_error ?? '').trim();
                const held = sharedError && Number(row?.status) === 1
                    ? `<span class="a-badge a-badge-warning" title="${escapeHtml(sharedError)}">${i18n('上游取货失败')}</span>`
                    : '';
                return `<div class="md-order-id"><span class="md-order-trade-no"><span class="md-order-trade-no__value">${escapeHtml(tradeNo)}</span><button type="button" class="md-order-trade-no__copy" aria-label="${i18n('复制订单号')}" title="${i18n('复制订单号')}">${util.icon("fa-duotone fa-regular fa-copy")}</button></span>${time}${held}</div>`;
            },
            events: {
                'click .md-order-trade-no__copy': (event, value) => {
                    event.preventDefault();
                    event.stopPropagation();
                    util.copyTextToClipboard(
                        String(value ?? '').trim(),
                        () => message.success('订单号已复制'),
                        () => message.error('订单号复制失败')
                    );
                },
                'dblclick .md-order-trade-no__copy': event => {
                    event.preventDefault();
                    event.stopPropagation();
                }
            }
        }
        , {
            //游客单（owner 为空）把联系方式顶到这一列外显：以前只埋在双击订单号的详情里，
            //管理员想按联系方式对单得一条条点开，用户填错空格/字母时搜索也搜不到。见 issue #814。
            //只对游客显示——登录下单的 contact 是系统生成的随机串，摆出来没意义。
            field: 'owner', title: '客户', formatter: (_, __) => _ ? mdUserCell(_) : mdGuestCell(__ && __.contact)
        }
        , {
            field: 'commodity', title: '商品', formatter: (_, __) => {
                const c = _ || {};
                const cover = c.cover
                    ? `<img src="${c.cover}" class="md-commodity-cell__cover" alt="">`
                    : `<span class="md-commodity-cell__cover md-commodity-cell__cover--ph"><i class="fa-duotone fa-regular fa-image"></i></span>`;
                const ownerObj = __.user || __.substation_user;
                const owner = (ownerObj && ownerObj.username) ? ownerObj.username : i18n('主站');
                return `<div class="md-commodity-cell md-commodity-cell--sm">${cover}<div class="md-commodity-cell__text"><span class="md-commodity-cell__name">${c.name || ''}</span><span class="md-commodity-cell__sub">${owner}</span></div></div>`;
            }
        }
        , {
            field: 'sku', title: '类别/SKU', formatter: (_, __) => {
                const race = (__.race && __.race !== '-') ? __.race : '';
                const hasSku = !util.isEmptyOrNotJson(__.sku);
                if (!race && !hasSku) return '-';
                let rows = `<div class="md-pair__row"><span class="md-pair__k">${i18n('类别')}</span><span class="md-pair__v">${escapeHtml(i18n(race)) || '-'}</span></div>`;
                if (hasSku) {
                    let badges = '';
                    for (const x in __.sku) badges += format.badge(`${escapeHtml(i18n(x))}: ${escapeHtml(i18n(__.sku[x]))}`, "a-badge-info");
                    rows += `<div class="md-pair__row"><span class="md-pair__k">SKU</span><span class="md-pair__v">${format.badgeGroup(badges)}</span></div>`;
                }
                return `<div class="md-pair">${rows}</div>`;
            }
        }
        , {
            field: 'card_num', title: '数量/金额', formatter: (_, __) => {
                const amt = parseFloat(__.amount) || 0;
                //订单金额已含支付通道手续费，单独列一行提示，避免和商品定价混淆（issue #903）
                const fee = parseFloat(__.pay_cost) || 0;
                const amountHtml = amt > 0
                    ? `<span class="md-pair__v" style="color:var(--md-success);font-weight:600">${format.currencySymbol()}${format.amountRemoveTrailingZeros(amt)}</span>`
                    : `<span class="md-pair__v md-pair__v--muted">${format.currencySymbol()}0</span>`;
                const feeRow = fee > 0
                    ? `<div class="md-pair__row"><span class="md-pair__k">${i18n('含手续费')}</span><span class="md-pair__v md-pair__v--muted">${format.currencySymbol()}${format.amountRemoveTrailingZeros(fee)}</span></div>`
                    : '';
                //已退款到会员余额（#1145）：标在金额下面，统计已不再计入这单
                const refundRow = Number(__.refund_status) === 1
                    ? `<div class="md-pair__row"><span class="md-pair__k">${i18n('已退款')}</span><span class="md-pair__v" style="color:var(--md-error);font-weight:600">${format.currencySymbol()}${format.amountRemoveTrailingZeros(parseFloat(__.refund_amount) || amt)}</span></div>`
                    : '';
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__k">${i18n('数量')}</span><span class="md-pair__v">${__.card_num ?? '-'}</span></div><div class="md-pair__row"><span class="md-pair__k">${i18n('金额')}</span>${amountHtml}</div>${feeRow}${refundRow}</div>`;
            }
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
            field: 'pay_cost', title: '手续费/佣金', formatter: (_, __) => {
                //手续费取支付通道费 pay_cost（旧 cost 列已废弃、恒为 0）。issue #903
                const fee = parseFloat(__.pay_cost) || 0;
                const rebate = parseFloat(__.rebate) || 0;
                if (fee <= 0 && rebate <= 0) return '-';
                const fmt = v => format.currencySymbol() + format.amountRemoveTrailingZeros(v);
                return `<div class="md-pair"><div class="md-pair__row"><span class="md-pair__k">${i18n('手续费')}</span><span class="md-pair__v" style="color:var(--md-info)">${fmt(fee)}</span></div><div class="md-pair__row"><span class="md-pair__k">${i18n('佣金')}</span><span class="md-pair__v md-pair__v--muted">${fmt(rebate)}</span></div></div>`;
            }
        }
        , {
            field: 'rent', title: '消耗成本'
        }
        , {
            field: 'promote', title: '推广人/分成', formatter: (_, __) => {
                if (!_) return '-';
                const name = _.username || '';
                const avatar = _.avatar
                    ? `<img src="${_.avatar}" class="md-user-cell__avatar" alt="">`
                    : `<span class="md-user-cell__avatar md-user-cell__avatar--ph">${(name.charAt(0) || '?').toUpperCase()}</span>`;
                const divide = parseFloat(__.divide_amount) || 0;
                const sub = divide > 0
                    ? `<span class="md-user-cell__sub" style="color:var(--md-success);font-weight:600">${i18n('分成')} ${format.currencySymbol()}${format.amountRemoveTrailingZeros(divide)}</span>`
                    : `<span class="md-user-cell__sub">${i18n('分成')} ${format.currencySymbol()}0</span>`;
                return `<div class="md-user-cell">${avatar}<div class="md-user-cell__text"><span class="md-user-cell__name">${name}</span>${sub}</div></div>`;
            }
        }
        , {
            field: 'secret', title: '卡密信息', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => _?.commodity?.delivery_way === 0 && _.delivery_status == 1,
                    click: (event, value, map, index) => {
                        const mobile = mobileAdminEnabled();
                        const secret = map.secret ?? '';
                        if (hasDeliveryFiles(secret)) {
                            ensureDeliveryStyle();
                            const meta = map.delivery_files && typeof map.delivery_files === 'object' ? map.delivery_files : null;
                            openControllerLayer({
                                ...(mobile ? mobileSheetOptions('md-order-secret-layer') : {area: '560px'}),
                                type: 1,
                                title: `${util.icon("fa-duotone fa-regular fa-eye")} ${i18n('查看卡密')}`,
                                shadeClose: true,
                                content: `<div class="md-secret md-order-delivery"><div class="md-order-delivery__body"></div><div class="md-secret__bar"><button type="button" class="md-secret__btn" data-act="copy">${util.icon("fa-duotone fa-regular fa-copy")} ${i18n('复制全部')}</button></div></div>`,
                                success: (layero) => {
                                    layero.find('.md-order-delivery__body').append(renderDelivery(secret, meta));
                                    layero.find('[data-act="copy"]').on('click', () => {
                                        util.copyTextToClipboard(secret, () => message.success('卡密已复制'));
                                    });
                                }
                            });
                            return;
                        }
                        const escaped = String(secret).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        openControllerLayer({
                            ...(mobile ? mobileSheetOptions('md-order-secret-layer') : {area: '480px'}),
                            type: 1,
                            title: `${util.icon("fa-duotone fa-regular fa-eye")} ${i18n('查看卡密')}`,
                            shadeClose: true,
                            content: `<div class="md-secret"><div class="md-secret__code">${escaped}</div><div class="md-secret__bar"><button type="button" class="md-secret__btn" data-act="copy">${util.icon("fa-duotone fa-regular fa-copy")} ${i18n('复制')}</button><button type="button" class="md-secret__btn md-secret__btn--primary" data-act="download">${util.icon("fa-duotone fa-regular fa-download")} ${i18n('下载')}</button></div></div>`,
                            success: (layero) => {
                                layero.find('[data-act="copy"]').on('click', () => {
                                    util.copyTextToClipboard(secret, () => message.success('卡密已复制'));
                                });
                                layero.find('[data-act="download"]').on('click', () => {
                                    const blob = new Blob([secret], {type: 'text/plain;charset=utf-8'});
                                    const url = URL.createObjectURL(blob);
                                    const a = document.createElement('a');
                                    a.href = url;
                                    a.download = `${i18n('卡密')}_${map.trade_no || 'export'}.txt`;
                                    document.body.appendChild(a);
                                    a.click();
                                    a.remove();
                                    URL.revokeObjectURL(url);
                                });
                            }
                        });
                    }
                },
                {
                    icon: `fa-duotone fa-regular fa-truck-ramp-box`,
                    class: "text-success",
                    title: "手动发货",
                    show: _ => _?.commodity?.delivery_way === 1 && Number(_.status) === 1,
                    click: (event, value, map, index) => {
                        modal(`${util.icon("fa-duotone fa-regular fa-truck-ramp-box")} ${i18n('发货内容')}`, map);
                    }
                },
                //Mobile recipes address buttons by index (secret:N): append new buttons, never insert
                {
                    icon: `fa-duotone fa-regular fa-rotate`,
                    class: "text-warning",
                    title: "重新向上游取货",
                    show: _ => isOwner && Number(_.status) === 1 && String(_.shared_error ?? '').trim() !== '',
                    click: (event, value, map) => retrySharedDelivery(map)
                },

            ]
        }
        , {
            field: 'widget', title: '控件', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-eye`,
                    class: "text-primary",
                    title: "查看",
                    show: _ => parseWidget(_.widget).length > 0,
                    click: (event, value, map, index) => {
                        const mobile = mobileAdminEnabled();
                        const parse = parseWidget(map.widget);
                        if (!parse.length) return;
                        const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        let rows = '';
                        for (const k in parse) {
                            rows += `<div class="md-detail__row"><span class="md-detail__label">${esc(parse[k].cn)}</span><span class="md-detail__value">${esc(parse[k].value ?? '-')}</span></div>`;
                        }
                        openControllerLayer({
                            ...(mobile ? mobileSheetOptions('md-order-widget-layer') : {area: '460px'}),
                            type: 1,
                            shadeClose: true,
                            title: `${util.icon("fa-duotone fa-regular fa-diamonds-4")} ${i18n('控件信息')}`,
                            content: mobile
                                ? `<div class="md-detail md-order-widget-detail"><div class="md-detail__body">${rows}</div></div>`
                                : `<div class="md-detail" style="padding:6px 14px 14px;"><div class="md-detail__body">${rows}</div></div>`
                        });
                    }
                }
            ]
        }
        , {
            field: 'refund', title: '退款', type: "button", buttons: [
                {
                    icon: `fa-duotone fa-regular fa-money-bill-transfer`,
                    class: "text-primary",
                    title: "退款",
                    //已支付、未退款、有会员账户（游客单没有余额可退）
                    show: _ => isOwner && Number(_.status) === 1 && Number(_.refund_status) !== 1
                        && Number((_.owner && typeof _.owner === 'object') ? _.owner.id : _.owner) > 0,
                    click: (event, value, map) => openRefund(map)
                },
                {
                    icon: `fa-duotone fa-regular fa-circle-check`,
                    class: "text-success",
                    title: "已退款",
                    show: _ => Number(_.refund_status) === 1,
                    click: (event, value, map) => message.info(`${i18n('已退款')} ${refundMoney(parseFloat(map.refund_amount) || map.amount)}${map.refund_time ? ' · ' + escapeHtml(map.refund_time) : ''}`)
                }
            ]
        }
    ]);

    // 双击订单号 → MUI 详情弹窗（取代原 hover 黑色浮层 setFloatMessage）
    table.setColumnDetail({
        column: 'trade_no',
        trigger: 'dblclick',
        header: false,
        title: (row) => row.trade_no,
        fields: [
        {field: 'contact', title: '联系方式'},
        {field: 'password', title: '查询密码'},
        {field: 'create_time', title: '下单时间'},
        {field: 'pay_time', title: '支付时间'},
        {field: 'pay_cost', title: '支付手续费', formatter: v => (parseFloat(v) || 0) > 0 ? format.currencySymbol() + format.amountRemoveTrailingZeros(v) : '-'},
        {field: 'create_ip', title: '客户IP'},
        {field: 'create_device', title: '设备', dict: "_common_device"},
        {field: 'card.secret', title: '预选卡密'},
        {field: 'coupon.code', title: '优惠券'}
        ]
    });

    table.setSearch([
        {title: "订单号", name: "equal-trade_no", type: "input"},
        {title: "商品ID", name: "equal-commodity_id", type: "input"},
        {title: "卡密信息(模糊)", name: "search-secret", type: "input"},
        {title: "联系方式", name: "equal-contact", type: "input"},
        {title: "支付状态", name: "equal-status", type: "select", dict: "_order_status"},
        {title: "发货状态", name: "equal-delivery_status", type: "select", dict: "_order_delivery_status"},
        {title: "退款状态", name: "equal-refund_status", type: "select", dict: [{id: 0, name: "未退款"}, {id: 1, name: "已退款"}]},
        {title: "支付方式", name: "equal-pay_id", type: "select", dict: "pay,id,name"},
        {
            title: "下单设备",
            name: "equal-create_device",
            type: "select",
            dict: "_common_device",
        },
        {title: "IP地址", name: "equal-create_ip", type: "input"},
        {title: "会员ID，0=访客", name: "equal-owner", type: "input"},
        {title: "商户ID，0=系统", name: "equal-user_id", type: "input"},
        {title: "下单时间", name: "between-create_time", type: "date"},
    ]);
    table.setState("status", "_order_status");

    table.onResponse(res => {
        $('.order_count').html(Number(res.data.total || 0).toLocaleString('en-US'));
        $('.order_amount').html(format.currencySymbol() + Number(res.data.order_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('.order_cost').html(format.currencySymbol() + Number(res.data.order_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    });

    table.render();

    $('.clear').off(namespace).on('click' + namespace, () => {
        util.post({
            url: "/admin/api/order/clear",
            done: res => {
                if (!controllerActive) return;
                message.success(res.msg);
                table.refresh();
            }
        });
    });


    const orderExportFormBody = payload => {
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
    const postOrderExportRequest = async (url, payload) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: orderExportFormBody(payload)
        });
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            const json = await response.json();
            if (!response.ok || json.code !== 200) throw new Error(json.msg || i18n('请求失败'));
            return {json: json};
        }
        if (!response.ok) throw new Error(i18n('服务器无法完成订单导出'));
        if (!contentType.includes('text/csv') && !contentType.includes('application/octet-stream')) {
            throw new Error(i18n('服务器返回的订单导出文件格式不正确'));
        }
        return {blob: await response.blob()};
    };
    const downloadOrderExport = async (payload, impact) => {
        Loading.show();
        try {
            const result = await postOrderExportRequest('/admin/api/order/export', payload);
            if (!result.blob) throw new Error(i18n('服务器没有返回订单导出文件'));
            if (!controllerActive) return;
            const count = Number(impact.count || 0);
            const url = URL.createObjectURL(result.blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${i18n('订单导出')}-${count}-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            message.success(Number(impact.export_status) === 1
                ? `${i18n('已导出并永久删除')} ${count} ${i18n('笔订单')}`
                : `${i18n('已安全导出')} ${count} ${i18n('笔订单')}`);
        } catch (error) {
            if (controllerActive) {
                message.alert(
                    error.message || i18n('订单导出请求失败；若选择了永久删除，请刷新订单列表确认结果'),
                    'error'
                );
            }
        } finally {
            Loading.hide();
        }
    };

    $('.btn-app-export').off(namespace).on('click' + namespace, function () {
        let previewPending = false;
        let downloadPending = false;

        component.popup({
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-file-export") + i18n(" 导出订单"),
                    form: [
                        {
                            name: "custom",
                            type: "custom",
                            complete: (obj, dom) => {
                                dom.html('<div class="alert alert-warning mb-4"><b>' + i18n('订单导出') + '</b><br>' + i18n('系统会先通过 POST 精确预览当前筛选范围，再生成文件。单次最多 5000 笔；选择“永久删除”后还必须完成高危确认。') + '</div>');
                            }
                        },
                        {
                            title: "导出数量",
                            name: "export_num",
                            type: "input",
                            inputmode: "numeric",
                            enterkeyhint: "next",
                            placeholder: "0 或留空表示当前筛选范围内全部导出（最多 5000 笔）"
                        },
                        {
                            title: "导出后执行",
                            name: "export_status",
                            type: "radio",
                            dict: [
                                {id: 0, name: "不执行任何操作"},
                                {id: 1, name: "删除导出的订单（高危/物理删除）"},
                            ]
                        }
                    ]
                }
            ],
            height: "auto",
            width: "580px",
            assign: {export_num: 0, export_status: 0},
            confirmText: i18n("预览导出范围"),
            maxmin: false,
            autoPosition: true,
            submit: async (data, index) => {
                if (previewPending || downloadPending || !controllerActive) return;
                const rawExportNum = String(data.export_num ?? '').trim();
                const exportNum = rawExportNum === '' ? 0 : Number(rawExportNum);
                const exportStatus = Number(data.export_status ?? 0);
                if (!Number.isInteger(exportNum) || exportNum < 0 || exportNum > 5000) {
                    message.warning('导出数量必须是 0 到 5000 的整数');
                    return;
                }
                if (![0, 1].includes(exportStatus)) {
                    message.warning('请选择正确的导出后操作');
                    return;
                }

                const payload = Object.assign({}, table.getSearchData(), {
                    export_num: exportNum,
                    export_status: exportStatus
                });
                const state = table.getState();
                if (state.field && String(state.value ?? '') !== '') {
                    payload[`equal-${state.field}`] = state.value;
                }

                previewPending = true;
                Loading.show();
                try {
                    const preview = await postOrderExportRequest('/admin/api/order/exportImpact', payload);
                    if (!controllerActive) return;
                    const impact = preview.json?.data || {};
                    const count = Number(impact.count || 0);
                    const total = Number(impact.total || 0);
                    const previewToken = String(impact.preview_token || '');
                    if (!Number.isInteger(count) || count < 1 || !previewToken.includes('.')) {
                        throw new Error(i18n('服务器没有返回有效的订单导出范围'));
                    }

                    const scope = impact.has_filter
                        ? i18n('当前筛选条件')
                        : '<span style="color:#d32f2f;font-weight:700">' + i18n('未设置筛选条件') + '</span>';
                    const limitText = count < total
                        ? `，${i18n('按订单')} ID ${i18n('从新到旧导出其中')} <b>${count} ${i18n('笔')}</b>`
                        : `，${i18n('本次导出')} <b>${count} ${i18n('笔')}</b>`;
                    const detail = `${scope}${i18n('共命中')} ${total} ${i18n('笔')}${limitText}。<br><br>${i18n('本次范围内：已支付')} ${Number(impact.paid_count || 0)} ${i18n('笔、未支付')} ${Number(impact.unpaid_count || 0)} ${i18n('笔；已发货')} ${Number(impact.delivered_count || 0)} ${i18n('笔、未发货')} ${Number(impact.undelivered_count || 0)} ${i18n('笔。')}`;
                    const proceed = deleteConfirmation => {
                        if (downloadPending || !controllerActive) return;
                        downloadPending = true;
                        layer.close(index);
                        const exportPayload = Object.assign({}, payload, {
                            expected_count: count,
                            preview_token: previewToken
                        });
                        if (deleteConfirmation) exportPayload.delete_confirmation = deleteConfirmation;
                        downloadOrderExport(exportPayload, impact).finally(() => {
                            downloadPending = false;
                        });
                    };

                    if (exportStatus === 1) {
                        const phrase = `${i18n('确认永久删除')}${count}${i18n('笔订单')}`;
                        message.dangerPrompt(
                            `${detail}<br><br><b style="color:#d32f2f">${i18n('下载请求成功后，系统会物理删除上述')} ${count} ${i18n('笔订单及其历史记录，无法恢复。')}</b><br>${i18n('删除失败时不会生成下载文件；但服务端确认成功后即完成删除，即使浏览器未保存文件也无法撤销。')}`,
                            phrase,
                            () => proceed(phrase)
                        );
                    } else {
                        message.ask(
                            `${detail}<br><br>${i18n('本次只下载')} CSV，${i18n('不修改或删除订单。')}`,
                            () => proceed(''),
                            i18n('确认导出订单'),
                            i18n('确认下载')
                        );
                    }
                } catch (error) {
                    if (controllerActive) message.alert(error.message || i18n('无法预览订单导出范围'), 'error');
                } finally {
                    previewPending = false;
                    Loading.hide();
                }
            },
        });
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        deliveryConfirmationOpen = false;
        $('.clear, .btn-app-export').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (typeof Swal !== 'undefined') Swal.close();
        if (typeof Loading !== 'undefined') Loading.hide();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeOrderDestroy === destroy) delete window.__mdTradeOrderDestroy;
    }

    window.__mdTradeOrderDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
