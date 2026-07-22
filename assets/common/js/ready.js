window._data_var = {};

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
