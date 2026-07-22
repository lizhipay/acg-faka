(function (window, document) {
    'use strict';

    var api = window.AdminMobile = window.AdminMobile || {};
    var STORAGE_KEY = 'admin-layout-mode';
    var BREAKPOINT = 992;
    var recipes = api.recipes || [];
    var workflows = api.workflows || [];
    var media = window.matchMedia ? window.matchMedia('(max-width: 991.98px)') : null;
    var resizeFrame = 0;
    var focusVisibilityTimer = 0;
    var stableViewportHeight = window.innerHeight;
    var stableViewportWidth = window.innerWidth;
    var keyboardOpen = false;

    function readMode() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === 'desktop' ? 'desktop' : 'auto';
        } catch (error) {
            return 'auto';
        }
    }

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, {detail: detail || {}}));
    }

    function normalizePath(value) {
        if (!value) return '';
        try {
            var parsed = new URL(String(value), window.location.origin);
            return (parsed.pathname.replace(/\/+$/, '') || '/') + parsed.search;
        } catch (error) {
            return String(value).replace(/\/+$/, '') || '/';
        }
    }

    function matches(value, candidates, prefix) {
        if (!candidates) return false;
        if (!Array.isArray(candidates)) candidates = [candidates];
        return candidates.some(function (candidate) {
            if (candidate instanceof RegExp) return candidate.test(value);
            if (typeof candidate === 'function') return candidate(value);
            candidate = normalizePath(candidate);
            if (candidate.indexOf('*') !== -1) {
                var expression = candidate.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
                return new RegExp('^' + expression + '$').test(value);
            }
            return prefix ? value.indexOf(candidate) === 0 : value === candidate;
        });
    }

    function matchesTableId(value, candidates) {
        if (!value || !candidates) return false;
        if (!Array.isArray(candidates)) candidates = [candidates];
        value = String(value).replace(/^#/, '');
        return candidates.some(function (candidate) {
            if (candidate instanceof RegExp) return candidate.test(value);
            if (typeof candidate === 'function') return candidate(value);
            return String(candidate || '').replace(/^#/, '') === value;
        });
    }

    function recipeMatches(recipe, context) {
        var match = recipe.match || recipe;
        var route = normalizePath(context.route || window.location.pathname).split('?')[0];
        var queryUrl = normalizePath(context.queryUrl || '');
        var tableId = context.tableId || '';
        if (matches(route, match.routes, false)) return true;
        if (matches(route, match.routePrefixes, true)) return true;
        if (queryUrl && matches(queryUrl, match.queryUrls, false)) return true;
        if (queryUrl && matches(queryUrl, match.queryUrlPrefixes, true)) return true;
        if (matchesTableId(tableId, match.tableIds)) return true;
        return typeof match.test === 'function' ? match.test(context) === true : false;
    }

    function register(collection, item, queueName) {
        if (!item) return item;
        var id = item.id || item.name;
        var index = collection.findIndex(function (entry) { return id && (entry.id || entry.name) === id; });
        if (index >= 0) collection[index] = item; else collection.push(item);
        window[queueName] = collection;
        if (queueName === 'AdminMobileWorkflowQueue' && typeof api.refreshWorkflows === 'function') api.refreshWorkflows();
        else if (typeof api.refreshRecipe === 'function') api.refreshRecipe();
        return item;
    }

    function updateViewportVars() {
        var viewport = window.visualViewport;
        var height = viewport ? viewport.height : window.innerHeight;
        var offsetTop = viewport ? viewport.offsetTop : 0;
        var widthChanged = Math.abs(window.innerWidth - stableViewportWidth) > 48;
        if (widthChanged) {
            stableViewportWidth = window.innerWidth;
            stableViewportHeight = window.innerHeight;
        }
        var focused = document.activeElement;
        var editable = focused && (
            /^(INPUT|TEXTAREA|SELECT)$/.test(focused.tagName) ||
            focused.isContentEditable === true ||
            focused.getAttribute('role') === 'textbox'
        );
        var visualKeyboard = Math.max(0, window.innerHeight - height - offsetTop);
        if (!editable && !keyboardOpen && visualKeyboard <= 80) {
            stableViewportHeight = window.innerHeight;
        }
        var resizedKeyboard = (editable || keyboardOpen) ? Math.max(0, stableViewportHeight - height - offsetTop) : 0;
        keyboardOpen = (editable || keyboardOpen) && Math.max(visualKeyboard, resizedKeyboard) > 80;
        document.documentElement.style.setProperty('--admin-mobile-vh', height + 'px');
        // Only the portion covered inside the layout viewport is used as a
        // bottom offset. When Chrome resizes the layout viewport, fixed
        // elements already sit above the keyboard and must not be lifted twice.
        document.documentElement.style.setProperty('--admin-mobile-keyboard', visualKeyboard + 'px');
        document.documentElement.style.setProperty('--admin-mobile-offset-top', offsetTop + 'px');
        document.documentElement.toggleAttribute('data-admin-mobile-keyboard-open', keyboardOpen && (media ? media.matches : window.innerWidth < BREAKPOINT));
    }

    function scheduleViewportUpdate() {
        if (resizeFrame) return;
        resizeFrame = window.requestAnimationFrame(function () {
            resizeFrame = 0;
            updateViewportVars();
            if (typeof api.refresh === 'function') api.refresh('viewport');
            emit('admin:mobile:viewportchange', api.getContext());
        });
    }

    function keepPageFieldVisible(event) {
        scheduleViewportUpdate();
        window.clearTimeout(focusVisibilityTimer);
        var target = event && event.target;
        if (!target || !(media ? media.matches : window.innerWidth < BREAKPOINT)) return;
        if (!target.matches('input:not([type="hidden"]), textarea, select, [contenteditable="true"], [role="textbox"]')) return;
        if (!target.closest('#data-form') || target.closest('.layui-layer, [data-admin-mobile-overlay-host]')) return;

        focusVisibilityTimer = window.setTimeout(function () {
            if (!target.isConnected || document.activeElement !== target) return;
            var actions = document.querySelector('[data-admin-mobile-native-actions]');
            if (!actions || actions.offsetParent === null) return;
            var viewport = window.visualViewport;
            var viewportTop = viewport ? viewport.offsetTop : 0;
            var viewportBottom = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
            var appbar = document.querySelector('[data-admin-mobile-appbar]');
            var topLimit = Math.max(viewportTop, appbar && appbar.offsetParent !== null ? appbar.getBoundingClientRect().bottom : 0) + 16;
            var bottomLimit = Math.min(viewportBottom, actions.getBoundingClientRect().top) - 16;
            var rect = target.getBoundingClientRect();
            if (rect.top >= topLimit && rect.bottom <= bottomLimit) return;
            var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            target.scrollIntoView({block: 'center', inline: 'nearest', behavior: reduced ? 'auto' : 'smooth'});
        }, 120);
    }

    function releasePageField(event) {
        window.clearTimeout(focusVisibilityTimer);
        focusVisibilityTimer = 0;
        scheduleViewportUpdate(event);
    }

    api.version = '1.0.0';
    api.storageKey = STORAGE_KEY;
    api.breakpoint = BREAKPOINT;
    api.recipes = recipes;
    api.workflows = workflows;
    api.emit = emit;
    api.normalizePath = normalizePath;
    api.getLayoutMode = readMode;
    api.isViewportEligible = function () {
        return media ? media.matches : window.innerWidth < BREAKPOINT;
    };
    api.isEnabled = function () {
        return api.isViewportEligible() && readMode() !== 'desktop';
    };
    api.getContext = function (extra) {
        return Object.assign({
            route: window.location.pathname,
            url: window.location.href,
            mode: readMode(),
            enabled: api.isEnabled(),
            viewport: {width: window.innerWidth, height: window.innerHeight}
        }, extra || {});
    };
    api.setLayoutMode = function (mode) {
        mode = mode === 'desktop' ? 'desktop' : 'auto';
        try { window.localStorage.setItem(STORAGE_KEY, mode); } catch (error) {}
        if (typeof api.refresh === 'function') api.refresh('layout-mode');
        return mode;
    };
    api.registerRecipe = function (recipe) {
        return register(recipes, recipe, 'AdminMobileRecipeQueue');
    };
    api.registerWorkflow = function (workflow) {
        return register(workflows, workflow, 'AdminMobileWorkflowQueue');
    };
    api.matches = function (match, context) {
        return recipeMatches({match: match || {}}, api.getContext(context));
    };
    api.matchRecipe = function (context) {
        var hasTableContext = Boolean(context && (
            Object.prototype.hasOwnProperty.call(context, 'queryUrl') ||
            Object.prototype.hasOwnProperty.call(context, 'tableId')
        ));
        context = api.getContext(context);

        if (hasTableContext) {
            var queryUrl = normalizePath(context.queryUrl || '');
            var tableId = context.tableId || '';
            var exact = queryUrl && recipes.find(function (recipe) {
                var match = recipe.match || recipe;
                return matches(queryUrl, match.queryUrls, false);
            });
            if (exact) return exact;

            return recipes.find(function (recipe) {
                var match = recipe.match || recipe;
                return (queryUrl && matches(queryUrl, match.queryUrlPrefixes, true)) ||
                    matchesTableId(tableId, match.tableIds);
            }) || null;
        }

        return recipes.find(function (recipe) { return recipeMatches(recipe, context); }) || null;
    };
    api.getActiveRecipe = function () { return api.activeRecipe || null; };

    (window.AdminMobileRecipeQueue || []).slice().forEach(api.registerRecipe);
    (window.AdminMobileWorkflowQueue || []).slice().forEach(api.registerWorkflow);
    window.AdminMobileRecipeQueue = recipes;
    window.AdminMobileWorkflowQueue = workflows;

    updateViewportVars();
    if (media && media.addEventListener) media.addEventListener('change', scheduleViewportUpdate);
    else if (media && media.addListener) media.addListener(scheduleViewportUpdate);
    window.addEventListener('resize', scheduleViewportUpdate, {passive: true});
    window.addEventListener('orientationchange', scheduleViewportUpdate, {passive: true});
    document.addEventListener('focusin', keepPageFieldVisible, {passive: true});
    document.addEventListener('focusout', releasePageField, {passive: true});
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', scheduleViewportUpdate, {passive: true});
        window.visualViewport.addEventListener('scroll', scheduleViewportUpdate, {passive: true});
    }
}(window, document));
