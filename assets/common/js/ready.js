window._data_var = {};

const __cspNonce = document.currentScript ? (document.currentScript.nonce || '') : '';

function documentReady(callback) {
    if (document.readyState === "complete" || document.readyState === "interactive") {
        callback();
    } else {
        document.addEventListener("DOMContentLoaded", callback, false);
    }
}

const readyLoaderState = window.__adminReadyLoader ??= {
    queue: [],
    timer: null,
    batch: 0,
    lifecycleBound: false,
    generation: 0,
    activeLoads: new Map()
};
readyLoaderState.generation ??= 0;
readyLoaderState.activeLoads = readyLoaderState.activeLoads instanceof Map
    ? readyLoaderState.activeLoads
    : new Map();

function removeReadyControllerScripts() {
    document.querySelectorAll('script[ready], script[data-ready-controller]').forEach(script => script.remove());
}

function cancelReadyControllerLoads() {
    readyLoaderState.activeLoads.forEach(load => {
        load.cancelled = true;
        load.controllers.forEach(controller => {
            try {
                controller.abort();
            } catch (error) {
                // An already completed request needs no further cleanup.
            }
        });
        load.controllers.clear();
    });
    readyLoaderState.activeLoads.clear();
}

function bindReadyLifecycle() {
    if (readyLoaderState.lifecycleBound || typeof window.jQuery !== 'function') {
        return;
    }
    readyLoaderState.lifecycleBound = true;
    $(document).on('pjax:beforeReplace.adminReady', function (event) {
        readyLoaderState.generation++;
        if (readyLoaderState.timer !== null) {
            clearTimeout(readyLoaderState.timer);
            readyLoaderState.timer = null;
        }
        readyLoaderState.queue = [];
        cancelReadyControllerLoads();

        if (typeof Table !== 'undefined' && typeof Table.destroyAll === 'function') {
            Table.destroyAll(event.target);
        }
        $(document).trigger('admin:page:destroy', [{container: event.target}]);
        removeReadyControllerScripts();
    });
}

// Plugin templates may initialise directly through documentReady() without
// ever calling ready(). Bind the shared PJAX cleanup on every full page load so
// their Table instances are destroyed before #pjax-container is replaced too.
documentReady(bindReadyLifecycle);

function flushReadyQueue() {
    readyLoaderState.timer = null;
    const generation = readyLoaderState.generation;
    const calls = readyLoaderState.queue.splice(0)
        .filter(entry => {
            // Keep compatibility with a queue populated by the previous loader
            // if this source file is replaced during local development.
            return !entry || !Object.prototype.hasOwnProperty.call(entry, 'generation') || entry.generation === generation;
        })
        .map(entry => entry && Object.prototype.hasOwnProperty.call(entry, 'call') ? entry.call : entry);
    if (calls.length === 0) {
        return;
    }

    const execute = () => {
        if (generation !== readyLoaderState.generation) {
            return;
        }
        bindReadyLifecycle();
        removeReadyControllerScripts();
        const batch = ++readyLoaderState.batch;
        const sources = new Set();

        calls.forEach(call => {
            if (typeof call === 'function') {
                call();
                return;
            }
            if (typeof call !== 'string' || call === '' || sources.has(call)) {
                return;
            }
            sources.add(call);
            util.debug(`RELOAD -> ${call}`, "#10d18f");
        });

        if (generation !== readyLoaderState.generation) {
            return;
        }
        const sourceList = Array.from(sources);
        if (sourceList.length === 0) {
            $(document).trigger('admin:controllers:ready', [{
                batch: batch,
                generation: generation,
                sources: []
            }]);
            return;
        }

        // A removed <script src> may still execute after its network request
        // completes. Fetch first, then inject only while this PJAX generation is
        // current, so a late controller can never initialise the next page.
        const load = {generation: generation, cancelled: false, controllers: new Set()};
        readyLoaderState.activeLoads.set(batch, load);
        const requests = sourceList.map(source => {
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            if (controller) load.controllers.add(controller);
            return fetch(source, {
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined
            }).then(response => {
                if (!response.ok) {
                    throw new Error('Controller request failed with HTTP ' + response.status);
                }
                return response.text();
            }).then(code => ({source: source, code: code}), error => ({source: source, error: error}));
        });

        Promise.all(requests).then(results => {
            if (load.cancelled || generation !== readyLoaderState.generation) {
                return;
            }
            for (const result of results) {
                if (load.cancelled || generation !== readyLoaderState.generation) {
                    return;
                }
                if (result.error) {
                    if (result.error.name !== 'AbortError') {
                        $(document).trigger('admin:controller:error', [{
                            src: result.source,
                            batch: batch,
                            error: result.error
                        }]);
                    }
                    continue;
                }
                const script = document.createElement('script');
                if (__cspNonce) script.nonce = __cspNonce;
                script.setAttribute('ready', 'true');
                script.setAttribute('data-ready-controller', 'true');
                script.setAttribute('data-ready-src', result.source);
                script.setAttribute('data-ready-batch', String(batch));
                script.setAttribute('data-ready-generation', String(generation));
                const sourceUrl = new URL(result.source, window.location.href).href.replace(/[\r\n]/g, '');
                script.textContent = result.code + '\n//# sourceURL=' + sourceUrl;
                document.body.appendChild(script);
            }
            if (!load.cancelled && generation === readyLoaderState.generation) {
                $(document).trigger('admin:controllers:ready', [{
                    batch: batch,
                    generation: generation,
                    sources: sourceList
                }]);
            }
        }).finally(() => {
            load.controllers.clear();
            if (readyLoaderState.activeLoads.get(batch) === load) {
                readyLoaderState.activeLoads.delete(batch);
            }
        });
    };

    if (window.layui?.use) {
        layui.use('form', execute);
    } else {
        execute();
    }
}

