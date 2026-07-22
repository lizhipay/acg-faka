/**
 * 后台谷歌验证器（TOTP）：弹窗 + 二维码 绑定 / 解绑。
 * 复用后台通用组件：component.popup（弹窗+表单）、util.post、message、layer、$.fn.qrcode（Footer 已全局加载）。
 */
!function () {
    const namespace = '.mdGoogle2faController';
    let controllerActive = true;
    let secretLoading = false;
    let bindSubmitting = false;
    let unbindSubmitting = false;

    if (typeof window.__mdGoogle2faDestroy === 'function') window.__mdGoogle2faDestroy();

    function reauthenticateIfNeeded(response) {
        if (!response?.data?.reauthenticate) return false;
        window.setTimeout(() => { window.location.href = '/admin/authentication/login'; }, 500);
        return true;
    }

    function prepareCodeInput(unique) {
        $('.' + unique + ' input[name="code"]').attr({
            inputmode: 'numeric',
            autocomplete: 'one-time-code',
            maxlength: '6',
            pattern: '[0-9]*'
        });
    }

    function copySecret(secret) {
        const copied = () => { if (controllerActive) message.success('密钥已复制'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(secret).then(copied, () => copySecretFallback(secret, copied));
            return;
        }
        copySecretFallback(secret, copied);
    }

    function copySecretFallback(secret, done) {
        const input = document.createElement('textarea');
        input.value = secret;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        try {
            if (document.execCommand('copy')) done();
            else if (controllerActive) layer.msg('复制失败，请长按密钥手动复制');
        } catch (error) {
            if (controllerActive) layer.msg('复制失败，请长按密钥手动复制');
        }
        input.remove();
    }

    function refresh() {
        if (!controllerActive) return;
        $('#g2fa-status').removeClass('text-danger').addClass('text-muted').text('正在读取绑定状态…');
        $('#g2fa-bind-btn, #g2fa-unbind-btn, #g2fa-retry-btn').hide();
        util.post({
            url: "/admin/api/manage/googleStatus",
            data: {},
            loader: false,
            done: function (res) {
                if (!controllerActive) return;
                var bound = Boolean(res && res.data && res.data.bound);
                $('#g2fa-status').removeClass('text-danger text-muted').text(bound ? '已绑定' : '未绑定');
                //已绑定→只显示解绑；未绑定→只显示绑定（要更换必须先解绑再重新绑定）
                $('#g2fa-bind-btn').css('display', bound ? 'none' : '');
                $('#g2fa-unbind-btn').css('display', bound ? '' : 'none');
            },
            error: function (res) {
                if (!controllerActive) return;
                $('#g2fa-status').removeClass('text-muted').addClass('text-danger').text(res?.msg || '绑定状态读取失败');
                $('#g2fa-retry-btn').show();
            },
            fail: function () {
                if (!controllerActive) return;
                $('#g2fa-status').removeClass('text-muted').addClass('text-danger').text('网络异常，绑定状态读取失败');
                $('#g2fa-retry-btn').show();
            }
        });
    }

    $('#g2fa-retry-btn').off(namespace).on('click' + namespace, refresh);

    // 生成密钥 → 弹窗（二维码 + 密钥 + 动态码）→ 确认绑定
    $('#g2fa-bind-btn').off(namespace).on('click' + namespace, function () {
        if (secretLoading) return;
        secretLoading = true;
        util.post("/admin/api/manage/googleSecret", {}, function (res) {
            secretLoading = false;
            if (!controllerActive || !res || !res.data) { return; }
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
                                const panel = $('<div class="md-g2fa-setup text-center"></div>');
                                panel.append('<p class="md-g2fa-setup__hint mb-2 text-muted">用 Google / Microsoft Authenticator 扫码，或手动输入密钥</p>');
                                const qr = $('<div class="md-g2fa-setup__qr d-inline-grid p-3 bg-white rounded-4" role="img" aria-label="谷歌验证器绑定二维码"></div>');
                                const secretRow = $('<div class="md-g2fa-setup__secret d-flex flex-wrap align-items-center justify-content-center gap-2 mt-3"><span>密钥</span><code></code><button type="button" class="btn btn-sm btn-light" aria-label="复制验证器密钥">复制</button></div>');
                                secretRow.find('code').text(secret);
                                secretRow.find('button').on('click' + namespace, function () { copySecret(secret); });
                                panel.append(qr, secretRow);
                                dom.empty().append(panel);
                                try {
                                    qr.qrcode({text: uri, width: 176, height: 176});
                                } catch (error) {
                                    qr.attr('aria-label', '二维码生成失败，请使用下方密钥手动绑定').append('<span class="text-danger">二维码生成失败，请手动输入密钥</span>');
                                }
                            }
                        },
                        {
                            title: "动态码",
                            name: "code",
                            type: "input",
                            placeholder: "输入 App 上的 6 位动态码",
                            required: true,
                            regex: {value: '^\\d{6}$', message: '请输入 6 位数字动态码'}
                        }
                    ]
                }],
                renderComplete: prepareCodeInput,
                submit: function (data, index) {
                    if (bindSubmitting) {
                        layer.msg('正在绑定，请稍候');
                        return;
                    }
                    bindSubmitting = true;
                    util.post("/admin/api/manage/googleBind", {
                        secret: secret,
                        code: (data.code || '').trim()
                    }, function (r) {
                        bindSubmitting = false;
                        if (!controllerActive) return;
                        message.success(r.msg || "绑定成功");
                        layer.close(index);
                        if (reauthenticateIfNeeded(r)) return;
                        refresh();
                    }, function (r) {
                        bindSubmitting = false;
                        if (controllerActive) message.error(r?.msg || '绑定失败');
                    }, function () {
                        bindSubmitting = false;
                        if (controllerActive) message.error('网络异常，绑定失败');
                    });
                }
            });
        }, function (res) {
            secretLoading = false;
            if (controllerActive) message.error(res?.msg || '生成绑定密钥失败');
        }, function () {
            secretLoading = false;
            if (controllerActive) message.error('网络异常，生成绑定密钥失败');
        });
    });

    // 解绑（弹窗输入当前动态码）
    $('#g2fa-unbind-btn').off(namespace).on('click' + namespace, function () {
        component.popup({
            title: util.icon("fa-duotone fa-regular fa-link-slash me-1") + "解绑谷歌验证器",
            width: "420px",
            height: "auto",
            autoPosition: true,
            danger: true,
            confirmText: util.icon("fa-duotone fa-regular fa-trash-can me-1 text-danger") + "确认解绑",
            tab: [{
                name: "解绑谷歌验证器",
                form: [
                    { title: "动态码", name: "code", type: "input", placeholder: "输入当前 6 位动态码", required: true, regex: {value: '^\\d{6}$', message: '请输入 6 位数字动态码'} }
                ]
            }],
            renderComplete: prepareCodeInput,
            submit: function (data, index) {
                if (unbindSubmitting) {
                    layer.msg('正在解绑，请稍候');
                    return;
                }
                unbindSubmitting = true;
                util.post("/admin/api/manage/googleUnbind", { code: (data.code || '').trim() }, function (r) {
                    unbindSubmitting = false;
                    if (!controllerActive) return;
                    message.success(r.msg || "已解绑");
                    layer.close(index);
                    if (reauthenticateIfNeeded(r)) return;
                    refresh();
                }, function (r) {
                    unbindSubmitting = false;
                    if (controllerActive) message.error(r?.msg || '解绑失败');
                }, function () {
                    unbindSubmitting = false;
                    if (controllerActive) message.error('网络异常，解绑失败');
                });
            }
        });
    });

    refresh();

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        secretLoading = false;
        bindSubmitting = false;
        unbindSubmitting = false;
        $('#g2fa-bind-btn, #g2fa-unbind-btn, #g2fa-retry-btn').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (window.__mdGoogle2faDestroy === destroy) delete window.__mdGoogle2faDestroy;
    }

    window.__mdGoogle2faDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
