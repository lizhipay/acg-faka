window._data_var = {};

function documentReady(callback) {
    if (document.readyState === "complete" || document.readyState === "interactive") {
        callback();
    } else {
        document.addEventListener("DOMContentLoaded", callback, false);
    }
}

function ready(call) {
    documentReady(() => {
        layui.use('form', function () {
            if (typeof call === "function") {
                call();
                return;
            }
            if (!call) return;

            document.querySelectorAll('script[ready]').forEach(s => s.remove());

            const s = document.createElement('script');
            s.src = call;
            s.async = true;
            s.setAttribute('ready', 'true');
            document.body.appendChild(s);

            util.debug(`RELOAD -> ${call}`, "#10d18f");
        });
    });
}

function setVar(name, data) {
    window._data_var[name] = data;
}

function getVar(name) {
    return window._data_var[name];
}

function i18n(text) {
    return text;
}

function evalResults(code) {
    return eval('(' + code + ')');
}

function route(uri) {
    uri = uri.replace(/^\/+|\/+$/g, '');
    const pathname = location.pathname;
    const rt = pathname.trim().split("/").filter(Boolean);
    if (rt[0] !== "plugin") {
        return "";
    }

    if (rt[1] === undefined) {
        return "";
    }

    if (!/^\d+$/.test(rt[1])) {
        //主站

        return `/plugin/${rt[1]}/${uri}`;
    } else {
        //分站
        if (rt[2] === undefined) {
            return "";
        }
        return `/plugin/${rt[1]}/${rt[2]}/${uri}`;
    }
}