function ready(call) {
    documentReady(() => {
        if (!call) return;
        bindReadyLifecycle();
        readyLoaderState.queue.push({call: call, generation: readyLoaderState.generation});
        if (readyLoaderState.timer === null) {
            readyLoaderState.timer = setTimeout(flushReadyQueue, 0);
        }
    });
}

function setVar(name, data) {
    window._data_var[name] = data;
}

function getVar(name) {
    return window._data_var[name];
}

function i18n(text) {
    //表格 formatter 等调用方可能传入数字/对象，非字符串一律原样返回
    if (typeof text !== "string" || text === "") {
        return text;
    }
    const _lang = getVar("LANG");
    if (!_lang || _lang === "zh-cn") {
        return text;
    }
    const _dict = getVar("I18N");
    if (_dict && Object.prototype.hasOwnProperty.call(_dict, text)) {
        return _dict[text];
    }
    //兼容首尾空格：词条以去空格形式入库，命中后按原样保留两侧空白
    if (_dict) {
        const _trimmed = text.trim();
        if (_trimmed !== text && _trimmed !== "" && Object.prototype.hasOwnProperty.call(_dict, _trimmed)) {
            return text.replace(_trimmed, _dict[_trimmed]);
        }
    }
    //带标签的文案（后台常把图标写进配置值，如 <i class="…"></i> 推荐）：整串查不到时，
    //先看文本片段是不是已有现成译文，能全部命中就直接复用，省掉一次翻译调用
    if (text.indexOf("<") !== -1) {
        const _markup = _i18nMarkup(text, _dict);
        if (_markup !== null) {
            return _markup;
        }
    }
    _i18nMiss(text);
    return text;
}

//复用已有词条翻译 HTML 片段里的文本节点；只读字典不上报，
//有任一片段没译出来就返回 null，交回上层整串上报（富文本整段翻更有上下文）
function _i18nMarkup(text, dict) {
    const parts = text.split(/(<[^>]*>)/);
    if (parts.length < 2) {
        return null;
    }
    let hit = false, pending = false;
    for (let i = 0; i < parts.length; i++) {
        const part = parts[i];
        if (!part || part.charAt(0) === "<") {
            continue;
        }
        const seg = part.trim();
        if (!seg || !/[一-鿿]/.test(seg)) {
            continue;
        }
        if (dict && Object.prototype.hasOwnProperty.call(dict, seg)) {
            parts[i] = part.replace(seg, dict[seg]);
            hit = true;
            continue;
        }
        pending = true;
    }
    return (hit && !pending) ? parts.join("") : null;
}

//miss 收集：去重攒批，3秒 debounce 上报到 /user/api/lang/report(服务端复用同一 miss 队列与 LANG_MISS 钩子)
const _i18nMissState = {set: new Set(), timer: null, reported: new Set()};

