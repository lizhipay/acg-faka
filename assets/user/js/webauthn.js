/**
 * 会员端 passkey(WebAuthn) 浏览器封装：base64url <-> ArrayBuffer、create()/get() 编解码、
 * 条件式界面（账号输入框的自动填充里直接列出通行密钥）与异常文案。
 * 登录页与安全中心共用，暴露为 window.UserWebAuthn。
 */
(function () {
    "use strict";
    if (window.UserWebAuthn) {
        return;
    }

    const T = (s) => (typeof i18n === "function" ? i18n(s) : s);

    function b64uToBuf(s) {
        s = String(s || "").replace(/-/g, "+").replace(/_/g, "/");
        const pad = s.length % 4;
        if (pad) {
            s += "====".slice(pad);
        }
        const bin = atob(s);
        const buf = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) {
            buf[i] = bin.charCodeAt(i);
        }
        return buf.buffer;
    }

    function bufToB64u(buf) {
        const bytes = new Uint8Array(buf);
        let bin = "";
        for (let i = 0; i < bytes.length; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    }

    // IP 不能当 RP ID；App 内置浏览器（微信/QQ/支付宝/钉钉/微博）即使有 API 也用不了
    function hostUsable() {
        const host = location.hostname;
        const isIp = /^\d{1,3}(\.\d{1,3}){3}$/.test(host) || host.indexOf(":") !== -1 || host.charAt(0) === "[";
        return !isIp && !/MicroMessenger|\bQQ\/|AlipayClient|DingTalk|Weibo/i.test(navigator.userAgent);
    }

    function supported() {
        return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext) && hostUsable();
    }

    async function conditionalAvailable() {
        try {
            return supported()
                && typeof PublicKeyCredential.isConditionalMediationAvailable === "function"
                && await PublicKeyCredential.isConditionalMediationAvailable() === true;
        } catch (e) {
            return false;
        }
    }

    function descriptors(list) {
        return (list || []).map((c) => ({type: c.type || "public-key", id: b64uToBuf(c.id)}));
    }

    function assertOptions(opts, fields) {
        if (!opts || fields.some((f) => !opts[f])) {
            const e = new Error(T("参数异常，请刷新页面后重试"));
            e.name = "BadOptionsError";
            throw e;
        }
    }

    /**
     * @param {object} opts 服务端下发的 create 选项
     * @param {{signal?: AbortSignal}} [extra]
     */
    async function create(opts, extra) {
        assertOptions(opts, ["challenge", "rp", "user"]);
        const request = {
            publicKey: {
                rp: opts.rp,
                user: {
                    id: b64uToBuf(opts.user.id),
                    name: opts.user.name,
                    displayName: opts.user.displayName
                },
                challenge: b64uToBuf(opts.challenge),
                pubKeyCredParams: opts.pubKeyCredParams,
                timeout: opts.timeout,
                attestation: opts.attestation || "none",
                authenticatorSelection: opts.authenticatorSelection || {},
                excludeCredentials: descriptors(opts.excludeCredentials)
            }
        };
        if (extra && extra.signal) {
            request.signal = extra.signal;
        }
        const cred = await navigator.credentials.create(request);
        const r = cred.response;
        return {
            id: cred.id,
            attestationObject: bufToB64u(r.attestationObject),
            clientDataJSON: bufToB64u(r.clientDataJSON),
            transports: typeof r.getTransports === "function" ? (r.getTransports() || []).join(",") : ""
        };
    }

    /**
     * @param {object} opts 服务端下发的 get 选项
     * @param {{mediation?: string, signal?: AbortSignal}} [extra] 条件式界面传 mediation:"conditional"
     */
    async function get(opts, extra) {
        assertOptions(opts, ["challenge"]);
        const request = {
            publicKey: {
                challenge: b64uToBuf(opts.challenge),
                timeout: opts.timeout,
                rpId: opts.rpId,
                userVerification: opts.userVerification || "preferred",
                allowCredentials: descriptors(opts.allowCredentials)
            }
        };
        if (extra && extra.mediation) {
            request.mediation = extra.mediation;
        }
        if (extra && extra.signal) {
            request.signal = extra.signal;
        }
        const cred = await navigator.credentials.get(request);
        const r = cred.response;
        return {
            id: cred.id,
            authenticatorData: bufToB64u(r.authenticatorData),
            clientDataJSON: bufToB64u(r.clientDataJSON),
            signature: bufToB64u(r.signature),
            userHandle: r.userHandle ? bufToB64u(r.userHandle) : ""
        };
    }

    // WebAuthn Signal API：让密码管理器同步服务端的真实状态（隐藏已删除的通行密钥），浏览器支持才调用
    function signalUnknown(rpId, credentialId) {
        try {
            if (rpId && credentialId && typeof PublicKeyCredential.signalUnknownCredential === "function") {
                PublicKeyCredential.signalUnknownCredential({rpId: rpId, credentialId: credentialId}).catch(() => {
                });
            }
        } catch (e) {
        }
    }

    function signalAccepted(rpId, userId, credentialIds) {
        try {
            if (rpId && userId && Array.isArray(credentialIds) && typeof PublicKeyCredential.signalAllAcceptedCredentials === "function") {
                PublicKeyCredential.signalAllAcceptedCredentials({
                    rpId: rpId,
                    userId: userId,
                    allAcceptedCredentialIds: credentialIds
                }).catch(() => {
                });
            }
        } catch (e) {
        }
    }

    /**
     * @param {Error} e create()/get() 抛出的异常
     * @param {"create"|"get"} [action]
     */
    function errorText(e, action) {
        switch (e && e.name) {
            case "NotAllowedError":
                return T("已取消，或等待超时");
            case "InvalidStateError":
                return action === "create" ? T("这台设备已经添加过通行密钥") : T("认证器暂时无法使用，请重试");
            case "ConstraintError":
            case "NotSupportedError":
                return T("这台设备或浏览器不支持通行密钥");
            case "SecurityError":
                return T("请使用域名并通过 HTTPS 访问后再使用通行密钥");
            case "AbortError":
                return T("操作已取消");
        }
        return (e && (e.msg || (e.name === "BadOptionsError" && e.message))) || T("操作失败，请重试");
    }

    window.UserWebAuthn = {
        b64uToBuf: b64uToBuf,
        bufToB64u: bufToB64u,
        supported: supported,
        conditionalAvailable: conditionalAvailable,
        create: create,
        get: get,
        signalUnknown: signalUnknown,
        signalAccepted: signalAccepted,
        errorText: errorText
    };
})();
