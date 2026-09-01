!function () {
    const namespace = '.mdConfigSecurityController';
    let controllerActive = true;
    let saveInFlight = false;

    function setSaveBusy(busy) {
        const $btn = $('#submit');
        $btn.prop('disabled', busy);
        $btn.attr('data-kt-indicator', busy ? 'on' : null);
    }

    function emitFormState(name, revision) {
        document.dispatchEvent(new CustomEvent(name, {detail: {form: 'security', revision: revision}}));
    }

    function formRevision() {
        return $('#data-form').serialize();
    }

    function submitSecurity() {
        if (!controllerActive || saveInFlight) return;
        const revision = formRevision();
        saveInFlight = true;
        setSaveBusy(true);
        util.post({
            url: "/admin/api/config/security",
            data: util.arrayToObject($("#data-form").serializeArray()),
            done: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                layer.msg(res.msg || i18n('保存成功'));
                emitFormState('admin:mobile:form-saved', revision);
            },
            error: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                if (window.AdminMobile?.isEnabled?.()) {
                    window.AdminMobile?.pageWorkflows?.focusFormError?.(document.getElementById('data-form'), res?.msg);
                }
                message.error(res?.msg || i18n('安全设置保存失败'));
            },
            fail: () => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                message.error(i18n('网络异常，安全设置未保存'));
            }
        });
    }

    $('#data-form').off('submit' + namespace).on('submit' + namespace, function (e) {
        e.preventDefault();

        //从「原来有入口」变成「提交时为空」= 关闭保护，这一步值得拦一道
        const entrance = document.getElementById('admin-entrance');
        if (entrance && entrance.defaultValue !== '' && entrance.value.trim() === '') {
            message.ask(
                i18n('清空后台安全入口将关闭该保护，后台地址会重新对外可见。确认继续？'),
                submitSecurity,
                i18n('危险操作')
            );
            return;
        }
        submitSecurity();
    });

    $('input[name="link_domain_filter"]').off('change' + namespace).on('change' + namespace, function () {
        if (!this.checked) {
            message.warning(i18n('已关闭外链域名过滤，提交内容将不再校验外部域名。保存后生效。'));
        }
    });

    $('#csp-clear').off('click' + namespace).on('click' + namespace, function () {
        message.ask(i18n('确认清空 CSP 违规统计？'), () => {
            util.post({
                url: "/admin/api/config/cspClear",
                data: {},
                done: res => {
                    layer.msg(res.msg || i18n('已清空'));
                    setTimeout(() => window.location.reload(), 600);
                },
                error: res => message.error(res?.msg || i18n('清空失败'))
            });
        });
    });

    //密钥和后台入口都默认遮住，点眼睛才显示——这类值不该在有人路过时留在屏幕上
    const bindReveal = (iconId, inputId) => {
        $('#' + iconId).off('click' + namespace).on('click' + namespace, function () {
            const input = document.getElementById(inputId);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            this.textContent = show ? 'visibility_off' : 'visibility';
        });
    };
    bindReveal('request-log-key-toggle', 'request-log-key');
    bindReveal('admin-entrance-toggle', 'admin-entrance');

    $('.request-log-clear').off('click' + namespace).on('click' + namespace, function () {
        const days = String($(this).data('days'));
        const label = days === '0'
            ? i18n('确认清空全部请求日志？删除不可恢复。')
            : i18n('确认清理 ') + days + i18n(' 天前的请求日志？删除不可恢复。');

        message.ask(label, () => {
            util.post({
                url: "/admin/api/config/requestLogClear",
                data: {days: days},
                done: res => {
                    layer.msg(res.msg || i18n('已清理'));
                    setTimeout(() => window.location.reload(), 800);
                },
                error: res => message.error(res?.msg || i18n('清理失败')),
                fail: () => message.error(i18n('网络异常，清理未执行'))
            });
        }, i18n('危险操作'));
    });

    window.addEventListener('pagehide', () => {
        controllerActive = false;
    }, {once: true});
}();
