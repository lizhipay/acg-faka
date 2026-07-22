!function () {
    const namespace = '.mdManageSetController';

    if (typeof window.__mdManageSetDestroy === 'function') window.__mdManageSetDestroy();

    let controllerActive = true;
    let saveInFlight = false;
    let uploadInFlight = false;
    let uploadRequest = null;
    let uploadGeneration = 0;
    let sessionsLoading = false;
    let sessionActionInFlight = false;
    let formVersion = 0;
    let savedAvatar = String($('#data-form input[name="avatar"]').val() || '');
    let savedPreview = $('.image-input-wrapper').css('background-image');

    function profileFingerprint(data) {
        const source = Array.isArray(data) ? data : $('#data-form').serializeArray();
        return JSON.stringify(source
            .filter(field => !['old_password', 'password', 're_password'].includes(field.name))
            .map(field => [String(field.name), String(field.value ?? '')]));
    }

    let savedProfileFingerprint = profileFingerprint();

    function formElement() {
        return document.getElementById('data-form');
    }

    function formRevision() {
        const form = formElement();
        return form && window.AdminMobile?.pageWorkflows?.getRevision ? window.AdminMobile.pageWorkflows.getRevision(form) : null;
    }

    function revisionIsCurrent(revision) {
        const form = formElement();
        return !form || !window.AdminMobile?.pageWorkflows?.isRevisionCurrent || window.AdminMobile.pageWorkflows.isRevisionCurrent(form, revision);
    }

    function emitFormState(name, revision) {
        const form = formElement();
        if (form) document.dispatchEvent(new CustomEvent(name, {detail: {form: form, revision: revision}}));
    }

    function redirectToLogin() {
        window.setTimeout(() => { window.location.href = '/admin/authentication/login'; }, 500);
    }

    function focusField(name, text) {
        const input = document.querySelector('#data-form [name="' + name + '"]');
        layer.msg(text);
        if (!input) return;
        const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        input.scrollIntoView({block: 'center', behavior: reduced ? 'auto' : 'smooth'});
        window.setTimeout(() => input.focus({preventScroll: true}), reduced ? 0 : 120);
    }

    function syncSaveBusy() {
        const busy = saveInFlight || uploadInFlight;
        const $button = $('#data-form .save-data');
        $button.prop('disabled', busy).toggleClass('disabled', busy);
        if (busy) {
            $button.attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        } else {
            $button.removeAttr('aria-busy aria-disabled');
        }
    }

    function avatarBackground(url) {
        return 'url("' + String(url || '/favicon.ico').replace(/["\\\r\n]/g, '') + '")';
    }

    function markAvatarChanged() {
        formVersion += 1;
        emitFormState('admin:mobile:form-dirty');
    }

    function reconcileProfileDirty() {
        if (profileFingerprint() === savedProfileFingerprint) {
            emitFormState('admin:mobile:form-saved', formRevision());
        } else {
            emitFormState('admin:mobile:form-dirty');
        }
    }

    function restoreSavedAvatar() {
        $('#data-form input[name="avatar"]').val(savedAvatar);
        window.setTimeout(() => {
            if (!controllerActive) return;
            $('.image-input-wrapper').css('background-image', savedPreview || avatarBackground(savedAvatar));
        }, 0);
        reconcileProfileDirty();
    }

    $('.upload-logo').off('change' + namespace).on('change' + namespace, function () {
        if (!controllerActive || !this.files || !this.files[0]) return;
        const generation = ++uploadGeneration;
        if (uploadRequest) uploadRequest.abort();

        const formData = new FormData();
        formData.append('file', this.files[0]);
        uploadInFlight = true;
        syncSaveBusy();
        Loading.show();
        uploadRequest = $.ajax({
            type: 'POST',
            url: '/admin/api/upload/send?mime=image',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: res => {
                if (!controllerActive || generation !== uploadGeneration) return;
                if (res.code !== 200 || !res.data || !res.data.url) {
                    restoreSavedAvatar();
                    layer.msg(res.msg || '头像上传失败');
                    return;
                }
                const url = String(res.data.url);
                $('#data-form input[name="avatar"]').val(url);
                $('.image-input-wrapper').css('background-image', avatarBackground(url));
                markAvatarChanged();
                layer.msg('头像上传成功，保存后生效');
            },
            error: (xhr, status) => {
                if (!controllerActive || generation !== uploadGeneration || status === 'abort') return;
                restoreSavedAvatar();
                layer.msg('网络异常，头像上传失败');
            },
            complete: () => {
                if (generation !== uploadGeneration) return;
                uploadRequest = null;
                uploadInFlight = false;
                Loading.hide();
                syncSaveBusy();
            }
        });
    });

    $('[data-kt-image-input-action="cancel"]').off('click' + namespace).on('click' + namespace, function () {
        if (!controllerActive) return;
        uploadGeneration += 1;
        if (uploadRequest) uploadRequest.abort();
        uploadRequest = null;
        if (uploadInFlight) Loading.hide();
        uploadInFlight = false;
        restoreSavedAvatar();
        reconcileProfileDirty();
        syncSaveBusy();
    });

    $('#data-form input[name="old_password"]').attr('autocomplete', 'current-password');
    $('#data-form input[name="password"], #data-form input[name="re_password"]').attr('autocomplete', 'new-password');
    $('#data-form').off(namespace).on('input' + namespace + ' change' + namespace, 'input, textarea, select', function () {
        formVersion += 1;
    });


    function saveSettings() {
        if (!controllerActive || saveInFlight) return;
        if (uploadInFlight) {
            layer.msg('头像正在上传，请稍候');
            return;
        }
        const serialized = $("#data-form").serializeArray();
        const data = util.arrayToObject(serialized);
        const submittedAvatar = String($('#data-form input[name="avatar"]').val() || '');
        const normalizedSubmittedAvatar = submittedAvatar || '/favicon.ico';
        const submittedProfileFingerprint = profileFingerprint(serialized.map(field => field.name === 'avatar'
            ? Object.assign({}, field, {value: normalizedSubmittedAvatar})
            : field));
        if (!String(data.nickname || '').trim()) {
            focusField('nickname', '昵称不能为空');
            return;
        }
        if (data.password && !data.old_password) {
            focusField('old_password', '修改密码前请输入旧密码');
            return;
        }
        if (data.password && String(data.password).length < 6) {
            focusField('password', '新密码不能少于 6 位');
            return;
        }
        if ((data.password || '') !== (data.re_password || '')) {
            focusField('re_password', '两次输入的新密码不一致');
            return;
        }
        const revision = formRevision();
        const submittedVersion = formVersion;
        saveInFlight = true;
        syncSaveBusy();
        util.post({
            url: "/admin/api/manage/set",
            data: data,
            done: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                syncSaveBusy();
                message.success(res.msg);
                if (res?.data?.reauthenticate) {
                    redirectToLogin();
                    return;
                }
                savedAvatar = normalizedSubmittedAvatar;
                savedPreview = avatarBackground(savedAvatar);
                savedProfileFingerprint = submittedProfileFingerprint;
                const $avatar = $('#data-form input[name="avatar"]');
                if (String($avatar.val() || '') === submittedAvatar) $avatar.val(savedAvatar);
                if (formVersion === submittedVersion && revisionIsCurrent(revision)) {
                    $('#data-form input[name=old_password], #data-form input[name=password], #data-form input[name=re_password]').val('');
                }
                emitFormState('admin:mobile:form-saved', revision);
            },
            error: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                syncSaveBusy();
                message.error(res?.msg || '个人设置保存失败');
            },
            fail: () => {
                if (!controllerActive) return;
                saveInFlight = false;
                syncSaveBusy();
                message.error('网络异常，个人设置未保存');
            }
        });
    }

    $('.save-data').off(namespace).on('click' + namespace, function (event) {
        event.preventDefault();
        saveSettings();
    });
    $('#data-form').on('submit' + namespace, function (event) {
        event.preventDefault();
        saveSettings();
    });

    $('.logout').off(namespace).on('click' + namespace, function (event) {
        event.preventDefault();
        const href = this.href;
        message.ask('确定要注销当前后台账号吗？', function () {
            const leave = () => { window.location.href = href; };
            if (window.AdminMobile?.pageWorkflows?.requestLeave) window.AdminMobile.pageWorkflows.requestLeave(leave);
            else leave();
        });
    });

    function sessionIcon(type) {
        if (type === 'mobile') return 'fa-mobile-screen-button';
        if (type === 'tablet') return 'fa-tablet-screen-button';
        return 'fa-display';
    }

    function sessionMeta(label, value, title) {
        const item = document.createElement('span');
        item.className = 'md-device-session__meta-item';
        if (title) item.title = String(title);
        const strong = document.createElement('b');
        strong.textContent = label;
        item.append(strong, document.createTextNode(String(value || '-')));
        return item;
    }

    function renderSessions(list) {
        const container = document.getElementById('md-device-session-list');
        const status = document.getElementById('md-device-session-status');
        if (!container || !status) return;
        container.replaceChildren();
        if (!Array.isArray(list) || list.length === 0) {
            status.className = 'text-muted py-6 text-center';
            status.textContent = '没有有效的登录设备';
            status.style.display = '';
            return;
        }
        status.style.display = 'none';

        list.forEach(session => {
            const row = document.createElement('article');
            row.className = 'md-device-session' + (session.current ? ' is-current' : '');

            const icon = document.createElement('span');
            icon.className = 'md-device-session__icon';
            icon.setAttribute('aria-hidden', 'true');
            const iconGlyph = document.createElement('i');
            iconGlyph.className = 'fa-duotone fa-regular ' + sessionIcon(String(session.device_type || ''));
            icon.appendChild(iconGlyph);

            const content = document.createElement('div');
            content.className = 'md-device-session__content';
            const heading = document.createElement('div');
            heading.className = 'md-device-session__heading';
            const name = document.createElement('strong');
            name.textContent = String(session.device_name || '未知设备');
            heading.appendChild(name);
            if (session.current) {
                const badge = document.createElement('span');
                badge.className = 'badge badge-light-success';
                badge.textContent = '当前设备';
                heading.appendChild(badge);
            }
            const activity = document.createElement('div');
            activity.className = 'md-device-session__meta';
            activity.append(
                sessionMeta('登录：', session.created_relative, session.created_time),
                sessionMeta('活跃：', session.last_seen_relative, session.last_seen_time)
            );
            const network = document.createElement('div');
            network.className = 'md-device-session__meta md-device-session__network';
            network.append(
                sessionMeta('登录 IP：', session.login_ip),
                sessionMeta('最近 IP：', session.last_ip)
            );
            content.append(heading, activity, network);
            row.append(icon, content);

            if (!session.current) {
                const revoke = document.createElement('button');
                revoke.type = 'button';
                revoke.className = 'btn btn-light-danger md-device-session__revoke';
                revoke.dataset.sessionId = String(Number(session.id));
                revoke.setAttribute('aria-label', '退出设备 ' + String(session.device_name || '未知设备'));
                revoke.innerHTML = '<i class="fa-duotone fa-regular fa-right-from-bracket" aria-hidden="true"></i><span>退出</span>';
                row.appendChild(revoke);
            }
            container.appendChild(row);
        });
    }

    function setSessionActionsBusy(busy) {
        sessionActionInFlight = busy;
        $('.md-session-revoke-others, .md-session-revoke-all, .md-device-session__revoke')
            .prop('disabled', busy)
            .attr('aria-busy', busy ? 'true' : null);
    }

    function loadSessions() {
        if (!controllerActive || sessionsLoading) return;
        sessionsLoading = true;
        $('#md-device-session-status').removeClass('text-danger').addClass('text-muted').text('正在读取登录设备…').show();
        $('.md-session-retry').hide();
        util.post({
            url: '/admin/api/manage/deviceSessions',
            data: {},
            loader: false,
            done: res => {
                sessionsLoading = false;
                if (controllerActive) renderSessions(res?.data?.list || []);
            },
            error: res => {
                sessionsLoading = false;
                if (!controllerActive) return;
                $('#md-device-session-status').removeClass('text-muted').addClass('text-danger').text(res?.msg || '登录设备读取失败').show();
                $('.md-session-retry').css('display', 'flex');
            },
            fail: () => {
                sessionsLoading = false;
                if (!controllerActive) return;
                $('#md-device-session-status').removeClass('text-muted').addClass('text-danger').text('网络异常，登录设备读取失败').show();
                $('.md-session-retry').css('display', 'flex');
            }
        });
    }

    function runSessionAction(url, data, onDone) {
        if (!controllerActive || sessionActionInFlight) return;
        setSessionActionsBusy(true);
        util.post({
            url: url,
            data: data || {},
            done: res => {
                if (!controllerActive) return;
                setSessionActionsBusy(false);
                message.success(res?.msg || '操作成功');
                if (typeof onDone === 'function') onDone(res);
                else loadSessions();
            },
            error: res => {
                if (!controllerActive) return;
                setSessionActionsBusy(false);
                message.error(res?.msg || '设备退出失败');
            },
            fail: () => {
                if (!controllerActive) return;
                setSessionActionsBusy(false);
                message.error('网络异常，设备未退出');
            }
        });
    }

    $('#md-device-session-list').off(namespace).on('click' + namespace, '.md-device-session__revoke', function () {
        const sessionId = Number(this.dataset.sessionId || 0);
        if (!Number.isInteger(sessionId) || sessionId <= 0) return;
        message.ask('确定让这台设备退出后台吗？', function () {
            runSessionAction('/admin/api/manage/revokeDeviceSession', {id: sessionId});
        });
    });
    $('.md-session-revoke-others').off(namespace).on('click' + namespace, function () {
        message.ask('确定退出当前设备以外的全部设备吗？', function () {
            runSessionAction('/admin/api/manage/revokeOtherDeviceSessions', {});
        });
    });
    $('.md-session-revoke-all').off(namespace).on('click' + namespace, function () {
        message.ask('确定退出全部设备吗？当前设备也需要重新登录。', function () {
            runSessionAction('/admin/api/manage/revokeAllDeviceSessions', {}, redirectToLogin);
        });
    });
    $('.md-session-retry').off(namespace).on('click' + namespace, loadSessions);

    loadSessions();

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        uploadGeneration += 1;
        if (uploadRequest) uploadRequest.abort();
        uploadRequest = null;
        if (uploadInFlight) Loading.hide();
        uploadInFlight = false;
        saveInFlight = false;
        sessionsLoading = false;
        sessionActionInFlight = false;
        syncSaveBusy();
        $('#data-form, #md-device-session-list, .save-data, .logout, .upload-logo, .md-session-revoke-others, .md-session-revoke-all, .md-session-retry, [data-kt-image-input-action="cancel"]').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (window.__mdManageSetDestroy === destroy) delete window.__mdManageSetDestroy;
    }

    window.__mdManageSetDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
