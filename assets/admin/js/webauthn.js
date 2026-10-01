/**
 * 后台 passkey(WebAuthn) 浏览器端公共封装：base64url <-> ArrayBuffer 转换，
 * 以及 create()/get() 仪式的参数编解码。登入页、锁屏页、通行密钥管理共用。
 * 暴露为 window.AdminWebAuthn。
 */
(function () {
    "use strict";

    function b64uToBuf(s) {
        s = String(s || "").replace(/-/g, "+").replace(/_/g, "/");
        var pad = s.length % 4;
        if (pad) {
            s += "====".slice(pad);
        }
        var bin = atob(s);
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) {
            buf[i] = bin.charCodeAt(i);
        }
        return buf.buffer;
    }

    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf);
        var bin = "";
        for (var i = 0; i < bytes.length; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    }

    function supported() {
        return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
    }

    // 注册：把服务端下发的 create 选项转成浏览器需要的形态，回传可直接上送的 base64url 字段。
    async function create(opts) {
        var pk = {
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
            excludeCredentials: (opts.excludeCredentials || []).map(function (c) {
                return { type: c.type, id: b64uToBuf(c.id) };
            })
        };
        var cred = await navigator.credentials.create({ publicKey: pk });
        return {
            id: cred.id,
            attestationObject: bufToB64u(cred.response.attestationObject),
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            transports: (cred.response.getTransports ? (cred.response.getTransports() || []).join(",") : "")
        };
    }

    // 断言：登入/解锁共用。
    async function get(opts) {
        var pk = {
            challenge: b64uToBuf(opts.challenge),
            timeout: opts.timeout,
            rpId: opts.rpId,
            userVerification: opts.userVerification || "preferred",
            allowCredentials: (opts.allowCredentials || []).map(function (c) {
                return { type: c.type, id: b64uToBuf(c.id) };
            })
        };
        var cred = await navigator.credentials.get({ publicKey: pk });
        return {
            id: cred.id,
            authenticatorData: bufToB64u(cred.response.authenticatorData),
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            signature: bufToB64u(cred.response.signature),
            userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : ""
        };
    }

    // 把 create/get 抛出的异常转成可读文案。
    function errorText(e) {
        if (!e) {
            return "操作失败";
        }
        if (e.name === "NotAllowedError") {
            return "操作已取消或超时";
        }
        if (e.name === "InvalidStateError") {
            return "该设备可能已注册过通行密钥";
        }
        if (e.name === "SecurityError") {
            return "通行密钥要求 HTTPS 安全环境";
        }
        return e.message || String(e);
    }

    window.AdminWebAuthn = {
        b64uToBuf: b64uToBuf,
        bufToB64u: bufToB64u,
        supported: supported,
        create: create,
        get: get,
        errorText: errorText
    };
})();
