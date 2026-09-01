(function () {
    'use strict';

    if (window.__acgBind) {
        return;
    }

    var ALLOW = [
        'acg.API.tradePerform',
        'acg.API.tradeAmountPerform',
        'acg.API.draftCardPerform',
        'acg.API.draftCardCheckbox',
        'goAlipay',
        'agreeTerms',
        'message.info',
        'sendKeywords'
    ];

    function resolve(name) {
        if (ALLOW.indexOf(name) === -1) {
            return null;
        }
        var parts = name.split('.');
        var ctx = window;
        for (var i = 0; i < parts.length; i++) {
            if (ctx == null) {
                ctx = null;
                break;
            }
            ctx = ctx[parts[i]];
        }
        if (typeof ctx === 'function') {
            return {fn: ctx, owner: owner(window, parts)};
        }
        //支付模板里的 goAlipay、插件里的 sendKeywords 都是 let/const 声明的，
        //只存在于全局词法环境、不挂在 window 上，从这里走 window 永远找不到。
        //间接 eval 在全局作用域求值，能取到它们；name 只可能是上面 ALLOW 里的
        //字面量常量，不含任何来自页面或用户的输入。
        try {
            var fn = (0, eval)(name);
            if (typeof fn !== 'function') {
                return null;
            }
            return {fn: fn, owner: parts.length > 1 ? (0, eval)(parts.slice(0, -1).join('.')) : null};
        } catch (e) {
            return null;
        }
    }

    //acg.API.tradePerform 内部要用 this.getPostData()，this 必须是 acg.API 而不是
    //被点的元素——内联 onclick 写成 acg.API.tradePerform(id) 时 this 天然就是 acg.API。
    //只有单段名字（goAlipay 这种）才沿用内联处理器的语义，把元素当 this。
    function owner(root, parts) {
        if (parts.length < 2) {
            return null;
        }
        var ctx = root;
        for (var i = 0; i < parts.length - 1; i++) {
            if (ctx == null) {
                return null;
            }
            ctx = ctx[parts[i]];
        }
        return ctx == null ? null : ctx;
    }

    function invoke(target, el) {
        if (!target) {
            return;
        }
        target.fn.apply(target.owner || el, args(el));
    }

    function args(el) {
        var raw = el.getAttribute('data-acg-args');
        if (!raw) {
            return [];
        }
        try {
            var v = JSON.parse(raw);
            return Array.isArray(v) ? v : [v];
        } catch (e) {
            return [raw];
        }
    }

    function safeUrl(u) {
        if (!u) {
            return '';
        }
        if (u.charAt(0) === '/' || u.indexOf('data:image/') === 0) {
            return u;
        }
        return /^https?:\/\//i.test(u) ? u : '';
    }

    function fallback(img) {
        if (img.getAttribute('data-acg-fallback-done')) {
            return;
        }
        img.setAttribute('data-acg-fallback-done', '1');
        var to = img.getAttribute('data-acg-fallback') || '';
        if (to === 'hide') {
            img.style.display = 'none';
            return;
        }
        if (to === 'remove') {
            img.remove();
            return;
        }
        if (to === 'hidden') {
            img.style.visibility = 'hidden';
            return;
        }
        if (to === 'placeholder') {
            var ph = document.createElement('span');
            ph.className = img.getAttribute('data-acg-ph-class') || '';
            ph.textContent = img.getAttribute('data-ph') || '#';
            img.replaceWith(ph);
            return;
        }
        var url = safeUrl(to);
        if (url) {
            img.src = url;
        }
        var un = img.getAttribute('data-acg-fallback-unclass');
        if (un) {
            var bits = un.split(':');
            var host = img.closest ? img.closest(bits[0]) : null;
            if (host && bits[1]) {
                host.classList.remove(bits[1]);
            }
        }
    }

    function sweep(root) {
        var list = (root || document).querySelectorAll('img[data-acg-fallback]:not([data-acg-fallback-done])');
        for (var i = 0; i < list.length; i++) {
            var img = list[i];
            if (img.complete && img.naturalWidth === 0) {
                fallback(img);
            }
        }
    }

    //load 和 error 一样不冒泡，必须用捕获阶段
    document.addEventListener('load', function (e) {
        var t = e.target;
        if (t && t.hasAttribute && t.hasAttribute('data-acg-loaded-clear')) {
            t.className = '';
        }
    }, true);

    document.addEventListener('error', function (e) {
        var t = e.target;
        if (t && t.tagName === 'IMG' && t.hasAttribute('data-acg-fallback')) {
            fallback(t);
        }
    }, true);

    //渲染出口把占位的 javascript:void(0) 换成了 href="#"，这里阻止它跳到页首
    document.addEventListener('click', function (e) {
        var noop = e.target && e.target.closest ? e.target.closest('[data-acg-noop]') : null;
        if (noop) {
            e.preventDefault();
        }
    });

    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-acg-proxy],[data-acg-refresh],[data-acg-open],[data-acg-action]') : null;
        if (!el) {
            return;
        }

        var proxy = el.getAttribute('data-acg-proxy');
        if (proxy) {
            var target = document.querySelector(proxy);
            if (target) {
                target.click();
            }
            return;
        }

        var refresh = el.getAttribute('data-acg-refresh');
        if (refresh) {
            var base = safeUrl(refresh);
            if (base) {
                el.src = base + (base.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
            }
            return;
        }

        var open = el.getAttribute('data-acg-open');
        if (open) {
            var href = safeUrl(open);
            if (href) {
                window.open(href);
            }
            return;
        }

        var action = el.getAttribute('data-acg-action');
        if (action) {
            invoke(resolve(action), el);
        }
    });

    document.addEventListener('change', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-acg-change]') : null;
        if (!el) {
            return;
        }
        invoke(resolve(el.getAttribute('data-acg-change')), el);
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.hasAttribute && form.hasAttribute('data-acg-prevent')) {
            e.preventDefault();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            sweep(document);
        });
    } else {
        sweep(document);
    }

    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType !== 1) {
                        continue;
                    }
                    if (n.tagName === 'IMG') {
                        if (n.hasAttribute('data-acg-fallback') && n.complete && n.naturalWidth === 0) {
                            fallback(n);
                        }
                    } else if (n.querySelectorAll) {
                        sweep(n);
                    }
                }
            }
        }).observe(document.documentElement, {childList: true, subtree: true});
    }

    window.__acgBind = {sweep: sweep, allow: ALLOW};
})();
