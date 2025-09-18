!function () {
    $('.save-data').click(function () {
        util.post("/admin/api/config/email", util.arrayToObject($("#data-form").serializeArray()), res => {
            layer.msg("保存成功");
        });
    });

    $('.send-test-message').click(function () {
        layer.prompt({title: '邮箱地址', formType: 0}, function (email, index) {
            util.post("/admin/api/config/emailTest", {email: email}, res => {
                layer.msg(res.msg);
                layer.close(index);
            });
        });

    });
}();