(function (window, document) {
    'use strict';

    var api = window.AdminMobile;
    if (!api) return;
    var observer = null;
    var root = null;
    var clickHandler = null;
    var rebuildTimer = 0;
    var routes = {
        home: ['/admin/dashboard'], orders: ['/admin/order'], tickets: ['/admin/ticket'], members: ['/admin/user']
    };
    var generatedMenuIcons = {
        '/admin/pay/plugin': 'extension',
        '/admin/pay/index': 'payments'
    };

    function activeKey(path) {
        var key = Object.keys(routes).find(function (name) {
            return routes[name].some(function (prefix) {
                return path === prefix || path.indexOf(prefix + '/') === 0;
            });
        });
        return key || 'all';
    }

    function refreshActive() {
        if (!root) return;
        var active = activeKey(window.location.pathname);
        root.querySelectorAll('[data-admin-mobile-nav], [data-admin-mobile-all]').forEach(function (item) {
            var selected = item.getAttribute('data-admin-mobile-nav') === active || (item.hasAttribute('data-admin-mobile-all') && active === 'all');
            item.classList.toggle('is-active', selected);
            if (selected) item.setAttribute('aria-current', 'page'); else item.removeAttribute('aria-current');
        });
    }

    function setAllExpanded(expanded) {
        if (!root) return;
        root.querySelectorAll('[data-admin-mobile-all]').forEach(function (button) {
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    function cleanClone(source) {
        var clone = source.cloneNode(true);
        clone.className = 'admin-mobile-menu-link';
        clone.removeAttribute('id');
        clone.removeAttribute('data-kt-menu-trigger');
        clone.removeAttribute('data-kt-menu-placement');
        clone.querySelectorAll('[id]').forEach(function (node) { node.removeAttribute('id'); });
        clone.querySelectorAll('[style]').forEach(function (node) { node.removeAttribute('style'); });
        clone.querySelectorAll('.menu-icon, .material-icons, .material-icons-outlined, svg').forEach(function (node) { node.setAttribute('aria-hidden', 'true'); });
        var icon = clone.querySelector('.menu-icon');
        if (!icon) {
            var href = source.getAttribute('href') || '';
            var path = href.split('?')[0].replace(/\/+$/, '') || '/';
            icon = document.createElement('span');
            icon.className = 'menu-icon';
            var glyph = document.createElement('span');
            glyph.className = 'material-icons-outlined';
            glyph.setAttribute('aria-hidden', 'true');
            glyph.textContent = generatedMenuIcons[path] || 'apps';
            icon.appendChild(glyph);
            clone.insertBefore(icon, clone.firstChild);
        }
        var ticketBadge = clone.querySelector('.ticket-admin-badge');
        if (ticketBadge) {
            icon.removeAttribute('aria-hidden');
            ticketBadge.classList.add('admin-mobile-ticket-badge');
            icon.appendChild(ticketBadge);
        }
        return clone;
    }

    function isSourceMenuLinkAllowed(link, menuRoot) {
        var node = link;
        while (node && node !== menuRoot) {
            if (
                node.hidden
                || node.getAttribute('aria-hidden') === 'true'
                || node.getAttribute('aria-disabled') === 'true'
                || node.classList.contains('d-none')
                || node.classList.contains('hide')
                || node.classList.contains('hidden')
                || node.classList.contains('disabled')
            ) {
                return false;
            }
            var inlineStyle = node.style;
            if (inlineStyle && (inlineStyle.display === 'none' || inlineStyle.visibility === 'hidden')) {
                return false;
            }
            node = node.parentElement;
        }
        return true;
    }

    function groupTitle(value) {
        var title = String(value || '').trim();
        var names = {
            Main: '主要功能',
            User: '会员运营',
            Trade: '商品与交易',
            Shared: '共享服务',
            Config: '系统设置',
            Plugin: '插件与扩展',
            Store: '应用服务',
            System: '系统管理'
        };
        return names[title] || title;
    }

    function filterMenu(container, value) {
        var query = String(value || '').trim().toLocaleLowerCase();
        container.querySelectorAll('.admin-mobile-menu-link').forEach(function (link) {
            link.hidden = Boolean(query) && link.textContent.toLocaleLowerCase().indexOf(query) < 0;
        });
        container.querySelectorAll('.admin-mobile-menu-group').forEach(function (group) {
            group.hidden = !Array.from(group.querySelectorAll('.admin-mobile-menu-link')).some(function (link) { return !link.hidden; });
        });
        var empty = container.querySelector('[data-admin-mobile-menu-empty]');
        if (empty) empty.hidden = Array.from(container.querySelectorAll('.admin-mobile-menu-link')).some(function (link) { return !link.hidden; });
    }

    function menuContent() {
        var source = document.getElementById('kt_aside_menu');
        var container = document.createElement('div');
        container.className = 'admin-mobile-all-menu';
        if (!source) {
            container.innerHTML = '<div class="admin-mobile-empty"><span class="material-icons-outlined" aria-hidden="true">menu_open</span><strong>菜单正在加载</strong><small>请稍后重试</small></div>';
            return container;
        }
        var search = document.createElement('label');
        search.className = 'admin-mobile-menu-search';
        search.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">search</span><input type="search" inputmode="search" autocomplete="off" placeholder="搜索后台功能" aria-label="搜索后台功能"><button type="button" aria-label="清除搜索" hidden><span class="material-icons-outlined" aria-hidden="true">cancel</span></button>';
        container.appendChild(search);
        var input = search.querySelector('input');
        var clear = search.querySelector('button');
        input.addEventListener('input', function () {
            clear.hidden = !input.value;
            filterMenu(container, input.value);
        });
        clear.addEventListener('click', function () {
            input.value = '';
            clear.hidden = true;
            filterMenu(container, '');
            input.focus();
        });
        var group = null;
        Array.from(source.children).forEach(function (item) {
            var section = item.querySelector(':scope > .menu-content .menu-section');
            if (section) {
                group = document.createElement('section');
                group.className = 'admin-mobile-menu-group';
                var heading = document.createElement('h3');
                heading.textContent = groupTitle(section.textContent);
                group.appendChild(heading);
                container.appendChild(group);
                return;
            }
            var links = item.querySelectorAll('a.menu-link[href]');
            links.forEach(function (link) {
                if (!isSourceMenuLinkAllowed(link, source)) return;
                if (!group) {
                    group = document.createElement('section');
                    group.className = 'admin-mobile-menu-group';
                    container.appendChild(group);
                }
                group.appendChild(cleanClone(link));
            });
        });
        container.querySelectorAll('.admin-mobile-menu-group').forEach(function (section) {
            if (!section.querySelector('a')) section.remove();
        });
        if (!container.querySelector('a')) {
            source.querySelectorAll('a.menu-link[href]').forEach(function (link) {
                if (isSourceMenuLinkAllowed(link, source)) container.appendChild(cleanClone(link));
            });
        }
        var account = document.createElement('section');
        account.className = 'admin-mobile-menu-group admin-mobile-menu-group--account';
        account.innerHTML = '<h3>账户与系统</h3>';
        var store = document.querySelector('#kt_header a[href="/admin/store/home"]');
        if (store && isSourceMenuLinkAllowed(store, document.getElementById('kt_header'))) {
            var storeLink = document.createElement('a');
            storeLink.className = 'admin-mobile-menu-link'; storeLink.href = '/admin/store/home';
            storeLink.innerHTML = '<span class="menu-icon material-icons-outlined" aria-hidden="true">storefront</span><span class="menu-title">应用商店</span>';
            account.appendChild(storeLink);
        }
        var personal = document.querySelector('#kt_header a[href="/admin/manage/set"]');
        if (personal && isSourceMenuLinkAllowed(personal, document.getElementById('kt_header'))) {
            var personalLink = document.createElement('a');
            personalLink.className = 'admin-mobile-menu-link'; personalLink.href = '/admin/manage/set';
            personalLink.innerHTML = '<span class="menu-icon material-icons-outlined" aria-hidden="true">manage_accounts</span><span class="menu-title">个人设置</span>';
            account.appendChild(personalLink);
        }
        var logout = document.createElement('a');
        logout.className = 'admin-mobile-menu-link is-danger'; logout.href = '/admin/authentication/logout'; logout.setAttribute('data-admin-mobile-logout', '');
        logout.innerHTML = '<span class="menu-icon material-icons-outlined" aria-hidden="true">logout</span><span class="menu-title">退出登录</span>';
        account.appendChild(logout);
        container.appendChild(account);
        var empty = document.createElement('div');
        empty.className = 'admin-mobile-empty admin-mobile-menu-empty';
        empty.setAttribute('data-admin-mobile-menu-empty', '');
        empty.hidden = true;
        empty.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">search_off</span><strong>没有匹配的功能</strong><small>换个关键词试试</small>';
        container.appendChild(empty);
        return container;
    }

    function openAll(options) {
        if (!api.openSheet) return;
        options = options || {};
        var content = menuContent();
        var sheet = api.openSheet({
            id: 'all-menu',
            title: '全部功能',
            subtitle: '仅显示当前账号可使用的功能',
            content: content,
            className: 'admin-mobile-sheet--menu',
            onClose: function () { setAllExpanded(false); }
        });
        setAllExpanded(Boolean(sheet));
        if (sheet && options.focusSearch) window.requestAnimationFrame(function () {
            var input = content.querySelector('input[type="search"]');
            if (input) input.focus({preventScroll: true});
        });
        return sheet;
    }

    function handleClick(event) {
        var logout = event.target.closest('[data-admin-mobile-logout]');
        var primaryNavigation = event.target.closest('a[data-admin-mobile-nav][href]');
        if (logout) {
            event.preventDefault();
            var messageApi = typeof message !== 'undefined' ? message : window.message;
            var leave = function () {
                var navigate = function () { window.location.href = logout.href; };
                if (api.pageWorkflows && typeof api.pageWorkflows.requestLeave === 'function') api.pageWorkflows.requestLeave(navigate);
                else navigate();
            };
            if (messageApi && typeof messageApi.ask === 'function') messageApi.ask('确定要退出当前后台账号吗？', leave);
            else if (window.confirm('确定要退出当前后台账号吗？')) leave();
            return;
        }
        if (primaryNavigation) {
            event.preventDefault();
            if (api.closeAll) api.closeAll({silentHistory: true});
            api.navigate(primaryNavigation.getAttribute('href'));
            return;
        }
        if (event.target.closest('[data-admin-mobile-all]')) openAll();
        if (event.target.closest('.admin-mobile-menu-link, [data-admin-mobile-nav]') && api.closeAll) api.closeAll();
    }

    function observeMenu() {
        var source = document.getElementById('kt_aside_menu');
        if (!source || !window.MutationObserver) return;
        if (observer) observer.disconnect();
        observer = new MutationObserver(function () {
            clearTimeout(rebuildTimer);
            rebuildTimer = window.setTimeout(function () {
                rebuildTimer = 0;
                var open = document.querySelector('[data-admin-mobile-overlay="all-menu"] .admin-mobile-overlay__body');
                if (open) open.replaceChildren(menuContent());
            }, 60);
        });
        observer.observe(source, {subtree: true, childList: true, attributes: true});
    }

    api.navigate = function (href, options) {
        if (!href) return;
        options = options || {};
        var navigate = function () {
            if (window.jQuery && window.jQuery.pjax) window.jQuery.pjax({
                url: href,
                container: '#pjax-container',
                fragment: '#pjax-container',
                timeout: 8000
            });
            else window.location.href = href;
        };
        if (!options.skipPageGuard && api.pageWorkflows && typeof api.pageWorkflows.requestLeave === 'function') return api.pageWorkflows.requestLeave(navigate);
        navigate();
    };
    api.navigation = {
        openAll: openAll,
        mount: function () {
            root = api.shell && api.shell.ensure();
            if (!root) return;
            setAllExpanded(false);
            if (!clickHandler) { clickHandler = handleClick; root.addEventListener('click', clickHandler); }
            observeMenu();
            refreshActive();
        },
        refresh: function () { refreshActive(); observeMenu(); },
        rebuild: menuContent,
        unmount: function () {
            setAllExpanded(false);
            if (observer) observer.disconnect();
            observer = null;
            if (rebuildTimer) window.clearTimeout(rebuildTimer);
            rebuildTimer = 0;
        }
    };
}(window, document));
