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

    //安全导航里「个人资料 / 修改个人信息」= 同页两个面板,拦截为即时切换(不整页刷新);
    //在其它安全页(密码/邮箱/手机)这两个链接照常跳转到本页,由下方 URL 参数决定落在哪个面板
    const $info = $('.uc-subtab a[data-ptab="info"]');
    const $edit = $('.uc-subtab a[data-ptab="edit"]');
    function showTab(which) {
        const isEdit = which === "edit";
        $('.uc-tabpanel[data-panel="security"]').toggleClass("active", !isEdit);
        $('.uc-tabpanel[data-panel="profile"]').toggleClass("active", isEdit);
        $info.toggleClass("active", !isEdit);
        $edit.toggleClass("active", isEdit);
        if (window.history && history.replaceState) {
            history.replaceState(null, "", isEdit ? "/user/security/personal?tab=edit" : "/user/security/personal");
        }
    }
    $info.on("click", function (e) { e.preventDefault(); showTab("info"); });
    $edit.on("click", function (e) { e.preventDefault(); showTab("edit"); });
    //初始:从其它页带 ?tab=edit 进来则直接落在「修改个人信息」
    if (util.getParam("tab") === "edit") {
        showTab("edit");
    }

    //账户与安全 tab:重置商户密钥
    $('.reset-key').click(function () {
        message.ask("是否要重置您的密钥？", () => {
            util.post('/user/api/security/resetKey', res => {
                $('.app-key').html(res.data.app_key);
                message.success("密钥已重置");
            });
        });
    });
}();