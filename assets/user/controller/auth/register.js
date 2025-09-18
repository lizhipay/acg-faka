!function () {
    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post("/user/api/authentication/register", data, res => {
            window.location.href = "/";
            message.success(res.msg);
        });
    });


    $(`.send-phone-captcha`).click(function () {
        message.prompt({
            title: '人机验证',
            width: 420,
            html: `<img src="/user/captcha/image?action=phoneRegisterCaptcha" onclick="this.src='/user/captcha/image?action=phoneRegisterCaptcha&t=' + new Date().getTime()"  class="prompt-image-code" alt="更换验证码">`,
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
                util.post("/user/api/authentication/phoneRegisterCaptcha", {
                    captcha: res.value,
                    phone: $('input[name=phone]').val()
                }, res => {
                    util.countDown(this, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });


    $(`.send-email-code`).click(function () {
        message.prompt({
            title: '人机验证',
            width: 420,
            html: `<img src="/user/captcha/image?action=emailRegisterCaptcha" onclick="this.src='/user/captcha/image?action=emailRegisterCaptcha&t=' + new Date().getTime()"  class="prompt-image-code" alt="更换验证码">`,
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
                util.post("/user/api/authentication/emailRegisterCaptcha", {
                    captcha: res.value,
                    email: $('input[name=email]').val()
                }, res => {
                    util.countDown(this, 60);
                    message.success("验证码发送成功");
                });
            }
        });
    });
}();