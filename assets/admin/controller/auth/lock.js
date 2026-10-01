!function () {
    const body = document.body;
    const form = document.getElementById('lk-form');
    if (!form) {
        return;
    }

    const pass = document.getElementById('lk-pass');
    const eye = document.getElementById('lk-eye');
    const note = document.getElementById('lk-note');
    const noteText = document.getElementById('lk-note-text');
    const passkeyBtn = document.getElementById('lk-passkey');
    const wideLabel = form.querySelector('button[type="submit"] .lk-submit__label');
    const submits = form.querySelectorAll('button[type="submit"]');
    const gotoSrc = form.hasAttribute('data-lock-goto') ? form : document.querySelector('[data-lock-goto]');
    let busy = false;

    function safeGoto(raw) {
        const fallback = "/admin/dashboard/index";
        if (typeof raw !== "string" || raw === "" || raw === "null") {
            return fallback;
        }
        const ok = raw.charAt(0) === "/" && raw.charAt(1) !== "/"
            && raw.indexOf("\\") === -1 && raw.indexOf("/admin") === 0
            && !/[\u0000-\u001f\u007f]/.test(raw);
        return ok ? raw : fallback;
    }

    const goto = safeGoto(gotoSrc ? gotoSrc.getAttribute('data-lock-goto') : '');

    function say(text, tone) {
        noteText.textContent = text;
        note.setAttribute('data-tone', tone || 'error');
        note.classList.add('is-shown');
    }

    function hush() {
        note.classList.remove('is-shown');
    }

    function setBusy(on, label) {
        busy = on;
        form.classList.toggle('is-busy', on);
        submits.forEach(b => b.disabled = on);
        if (passkeyBtn) {
            passkeyBtn.disabled = on;
        }
        if (wideLabel && label) {
            wideLabel.textContent = label;
        }
    }

    function unlocked() {
        setBusy(true, i18n('解锁成功，正在返回…'));
        body.classList.add('is-unlocking');
        window.location.href = goto;
    }

    function postP(url, data) {
        return new Promise((resolve, reject) => {
            util.post({
                url: url,
                data: data || {},
                loader: false,
                done: res => resolve(res && res.data ? res.data : {}),
                error: res => reject(res || {}),
                fail: () => reject({msg: i18n('网络错误')})
            });
        });
    }

    function passwordUnlock() {
        if (busy) {
            return;
        }
        if (!pass.value) {
            say(i18n('请输入密码'), 'error');
            pass.focus();
            return;
        }
        hush();
        setBusy(true, i18n('验证中…'));
        postP('/admin/api/authentication/unlockPassword', {password: pass.value})
            .then(unlocked)
            .catch(res => {
                setBusy(false);
                pass.value = '';
                pass.focus();
                say((res && res.msg) ? res.msg : i18n('网络错误'), 'error');
            });
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        passwordUnlock();
    });

    if (eye) {
        const keep = (e) => e.preventDefault();
        eye.addEventListener('pointerdown', keep);
        eye.addEventListener('mousedown', keep);
        eye.addEventListener('click', () => {
            const reveal = pass.type === 'password';
            pass.type = reveal ? 'text' : 'password';
            eye.setAttribute('aria-pressed', String(reveal));
        });
    }

    if (passkeyBtn) {
        if (window.AdminWebAuthn && AdminWebAuthn.supported()) {
            passkeyBtn.addEventListener('click', async () => {
                if (busy) {
                    return;
                }
                hush();
                setBusy(true, i18n('等待通行密钥…'));
                try {
                    const options = await postP('/admin/api/authentication/unlockPasskeyOptions', {});
                    const assertion = await AdminWebAuthn.get(options);
                    await postP('/admin/api/authentication/unlockPasskey', assertion);
                    unlocked();
                } catch (e) {
                    setBusy(false);
                    const msg = (e && e.msg) ? e.msg : (window.AdminWebAuthn ? AdminWebAuthn.errorText(e) : i18n('通行密钥解锁失败'));
                    say(msg, 'error');
                }
            });
        } else {
            // 浏览器不支持通行密钥：移除入口，仅保留「改用其他账号登录」
            passkeyBtn.remove();
        }
    }

    pass && pass.focus();
}();
