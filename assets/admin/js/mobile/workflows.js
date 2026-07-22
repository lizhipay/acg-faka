(function (window, document) {
    'use strict';

    var api = window.AdminMobile;
    if (!api) return;

    function profile(role, options) {
        return Object.assign({
            role: role,
            navigation: true,
            pageActions: false,
            forms: false,
            charts: false,
            table: false,
            longTask: false
        }, options || {});
    }

    /*
     * Every named recipe workflow has an explicit profile. Profiles only arrange
     * existing controls and callbacks; business requests remain owned by the
     * page controller and the shared Table/Form components.
     */
    var WORKFLOW_PROFILES = Object.freeze({
        'authentication': profile('authentication', {navigation: false, pageActions: true, forms: true}),
        'dashboard': profile('analytics-dashboard', {navigation: false, charts: true}),
        'user-management': profile('member-operations', {table: true}),
        'ticket-management': profile('ticket-operations', {table: true, longTask: true}),
        'message-management': profile('message-operations', {table: true}),
        'group-management': profile('group-operations', {table: true}),
        'cash-review': profile('cash-review', {table: true, longTask: true}),
        'category-management': profile('category-operations', {table: true}),
        'commodity-management': profile('catalog-operations', {table: true, longTask: true}),
        'card-inventory': profile('inventory-operations', {table: true, longTask: true}),
        'order-management': profile('order-operations', {table: true, longTask: true}),
        'settings-form': profile('configuration-task', {pageActions: true, forms: true}),
        'document-navigation': profile('document-navigation', {pageActions: true}),
        'personal-settings': profile('personal-settings', {pageActions: true, forms: true, longTask: true}),
        'plugin-management': profile('plugin-operations', {table: true, longTask: true}),
        'payment-management': profile('payment-operations', {table: true, longTask: true}),
        'payment-plugin-management': profile('payment-plugin-operations', {table: true, longTask: true}),
        'file-management': profile('file-operations', {table: true, longTask: true}),
        'manager-management': profile('manager-operations', {table: true}),
        'application-store': profile('store-connection-operations', {table: true, longTask: true}),
        'owned-applications': profile('owned-application-operations', {table: true, longTask: true}),
        'developer-applications': profile('developer-application-operations', {table: true, longTask: true}),
        'supply-market': profile('supply-market-operations', {table: true, longTask: true}),
        'supply-market-form': profile('supply-market-publishing', {pageActions: true, forms: true, longTask: true}),
        'third-dock-site': profile('third-dock-site-operations', {table: true, longTask: true}),
        'third-dock-category': profile('third-dock-category-operations', {table: true, longTask: true}),
        'third-dock-product': profile('third-dock-product-operations', {table: true, longTask: true}),
        'third-dock-rule': profile('third-dock-rule-operations', {table: true, longTask: true})
    });

    var PAGE_TYPE_PROFILES = Object.freeze({
        'auth': profile('authentication', {navigation: false, pageActions: true, forms: true}),
        'dashboard': profile('analytics-dashboard', {navigation: false, charts: true}),
        'settings': profile('configuration-task', {pageActions: true, forms: true}),
        'document': profile('document-navigation', {pageActions: true}),
        'form': profile('form-task', {pageActions: true, forms: true, longTask: true}),
        'list': profile('record-list', {table: true}),
        'segmented-list': profile('segmented-record-list', {table: true}),
        'tree-list': profile('tree-record-list', {table: true}),
        'catalog-list': profile('catalog-record-list', {table: true}),
        'inventory-list': profile('inventory-record-list', {table: true}),
        'order-list': profile('order-record-list', {table: true, longTask: true}),
        'media-list': profile('media-record-list', {table: true, longTask: true}),
        'audit-list': profile('audit-record-list', {table: true}),
        'store-list': profile('store-record-list', {table: true, longTask: true}),
        'mapping-list': profile('mapping-record-list', {table: true, longTask: true})
    });

    var ACTION_HINT = /(?:保存|提交|确认修改|发送测试|测试|上传|发布|设置|配置|同步|安装|卸载|更新|升级|导入|导出|下载|刷新|生成|绑定|解绑|停用|停止|禁用|删除|移除|驳回|拒绝|清理|清空|注销|退出|结算|迁移|采集|拉取|重试|开始|执行|审核|接入|克隆|save|submit|test|upload|publish|setting|configure|sync|install|uninstall|update|upgrade|import|export|download|refresh|generate|bind|unbind|clear|delete|remove|reject|disable|stop|logout|retry|start|run)/i;
    var LONG_TASK_HINT = /(?:同步|安装|更新|升级|上传|发布|迁移|采集|拉取|克隆|结算|生成|处理中|正在|sync|install|update|upgrade|upload|publish|migrate|collect|clone|processing)/i;
    var BUSY_HINT = /(?:处理中|正在|加载中|同步中|上传中|提交中|安装中|更新中|请稍候|processing|loading|syncing|uploading|installing|updating)/i;
    var DANGER_HINT = /(?:删除|移除|清理|清空|卸载|解绑|驳回|拒绝|禁用|停用|停止|注销|退出|delete|remove|clear|uninstall|unbind|reject|disable|stop|logout)/i;
    var CONTROL_SELECTOR = 'button, input[type="button"], input[type="submit"], a[href], [role="button"], label';
    var PAGE_HISTORY_GUARD_KEY = 'adminMobilePageGuard';
    var activeSession = null;

    function safeName(value) {
        return String(value || 'generic').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'generic';
    }

    function plainText(value) {
        var template = document.createElement('template');
        template.innerHTML = value == null ? '' : String(value);
        return (template.content.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function cloneLabel(control) {
        if (!control) return '';
        if (control.matches('label') && control.querySelector('input[type="file"]')) {
            var rowLabel = control.closest('.row, .form-group');
            rowLabel = rowLabel && rowLabel.querySelector('.col-form-label, [data-field-label]');
            if (rowLabel && plainText(rowLabel.textContent)) return '上传 ' + plainText(rowLabel.textContent).replace(/[：:]$/, '');
            var fileTitle = control.getAttribute('aria-label') || control.getAttribute('title');
            return plainText(fileTitle) || '上传文件';
        }
        var explicit = control.getAttribute('aria-label') || control.getAttribute('data-title') || control.getAttribute('title');
        var clone = control.cloneNode(true);
        clone.querySelectorAll('i, svg, .material-icons, .material-icons-outlined, .fa-spin, [aria-hidden="true"]').forEach(function (node) { node.remove(); });
        return plainText(clone.textContent || control.value || explicit || control.name || control.id || '操作');
    }

    function visible(control) {
        if (!control || !control.isConnected || control.hidden) return false;
        if (control.closest('[hidden], .admin-mobile-shell, [data-admin-mobile-page-actions-generated], [data-admin-mobile-local-actions], [data-admin-mobile-overlay-host], .layui-layer, .modal, table, .bootstrap-table')) return false;
        var style = window.getComputedStyle ? window.getComputedStyle(control) : null;
        if (style && (style.display === 'none' || style.visibility === 'hidden')) return false;
        if (control.getClientRects().length > 0) return true;
        // Closed action menus are intentionally not laid out. Their items are
        // still valid page operations, so keep them in the mobile action Sheet
        // unless the item or an inner wrapper is explicitly permission-hidden.
        var menu = control.closest('.dropdown-menu, .menu-sub-dropdown');
        if (!menu) return false;
        var node = control;
        while (node && node !== menu) {
            if (
                node.hidden ||
                node.getAttribute('aria-hidden') === 'true' ||
                node.classList.contains('d-none') ||
                node.classList.contains('hide') ||
                node.classList.contains('hidden')
            ) return false;
            var inlineStyle = node.style;
            if (inlineStyle && (inlineStyle.display === 'none' || inlineStyle.visibility === 'hidden')) return false;
            node = node.parentElement;
        }
        return true;
    }

    function isDisabled(control) {
        return !!(control && (control.disabled || control.getAttribute('aria-disabled') === 'true' || control.classList.contains('disabled')));
    }

    function isBusy(control) {
        return !!(control && (control.getAttribute('aria-busy') === 'true' || control.querySelector('.fa-spin, .spinner-border, .spinner-grow') || BUSY_HINT.test(cloneLabel(control))));
    }

    function isDanger(control, label, configured) {
        return !!(configured && configured.danger) || DANGER_HINT.test([label, control && control.className].join(' '));
    }

    function behaviorFor(recipe) {
        if (recipe && recipe.workflow && WORKFLOW_PROFILES[recipe.workflow]) return WORKFLOW_PROFILES[recipe.workflow];
        return PAGE_TYPE_PROFILES[(recipe && recipe.pageType) || ''] || profile('generic-page', {pageActions: true});
    }

    function pageRoot() {
        return document.getElementById('pjax-container') || document.getElementById('app') || document.querySelector('main') || document.body;
    }

    function addCleanup(session, cleanup, pageOnly) {
        (pageOnly ? session.pageCleanups : session.cleanups).push(cleanup);
        return cleanup;
    }

    function bind(session, target, type, handler, options, pageOnly) {
        if (!target || !target.addEventListener) return;
        target.addEventListener(type, handler, options);
        addCleanup(session, function () { target.removeEventListener(type, handler, options); }, pageOnly);
    }

    function rememberAttribute(session, node, name, value) {
        if (!node) return;
        var existing = session.marks.find(function (mark) { return mark.node === node && mark.type === 'attribute' && mark.name === name; });
        if (!existing) {
            existing = {node: node, type: 'attribute', name: name, had: node.hasAttribute(name), value: node.getAttribute(name)};
            session.marks.push(existing);
        }
        if (value === null || value === undefined) node.removeAttribute(name); else node.setAttribute(name, String(value));
    }

    function rememberClass(session, node, name) {
        if (!node || node.classList.contains(name)) return;
        node.classList.add(name);
        session.marks.push({node: node, type: 'class', name: name});
    }

    function restoreMarks(session) {
        session.marks.splice(0).reverse().forEach(function (mark) {
            if (!mark.node) return;
            if (mark.type === 'class') mark.node.classList.remove(mark.name);
            else if (mark.had) mark.node.setAttribute(mark.name, mark.value);
            else mark.node.removeAttribute(mark.name);
        });
    }

    function rememberTemporaryAttribute(node, name) {
        return {
            name: name,
            had: node.hasAttribute(name),
            value: node.getAttribute(name)
        };
    }

    function restoreTemporaryAttribute(node, mark) {
        if (!node || !mark) return;
        if (mark.had) node.setAttribute(mark.name, mark.value);
        else node.removeAttribute(mark.name);
    }

    function clearBridgedActionSources(session) {
        (session.bridgedActionSources || []).splice(0).reverse().forEach(function (entry) {
            if (!entry.node) return;
            restoreTemporaryAttribute(entry.node, entry.hidden);
            restoreTemporaryAttribute(entry.node, entry.marker);
        });
    }

    function hideBridgedActionSource(session, control, owner) {
        if (!control || session.bridgedActionSources.some(function (entry) { return entry.node === control; })) return;
        session.bridgedActionSources.push({
            node: control,
            hidden: rememberTemporaryAttribute(control, 'hidden'),
            marker: rememberTemporaryAttribute(control, 'data-admin-mobile-action-source-hidden')
        });
        control.setAttribute('data-admin-mobile-action-source-hidden', owner);
        control.setAttribute('hidden', '');
    }

    function clearGeneratedActions(session) {
        if (session.generatedActions) session.generatedActions.remove();
        session.generatedActions = null;
        clearBridgedActionSources(session);
        (session.hiddenNativeActions || []).splice(0).forEach(function (control) {
            if (control) control.removeAttribute('data-admin-mobile-action-in-sheet');
        });
        if (session.nativeActionObserver) session.nativeActionObserver.disconnect();
        if (session.nativeActionFrame) window.cancelAnimationFrame(session.nativeActionFrame);
        session.nativeActionObserver = null;
        session.nativeActionFrame = 0;
        document.documentElement.style.removeProperty('--admin-mobile-native-actions-height');
        if (session.nativeActionBar) {
            session.nativeActionBar.removeAttribute('data-admin-mobile-native-actions');
            session.nativeActionBar.classList.remove('admin-mobile-page-action-source');
        }
        session.nativeActionBar = null;
    }

    function pageGuardForms(session) {
        if (!session || !session.root || !session.behavior || !session.behavior.forms) return [];
        return Array.from(session.root.querySelectorAll('form')).filter(function (form) {
            if (form.closest('[data-admin-mobile-overlay-host], .layui-layer, .modal, .admin-mobile-native-search')) return false;
            return form.id === 'data-form' || form.hasAttribute('data-admin-mobile-guard-unsaved');
        });
    }

    function formRowIsVisible(row) {
        if (!row || !row.isConnected || row.hidden) return false;
        var style = window.getComputedStyle ? window.getComputedStyle(row) : null;
        return !style || (style.display !== 'none' && style.visibility !== 'hidden');
    }

    function formRowLabel(row) {
        var label = row && row.querySelector(':scope > label.col-form-label, :scope > [data-field-label]');
        return plainText(label && label.textContent).replace(/[（(].*$/, '').replace(/[：:]$/, '').trim();
    }

    function formRowControls(row) {
        return Array.from(row.querySelectorAll('input:not([type="hidden"]):not([type="file"]):not([type="button"]):not([type="submit"]):not([type="reset"]), select, textarea'));
    }

    function clearFormFieldError(row) {
        if (!row) return;
        row.classList.remove('admin-mobile-form-field-error');
        row.querySelectorAll('[aria-invalid="true"]').forEach(function (control) { control.removeAttribute('aria-invalid'); });
    }

    function invalidFormField(form, serverMessage) {
        if (!form) return null;
        var rows = Array.from(form.querySelectorAll('.row.mb-6')).filter(formRowIsVisible);
        var messageText = plainText(serverMessage || '').toLowerCase();
        var compactMessage = messageText.replace(/[\s：:()（）_-]+/g, '');
        var matchedByMessage = null;
        var requiredFallback = null;
        for (var index = 0; index < rows.length; index += 1) {
            var row = rows[index];
            var controls = formRowControls(row);
            if (!controls.length) continue;
            var label = formRowLabel(row);
            var compactLabel = label.toLowerCase().replace(/[\s：:()（）_-]+/g, '').replace(/配置$/, '');
            if (messageText && (compactLabel && compactMessage.indexOf(compactLabel) >= 0 || controls.some(function (control) { return control.name && messageText.indexOf(String(control.name).toLowerCase()) >= 0; }))) {
                matchedByMessage = {row: row, control: controls[0], label: label};
            }
            var nativeInvalid = controls.find(function (control) { return control.validity && control.validity.valid === false; });
            if (nativeInvalid) return {row: row, control: nativeInvalid, label: label};
            var labelNode = row.querySelector(':scope > label.col-form-label.required, :scope > [data-field-label].required');
            if (!labelNode) continue;
            var checks = controls.filter(function (control) { return control.type === 'checkbox' || control.type === 'radio'; });
            if (checks.length === controls.length) {
                if (!row.querySelector('.form-switch') && !checks.some(function (control) { return control.checked; })) return {row: row, control: checks[0], label: label};
                continue;
            }
            var values = controls.filter(function (control) { return control.type !== 'checkbox' && control.type !== 'radio'; });
            var blankValue = values.find(function (control) { return String(control.value == null ? '' : control.value).trim() === ''; });
            if (!requiredFallback && blankValue) requiredFallback = {row: row, control: blankValue, label: label};
            var empty = values.find(function (control) {
                if (control.type === 'password' && /保留/.test(control.getAttribute('placeholder') || '')) return false;
                return String(control.value == null ? '' : control.value).trim() === '';
            });
            if (empty) return {row: row, control: empty, label: label};
        }
        return matchedByMessage || (/(?:配置不完整|尚未配置|必填|不能为空)/.test(messageText) ? requiredFallback : null);
    }

    function focusFormFieldError(form, serverMessage, announce) {
        if (!form) return true;
        form.querySelectorAll('.admin-mobile-form-field-error').forEach(clearFormFieldError);
        var invalid = invalidFormField(form, serverMessage);
        if (!invalid) return true;
        invalid.row.classList.add('admin-mobile-form-field-error');
        invalid.control.setAttribute('aria-invalid', 'true');
        invalid.row.scrollIntoView({block: 'center', behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
        window.setTimeout(function () {
            var select2 = invalid.control.nextElementSibling;
            var focusTarget = select2 && select2.classList.contains('select2-container') ? select2.querySelector('.select2-selection') : invalid.control;
            if (focusTarget && typeof focusTarget.focus === 'function') focusTarget.focus({preventScroll: true});
        }, 180);
        if (announce !== false && window.layer && typeof window.layer.msg === 'function') window.layer.msg('请检查「' + (invalid.label || '必填项') + '」');
        return false;
    }

    function pageIsDirty(session) {
        if (!session || session.leaveBypass) return false;
        return Array.from(session.formGuards.values()).some(function (guard) { return guard.dirty; });
    }

    function pageHistoryGuardMatches(session, state) {
        state = state === undefined ? window.history.state : state;
        return !!(session && state && state[PAGE_HISTORY_GUARD_KEY] === session.historyGuardToken);
    }

    function pageHistoryGuardState(session, baseState) {
        var state = Object.assign({}, baseState || {});
        state[PAGE_HISTORY_GUARD_KEY] = session.historyGuardToken;
        return state;
    }

    function overlayHistoryIsActive() {
        var overlay = api.overlay;
        return !!(
            overlay && typeof overlay.hasOpenEntries === 'function' && overlay.hasOpenEntries() &&
            window.history && window.history.state &&
            (window.history.state.adminMobileOverlay || window.history.state.adminMobilePopup)
        );
    }

    function armPageHistoryGuard(session) {
        if (
            !session || session !== activeSession || session.suspendHistoryGuard || session.leaveBypass ||
            session.historyGuardActive || session.historyGuardReleasing || !pageIsDirty(session) ||
            !window.history || typeof window.history.pushState !== 'function'
        ) return;
        try {
            session.historyGuardUrl = window.location.href;
            window.history.pushState(pageHistoryGuardState(session, window.history.state), '', session.historyGuardUrl);
            session.historyGuardActive = true;
            session.historyGuardRetired = false;
        } catch (error) { console.error(error); }
    }

    function finishPageHistoryRelease(session) {
        if (!session || !session.historyGuardReleasing) return;
        if (session.historyGuardReleaseTimer) window.clearTimeout(session.historyGuardReleaseTimer);
        session.historyGuardReleaseTimer = 0;
        session.historyGuardReleasing = false;
        session.historyGuardRetired = true;
        var action = session.historyGuardReleaseAction;
        session.historyGuardReleaseAction = null;
        if (typeof action === 'function') {
            try { action(); } catch (error) {
                session.leaveBypass = false;
                console.error(error);
            }
        }
        if (session === activeSession && pageIsDirty(session)) window.setTimeout(function () { armPageHistoryGuard(session); }, 0);
    }

    function releasePageHistoryGuard(session, action) {
        if (!session) {
            if (typeof action === 'function') action();
            return;
        }
        if (typeof action === 'function' && !session.historyGuardReleaseAction) session.historyGuardReleaseAction = action;
        if (session.historyGuardReleasing) return;
        if (!session.historyGuardActive) {
            var immediate = session.historyGuardReleaseAction;
            session.historyGuardReleaseAction = null;
            if (typeof immediate === 'function') immediate();
            return;
        }
        if (!pageHistoryGuardMatches(session)) {
            // An Overlay history entry is above the page sentinel. Let the
            // Overlay close first; its popstate will expose this sentinel.
            if (!action) session.historyGuardPendingRelease = true;
            else if (overlayHistoryIsActive() && typeof api.dismissAllThen === 'function') {
                // Keep the requested action owned by this release. The overlay
                // helper rewinds every live Sheet/Popup entry, then we can
                // retire the page sentinel exactly once before navigating.
                session.historyGuardReleaseAction = null;
                api.dismissAllThen(function () { releasePageHistoryGuard(session, action); });
            }
            else {
                session.historyGuardActive = false;
                var deferred = session.historyGuardReleaseAction;
                session.historyGuardReleaseAction = null;
                if (typeof deferred === 'function') deferred();
            }
            return;
        }
        session.historyGuardActive = false;
        session.historyGuardPendingRelease = false;
        session.historyGuardReleasing = true;
        window.history.back();
        session.historyGuardReleaseTimer = window.setTimeout(function () { finishPageHistoryRelease(session); }, 700);
    }

    function handlePagePopstate(event) {
        var session = activeSession;
        if (!session) return;
        if (session.historyGuardReleasing) {
            finishPageHistoryRelease(session);
            return;
        }
        if (pageHistoryGuardMatches(session, event.state)) {
            if (!session.historyGuardActive && session.historyGuardRetired) {
                window.history.back();
                return;
            }
            if (session.historyGuardPendingRelease && !pageIsDirty(session)) releasePageHistoryGuard(session);
            return;
        }
        if (!session.historyGuardActive) {
            return;
        }
        if (!pageIsDirty(session) || session.leaveBypass) {
            session.historyGuardActive = false;
            return;
        }
        // Back moved from the same-URL sentinel to the real PJAX entry. Put a
        // single sentinel back before asking, so cancel leaves history intact.
        try {
            window.history.pushState(pageHistoryGuardState(session, event.state), '', session.historyGuardUrl || window.location.href);
        } catch (error) { console.error(error); }
        if (session.leavePromise) return;
        confirmPageLeave(session, function () { window.history.back(); });
    }

    function syncPageDirtyUi(session) {
        var dirty = pageIsDirty(session);
        document.documentElement.toggleAttribute('data-admin-mobile-page-dirty', dirty);
        if (document.body) document.body.toggleAttribute('data-admin-mobile-page-dirty', dirty);
        var heading = document.querySelector('[data-admin-mobile-appbar] .admin-mobile-appbar__heading');
        var badge = heading && heading.querySelector('[data-admin-mobile-page-unsaved]');
        if (dirty && heading && !badge) {
            badge = document.createElement('span');
            badge.className = 'admin-mobile-unsaved-indicator';
            badge.setAttribute('data-admin-mobile-page-unsaved', '');
            badge.textContent = '未保存';
            heading.appendChild(badge);
        } else if (!dirty && badge) {
            badge.remove();
        }
        if (!session.suspendHistoryGuard) {
            if (dirty) armPageHistoryGuard(session);
            else releasePageHistoryGuard(session);
        }
    }

    function setPageFormDirty(session, form, dirty, revision) {
        var guard = session && session.formGuards.get(form);
        if (!guard) return;
        if (dirty) guard.revision += 1;
        if (!dirty && revision !== undefined && revision !== null && Number(revision) !== guard.revision) return;
        if (guard.dirty === dirty) return;
        guard.dirty = dirty;
        form.toggleAttribute('data-admin-mobile-form-dirty', dirty);
        syncPageDirtyUi(session);
    }

    function removePageFormGuard(session, form) {
        var guard = session.formGuards.get(form);
        if (!guard) return;
        guard.cleanups.splice(0).forEach(function (cleanup) {
            try { cleanup(); } catch (error) { console.error(error); }
        });
        form.removeAttribute('data-admin-mobile-form-dirty');
        session.formGuards.delete(form);
    }

    function clearPageFormGuards(session) {
        if (!session || !session.formGuards) return;
        Array.from(session.formGuards.keys()).forEach(function (form) { removePageFormGuard(session, form); });
        if (session.leaveResetTimer) window.clearTimeout(session.leaveResetTimer);
        session.leaveResetTimer = 0;
        session.leaveBypass = false;
        session.leavePromise = null;
        syncPageDirtyUi(session);
    }

    function addPageFormGuard(session, form) {
        if (session.formGuards.has(form)) return;
        var guard = {form: form, dirty: false, revision: 0, cleanups: []};
        session.formGuards.set(form, guard);
        var mark = function (event) {
            if (event && event.isTrusted === false) return;
            if (event && event.target && event.target.closest && event.target.closest('table, .bootstrap-table, [data-admin-mobile-table], .admin-mobile-list-toolbar, .admin-mobile-pagination')) return;
            setPageFormDirty(session, form, true);
        };
        ['input', 'change', 'cut', 'paste', 'drop'].forEach(function (type) {
            form.addEventListener(type, mark, true);
            guard.cleanups.push(function () { form.removeEventListener(type, mark, true); });
        });
        var click = function (event) {
            if (event.isTrusted === false) return;
            if (event.target.closest('.layui-form-checkbox, .layui-form-radio, .layui-form-switch')) mark(event);
        };
        form.addEventListener('click', click, true);
        guard.cleanups.push(function () { form.removeEventListener('click', click, true); });
        var validateSave = function (event) {
            if (!event.target.closest('.save-data, [data-admin-mobile-validate-form]')) return;
            if (focusFormFieldError(form)) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        };
        form.addEventListener('click', validateSave, true);
        guard.cleanups.push(function () { form.removeEventListener('click', validateSave, true); });
        var clearInvalid = function (event) {
            var row = event.target && event.target.closest ? event.target.closest('.row.admin-mobile-form-field-error') : null;
            if (row) clearFormFieldError(row);
        };
        form.addEventListener('input', clearInvalid, true);
        form.addEventListener('change', clearInvalid, true);
        guard.cleanups.push(function () { form.removeEventListener('input', clearInvalid, true); form.removeEventListener('change', clearInvalid, true); });
        var reset = function () { window.setTimeout(function () { setPageFormDirty(session, form, false); }, 0); };
        form.addEventListener('reset', reset);
        guard.cleanups.push(function () { form.removeEventListener('reset', reset); });
        if (window.jQuery) {
            var namespace = '.adminMobilePageFormGuard';
            window.jQuery(form).off(namespace).on('select2:select' + namespace + ' select2:unselect' + namespace + ' xm-select' + namespace, mark);
            guard.cleanups.push(function () { window.jQuery(form).off(namespace); });
        }
    }

    function syncPageFormGuards(session) {
        var forms = pageGuardForms(session);
        Array.from(session.formGuards.keys()).forEach(function (form) {
            if (!form.isConnected || forms.indexOf(form) < 0) removePageFormGuard(session, form);
        });
        forms.forEach(function (form) { addPageFormGuard(session, form); });
        syncPageDirtyUi(session);
    }

    function confirmPageLeave(session, action) {
        if (!session || session !== activeSession || session.leaveBypass || !pageIsDirty(session)) {
            if (typeof action === 'function') action();
            return Promise.resolve(true);
        }
        if (session.leavePromise) return session.leavePromise;
        var result;
        if (window.Swal && typeof window.Swal.fire === 'function') {
            result = window.Swal.fire({
                title: '放弃未保存的修改？',
                text: '当前页面还有未保存的内容。',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '放弃修改',
                cancelButtonText: '继续编辑',
                reverseButtons: true,
                focusCancel: true,
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(function (answer) { return answer.isConfirmed === true || answer.value === true; });
        } else {
            result = Promise.resolve(window.confirm('当前页面还有未保存的内容，确定放弃修改吗？'));
        }
        session.leavePromise = result.then(function (confirmed) {
            session.leavePromise = null;
            if (!confirmed || session !== activeSession) return false;
            session.leaveBypass = true;
            if (session.leaveResetTimer) window.clearTimeout(session.leaveResetTimer);
            session.leaveResetTimer = window.setTimeout(function () {
                if (session === activeSession) {
                    session.leaveBypass = false;
                    syncPageDirtyUi(session);
                }
                session.leaveResetTimer = 0;
            }, 1800);
            releasePageHistoryGuard(session, action);
            return true;
        }, function () {
            session.leavePromise = null;
            return false;
        });
        return session.leavePromise;
    }

    function observeNativeActionBar(session, element) {
        function update() {
            if (session !== activeSession || !element || !element.isConnected) return;
            var height = Math.ceil(element.getBoundingClientRect().height);
            if (height > 0) document.documentElement.style.setProperty('--admin-mobile-native-actions-height', height + 'px');
        }
        session.nativeActionFrame = window.requestAnimationFrame(function () {
            session.nativeActionFrame = 0;
            update();
        });
        if (window.ResizeObserver) {
            session.nativeActionObserver = new ResizeObserver(update);
            session.nativeActionObserver.observe(element);
        }
    }

    function clearContextNavigation(session) {
        (session.contextNavigationSources || []).splice(0).reverse().forEach(function (entry) {
            if (!entry.node) return;
            restoreTemporaryAttribute(entry.node, entry.hidden);
            restoreTemporaryAttribute(entry.node, entry.marker);
        });
        var target = document.querySelector('[data-admin-mobile-context-tabs]');
        if (target && target.getAttribute('data-admin-mobile-workflow-navigation') === session.navigationOwner) {
            if (target.getAttribute('data-admin-mobile-workflow-owned') === 'true') {
                target.innerHTML = '';
                target.hidden = true;
                document.documentElement.removeAttribute('data-admin-mobile-has-tabs');
            }
            target.removeAttribute('data-admin-mobile-workflow-navigation');
            target.removeAttribute('data-admin-mobile-workflow-owned');
        }
        session.navigationOwner = '';
    }

    function clearSettingsNavigation(session) {
        if (!session) return;
        if (session.settingsNavigationObserver) session.settingsNavigationObserver.disconnect();
        session.settingsNavigationObserver = null;
        (session.settingsNavigationCleanups || []).splice(0).reverse().forEach(function (cleanup) {
            try { cleanup(); } catch (error) { console.error(error); }
        });
        session.settingsNavigation = null;
        session.settingsNavigationEntries = [];
        session.settingsNavigationTopOffset = 0;
    }

    function contextNavigationContainer(source) {
        if (!source || !source.closest) return source;
        return source.closest('.nav-tabs, .nav-pills, [role="tablist"], .layui-tab-title, #kt_toolbar .md-tabs, .sidebar-nav, .app-nav') || source;
    }

    function hideContextNavigationSources(session, sources, owner) {
        sources.map(contextNavigationContainer).filter(function (node, index, list) {
            return node && list.indexOf(node) === index;
        }).forEach(function (node) {
            session.contextNavigationSources.push({
                node: node,
                hidden: rememberTemporaryAttribute(node, 'hidden'),
                marker: rememberTemporaryAttribute(node, 'data-admin-mobile-context-source-hidden')
            });
            node.setAttribute('data-admin-mobile-context-source-hidden', owner);
            node.setAttribute('hidden', '');
        });
    }

    function clearPage(session) {
        if (session.pageObserver) session.pageObserver.disconnect();
        if (session.chartObserver) session.chartObserver.disconnect();
        if (session.chartFrame) window.cancelAnimationFrame(session.chartFrame);
        session.chartFrame = 0;
        session.chartTimers.splice(0).forEach(window.clearTimeout);
        session.pageObserver = null;
        session.chartObserver = null;
        clearSettingsNavigation(session);
        session.pageCleanups.splice(0).reverse().forEach(function (cleanup) {
            try { cleanup(); } catch (error) { console.error(error); }
        });
        session.suspendHistoryGuard = true;
        clearPageFormGuards(session);
        session.suspendHistoryGuard = false;
        clearGeneratedActions(session);
        clearContextNavigation(session);
        restoreMarks(session);
        session.root = null;
    }

    function markSemanticPage(session, recipe, behavior) {
        var root = session.root;
        var pageType = (recipe && recipe.pageType) || 'generic';
        var workflow = (recipe && recipe.workflow) || ('page-type-' + pageType);
        rememberClass(session, root, 'admin-mobile-page');
        rememberClass(session, root, 'admin-mobile-page--' + safeName(pageType));
        rememberClass(session, root, 'admin-mobile-workflow--' + safeName(workflow));
        rememberAttribute(session, root, 'data-admin-mobile-page-id', (recipe && recipe.id) || 'unmatched');
        rememberAttribute(session, root, 'data-admin-mobile-page-type', pageType);
        rememberAttribute(session, root, 'data-admin-mobile-page-workflow', workflow);
        rememberAttribute(session, root, 'data-admin-mobile-workflow-role', behavior.role);
        rememberAttribute(session, root, 'data-admin-mobile-workflow-source', recipe && recipe.workflow ? 'recipe' : 'page-type');
        rememberAttribute(session, root, 'data-admin-mobile-workflow-capabilities', ['navigation', behavior.pageActions && 'actions', behavior.forms && 'forms', behavior.charts && 'charts', behavior.table && 'table', behavior.longTask && 'long-task'].filter(Boolean).join(' '));

        var container = root.querySelector('#kt_content_container') || root;
        rememberClass(session, container, 'admin-mobile-page-content');
        root.querySelectorAll('.card').forEach(function (card, index) {
            var role = card.querySelector('form') ? 'form' : card.querySelector('table[id], .bootstrap-table') ? 'table' : card.querySelector('#statistics, [_echarts_instance_], [data-chart]') ? 'analytics' : card.querySelector('.nav-tabs, [role="tablist"]') ? 'navigation' : 'content';
            rememberClass(session, card, 'admin-mobile-page-card');
            rememberAttribute(session, card, 'data-admin-mobile-card-role', role);
            rememberAttribute(session, card, 'data-admin-mobile-card-index', index + 1);
        });
        if (behavior.forms) {
            root.querySelectorAll('form').forEach(function (form) {
                var fieldCount = form.querySelectorAll('input:not([type="hidden"]), select, textarea, [contenteditable="true"]').length;
                var complex = !!form.querySelector('textarea, input[type="file"], [contenteditable="true"], .CodeMirror, .ace_editor, [data-control="select2"]');
                rememberClass(session, form, 'admin-mobile-task-form');
                rememberAttribute(session, form, 'data-admin-mobile-form-mode', complex || fieldCount > 6 ? 'task' : 'compact');
            });
        }
    }

    function tabSources(root, behavior) {
        if (!behavior.navigation) return [];
        var internalSelectors = [
            '.nav-tabs .nav-link',
            '.nav-tabs button',
            '.nav-pills [role="tab"]',
            '[role="tablist"] [role="tab"]',
            '.layui-tab-title > li'
        ];
        var collect = function (selectors) {
            var seen = [];
            selectors.forEach(function (selector) {
                root.querySelectorAll(selector).forEach(function (source) {
                    if (seen.indexOf(source) < 0 && !source.closest('.admin-mobile-context-tabs, [data-admin-mobile-overlay-host]') && cloneLabel(source)) seen.push(source);
                });
            });
            return seen;
        };
        var internal = collect(internalSelectors).filter(function (source) { return !source.closest('#kt_toolbar'); });
        if (internal.length) return internal.slice(0, 24);
        var toolbar = collect(['#kt_toolbar .md-tabs a[href]', '#kt_toolbar .md-tabs button']);
        if (toolbar.length) return toolbar.slice(0, 24);
        if (behavior.role !== 'document-navigation') return [];
        var documentNavigation = collect(['.sidebar-nav a[href]', '.app-nav a[href]']);
        return documentNavigation.slice(0, 24);
    }

    function sourceIsActive(source) {
        if (!source) return false;
        if (source.classList.contains('active') || source.getAttribute('aria-selected') === 'true') return true;
        return !!(source.parentElement && source.parentElement.classList.contains('active'));
    }

    function coreConfigContextTab(source, recipe) {
        if (!recipe || !/^admin-config-(?:website|sms|email|other)$/.test(recipe.id || '') || !source || !source.matches('a[href]')) return null;
        var path = '';
        try {
            path = new URL(source.getAttribute('href'), window.location.href).pathname.replace(/\/+$/, '') || '/';
        } catch (error) {
            path = String(source.getAttribute('href') || '').split(/[?#]/)[0].replace(/\/+$/, '') || '/';
        }
        return {
            '/admin/config/index': {label: '基本设置', icon: 'tune'},
            '/admin/config/sms': {label: '短信设置', icon: 'sms'},
            '/admin/config/email': {label: '邮箱设置', icon: 'mail'},
            '/admin/config/other': {label: '其他设置', icon: 'settings'}
        }[path] || null;
    }

    function syncSettingsNavigation(session) {
        var navigation = session.root && session.root.querySelector('.admin-mobile-settings-nav');
        if (!navigation) {
            clearSettingsNavigation(session);
            return;
        }
        var entries = Array.from(navigation.querySelectorAll('a[href^="#"]')).map(function (link) {
            var id = String(link.getAttribute('href') || '').slice(1);
            var section = id ? document.getElementById(id) : null;
            return section && session.root.contains(section) ? {link: link, section: section} : null;
        }).filter(Boolean);
        if (!entries.length) {
            clearSettingsNavigation(session);
            return;
        }
        var rootStyle = window.getComputedStyle ? window.getComputedStyle(document.documentElement) : null;
        var appBarHeight = rootStyle ? parseFloat(rootStyle.getPropertyValue('--admin-mobile-appbar-height')) || 0 : 0;
        var tabHeight = rootStyle ? parseFloat(rootStyle.getPropertyValue('--admin-mobile-tabs-height')) || 0 : 0;
        var navigationHeight = Math.ceil(navigation.getBoundingClientRect().height || 0);
        var sectionStyle = window.getComputedStyle ? window.getComputedStyle(entries[0].section) : null;
        var sectionScrollMargin = sectionStyle ? parseFloat(sectionStyle.scrollMarginTop) || 0 : 0;
        var topOffset = Math.ceil(Math.max(
            appBarHeight + tabHeight + navigationHeight + 8,
            sectionScrollMargin + 2
        ));
        if (
            session.settingsNavigation === navigation &&
            session.settingsNavigationTopOffset === topOffset &&
            session.settingsNavigationEntries &&
            session.settingsNavigationEntries.length === entries.length &&
            session.settingsNavigationEntries.every(function (entry, index) {
                return entry.link === entries[index].link && entry.section === entries[index].section;
            })
        ) return;

        clearSettingsNavigation(session);
        session.settingsNavigation = navigation;
        session.settingsNavigationTopOffset = topOffset;
        session.settingsNavigationEntries = entries;

        var setActive = function (activeEntry, center) {
            if (!activeEntry) return;
            entries.forEach(function (entry) {
                var active = entry === activeEntry;
                entry.link.classList.remove('active');
                entry.link.classList.toggle('is-active', active);
                if (active) entry.link.setAttribute('aria-current', 'location');
                else entry.link.removeAttribute('aria-current');
            });
            if (center && activeEntry.link.isConnected) {
                activeEntry.link.scrollIntoView({behavior: 'smooth', block: 'nearest', inline: 'center'});
            }
        };
        var updateActive = function () {
            var anchor = topOffset + 2;
            var activeEntry = entries[0];
            var pageBottom = Math.max(
                document.documentElement ? document.documentElement.scrollHeight : 0,
                document.body ? document.body.scrollHeight : 0
            );
            entries.forEach(function (entry) {
                if (entry.section.getBoundingClientRect().top <= anchor) activeEntry = entry;
            });
            if (window.scrollY + window.innerHeight >= pageBottom - 2) {
                activeEntry = entries[entries.length - 1];
            }
            setActive(activeEntry, false);
        };

        entries.forEach(function (entry) {
            var click = function (event) {
                event.preventDefault();
                setActive(entry, true);
                entry.section.scrollIntoView({
                    behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                    block: 'start',
                    inline: 'nearest'
                });
            };
            entry.link.addEventListener('click', click);
            session.settingsNavigationCleanups.push(function () { entry.link.removeEventListener('click', click); });
        });
        if (window.IntersectionObserver) {
            session.settingsNavigationObserver = new IntersectionObserver(updateActive, {
                root: null,
                rootMargin: '-' + topOffset + 'px 0px -20% 0px',
                threshold: [0, 1]
            });
            entries.forEach(function (entry) { session.settingsNavigationObserver.observe(entry.section); });
        }
        var scrollFrame = 0;
        var onScroll = function () {
            if (scrollFrame) return;
            scrollFrame = window.requestAnimationFrame(function () {
                scrollFrame = 0;
                if (navigation.isConnected) updateActive();
            });
        };
        window.addEventListener('scroll', onScroll, {passive: true});
        session.settingsNavigationCleanups.push(function () {
            window.removeEventListener('scroll', onScroll);
            if (scrollFrame) window.cancelAnimationFrame(scrollFrame);
        });
        updateActive();
    }

    function syncContextNavigation(session, recipe, behavior) {
        clearContextNavigation(session);
        var target = document.querySelector('[data-admin-mobile-context-tabs]');
        if (!target || !behavior.navigation) return;
        var sources = tabSources(session.root, behavior);
        var owner = (recipe && recipe.id) || 'generic';
        if (!sources.length) {
            if (target.getAttribute('data-admin-mobile-workflow-owned') === 'true') {
                target.innerHTML = '';
                target.hidden = true;
                target.removeAttribute('data-admin-mobile-workflow-navigation');
                target.removeAttribute('data-admin-mobile-workflow-owned');
                document.documentElement.removeAttribute('data-admin-mobile-has-tabs');
                if (api.shell && typeof api.shell.refresh === 'function') api.shell.refresh();
            }
            if (target.children.length) {
                target.setAttribute('data-admin-mobile-workflow-navigation', owner);
                target.setAttribute('data-admin-mobile-workflow-owned', 'false');
                session.navigationOwner = owner;
            }
            return;
        }
        target.innerHTML = '';
        sources.forEach(function (source) {
            var tab = document.createElement('button');
            var active = sourceIsActive(source);
            var presentation = coreConfigContextTab(source, recipe);
            tab.type = 'button';
            tab.className = 'admin-mobile-context-tab' + (active ? ' is-active' : '');
            tab.setAttribute('aria-current', active ? 'page' : 'false');
            if (presentation) {
                var icon = document.createElement('span');
                icon.className = 'material-icons-outlined me-1';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = presentation.icon;
                tab.appendChild(icon);
                tab.appendChild(document.createTextNode(presentation.label));
            } else {
                tab.textContent = cloneLabel(source);
            }
            tab.addEventListener('click', function () {
                if (source.isConnected) {
                    var href = source.matches('a[href]') ? String(source.getAttribute('href') || '').trim() : '';
                    if (href && href.charAt(0) !== '#' && !/^javascript:/i.test(href) && typeof api.navigate === 'function') {
                        api.navigate(href);
                    } else {
                        source.click();
                    }
                }
                schedule(session, 'context-tab');
                scheduleChartResize(session, 60);
            });
            target.appendChild(tab);
        });
        target.hidden = false;
        target.setAttribute('data-admin-mobile-workflow-navigation', owner);
        target.setAttribute('data-admin-mobile-workflow-owned', 'true');
        document.documentElement.setAttribute('data-admin-mobile-has-tabs', '');
        session.navigationOwner = owner;
        hideContextNavigationSources(session, sources, owner);
        var active = target.querySelector('.is-active');
        if (active) window.requestAnimationFrame(function () {
            if (active.isConnected) active.scrollIntoView({block: 'nearest', inline: 'center'});
        });
    }

    function hasBusinessTable(root) {
        if (root.querySelector('[data-admin-mobile-table], .bootstrap-table')) return true;
        return Array.from(root.querySelectorAll('table[id]')).some(function (table) {
            return !!table.id && !table.closest('[data-admin-mobile-overlay-host], .layui-layer, .modal');
        });
    }

    function addControl(found, control, configured, group) {
        if (!control || found.some(function (item) { return item.control === control; }) || !visible(control)) return;
        var originalLabel = cloneLabel(control);
        var label = plainText(isBusy(control) ? originalLabel : (configured && configured.label) || originalLabel);
        if (!label) return;
        found.push({
            control: control,
            label: label,
            configured: configured || null,
            group: group || 'discovered',
            priority: group === 'primary' ? 0 : group === 'toolbar' ? 1 : group === 'more' ? 2 : /(?:保存|提交|发布|save|submit|publish)/i.test(label) ? 1 : 3,
            danger: isDanger(control, label, configured),
            busy: isBusy(control),
            disabled: isDisabled(control)
        });
    }

    function controlIsInlineAction(control, recipe) {
        if (!control) return false;
        if (control.closest('[data-admin-mobile-action-placement="inline"]')) return true;
        return ((((recipe || {}).actions || {}).inline) || []).some(function (configured) {
            if (!configured || !configured.selector) return false;
            try { return control.matches(configured.selector); } catch (error) { return false; }
        });
    }

    function discoverActions(root, recipe) {
        var found = [];
        ['primary', 'toolbar', 'more'].forEach(function (group) {
            (((recipe || {}).actions || {})[group] || []).forEach(function (configured) {
                if (!configured.selector) return;
                try {
                    root.querySelectorAll(configured.selector).forEach(function (control) {
                        if (!controlIsInlineAction(control, recipe)) addControl(found, control, configured, group);
                    });
                } catch (error) {}
            });
        });
        root.querySelectorAll(CONTROL_SELECTOR).forEach(function (control) {
            if (controlIsInlineAction(control, recipe)) return;
            if (control.matches('label') && !control.querySelector('input[type="file"]')) return;
            if (control.matches('label') && control.querySelector('input[type="file"]')) {
                addControl(found, control, null, 'discovered');
                return;
            }
            if (control.matches('input[type="button"], input[type="submit"]') || (control.matches('button') && control.type === 'submit')) {
                addControl(found, control, null, 'discovered');
                return;
            }
            var signature = [cloneLabel(control), control.className, control.id, control.getAttribute('data-action'), control.getAttribute('title')].join(' ');
            if (ACTION_HINT.test(signature)) addControl(found, control, null, 'discovered');
        });
        var labelCounts = found.reduce(function (counts, action) {
            counts[action.label] = (counts[action.label] || 0) + 1;
            return counts;
        }, Object.create(null));
        found.forEach(function (action) {
            if (labelCounts[action.label] < 2) return;
            var row = action.control.closest('.row, .form-group');
            var fieldLabel = row && row.querySelector('.col-form-label, [data-field-label]');
            var context = fieldLabel && plainText(fieldLabel.textContent).replace(/[：:]$/, '');
            if (context && action.label.indexOf(context) < 0) action.label = context + ' · ' + action.label;
        });
        return found.sort(function (a, b) { return a.priority - b.priority; });
    }

    function actionIcon(action) {
        var label = action.label;
        var configuredIcon = plainText(action.configured && action.configured.icon);
        if (/^[a-z0-9_]+$/i.test(configuredIcon)) return configuredIcon;
        if (action.danger) return 'warning';
        if (/(?:保存|提交|发布|save|submit|publish)/i.test(label)) return 'check_circle';
        if (/(?:上传|导入|upload|import)/i.test(label)) return 'upload';
        if (/(?:下载|导出|download|export)/i.test(label)) return 'download';
        if (/(?:同步|更新|刷新|sync|update|refresh)/i.test(label)) return 'sync';
        if (/(?:测试|test)/i.test(label)) return 'science';
        return 'arrow_forward';
    }

    function runOriginal(action) {
        var control = action && action.control;
        if (!control || !control.isConnected || isDisabled(control)) return false;
        var file = control.matches('label') && control.querySelector('input[type="file"]');
        if (file) file.click(); else control.click();
        return true;
    }

    function nativeActionBar(actions) {
        var groups = [];
        actions.forEach(function (action) {
            var footer = action.control.closest('.card-footer, [data-admin-mobile-native-action-bar]');
            if (!footer) return;
            var style = window.getComputedStyle ? window.getComputedStyle(footer) : null;
            if (!footer.hasAttribute('data-admin-mobile-native-action-bar') && (!style || style.position !== 'sticky')) return;
            var group = groups.find(function (item) { return item.element === footer; });
            if (!group) {
                group = {element: footer, actions: []};
                groups.push(group);
            }
            group.actions.push(action);
        });
        groups.sort(function (a, b) { return b.actions.length - a.actions.length; });
        if (!groups.length) return null;
        groups[0].remaining = actions.filter(function (action) { return groups[0].actions.indexOf(action) < 0; });
        return groups[0];
    }

    function sheetActions(actions) {
        return actions.map(function (action) {
            return {
                label: action.label,
                icon: actionIcon(action),
                danger: action.danger,
                disabled: action.disabled,
                description: plainText(action.configured && action.configured.description) || (action.busy ? '当前任务正在处理' : ''),
                run: function () { return runOriginal(action); }
            };
        });
    }

    function renderPageActions(session, recipe, behavior) {
        clearGeneratedActions(session);
        if (!behavior.pageActions) return;
        var tablePage = hasBusinessTable(session.root);
        var actions = discoverActions(session.root, recipe);
        // The personal avatar picker must remain the original visible label.
        // Moving it into an action Sheet delays input.click() until after the
        // Sheet/history transition, which can lose Chrome's user activation.
        if (recipe && recipe.id === 'admin-personal') {
            actions = actions.filter(function (action) {
                return !(action.control.matches('label') && action.control.querySelector('input[type="file"]'));
            });
        }
        if (!actions.length) return;
        actions.forEach(function (action) {
            rememberAttribute(session, action.control, 'data-admin-mobile-original-action', action.group);
            if (behavior.longTask || LONG_TASK_HINT.test(action.label)) rememberAttribute(session, action.control, 'data-admin-mobile-operation-bridge', 'original');
        });
        var nativeBar = nativeActionBar(actions);
        if (nativeBar) {
            var totalNativeActions = nativeBar.actions.length + nativeBar.remaining.length;
            if (!tablePage && totalNativeActions > 2) {
                var overflowActions = nativeBar.actions.slice(1).concat(nativeBar.remaining).sort(function (a, b) {
                    return Number(a.danger) - Number(b.danger) || a.priority - b.priority;
                });
                nativeBar.actions = nativeBar.actions.slice(0, 1);
                nativeBar.remaining = overflowActions;
                overflowActions.forEach(function (action) {
                    action.control.setAttribute('data-admin-mobile-action-in-sheet', '');
                    session.hiddenNativeActions.push(action.control);
                });
            }
            nativeBar.element.setAttribute('data-admin-mobile-native-actions', (recipe && recipe.id) || 'generic');
            nativeBar.element.classList.add('admin-mobile-page-action-source');
            session.nativeActionBar = nativeBar.element;
            if (nativeBar.remaining.length && !tablePage) {
                nativeBar.remaining.forEach(function (action) {
                    hideBridgedActionSource(session, action.control, (recipe && recipe.id) || 'generic');
                });
                var nativeMore = document.createElement('button');
                nativeMore.type = 'button';
                nativeMore.className = 'btn btn-light';
                nativeMore.setAttribute('data-admin-mobile-page-actions-generated', (recipe && recipe.id) || 'generic');
                nativeMore.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">more_horiz</span><span>更多操作</span>';
                nativeMore.addEventListener('click', function () {
                    api.openActions({
                        id: 'page-actions-' + ((recipe && recipe.id) || 'generic'),
                        title: (recipe && recipe.title) || '页面操作',
                        actions: sheetActions(nativeBar.remaining)
                    });
                });
                nativeBar.element.appendChild(nativeMore);
                session.generatedActions = nativeMore;
            }
            observeNativeActionBar(session, nativeBar.element);
            return;
        }
        if (tablePage) return;

        var toolbar = document.createElement('section');
        toolbar.className = 'admin-mobile-list-toolbar admin-mobile-page-actions';
        toolbar.setAttribute('data-admin-mobile-page-actions-generated', (recipe && recipe.id) || 'generic');
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', '页面操作');
        actions.forEach(function (action) {
            hideBridgedActionSource(session, action.control, (recipe && recipe.id) || 'generic');
        });
        var direct = actions.length <= 2 ? actions : actions.slice(0, 1);
        direct.forEach(function (action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.classList.toggle('is-primary', action.priority <= 1 && !action.danger);
            button.classList.toggle('is-danger', action.danger);
            button.disabled = action.disabled;
            if (action.busy) button.setAttribute('aria-busy', 'true');
            button.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">' + actionIcon(action) + '</span><span></span>';
            button.querySelector('span:last-child').textContent = action.label;
            button.addEventListener('click', function () { runOriginal(action); });
            toolbar.appendChild(button);
        });
        if (actions.length > direct.length) {
            var more = document.createElement('button');
            more.type = 'button';
            more.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">more_horiz</span><span>更多操作</span>';
            more.addEventListener('click', function () {
                api.openActions({
                    id: 'page-actions-' + ((recipe && recipe.id) || 'generic'),
                    title: (recipe && recipe.title) || '页面操作',
                    actions: sheetActions(actions.slice(direct.length))
                });
            });
            toolbar.appendChild(more);
        }
        var container = session.root.querySelector('#kt_content_container') || session.root.querySelector('#kt_post') || session.root;
        container.insertBefore(toolbar, container.firstElementChild);
        session.generatedActions = toolbar;
    }

    function markLongTaskSources(session, behavior) {
        if (!behavior.longTask) return;
        session.root.querySelectorAll(CONTROL_SELECTOR).forEach(function (control) {
            if (control.matches('label') && !control.querySelector('input[type="file"]')) return;
            if (!visible(control)) return;
            var signature = [cloneLabel(control), control.className, control.id].join(' ');
            if (!LONG_TASK_HINT.test(signature) && !isBusy(control)) return;
            rememberAttribute(session, control, 'data-admin-mobile-operation-bridge', 'original');
            rememberAttribute(session, control, 'data-admin-mobile-operation-state', isBusy(control) ? 'busy' : 'ready');
        });
    }

    function chartElements(root) {
        var selectors = '#statistics, [_echarts_instance_], [data-echarts], [data-chart], .echart, .echarts';
        if (!root || typeof root.querySelectorAll !== 'function') return [];
        return Array.from(root.querySelectorAll(selectors)).filter(function (element, index, list) { return list.indexOf(element) === index; });
    }

    function resizeCharts(session) {
        if (!session.behavior || !session.behavior.charts || !window.echarts || typeof window.echarts.getInstanceByDom !== 'function') return;
        chartElements(session.root).forEach(function (element) {
            if (!element.isConnected || element.clientWidth < 1 || element.clientHeight < 1) return;
            var instance = window.echarts.getInstanceByDom(element);
            if (!instance || (typeof instance.isDisposed === 'function' && instance.isDisposed())) return;
            try { instance.resize({animation: {duration: 0}}); } catch (error) { console.error(error); }
        });
    }

    function scheduleChartResize(session, delay) {
        if (!session || session !== activeSession || !session.behavior || !session.behavior.charts) return;
        var timer = window.setTimeout(function () {
            var index = session.chartTimers.indexOf(timer);
            if (index >= 0) session.chartTimers.splice(index, 1);
            if (session !== activeSession) return;
            if (session.chartFrame) window.cancelAnimationFrame(session.chartFrame);
            session.chartFrame = window.requestAnimationFrame(function () {
                session.chartFrame = 0;
                resizeCharts(session);
            });
        }, delay || 0);
        session.chartTimers.push(timer);
    }

    function syncChartObserver(session) {
        if (session.chartObserver) session.chartObserver.disconnect();
        session.chartObserver = null;
        if (!session.behavior.charts || typeof window.ResizeObserver !== 'function') return;
        session.chartObserver = new ResizeObserver(function () { scheduleChartResize(session, 0); });
        chartElements(session.root).forEach(function (element) { session.chartObserver.observe(element); });
    }

    function observePage(session) {
        if (session.pageObserver) session.pageObserver.disconnect();
        session.pageObserver = new MutationObserver(function (mutations) {
            var relevant = mutations.some(function (mutation) {
                return !mutation.target.closest || !mutation.target.closest('[data-admin-mobile-page-actions-generated], [data-admin-mobile-context-tabs]');
            });
            if (relevant) schedule(session, 'mutation');
        });
        session.pageObserver.observe(session.root, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class', 'style', 'hidden', 'disabled', 'aria-disabled', 'aria-busy', 'aria-selected', '_echarts_instance_']
        });
    }

    function refreshPage(session) {
        if (session !== activeSession) return;
        if (!api.isEnabled()) {
            if (session.root) clearPage(session);
            return;
        }
        var root = pageRoot();
        if (session.root !== root) {
            clearPage(session);
            session.root = root;
            bind(session, root, 'click', function (event) {
                if (event.target.closest('.nav-tabs, [role="tablist"], .layui-tab-title, [data-admin-mobile-original-action]')) schedule(session, 'page-click');
            }, true, true);
        }
        if (session.pageObserver) session.pageObserver.disconnect();
        clearGeneratedActions(session);
        var recipe = api.matchRecipe(api.getContext()) || api.getActiveRecipe();
        var behavior = behaviorFor(recipe);
        session.recipe = recipe;
        session.behavior = behavior;
        markSemanticPage(session, recipe, behavior);
        syncPageFormGuards(session);
        syncContextNavigation(session, recipe, behavior);
        syncSettingsNavigation(session);
        renderPageActions(session, recipe, behavior);
        markLongTaskSources(session, behavior);
        syncChartObserver(session);
        observePage(session);
        scheduleChartResize(session, 0);
        scheduleChartResize(session, 120);
        scheduleChartResize(session, 360);
        api.emit('admin:mobile:workflowready', {recipe: recipe, behavior: behavior, root: session.root});
    }

    function schedule(session, reason) {
        if (!session || session !== activeSession || session.refreshFrame) return;
        session.refreshFrame = window.requestAnimationFrame(function () {
            session.refreshFrame = 0;
            refreshPage(session, reason);
        });
    }

    function mount() {
        if (activeSession) unmount();
        var session = activeSession = {
            root: null,
            recipe: null,
            behavior: null,
            marks: [],
            cleanups: [],
            pageCleanups: [],
            generatedActions: null,
            nativeActionBar: null,
            nativeActionObserver: null,
            nativeActionFrame: 0,
            hiddenNativeActions: [],
            bridgedActionSources: [],
            navigationOwner: '',
            contextNavigationSources: [],
            settingsNavigation: null,
            settingsNavigationEntries: [],
            settingsNavigationObserver: null,
            settingsNavigationCleanups: [],
            settingsNavigationTopOffset: 0,
            pageObserver: null,
            chartObserver: null,
            refreshFrame: 0,
            chartFrame: 0,
            chartTimers: [],
            formGuards: new Map(),
            leaveBypass: false,
            leavePromise: null,
            leaveResetTimer: 0,
            suspendHistoryGuard: false,
            historyGuardToken: 'admin-mobile-page-' + Date.now() + '-' + Math.random().toString(36).slice(2),
            historyGuardUrl: '',
            historyGuardActive: false,
            historyGuardReleasing: false,
            historyGuardRetired: false,
            historyGuardPendingRelease: false,
            historyGuardReleaseAction: null,
            historyGuardReleaseTimer: 0
        };
        var viewportHandler = function () { scheduleChartResize(session, 0); schedule(session, 'viewport'); };
        var tabHandler = function () { schedule(session, 'tab'); scheduleChartResize(session, 60); scheduleChartResize(session, 240); };
        bind(session, window, 'resize', viewportHandler, {passive: true});
        bind(session, window, 'orientationchange', viewportHandler, {passive: true});
        bind(session, document, 'admin:mobile:viewportchange', viewportHandler);
        bind(session, document, 'shown.bs.tab', tabHandler);
        bind(session, document, 'shown.bs.collapse', tabHandler);
        bind(session, document, 'visibilitychange', function () { if (!document.hidden) scheduleChartResize(session, 0); });
        bind(session, document, 'admin:mobile:form-dirty', function (event) {
            var form = event.detail && event.detail.form;
            if (form && session.formGuards.has(form)) setPageFormDirty(session, form, true);
        });
        bind(session, document, 'admin:mobile:form-saved', function (event) {
            var form = event.detail && event.detail.form;
            var revision = event.detail && event.detail.revision;
            if (form && session.formGuards.has(form)) setPageFormDirty(session, form, false, revision);
        });
        bind(session, window, 'beforeunload', function (event) {
            if (!pageIsDirty(session)) return;
            event.preventDefault();
            event.returnValue = '';
        });
        bind(session, window, 'popstate', handlePagePopstate);
        if (window.jQuery) {
            window.jQuery(document)
                .off('.adminMobilePageWorkflows')
                .on('pjax:complete.adminMobilePageWorkflows pjax:end.adminMobilePageWorkflows', function () { schedule(session, 'pjax'); })
                .on('pjax:click.adminMobilePageWorkflows', function (event, options) {
                    if (!pageIsDirty(session)) return;
                    event.preventDefault();
                    var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
                    var href = (options && options.url) || (link && link.href);
                    if (href) confirmPageLeave(session, function () { api.navigate(href); });
                })
                .on('shown.bs.tab.adminMobilePageWorkflows shown.bs.collapse.adminMobilePageWorkflows', tabHandler);
            addCleanup(session, function () { window.jQuery(document).off('.adminMobilePageWorkflows'); });
        } else {
            bind(session, document, 'pjax:complete', function () { schedule(session, 'pjax'); });
            bind(session, document, 'pjax:end', function () { schedule(session, 'pjax'); });
        }
        refreshPage(session);
        schedule(session, 'after-shell');
    }

    function unmount() {
        var session = activeSession;
        if (!session) return;
        releasePageHistoryGuard(session);
        activeSession = null;
        if (session.refreshFrame) window.cancelAnimationFrame(session.refreshFrame);
        if (session.chartFrame) window.cancelAnimationFrame(session.chartFrame);
        if (session.historyGuardReleaseTimer) window.clearTimeout(session.historyGuardReleaseTimer);
        session.chartTimers.splice(0).forEach(window.clearTimeout);
        clearPage(session);
        session.cleanups.splice(0).reverse().forEach(function (cleanup) {
            try { cleanup(); } catch (error) { console.error(error); }
        });
    }

    var workflow = {
        id: 'admin-mobile-page-workflows',
        match: function () { return true; },
        mount: mount,
        unmount: unmount,
        onRoute: function () { if (activeSession) schedule(activeSession, 'route'); },
        onTable: function () { if (activeSession) schedule(activeSession, 'table'); }
    };

    api.pageWorkflows = {
        profiles: WORKFLOW_PROFILES,
        pageTypes: PAGE_TYPE_PROFILES,
        refresh: function () { if (activeSession) schedule(activeSession, 'manual'); },
        isDirty: function () { return pageIsDirty(activeSession); },
        requestLeave: function (action) { return confirmPageLeave(activeSession, action); },
        markDirty: function (form) { if (activeSession && form) setPageFormDirty(activeSession, form, true); },
        markSaved: function (form, revision) { if (activeSession && form) setPageFormDirty(activeSession, form, false, revision); },
        getRevision: function (form) {
            var guard = activeSession && activeSession.formGuards.get(form);
            return guard ? guard.revision : null;
        },
        isRevisionCurrent: function (form, revision) {
            var guard = activeSession && activeSession.formGuards.get(form);
            return !guard || revision === null || revision === undefined || guard.revision === Number(revision);
        },
        validateForm: function (form) { return focusFormFieldError(form); },
        focusFormError: function (form, message) { return focusFormFieldError(form, message, false); }
    };
    api.registerWorkflow(workflow);
}(window, document));
