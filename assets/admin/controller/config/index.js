!function () {
    const _config = getVar('_config'), _themes = getVar("_themes");

    function _NoticeEditor() {
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });

        const wangEditor = window.wangEditor, uploadUrl = '/admin/api/upload/send';
        const editor = new wangEditor(`.editor-container`);
        const textarea = $(`.text-container`);
        const editorContent = $('.editor-content');
        const editorWrapper = $('.editor-wrapper');
        const htmlContainer = $('.html-container');

        editor.config.onchange = function (html) {
            textarea.val(html);
        }

        editor.config.zIndex = 0;
        editor.config.uploadFileName = 'file';
        editor.config.uploadImgServer = uploadUrl + "?mime=image";
        editor.config.uploadImgAccept = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        editor.config.uploadImgMaxLength = 1;
        editor.config.uploadImgTimeout = 60 * 1000;
        editor.config.uploadImgMaxSize = 50 * 1024 * 1024;  //50M
        editor.config.uploadImgHooks = {
            customInsert: function (insertImgFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertImgFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("图片上传失败，文件可能过大");
            },
        }
        editor.config.uploadVideoServer = uploadUrl + "?mime=video";
        editor.config.uploadVideoName = 'file'
        editor.config.uploadVideoHooks = {
            customInsert: function (insertVideoFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertVideoFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("视频上传失败，文件可能过大");
            },
        }
        editor.config.height = 480;
        editor.create();

        editor.txt.html(_config?.notice);
        textarea.val(_config?.notice);


        $('.button-switch-notice').click(function () {
            let _obj = $(this);
            let type = _obj.attr("data-type");
            if (type == 0) {
                const toolbarWidth = $(`.editor-container .w-e-toolbar`).width();
                const heightDifference = toolbarWidth > 1000 ? 40 : 80;

                _obj.attr("data-type", 1);
                _obj.html('<i class="fa-duotone fa-regular fa-pen-paintbrush me-1"></i>' + i18n("写作"));
                editorWrapper.append(`<div id="notice-tmp-html" style="margin-top:10px;width:100%;height: ${480 + heightDifference}px"></div>`);
                const editor = ace.edit(`notice-tmp-html`, {
                    theme: "ace/theme/chrome",
                    mode: "ace/mode/html"
                });
                editor.getSession().setUseWrapMode(true);
                editor.setOption("showPrintMargin", false);
                editor.setValue(textarea.val());
                editor.getSession().on('change', function (delta) {
                    const currentContent = editor.getValue();
                    textarea.val(currentContent);
                });
                editorContent.hide();
                htmlContainer.fadeIn(150);
            } else {
                _obj.attr("data-type", 0);
                _obj.html('<i class="fa-duotone fa-regular fa-code me-1"></i>HTML');
                editor.txt.html(textarea.val());
                $(`#notice-tmp-html`).remove();
                editorContent.fadeIn(150);
            }
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
            if (values.submit.length == 0) {
                layer.msg("该模板暂时没有可设置的选项");
                return;
            }

            values.setting.id = values.info.KEY;

            component.popup({
                submit: '/admin/api/plugin/setThemeConfig',
                tab: [
                    {
                        name: `<i class="fa-duotone fa-regular fa-gear-code"></i> ${values.info.NAME}`,
                        form: values.submit
                    },
                ],
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