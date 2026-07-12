!function () {
    const _config = getVar('_config'), _themes = getVar("_themes");

    function _NoticeEditor() {
        const $mount = $('.notice-editor');
        if (!$mount.length) return;
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });
        $mount.html(EditorV2.buildHtml({name: 'notice', placeholder: i18n('填写店铺公告，支持 Markdown 语法')}));
        EditorV2.register($mount.get(0), {
            name: 'notice',
            uploadUrl: '/admin/api/upload/send',
            height: 480,
            value: _config?.notice ?? ""
        });
    }


    function _UploadLogoAndBackground() {

        util.bindButtonUpload(".upload-logo", "/admin/api/upload/send?mime=image", data => {
            $('input[name=logo]').val(data.url);
            layer.msg('图标上传成功，但需要保存后才会生效');
            $('.image-input-wrapper').css({
                "background-image": `url(${data.url})`
            });
        });

        util.bindButtonUpload(".background-upload", "/admin/api/upload/send?mime=image", data => {
            layer.msg('背景图片上传成功，需要保存才会生效');
            $('input[name=background_url]').val(data.url);
        });

        util.bindButtonUpload(".background-mobile-upload", "/admin/api/upload/send?mime=image", data => {
            layer.msg('手机背景图片上传成功，需要保存才会生效');
            $('input[name=background_mobile_url]').val(data.url);
        });
    }


    function _ThemeSetting() {
        let themes = {};
        _themes.forEach(item => {
            themes[item.info.KEY] = item;
        });

        function modal(values = {}) {
            let submit = [];
            if (typeof values.submit === "object") {
                submit = [
                    {
                        name: `<i class="fa-duotone fa-regular fa-gear-code"></i> ${values.info.NAME}`,
                        form: values.submit
                    },
                ];
            } else if (typeof values.submit === "string" && values.submit.trim() != "") {
                submit = eval(values.submit) ?? [];
            }

            if (submit?.length == 0) {
                layer.msg("该模板暂时没有可设置的选项");
                return;
            }

            component.popup({
                submit: `/admin/api/plugin/setThemeConfig?id=${values?.info?.KEY}`,
                tab: submit,
                autoPosition: true,
                height: "auto",
                assign: values.setting,
                width: "660px",
                done: (res) => {
                    window.location.reload();
                }
            });
        }


        $('.theme-setting').click(function () {
            let userTheme = $('select[name=user_theme]').val();
            modal(themes[userTheme]);
        });

        $('.theme-mobile-setting').click(function () {
            let userTheme = $('select[name=user_mobile_theme]').val();
            if (userTheme == 0) {
                layer.msg("没有模板可以设置哦，请不要瞎点(>_<)");
                return;
            }
            modal(themes[userTheme]);
        });

        $('.theme-user-setting').click(function () {
            let userTheme = $('select[name=user_center_theme]').val();
            if (userTheme == 0) {
                layer.msg("没有模板可以设置哦，请不要瞎点(>_<)");
                return;
            }
            modal(themes[userTheme]);
        });

        $('.theme-user-mobile-setting').click(function () {
            let userTheme = $('select[name=user_center_mobile_theme]').val();
            if (userTheme == 0) {
                userTheme = $('select[name=user_center_theme]').val();
            }
            modal(themes[userTheme]);
        });
    }


    function _Save() {
        $('.save-data').click(function () {
            util.post("/admin/api/config/setting", util.arrayToObject($("#data-form").serializeArray()), res => {
                layer.msg(res.msg);
            });
        });
    }

    _NoticeEditor();
    _UploadLogoAndBackground();
    _ThemeSetting();
    _Save();
}();
