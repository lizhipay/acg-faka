!function () {
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

    let goto = safeGoto(util.getParam("goto"), "/");
    const T = (s) => (typeof i18n === "function" ? i18n(s) : s);

    // 两步验证第二步：密码已通过，弹出验证码输入框，提交到独立端点。onCancel：用户关掉输入框时回调
    function promptTotp(onCancel) {
        message.prompt({
            title: T("两步验证"),
            input: "text",
            inputPlaceholder: T("验证器上的 6 位动态码，或一条备用恢复码"),
            inputAttributes: {autocapitalize: "off", autocomplete: "one-time-code", inputmode: "text"},
            confirmButtonText: T("验证并登录")
        }).then((r) => {
            if (!r || r.isConfirmed !== true) {
                typeof onCancel === "function" && onCancel();
                return;
            }
            const code = (r.value || "").trim();
            if (code === "") { promptTotp(onCancel); return; }
            util.post({
                url: "/user/api/authentication/totp",
                data: {code: code},
                done: (res) => {
                    message.success(res.msg);
                    window.location.href = goto;
                },
                error: (res) => {
                    message.error((res && res.msg) || T("验证码错误"));
                    promptTotp(onCancel);
                },
                fail: () => message.error(T("网络异常，请稍后重试"))
            });
        });
    }

    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post({
            url: "/user/api/authentication/login",
            data: data,
            done: (res) => {
                window.location.href = goto;
                message.success(res.msg);
            },
            // 42001 = 密码正确但已开启两步验证，需输入动态码
            error: (res) => {
                if (res && res.code === 42001) {
                    promptTotp();
                    return;
                }
                message.error((res && res.msg) || T("登录失败"));
            },
            fail: () => message.error(T("网络异常，请稍后重试"))
        });
    });

    // OAuth 等跳回登录页并带 ?totp=1 时，直接弹出验证码框
    if (util.getParam("totp") === "1") {
        promptTotp();
    }

    /* ---------------- 通行密钥登录 ----------------
     * 主题只需提供：登录表单里一个 #passkey-login 按钮（默认 hidden，支持才显示），
     * 以及可选的账号输入框 autocomplete="username webauthn"（浏览器会在自动填充里直接列出通行密钥）。
     * 依赖 /assets/user/js/webauthn.js（window.UserWebAuthn）。 */
    const WA = window.UserWebAuthn;
    const pkButton = document.getElementById("passkey-login");
    if (!WA || !WA.supported()) {
        pkButton && pkButton.remove();
        return;
    }

    const OPTIONS_FRESH = 10 * 60 * 1000; // 服务端登录挑战保留 15 分钟，前端 10 分钟内的才拿来用
    let pkPending = null;  // 挂起中的条件式请求的中止器
    let pkArmedAt = 0;     // 条件式请求挂上的时间
    let pkRetries = 0;     // 条件式请求被用户取消后自动重挂的次数
    let pkFetching = null; // 正在取的登录选项
    let pkOptions = null;  // 已取到、挑战还没用过的登录选项 {opts, at}
    let pkBusy = false;

    const rememberValue = () => {
        const form = (pkButton && pkButton.closest("form")) || document.querySelector(".needs-validation") || document;
        const box = form.querySelector('[name="remember"]');
        return box && box.checked ? 1 : 0;
    };

    const request = (url, data) => new Promise((resolve, reject) => util.post({
        url: url,
        data: data || {},
        loader: false,
        done: resolve,
        error: (res) => reject(res || {}),
        fail: () => reject({msg: T("网络异常，请稍后重试")})
    }));

    const setBusy = (busy) => {
        pkBusy = busy;
        if (!pkButton) return;
        pkButton.disabled = busy;
        pkButton.classList.toggle("is-busy", busy);
        pkButton.setAttribute("aria-busy", busy ? "true" : "false");
    };

    // 失败后把焦点还给按钮（disabled 期间焦点会掉到 body，键盘用户得从头 Tab）
    const restoreFocus = () => {
        if (pkButton && (document.activeElement === document.body || document.activeElement === null)) {
            pkButton.focus({preventScroll: true});
        }
    };

    // 取一份登录选项（服务端挑战），存成 {opts, at}。多份并存没关系：服务端按挑战值保留最近几份
    function fetchOptions() {
        const fetching = request("/user/api/authentication/passkeyOptions").then((res) => {
            const opts = (res && res.data) || null;
            if (opts && opts.challenge) {
                pkOptions = {opts: opts, at: Date.now()};
            }
        });
        pkFetching = fetching;
        fetching.catch(() => null).then(() => {
            if (pkFetching === fetching) pkFetching = null;
        });
        return fetching;
    }

    // 拿走一份新鲜、挑战没用过的选项（单次使用）
    function takeOptions() {
        const entry = pkOptions && (Date.now() - pkOptions.at) < OPTIONS_FRESH ? pkOptions : null;
        pkOptions = null;
        return entry;
    }

    // 断言交给服务端。42001 = 认证器没做身份验证而账号开了两步验证，接着输动态码
    async function finish(assertion, rpId) {
        setBusy(true);
        try {
            const res = await request("/user/api/authentication/passkeyLogin", Object.assign({}, assertion, {remember: rememberValue()}));
            message.success(res.msg);
            window.location.href = goto;
            return;
        } catch (res) {
            setBusy(false);
            if (res.code === 42001) {
                promptTotp(() => armAutofill());
                return;
            }
            if (res.code === 42004) {
                WA.signalUnknown(rpId, assertion.id);
            }
            message.error(res.msg || T("登录失败"));
            restoreFocus();
        }
        armAutofill();
    }

    // 条件式界面：挂着一个不弹窗的请求，点账号输入框时浏览器会在自动填充里列出本站的通行密钥
    async function armAutofill() {
        if (pkBusy || !document.querySelector('input[autocomplete~="webauthn"]') || !(await WA.conditionalAvailable()) || pkBusy) {
            if (!pkBusy && !pkOptions && !pkFetching) {
                fetchOptions().catch(() => null); // 没有自动填充也先备好一份，按钮点下去就能立刻弹框
            }
            return;
        }
        pkPending && pkPending.abort();
        const controller = new AbortController();
        pkPending = controller;
        const release = () => {
            if (pkPending === controller) pkPending = null;
        };
        let entry, assertion;
        try {
            entry = takeOptions();
            if (!entry) {
                await fetchOptions();
                entry = takeOptions();
            }
            if (!entry) {
                release();
                return;
            }
            if (controller.signal.aborted || pkBusy) {
                pkOptions = pkOptions || entry; // 没用上，留给按钮
                release();
                return;
            }
            pkOptions = entry; // 挂着期间用户点按钮会中止本请求，这份挑战可以直接沿用
            pkArmedAt = entry.at;
            assertion = await WA.get(entry.opts, {mediation: "conditional", signal: controller.signal});
        } catch (e) {
            release();
            // Safari 在用户取消面容验证时会结束条件式请求；有限次数内重新挂上，自动填充才不会消失
            if (e && e.name === "NotAllowedError" && !controller.signal.aborted && pkRetries < 3) {
                pkRetries++;
                setTimeout(armAutofill, 300);
            }
            return;
        }
        release();
        if (controller.signal.aborted) return;
        pkRetries = 0;
        if (pkOptions === entry) pkOptions = null; // 这份挑战要被服务端用掉了
        finish(assertion, entry.opts.rpId);
    }

    // 按钮：弹出系统的通行密钥选择框。尽量在点击当下直接调 get()（Safari 要求在用户手势内发起）
    async function signIn() {
        if (pkBusy) return;
        setBusy(true);
        pkPending && pkPending.abort();
        pkPending = null;
        let entry = takeOptions();
        let assertion;
        try {
            if (!entry && pkFetching) {
                await pkFetching.catch(() => null);
                entry = takeOptions();
            }
            if (!entry) {
                await fetchOptions();
                entry = takeOptions();
            }
            assertion = await WA.get(entry ? entry.opts : null);
        } catch (e) {
            setBusy(false);
            if (e && e.name === "NotAllowedError") {
                message.info(T("未完成通行密钥验证"));
            } else {
                message.error(WA.errorText(e, "get"));
            }
            restoreFocus();
            armAutofill();
            return;
        }
        finish(assertion, entry.opts.rpId);
    }

    // 登录页挂着不动太久（例如切到别的分页），挑战会过期：回到页面时换一份新的
    function refreshIfStale() {
        const input = document.querySelector('input[autocomplete~="webauthn"]');
        if (pkBusy || !pkPending || document.hidden || (input && document.activeElement === input)) return;
        if (Date.now() - pkArmedAt > OPTIONS_FRESH) {
            pkOptions = null;
            armAutofill();
        }
    }

    if (pkButton) {
        pkButton.hidden = false;
        pkButton.addEventListener("click", signIn);
    }
    document.addEventListener("visibilitychange", refreshIfStale);
    setInterval(refreshIfStale, 60 * 1000);
    // 从下一页按「返回」时页面从 bfcache 还原：按钮还停在「验证中」，条件式请求也已结束
    window.addEventListener("pageshow", (e) => {
        if (e.persisted) {
            setBusy(false);
            pkPending = null;
            pkOptions = null;
            pkRetries = 0;
            armAutofill();
        }
    });
    armAutofill();
}();
