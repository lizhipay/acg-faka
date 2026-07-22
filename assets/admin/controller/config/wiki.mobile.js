(function (window, document) {
    'use strict';

    var previousController = window.AdminWikiMobileController;
    if (previousController && typeof previousController.destroy === 'function') previousController.destroy();

    var api = window.AdminMobile;
    var body = document.body;
    if (!api || !body || !body.classList.contains('admin-wiki-page')) return;

    var destroyed = false;
    var menuPromise = null;
    var menuAbort = null;
    var installedMenu = null;
    var installedHeader = null;
    var docsSheet = null;
    var docsPlaceholder = null;
    var docsSidebar = null;
    var shell = null;
    var shellClickHandler = null;
    var sidebarClickHandler = null;
    var readyObserver = null;
    var pageInertRecord = null;
    var clearButtonBindings = [];
    var docsifyPlugin = null;
    var resourceRewrite = window.AdminWikiResourceRewrite;
    var controller = null;
    var pluginName = (document.title || '').split(' - ')[0].trim() || '插件';

    function adminMenu() {
        return document.getElementById('kt_aside_menu');
    }

    function installAccountLinks(page) {
        if (destroyed || document.getElementById('kt_header') || !page) return;
        var source = page.getElementById('kt_header');
        if (!source) return;
        var header = document.createElement('div');
        header.id = 'kt_header';
        header.hidden = true;
        header.className = 'admin-wiki-menu-source';
        header.setAttribute('aria-hidden', 'true');
        ['/admin/store/home', '/admin/manage/set'].forEach(function (href) {
            var link = source.querySelector('a[href="' + href + '"]');
            if (link) header.appendChild(document.importNode(link, true));
        });
        if (header.children.length) {
            body.appendChild(header);
            installedHeader = header;
        }
    }

    function installAdminMenu(source, page) {
        if (destroyed || adminMenu() || !source) return adminMenu();
        var clone = document.importNode(source, true);
        clone.hidden = true;
        clone.classList.add('admin-wiki-menu-source');
        clone.setAttribute('aria-hidden', 'true');
        body.appendChild(clone);
        installedMenu = clone;
        installAccountLinks(page);
        if (api.navigation && typeof api.navigation.refresh === 'function') api.navigation.refresh();
        return clone;
    }

    function loadAdminMenu() {
        if (destroyed) return Promise.reject(new Error('Wiki 页面已销毁'));
        if (adminMenu()) return Promise.resolve(adminMenu());
        if (menuPromise) return menuPromise;
        var abort = window.AbortController ? new window.AbortController() : null;
        menuAbort = abort;
        var request = {
            credentials: 'same-origin',
            headers: {'Accept': 'text/html'}
        };
        if (abort) request.signal = abort.signal;
        menuPromise = window.fetch('/admin/dashboard/index', request).then(function (response) {
            if (!response.ok) throw new Error('后台菜单加载失败');
            return response.text();
        }).then(function (html) {
            if (destroyed) throw new Error('Wiki 页面已销毁');
            var page = new window.DOMParser().parseFromString(html, 'text/html');
            var source = page.getElementById('kt_aside_menu');
            if (!source) throw new Error('后台菜单不可用');
            return installAdminMenu(source, page);
        }).catch(function (error) {
            menuPromise = null;
            throw error;
        }).finally(function () {
            if (menuAbort === abort) menuAbort = null;
        });
        return menuPromise;
    }

    function menuError() {
        if (destroyed || !api.isEnabled()) return;
        if (!api.openSheet) {
            window.location.href = '/admin/dashboard/index';
            return;
        }
        var content = document.createElement('div');
        content.className = 'admin-wiki-menu-error';
        content.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">cloud_off</span>' +
            '<strong>暂时无法读取后台菜单</strong><small>可以返回后台后继续操作</small>' +
            '<a href="/admin/dashboard/index">返回后台</a>';
        api.openSheet({id: 'wiki-menu-error', title: '全部功能', content: content});
    }

    function setPageInert(inert) {
        if (destroyed && inert) return;
        var main = document.querySelector('.admin-wiki-page > main');
        if (inert) {
            if (!main || pageInertRecord) return;
            pageInertRecord = {
                element: main,
                hadInert: main.hasAttribute('inert'),
                ariaHidden: main.getAttribute('aria-hidden')
            };
            main.setAttribute('inert', '');
            main.setAttribute('aria-hidden', 'true');
            return;
        }
        if (!pageInertRecord) return;
        if (!pageInertRecord.hadInert) pageInertRecord.element.removeAttribute('inert');
        if (pageInertRecord.ariaHidden === null) pageInertRecord.element.removeAttribute('aria-hidden');
        else pageInertRecord.element.setAttribute('aria-hidden', pageInertRecord.ariaHidden);
        pageInertRecord = null;
    }

    function restoreDocsSidebar() {
        if (docsPlaceholder && docsPlaceholder.parentNode && docsSidebar) {
            docsPlaceholder.parentNode.replaceChild(docsSidebar, docsPlaceholder);
        }
        if (docsSidebar && sidebarClickHandler) docsSidebar.removeEventListener('click', sidebarClickHandler, true);
        docsPlaceholder = null;
        docsSidebar = null;
        sidebarClickHandler = null;
        docsSheet = null;
    }

    function navigateFromDocs(link, event) {
        if (destroyed) return;
        var raw = link.getAttribute('href') || '';
        if (!raw || /^javascript:/i.test(raw) || link.target === '_blank' || link.hasAttribute('download')) return;
        var target;
        try { target = new URL(link.href, window.location.href); } catch (error) { return; }
        if (target.origin !== window.location.origin || target.pathname !== window.location.pathname) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        var navigate = function () { window.location.href = target.href; };
        if (typeof api.dismissAllThen === 'function') api.dismissAllThen(navigate);
        else {
            if (docsSheet && typeof docsSheet.close === 'function') docsSheet.close();
            navigate();
        }
    }

    function openDocs() {
        if (destroyed || docsSheet || !api.isEnabled() || typeof api.openSheet !== 'function') return;
        var sidebar = document.querySelector('main > aside.sidebar');
        if (!sidebar || !sidebar.parentNode) return;
        docsSidebar = sidebar;
        docsPlaceholder = document.createComment('admin-wiki-sidebar');
        sidebar.parentNode.insertBefore(docsPlaceholder, sidebar);
        sidebarClickHandler = function (event) {
            var link = event.target.closest && event.target.closest('a[href]');
            if (link) navigateFromDocs(link, event);
        };
        sidebar.addEventListener('click', sidebarClickHandler, true);
        docsSheet = api.openSheet({
            id: 'wiki-documents',
            title: pluginName + ' 文档',
            subtitle: '搜索或选择帮助主题',
            content: sidebar,
            fullScreen: true,
            className: 'admin-wiki-docs-sheet',
            onClose: restoreDocsSidebar
        });
        if (!docsSheet) {
            restoreDocsSidebar();
            return;
        }
        window.requestAnimationFrame(function () {
            if (destroyed || !api.isEnabled() || !sidebar.isConnected) return;
            var search = sidebar.querySelector('.search input[type="search"]');
            if (search) search.focus({preventScroll: true});
        });
    }

    function configureShell() {
        if (destroyed || !api.isEnabled()) return;
        shell = api.shell && api.shell.ensure ? api.shell.ensure() : document.getElementById('admin-mobile-shell');
        if (!shell) return;
        if (api.shell) {
            api.shell.setTitle(pluginName);
            api.shell.setSearch({
                source: 'wiki-document',
                priority: 100,
                placeholder: '搜索或浏览文档',
                run: openDocs
            });
        }
        if (!shellClickHandler) {
            shellClickHandler = function (event) {
                var all = event.target.closest && event.target.closest('[data-admin-mobile-all]');
                if (!all || adminMenu()) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                all.setAttribute('aria-busy', 'true');
                loadAdminMenu().then(function () {
                    if (destroyed || !api.isEnabled() || !all.isConnected) return;
                    all.removeAttribute('aria-busy');
                    if (api.navigation && typeof api.navigation.openAll === 'function') api.navigation.openAll();
                }).catch(function () {
                    if (all.isConnected) all.removeAttribute('aria-busy');
                    if (destroyed || !api.isEnabled()) return;
                    menuError();
                });
            };
            shell.addEventListener('click', shellClickHandler, true);
        }
        if (api.isEnabled()) loadAdminMenu().catch(function () {});
    }

    function configureHomeLink() {
        if (destroyed) return;
        var link = document.querySelector('main > aside.sidebar .app-name-link');
        if (!link) return;
        link.href = window.location.pathname + window.location.search + '#/';
        link.removeAttribute('target');
        link.setAttribute('aria-label', pluginName + ' 文档首页');
    }

    function configureSearchClear() {
        if (destroyed) return;
        var clear = document.querySelector('main > aside.sidebar .search .clear-button');
        if (!clear || clear.hasAttribute('data-admin-wiki-clear-ready')) return;
        clear.setAttribute('data-admin-wiki-clear-ready', '');
        clear.setAttribute('role', 'button');
        clear.setAttribute('tabindex', '0');
        clear.setAttribute('aria-label', '清除文档搜索');
        var handler = function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            clear.click();
        };
        clear.addEventListener('keydown', handler);
        clearButtonBindings.push({element: clear, handler: handler});
    }

    function neutralizeNavigationButtons() {
        if (destroyed) return;
        document.querySelectorAll('main > button.sidebar-toggle:not([type]), main > aside.sidebar button:not([type])').forEach(function (button) {
            button.type = 'button';
        });
    }

    function docsReady() {
        if (destroyed || !document.querySelector('main > aside.sidebar')) return false;
        configureHomeLink();
        configureSearchClear();
        neutralizeNavigationButtons();
        configureShell();
        return true;
    }

    function watchDocs() {
        if (destroyed || docsReady() || !window.MutationObserver) return;
        readyObserver = new MutationObserver(function () {
            if (destroyed || !docsReady()) return;
            readyObserver.disconnect();
            readyObserver = null;
        });
        readyObserver.observe(document.getElementById('app') || body, {childList: true, subtree: true});
    }

    function cancelMenuLoad() {
        if (menuAbort) menuAbort.abort();
        menuAbort = null;
        menuPromise = null;
    }

    function detachShellHandler() {
        if (shell && shellClickHandler) shell.removeEventListener('click', shellClickHandler, true);
        if (shell) shell.querySelectorAll('[data-admin-mobile-all][aria-busy="true"]').forEach(function (button) {
            button.removeAttribute('aria-busy');
        });
        shellClickHandler = null;
    }

    function handleMobileMount() {
        configureShell();
    }

    function handleMobileUnmount() {
        cancelMenuLoad();
        detachShellHandler();
        restoreDocsSidebar();
        setPageInert(false);
        if (api.shell && typeof api.shell.clearSearch === 'function') api.shell.clearSearch('wiki-document');
    }

    function handleOverlayOpen() {
        setPageInert(true);
    }

    function handleOverlayClose(event) {
        if (!event.detail || Number(event.detail.remaining || 0) < 1) setPageInert(false);
    }

    function destroy() {
        if (destroyed) return;
        destroyed = true;
        cancelMenuLoad();
        if (readyObserver) readyObserver.disconnect();
        readyObserver = null;
        detachShellHandler();
        restoreDocsSidebar();
        setPageInert(false);
        clearButtonBindings.splice(0).forEach(function (binding) {
            binding.element.removeEventListener('keydown', binding.handler);
            binding.element.removeAttribute('data-admin-wiki-clear-ready');
        });
        document.removeEventListener('admin:mobile:mount', handleMobileMount);
        document.removeEventListener('admin:mobile:unmount', handleMobileUnmount);
        document.removeEventListener('admin:mobile:overlayopen', handleOverlayOpen);
        document.removeEventListener('admin:mobile:overlayclose', handleOverlayClose);
        window.removeEventListener('pagehide', destroy);
        if (window.jQuery) window.jQuery(document).off('pjax:beforeReplace.adminWikiMobile');
        else document.removeEventListener('pjax:beforeReplace', destroy);
        if (window.$docsify && Array.isArray(window.$docsify.plugins) && docsifyPlugin) {
            var pluginIndex = window.$docsify.plugins.indexOf(docsifyPlugin);
            if (pluginIndex >= 0) window.$docsify.plugins.splice(pluginIndex, 1);
        }
        if (installedMenu && installedMenu.parentNode) installedMenu.remove();
        if (installedHeader && installedHeader.parentNode) installedHeader.remove();
        installedMenu = null;
        installedHeader = null;
        if (api.shell && typeof api.shell.clearSearch === 'function') api.shell.clearSearch('wiki-document');
        if (resourceRewrite && typeof resourceRewrite.destroy === 'function') resourceRewrite.destroy();
        if (window.AdminWikiMobileController === controller) window.AdminWikiMobileController = null;
    }

    window.$docsify = window.$docsify || {};
    docsifyPlugin = function (hook) {
        hook.mounted(function () { if (!destroyed) docsReady(); });
        hook.doneEach(function () {
            if (destroyed) return;
            configureHomeLink();
            configureSearchClear();
            neutralizeNavigationButtons();
            if (api.isEnabled() && api.shell) api.shell.setTitle(pluginName);
        });
    };
    window.$docsify.plugins = (window.$docsify.plugins || []).concat(docsifyPlugin);

    controller = {destroy: destroy};
    window.AdminWikiMobileController = controller;
    document.addEventListener('admin:mobile:mount', handleMobileMount);
    document.addEventListener('admin:mobile:unmount', handleMobileUnmount);
    document.addEventListener('admin:mobile:overlayopen', handleOverlayOpen);
    document.addEventListener('admin:mobile:overlayclose', handleOverlayClose);
    window.addEventListener('pagehide', destroy, {once: true});
    if (window.jQuery) window.jQuery(document).off('pjax:beforeReplace.adminWikiMobile').on('pjax:beforeReplace.adminWikiMobile', destroy);
    else document.addEventListener('pjax:beforeReplace', destroy);
    watchDocs();
}(window, document));
