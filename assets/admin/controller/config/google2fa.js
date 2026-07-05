/**
 * 后台谷歌验证器（TOTP）：弹窗 + 二维码 绑定 / 解绑。
 * 复用后台通用组件：component.popup（弹窗+表单）、util.post、message、layer、$.fn.qrcode（Footer 已全局加载）。
 */
!function () {

    function refresh() {
        util.post("/admin/api/manage/googleStatus", {}, function (res) {
            var bound = res && res.data && res.data.bound;
            //已绑定→只显示解绑；未绑定→只显示绑定（要更换必须先解绑再重新绑定）
            $('#g2fa-bind-btn').css('display', bound ? 'none' : '');
            $('#g2fa-unbind-btn').css('display', bound ? '' : 'none');
        });
    }

    // 生成密钥 → 弹窗（二维码 + 密钥 + 动态码）→ 确认绑定
    $('#g2fa-bind-btn').click(function () {
        util.post("/admin/api/manage/googleSecret", {}, function (res) {
            if (!res || !res.data) { return; }
            var secret = res.data.secret, uri = res.data.uri;

            component.popup({
                title: util.icon("fa-duotone fa-regular fa-shield-keyhole me-1") + "绑定谷歌验证器",
                width: "460px",
                height: "auto",
                autoPosition: true,
                confirmText: util.icon("fa-duotone fa-regular fa-floppy-disk me-1 text-success") + "确认绑定",
                tab: [{
                    name: "绑定谷歌验证器",
                    form: [
                        {
                            name: "g2fa_qr",
                            type: "custom",
                            complete: function (form, dom) {
                                dom.html(
                                    '<div style="text-align:center">' +
                                    '<div class="mb-2 text-muted">用 Google / Microsoft Authenticator 扫码，或手动输入密钥</div>' +
                                    '<div id="g2fa-qr" style="display:inline-block;padding:8px;background:#fff;border-radius:8px"></div>' +
                                    '<div class="mt-2">密钥：<code style="font-size:15px;color:#d63384">' + secret + '</code></div>' +
                                    '</div>'
                                );
                                try { dom.find('#g2fa-qr').empty().qrcode({ text: uri, width: 176, height: 176 }); } catch (e) {}
                            }
                        },
                        {
                            title: "动态码",
                            name: "code",
                            type: "input",
                            placeholder: "输入 App 上的 6 位动态码",
                            required: true
                        }
                    ]
                }],
                submit: function (data, index) {
                    util.post("/admin/api/manage/googleBind", {
                        secret: secret,
                        code: (data.code || '').trim()
                    }, function (r) {
                        message.success(r.msg || "绑定成功");
                        layer.close(index);
                        refresh();
                    });
                }
            });
        });
    });

    // 解绑（弹窗输入当前动态码）
    $('#g2fa-unbind-btn').click(function () {
        component.popup({
            title: util.icon("fa-duotone fa-regular fa-link-slash me-1") + "解绑谷歌验证器",
            width: "420px",
            height: "auto",
            autoPosition: true,
            confirmText: util.icon("fa-duotone fa-regular fa-trash-can me-1 text-danger") + "确认解绑",
            tab: [{
                name: "解绑谷歌验证器",
                form: [
                    { title: "动态码", name: "code", type: "input", placeholder: "输入当前 6 位动态码", required: true }
                ]
            }],
            submit: function (data, index) {
                util.post("/admin/api/manage/googleUnbind", { code: (data.code || '').trim() }, function (r) {
                    message.success(r.msg || "已解绑");
                    layer.close(index);
                    refresh();
                });
            }
        });
    });

    refresh();
}();
