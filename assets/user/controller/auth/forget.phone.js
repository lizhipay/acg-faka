!function () {

    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post("/user/api/authentication/password", data, res => {
            setTimeout(() => {
                window.location.href = "/user/authentication/login";
            }, 1000);
            message.success(res.msg);
        });
    });


    $(`.send-phone-captcha`).click(function () {
        message.prompt({
            title: '人机验证',
            width: 420,
            html: `<img src="/user/captcha/image?action=phoneForgetCaptcha" onclick="this.src='/user/captcha/image?action=phoneForgetCaptcha&t=' + new Date().getTime()"  class="prompt-image-code" alt="更换验证码">`,
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
                util.post("/user/api/authentication/phoneForgetCaptcha", {
                    captcha: res.value,
                    phone: $('input[name=username]').val()
                }, res => {
                    util.countDown(this, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });
}();