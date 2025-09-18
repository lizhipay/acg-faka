!function () {
    $('.clipboard').click(function () {
        util.copyTextToClipboard($(this).data("text"), () => {
            message.success("已复制");
        });
    });

    $('.reset-key').click(function () {
        message.ask("是否要重置您的密钥？", () => {
            util.post('/user/api/security/resetKey', res => {
                $('.app-key').html(res.data.app_key);
            });
        });
    });
}();