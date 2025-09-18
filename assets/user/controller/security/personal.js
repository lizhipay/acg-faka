!function () {

    $('.wx_qrcode').qrcode({
        render: "canvas",
        width: 150,
        height: 150,
        text: getVar('_user_wechat')
    });


    util.bindButtonUpload(".avatar-input", "/user/api/upload/send?mime=image", result => {
        $('input[name=avatar]').val(result.url);
        $('.avatar-img').attr("src", result.url);
    });

    util.bindButtonUpload(".wechat-input", "/user/api/upload/send?mime=image", result => {
        $('input[name=wechat]').val(result.url);
        $('.wx_qrcode').html('<img class="wechat-img" src="' + result.url + '" style="width: 100px;cursor: pointer;"   onclick="document.getElementsByClassName(\'wechat-input\')[0].click()">');
        $('.wx_qrcode_temp').html('<img class="wechat-img" src="' + result.url + '" style="width: 100px;cursor: pointer;"   onclick="document.getElementsByClassName(\'wechat-input\')[0].click()">');
        message.success("上传完成，需要保存才会生效哦");
    });


    $('.save-data').click(function () {
        util.post("/user/api/security/personal", util.getFormData('.form-data'), () => {
            message.success("已生效");
        });
    });
}();