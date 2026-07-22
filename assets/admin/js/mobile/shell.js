(function (window, document) {
    'use strict';

    var api = window.AdminMobile;
    if (!api) return;
    var root = null;
    var clickHandler = null;
    var searchAction = null;
    var searchSource = '';
    var searchPriority = -1;
    var searchRoute = '';
    var pendingDialogControl = '';
    var dialogOverlayIds = {appearance: '', search: ''};
    var overlayOpenHandler = null;
    var overlayCloseHandler = null;
    var storeStatusObserver = null;
    var storeStatusSource = null;
    var themeMedia = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var dialogControls = {
        appearance: {selector: '[data-admin-mobile-appearance]', targetId: 'admin-mobile-appearance-sheet'},
        search: {selector: '[data-admin-mobile-search]', targetId: 'admin-mobile-search-sheet'}
    };

    function markup() {
        return '<div id="admin-mobile-shell" class="admin-mobile-shell" aria-hidden="true">' +
            '<button class="admin-mobile-restore" type="button" data-admin-mobile-layout="auto">返回手机版</button>' +
            '<header class="admin-mobile-appbar" data-admin-mobile-appbar>' +
            '<div class="admin-mobile-appbar__top"><div class="admin-mobile-appbar__heading"><strong data-admin-mobile-title>后台管理</strong><span class="admin-mobile-enterprise-badge" data-admin-mobile-enterprise hidden>企业版</span></div>' +
            '<button type="button" class="admin-mobile-icon-button" data-admin-mobile-appearance aria-label="外观模式" aria-haspopup="dialog" aria-expanded="false" aria-controls="admin-mobile-appearance-sheet"><span class="material-icons-outlined" aria-hidden="true">contrast</span></button>' +
            '<a class="admin-mobile-icon-button admin-mobile-store-button" data-admin-mobile-store href="/admin/store/home" aria-label="应用商店"><span class="material-icons-outlined" aria-hidden="true">storefront</span></a></div>' +
            '</header><nav class="admin-mobile-context-tabs" data-admin-mobile-context-tabs aria-label="当前功能导航" hidden></nav><nav class="admin-mobile-bottom-nav" data-admin-mobile-bottom-nav aria-label="后台主要导航">' +
            '<a href="/admin/dashboard/index" data-admin-mobile-nav="home"><span class="material-icons-outlined" aria-hidden="true">space_dashboard</span><span>首页</span></a>' +
            '<a href="/admin/order/index" data-admin-mobile-nav="orders"><span class="material-icons-outlined" aria-hidden="true">receipt_long</span><span>订单</span></a>' +
            '<a href="/admin/ticket/index" data-admin-mobile-nav="tickets"><span class="admin-mobile-nav-icon"><span class="material-icons-outlined" aria-hidden="true">support_agent</span><span class="admin-mobile-ticket-badge ticket-admin-badge" aria-label="没有待处理工单" hidden>0</span></span><span>工单</span></a>' +
            '<a href="/admin/user/index" data-admin-mobile-nav="members"><span class="material-icons-outlined" aria-hidden="true">group</span><span>会员</span></a>' +
            '<button type="button" data-admin-mobile-all aria-haspopup="dialog" aria-expanded="false"><span class="material-icons-outlined" aria-hidden="true">apps</span><span>全部</span></button>' +
            '</nav><div class="admin-mobile-backdrop" data-admin-mobile-backdrop hidden></div>' +
            '<div class="admin-mobile-overlay-host" data-admin-mobile-overlay-host></div></div>';
    }

    function ensure() {
        root = document.getElementById('admin-mobile-shell');
        if (!root) {
            document.body.insertAdjacentHTML('beforeend', markup());
            root = document.getElementById('admin-mobile-shell');
        }
        return root;
    }

    function routeKey() {
        return window.location.pathname + window.location.search;
    }

    function setDialogControlState(name, expanded, element) {
        var definition = dialogControls[name];
        if (!definition) return;
        var shell = ensure();
        var button = shell.querySelector(definition.selector);
        if (!button) return;
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-controls', definition.targetId);
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (element) element.id = definition.targetId;
    }

    function runDialogControl(name, action) {
        pendingDialogControl = name;
        try { return action(); }
        finally { pendingDialogControl = ''; }
    }

    function handleOverlayOpen(event) {
        var detail = event.detail || {};
        var name = detail.id === 'appearance' ? 'appearance' : pendingDialogControl;
        if (!dialogControls[name] || !detail.element) return;
        dialogOverlayIds[name] = String(detail.id || '');
        setDialogControlState(name, true, detail.element);
    }

    function handleOverlayClose(event) {
        var detail = event.detail || {};
        Object.keys(dialogOverlayIds).forEach(function (name) {
            if (!dialogOverlayIds[name] || dialogOverlayIds[name] !== String(detail.id || '')) return;
            dialogOverlayIds[name] = '';
            setDialogControlState(name, false);
        });
    }

    function syncDialogControls() {
        Object.keys(dialogControls).forEach(function (name) {
            var definition = dialogControls[name];
            var element = document.getElementById(definition.targetId);
            setDialogControlState(name, Boolean(element && element.isConnected), element);
        });
    }

    function resetSearch() {
        searchAction = null;
        searchSource = '';
        searchPriority = -1;
        searchRoute = routeKey();
        renderSearch();
    }

    function renderSearch() {
        var shell = ensure();
        var button = shell.querySelector('[data-admin-mobile-search]');
        if (!button) return;
        var placeholder = button.querySelector('[data-admin-mobile-search-placeholder]');
        var count = button.querySelector('[data-admin-mobile-search-count]');
        var trail = button.querySelector('[data-admin-mobile-search-trail]');
        var options = searchAction || {};
        var label = options.placeholder || '搜索后台功能';
        var activeCount = Math.max(0, Number(options.count || 0) || 0);
        placeholder.textContent = label;
        button.setAttribute('aria-label', activeCount > 0 ? label + '，已启用 ' + activeCount + ' 个筛选条件' : label);
        count.hidden = activeCount < 1;
        count.textContent = activeCount > 99 ? '99+' : String(activeCount);
        trail.textContent = searchAction ? 'tune' : 'apps';
        button.classList.toggle('has-filters', activeCount > 0);
    }

    function currentTitle() {
        if (api.activeRecipe && api.activeRecipe.title) return String(api.activeRecipe.title).trim();
        var title = document.querySelector('#kt_toolbar .md-page-title, #pjax-container h1, #pjax-container h2');
        if (title && title.textContent.trim()) return title.textContent.trim();
        return (document.title || '后台管理').split('-')[0].trim();
    }

    function syncStoreStatus() {
        var shell = ensure();
        var badge = shell.querySelector('[data-admin-mobile-enterprise]');
        var storeLink = shell.querySelector('[data-admin-mobile-store]');
        var enterprise = Boolean(storeStatusSource && /(?:企业版|企業版)/.test(storeStatusSource.textContent || ''));
        if (badge) badge.hidden = !enterprise;
        if (storeLink) {
            var active = /^\/admin\/store(?:\/|$)/.test(window.location.pathname);
            if (active) storeLink.setAttribute('aria-current', 'page');
            else storeLink.removeAttribute('aria-current');
        }
    }

    function observeStoreStatus() {
        var source = document.querySelector('#kt_header .store-text');
        if (source !== storeStatusSource) {
            if (storeStatusObserver) storeStatusObserver.disconnect();
            storeStatusSource = source;
            storeStatusObserver = null;
            if (source) {
                storeStatusObserver = new MutationObserver(syncStoreStatus);
                storeStatusObserver.observe(source, {childList: true, subtree: true, characterData: true});
            }
        }
        syncStoreStatus();
    }

    function contextTabAllowed(link, boundary) {
        var node = link;
        while (node) {
            if (
                node.hidden ||
                node.getAttribute('aria-hidden') === 'true' ||
                node.getAttribute('aria-disabled') === 'true' ||
                node.classList.contains('d-none') ||
                node.classList.contains('hide') ||
                node.classList.contains('hidden') ||
                node.classList.contains('disabled') ||
                node.style.display === 'none' ||
                node.style.visibility === 'hidden'
            ) return false;
            if (node === boundary) break;
            node = node.parentElement;
        }
        return true;
    }

    function refreshContextTabs() {
        var target = ensure().querySelector('[data-admin-mobile-context-tabs]');
        var source = document.querySelector('#kt_toolbar .md-tabs');
        target.innerHTML = '';
        if (source) {
            source.querySelectorAll('a[href]').forEach(function (link) {
                if (!contextTabAllowed(link, source)) return;
                var clone = link.cloneNode(true);
                var active = link.classList.contains('active');
                clone.className = 'admin-mobile-context-tab' + (active ? ' is-active' : '');
                if (active) clone.setAttribute('aria-current', 'page');
                else clone.removeAttribute('aria-current');
                clone.removeAttribute('id');
                clone.removeAttribute('style');
                clone.querySelectorAll('[id]').forEach(function (node) { node.removeAttribute('id'); });
                clone.querySelectorAll('.menu-icon, .material-icons, .material-icons-outlined, svg').forEach(function (node) { node.setAttribute('aria-hidden', 'true'); });
                target.appendChild(clone);
            });
        }
        var hasTabs = target.children.length > 0;
        target.hidden = !hasTabs;
        document.documentElement.toggleAttribute('data-admin-mobile-has-tabs', hasTabs);
    }

    function readTheme() {
        try {
            var theme = window.localStorage.getItem('admin-theme') || 'auto';
            return ['auto', 'light', 'dark'].indexOf(theme) >= 0 ? theme : 'auto';
        } catch (error) { return 'auto'; }
    }

    function applyTheme(preference) {
        var dark = preference === 'dark' || (preference === 'auto' && themeMedia && themeMedia.matches);
        document.documentElement.setAttribute('data-theme-pref', preference);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    }

    function appearanceContent() {
        var selected = readTheme();
        return '<div class="admin-mobile-choice-list" role="radiogroup" aria-label="外观模式">' +
            [['light', 'light_mode', '白天', '明亮清晰'], ['auto', 'brightness_auto', '自动', '跟随系统'], ['dark', 'dark_mode', '黑夜', '柔和低亮']].map(function (item) {
                return '<button type="button" role="radio" aria-checked="' + (selected === item[0]) + '" data-admin-mobile-theme="' + item[0] + '"><span class="material-icons-outlined" aria-hidden="true">' + item[1] + '</span><span><strong>' + item[2] + '</strong><small>' + item[3] + '</small></span><span class="material-icons-outlined" aria-hidden="true">check</span></button>';
            }).join('') + '</div><button type="button" class="admin-mobile-desktop-choice" data-admin-mobile-layout="desktop"><span class="material-icons-outlined" aria-hidden="true">desktop_windows</span><span><strong>切换桌面版</strong><small>可随时返回手机版</small></span></button>';
    }

    function handleClick(event) {
        var appearance = event.target.closest('[data-admin-mobile-appearance]');
        var search = event.target.closest('[data-admin-mobile-search]');
        var layout = event.target.closest('[data-admin-mobile-layout]');
        var theme = event.target.closest('[data-admin-mobile-theme]');
        if (search) {
            runDialogControl('search', function () {
                if (searchAction && typeof searchAction.run === 'function') return searchAction.run();
                if (api.navigation && typeof api.navigation.openAll === 'function') return api.navigation.openAll({focusSearch: true});
            });
        } else if (appearance && api.openSheet) {
            runDialogControl('appearance', function () {
                return api.openSheet({id: 'appearance', title: '外观模式', subtitle: '选择适合当前环境的显示方式', content: appearanceContent()});
            });
        } else if (layout) {
            var mode = layout.getAttribute('data-admin-mobile-layout');
            if (api.dismissAllThen) api.dismissAllThen(function () { api.setLayoutMode(mode); });
            else {
                if (api.closeAll) api.closeAll();
                api.setLayoutMode(mode);
            }
        } else if (theme) {
            var preference = theme.getAttribute('data-admin-mobile-theme');
            try { window.localStorage.setItem('admin-theme', preference); } catch (error) {}
            applyTheme(preference);
            if (api.closeTop) api.closeTop();
        }
    }

    api.shell = {
        ensure: ensure,
        setTitle: function (title) {
            ensure().querySelectorAll('[data-admin-mobile-title]').forEach(function (node) { node.textContent = title || '后台管理'; });
        },
        setSearch: function (options) {
            options = options || {};
            if (searchRoute !== routeKey()) resetSearch();
            var priority = Number(options.priority || 0);
            if (searchSource && searchSource !== options.source && priority < searchPriority) return false;
            searchAction = options;
            searchSource = options.source || '';
            searchPriority = priority;
            renderSearch();
            return true;
        },
        clearSearch: function (source) {
            if (!source || source === searchSource) resetSearch();
        },
        refresh: function () {
            ensure();
            if (searchRoute !== routeKey()) resetSearch();
            api.shell.setTitle(currentTitle());
            observeStoreStatus();
            refreshContextTabs();
            renderSearch();
            syncDialogControls();
            root.setAttribute('aria-hidden', api.isEnabled() ? 'false' : 'true');
            applyTheme(readTheme());
        },
        mount: function () {
            ensure();
            if (!clickHandler) {
                clickHandler = handleClick;
                root.addEventListener('click', clickHandler);
            }
            if (!overlayOpenHandler) {
                overlayOpenHandler = handleOverlayOpen;
                overlayCloseHandler = handleOverlayClose;
                document.addEventListener('admin:mobile:overlayopen', overlayOpenHandler);
                document.addEventListener('admin:mobile:overlayclose', overlayCloseHandler);
            }
            api.shell.refresh();
        },
        unmount: function () {
            if (storeStatusObserver) storeStatusObserver.disconnect();
            storeStatusObserver = null;
            storeStatusSource = null;
            if (root) root.setAttribute('aria-hidden', api.isViewportEligible() && api.getLayoutMode() === 'desktop' ? 'false' : 'true');
        }
    };
    if (themeMedia && themeMedia.addEventListener) themeMedia.addEventListener('change', function () {
        if (readTheme() === 'auto') applyTheme('auto');
    });
}(window, document));
