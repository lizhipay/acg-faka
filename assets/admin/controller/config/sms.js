!function () {
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const sendMessageIcon = '<svg class="md-message-send-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 6.5h10a2 2 0 0 1 2 2v2.25M3.5 7l5 4 5-4M3.5 6.5v9a2 2 0 0 0 2 2h7.25M14 14h6m-2.5-2.5L20 14l-2.5 2.5"/></svg>';
    const namespace = '.mdConfigSmsController';
    const promptLayers = new Set();
    let controllerActive = true;
    let saveInFlight = false;
    let testSending = false;
    let formDirty = false;
    let dirtyVersion = 0;

    if (typeof window.__mdConfigSmsDestroy === 'function') window.__mdConfigSmsDestroy();

    function formElement() {
        return document.getElementById('data-form');
    }

    function formRevision() {
        const form = formElement();
        return form && window.AdminMobile?.pageWorkflows?.getRevision ? window.AdminMobile.pageWorkflows.getRevision(form) : null;
    }

    function emitFormState(name, revision) {
        const form = formElement();
        if (form) document.dispatchEvent(new CustomEvent(name, {detail: {form: form, revision: revision}}));
    }

    function setSaveBusy(busy) {
        const $button = $('#data-form .save-data');
        $button.prop('disabled', busy).toggleClass('disabled', busy);
        if (busy) {
            $button.attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        } else {
            $button.removeAttr('aria-busy aria-disabled');
        }
    }

    function closePrompt(index) {
        promptLayers.delete(index);
        layer.close(index);
    }

    function prepareTestInput(unique) {
        $('.' + unique + ' input[name="phone"]').attr({
            inputmode: 'tel',
            autocomplete: 'tel',
            maxlength: '11',
            pattern: '[0-9]*'
        });
    }

    $('#data-form').off(namespace).on('input' + namespace + ' change' + namespace, 'input, textarea, select', function () {
        formDirty = true;
        dirtyVersion += 1;
        emitFormState('admin:mobile:form-dirty');
    });

    $('select[name=platform]').off(namespace).on('change' + namespace, function () {
        let val = $(this).val();
        if (val == 0) {
            $(".aliyun").show();
            $(".tencent").hide();
            $(".dxbao").hide();
        } else if (val == 1) {
            $(".aliyun").hide();
            $(".dxbao").hide();
            $(".tencent").show();
        } else if (val == 2) {
            $(".aliyun").hide();
            $(".dxbao").show();
            $(".tencent").hide();
        }
    });


    $('.save-data').off(namespace).on('click' + namespace, function () {
        if (!controllerActive || saveInFlight) return;
        if (testSending) {
            layer.msg('测试短信正在发送，请稍候再保存');
            return;
        }
        const revision = formRevision();
        const submittedVersion = dirtyVersion;
        const $secretFields = $('#data-form input[type="password"]');
        const submittedSecrets = new Map($secretFields.toArray().map(input => [input, input.value]));
        saveInFlight = true;
        setSaveBusy(true);
        util.post({
            url: "/admin/api/config/sms",
            data: util.arrayToObject($("#data-form").serializeArray()),
            done: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                submittedSecrets.forEach((value, input) => {
                    if (input.isConnected && input.value === value) input.value = '';
                });
                if (dirtyVersion === submittedVersion) formDirty = false;
                layer.msg(res.msg || "保存成功");
                emitFormState('admin:mobile:form-saved', revision);
            },
            error: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                if (mobileAdminEnabled()) window.AdminMobile?.pageWorkflows?.focusFormError?.(formElement(), res?.msg);
                message.error(res?.msg || '短信设置保存失败');
            },
            fail: () => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                message.error('网络异常，短信设置未保存');
            }
        });
    });

    function sendTest(phone, index) {
        const normalized = String(phone || '').trim();
        if (!/^1[3-9]\d{9}$/.test(normalized)) {
            layer.msg('请输入正确的国内手机号');
            return false;
        }
        if (testSending) {
            layer.msg('测试短信正在发送，请稍候');
            return false;
        }
        testSending = true;
        util.post("/admin/api/config/smsTest", {phone: normalized}, res => {
            testSending = false;
            if (!controllerActive) return;
            layer.msg(res.msg);
            closePrompt(index);
        }, res => {
            testSending = false;
            if (controllerActive) message.error(res?.msg || '测试短信发送失败');
        }, () => {
            testSending = false;
            if (controllerActive) message.error('网络异常，测试短信发送失败');
        });
        return true;
    }

    $('.send-test-message').off(namespace).on('click' + namespace, function () {
        if (saveInFlight) {
            layer.msg('短信设置正在保存，请稍候再测试');
            return;
        }
        if (formDirty) {
            layer.msg('请先保存当前短信设置，再发送测试短信');
            return;
        }
        const mobile = mobileAdminEnabled();
        if (mobile) {
            component.popup({
                width: '420px',
                height: 'auto',
                autoPosition: true,
                confirmText: sendMessageIcon + '<span>发送测试短信</span>',
                tab: [{
                    name: '发送测试短信',
                    form: [{
                        title: '(国内)手机号',
                        name: 'phone',
                        type: 'input',
                        placeholder: '请输入 11 位手机号',
                        required: true,
                        regex: {value: '^1[3-9]\\d{9}$', message: '请输入正确的国内手机号'}
                    }]
                }],
                renderComplete: prepareTestInput,
                submit: function (data, index) { return sendTest(data.phone, index); }
            });
            return;
        }
        let promptIndex = null;
        promptIndex = layer.prompt({
            title: '(国内)手机号',
            formType: 0,
            area: 'auto',
            offset: 'auto',
            skin: 'md-config-test-layer',
            resize: true,
            move: true,
            end: function () { promptLayers.delete(promptIndex); }
        }, function (phone, index) {
            sendTest(phone, index);
        });
        if (promptIndex !== undefined && promptIndex !== null) promptLayers.add(promptIndex);
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        saveInFlight = false;
        testSending = false;
        formDirty = false;
        setSaveBusy(false);
        $('#data-form, select[name=platform], .save-data, .send-test-message').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        promptLayers.forEach(index => layer.close(index));
        promptLayers.clear();
        if (window.__mdConfigSmsDestroy === destroy) delete window.__mdConfigSmsDestroy;
    }

    window.__mdConfigSmsDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
