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

    //把一条违规记录加进外部脚本放行清单。
    //注意这里提交的是违规记录的 key，不是域名——服务端会拿它反查违规库，
    //所以白名单里只可能出现本站真实被拦过的地址，站长填不宽也填不错（GitHub #909）。
    $('.csp-allow').off('click' + namespace).on('click' + namespace, function () {
        const key = $(this).data('key');
        const blocked = String($(this).data('blocked') || '');
        //目录级默认：厂商发版换文件名(hash)时不用反复加白，又不是整站放行
        const dir = blocked.replace(/[?#].*$/, '').replace(/[^/]*$/, '');
        const host = (blocked.match(/^https?:\/\/[^/]+/) || [''])[0];

        component.popup({
            submit: '/admin/api/config/cspAllow',
            tab: [{
                name: util.icon('fa-duotone fa-regular fa-shield-check') + i18n(' 放行外部脚本'),
                form: [
                    {title: 'key', name: 'key', type: 'input', hide: true, default: key},
                    {
                        title: false, name: 'csp_allow_tip', type: 'custom', submit: false,
                        complete: (form, dom) => {
                            const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({
                                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                            })[c]);
                            dom.html('<div class="alert alert-primary" style="margin:0;word-break:break-all;">'
                                + '<div style="font-size:12px;opacity:.75;margin-bottom:6px;">' + i18n('被拦下的地址') + '</div>'
                                + esc(blocked) + '</div>');
                        }
                    },
                    {
                        title: '放行范围', name: 'grain', type: 'radio', default: 'dir',
                        dict: [
                            {id: 'file', name: i18n('只这个文件') + '（' + blocked.replace(/[?#].*$/, '') + '）'},
                            {id: 'dir', name: i18n('该目录（推荐）') + '（' + dir + '）'},
                            {id: 'host', name: i18n('整个域名（风险最大）') + '（' + host + '）'}
                        ],
                        tips: '选「该目录」：厂商换了文件名也不用再来加白，同时不会把该域名下其它脚本一起放行。选「整个域名」等于允许那台服务器上任何脚本在你站上执行，除非必要不要选。'
                    }
                ]
            }],
            autoPosition: true,
            height: 'auto',
            width: '680px',
            done: () => setTimeout(() => window.location.reload(), 600)
        });
    });

    $('.csp-allow-remove').off('click' + namespace).on('click' + namespace, function () {
        const source = $(this).data('source');
        message.ask(i18n('移除后该地址的脚本会重新被拦下，确认？'), () => {
            util.post({
                url: '/admin/api/config/cspAllowRemove',
                data: {source: source},
                done: res => {
                    layer.msg(res.msg || i18n('已移除'));
                    setTimeout(() => window.location.reload(), 600);
                },
                error: res => message.error(res?.msg || i18n('移除失败'))
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
