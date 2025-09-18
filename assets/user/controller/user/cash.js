!function () {
    let cashWallet = 2;


    $(`.cash-wallet-btn`).click(function () {
        $('.checked').removeClass('checked');
        $(this).addClass('checked');
        cashWallet = $(this).data("id");
    });


    $('.payButton').click(function () {

        message.ask("确认是否兑现?", () => {
            util.post("/user/api/cash/submit", {
                type: cashWallet,
                amount: $('input[name=amount]').val()
            }, res => {
                message.success("兑现成功，请耐心等待到账。")
                setTimeout(() => {
                    window.location.href = "/user/cash/record";
                }, 1500);
            });
        });
    });
}();