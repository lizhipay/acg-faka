!function () {

    util.bindButtonUpload(".upload-logo", "/admin/api/upload/send?mime=image", data => {
        layer.msg('头像上传成功，保存后生效');
        $('input[name=avatar]').val(data.url);
        $('.image-input-wrapper').css({
            "background-image": `url(${data.url})`
        });
    });


    $('.save-data').click(function () {
        util.post("/admin/api/manage/set", util.arrayToObject($("#data-form").serializeArray()), res => {
            message.success(res.msg);
        });
    });
}();