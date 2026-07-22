(function (window, document) {
    'use strict';

    var api = window.AdminMobile;
    if (!api) return;
    var host = null;
    var backdrop = null;
    var rootElement = null;
    var stack = [];
    var popupStack = [];
    var token = 0;
    var mounted = false;
    var ignoredPopstates = [];
    var inertRecords = new Map();
    var lastEditableFocus = null;
    var navigationPending = false;
    var PAGE_HISTORY_GUARD_KEY = 'adminMobilePageGuard';

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
        });
    }

    function plainText(value) {
        var template = document.createElement('template');
        template.innerHTML = String(value == null ? '' : value);
        return (template.content.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function actionMaterialIcon(value, label, fallbackIcon) {
        var icon = plainText(value);
        if (icon && !/\s/.test(icon) && !/^fa-/i.test(icon)) return icon;
        var signature = (icon + ' ' + label).toLowerCase();
        if (/(?:circle-check|补单|确认|完成)/.test(signature)) return 'check_circle';
        if (/(?:truck|发货)/.test(signature)) return 'local_shipping';
        if (/(?:copy|clone|复制)/.test(signature)) return 'content_copy';
        if (/(?:eye|查看|详情)/.test(signature)) return 'visibility';
        if (/(?:trash|delete|删除|清理)/.test(signature)) return 'delete_forever';
        return fallbackIcon;
    }

    function allowedInlineIcon(value) {
        if (typeof value !== 'string' || value.indexOf('md-message-send-icon') < 0) return null;
        var template = document.createElement('template');
        template.innerHTML = value;
        var source = template.content.querySelector('svg.md-message-send-icon');
        if (!source) return null;
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'md-message-send-icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        Array.from(source.querySelectorAll('path')).slice(0, 4).forEach(function (sourcePath) {
            var d = sourcePath.getAttribute('d') || '';
            if (!d || !/^[MmLlHhVvCcSsQqTtAaZz0-9eE+.,\-\s]+$/.test(d)) return;
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        });
        return svg.childNodes.length ? svg : null;
    }

    function ensure() {
        rootElement = api.shell && api.shell.ensure();
        if (!rootElement) return false;
        host = rootElement.querySelector('[data-admin-mobile-overlay-host]');
        backdrop = rootElement.querySelector('[data-admin-mobile-backdrop]');
        return !!host;
    }

    function resolveLayerElement(value, layerIndex) {
        var element = value;
        var depth = 0;
        while (element && element.nodeType !== 1 && depth++ < 4) {
            if (element.jquery && typeof element.get === 'function') element = element.get(0);
            else if (element[0]) element = element[0];
            else break;
        }
        if (!element || element.nodeType !== 1) {
            element = document.getElementById('layui-layer' + layerIndex);
        }
        return element && element.nodeType === 1 ? element : null;
    }

    function allEntries(includeClosing) {
        return stack.concat(popupStack).filter(function (entry) {
            return includeClosing === true || !entry.closing;
        });
    }

    function topEntry() {
        return allEntries(false).sort(function (left, right) { return left.order - right.order; }).pop() || null;
    }

    function elementZIndex(element) {
        if (!element) return 0;
        var value = parseInt(window.getComputedStyle(element).zIndex, 10);
        return Number.isFinite(value) ? value : 0;
    }

    function syncSheetLayering() {
        if (!host || !backdrop) return;
        var connectedEntries = stack.concat(popupStack).filter(function (entry) {
            return entry && entry.element && entry.element.isConnected;
        }).sort(function (left, right) { return left.order - right.order; });
        var active = connectedEntries[connectedEntries.length - 1];
        var popupZIndex = active && active.kind === 'sheet' ? connectedEntries.reduce(function (maximum, entry) {
            return entry.kind === 'popup' && entry.order < active.order
                ? Math.max(maximum, elementZIndex(entry.element))
                : maximum;
        }, 0) : 0;
        if (!popupZIndex) {
            host.style.removeProperty('z-index');
            backdrop.style.removeProperty('z-index');
            return;
        }
        backdrop.style.zIndex = String(popupZIndex + 1);
        host.style.zIndex = String(popupZIndex + 2);
    }

    function setManagedInert(element, inert) {
        if (!element) return;
        var record = inertRecords.get(element);
        if (inert) {
            if (!record) {
                record = {
                    hadInert: element.hasAttribute('inert'),
                    ariaHidden: element.getAttribute('aria-hidden')
                };
                inertRecords.set(element, record);
            }
            element.setAttribute('inert', '');
            element.setAttribute('aria-hidden', 'true');
            return;
        }
        if (!record) return;
        if (!record.hadInert) element.removeAttribute('inert');
        if (record.ariaHidden === null) element.removeAttribute('aria-hidden');
        else element.setAttribute('aria-hidden', record.ariaHidden);
        inertRecords.delete(element);
    }

    function syncModalState() {
        var entries = allEntries(true);
        var active = topEntry();
        var desired = new Set();
        syncSheetLayering();
        if (entries.length) {
            ['#pjax-container', '#kt_aside', '#kt_header', '[data-admin-mobile-appbar]', '[data-admin-mobile-bottom-nav]', '[data-admin-mobile-context-tabs]'].forEach(function (selector) {
                var element = document.querySelector(selector);
                if (element) desired.add(element);
            });
            entries.forEach(function (entry) {
                if (entry.element && (entry !== active || entry.closing || entry.confirming)) desired.add(entry.element);
            });
        }
        Array.from(inertRecords.keys()).forEach(function (element) {
            if (!desired.has(element)) setManagedInert(element, false);
        });
        desired.forEach(function (element) { setManagedInert(element, true); });

        var hasSheet = stack.some(function (entry) { return !entry.closing; });
        if (backdrop) {
            backdrop.hidden = !hasSheet;
            backdrop.classList.toggle('is-open', hasSheet);
        }
        if (document.body) document.body.classList.toggle('admin-mobile-overlay-open', entries.length > 0);
    }

    function focusables(element) {
        if (!element) return [];
        var selector = 'button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, iframe, [contenteditable="true"], [tabindex]:not([tabindex="-1"])';
        return Array.from(element.querySelectorAll(selector)).filter(function (node) {
            return !node.closest('[inert], [aria-hidden="true"], [hidden]') && (node.offsetParent !== null || node === document.activeElement);
        });
    }

    function focusEntry(entry, preferred) {
        if (!entry || !entry.element || entry.closing) return;
        var target = preferred || entry.element.querySelector('[autofocus]') || focusables(entry.element)[0] || entry.element;
        if (!target.hasAttribute('tabindex') && target === entry.element) target.setAttribute('tabindex', '-1');
        window.setTimeout(function () {
            if (document.contains(target) && typeof target.focus === 'function') target.focus({preventScroll: true});
        }, 30);
    }

    function restoreFocus(entry, wasTop) {
        if (!wasTop) return;
        var active = topEntry();
        if (active) {
            if (entry.focus && active.element && active.element.contains(entry.focus) && typeof entry.focus.focus === 'function') {
                window.setTimeout(function () { entry.focus.focus({preventScroll: true}); }, 0);
            } else {
                focusEntry(active);
            }
        } else if (entry.focus && document.contains(entry.focus) && typeof entry.focus.focus === 'function') {
            window.setTimeout(function () { entry.focus.focus({preventScroll: true}); }, 0);
        }
    }

    function renderContent(target, content) {
        if (content instanceof window.Node) target.appendChild(content);
        else target.innerHTML = String(content || '');
    }

    function historyKey(entry) {
        return entry && entry.kind === 'popup' ? 'adminMobilePopup' : 'adminMobileOverlay';
    }

    function historyMatches(entry) {
        return !!(entry && window.history.state && window.history.state[historyKey(entry)] === entry.token);
    }

    function pushHistory(entry, replace) {
        var state = Object.assign({}, window.history.state || {});
        // A page-level unsaved-form sentinel must identify only its own
        // history entry. Inheriting it into a Sheet/Popup entry makes the page
        // guard mistake the overlay for its sentinel and rewind just one of
        // two entries when a navigation is confirmed.
        delete state[PAGE_HISTORY_GUARD_KEY];
        state[historyKey(entry)] = entry.token;
        if (replace) window.history.replaceState(state, '', window.location.href);
        else window.history.pushState(state, '', window.location.href);
        entry.historyPushed = true;
    }

    function restorePoppedHistory(entry) {
        if (!entry || entry.closing || historyMatches(entry)) return;
        var state = Object.assign({}, window.history.state || {});
        delete state[PAGE_HISTORY_GUARD_KEY];
        state[historyKey(entry)] = entry.token;
        window.history.pushState(state, '', window.location.href);
        entry.historyPushed = true;
    }

    function ignoreNextPopstate() {
        var marker = {timer: 0};
        marker.timer = window.setTimeout(function () {
            var index = ignoredPopstates.indexOf(marker);
            if (index >= 0) ignoredPopstates.splice(index, 1);
        }, 1200);
        ignoredPopstates.push(marker);
        return marker;
    }

    function consumeIgnoredPopstate() {
        var marker = ignoredPopstates.shift();
        if (!marker) return false;
        window.clearTimeout(marker.timer);
        return true;
    }

    function rewindHistory(steps, action) {
        if (steps < 1) {
            action();
            return;
        }
        var finished = false;
        var fallbackTimer = 0;
        var finish = function () {
            if (finished) return;
            finished = true;
            window.clearTimeout(fallbackTimer);
            window.removeEventListener('popstate', finish);
            action();
        };
        ignoreNextPopstate();
        window.addEventListener('popstate', finish, {once: true});
        window.history.go(-steps);
        fallbackTimer = window.setTimeout(finish, 500);
    }

    function cleanupEntry(entry) {
        (entry.cleanups || []).splice(0).forEach(function (cleanup) {
            try { cleanup(); } catch (error) { console.error(error); }
        });
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function cssTime(value) {
        value = String(value || '').trim();
        if (!value) return 0;
        if (value.slice(-2) === 'ms') return parseFloat(value) || 0;
        if (value.slice(-1) === 's') return (parseFloat(value) || 0) * 1000;
        return parseFloat(value) || 0;
    }

    function transitionTime(element) {
        if (!element || reducedMotion()) return 0;
        var style = window.getComputedStyle(element);
        var durations = String(style.transitionDuration || '0s').split(',');
        var delays = String(style.transitionDelay || '0s').split(',');
        var count = Math.max(durations.length, delays.length);
        var maximum = 0;
        for (var index = 0; index < count; index++) {
            maximum = Math.max(maximum, cssTime(durations[index % durations.length]) + cssTime(delays[index % delays.length]));
        }
        return maximum;
    }

    function transitionFallbackDelay(element) {
        if (reducedMotion()) return 0;
        var duration = transitionTime(element);
        return duration > 0 ? Math.min(1200, duration + 80) : 32;
    }

    function finishSheetClose(entry) {
        if (!entry || entry.closed) return;
        entry.closed = true;
        if (entry.closeAnimationCleanup) entry.closeAnimationCleanup();
        entry.closeAnimationCleanup = null;
        var index = stack.indexOf(entry);
        if (index >= 0) stack.splice(index, 1);
        cleanupEntry(entry);
        if (entry.element) entry.element.remove();
        if (typeof entry.onClose === 'function') {
            try { entry.onClose(); } catch (error) { console.error(error); }
        }
        syncModalState();
        restoreFocus(entry, entry.closeWasTop);
        try {
            api.emit('admin:mobile:overlayclose', {id: entry.id, remaining: allEntries(false).length});
        } catch (error) { console.error(error); }
        if (!entry.closeOptions.silentHistory && historyMatches(entry)) {
            ignoreNextPopstate();
            window.history.back();
        }
        (entry.closeCallbacks || []).splice(0).forEach(function (callback) {
            try { callback(); } catch (error) { console.error(error); }
        });
        if (entry.resolveClose) entry.resolveClose(true);
    }

    function finalize(entry, options) {
        options = options || {};
        var index = stack.indexOf(entry);
        if (index < 0 || entry.closed) return false;
        if (typeof options.afterClose === 'function') {
            entry.closeCallbacks = entry.closeCallbacks || [];
            entry.closeCallbacks.push(options.afterClose);
        }
        if (entry.closing) {
            if (options.silentHistory) entry.closeOptions.silentHistory = true;
            return false;
        }
        entry.closeWasTop = topEntry() === entry;
        entry.closeOptions = {silentHistory: !!options.silentHistory};
        entry.closePromise = new Promise(function (resolve) { entry.resolveClose = resolve; });
        entry.closing = true;
        if (entry.frame) {
            window.cancelAnimationFrame(entry.frame);
            entry.frame = 0;
        }
        if (typeof entry.stopDragging === 'function') entry.stopDragging(true);
        var element = entry.element;
        if (!element || !document.contains(element)) {
            finishSheetClose(entry);
            return true;
        }
        var finished = false;
        var timer = 0;
        var complete = function () {
            if (finished) return;
            finished = true;
            window.clearTimeout(timer);
            element.removeEventListener('transitionend', handleTransitionEnd);
            finishSheetClose(entry);
        };
        var handleTransitionEnd = function (event) {
            if (event.target !== element || (event.propertyName && event.propertyName !== 'transform')) return;
            complete();
        };
        entry.closeAnimationCleanup = function () {
            window.clearTimeout(timer);
            element.removeEventListener('transitionend', handleTransitionEnd);
        };
        element.addEventListener('transitionend', handleTransitionEnd);
        element.classList.remove('is-dragging', 'is-settling', 'is-open');
        element.classList.add('is-closing');
        syncModalState();
        timer = window.setTimeout(complete, transitionFallbackDelay(element));
        return true;
    }

    function visible(element) {
        return !!(element && element.offsetParent !== null && window.getComputedStyle(element).visibility !== 'hidden');
    }

    function editable(element) {
        return !!(element && element.matches && element.matches('input:not([type="hidden"]), textarea, [contenteditable="true"], .CodeMirror textarea, .ace_text-input'));
    }

    function closeTransientInput() {
        var entry = topEntry();
        // The App Bar back button is also available on full-page task forms.
        // Use the document as the fallback scope so its first press dismisses a
        // page keyboard or picker instead of immediately navigating away.
        var scope = entry && entry.element ? entry.element : document;
        var select2 = scope.querySelector('.select2-container--open');
        var layuiSelect = scope.querySelector('.layui-form-selected');
        var xmBody = Array.from(scope.querySelectorAll('xm-select .xm-body')).find(visible);
        var treeBody = Array.from(document.querySelectorAll('.layui-treeSelect-body, .layui-treeSelect .ztree')).find(visible);
        var laydate = Array.from(document.querySelectorAll('.layui-laydate:not(.layui-laydate-static)')).find(visible);
        if (select2 && window.jQuery) {
            var select = window.jQuery(select2).prev('select');
            if (select.length && typeof select.select2 === 'function') select.select2('close');
            return true;
        }
        if (layuiSelect) {
            layuiSelect.classList.remove('layui-form-selected');
            return true;
        }
        if (xmBody || treeBody) {
            window.dispatchEvent(new MouseEvent('click', {bubbles: false}));
            document.body.dispatchEvent(new MouseEvent('click', {bubbles: true}));
            return true;
        }
        if (laydate) {
            var key = laydate.getAttribute('lay-key');
            if (typeof layui !== 'undefined' && layui.laydate && typeof layui.laydate.close === 'function') layui.laydate.close(key);
            else laydate.remove();
            return true;
        }
        var viewport = window.visualViewport;
        var keyboard = viewport ? Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop) : 0;
        var focused = editable(document.activeElement) ? document.activeElement : lastEditableFocus;
        var keyboardIsOpen = keyboard > 80 || document.documentElement.hasAttribute('data-admin-mobile-keyboard-open');
        if (keyboardIsOpen && focused && document.contains(focused)) {
            focused.blur();
            lastEditableFocus = null;
            return true;
        }
        return false;
    }

    function stableValue(value, seen) {
        if (value === null) return null;
        if (value === undefined) return '__undefined__';
        if (typeof value !== 'object') return value;
        if (seen.indexOf(value) >= 0) return '__circular__';
        seen.push(value);
        var normalized;
        if (Array.isArray(value)) {
            normalized = value.map(function (item) { return stableValue(item, seen); });
        } else {
            normalized = {};
            Object.keys(value).sort().forEach(function (key) { normalized[key] = stableValue(value[key], seen); });
        }
        seen.pop();
        return normalized;
    }

    function formFingerprint(entry) {
        if (!entry.form || typeof entry.form.getData !== 'function') return null;
        try { return JSON.stringify(stableValue(entry.form.getData(), [])); } catch (error) { return null; }
    }

    function entryIsDirty(entry) {
        if (!entry || !entry.guardUnsaved || entry.discarded || entry.forceClose) return false;
        if (entry.baseline !== null) {
            var current = formFingerprint(entry);
            if (current !== null) return current !== entry.baseline;
        }
        return entry.touched === true;
    }

    function markTouched(entry) {
        if (!entry || !entry.guardUnsaved || entry.closing) return;
        entry.touched = true;
        if (!entry.element.hasAttribute('data-admin-mobile-dirty')) {
            entry.element.setAttribute('data-admin-mobile-dirty', 'true');
            var title = entry.element.querySelector('.layui-layer-title, .admin-mobile-overlay__head > div');
            if (title && !title.querySelector('.admin-mobile-unsaved-indicator')) {
                var badge = document.createElement('span');
                badge.className = 'admin-mobile-unsaved-indicator';
                badge.textContent = '未保存';
                title.appendChild(badge);
            }
        }
    }

    function setupDirtyTracking(entry) {
        if (!entry.guardUnsaved || !entry.element) return;
        var userChange = function (event) {
            if (event.isTrusted === false) return;
            markTouched(entry);
        };
        var editorKey = function (event) {
            if (event.isTrusted === false || event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.target && event.target.closest && event.target.closest('.photo-album, .external-input')) return;
            if (event.key.length === 1 || ['Backspace', 'Delete', 'Enter'].indexOf(event.key) >= 0) markTouched(entry);
        };
        var controlClick = function (event) {
            if (event.isTrusted === false) return;
            var selector = '.layui-form-checkbox, .layui-form-radio, .layui-form-switch, xm-select .xm-option, .widget-add-control, .widget-remove-control, .attribute-add-control, .image-render input[type="file"], .file-render input[type="file"]';
            if (event.target.closest(selector)) markTouched(entry);
        };
        entry.element.addEventListener('input', userChange, true);
        entry.element.addEventListener('change', userChange, true);
        entry.element.addEventListener('cut', userChange, true);
        entry.element.addEventListener('paste', userChange, true);
        entry.element.addEventListener('drop', userChange, true);
        entry.element.addEventListener('keydown', editorKey, true);
        entry.element.addEventListener('click', controlClick, true);
        entry.cleanups.push(function () {
            entry.element.removeEventListener('input', userChange, true);
            entry.element.removeEventListener('change', userChange, true);
            entry.element.removeEventListener('cut', userChange, true);
            entry.element.removeEventListener('paste', userChange, true);
            entry.element.removeEventListener('drop', userChange, true);
            entry.element.removeEventListener('keydown', editorKey, true);
            entry.element.removeEventListener('click', controlClick, true);
        });

        var captureBaseline = function () {
            if (entry.closing || entry.touched) return;
            var fingerprint = formFingerprint(entry);
            if (fingerprint !== null) entry.baseline = fingerprint;
        };
        captureBaseline();
        [250, 1000].forEach(function (delay) {
            var timer = window.setTimeout(captureBaseline, delay);
            entry.cleanups.push(function () { window.clearTimeout(timer); });
        });
    }

    function confirmDiscard(entry) {
        if (!entryIsDirty(entry)) return Promise.resolve(true);
        if (entry.confirmPromise) return entry.confirmPromise;
        if (entry.element && entry.element.contains(document.activeElement) && typeof document.activeElement.blur === 'function') document.activeElement.blur();
        entry.confirming = true;
        syncModalState();
        var result;
        if (window.Swal && typeof window.Swal.fire === 'function') {
            result = window.Swal.fire({
                title: '放弃未保存的修改？',
                text: '当前表单还有未保存的内容。',
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
            result = Promise.resolve(window.confirm('当前表单还有未保存的内容，确定放弃修改吗？'));
        }
        entry.confirmPromise = result.then(function (confirmed) {
            entry.confirming = false;
            entry.confirmPromise = null;
            syncModalState();
            return confirmed;
        }, function () {
            entry.confirming = false;
            entry.confirmPromise = null;
            syncModalState();
            return false;
        });
        return entry.confirmPromise;
    }

    function requestPopupClose(entry, options) {
        options = options || {};
        if (!entry || entry.closing) return false;
        confirmDiscard(entry).then(function (confirmed) {
            if (!confirmed || entry.closing) {
                if (!confirmed) focusEntry(entry);
                return;
            }
            entry.discarded = true;
            if (options.historyWasPopped) entry.closedByHistory = true;
            window.layer.close(entry.index);
        });
        return true;
    }

    function requestSheetClose(entry, options) {
        options = options || {};
        if (!entry || entry.closing) return false;
        if (options.force) {
            entry.forceClose = true;
            entry.discarded = true;
            return finalize(entry, options);
        }
        confirmDiscard(entry).then(function (confirmed) {
            if (!confirmed || entry.closing) {
                if (!confirmed) focusEntry(entry);
                return;
            }
            entry.discarded = true;
            finalize(entry, options);
        });
        return true;
    }

    function closeTop(options) {
        options = options || {};
        if (!options.force && closeTransientInput()) return true;
        var entry = topEntry();
        if (!entry) return false;
        if (entry.kind === 'popup') {
            if (options.force) {
                entry.forceClose = true;
                entry.silentHistory = !!options.silentHistory;
                window.layer.close(entry.index);
            } else {
                requestPopupClose(entry, options);
            }
            return true;
        }
        return requestSheetClose(entry, options);
    }

    function historyDepth() {
        return allEntries(false).filter(function (entry) { return entry.historyPushed; }).length;
    }

    function dismissAllThen(action) {
        if (navigationPending) return;
        navigationPending = true;
        var entry = topEntry();
        var dirtyEntry = allEntries(false).slice().sort(function (left, right) { return right.order - left.order; }).find(entryIsDirty);
        // A temporary action Sheet may sit above a dirty form. Confirm the
        // actual form before closing the complete stack, while keeping focus on
        // the visible top layer if the user cancels.
        confirmDiscard(dirtyEntry || entry).then(function (confirmed) {
            if (!confirmed) {
                navigationPending = false;
                focusEntry(entry);
                return;
            }
            var depth = historyDepth();
            allEntries(false).forEach(function (item) { item.discarded = true; item.forceClose = true; });
            Promise.resolve(api.closeAll({silentHistory: true, preserveHistory: true})).then(function () {
                rewindHistory(depth, function () {
                    navigationPending = false;
                    action();
                });
            });
        });
    }

    function runAfterSheetClose(action, event) {
        var entry = topEntry();
        if (!entry) {
            action(event);
            return;
        }
        confirmDiscard(entry).then(function (confirmed) {
            if (!confirmed) return;
            var shouldRewind = entry.historyPushed && historyMatches(entry);
            entry.discarded = true;
            entry.forceClose = true;
            if (entry.kind === 'popup') {
                entry.silentHistory = true;
                window.layer.close(entry.index);
                if (shouldRewind) rewindHistory(1, function () { action(event); });
                else action(event);
            } else {
                finalize(entry, {
                    silentHistory: true,
                    afterClose: function () {
                        if (shouldRewind) rewindHistory(1, function () { action(event); });
                        else action(event);
                    }
                });
            }
        });
    }

    function setupSheetDrag(entry) {
        if (!entry || entry.fullScreen || !entry.element || !window.PointerEvent) return;
        var element = entry.element;
        var body = element.querySelector('.admin-mobile-overlay__body');
        var gesture = null;
        var settleTimer = 0;
        var settleListener = null;
        var dragVariable = '--admin-mobile-overlay-drag-y';

        function releaseCapture(pointerId) {
            try {
                if (element.hasPointerCapture && element.hasPointerCapture(pointerId)) element.releasePointerCapture(pointerId);
            } catch (error) {}
        }

        function clearSettle(removeOffset) {
            window.clearTimeout(settleTimer);
            settleTimer = 0;
            if (settleListener) element.removeEventListener('transitionend', settleListener);
            settleListener = null;
            element.classList.remove('is-settling');
            if (removeOffset) element.style.removeProperty(dragVariable);
        }

        function settle() {
            clearSettle(false);
            element.classList.remove('is-dragging');
            element.classList.add('is-settling');
            element.style.setProperty(dragVariable, '0px');
            var complete = function (event) {
                if (event && (event.target !== element || (event.propertyName && event.propertyName !== 'transform'))) return;
                clearSettle(true);
            };
            settleListener = complete;
            element.addEventListener('transitionend', settleListener);
            settleTimer = window.setTimeout(complete, transitionFallbackDelay(element));
        }

        function stopDragging(preserveOffset) {
            if (gesture) releaseCapture(gesture.pointerId);
            gesture = null;
            clearSettle(!preserveOffset);
            element.classList.remove('is-dragging');
            if (!preserveOffset) element.style.removeProperty(dragVariable);
        }

        function pointerDown(event) {
            if (entry.closing || gesture || event.isPrimary === false || (event.pointerType === 'mouse' && event.button !== 0)) return;
            if (topEntry() !== entry || element.classList.contains('is-settling')) return;
            var target = event.target;
            if (!target || !target.closest('.admin-mobile-overlay__handle, .admin-mobile-overlay__head')) return;
            if (target.closest('button, a, input, select, textarea, [contenteditable="true"], [role="button"]')) return;
            if (closeTransientInput()) return;
            if (body && body.scrollTop > 1) return;
            clearSettle(true);
            var now = window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
            gesture = {
                pointerId: event.pointerId,
                startY: event.clientY,
                lastY: event.clientY,
                startTime: now,
                lastTime: now,
                offset: 0,
                velocity: 0
            };
            element.classList.add('is-dragging');
            element.style.setProperty(dragVariable, '0px');
            try { element.setPointerCapture(event.pointerId); } catch (error) {}
            if (event.cancelable) event.preventDefault();
        }

        function pointerMove(event) {
            if (!gesture || event.pointerId !== gesture.pointerId || entry.closing) return;
            var now = window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
            var offset = Math.max(0, event.clientY - gesture.startY);
            var elapsed = Math.max(1, now - gesture.lastTime);
            var sample = (event.clientY - gesture.lastY) / elapsed;
            gesture.velocity = Math.max(-3, Math.min(3, gesture.velocity * .55 + sample * .45));
            gesture.lastY = event.clientY;
            gesture.lastTime = now;
            gesture.offset = offset;
            element.style.setProperty(dragVariable, Math.round(offset * 100) / 100 + 'px');
            if (event.cancelable) event.preventDefault();
        }

        function pointerEnd(event, cancelled) {
            if (!gesture || event.pointerId !== gesture.pointerId) return;
            var current = gesture;
            var now = window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
            var clientY = typeof event.clientY === 'number' ? event.clientY : current.lastY;
            var finalOffset = Math.max(0, clientY - current.startY);
            var averageVelocity = finalOffset / Math.max(1, now - current.startTime);
            var idleTime = Math.max(0, now - current.lastTime);
            var recentVelocity = current.velocity * Math.max(0, Math.min(1, 1 - Math.max(0, idleTime - 80) / 160));
            if (clientY < current.lastY - 3) recentVelocity = Math.min(0, recentVelocity);
            var velocity = Math.max(recentVelocity, averageVelocity);
            var height = element.getBoundingClientRect().height || window.innerHeight;
            var distanceThreshold = Math.min(180, Math.max(88, height * .22));
            var shouldClose = !cancelled && (finalOffset >= distanceThreshold || (finalOffset >= 18 && velocity >= .65));
            releaseCapture(current.pointerId);
            gesture = null;
            if (event.cancelable) event.preventDefault();
            if (shouldClose) {
                element.style.setProperty(dragVariable, Math.round(finalOffset * 100) / 100 + 'px');
                element.classList.remove('is-dragging');
                if (entryIsDirty(entry)) settle();
                requestSheetClose(entry);
            } else if (finalOffset < 1) {
                stopDragging(false);
            } else {
                settle();
            }
        }

        entry.stopDragging = stopDragging;
        element.addEventListener('pointerdown', pointerDown);
        element.addEventListener('pointermove', pointerMove);
        element.addEventListener('pointerup', pointerEnd);
        var pointerCancel = function (event) { pointerEnd(event, true); };
        element.addEventListener('pointercancel', pointerCancel);
        entry.cleanups.push(function () {
            stopDragging(false);
            element.removeEventListener('pointerdown', pointerDown);
            element.removeEventListener('pointermove', pointerMove);
            element.removeEventListener('pointerup', pointerEnd);
            element.removeEventListener('pointercancel', pointerCancel);
            entry.stopDragging = null;
        });
    }

    function openSheet(options) {
        if (!api.isEnabled() || !ensure()) return false;
        if (typeof options === 'string') options = {id: options};
        options = options || {};
        var existing = stack.find(function (entry) { return entry.id === options.id && !entry.closing; });
        var replaceHistory = !!(existing && historyMatches(existing));
        if (existing) finalize(existing, {silentHistory: true});
        var id = options.id || ('sheet-' + (++token));
        var overlay = document.createElement('section');
        var overlayToken = 'admin-mobile-' + Date.now() + '-' + (++token);
        overlay.className = 'admin-mobile-overlay ' + (options.fullScreen ? 'admin-mobile-overlay--task ' : '') + (options.className || '');
        overlay.setAttribute('data-admin-mobile-overlay', id);
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', overlayToken + '-title');
        overlay.setAttribute('tabindex', '-1');
        overlay.innerHTML = '<div class="admin-mobile-overlay__handle" aria-hidden="true"></div>' +
            '<header class="admin-mobile-overlay__head"><div><strong id="' + overlayToken + '-title">' + escapeHtml(options.title || '操作') + '</strong>' +
            (options.subtitle ? '<small>' + escapeHtml(options.subtitle) + '</small>' : '') + '</div>' +
            '<button type="button" data-admin-mobile-overlay-close aria-label="关闭"><span class="material-icons-outlined" aria-hidden="true">close</span></button></header>' +
            '<div class="admin-mobile-overlay__body"></div><footer class="admin-mobile-overlay__foot" hidden></footer>';
        if (options.headerContent) {
            var headerContent = overlay.querySelector('.admin-mobile-overlay__head > div');
            headerContent.textContent = '';
            renderContent(headerContent, options.headerContent);
            var customTitle = headerContent.querySelector('[data-admin-mobile-overlay-title], strong');
            if (customTitle) customTitle.id = overlayToken + '-title';
        }
        renderContent(overlay.querySelector('.admin-mobile-overlay__body'), options.content);
        if (options.footer) {
            var footer = overlay.querySelector('.admin-mobile-overlay__foot');
            footer.hidden = false;
            renderContent(footer, options.footer);
        }
        var previousFocus = document.activeElement;
        host.appendChild(overlay);
        overlay.focus({preventScroll: true});
        var entry = {
            id: id,
            kind: 'sheet',
            token: overlayToken,
            order: ++token,
            element: overlay,
            focus: previousFocus,
            onClose: options.onClose,
            shadeClose: options.shadeClose !== false,
            fullScreen: !!options.fullScreen,
            guardUnsaved: options.guardUnsaved === true,
            baseline: null,
            touched: false,
            cleanups: []
        };
        stack.push(entry);
        setupSheetDrag(entry);
        setupDirtyTracking(entry);
        syncModalState();
        entry.frame = window.requestAnimationFrame(function () {
            if (document.contains(overlay)) overlay.classList.add('is-open');
        });
        entry.cleanups.push(function () { window.cancelAnimationFrame(entry.frame); });
        pushHistory(entry, replaceHistory);
        focusEntry(entry);
        api.emit('admin:mobile:overlayopen', {id: id, element: overlay});
        return {
            handled: true,
            id: id,
            element: overlay,
            close: function () { return requestSheetClose(entry); },
            commit: function () { entry.discarded = true; return finalize(entry); }
        };
    }

    function openActions(options) {
        options = options || {};
        var list = document.createElement('div');
        list.className = 'admin-mobile-action-list';
        (options.actions || []).forEach(function (action) {
            var control = document.createElement(action.href && action.disabled !== true ? 'a' : 'button');
            var actionLabel = plainText(action.label || action.title || '操作');
            var fallbackIcon = action.danger
                ? (/(?:永久删除|删除|卸载|清空|清理)/.test(actionLabel) ? 'delete_forever' : 'warning')
                : 'arrow_forward';
            var actionIcon = actionMaterialIcon(action.icon, actionLabel, fallbackIcon);
            if (action.href && action.disabled !== true) control.href = action.href; else control.type = 'button';
            control.className = action.danger ? 'is-danger' : '';
            control.disabled = action.disabled === true;
            control.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">' + escapeHtml(actionIcon) + '</span><span><strong>' + escapeHtml(actionLabel) + '</strong>' + (action.description ? '<small>' + escapeHtml(plainText(action.description)) + '</small>' : '') + '</span>';
            if (typeof action.run === 'function' || typeof action.onClick === 'function') control.addEventListener('click', function (event) {
                event.preventDefault();
                runAfterSheetClose(action.run || action.onClick, event);
            });
            list.appendChild(control);
        });
        return openSheet({id: options.id || 'actions', title: options.title || '选择操作', subtitle: options.subtitle, content: list, className: 'admin-mobile-overlay--actions'});
    }

    function activatePopupTab(wrapper, index, focusTab) {
        var targetIndex = String(index);
        var panels = wrapper.querySelectorAll('.admin-mobile-popup-tab');
        var buttons = wrapper.querySelectorAll('[data-admin-mobile-popup-tab]');
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-admin-mobile-popup-panel') !== targetIndex;
        });
        buttons.forEach(function (button) {
            var selected = button.getAttribute('data-admin-mobile-popup-tab') === targetIndex;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
            button.setAttribute('tabindex', selected ? '0' : '-1');
        });
        if (focusTab) {
            var target = wrapper.querySelector('[data-admin-mobile-popup-tab="' + targetIndex + '"]');
            if (target) target.focus({preventScroll: true});
        }
    }

    function visiblePopupTabs(context) {
        var renderedTabs = Array.isArray(context && context.tab) ? context.tab : [];
        var optionTabs = context && context.options && Array.isArray(context.options.tab) ? context.options.tab : [];
        return renderedTabs.map(function (tab, originalIndex) {
            return {
                definition: optionTabs[originalIndex] || {},
                originalIndex: originalIndex,
                rendered: tab || {}
            };
        }).filter(function (entry) {
            return entry.definition.hide !== true;
        });
    }

    function enhancePopupFields(panel, fields) {
        var inputModes = ['none', 'text', 'tel', 'url', 'email', 'numeric', 'decimal', 'search'];
        var enterKeyHints = ['enter', 'done', 'go', 'next', 'previous', 'search', 'send'];
        (fields || []).forEach(function (field) {
            if (!field || !field.name) return;
            var name = typeof util !== 'undefined' && typeof util.replaceDotWithHyphen === 'function'
                ? util.replaceDotWithHyphen(field.name)
                : field.name;
            var controls = Array.from(panel.querySelectorAll('[name]')).filter(function (control) {
                return control.getAttribute('name') === String(name);
            });
            controls.forEach(function (control) {
                var inputMode = String(field.inputmode || field.inputMode || '').toLowerCase();
                var enterKeyHint = String(field.enterkeyhint || field.enterKeyHint || '').toLowerCase();
                if (inputModes.indexOf(inputMode) >= 0) control.setAttribute('inputmode', inputMode);
                if (enterKeyHints.indexOf(enterKeyHint) >= 0) control.setAttribute('enterkeyhint', enterKeyHint);
            });
        });
    }

    function popupContent(tabs) {
        var wrapper = document.createElement('div');
        var popupToken = ++token;
        wrapper.className = 'admin-mobile-popup-form';
        if (tabs.length > 1) {
            var nav = document.createElement('nav');
            nav.className = 'admin-mobile-popup-tabs';
            nav.setAttribute('role', 'tablist');
            nav.setAttribute('aria-orientation', 'horizontal');
            tabs.forEach(function (entry, visibleIndex) {
                var originalIndex = entry.originalIndex;
                var tab = entry.rendered;
                var button = document.createElement('button');
                var tabId = 'admin-mobile-popup-tab-' + popupToken + '-' + originalIndex;
                var panelId = 'admin-mobile-popup-panel-' + popupToken + '-' + originalIndex;
                button.type = 'button';
                button.textContent = plainText(tab.title || ('第 ' + (visibleIndex + 1) + ' 项'));
                button.id = tabId;
                button.setAttribute('role', 'tab');
                button.setAttribute('data-admin-mobile-popup-tab', String(originalIndex));
                button.setAttribute('aria-controls', panelId);
                button.setAttribute('aria-selected', visibleIndex === 0 ? 'true' : 'false');
                button.setAttribute('tabindex', visibleIndex === 0 ? '0' : '-1');
                button.classList.toggle('is-active', visibleIndex === 0);
                button.addEventListener('click', function () { activatePopupTab(wrapper, originalIndex, false); });
                button.addEventListener('keydown', function (event) {
                    var next = visibleIndex;
                    if (event.key === 'ArrowRight') next = (visibleIndex + 1) % tabs.length;
                    else if (event.key === 'ArrowLeft') next = (visibleIndex - 1 + tabs.length) % tabs.length;
                    else if (event.key === 'Home') next = 0;
                    else if (event.key === 'End') next = tabs.length - 1;
                    else return;
                    event.preventDefault();
                    activatePopupTab(wrapper, tabs[next].originalIndex, true);
                });
                nav.appendChild(button);
            });
            wrapper.appendChild(nav);
        }
        tabs.forEach(function (entry, visibleIndex) {
            var originalIndex = entry.originalIndex;
            var tab = entry.rendered;
            var panel = document.createElement('div');
            panel.className = 'admin-mobile-popup-tab';
            panel.id = 'admin-mobile-popup-panel-' + popupToken + '-' + originalIndex;
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('data-admin-mobile-popup-panel', String(originalIndex));
            if (tabs.length > 1) panel.setAttribute('aria-labelledby', 'admin-mobile-popup-tab-' + popupToken + '-' + originalIndex);
            panel.hidden = visibleIndex !== 0;
            panel.innerHTML = tab.content || '';
            enhancePopupFields(panel, entry.definition.form || []);
            wrapper.appendChild(panel);
        });
        return wrapper;
    }

    function fieldValue(data, name) {
        try {
            if (typeof util !== 'undefined' && typeof util.parseStringObject === 'function') return util.parseStringObject(data, name);
        } catch (error) {}
        return data ? data[name] : undefined;
    }

    function firstInvalidField(entry) {
        var data;
        try { data = entry.form.getData(); } catch (error) { return null; }
        var tabs = (entry.options && entry.options.tab) || [];
        for (var tabIndex = 0; tabIndex < tabs.length; tabIndex++) {
            if (tabs[tabIndex].hide === true) continue;
            var fields = tabs[tabIndex].form || [];
            for (var fieldIndex = 0; fieldIndex < fields.length; fieldIndex++) {
                var field = fields[fieldIndex];
                var name = typeof util !== 'undefined' && typeof util.replaceDotWithHyphen === 'function' ? util.replaceDotWithHyphen(field.name) : field.name;
                var value = fieldValue(data, name);
                if (field.required === true && value === '') return {field: field, name: name, tabIndex: tabIndex};
                if (field.regex && value) {
                    try {
                        if (!(new RegExp(field.regex.value)).test(value)) return {field: field, name: name, tabIndex: tabIndex};
                    } catch (error) {}
                }
            }
        }
        return null;
    }

    function focusInvalid(entry) {
        if (!entry || !entry.element) return;
        var invalid = firstInvalidField(entry);
        var wrapper = entry.element.querySelector('.admin-mobile-popup-form');
        if (invalid && wrapper) activatePopupTab(wrapper, invalid.tabIndex, false);
        var panel = invalid && wrapper ? wrapper.querySelector('[data-admin-mobile-popup-panel="' + invalid.tabIndex + '"]') : wrapper;
        var named = null;
        if (invalid && panel) {
            named = Array.from(panel.querySelectorAll('[name]')).find(function (element) { return element.getAttribute('name') === invalid.name; }) || null;
        }
        var block = named && named.closest('.layui-form-item');
        if (!block && invalid && panel) {
            block = Array.from(panel.querySelectorAll('.layui-form-item')).find(function (item) {
                return !!Array.from(item.querySelectorAll('[name]')).find(function (element) { return element.getAttribute('name') === invalid.name; });
            }) || null;
        }
        if (!block) block = entry.element.querySelector('[aria-invalid="true"], .layui-form-danger, :invalid');
        if (!block) return;
        if (!block.classList.contains('layui-form-item')) block = block.closest('.layui-form-item') || block;
        block.classList.add('admin-mobile-field-invalid');
        block.setAttribute('aria-invalid', 'true');
        if (invalid && wrapper) {
            var tabButton = wrapper.querySelector('[data-admin-mobile-popup-tab="' + invalid.tabIndex + '"]');
            if (tabButton) tabButton.classList.add('has-error');
        }
        var control = named && named.type !== 'hidden' && !named.disabled ? named : block.querySelector('.CodeMirror textarea, .ace_text-input, [contenteditable="true"], input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])');
        var clear = function () {
            block.classList.remove('admin-mobile-field-invalid');
            block.removeAttribute('aria-invalid');
            if (invalid && wrapper) {
                var tabButton = wrapper.querySelector('[data-admin-mobile-popup-tab="' + invalid.tabIndex + '"]');
                if (tabButton) tabButton.classList.remove('has-error');
            }
            block.removeEventListener('input', clear, true);
            block.removeEventListener('change', clear, true);
        };
        block.addEventListener('input', clear, true);
        block.addEventListener('change', clear, true);
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        block.scrollIntoView({behavior: reduced ? 'auto' : 'smooth', block: 'center', inline: 'nearest'});
        window.setTimeout(function () {
            if (control && document.contains(control) && typeof control.focus === 'function') control.focus({preventScroll: true});
        }, reduced ? 0 : 180);
    }

    function enhancePopupInlineActions(entry) {
        var element = entry && entry.element;
        if (!element) return;
        element.querySelectorAll('.admin-mobile-popup-form .photo-album, .admin-mobile-popup-form .external-input').forEach(function (button) {
            if (!button.hasAttribute('aria-label')) {
                button.setAttribute('aria-label', button.classList.contains('photo-album') ? '打开相册' : '设置外部图片链接');
            }
            if (button.matches('button, a[href]') || button.dataset.adminMobileInlineAction === 'true') return;
            button.dataset.adminMobileInlineAction = 'true';
            button.setAttribute('role', 'button');
            button.setAttribute('tabindex', '0');
            var activate = function (event) {
                if (event.repeat) return;
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                button.click();
            };
            button.addEventListener('keydown', activate);
            entry.cleanups.push(function () { button.removeEventListener('keydown', activate); });
        });
    }

    function presentPopup(context) {
        if (!api.isEnabled() || !context || !context.form) return false;
        var workflow = (api.workflows || []).find(function (item) { return typeof item.presentPopup === 'function' && item.presentPopup(context, api.getContext()) === true; });
        if (workflow) return true;
        if (!window.layer || typeof window.layer.open !== 'function') return false;
        var options = context.options || {};
        var visibleTabs = visiblePopupTabs(context);
        var count = visibleTabs.reduce(function (total, entry) { return total + ((entry.definition.form || []).length); }, 0);
        var complex = visibleTabs.some(function (entry) { return (entry.definition.form || []).some(function (field) { return ['editor', 'editorv2', 'html', 'image', 'file', 'treeCheckbox', 'treeSelect'].indexOf(field.type) >= 0; }); });
        var content = popupContent(visibleTabs);
        // Layer's object-content mode expects an element that is already in the
        // document. A detached node creates only the shade while its wrapper
        // stays detached, which leaves the page dimmed with no visible form.
        content.style.display = 'none';
        document.body.appendChild(content);
        var previousFocus = document.activeElement;
        var historyToken = 'admin-mobile-popup-' + Date.now() + '-' + (++token);
        var unique = typeof context.form.getUnique === 'function' ? context.form.getUnique() : '';
        var popupEntry = {
            index: null,
            kind: 'popup',
            token: historyToken,
            order: ++token,
            element: null,
            focus: previousFocus,
            form: context.form,
            options: options,
            guardUnsaved: !!options.submit,
            baseline: null,
            touched: false,
            closedByHistory: false,
            silentHistory: false,
            cleanups: []
        };
        var index;
        try {
            index = window.layer.open({
            type: 1,
            title: plainText(options.mobileTitle || (visibleTabs[0] && visibleTabs[0].rendered.title) || '操作'),
            content: window.jQuery ? window.jQuery(content) : content,
            area: complex || count > 5 ? ['100%', '100%'] : ['100%', 'auto'],
            offset: complex || count > 5 ? 'auto' : 'b',
            skin: 'component-popup admin-mobile-layer-popup ' + (complex || count > 5 ? 'admin-mobile-layer-popup--task ' : 'admin-mobile-layer-popup--sheet ') + (options.danger ? 'admin-mobile-layer-popup--danger-submit ' : '') + unique,
            shade: options.shade == null ? 0.46 : options.shade,
            shadeClose: options.submit ? false : options.shadeClose === true,
            closeBtn: options.closeBtn == null ? 1 : options.closeBtn,
            maxmin: false,
            resize: false,
            move: false,
            anim: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? -1 : 2,
            btn: options.submit ? [plainText(options.confirmText || '保存'), '取消'] : false,
            yes: function (layerIndex) {
                var submitted = context.submit(layerIndex);
                if (submitted === false) focusInvalid(popupEntry);
                return false;
            },
            btn2: function () {
                requestPopupClose(popupEntry);
                return false;
            },
            cancel: function () {
                requestPopupClose(popupEntry);
                return false;
            },
            success: function (layerElement, layerIndex) {
                var element = resolveLayerElement(layerElement, layerIndex);
                popupEntry.index = layerIndex;
                popupEntry.element = element;
                if (element) {
                    element.setAttribute('role', 'dialog');
                    element.setAttribute('aria-modal', 'true');
                    element.setAttribute('tabindex', '-1');
                    var title = element.querySelector('.layui-layer-title');
                    if (title) {
                        var titleIcon = allowedInlineIcon(visibleTabs[0] && visibleTabs[0].definition.name);
                        if (titleIcon) {
                            var titleLabel = document.createElement('span');
                            titleLabel.textContent = title.textContent.trim();
                            titleLabel.prepend(titleIcon);
                            title.textContent = '';
                            title.appendChild(titleLabel);
                        }
                        title.id = historyToken + '-title';
                        element.setAttribute('aria-labelledby', title.id);
                    }
                    var close = element.querySelector('.layui-layer-close');
                    if (close) close.setAttribute('aria-label', '关闭');
                    element.querySelectorAll('.layui-layer-btn a').forEach(function (button) {
                        button.setAttribute('role', 'button');
                        button.setAttribute('tabindex', '0');
                        button.addEventListener('keydown', function (event) {
                            if (event.key !== 'Enter' && event.key !== ' ') return;
                            event.preventDefault();
                            button.click();
                        });
                    });
                    var primaryButton = element.querySelector('.layui-layer-btn .layui-layer-btn0');
                    var confirmIcon = allowedInlineIcon(options.confirmText);
                    if (primaryButton && confirmIcon) primaryButton.prepend(confirmIcon);
                    element.focus({preventScroll: true});
                }
                popupStack.push(popupEntry);
                syncModalState();
                pushHistory(popupEntry, false);
                try { context.register(layerIndex); } catch (error) { console.error(error); }
                enhancePopupInlineActions(popupEntry);
                setupDirtyTracking(popupEntry);
                var preferred = element && element.querySelector('.layui-layer-content input:not([type="hidden"]):not([disabled]), .layui-layer-content textarea:not([disabled]), .layui-layer-content select:not([disabled]), .layui-layer-content [contenteditable="true"]');
                focusEntry(popupEntry, preferred);
            },
            end: function () {
                var wasTop = topEntry() === popupEntry;
                popupEntry.closing = true;
                var popupIndex = popupStack.indexOf(popupEntry);
                if (popupIndex >= 0) popupStack.splice(popupIndex, 1);
                cleanupEntry(popupEntry);
                if (!popupEntry.closedByHistory && !popupEntry.silentHistory && historyMatches(popupEntry)) {
                    ignoreNextPopstate();
                    window.history.back();
                }
                try {
                    if (typeof context.form.destroy === 'function') context.form.destroy();
                } catch (error) { console.error(error); }
                try {
                    if (typeof options.end === 'function') options.end();
                } catch (error) { console.error(error); }
                if (content && content.parentNode) content.remove();
                syncModalState();
                restoreFocus(popupEntry, wasTop);
            }
            });
        } catch (error) {
            if (content && content.parentNode) content.remove();
            throw error;
        }
        popupEntry.index = index;
        return {handled: true, index: index, close: function () { return requestPopupClose(popupEntry); }};
    }

    function handleClick(event) {
        var close = event.target.closest('[data-admin-mobile-overlay-close]');
        if (close) {
            event.preventDefault();
            closeTop();
            return;
        }
        if (event.target === backdrop) {
            var entry = topEntry();
            if (entry && entry.kind === 'sheet' && entry.shadeClose) closeTop();
        }
    }

    function handleNavigationClick(event) {
        if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        var link = event.target.closest && event.target.closest('a[href]');
        var entry = topEntry();
        if (!link || !entry || !entry.element || !entry.element.contains(link)) return;
        if (link.hasAttribute('download') || link.target === '_blank' || link.hasAttribute('data-admin-mobile-logout') || link.closest('.layui-layer-btn, .layui-layer-setwin')) return;
        var raw = link.getAttribute('href') || '';
        if (!raw || raw.charAt(0) === '#' || /^javascript:/i.test(raw)) return;
        var url;
        try { url = new URL(link.href, window.location.href); } catch (error) { return; }
        event.preventDefault();
        event.stopImmediatePropagation();
        dismissAllThen(function () {
            if (url.origin === window.location.origin && typeof api.navigate === 'function') api.navigate(url.pathname + url.search + url.hash);
            else window.location.href = url.href;
        });
    }

    function handleFocusIn(event) {
        if (editable(event.target)) lastEditableFocus = event.target;
    }

    function handleKey(event) {
        if (document.querySelector('.swal2-container.swal2-backdrop-show, .swal2-container.swal2-shown')) return;
        var entry = topEntry();
        if (!entry) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeTop();
            return;
        }
        if (event.key !== 'Tab' || !entry.element) return;
        if (document.activeElement && document.activeElement.closest && document.activeElement.closest('.layui-laydate, .layui-treeSelect-body, .select2-container--open, xm-select > .xm-body')) return;
        var items = focusables(entry.element);
        if (!items.length) {
            event.preventDefault();
            focusEntry(entry);
            return;
        }
        var first = items[0], last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || !entry.element.contains(document.activeElement))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !entry.element.contains(document.activeElement))) {
            event.preventDefault();
            first.focus();
        }
    }

    function handlePopstate() {
        if (consumeIgnoredPopstate()) return;
        var entry = topEntry();
        if (!entry) return;
        if (entry.confirming) {
            restorePoppedHistory(entry);
            return;
        }
        if (entry.kind === 'popup') {
            if (entryIsDirty(entry)) {
                restorePoppedHistory(entry);
                requestPopupClose(entry);
            } else {
                entry.closedByHistory = true;
                entry.forceClose = true;
                window.layer.close(entry.index);
            }
            return;
        }
        if (entryIsDirty(entry)) {
            restorePoppedHistory(entry);
            requestSheetClose(entry);
        } else {
            finalize(entry, {silentHistory: true});
        }
    }

    function handleBeforeUnload(event) {
        var entry = topEntry();
        if (!entryIsDirty(entry)) return;
        event.preventDefault();
        event.returnValue = '';
    }

    api.openSheet = openSheet;
    api.openActions = openActions;
    api.dismissAllThen = dismissAllThen;
    api.closeTop = closeTop;
    api.closeAll = function (options) {
        options = options || {};
        var silent = options.silentHistory !== false;
        var pending = [];
        var closingSheetTokens = stack.map(function (entry) { return entry.token; });
        var closingPopupTokens = popupStack.map(function (entry) { return entry.token; });
        if (window.history.state && window.history.state.adminMobileOverlay && closingSheetTokens.indexOf(window.history.state.adminMobileOverlay) < 0) {
            closingSheetTokens.push(window.history.state.adminMobileOverlay);
        }
        if (window.history.state && window.history.state.adminMobilePopup && closingPopupTokens.indexOf(window.history.state.adminMobilePopup) < 0) {
            closingPopupTokens.push(window.history.state.adminMobilePopup);
        }
        popupStack.slice().reverse().forEach(function (popup) {
            if (popup.closing) {
                if (silent) popup.silentHistory = true;
                return;
            }
            popup.forceClose = true;
            popup.discarded = true;
            popup.silentHistory = silent;
            popup.closing = true;
            window.layer.close(popup.index);
        });
        stack.slice().reverse().forEach(function (entry) {
            entry.forceClose = true;
            entry.discarded = true;
            finalize(entry, {silentHistory: silent});
            if (entry.closePromise) pending.push(entry.closePromise);
        });
        syncModalState();
        var completion = Promise.all(pending);
        if (silent && !options.preserveHistory) {
            completion.then(function () {
                if (!window.history.state) return;
                var state = Object.assign({}, window.history.state);
                var changed = false;
                if (closingSheetTokens.indexOf(state.adminMobileOverlay) >= 0) {
                    delete state.adminMobileOverlay;
                    changed = true;
                }
                if (closingPopupTokens.indexOf(state.adminMobilePopup) >= 0) {
                    delete state.adminMobilePopup;
                    changed = true;
                }
                if (changed) window.history.replaceState(state, '', window.location.href);
            });
        }
        return completion;
    };
    api.presentPopup = presentPopup;
    api.overlay = {
        hasOpenEntries: function () { return allEntries(false).length > 0; },
        historyDepth: historyDepth,
        mount: function () {
            if (!ensure() || mounted) return;
            mounted = true;
            document.addEventListener('keydown', handleKey);
            document.addEventListener('focusin', handleFocusIn, true);
            document.addEventListener('click', handleNavigationClick, true);
            window.addEventListener('popstate', handlePopstate);
            window.addEventListener('beforeunload', handleBeforeUnload);
            rootElement.addEventListener('click', handleClick);
        },
        closeAll: api.closeAll,
        unmount: function () {
            var completion = api.closeAll({silentHistory: true});
            if (!mounted) return;
            mounted = false;
            document.removeEventListener('keydown', handleKey);
            document.removeEventListener('focusin', handleFocusIn, true);
            document.removeEventListener('click', handleNavigationClick, true);
            window.removeEventListener('popstate', handlePopstate);
            window.removeEventListener('beforeunload', handleBeforeUnload);
            if (rootElement) rootElement.removeEventListener('click', handleClick);
            Promise.resolve(completion).then(function () {
                Array.from(inertRecords.keys()).forEach(function (element) { setManagedInert(element, false); });
                if (host) host.style.removeProperty('z-index');
                if (backdrop) backdrop.style.removeProperty('z-index');
            });
        }
    };
}(window, document));
