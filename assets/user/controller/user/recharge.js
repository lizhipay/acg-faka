!function () {
    let _PayId;

    function _GetPayList() {
        util.post({
            url: "/user/api/recharge/pay",
            loader: false,
            done: res => {
                res?.data?.forEach(item => {
                    $('.pay-list').append(`<a class="button-click btn-pay" data-id="${item.id}" style="line-height: 22px;color: #db66ac;"> <img src="${item.icon}" class="pay-icon"> ${item.name}</a>`);
                });


                $(`.btn-pay`).click(function () {
                    _PayId = $(this).data("id");
                    $('.checked').removeClass('checked');
                    $(this).addClass('checked');
                });
            }
        })
    }

    function _Recharge() {
        $('.payButton').click(function () {
            if (!_PayId) {
                message.error("请选择支付方式");
                return;
            }
            util.post("/user/api/recharge/trade", {
                pay_id: _PayId,
                amount: $('input[name=amount]').val()
            }, res => {
                window.location.href = res?.data?.url;
            });
        });
    }

    _GetPayList();
    _Recharge();
}();