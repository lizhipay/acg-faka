!function () {

    $('.save-data').click(function () {
        util.post("/user/api/security/password", util.getFormData('.form-data'), () => {
            message.success("修改成功");
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        })
    });
}();