!function () {
    const body = document.body;
    const form = document.getElementById('lk-form');
    if (!form) {
        return;
    }

    const q = (selector) => form.querySelector(selector);
    const fields = q('.lk-fields');
    const user = q('#lk-user');
    const pass = q('#lk-pass');
    const captcha = q('#lk-captcha');
    const captchaButton = q('#lk-captcha-btn');
    const captchaImage = q('#lk-captcha-img');
    const codeRow = q('#lk-code-row');
    const code = q('#lk-code');
    const eye = q('#lk-eye');
    const caps = q('#lk-caps');
    const note = q('#lk-note');
    const noteText = q('#lk-note-text');
    const wideLabel = q('.lk-submit__label');
    const submits = form.querySelectorAll('button[type="submit"]');
    const motion = window.matchMedia('(prefers-reduced-motion: no-preference)');
    const dict = getVar('I18N') || {};
    let busy = false;
    let passkeyPending = false; //通行密钥已验证、仅差补谷歌验证码（UV=0 + 账号开了 2FA）

    function safeGoto(raw, fallback) {
        if (typeof raw !== "string" || raw === "" || raw === "null") {
            return fallback;
        }
        let target;
        try {
            target = decodeURIComponent(raw);
        } catch (e) {
            return fallback;
        }
        const safe = target.charAt(0) === "/"
            && target.charAt(1) !== "/"
            && target.indexOf("\\") === -1
            && !/[\u0000-\u001f\u007f]/.test(target);
        return safe ? target : fallback;
    }

    const goto = safeGoto(util.getParam("goto"), "/admin/dashboard/index");

    // Server errors carry no field hint: match the known ones through the dictionary the server used.
    const translated = (text) => Object.prototype.hasOwnProperty.call(dict, text) ? dict[text] : text;
    const FIELD_ERRORS = [
        ["验证码错误", () => captcha],
        ["密码错误", () => pass],
        ["该邮箱不存在", () => user],
        ["谷歌验证码错误", () => code]
    ];

    function fieldOf(msg) {
        for (let i = 0; i < FIELD_ERRORS.length; i++) {
            if (msg === FIELD_ERRORS[i][0] || msg === translated(FIELD_ERRORS[i][0])) {
                return FIELD_ERRORS[i][1]();
            }
        }
        return null;
    }

    function say(text, tone) {
        noteText.textContent = text;
        note.setAttribute('data-tone', tone || 'error');
        note.classList.add('is-shown');
    }

    function hush() {
        note.classList.remove('is-shown');
    }

    function flag(input) {
        form.querySelectorAll('.lk-field.is-invalid').forEach(row => row.classList.remove('is-invalid'));
        form.querySelectorAll('.lk-input[aria-invalid]').forEach(el => {
            el.removeAttribute('aria-invalid');
            el.removeAttribute('aria-describedby');
        });
        if (input) {
            input.closest('.lk-field').classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', 'lk-note-text');
        }
    }

    function shake() {
        if (motion.matches && fields.animate) {
            fields.animate([
                {transform: 'translateX(0)'},
                {transform: 'translateX(-13px)'},
                {transform: 'translateX(11px)'},
                {transform: 'translateX(-8px)'},
                {transform: 'translateX(5px)'},
                {transform: 'translateX(-2px)'},
                {transform: 'translateX(0)'}
            ], {duration: 520, easing: 'cubic-bezier(.36, .07, .19, .97)'});
        }
    }

    function focus(input) {
        if (input) {
            input.focus();
            input.value && input.select();
        }
    }

    // The captcha is single use: every attempt burns it, so a failed one always needs a fresh image.
    function refreshCaptcha() {
        if (!captchaImage) {
            return;
        }
        captchaButton.classList.add('is-loading');
        captchaImage.onload = captchaImage.onerror = () => captchaButton.classList.remove('is-loading');
        captchaImage.src = '/user/captcha/image?action=adminLogin&t=' + Date.now();
        captcha.value = '';
    }

    function revealCode() {
        if (!codeRow.hidden) {
            return;
        }
        codeRow.hidden = false;
        fields.classList.add('has-code');
        if (motion.matches) {
            codeRow.classList.add('is-unfolding');
            codeRow.addEventListener('animationend', () => codeRow.classList.remove('is-unfolding'), {once: true});
        }
    }

    function setBusy(on, label) {
        busy = on;
        form.classList.toggle('is-busy', on);
        form.setAttribute('aria-busy', String(on));
        submits.forEach(button => button.disabled = on);
        if (wideLabel) {
            wideLabel.textContent = label || wideLabel.getAttribute('data-lk-label');
        }
    }

    function missing() {
        if (!user.value.trim()) {
            return [user, i18n('请输入邮箱')];
        }
        if (!pass.value) {
            return [pass, i18n('请输入密码')];
        }
        if (captcha && !captcha.value) {
            return [captcha, i18n('请输入验证码')];
        }
        if (!codeRow.hidden && !code.value) {
            return [code, i18n('请输入 6 位数字动态码')];
        }
        return null;
    }

    function reject(msg, field) {
        say(msg, 'error');
        flag(field);
        shake();
        focus(field);
    }

    function submitPasskeyCode() {
        const value = (code.value || '').trim();
        if (!value) {
            reject(i18n('请输入 6 位数字动态码'), code);
            return;
        }
        flag(null);
        hush();
        setBusy(true, i18n('验证中…'));
        postP('/admin/api/authentication/passkeyTotp', {code: value}).then(() => {
            setBusy(true, i18n('登录成功！正在跳转…'));
            body.classList.add('is-unlocking');
            window.location.href = goto;
        }).catch((e) => {
            setBusy(false);
            code.value = '';
            if (e && e.code === 42001) {
                //暂存态失效：退回让用户重新用通行密钥登入
                passkeyPending = false;
                say((e && e.msg) || i18n('登录状态已失效，请重新登录'), 'error');
                return;
            }
            reject((e && e.msg) || i18n('验证码错误'), code);
        });
    }

    function submit() {
        if (busy) {
            return;
        }
        if (passkeyPending) {
            submitPasskeyCode();
            return;
        }
        const gap = missing();
        if (gap) {
            reject(gap[1], gap[0]);
            return;
        }
        flag(null);
        hush();
        setBusy(true, i18n('验证中…'));

        util.post({
            url: "/admin/api/authentication/login",
            data: util.getFormData(form),
            loader: false,
            done: () => {
                setBusy(true, i18n('登录成功！正在跳转…'));
                body.classList.add('is-unlocking');
                window.location.href = goto;
            },
            error: res => {
                setBusy(false);
                refreshCaptcha();
                const msg = (res && res.msg) || i18n('网络错误');
                // Password accepted, the account wants its authenticator code (ManageSSO::CODE_NEED_TOTP).
                if (res && res.code === 42001) {
                    revealCode();
                    say(msg, 'info');
                    flag(null);
                    focus(captcha || code);
                    return;
                }
                const field = fieldOf(msg);
                if (field === pass || field === code) {
                    field.value = '';
                }
                reject(msg, field);
                field || focus(captcha || pass);
            },
            fail: () => {
                setBusy(false);
                refreshCaptcha();
                reject(i18n('网络错误'), null);
                focus(captcha || pass);
            }
        });
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        submit();
    });

    form.addEventListener('input', (e) => {
        const input = e.target;
        if (input.classList.contains('lk-input--digits')) {
            const digits = input.value.replace(/\D+/g, '');
            digits !== input.value && (input.value = digits);
        }
        if (input.getAttribute('aria-invalid') === 'true') {
            flag(null);
            hush();
        }
        // An authenticator code is complete at six digits: sign in without reaching for the button.
        if (input === code && code.value.length === 6 && !missing()) {
            submit();
        }
    });

    // Keep the caret (and the phone keyboard) in the field when tapping helpers inside it.
    const keepFocus = (e) => e.preventDefault();
    [eye, captchaButton].forEach(el => {
        if (el) {
            el.addEventListener('pointerdown', keepFocus);
            el.addEventListener('mousedown', keepFocus);
        }
    });

    eye.addEventListener('click', () => {
        const reveal = pass.type === 'password';
        const label = reveal ? i18n('隐藏密码') : i18n('显示密码');
        pass.type = reveal ? 'text' : 'password';
        eye.setAttribute('aria-pressed', String(reveal));
        eye.setAttribute('aria-label', label);
        eye.title = label;
    });

    if (captchaButton) {
        captchaButton.addEventListener('click', () => {
            refreshCaptcha();
            captcha.focus();
        });
    }

    const syncCaps = (e) => {
        if (e.getModifierState) {
            caps.hidden = !e.getModifierState('CapsLock');
        }
    };
    pass.addEventListener('keydown', syncCaps);
    pass.addEventListener('keyup', syncCaps);
    pass.addEventListener('blur', () => caps.hidden = true);

    // passkey 登入：util.post 的 Promise 封装
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

    const passkeyBtn = document.getElementById('lk-passkey');
    if (passkeyBtn) {
        if (window.AdminWebAuthn && AdminWebAuthn.supported()) {
            passkeyBtn.hidden = false;
            passkeyBtn.addEventListener('click', async () => {
                if (busy) {
                    return;
                }
                passkeyPending = false;
                flag(null);
                hush();
                setBusy(true, i18n('等待通行密钥…'));
                try {
                    const options = await postP('/admin/api/authentication/passkeyOptions', {});
                    const assertion = await AdminWebAuthn.get(options);
                    const remember = document.getElementById('lk-remember');
                    assertion.remember = (remember && remember.checked) ? 1 : '';
                    await postP('/admin/api/authentication/passkeyLogin', assertion);
                    setBusy(true, i18n('登录成功！正在跳转…'));
                    body.classList.add('is-unlocking');
                    window.location.href = goto;
                } catch (e) {
                    setBusy(false);
                    // UV=0 的金钥登入已开谷歌 2FA 的账号：通行密钥已验证，仅差动态码→展开验证码栏补码。
                    if (e && e.code === 42001) {
                        passkeyPending = true;
                        revealCode();
                        flag(null);
                        say((e && e.msg) || i18n('请输入 6 位数字动态码'), 'info');
                        focus(code);
                        return;
                    }
                    const msg = (e && e.msg) ? e.msg : (window.AdminWebAuthn ? AdminWebAuthn.errorText(e) : i18n('通行密钥登录失败'));
                    say(msg, 'error');
                }
            });
        } else {
            passkeyBtn.remove();
        }
    }

    // Back button after a successful sign-in restores the faded page from bfcache: let the server route it again.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted && body.classList.contains('is-unlocking')) {
            window.location.reload();
        }
    });

    try {
        localStorage.removeItem("manage_token");
    } catch (e) {
    }
}();
