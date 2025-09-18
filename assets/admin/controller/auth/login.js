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
            eye.setAttribute('aria-label', t === 'password' ? '显示密码' : '隐藏密码');
        });
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            btn.disabled = true;
            btn.textContent = '验证中…';

            util.post({
                url: "/admin/api/authentication/login",
                data: util.getFormData('#ay-form'),
                loader: false,
                done: res => {
                    btn.textContent = '登录成功！正在跳转…';
                    localStorage.setItem("manage_token", res?.data?.token);
                    window.location.href = goto;
                },
                error: res => {
                    btn.disabled = false;
                    btn.textContent = "确认登入";
                    message.error(res.msg);
                },
                fail: () => {
                    btn.disabled = false;
                    btn.textContent = "确认登入";
                    message.error("网络错误");
                }
            });
        });
    }

    _Effects();
    _Login();
}();