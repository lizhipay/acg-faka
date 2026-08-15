!function () {
    function _Effects() {
        const card = document.querySelector('.ay-card');
        if (!card) return;
        const enable = window.matchMedia('(hover:hover) and (pointer:fine)').matches;
        if (!enable) return; // 只在桌面等精细指针设备启用

        let raf;
        const clamp = (v, a, b) => Math.max(a, Math.min(v, b));

        function onMove(e) {
            const r = card.getBoundingClientRect();
            const x = (e.clientX - r.left) / r.width; // 0..1
            const y = (e.clientY - r.top) / r.height;
            card.style.setProperty('--mx', (x * 100) + '%');
            card.style.setProperty('--my', (y * 100) + '%');
            const rx = clamp((0.5 - y) * 6, -6, 6);
            const ry = clamp((x - 0.5) * 8, -8, 8);
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => {
                card.style.transform = `perspective(1000px) rotateX(${rx}deg) rotateY(${ry}deg)`;
            });
        }

        function reset() {
            card.style.transform = 'none';
        }

        card.addEventListener('mousemove', onMove);
        card.addEventListener('mouseleave', reset);
        window.addEventListener('blur', reset);
    }

    function _Login() {
        localStorage.removeItem("manage_token");
        let goto = decodeURIComponent(util.getParam("goto"));

        if (goto == "null") {
            goto = "/admin/dashboard/index";
        }

        const eye = document.getElementById('ay-eye');
        const pass = document.getElementById('ay-pass');
        const form = document.getElementById('ay-form');
        const btn = document.getElementById('ay-submit');
        eye.addEventListener('click', () => {
            const t = pass.getAttribute('type') === 'password' ? 'text' : 'password';
            pass.setAttribute('type', t);
            eye.setAttribute('aria-label', t === 'password' ? i18n('显示密码') : i18n('隐藏密码'));
        });
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            btn.disabled = true;
            btn.textContent = i18n('验证中…');

            util.post({
                url: "/admin/api/authentication/login",
                data: util.getFormData('#ay-form'),
                loader: false,
                done: res => {
                    btn.textContent = i18n('登录成功！正在跳转…');
                    window.location.href = goto;
                },
                error: res => {
                    btn.disabled = false;
                    btn.textContent = i18n("确认登入");
                    const _c = document.getElementById('ay-captcha-img');
                    if (_c) _c.src = '/user/captcha/image?action=adminLogin&t=' + Date.now();
                    // 账号已绑定谷歌验证器：按需展开动态码输入框（服务端专属 code，见 ManageSSO::CODE_NEED_TOTP）
                    if (res.code === 42001) {
                        const twoFa = document.querySelector('.ay-2fa');
                        const codeInput = document.getElementById('ay-code');
                        if (twoFa && twoFa.classList.contains('is-hidden')) {
                            twoFa.classList.remove('is-hidden');
                            twoFa.classList.add('ay-reveal');
                        }
                        const _cap = document.getElementById('ay-captcha');
                        if (_cap) _cap.value = ''; // 图形验证码单次有效，需重新输入
                        codeInput && codeInput.focus();
                        message.warning ? message.warning(res.msg) : message.error(res.msg);
                        return;
                    }
                    message.error(res.msg);
                },
                fail: () => {
                    btn.disabled = false;
                    btn.textContent = i18n("确认登入");
                    const _c = document.getElementById('ay-captcha-img');
                    if (_c) _c.src = '/user/captcha/image?action=adminLogin&t=' + Date.now();
                    message.error("网络错误");
                }
            });
        });
    }

    function _Comfort() {
        // 大写锁定提示：输入密码时检测 CapsLock 状态
        const pass = document.getElementById('ay-pass');
        const caps = document.getElementById('ay-caps');
        if (pass && caps) {
            const sync = (e) => {
                try {
                    caps.hidden = !(e.getModifierState && e.getModifierState('CapsLock'));
                } catch (_) {
                    caps.hidden = true;
                }
            };
            pass.addEventListener('keydown', sync);
            pass.addEventListener('keyup', sync);
            pass.addEventListener('blur', () => caps.hidden = true);
        }

        // 明暗主题切换：与后台面板共用 admin-theme 存储
        const themeBtn = document.getElementById('ay-theme');
        if (themeBtn) {
            themeBtn.addEventListener('click', () => {
                const root = document.documentElement;
                const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                root.setAttribute('data-theme-pref', next);
                try {
                    localStorage.setItem('admin-theme', next);
                } catch (_) {
                }
            });
        }
    }

    _Effects();
    _Login();
    _Comfort();
}();
