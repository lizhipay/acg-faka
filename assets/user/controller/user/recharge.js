!function () {
    let _PayId;
    const $topup = $('.uc-topup');
    const isTopupPage = $topup.length > 0;
    let paymentLoaded = false;
    // 单笔限额（#1129）：只登记有限额的通道，id => 限额
    const _limits = {};

    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);

    function formatAmount(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '0';
        }
        return number.toLocaleString('zh-CN', {maximumFractionDigits: 2});
    }

    const money = v => format.currencySymbol() + formatAmount(v);
    const fill = (text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars.hasOwnProperty(k) ? vars[k] : m);

    // min/max 为 0 或缺省（旧版服务端）即不限，两头都不限的返回 null
    function payLimit(item) {
        const amount = v => Number.isFinite(+v) && +v > 0 ? +v : 0;
        const min = amount(item.min_amount), max = amount(item.max_amount);
        if (!min && !max) {
            return null;
        }
        return {name: i18n(item.name), min, max, cost: Number.isFinite(+item.cost) ? +item.cost : 0, percent: item.cost_type == 1};
    }

    // 与服务端同一口径：按含手续费的实付金额判断；超限返回 {over, amount}
    function payBreach(limit, base) {
        base = +base;
        if (!limit || !(base > 0)) {
            return null;
        }
        // 百分比手续费服务端 bcmul 截断到分，先取 6 位小数消掉浮点误差再截断
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

    function payHint(limit, breach) {
        if (breach) {
            return breach.over ? fill(i18n('超出上限 {amount}'), {amount: money(limit.max)}) : fill(i18n('低于下限 {amount}'), {amount: money(limit.min)});
        }
        if (limit.min && limit.max) {
            return fill(i18n('单笔 {min}–{max}'), {min: money(limit.min), max: money(limit.max)});
        }
        return limit.max ? fill(i18n('单笔最高 {amount}'), {amount: money(limit.max)}) : fill(i18n('单笔最低 {amount}'), {amount: money(limit.min)});
    }

    function payReason(limit, breach) {
        const vars = {name: limit.name, limit: money(breach.over ? limit.max : limit.min), amount: money(breach.amount)};
        return breach.over ? fill(i18n('{name} 单笔最高 {limit}，本次需支付 {amount}，请更换其他支付方式'), vars) : fill(i18n('{name} 单笔最低 {limit}，本次需支付 {amount}，请更换其他支付方式'), vars);
    }

    const amountNow = () => Number($('input[name=amount]').val());
    const breachOf = id => payBreach(_limits[String(id)], amountNow());

    // 状态样式与 item.js、acg.js 共用同一个 id，只认 acg-pay-* 自有类，套在哪个主题的按钮上都不改原有外观
    function payLimitStyle() {
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

    // 按当前输入金额重算；选中的通道超限时保留选中，由 updateSummary 拦下提交并说明原因
    function paintLimits() {
        $('.btn-pay.acg-pay-limited').each(function () {
            const limit = _limits[$(this).attr('data-id')];
            const breach = payBreach(limit, amountNow());
            $(this).toggleClass('acg-pay-off', !!breach)
                .attr('aria-disabled', breach ? 'true' : null)
                .attr('title', breach ? payReason(limit, breach) : null)
                .find('.acg-pay-limit').text(limit ? payHint(limit, breach) : '');
            clearIndicator(this);
        });
    }

    // Cartoon 的圆圈、MountFuji 的勾是绝对定位的 ::after，没给文字留位置；限额说明比名称长，按标记实际占位让开
    function clearIndicator(el) {
        const after = getComputedStyle(el, '::after');
        const gap = after.position === 'absolute' && after.content !== 'none' && after.content !== 'normal'
            ? parseFloat(after.right) + parseFloat(after.width) + 6 - parseFloat(getComputedStyle(el).paddingRight)
            : 0;
        $(el).find('.acg-pay-label').css('margin-right', gap > 0 ? `${gap}px` : '');
    }

    // <a> 没有 href 键盘够不着：补了 tabindex，回车/空格等同点击
    function payKey(e) {
        if ((e.key === 'Enter' || e.key === ' ') && !e.repeat) {
            e.preventDefault();
            this.click();
        }
    }

    function welfareFor(amount) {
        let matchedThreshold = -1;
        let bonus = 0;
        $('.uc-topup-bonus').each(function () {
            const threshold = Number($(this).data('threshold'));
            const currentBonus = Number($(this).data('bonus'));
            if (Number.isFinite(threshold) && amount >= threshold && threshold > matchedThreshold) {
                matchedThreshold = threshold;
                bonus = Number.isFinite(currentBonus) ? currentBonus : 0;
            }
        });
        return bonus;
    }

    function updateSummary() {
        paintLimits();
        if (!isTopupPage) {
            return true;
        }

        const amount = Number($('input[name=amount]').val());
        const safeAmount = Number.isFinite(amount) && amount > 0 ? amount : 0;
        const minimum = Number($topup.data('min')) || 0;
        const configuredMax = Number($topup.data('max')) || 0;
        const maximum = configuredMax > minimum ? configuredMax : 0;
        const gift = welfareFor(safeAmount);
        const total = safeAmount + gift;
        const breach = _PayId === undefined ? null : breachOf(_PayId);
        let error = '';
        let blocked = false;

        if (!Number.isFinite(amount) || amount <= 0) {
            error = i18n('请输入有效的充值金额');
        } else if (amount < minimum) {
            error = `${i18n('单次最低充值')} ${format.currencySymbol()}${formatAmount(minimum)}`;
        } else if (maximum > 0 && amount > maximum) {
            error = `${i18n('单次最高充值')} ${format.currencySymbol()}${formatAmount(maximum)}`;
        } else if (!paymentLoaded) {
            error = i18n('正在加载支付方式');
        } else if (_PayId === undefined) {
            error = $('.btn-pay').length ? i18n('请选择一种支付方式') : i18n('暂无可用的支付方式');
        } else if (breach) {
            error = payReason(_limits[String(_PayId)], breach);
            blocked = true;
        }

        $('.uc-topup-principal').text(formatAmount(safeAmount));
        $('.uc-topup-gift').text(`+${format.currencySymbol()}${formatAmount(gift)}`);
        $('.uc-topup-energy').text(formatAmount(total));
        $('.uc-topup-total').text(formatAmount(total));

        const valid = error === '';
        const $status = $('.uc-topup-summary__status');
        $status.toggleClass('is-waiting', !valid).toggleClass('is-ready', valid);
        $status.find('.material-icons-outlined').text(valid ? 'check_circle' : 'touch_app');
        $('.uc-topup-status-text').text(valid ? i18n('信息已确认，可以前往支付') : error);
        $('.payButton').prop('disabled', !valid).attr('aria-disabled', String(!valid));
        $('.uc-topup-submit__label').text(valid ? i18n('前往支付') : (blocked ? i18n('请更换支付方式') : (paymentLoaded ? i18n('请选择支付方式') : i18n('正在加载支付方式'))));

        return valid;
    }

    function _GetPayList() {
        util.post({
            url: '/user/api/recharge/pay',
            loader: false,
            done: res => {
                res?.data?.forEach(item => {
                    const limit = payLimit(item), name = esc(i18n(item.name));
                    limit && (_limits[String(item.id)] = limit);
                    // recharge.js 同时服务 Cartoon 与 MountFuji：注入标记结构必须保持不变；有限额的通道才把名称和限额说明包一层。
                    $('.pay-list').append(`<a class="button-click btn-pay${limit ? ' acg-pay-limited' : ''}" data-id="${esc(item.id)}" role="button" tabindex="0" aria-pressed="false" style="line-height: 22px;color: #db66ac;"> <img src="${esc(item.icon)}" class="pay-icon"> ${limit ? `<span class="acg-pay-label"><span class="acg-pay-name">${name}</span><small class="acg-pay-limit"></small></span>` : name}</a>`);
                });
                Object.keys(_limits).length > 0 && payLimitStyle();

                $('.btn-pay').on('keydown', payKey).click(function () {
                    const breach = breachOf($(this).attr('data-id'));
                    if (breach) {
                        message.error(payReason(_limits[$(this).attr('data-id')], breach));
                        return;
                    }
                    _PayId = $(this).data('id');
                    $('.btn-pay.checked').removeClass('checked').attr('aria-pressed', 'false');
                    $(this).addClass('checked').attr('aria-pressed', 'true');
                    if (isTopupPage) {
                        const $name = $(this).find('.acg-pay-name');
                        $('.uc-topup-method-name').text($.trim(($name.length ? $name : $(this)).text()));
                        updateSummary();
                    }
                });

                paymentLoaded = true;
                updateSummary();
            }
        });
    }

    // 金额快选只存在于 Cartoon；其它主题没有 .uc-topup-preset，会自动跳过。
    function _Presets() {
        const $presets = $('.uc-topup-preset');
        if (!$presets.length) {
            // 没有快选也要随输入重算单笔限额
            $('input[name=amount]').on('input', updateSummary);
            return;
        }

        const $input = $('input[name=amount]');
        const sync = () => {
            const current = String($input.val()).trim();
            $presets.each(function () {
                $(this).toggleClass('active', String($(this).data('amount')) === current);
            });
            updateSummary();
        };

        $presets.on('click', function () {
            $input.val($(this).data('amount'));
            sync();
        });
        $input.on('input', sync);
        sync();
    }

    function _Recharge() {
        $('.payButton').click(function () {
            if (isTopupPage && !updateSummary()) {
                message.error($('.uc-topup-status-text').text());
                return;
            }
            if (_PayId === undefined) {
                message.error('请选择支付方式');
                return;
            }
            const breach = breachOf(_PayId);
            if (breach) {
                message.error(payReason(_limits[String(_PayId)], breach));
                return;
            }
            util.post('/user/api/recharge/trade', {
                pay_id: _PayId,
                amount: $('input[name=amount]').val()
            }, res => {
                window.location.href = res?.data?.url;
            });
        });
    }

    _GetPayList();
    _Presets();
    _Recharge();
}();
