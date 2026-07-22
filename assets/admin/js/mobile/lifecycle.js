(function (window, document) {
    'use strict';

    var api = window.AdminMobile;
    if (!api) return;
    var mounted = false;
    var routeKey = '';
    var cleanups = [];
    var activeWorkflows = [];
    var refreshFrame = 0;

    function runCleanups() {
        cleanups.splice(0).reverse().forEach(function (cleanup) {
            try { cleanup(); } catch (error) { console.error(error); }
        });
    }

    function applyLayout(enabled) {
        var value = enabled ? 'mobile' : 'desktop';
        document.documentElement.setAttribute('data-admin-layout', value);
        if (document.body) document.body.setAttribute('data-admin-layout', value);
    }

    function updateRecipe(extra) {
        var context = api.getContext(extra);
        var previous = api.activeRecipe || null;
        api.activeRecipe = api.matchRecipe(context);
        if (api.activeRecipe && api.activeRecipe.id) {
            document.documentElement.setAttribute('data-admin-mobile-recipe', api.activeRecipe.id);
            if (document.body) document.body.setAttribute('data-admin-mobile-recipe', api.activeRecipe.id);
        } else {
            document.documentElement.removeAttribute('data-admin-mobile-recipe');
            if (document.body) document.body.removeAttribute('data-admin-mobile-recipe');
        }
        if (previous !== api.activeRecipe) {
            api.emit('admin:mobile:recipechange', {recipe: api.activeRecipe, previous: previous, context: context});
        }
        activeWorkflows.forEach(function (workflow) {
            if (typeof workflow.onRoute === 'function') workflow.onRoute(context, api.activeRecipe);
        });
    }

    function mountWorkflows() {
        var context = api.getContext({recipe: api.activeRecipe});
        activeWorkflows = api.workflows.filter(function (workflow) {
            if (typeof workflow.match === 'function') return workflow.match(context) === true;
            if (workflow.match || workflow.routes) return api.matches(workflow.match || {routes: workflow.routes}, context);
            return true;
        });
        activeWorkflows.forEach(function (workflow) {
            if (typeof workflow.mount === 'function') workflow.mount(context);
        });
    }

    function unmountWorkflows() {
        var context = api.getContext({recipe: api.activeRecipe});
        activeWorkflows.splice(0).forEach(function (workflow) {
            if (typeof workflow.unmount === 'function') workflow.unmount(context);
        });
    }

    function mount(reason) {
        if (mounted) {
            var next = window.location.pathname + window.location.search;
            if (next !== routeKey) routeChanged(reason || 'refresh');
            else {
                applyLayout(true);
                if (reason === 'viewport') return;
                if (api.shell) api.shell.refresh();
                if (api.navigation) api.navigation.refresh();
                if (api.fallback) api.fallback.refresh();
            }
            return;
        }
        mounted = true;
        applyLayout(true);
        if (api.shell) api.shell.mount();
        if (api.navigation) api.navigation.mount();
        if (api.overlay) api.overlay.mount();
        if (api.fallback) api.fallback.mount();
        updateRecipe();
        // The first Shell render happens before the route recipe is known.
        // Refresh once more so mobile pages use their concise APP title.
        if (api.shell) api.shell.refresh();
        mountWorkflows();
        routeKey = window.location.pathname + window.location.search;
        api.emit('admin:mobile:mount', {reason: reason || 'mount', context: api.getContext()});
    }

    function unmount(reason) {
        if (!mounted) {
            applyLayout(false);
            if (api.shell) {
                api.shell.mount();
                api.shell.unmount();
            }
            return;
        }
        mounted = false;
        if (api.overlay) api.overlay.closeAll({silentHistory: true});
        unmountWorkflows();
        if (api.fallback) api.fallback.unmount();
        if (api.navigation) api.navigation.unmount();
        if (api.overlay) api.overlay.unmount();
        if (api.shell) api.shell.unmount();
        runCleanups();
        applyLayout(false);
        document.documentElement.removeAttribute('data-admin-mobile-recipe');
        if (document.body) document.body.removeAttribute('data-admin-mobile-recipe');
        api.emit('admin:mobile:unmount', {reason: reason || 'unmount', context: api.getContext()});
    }

    function routeChanged(reason) {
        if (!mounted) return;
        var next = window.location.pathname + window.location.search;
        var changed = next !== routeKey;
        routeKey = next;
        if (api.overlay) api.overlay.closeAll({silentHistory: true});
        unmountWorkflows();
        updateRecipe();
        mountWorkflows();
        if (api.shell) api.shell.refresh();
        if (api.navigation) api.navigation.refresh();
        if (api.fallback) api.fallback.refresh();
        api.emit('admin:mobile:routechange', {reason: reason || 'route', changed: changed, context: api.getContext()});
    }

    api.isMounted = function () { return mounted; };
    api.addCleanup = function (cleanup) {
        if (typeof cleanup === 'function') cleanups.push(cleanup);
        return cleanup;
    };
    api.refreshRecipe = function (extra) {
        if (!mounted) return;
        updateRecipe(extra);
        if (api.fallback) api.fallback.refresh();
    };
    api.refreshWorkflows = function () {
        if (!mounted) return;
        unmountWorkflows();
        mountWorkflows();
    };
    api.mount = mount;
    api.unmount = unmount;
    api.refresh = function (reason) {
        if (refreshFrame) window.cancelAnimationFrame(refreshFrame);
        refreshFrame = window.requestAnimationFrame(function () {
            refreshFrame = 0;
            if (api.isEnabled()) mount(reason); else unmount(reason);
        });
    };

    if (window.jQuery) {
        window.jQuery(document)
            .off('.adminMobileLifecycle')
            .on('pjax:complete.adminMobileLifecycle pjax:end.adminMobileLifecycle', function (event) { api.refresh(event.type); })
            .on('pjax:beforeReplace.adminMobileLifecycle', function () {
                if (api.fallback) api.fallback.clear();
            });
    } else {
        ['pjax:complete', 'pjax:end'].forEach(function (eventName) {
            document.addEventListener(eventName, function () { api.refresh(eventName); });
        });
        document.addEventListener('pjax:beforeReplace', function () {
            if (api.fallback) api.fallback.clear();
        });
    }
    document.addEventListener('DOMContentLoaded', function () { api.refresh('ready'); }, {once: true});
    if (document.readyState !== 'loading') api.refresh('load');
}(window, document));
