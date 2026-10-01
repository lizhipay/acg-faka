/**
 * 后台闲置自动锁屏（客户端部分）。伺服器才是真正的边界（manage_session.last_active_time
 * 逾时后 API 回 42010、页面导向锁屏页）；本脚本负责：闲置到点确认已锁后跳锁屏页、
 * 真实交互时节流 ping 续命、以及全局捕获任何 42010 回应转跳锁屏页。
 *
 * 放在 Footer.html（#pjax-container 之外）以独立 <script> 载入，pjax 换页不销毁，
 * 计时器在整个完整页面生命周期内持续有效。
 */
(function () {
    "use strict";
    if (window.__adminLockInit) {
        return;
    }
    window.__adminLockInit = true;

    // util 以顶层 const 声明，不挂在 window 上，故用 typeof 判定而非 window.util。
    function hasUtil() {
        return typeof util !== "undefined" && util && typeof util.post === "function";
    }

    function onLockPage() {
        return location.pathname.indexOf("/admin/authentication/lock") === 0;
    }

    function toLock() {
        if (onLockPage()) {
            return;
        }
        var goto = encodeURIComponent(location.pathname + location.search);
        location.href = "/admin/authentication/lock?goto=" + goto;
    }

    // 全局捕获 42010（伺服器已判定锁定）：任何 util.post/util.get 命中即转跳。
    if (window.jQuery) {
        jQuery(document).ajaxComplete(function (e, xhr) {
            try {
                var r = JSON.parse(xhr.responseText);
                if (r && r.code === 42010) {
                    toLock();
                }
            } catch (_) {
            }
        });
    }

    var mins = parseInt(typeof getVar === "function" ? getVar("LOCK_TIMEOUT") : 0, 10);
    if (!mins || mins <= 0 || onLockPage()) {
        return; //锁屏关闭或本身就在锁屏页
    }

    var idleMs = mins * 60 * 1000;
    // ping 续命节流：须明显小于逾时的一半，否则逾时设得短时（如 1 分钟）正常操作也会被锁。
    var pingThrottleMs = Math.max(5000, Math.min(60000, Math.floor(idleMs / 3)));
    var last = Date.now();
    var lastPing = 0;
    var lastStatus = 0;
    var checking = false;

    function ping() {
        var now = Date.now();
        if (now - lastPing < pingThrottleMs || !hasUtil()) {
            return;
        }
        lastPing = now;
        util.post({
            url: "/admin/api/authentication/ping",
            loader: false,
            done: function (res) {
                if (res && res.data && res.data.locked) {
                    toLock();
                }
            },
            error: function () {
            },
            fail: function () {
            }
        });
    }

    // 闲置到点时的只读确认：不推进活动时间，仅在伺服器确实已锁时才跳转。
    // 多标签页场景下（本页闲置、他页仍活跃令会话未锁）据此不误跳、不丢未存内容。
    function checkAndLock() {
        if (checking || !hasUtil()) {
            if (!hasUtil()) {
                toLock(); //拿不到 util（异常情况），退回由伺服器把关：跳转后会要求解锁
            }
            return;
        }
        var now = Date.now();
        if (now - lastStatus < 15000) {
            return; //未锁时的轮询节流，避免空闲页每 5s 打一次
        }
        lastStatus = now;
        checking = true;
        util.post({
            url: "/admin/api/authentication/lockStatus",
            loader: false,
            done: function (res) {
                checking = false;
                if (res && res.data && res.data.locked) {
                    toLock();
                }
            },
            error: function () {
                checking = false;
            },
            fail: function () {
                checking = false;
            }
        });
    }

    function activity() {
        last = Date.now();
        ping();
    }

    // 只认指针/键盘型真实交互：element 自动滚动会派发 trusted scroll 事件，
    // 把 scroll 当活动会让自动滚动的面板（客服、实时日志）令会话永不锁，故不监听 scroll。
    ["mousemove", "mousedown", "keydown", "wheel", "touchstart", "click"].forEach(function (ev) {
        window.addEventListener(ev, activity, { passive: true, capture: true });
    });

    setInterval(function () {
        if (Date.now() - last >= idleMs) {
            checkAndLock();
        }
    }, 5000);
})();