function _i18nMiss(text) {
    if (typeof text !== "string" || text.length > 500 || !/[一-鿿]/.test(text)) {
        return;
    }
    //整段渲染好的（或被二次转义的）HTML 翻不出有意义的结果，只会污染词库，直接丢弃
    if (/<[a-zA-Z\/!]|&(?:amp|quot|lt|gt|#\d+);/.test(text)) {
        return;
    }
    //防翻译回环：接口返回的 msg 已由服务端翻译，前端组件会再调一次 i18n；
    //日文/繁体译文同样含汉字，不拦截就会被当成新的中文原文上报
    const _dict = getVar("I18N");
    if (_dict) {
        if (!window.__i18nReverse || window.__i18nReverseSrc !== _dict) {
            window.__i18nReverseSrc = _dict;
            window.__i18nReverse = new Set(Object.values(_dict));
        }
        if (window.__i18nReverse.has(text)) {
            return;
        }
    }
    if (_i18nMissState.reported.has(text) || _i18nMissState.set.size >= 50) {
        return;
    }
    _i18nMissState.set.add(text);
    if (_i18nMissState.timer === null) {
        _i18nMissState.timer = setTimeout(_i18nFlushMiss, 3000);
    }
}

function _i18nFlushMiss() {
    _i18nMissState.timer = null;
    if (_i18nMissState.set.size === 0) {
        return;
    }
    const list = Array.from(_i18nMissState.set);
    list.forEach(item => _i18nMissState.reported.add(item));
    _i18nMissState.set.clear();
    try {
        fetch("/user/api/lang/report", {
            method: "POST",
            keepalive: true,
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({list: list})
        }).catch(() => {
        });
    } catch (e) {
    }
}

//切换语言：写 cookie 后整页刷新，前后台共用
function setLang(lang) {
    document.cookie = "acg_lang=" + lang + ";max-age=315360000;path=/";
    window.location.reload();
}

//语言切换器：后台(#md-lang-toggle) 与 Cartoon(.uc-lang-btn) 共用一套事件委托
(function () {
    const bind = function () {
        const current = getVar("LANG") || "zh-cn";
        document.querySelectorAll("[data-lang-value]").forEach(function (el) {
            el.classList.toggle("active", el.getAttribute("data-lang-value") === current);
        });
        //切换器按钮上显示当前语言的短码，免得只看一个地球图标不知道现在是哪国语言
        const shortCode = {"zh-cn": "简", "zh-tw": "繁", "en": "EN", "ja": "日"}[current] || "";
        document.querySelectorAll("[data-lang-label]").forEach(function (el) {
            el.textContent = shortCode;
        });
        if (window.__langSwitchBound) {
            return;
        }
        window.__langSwitchBound = true;
        document.addEventListener("click", function (e) {
            const opt = e.target.closest ? e.target.closest("[data-lang-value]") : null;
            if (opt) {
                e.preventDefault();
                e.stopPropagation();
                setLang(opt.getAttribute("data-lang-value"));
                return;
            }
            //语言二级菜单：点击父项展开/收起（触屏与窄屏没有 hover）
            //选择器同时认 data-lang-parent，各主题不必为此再改公共 JS
            const langParent = e.target.closest ? e.target.closest(".uc-lang-parent, [data-lang-parent]") : null;
            if (langParent) {
                e.preventDefault();
                e.stopPropagation();
                const opened = langParent.classList.toggle("is-open");
                langParent.setAttribute("aria-expanded", opened ? "true" : "false");
                return;
            }
            document.querySelectorAll(".uc-lang-parent.is-open, [data-lang-parent].is-open").forEach(function (el) {
                el.classList.remove("is-open");
                el.setAttribute("aria-expanded", "false");
            });
            const toggle = e.target.closest ? e.target.closest("#md-lang-toggle, .uc-lang-btn") : null;
            const menus = document.querySelectorAll("#md-lang-menu, .uc-lang-menu");
            if (toggle) {
                e.preventDefault();
                e.stopPropagation();
                const box = toggle.parentElement;
                const menu = box ? box.querySelector("#md-lang-menu, .uc-lang-menu") : null;
                menus.forEach(function (m) {
                    if (m !== menu) {
                        m.classList.remove("show", "is-open");
                        m.style.display = "";
                    }
                });
                if (menu) {
                    const opened = menu.style.display === "block";
                    menu.style.display = opened ? "" : "block";
                    menu.classList.toggle("show", !opened);
                }
                return;
            }
            menus.forEach(function (m) {
                m.classList.remove("show", "is-open");
                m.style.display = "";
            });
        });
    };
    if (document.readyState !== "loading") {
        bind();
    } else {
        document.addEventListener("DOMContentLoaded", bind);
    }
    document.addEventListener("pjax:complete", bind);
})();

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
