!function () {
    const $methods = $('.cash-wallet-btn');
    const $cashPage = $('.uc-cash');
    const hasCashPage = $cashPage.length > 0;
    let cashWallet;

    function isReady($method) {
        return Number($method.data('ready')) !== 0;
    }

    function selectedMethod() {
        return $methods.filter('.checked');
    }

    function updateMethodSummary() {
        if (!hasCashPage) {
            return;
        }

        const $selected = selectedMethod();
        const name = $selected.data('name') || $.trim($selected.text()) || '未选择';
        const instant = $selected.data('speed') === 'instant';
        $('.uc-cash-method-name').text(name);
        $('.uc-cash-method-note').text(instant
            ? '扣除手续费后即时转入站内钱包，可直接用于购买商品。'
            : '提交申请后由管理员处理，到账时间以收款渠道为准。');
    }

    function selectMethod($method, notify) {
        if (!$method.length) {
            cashWallet = undefined;
            updateMethodSummary();
            return false;
        }

        if (!isReady($method)) {
            if (notify) {
                message.error('请先在“修改个人信息”中完善对应的收款信息');
            }
            return false;
        }

        $methods.removeClass('checked');
        $method.addClass('checked');
        cashWallet = Number($method.data('id'));
        updateMethodSummary();
        return true;
    }

    const $initial = selectedMethod();
    if (!$initial.length || !isReady($initial)) {
        const $available = $methods.filter(function () {
            return isReady($(this));
        });
        selectMethod($available.eq(0), false);
    } else {
        selectMethod($initial.eq(0), false);
    }

    $methods.on('click', function () {
        selectMethod($(this), true);
    });

    let currentSummary = null;

    if (hasCashPage) {
        const coin = Number($cashPage.data('coin')) || 0;
        const minimum = Number($cashPage.data('min')) || 0;
        const fee = Number($cashPage.data('fee')) || 0;
        const $amount = $('input[name=amount]');
        const $submit = $('.payButton');
        const $status = $('.uc-cash-receipt__status');

        function formatAmount(value) {
            const number = Number(value);
            if (!Number.isFinite(number)) {
                return '0';
            }
            return number.toLocaleString('zh-CN', {maximumFractionDigits: 2});
        }

        function evaluateAmount() {
            const amount = Number($amount.val());
            const safeAmount = Number.isFinite(amount) && amount > 0 ? amount : 0;
            const net = Math.max(safeAmount - fee, 0);
            let error = '';

            if (coin <= 0) {
                error = '当前暂无可兑现硬币';
            } else if (!Number.isFinite(amount) || amount <= 0) {
                error = '请输入有效的兑现数量';
            } else if (amount < minimum) {
                error = `最低可兑现 ${formatAmount(minimum)} 硬币`;
            } else if (amount <= fee) {
                error = `兑现数量需高于 ${formatAmount(fee)} 硬币手续费`;
            } else if (amount > coin) {
                error = `最多可兑现 ${formatAmount(coin)} 硬币`;
            } else if (cashWallet === undefined) {
                error = '暂无可用的到账方式';
            }

            currentSummary = {
                amount: safeAmount,
                fee: fee,
                net: net,
                valid: error === ''
            };

            $('.uc-cash-gross').text(formatAmount(safeAmount));
            $('.uc-cash-net').text(formatAmount(net));
            $('.uc-cash-fee').text(`-${formatAmount(fee)} 元`);
            $status.toggleClass('is-error', !currentSummary.valid).toggleClass('is-ok', currentSummary.valid);
            $status.find('.material-icons-outlined').text(currentSummary.valid ? 'check_circle' : 'info');
            $('.uc-cash-status-text').text(currentSummary.valid ? '金额可用，确认后即可提交' : error);
            $submit.prop('disabled', !currentSummary.valid).attr('aria-disabled', String(!currentSummary.valid));
            $('.uc-cash-submit__label').text(currentSummary.valid ? '确认兑现' : '暂不可兑现');

            return currentSummary;
        }

        $amount.on('input', evaluateAmount);
        $('.uc-cash-all').on('click', function () {
            if (coin <= 0) {
                return;
            }
            $amount.val(coin).trigger('input');
        });

        $methods.on('click', evaluateAmount);
        updateMethodSummary();
        evaluateAmount();
    }

    $('.payButton').click(function () {
        if (cashWallet === undefined) {
            message.error('请选择可用的到账方式');
            return;
        }

        const amount = $('input[name=amount]').val();
        if (hasCashPage) {
            currentSummary = currentSummary || {valid: false};
            if (!currentSummary.valid) {
                message.error($('.uc-cash-status-text').text());
                return;
            }
        }

        const confirmText = hasCashPage
            ? `确认兑现 ${currentSummary.amount} 硬币？扣除 ${currentSummary.fee} 元手续费后，预计到账 ${currentSummary.net} 元。`
            : '确认是否兑现？';

        message.ask(confirmText, () => {
            util.post('/user/api/cash/submit', {
                type: cashWallet,
                amount: amount
            }, () => {
                message.success('兑现成功，请耐心等待到账。');
                setTimeout(() => {
                    window.location.href = '/user/cash/record';
                }, 1500);
            });
        });
    });
}();
