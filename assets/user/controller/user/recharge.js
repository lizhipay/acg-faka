!function () {
    let _PayId;
    const $topup = $('.uc-topup');
    const isTopupPage = $topup.length > 0;
    let paymentLoaded = false;

    function formatAmount(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '0';
        }
        return number.toLocaleString('zh-CN', {maximumFractionDigits: 2});
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
        let error = '';

        if (!Number.isFinite(amount) || amount <= 0) {
            error = '请输入有效的充值金额';
        } else if (amount < minimum) {
            error = `单次最低充值 ￥${formatAmount(minimum)}`;
        } else if (maximum > 0 && amount > maximum) {
            error = `单次最高充值 ￥${formatAmount(maximum)}`;
        } else if (!paymentLoaded) {
            error = '正在加载支付方式';
        } else if (_PayId === undefined) {
            error = $('.btn-pay').length ? '请选择一种支付方式' : '暂无可用的支付方式';
        }

        $('.uc-topup-principal').text(formatAmount(safeAmount));
        $('.uc-topup-gift').text(`+￥${formatAmount(gift)}`);
        $('.uc-topup-energy').text(formatAmount(total));
        $('.uc-topup-total').text(formatAmount(total));

        const valid = error === '';
        const $status = $('.uc-topup-summary__status');
        $status.toggleClass('is-waiting', !valid).toggleClass('is-ready', valid);
        $status.find('.material-icons-outlined').text(valid ? 'check_circle' : 'touch_app');
        $('.uc-topup-status-text').text(valid ? '信息已确认，可以前往支付' : error);
        $('.payButton').prop('disabled', !valid).attr('aria-disabled', String(!valid));
        $('.uc-topup-submit__label').text(valid ? '前往支付' : (paymentLoaded ? '请选择支付方式' : '正在加载支付方式'));

        return valid;
    }

    function _GetPayList() {
        util.post({
            url: '/user/api/recharge/pay',
            loader: false,
            done: res => {
                res?.data?.forEach(item => {
                    // recharge.js 同时服务 Cartoon 与 MountFuji：注入标记结构必须保持不变。
                    $('.pay-list').append(`<a class="button-click btn-pay" data-id="${item.id}" style="line-height: 22px;color: #db66ac;"> <img src="${item.icon}" class="pay-icon"> ${item.name}</a>`);
                });

                $('.btn-pay').click(function () {
                    _PayId = $(this).data('id');
                    $('.btn-pay.checked').removeClass('checked');
                    $(this).addClass('checked');
                    if (isTopupPage) {
                        $('.uc-topup-method-name').text($.trim($(this).text()));
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
