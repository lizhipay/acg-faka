!function () {


    $(`.send-captcha`).click(function () {
        message.prompt({
            title: '人机验证',
            width: 420,
            html: `<img src="/user/captcha/image?action=phoneBindNew" onclick="this.src='/user/captcha/image?action=phoneBindNew&t=' + new Date().getTime()"  class="prompt-image-code" alt="更换验证码">`,
            inputAttributes: {
                onpaste: 'return false',
                oncopy: 'return false'
            },
            confirmButtonText: `继续操作`,
            inputValidator: function (value) {
                return (!value && "请输入验证码");
            }
        }).then(res => {
            if (res.isConfirmed === true) {
                util.post("/user/api/security/phoneBindNew", {
                    captcha: res.value,
                    phone: $('input[name=phone]').val()
                }, res => {
                    util.countDown(this, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });


    $('.save-data').click(function () {
        util.post("/user/api/security/phone", util.getFormData('.form-data'), res => {
            message.success("绑定成功");
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        })
    });
}();