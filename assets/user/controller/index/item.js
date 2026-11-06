!function () {
    const _item = getVar("_var_item");

    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
    let _price = 0, _available = false;
    //抢购结束后付款区被收起，切换SKU重新查库存时不能把它又打开
    let _seckillEnded = false;
    const $vstack = $(`.vstack`), $cashPay = $(`.cash-pay`);
    //单笔限额(#1129)：只登记有限额的支付方式；询价进行中(_pricing)不拦，交给服务端判断
    const _payLimits = {};
    let _pricing = false, _pricingSeq = 0;

    function _getPostData() {
        let post = util.arrayToObject($vstack.serializeArray());
        post["item_id"] = _item.id;

        if (!util.isEmptyOrNotJson(_item?.config?.category)) {
            //商品分类
            post["race"] = $(`.switch-race.is-primary`).data("sku");
        }

        //获取SKU
        if (!util.isEmptyOrNotJson(_item?.config?.sku)) {
            for (const name in _item?.config?.sku) {
                const $sku = $(`.switch-sku[data-sku="${name}"].is-primary`);
                post["sku"] = post["sku"] || {};
                post["sku"][name] = $sku.data("value");
            }
        }

        return post;
    }

    //算盘
    function _Abacus(error = null) {
        //商品默认价格
        const $price = $(`.abacus .price`);
        //远程加载价格
        $price.html(`<i class="fa-duotone fa-regular fa-spinner-third icon-spin fs-6"></i>`);
        const seq = ++_pricingSeq;
        _pricing = true;

        util.post({
            url: "/user/api/index/valuation",
            data: _getPostData(),
            done: res => {
                $price.html(`<span class="unit">${format.currencySymbol()}</span>${format.amountRemoveTrailingZeros(res.data.price)}`);
                _price = res.data.price;
                _available = true;
                seq === _pricingSeq && (_pricing = false);
                _PaintPayLimits();
            },
            loader: false,
            error: d => {
                seq === _pricingSeq && (_pricing = false);
                typeof error === "function" && error(d);
                $price.html(`<span class="unit">${format.currencySymbol()}</span>${format.amountRemoveTrailingZeros(_price)}`);
                _PaintPayLimits();
            }
        });

        _SetWholesaleMsg();
    }

    function _SnapUp() {
        if (_item.seckill_status != 1) {
            return;
        }

        //抢购逻辑
        const $snapUp = $(`.snap-up`);

        const startTime = _item.seckill_start_time;
        const endTime = _item.seckill_end_time;

        util.timer(() => {
            return new Promise(resolve => {
                const now = new Date().getTime();

                if (new Date(startTime).getTime() > now) {
                    //未开始
                    const t = format.expireTime(startTime);
                    t && $snapUp.addClass("badge-soft-info").html(`${i18n('离抢购开始还剩')}${t}`);
                    resolve(true);
                } else if (new Date(endTime).getTime() > now) {
                    //已开始
                    const t = format.expireTime(endTime);
                    t && $snapUp.removeClass("badge-soft-info").addClass("badge-soft-primary").html(`${i18n('抢购结束还剩')}${t}`);
                    resolve(true);
                } else {
                    //已结束
                    $snapUp.removeClass("badge-soft-success").addClass("badge-soft-muted").html(`${i18n('抢购已结束')}`);
                    _seckillEnded = true;
                    $cashPay.fadeOut(150);
                    resolve(false);
                }
                $snapUp.show();
            });
        }, 1000, true)
    }

    function _Coupon() {
        $(`.vstack input[name=coupon]`).change(function () {
            _Abacus(d => {
                message.error(d.msg);
                $(this).val("");
            });
        });
    }

    function _SwitchRace() {
        const $switchRace = $(`.switch-race`);

        $switchRace.click(function () {
            $switchRace.removeClass("is-primary");
            $(this).addClass("is-primary");
            _Abacus();
            _GetStock();
        });
    }

    function _SwitchSku() {
        if (!util.isEmptyOrNotJson(_item?.config?.sku)) {
            for (const name in _item?.config?.sku) {
                const $sku = $(`.switch-sku[data-sku="${name}"]`);
                $sku.click(function () {
                    $sku.removeClass("is-primary");
                    $(this).addClass("is-primary");
                    _Abacus();
                    _GetStock();
                });
            }
        }
    }

    function _ChangeNum() {
        const $input = $(`input[name=num]`);

        $input.on('change', function () {
            if (_item.minimum > 0 && $(this).val() < _item.minimum) {
                $(this).val(_item.minimum);
            }
            if (_item.maximum > 0 && $(this).val() > _item.maximum) {
                $(this).val(_item.maximum);
            }

            _Abacus();
        });

        $(`.change-num-sub`).click(function () {
            $input.val(Math.max(1, (+$input.val() || 1) - 1)).trigger('change');
        });

        $(`.change-num-add`).click(function () {
            $input.val(Math.max(1, (+$input.val() || 1) + 1)).trigger('change');
        });
    }

    function _SetWholesaleMsg() {
        const $qtyGroup = $(`.qty-group`);
        $(`.wholesale-table`).remove();
        const html = `<table class="table wholesale-table mt-1 mb-0"><thead><tr><th scope="col">${i18n('批发数量')}</th><th scope="col">${i18n('单价')}</th></tr></thead><tbody>[body]</tbody></table>`;
        if (!util.isEmptyOrNotJson(_item?.config?.category)) {
            const sku = $(`.switch-race.is-primary`).data('sku');
            //分类批发
            if (_item?.config?.category_wholesale?.hasOwnProperty(sku)) {
                let body = ``;
                for (const k in _item.config.category_wholesale[sku]) {
                    body += `<tr><td>${esc(k)}</td><td>${esc(format.currencySymbol())}${esc(_item.config.category_wholesale[sku][k])}</td></tr>`;
                }
                $qtyGroup.after(html.replace("[body]", body));
            }
            return;
        }

        if (!util.isEmptyOrNotJson(_item?.config?.wholesale)) {
            let body = ``;
            for (const k in _item.config.wholesale) {
                body += `<tr><td>${esc(k)}</td><td>${esc(format.currencySymbol())}${esc(_item.config.wholesale[k])}</td></tr>`;
            }
            $qtyGroup.after(html.replace("[body]", body));
        }
    }

    function _GetStock() {
        const $itemStock = $(`.item-stock`);
        util.post({
            url: "/user/api/index/stock",
            data: _getPostData(),
            done: res => {
                if (res.data.stock_state <= 0) {
                    $cashPay.fadeOut(150);
                    $itemStock.removeClass("badge-soft-success").addClass("badge-soft-danger").html(`${i18n('已售罄')}`);
                    return;
                }

                $itemStock.removeClass("badge-soft-danger").addClass('badge-soft-success').html(`${i18n('库存')} ${res.data.stock}`);
                //抢购结束也会把付款区收起来，那是另一码事——不能因为这个SKU有货就把它又打开，
                //否则切一下SKU就能给已经结束的秒杀下单
                if (!_seckillEnded) {
                    $cashPay.fadeIn(150);
                }
            },
            loader: false
        });
    }

    function _CaptchaRefresh() {
        const baseSrc = '/user/captcha/image?action=trade';
        $('.captcha-img').attr('src', baseSrc + '&_t=' + Date.now());
    }

    function _RegisterCaptchaRefresh() {
        $('.captcha-img').click(() => {
            _CaptchaRefresh();
        });
    }

    //单笔限额：min/max 为 0 或缺省(旧版服务端)即不限，两头都不限的返回 null
    function _PayLimit(item) {
        const amount = v => Number.isFinite(+v) && +v > 0 ? +v : 0;
        const min = amount(item.min_amount), max = amount(item.max_amount);
        if (!min && !max) {
            return null;
        }
        return {name: i18n(item.name), min, max, cost: Number.isFinite(+item.cost) ? +item.cost : 0, percent: item.cost_type == 1};
    }

    //与服务端同一口径：按含手续费的实付金额判断，免费单不受限；超限返回 {over, amount}
    function _PayBreach(limit, base) {
        base = +base;
        if (!limit || !(base > 0)) {
            return null;
        }
        //百分比手续费服务端 bcmul 截断到分，先取 6 位小数消掉浮点误差再截断
        const fee = limit.percent ? Math.trunc(Math.round(base * limit.cost * 1e6) / 1e4) / 100 : limit.cost;
        const amount = Math.round((base + fee) * 100) / 100;
        if (limit.max > 0 && amount > limit.max) {
            return {over: true, amount};
        }
        if (limit.min > 0 && amount < limit.min) {
            return {over: false, amount};
        }
        return null;
    }

    const _money = v => format.currencySymbol() + format.amountRemoveTrailingZeros(v);
    const _fill = (text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars.hasOwnProperty(k) ? vars[k] : m);

    function _PayHint(limit, breach) {
        if (breach) {
            return breach.over ? _fill(i18n('超出上限 {amount}'), {amount: _money(limit.max)}) : _fill(i18n('低于下限 {amount}'), {amount: _money(limit.min)});
        }
        if (limit.min && limit.max) {
            return _fill(i18n('单笔 {min}–{max}'), {min: _money(limit.min), max: _money(limit.max)});
        }
        return limit.max ? _fill(i18n('单笔最高 {amount}'), {amount: _money(limit.max)}) : _fill(i18n('单笔最低 {amount}'), {amount: _money(limit.min)});
    }

    function _PayReason(limit, breach) {
        const vars = {name: limit.name, limit: _money(breach.over ? limit.max : limit.min), amount: _money(breach.amount)};
        return breach.over ? _fill(i18n('{name} 单笔最高 {limit}，本次需支付 {amount}，请更换其他支付方式'), vars) : _fill(i18n('{name} 单笔最低 {limit}，本次需支付 {amount}，请更换其他支付方式'), vars);
    }

    //状态样式与 recharge.js、acg.js 共用同一个 id，只认 acg-pay-* 自有类，套在哪个主题的按钮上都不改原有外观
    function _PayLimitStyle() {
        if (document.getElementById('acg-pay-limit-style')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'acg-pay-limit-style';
        style.textContent = '.acg-pay-label{display:inline-block;min-width:0;max-width:100%;vertical-align:middle;text-align:start}' +
            '.acg-pay-name{display:block;overflow:hidden;text-overflow:ellipsis}' +
            '.acg-pay-limit{display:block;margin-top:2px;font-size:11px;font-size:max(11px,.78em);font-weight:500;line-height:1.3;letter-spacing:0;white-space:normal;opacity:.66;font-variant-numeric:tabular-nums}' +
            '.acg-pay-limited>img,.acg-pay-limited .acg-pay-name,.acg-pay-limit{transition:opacity .2s ease,filter .2s ease,color .2s ease}' +
            '.acg-pay-limited{vertical-align:middle}' +
            ':has(>.acg-pay-limited)>*{vertical-align:middle}' +
            '.acg-pay-off{cursor:not-allowed!important}' +
            '.acg-pay-off>img,.acg-pay-off .acg-pay-name{opacity:.42;filter:grayscale(1)}' +
            '.acg-pay-off .acg-pay-limit{opacity:1;color:#c8383e;color:color-mix(in srgb,#e5484d 74%,currentColor)}' +
            '.acg-pay-off::after{filter:opacity(.45)}' +
            '.acg-pay-off:hover,.acg-pay-off:active{transform:none!important}' +
            '@media (prefers-reduced-motion:reduce){.acg-pay-limited>img,.acg-pay-limited .acg-pay-name,.acg-pay-limit{transition:none}}';
        document.head.appendChild(style);
    }

    //每次询价后按当前总价重算；首次询价回来之前只显示范围、不置灰
    function _PaintPayLimits() {
        $(`.pay-list .pay.acg-pay-limited`).each(function () {
            const limit = _payLimits[$(this).attr("data-id")];
            const breach = _available ? _PayBreach(limit, _price) : null;
            $(this).toggleClass("acg-pay-off", !!breach)
                .attr("aria-disabled", breach ? "true" : null)
                .attr("title", breach ? _PayReason(limit, breach) : null)
                .find(".acg-pay-limit").text(limit ? _PayHint(limit, breach) : "");
        });
    }

    //<a> 没有 href 键盘够不着：补了 tabindex，回车/空格等同点击
    function _PayKey(e) {
        if ((e.key === "Enter" || e.key === " ") && !e.repeat) {
            e.preventDefault();
            this.click();
        }
    }

    function _SetPayList() {
        const $payList = $(`.pay-list`);
        util.post({
            url: `/user/api/index/pay?itemId=${_item.id}`,
            done: res => {
                res.data.forEach(item => {
                    const limit = _PayLimit(item), name = esc(i18n(item.name));
                    limit && (_payLimits[String(item.id)] = limit);
                    $(limit
                        ? `<a class="pay acg-pay-limited" data-id="${esc(item.id)}" role="button" tabindex="0"><img src="${esc(item.icon)}"><span class="acg-pay-label"><span class="acg-pay-name">${name}</span><small class="acg-pay-limit"></small></span></a>`
                        : `<a class="pay" data-id="${esc(item.id)}" role="button" tabindex="0"><img src="${esc(item.icon)}"><span>${name}</span></a>`
                    ).on("keydown", _PayKey).appendTo($payList);
                });
                if (Object.keys(_payLimits).length > 0) {
                    _PayLimitStyle();
                    _PaintPayLimits();
                }
            },
            loader: false
        });

        $(document).on("click", `.pay-list .pay`, function () {
            //超出单笔限额：直接说明原因、不去下单
            const limit = _payLimits[$(this).attr("data-id")];
            const breach = _available && !_pricing ? _PayBreach(limit, _price) : null;
            if (breach) {
                message.error(_PayReason(limit, breach));
                return;
            }

            let post = _getPostData();
            post["pay_id"] = $(this).data("id");
            util.post("/user/api/order/trade", post, res => {
                if (post["pay_id"] == 1) {
                    //余额购买，直接反馈
                    treasure.show(res.data.tradeNo, res.data.secret, res.data.leave_message, res.data.delivery_files);
                    return;
                }

                //0元单(如100%优惠券抵扣)没有收银台，url为空时直接反馈结果，防止跳转到"null"
                if (!res.data.url) {
                    treasure.show(res.data.tradeNo, res.data.secret, res.data.leave_message, res.data.delivery_files);
                    return;
                }

                window.location.href = res.data.url;
            }, error => {
                message.error(error.msg);
                _CaptchaRefresh();
            });
        });
    }

    function _OptionalCard() {
        const $OptionalCard = $(`.optional-card`);
        let table;

        $OptionalCard.click(() => {
            component.popup({
                submit: (data, index) => {
                    const selections = table.getSelections();
                    if (selections.length == 0) {
                        $(`input[name=card_id]`).val("");
                        $OptionalCard.html(`${i18n('未自选')},${i18n('将随机发货')}`);
                    } else {
                        const draftPremium = selections[0].draft_premium > 0 ? selections[0].draft_premium : _item.draft_premium;

                        $OptionalCard.html(`${draftPremium > 0 ? `<span class="text-primary me-1">« ${esc(format.currencySymbol())}${esc(draftPremium)} »</span> ` : ''}${esc(selections[0].draft)}`);
                        $(`input[name=card_id]`).val(selections[0].id);
                    }

                    layer.close(index);
                    _Abacus();
                },
                tab: [
                    {
                        name: `<i class="fa-duotone fa-regular fa-list-radio"></i> ${i18n('自助选号')}`,
                        form: [
                            {
                                name: "sku",
                                type: "custom",
                                complete: (popup, dom) => {
                                    const where = _getPostData();
                                    dom.html(`<div class="mcy-card"><table id="shop-selection-table"></table></div>`);
                                    table = new Table("/user/api/index/card", dom.find('#shop-selection-table'));
                                    table.setPagination(10, [10, 20, 30]);
                                    table.setColumns([
                                        {checkbox: true},
                                        {field: 'draft', title: '剧透内容'},
                                        {
                                            field: 'draft_premium', title: '溢价', formatter: _ => {
                                                if (_ == 0) {
                                                    if (_item.draft_premium > 0) {
                                                        return format.badge(format.currencySymbol() + _item.draft_premium, "a-badge-primary");
                                                    }
                                                    return '-';
                                                }
                                                return format.badge(format.currencySymbol() + _, "a-badge-primary");
                                            }
                                        },
                                    ]);

                                    for (const whereKey in where) {
                                        table.setWhere(whereKey, where[whereKey]);
                                    }

                                    table.setSearch([
                                        {
                                            title: "搜索剧透内容",
                                            name: "search-draft",
                                            type: "input",
                                            width: 300
                                        }
                                    ]);

                                    table.enableSingleSelect();
                                    table.render();
                                }
                            },
                        ]
                    },
                ],
                assign: {},
                autoPosition: true,
                confirmText: `<i class="fa-duotone fa-regular fa-badge-check"></i> ${i18n('确认选号')}`,
                width: "620px"
            });
        });
    }

    function _ShareItem() {
        $(`.shared-button`).click(() => {
            util.copyTextToClipboard(_item.share_url, () => {
                layer.msg(i18n("分享链接复制成功，快去分享给朋友吧！"))
            });
        });
    }

    _SnapUp();//抢购
    _SwitchRace();
    _SwitchSku();
    _ChangeNum();
    _SetWholesaleMsg();
    _Coupon();//优惠券
    //自动询价
    _Abacus();
    //自动更新库存
    _GetStock();
    //支付方式
    _SetPayList();
    _RegisterCaptchaRefresh();
    _OptionalCard();

    _ShareItem();
}();