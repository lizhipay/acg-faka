
(function (global) {
    let ev2Seq = 0;

    const ICON = {
        color: 'M2 20h20v4H2v-4zm3.49-3h2.42l1.27-3.58h5.65L16.09 17h2.42L13.25 3h-2.5L5.49 17zm4.42-5.61l2.03-5.79h.12l2.03 5.79H9.91z',
        size: 'M9 4v3h5v12h3V7h5V4H9zm-6 8h3v7h3v-7h3V9H3v3z',
        align: 'M7 15v2h10v-2H7zm-4 6h18v-2H3v2zm0-8h18v-2H3v2zm4-6v2h10V7H7zM3 3v2h18V3H3z',
        imgsize: 'M21 15h2v2h-2v-2zm0-4h2v2h-2v-2zm2 8h-2v2c1 0 2-1 2-2zM13 3h2v2h-2V3zm8 4h2v2h-2V7zm0-4v2h2c0-1-1-2-2-2zM1 7h2v2H1V7zm16-4h2v2h-2V3zm0 16h2v2h-2v-2zM3 3C2 3 1 4 1 5h2V3zm6 0h2v2H9V3zM5 3h2v2H5V3zm-4 8v8c0 1.1.9 2 2 2h12V11H1zm2 8l2.5-3.21 1.79 2.15 2.5-3.22L13 19H3z'
    };
    const TEXT_COLORS = ['#000000', '#595959', '#8c8c8c', '#e53935', '#fb8c00', '#f9a825', '#43a047', '#00897b', '#1e88e5', '#3949ab', '#8e24aa', '#d81b60'];
    const BG_COLORS = ['#fff59d', '#ffe0b2', '#ffcdd2', '#c8e6c9', '#b2ebf2', '#bbdefb', '#e1bee7', '#eeeeee'];
    const FONT_SIZES = [12, 14, 16, 18, 20, 24, 28, 32];
    const IMAGE_WIDTHS = [150, 300, 450, 600, 800];
    const MAX_IMAGE_WIDTH = 1200;

    function toolbarHtml(rich) {
        const tb = (cmd, icon, title) => `<button type="button" class="ev2-tb" data-cmd="${cmd}" title="${i18n(title)}"><i class="fa-duotone fa-regular ${icon}"></i></button>`;
        const pop = (name, title) => rich
            ? `<button type="button" class="ev2-tb ev2-rich" data-pop="${name}" title="${i18n(title)}" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="${ICON[name]}"/></svg></button>`
            : '';
        return tb('bold', 'fa-bold', '粗体')
            + tb('italic', 'fa-italic', '斜体')
            + pop('color', '文字颜色')
            + pop('size', '字号')
            + tb('heading', 'fa-heading', '标题')
            + tb('ul', 'fa-list-ul', '无序列表')
            + tb('ol', 'fa-list-ol', '有序列表')
            + tb('quote', 'fa-quote-right', '引用')
            + pop('align', '对齐')
            + tb('code', 'fa-code', '代码块')
            + tb('link', 'fa-link', '链接')
            + tb('image', 'fa-image', '图片')
            + pop('imgsize', '图片大小')
            + tb('table', 'fa-table', '表格');
    }

    function buildHtml(opt) {
        const name = opt.name;
        const ph = opt.placeholder ?? '';
        const allowHtmlSource = opt.allowHtmlSource !== false;
        const rich = opt.allowRawHtml !== false;
        return `<div class="ev2-editor" data-mode="md" data-preview="on">`
            + `<div class="ev2-bar"><div class="ev2-tools">${toolbarHtml(rich)}</div>`
            + `<div class="ev2-actions">`
            + `<button type="button" class="ev2-preview-toggle active" title="${i18n('预览开关')}" aria-pressed="true"><i class="fa-duotone fa-regular fa-eye"></i></button>`
            + (allowHtmlSource ? `<button type="button" data-type="0" class="ev2-mode-toggle" title="${i18n('HTML 源码')}"><i class="fa-duotone fa-regular fa-code me-1"></i>HTML</button>` : '')
            + `</div></div>`
            + `<div class="ev2-pop" hidden></div>`
            + `<div class="ev2-body"><div class="ev2-cm"><div class="ev2-ph">${ph}</div></div>`
            + `<div class="ev2-preview markdown-body"></div></div>`
            + `<input type="file" class="ev2-image" accept="image/*" style="display:none">`
            + `<textarea class="text-container" style="display:none;" name="${name}"></textarea>`
            + `</div>`;
    }

    function register(rootEl, opt) {
        opt = opt || {};
        let destroyed = false;
        const $root = $(rootEl);
        const $editor = $root.hasClass('ev2-editor') ? $root : $root.find('.ev2-editor').first();
        const $textarea = $editor.find('.text-container');
        const $preview = $editor.find('.ev2-preview');
        const $body = $editor.find('.ev2-body');
        const $ph = $editor.find('.ev2-ph');
        const $imgInput = $editor.find('.ev2-image');
        const $modeToggle = $editor.find('.ev2-mode-toggle');
        const $prevToggle = $editor.find('.ev2-preview-toggle');
        const cmHost = $editor.find('.ev2-cm').get(0);
        const uid = 'ev2-' + (opt.name || 'x') + '-' + (++ev2Seq);
        const aceId = uid + '-html';
        const uploadUrl = opt.uploadUrl || '/admin/api/upload/send';
        const allowRawHtml = opt.allowRawHtml !== false;

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const safeRenderer = allowRawHtml ? null : (() => {
            const renderer = new global.marked.Renderer();
            renderer.html = (token) => escapeHtml(typeof token === 'string' ? token : (token?.text ?? token?.raw ?? ''));
            return renderer;
        })();
        const sanitizePreview = (html) => {
            if (allowRawHtml) return html;
            const template = document.createElement('template');
            template.innerHTML = html;
            template.content.querySelectorAll('*').forEach((node) => {
                const tag = node.tagName.toLowerCase();
                if (!['p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'del', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr'].includes(tag)) {
                    node.replaceWith(document.createTextNode(node.textContent || ''));
                    return;
                }
                Array.from(node.attributes).forEach((attr) => {
                    const name = attr.name.toLowerCase();
                    const keep = ['href', 'src', 'alt', 'title', 'class'].includes(name);
                    if (!keep || name.startsWith('on') || name === 'style' || name === 'srcdoc') node.removeAttribute(attr.name);
                });
                ['href', 'src'].forEach((name) => {
                    const value = node.getAttribute(name);
                    if (!value) return;
                    try {
                        // Browsers discard C0 controls while resolving URLs. Parse the same
                        // normalized value so inputs such as "jav\tascript:" cannot bypass
                        // the preview guard through character references or whitespace.
                        const normalized = value.replace(/[\u0000-\u0020\u007f-\u009f]/g, '');
                        const parsed = new URL(normalized, global.location.href);
                        if (!['http:', 'https:'].includes(parsed.protocol)) node.removeAttribute(name);
                    } catch (e) {
                        node.removeAttribute(name);
                    }
                });
                if (tag === 'a') {
                    node.setAttribute('rel', 'noopener noreferrer nofollow');
                    if (node.getAttribute('href')) node.setAttribute('target', '_blank');
                }
            });
            return template.innerHTML;
        };
        const md2html = (src) => sanitizePreview(global.marked.parse(src ?? '', {
            gfm: true,
            breaks: true,
            ...(safeRenderer ? {renderer: safeRenderer} : {})
        }));
        const turndown = new global.TurndownService({headingStyle: 'atx', codeBlockStyle: 'fenced', bulletListMarker: '-'});
        // keep media / styling tags markdown can't represent so legacy HTML round-trips visually
        turndown.keep(['video', 'audio', 'iframe', 'source', 'embed', 'font', 'span', 'sub', 'sup', 'ins', 'del', 's', 'strike', 'mark', 'u', 'small', 'kbd', 'center', 'marquee', 'table', 'style']);
        if (allowRawHtml) {
            turndown.addRule('ev2SizedImage', {
                filter: (node) => node.nodeName === 'IMG' && ['width', 'height', 'style', 'align'].some((name) => node.hasAttribute(name)),
                replacement: (content, node) => node.outerHTML
            });
            turndown.addRule('ev2StyledBlock', {
                filter: (node) => /^(P|DIV|H[1-6])$/.test(node.nodeName) && (node.hasAttribute('style') || node.hasAttribute('align')),
                replacement: (content, node) => '\n\n' + node.outerHTML.replace(/\n\s*\n/g, '\n') + '\n\n'
            });
        }
        const html2md = (html) => {
            try {
                return turndown.turndown(html ?? '');
            } catch (e) {
                return html ?? '';
            }
        };
        const normalizeDefault = (html) => {
            if (!html) return '';
            const t = String(html).replace(/\s|&nbsp;|<br\s*\/?>|<\/?p>/gi, '');
            return t === '' ? '' : String(html);
        };

        // Does this HTML survive HTML -> Markdown -> HTML? Compare "tag@attribute" counts: anything
        // present before and missing after is formatting markdown cannot carry (inline styles, align,
        // link targets, image sizes...). DOMParser builds an inert document: no scripts, no image loads.
        const attrSignature = (html) => {
            const sig = new Map();
            const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
            doc.body.querySelectorAll('*').forEach((el) => {
                Array.from(el.attributes).forEach((attr) => {
                    const key = el.tagName.toLowerCase() + '@' + attr.name.toLowerCase();
                    sig.set(key, (sig.get(key) || 0) + 1);
                });
            });
            return sig;
        };
        const markdownLossy = (html) => {
            if (!html || String(html).trim() === '') return false;
            try {
                const before = attrSignature(String(html));
                if (before.size === 0) return false;
                const after = attrSignature(md2html(html2md(String(html))));
                for (const [key, count] of before) {
                    if ((after.get(key) || 0) < count) return true;
                }
            } catch (e) {
                return false;
            }
            return false;
        };

        // --- markdown formatting commands (selection wrap / line prefix / snippet) ---
        const applyCmd = (cm, cmd) => {
            const doc = cm.getDoc();
            const sel = doc.getSelection();
            const wrap = (l, r = l) => doc.replaceSelection(l + (sel || '') + r);
            const linePrefix = (p) => {
                const from = doc.getCursor('from'), to = doc.getCursor('to');
                for (let n = from.line; n <= to.line; n++) doc.replaceRange(p, {line: n, ch: 0});
            };
            switch (cmd) {
                case 'bold': wrap('**'); break;
                case 'italic': wrap('*'); break;
                case 'heading': linePrefix('## '); break;
                case 'ul': linePrefix('- '); break;
                case 'ol': linePrefix('1. '); break;
                case 'quote': linePrefix('> '); break;
                case 'code': (sel && sel.indexOf('\n') >= 0) ? wrap('\n```\n', '\n```\n') : wrap('`'); break;
                case 'link': doc.replaceSelection(`[${sel || i18n('链接文字')}](https://)`); break;
                case 'table': doc.replaceSelection('\n|  |  |\n| --- | --- |\n|  |  |\n'); break;
            }
        };

        // --- seed: hidden textarea holds canonical HTML; CodeMirror shows the markdown ---
        const rawDefault = (opt.value !== undefined && opt.value !== null) ? opt.value : ($textarea.val() || '');
        const seedHtml = normalizeDefault(rawDefault);
        const seedMd = seedHtml ? html2md(seedHtml) : '';
        $textarea.val(seedHtml);
        $preview.html(allowRawHtml ? seedHtml : sanitizePreview(seedHtml));

        const cmHeight = opt.height ? (Number.isInteger(opt.height) ? opt.height + 'px' : opt.height) : '460px';
        const cm = global.CodeMirror(cmHost, {
            value: seedMd,
            mode: 'markdown',
            // Keep CodeMirror on its hidden-textarea input path for consistent IME and
            // touch input. Cursor geometry is refreshed separately after popup motion.
            inputStyle: 'textarea',
            lineWrapping: true,
            lineNumbers: false,
            extraKeys: {
                'Cmd-B': () => applyCmd(cm, 'bold'), 'Ctrl-B': () => applyCmd(cm, 'bold'),
                'Cmd-I': () => applyCmd(cm, 'italic'), 'Ctrl-I': () => applyCmd(cm, 'italic'),
                'Cmd-K': () => applyCmd(cm, 'link'), 'Ctrl-K': () => applyCmd(cm, 'link')
            }
        });
        cm.setSize('100%', cmHeight);
        const cmWrapper = cm.getWrapperElement();
        $editor[0].style.setProperty('--ev2-height', cmHeight);
        const syncPaneHeight = () => {
            const height = cmWrapper.offsetHeight;
            if (height > 0) $editor[0].style.setProperty('--ev2-height', height + 'px');
        };
        let paneObserver = null;
        try {
            paneObserver = new ResizeObserver(syncPaneHeight);
            paneObserver.observe(cmWrapper);
        } catch (e) {}

        // component.popup registers the form before layui adds its entrance-animation
        // class. Any refresh queued immediately here can therefore run while the whole
        // popup is translated/rotated and make CodeMirror cache transformed character
        // coordinates. Wait until layui has removed its animation class, then measure.
        const popupLayer = $editor.closest('.layui-layer').get(0);
        let layoutReady = !popupLayer;
        let layoutTimer = null;
        let pendingSourceOpen = null;
        const refreshEditor = () => {
            if (!destroyed && cmHost.isConnected) cm.refresh();
        };
        const queueRefresh = () => {
            if (!layoutReady) return;
            global.requestAnimationFrame(refreshEditor);
        };
        const settlePopupLayout = () => {
            if (layoutReady) return;
            layoutReady = true;
            if (layoutTimer !== null) {
                clearTimeout(layoutTimer);
                layoutTimer = null;
            }
            global.requestAnimationFrame(refreshEditor);
            if (pendingSourceOpen) {
                const open = pendingSourceOpen;
                pendingSourceOpen = null;
                global.requestAnimationFrame(open);
            }
        };

        if (popupLayer) {
            const layoutDeadline = Date.now() + 1200;
            const waitForStablePopup = () => {
                if (!cmHost.isConnected) return;
                if (popupLayer.classList.contains('layer-anim') && Date.now() < layoutDeadline) {
                    layoutTimer = setTimeout(waitForStablePopup, 32);
                    return;
                }
                settlePopupLayout();
            };
            // The class is added synchronously after component.popup's success callback
            // returns, so probe on the next frame instead of treating its current absence
            // as a settled popup.
            layoutTimer = setTimeout(waitForStablePopup, 32);
        } else {
            queueRefresh();
        }

        const togglePh = () => $ph.css('display', cm.getValue() === '' ? 'block' : 'none');
        togglePh();

        const linkCheck = allowRawHtml && (opt.linkCheck ?? String(global.location.pathname).startsWith('/admin'));
        const linkState = {timer: null, okTimer: null, request: null, known: new Map(), dismissed: new Set(), canAdd: false};
        const $linkWarn = $('<div class="ev2-linkwarn" role="status" hidden></div>');
        if (linkCheck) $body.before($linkWarn);
        const linkHosts = (html) => {
            const hosts = new Set();
            if (!html) return hosts;
            const add = (value) => {
                const raw = String(value || '').trim();
                if (!/^(?:https?:)?\/\//i.test(raw)) return;
                try {
                    const url = new URL(raw, global.location.href);
                    if ((url.protocol === 'http:' || url.protocol === 'https:') && url.hostname && url.hostname !== global.location.hostname) {
                        hosts.add(url.hostname.toLowerCase());
                    }
                } catch (e) {}
            };
            const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
            doc.body.querySelectorAll('*').forEach((el) => {
                ['href', 'src', 'poster', 'cite', 'longdesc', 'action', 'data', 'background'].forEach((name) => {
                    if (el.hasAttribute(name)) add(el.getAttribute(name));
                });
                if (el.hasAttribute('srcset')) {
                    el.getAttribute('srcset').split(',').forEach((part) => add(part.trim().split(/\s+/)[0]));
                }
                const style = el.getAttribute('style');
                if (style) {
                    style.replace(/url\(\s*(['"]?)(.*?)\1\s*\)/gi, (match, quote, url) => {
                        add(url);
                        return match;
                    });
                }
            });
            return hosts;
        };
        const renderLinkWarn = (blocked) => {
            if (!blocked.length) {
                $linkWarn.attr('hidden', 'hidden').removeClass('is-ok').empty().removeData('hosts');
                return;
            }
            const list = blocked.map((host) => `<b>${escapeHtml(host)}</b>`).join('、');
            const text = linkState.canAdd
                ? i18n('这些域名不在外链白名单里，保存时它们的链接和图片会被安全机制去掉：')
                : i18n('这些域名不在外链白名单里，保存时它们的链接和图片会被安全机制去掉，请联系站长在「安全设置 → 外链域名白名单」里加入：');
            const actions = linkState.canAdd
                ? `<button type="button" class="ev2-linkwarn-btn is-primary" data-link-act="allow">${escapeHtml(i18n('加入白名单'))}</button>`
                    + `<button type="button" class="ev2-linkwarn-btn" data-link-act="dismiss">${escapeHtml(i18n('暂不处理'))}</button>`
                : `<button type="button" class="ev2-linkwarn-btn" data-link-act="dismiss">${escapeHtml(i18n('知道了'))}</button>`;
            $linkWarn.removeClass('is-ok').data('hosts', blocked)
                .html(`<div class="ev2-linkwarn-text">${escapeHtml(text)}${list}</div><div class="ev2-linkwarn-actions">${actions}</div>`)
                .removeAttr('hidden');
        };
        const runLinkCheck = () => {
            if (destroyed || !linkCheck) return;
            const hosts = Array.from(linkHosts($textarea.val()));
            let pruned = false;
            cspState.blocked.forEach((item, key) => {
                if (!hosts.includes(item.host)) {
                    cspState.blocked.delete(key);
                    pruned = true;
                }
            });
            if (pruned) renderCspWarn();
            const show = () => renderLinkWarn(hosts.filter((host) => linkState.known.get(host) === false && !linkState.dismissed.has(host)));
            const unknown = hosts.filter((host) => !linkState.known.has(host));
            if (!unknown.length) {
                show();
                return;
            }
            if (linkState.request) linkState.request.abort();
            linkState.request = $.ajax({url: '/admin/api/config/linkDomainCheck', type: 'POST', data: {hosts: unknown}, dataType: 'json'})
                .done((res) => {
                    if (destroyed || !res || res.code !== 200 || !res.data) return;
                    linkState.canAdd = !!res.data.can_add;
                    const blocked = new Set((res.data.blocked || []).map(String));
                    unknown.forEach((host) => linkState.known.set(host, !blocked.has(host)));
                    show();
                    if (cspState.blocked.size) renderCspWarn();
                })
                .always(() => {
                    linkState.request = null;
                });
        };
        const queueLinkCheck = () => {
            if (!linkCheck || destroyed) return;
            clearTimeout(linkState.timer);
            linkState.timer = setTimeout(runLinkCheck, 800);
        };
        $linkWarn.on('click', '[data-link-act]', function () {
            const hosts = ($linkWarn.data('hosts') || []).slice();
            if ($(this).attr('data-link-act') !== 'allow') {
                hosts.forEach((host) => linkState.dismissed.add(host));
                renderLinkWarn([]);
                return;
            }
            const $btn = $(this).prop('disabled', true);
            $.ajax({url: '/admin/api/config/linkDomainAllow', type: 'POST', data: {hosts: hosts}, dataType: 'json'})
                .done((res) => {
                    if (destroyed) return;
                    if (!res || res.code !== 200) {
                        $btn.prop('disabled', false);
                        layer.msg((res && res.msg) || i18n('加入白名单失败'));
                        return;
                    }
                    hosts.forEach((host) => linkState.known.set(host, true));
                    $linkWarn.addClass('is-ok').removeData('hosts')
                        .html(`<div class="ev2-linkwarn-text">${escapeHtml(i18n('已加入外链白名单，现在可以正常保存：'))}${hosts.map((host) => `<b>${escapeHtml(host)}</b>`).join('、')}</div>`);
                    clearTimeout(linkState.okTimer);
                    linkState.okTimer = setTimeout(runLinkCheck, 4000);
                })
                .fail(() => {
                    if (destroyed) return;
                    $btn.prop('disabled', false);
                    layer.msg(i18n('加入白名单失败'));
                });
        });

        const cspLabels = {'frame-src': '框架', 'child-src': '框架', 'media-src': '音视频'};
        const cspState = {blocked: new Map(), handled: new Set(), okTimer: null};
        const $cspWarn = $('<div class="ev2-linkwarn" role="status" hidden></div>');
        if (linkCheck) $body.before($cspWarn);
        const renderCspWarn = () => {
            const items = Array.from(cspState.blocked.values());
            if (!items.length) {
                if (!$cspWarn.hasClass('is-ok')) $cspWarn.attr('hidden', 'hidden').empty();
                return;
            }
            const list = items.map((item) => `<b>${escapeHtml(item.origin)}</b>（${escapeHtml(i18n(item.label))}）`).join('、');
            const text = linkState.canAdd
                ? i18n('本站的 CSP 安全策略会拦下这些外部内容，访客看不到：')
                : i18n('本站的 CSP 安全策略会拦下这些外部内容，访客看不到，请联系站长在「安全设置 → 违规统计」里允许：');
            const actions = linkState.canAdd
                ? `<button type="button" class="ev2-linkwarn-btn is-primary" data-csp-act="allow">${escapeHtml(i18n('允许显示'))}</button>`
                    + `<button type="button" class="ev2-linkwarn-btn" data-csp-act="dismiss">${escapeHtml(i18n('暂不处理'))}</button>`
                : `<button type="button" class="ev2-linkwarn-btn" data-csp-act="dismiss">${escapeHtml(i18n('知道了'))}</button>`;
            $cspWarn.removeClass('is-ok')
                .html(`<div class="ev2-linkwarn-text">${escapeHtml(text)}${list}</div><div class="ev2-linkwarn-actions">${actions}</div>`)
                .removeAttr('hidden');
        };
        const onCspViolation = (e) => {
            if (destroyed || e.disposition !== 'enforce' || !cspLabels[e.effectiveDirective]) return;
            let url;
            try {
                url = new URL(e.blockedURI);
            } catch (err) {
                return;
            }
            if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
            const host = url.hostname.toLowerCase();
            if (!linkHosts($textarea.val()).has(host)) return;
            const key = e.effectiveDirective + '|' + url.origin;
            if (cspState.handled.has(key) || cspState.blocked.has(key)) return;
            cspState.blocked.set(key, {directive: e.effectiveDirective, blocked: e.blockedURI, origin: url.origin, host: host, label: cspLabels[e.effectiveDirective]});
            renderCspWarn();
        };
        if (linkCheck) document.addEventListener('securitypolicyviolation', onCspViolation, true);
        $cspWarn.on('click', '[data-csp-act]', function () {
            const items = Array.from(cspState.blocked.entries());
            if ($(this).attr('data-csp-act') !== 'allow') {
                items.forEach(([key]) => cspState.handled.add(key));
                cspState.blocked.clear();
                renderCspWarn();
                return;
            }
            $cspWarn.find('button').prop('disabled', true);
            const allowed = [];
            const next = (index) => {
                if (destroyed) return;
                if (index >= items.length) {
                    $cspWarn.addClass('is-ok')
                        .html(`<div class="ev2-linkwarn-text">${escapeHtml(i18n('已允许显示，刷新页面后生效：'))}${allowed.map((origin) => `<b>${escapeHtml(origin)}</b>`).join('、')}</div>`)
                        .removeAttr('hidden');
                    clearTimeout(cspState.okTimer);
                    cspState.okTimer = setTimeout(() => {
                        $cspWarn.removeClass('is-ok');
                        renderCspWarn();
                    }, 6000);
                    return;
                }
                const [key, item] = items[index];
                const fail = (msg) => {
                    if (destroyed) return;
                    renderCspWarn();
                    layer.msg(msg || i18n('允许失败'));
                };
                $.ajax({url: '/admin/api/config/cspAllowBlocked', type: 'POST', data: {directive: item.directive, blocked: item.blocked}, dataType: 'json'})
                    .done((res) => {
                        if (destroyed) return;
                        if (!res || res.code !== 200) {
                            fail(res && res.msg);
                            return;
                        }
                        cspState.handled.add(key);
                        cspState.blocked.delete(key);
                        allowed.push(item.origin);
                        next(index + 1);
                    })
                    .fail(() => fail());
            };
            next(0);
        });
        queueLinkCheck();

        // --- live render: markdown -> HTML -> hidden textarea + preview (debounced) ---
        // The hidden textarea holds the canonical HTML; the markdown in CodeMirror is only a view of it
        // (turndown). Rendering markdown back is lossy, so the canonical HTML is regenerated only after
        // the user actually edits the markdown. Opening an item and saving untouched used to re-render
        // and wipe every inline style (#952). Programmatic setValue (mode switch, setHTML) is not an edit.
        let mdDirty = false;
        let rid;
        const render = () => {
            if (destroyed) return;
            if ($editor.attr('data-mode') === 'html') return;
            const src = cm.getValue();
            const html = src.trim() === '' ? '' : md2html(src);
            $textarea.val(html);
            $preview.html(html);
            togglePh();
            queueLinkCheck();
            opt.onChange && opt.onChange(html);
        };
        const onMarkdownChange = (instance, change) => {
            if (change && change.origin === 'setValue') return;
            mdDirty = true;
            clearTimeout(rid);
            rid = setTimeout(render, 120);
        };
        cm.on('change', onMarkdownChange);

        // --- toolbar ---
        $editor.find('.ev2-tb').on('click', function () {
            const panel = $(this).attr('data-pop');
            if (panel) {
                openPop(panel);
                return;
            }
            const cmd = $(this).data('cmd');
            if (cmd === 'image') {
                $imgInput.trigger('click');
                return;
            }
            applyCmd(cm, cmd);
            cm.focus();
        });

        // --- image upload (reuse the same endpoint + response shape as the old editor) ---
        const uploadRequests = new Set();
        const uploadImage = (file) => {
            if (!file || destroyed) return;
            const fd = new FormData();
            fd.append('file', file);
            const request = $.ajax({
                url: uploadUrl + '?mime=image', type: 'POST', data: fd,
                processData: false, contentType: false,
                success: (res) => {
                    uploadRequests.delete(request);
                    if (destroyed) return;
                    if (res.code !== 200) {
                        layer.msg(res.msg);
                        return;
                    }
                    cm.replaceSelection(`![](${res.data.url})`);
                    cm.focus();
                },
                error: (xhr, status) => {
                    uploadRequests.delete(request);
                    if (!destroyed && status !== 'abort') layer.msg(i18n('图片上传失败，文件可能过大'));
                }
            });
            uploadRequests.add(request);
        };
        $imgInput.on('change', function () {
            uploadImage(this.files && this.files[0]);
            this.value = '';
        });
        const onPaste = (cmi, e) => {
            const items = e.clipboardData && e.clipboardData.items;
            if (!items) return;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image') === 0) {
                    e.preventDefault();
                    uploadImage(items[i].getAsFile());
                }
            }
        };
        const onDrop = (cmi, e) => {
            const files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length && files[0].type && files[0].type.indexOf('image') === 0) {
                e.preventDefault();
                uploadImage(files[0]);
            }
        };
        cm.on('paste', onPaste);
        cm.on('drop', onDrop);

        // --- preview on/off toggle (persisted) ---
        const applyPreview = (on) => {
            $editor.attr('data-preview', on ? 'on' : 'off');
            $prevToggle.attr('aria-pressed', on ? 'true' : 'false').toggleClass('active', on);
            queueRefresh();
        };
        const prevPref = localStorage.getItem('ev2-preview');
        applyPreview(prevPref === null ? true : prevPref === '1');
        $prevToggle.on('click', function () {
            const on = $editor.attr('data-preview') !== 'on';
            try {
                localStorage.setItem('ev2-preview', on ? '1' : '0');
            } catch (e) {}
            applyPreview(on);
        });

        // --- mode toggle: markdown <-> HTML source (reuse existing ACE) ---
        let aceEditor = null;
        $modeToggle.on('click', function () {
            const $btn = $(this);
            if ($btn.attr('data-type') == 0) {
                $btn.attr('data-type', 1).html('<i class="fa-duotone fa-regular fa-pen-paintbrush me-1"></i>' + i18n('写作'));
                // Untouched markdown: open the canonical HTML as-is instead of a lossy re-render.
                if (mdDirty) {
                    clearTimeout(rid);
                    $textarea.val(cm.getValue().trim() === '' ? '' : md2html(cm.getValue()));
                }
                $editor.attr('data-mode', 'html');
                $body.hide();
                $prevToggle.hide();
                $editor.append(`<div id="${aceId}" class="ev2-ace" style="width:100%;height:${cmHeight};"></div>`);
                aceEditor = ace.edit(aceId, {theme: 'ace/theme/chrome', mode: 'ace/mode/html'});
                aceEditor.getSession().setUseWrapMode(true);
                aceEditor.setOption('showPrintMargin', false);
                aceEditor.setValue($textarea.val(), -1);
                aceEditor.getSession().on('change', () => {
                    const h = aceEditor.getValue();
                    $textarea.val(h);
                    $preview.html(h);
                    queueLinkCheck();
                    opt.onChange && opt.onChange(h);
                });
            } else {
                const toWriting = () => {
                    $btn.attr('data-type', 0).html('<i class="fa-duotone fa-regular fa-code me-1"></i>HTML');
                    const html = $textarea.val();
                    // The HTML edited in source mode stays canonical until the markdown is edited.
                    mdDirty = false;
                    cm.setValue(html.trim() === '' ? '' : html2md(html));
                    $preview.html(allowRawHtml ? html : sanitizePreview(html));
                    $('#' + aceId).remove();
                    aceEditor = null;
                    $editor.attr('data-mode', 'md');
                    $editor.find('.ev2-note').remove();
                    $body.show();
                    $prevToggle.show();
                    togglePh();
                    queueRefresh();
                };
                if (!markdownLossy($textarea.val())) {
                    toWriting();
                    return;
                }
                layer.confirm(i18n('写作模式保留不了这些排版（行内样式、对齐、新窗口打开等），在写作模式里修改并保存后会丢失。仍要切换吗？'), {
                    title: i18n('切换到写作模式'),
                    btn: [i18n('切换'), i18n('取消')]
                }, (index) => {
                    layer.close(index);
                    toWriting();
                });
            }
        });

        const $pop = $editor.find('.ev2-pop');
        let popName = null;
        const STYLE_ATTR = /\sstyle\s*=\s*"([^"]*)"/i;
        const BLOCK_LINE = /^(\s*)<(p|div|h[1-6])\b([^>]*)>([\s\S]*)<\/\2>\s*$/i;
        const LINE_PREFIX = /^(\s*(?:(?:[-*+]|\d+[.)])\s+|#{1,6}\s+|>\s?)*)([\s\S]*?)(\s*)$/;
        const IMAGE_PATTERN = /!\[([^\]]*)\]\(\s*<?([^\s)>]+)>?(?:\s+"([^"]*)")?\s*\)|<img\b[^>]*>/gi;

        const parseStyle = (text) => {
            const decl = new Map();
            String(text || '').split(';').forEach((part) => {
                const i = part.indexOf(':');
                if (i <= 0) return;
                const name = part.slice(0, i).trim().toLowerCase();
                const value = part.slice(i + 1).trim();
                if (name && value) decl.set(name, value);
            });
            return decl;
        };
        const stringifyStyle = (decl) => Array.from(decl, ([name, value]) => `${name}:${value}`).join(';');
        const attrText = (attrs) => attrs.map(([name, value]) => ` ${name}="${escapeHtml(value)}"`).join('');

        const singleSpan = (text) => {
            const open = text.match(/^<span\b[^>]*>/i);
            if (!open || !/<\/span>$/i.test(text)) return null;
            const body = new DOMParser().parseFromString(`<body>${text}</body>`, 'text/html').body;
            if (body.childNodes.length !== 1 || body.firstChild.nodeName !== 'SPAN') return null;
            return {
                attrs: Array.from(body.firstChild.attributes, (a) => [a.name.toLowerCase(), a.value]),
                inner: text.slice(open[0].length, text.length - 7)
            };
        };
        const wrapSpan = (line, prop, value) => {
            const block = line.match(BLOCK_LINE);
            if (block) return `${block[1]}<${block[2]}${block[3]}>${wrapSpan(block[4], prop, value)}</${block[2]}>`;
            const m = line.match(LINE_PREFIX);
            return m[2] === '' ? line : `${m[1]}<span style="${prop}:${value}">${m[2]}</span>${m[3]}`;
        };
        const styleText = (text, prop, value) => {
            const span = singleSpan(text);
            if (!span) return text.split('\n').map((line) => wrapSpan(line, prop, value)).join('\n');
            const style = span.attrs.find(([name]) => name === 'style');
            const decl = parseStyle(style ? style[1] : '');
            decl.set(prop, value);
            return `<span${attrText(span.attrs.filter(([name]) => name !== 'style').concat([['style', stringifyStyle(decl)]]))}>${span.inner}</span>`;
        };
        const clearStyle = (text, prop) => {
            let changed = false;
            const out = text.replace(/<span\b([^>]*)>/gi, (tag, rest) => {
                const style = rest.match(STYLE_ATTR);
                if (!style) return tag;
                const decl = parseStyle(style[1]);
                if (!decl.has(prop)) return tag;
                decl.delete(prop);
                changed = true;
                return `<span${rest.replace(STYLE_ATTR, '')}${decl.size ? ` style="${stringifyStyle(decl)}"` : ''}>`;
            });
            return changed ? out.replace(/<span>([^<]*)<\/span>/gi, '$1') : null;
        };

        const alignLine = (line, align, html) => {
            if (line.trim() === '') return line;
            const block = line.match(BLOCK_LINE);
            if (block) {
                const tag = block[2].toLowerCase();
                const style = block[3].match(STYLE_ATTR);
                const decl = parseStyle(style ? style[1] : '');
                if (align === 'left') {
                    decl.delete('text-align');
                } else {
                    decl.set('text-align', align);
                }
                const rest = block[3].replace(STYLE_ATTR, '').replace(/\salign\s*=\s*"[^"]*"/i, '');
                if (!html && !decl.size && rest.trim() === '') {
                    const md = html2md(block[4]).replace(/\s*\n\s*/g, ' ').trim();
                    return tag.charAt(0) === 'h' ? '#'.repeat(Number(tag.charAt(1))) + ' ' + md : md;
                }
                return `${block[1]}<${tag}${rest}${decl.size ? ` style="${stringifyStyle(decl)}"` : ''}>${block[4]}</${tag}>`;
            }
            if (align === 'left') return line;
            if (html) return `<p style="text-align:${align}">${line}</p>`;
            const heading = line.match(/^(#{1,6})\s+(.*)$/);
            const tag = heading ? 'h' + heading[1].length : 'p';
            const inner = global.marked.parseInline(heading ? heading[2] : line.trim(), {gfm: true, breaks: true});
            return `<${tag} style="text-align:${align}">${inner}</${tag}>`;
        };

        const imageAttrs = (tag) => {
            const el = new DOMParser().parseFromString(`<body>${tag}</body>`, 'text/html').body.querySelector('img');
            return el ? Array.from(el.attributes, (a) => [a.name.toLowerCase(), a.value]) : null;
        };
        const sizeImages = (text, width, html) => {
            let count = 0;
            const out = text.replace(IMAGE_PATTERN, (all, alt, src, title) => {
                let attrs = all.charAt(0) === '<' ? imageAttrs(all) : [['src', src], ['alt', alt || '']].concat(title ? [['title', title]] : []);
                if (!attrs) return all;
                count++;
                attrs = attrs.filter(([name]) => name !== 'width' && name !== 'height').map(([name, value]) => {
                    if (name !== 'style') return [name, value];
                    const decl = parseStyle(value);
                    decl.delete('width');
                    decl.delete('height');
                    return [name, stringifyStyle(decl)];
                }).filter(([name, value]) => name !== 'style' || value !== '');
                if (width) attrs.push(['width', String(width)]);
                const get = (key) => (attrs.find(([name]) => name === key) || [])[1] || '';
                if (!width && !html && attrs.every(([name]) => ['src', 'alt', 'title'].includes(name))
                    && !/[\[\]\n]/.test(get('alt')) && !/["\n]/.test(get('title'))) {
                    return `![${get('alt')}](${get('src')}${get('title') ? ` "${get('title')}"` : ''})`;
                }
                return `<img${attrText(attrs)}>`;
            });
            return {text: out, count: count};
        };

        const surface = () => {
            if (aceEditor) {
                const ed = aceEditor;
                const session = ed.getSession();
                const doc = session.getDocument();
                const Range = global.ace.require('ace/range').Range;
                const select = (a, b) => ed.selection.setRange(new Range(a.row, a.column, b.row, b.column));
                return {
                    html: true,
                    selection: () => ed.getSelectedText(),
                    replace: (text, pick) => {
                        const range = ed.getSelectionRange();
                        const base = doc.positionToIndex(range.start);
                        const end = session.replace(range, text);
                        if (pick === 'around') select(range.start, end);
                        else if (pick) select(doc.indexToPosition(base + pick[0]), doc.indexToPosition(base + pick[1]));
                    },
                    lines: () => {
                        const range = ed.getSelectionRange();
                        const first = range.start.row;
                        const last = (range.end.column === 0 && range.end.row > first) ? range.end.row - 1 : range.end.row;
                        return {
                            text: session.getLines(first, last),
                            before: first > 0 ? session.getLine(first - 1) : '',
                            after: last < session.getLength() - 1 ? session.getLine(last + 1) : '',
                            put: (value) => session.replace(new Range(first, 0, last, session.getLine(last).length), value)
                        };
                    },
                    focus: () => ed.focus()
                };
            }
            const doc = cm.getDoc();
            return {
                html: false,
                selection: () => doc.getSelection(),
                replace: (text, pick) => {
                    const base = doc.indexFromPos(doc.getCursor('from'));
                    doc.replaceSelection(text, pick === 'around' ? 'around' : 'end');
                    if (Array.isArray(pick)) doc.setSelection(doc.posFromIndex(base + pick[0]), doc.posFromIndex(base + pick[1]));
                },
                lines: () => {
                    const from = doc.getCursor('from'), to = doc.getCursor('to');
                    const first = from.line;
                    const last = (to.ch === 0 && to.line > first) ? to.line - 1 : to.line;
                    const text = [];
                    for (let n = first; n <= last; n++) text.push(doc.getLine(n));
                    return {
                        text: text,
                        before: first > 0 ? doc.getLine(first - 1) : '',
                        after: last < doc.lineCount() - 1 ? doc.getLine(last + 1) : '',
                        put: (value) => doc.replaceRange(value, {line: first, ch: 0}, {line: last, ch: doc.getLine(last).length})
                    };
                },
                focus: () => cm.focus()
            };
        };

        const applyStyle = (s, prop, value) => {
            const sel = s.selection();
            if (!value) {
                const out = sel ? clearStyle(sel, prop) : null;
                if (out === null) {
                    layer.msg(i18n('请先选中要清除样式的文字'));
                    return false;
                }
                s.replace(out, 'around');
                return true;
            }
            if (sel) {
                s.replace(styleText(sel, prop, value), 'around');
                return true;
            }
            const open = `<span style="${prop}:${value}">`;
            const placeholder = i18n('文字');
            s.replace(open + placeholder + '</span>', [open.length, open.length + placeholder.length]);
            return true;
        };
        const applyAlign = (s, align) => {
            const lines = s.lines();
            const before = lines.text.join('\n');
            let out = lines.text.map((line) => alignLine(line, align, s.html)).join('\n');
            if (!s.html && out !== before) {
                if (lines.before.trim() !== '') out = '\n' + out;
                if (lines.after.trim() !== '') out = out + '\n';
            }
            lines.put(out);
            return true;
        };
        const applyWidth = (s, width) => {
            const sel = s.selection();
            const lines = sel ? null : s.lines();
            const result = sizeImages(sel || lines.text.join('\n'), width, s.html);
            if (!result.count) {
                layer.msg(i18n('没找到图片：把光标放在图片所在的行，或选中图片'));
                return false;
            }
            sel ? s.replace(result.text, 'around') : lines.put(result.text);
            return true;
        };

        const closePop = () => {
            popName = null;
            $pop.attr('hidden', 'hidden').empty();
            $editor.find('.ev2-tb[data-pop]').attr('aria-expanded', 'false');
        };
        const popButton = (act, value, label) => `<button type="button" class="ev2-pop-btn" data-act="${act}" data-value="${escapeHtml(value)}">${escapeHtml(label)}</button>`;
        const popSwatch = (act, color) => `<button type="button" class="ev2-swatch" data-act="${act}" data-value="${color}" title="${color}" style="background:${color}"></button>`;
        const popRow = (label, body) => `<div class="ev2-pop-row"><span class="ev2-pop-label">${escapeHtml(label)}</span>${body}</div>`;
        const popHtml = (name) => {
            if (name === 'color') {
                return popRow(i18n('文字颜色'), TEXT_COLORS.map((c) => popSwatch('color', c)).join('')
                        + `<input type="color" class="ev2-pop-picker" value="#e53935" title="${escapeHtml(i18n('自定义颜色'))}">`
                        + popButton('color', '', i18n('默认')))
                    + popRow(i18n('背景色'), BG_COLORS.map((c) => popSwatch('background-color', c)).join('') + popButton('background-color', '', i18n('默认')));
            }
            if (name === 'size') {
                return popRow(i18n('字号'), FONT_SIZES.map((size) => popButton('font-size', size + 'px', String(size))).join('') + popButton('font-size', '', i18n('默认')));
            }
            if (name === 'align') {
                return popRow(i18n('对齐'), popButton('align', 'left', i18n('左对齐')) + popButton('align', 'center', i18n('居中')) + popButton('align', 'right', i18n('右对齐')));
            }
            return popRow(i18n('图片大小'), IMAGE_WIDTHS.map((w) => popButton('width', String(w), w + 'px')).join('')
                + popButton('width', '', i18n('原始大小')) + popButton('width', 'custom', i18n('自定义')));
        };
        const openPop = (name) => {
            if (popName === name) {
                closePop();
                return;
            }
            popName = name;
            $pop.html(popHtml(name)).removeAttr('hidden');
            $editor.find('.ev2-tb[data-pop]').each(function () {
                $(this).attr('aria-expanded', $(this).attr('data-pop') === name ? 'true' : 'false');
            });
        };
        const runOption = (act, value) => {
            const s = surface();
            let done;
            if (act === 'align') {
                done = applyAlign(s, value);
            } else if (act === 'width') {
                done = applyWidth(s, value === '' ? null : Number(value));
            } else {
                done = applyStyle(s, act, value);
            }
            if (done) closePop();
            s.focus();
        };
        const promptWidth = () => {
            layer.prompt({title: i18n('图片宽度（像素，16 到 1200）'), formType: 0, value: '400'}, (text, index) => {
                const width = Number(String(text).trim());
                if (!Number.isInteger(width) || width < 16 || width > MAX_IMAGE_WIDTH) {
                    layer.msg(i18n('宽度请填 16 到 1200 之间的整数'));
                    return;
                }
                layer.close(index);
                runOption('width', String(width));
            });
        };
        const onOutsidePointer = (e) => {
            if (popName && !$editor[0].contains(e.target)) closePop();
        };
        if (allowRawHtml) {
            $pop.on('click', '[data-act]', function () {
                const act = $(this).attr('data-act');
                const value = $(this).attr('data-value') || '';
                if (act === 'width' && value === 'custom') {
                    promptWidth();
                    return;
                }
                runOption(act, value);
            });
            $pop.on('change', '.ev2-pop-picker', function () {
                runOption('color', this.value);
            });
            $editor.on('keydown', (e) => {
                if (e.key === 'Escape' && popName) closePop();
            });
            document.addEventListener('pointerdown', onOutsidePointer, true);
        } else {
            $editor.find('.ev2-rich').remove();
            $pop.remove();
        }

        // HTML that markdown cannot carry (inline styles, align, link targets, image sizes...) opens in
        // HTML source mode, so fixing one typo in writing mode cannot wipe the whole layout on save (#952).
        // Deferred until the popup's entrance animation settles: ACE measures glyphs when it is created.
        if ($modeToggle.length && typeof global.ace !== 'undefined' && markdownLossy(seedHtml)) {
            const openSource = () => {
                if (destroyed || $modeToggle.attr('data-type') != 0) return;
                $modeToggle.trigger('click');
                $editor.find('.ev2-bar').after(`<div class="ev2-note" style="padding:6px 12px;font-size:12px;line-height:1.6;opacity:.75;border-bottom:1px solid rgba(127,127,127,.18);">${i18n('内容含写作模式保留不了的排版（行内样式等），已用 HTML 源码模式打开')}</div>`);
            };
            if (layoutReady) {
                openSource();
            } else {
                pendingSourceOpen = openSource;
            }
        }

        // --- CodeMirror mis-measures while hidden (layui tab / collapsed panel): refresh on reveal ---
        let intersectionObserver = null;
        try {
            intersectionObserver = new IntersectionObserver((entries) => {
                entries.forEach((en) => {
                    if (en.isIntersecting) queueRefresh();
                });
            });
            intersectionObserver.observe(cmHost);
        } catch (e) {}

        const destroy = () => {
            if (destroyed) return;
            destroyed = true;
            document.removeEventListener('pointerdown', onOutsidePointer, true);
            clearTimeout(linkState.timer);
            clearTimeout(linkState.okTimer);
            clearTimeout(cspState.okTimer);
            document.removeEventListener('securitypolicyviolation', onCspViolation, true);
            if (linkState.request) {
                try { linkState.request.abort(); } catch (e) {}
            }
            if (paneObserver) {
                paneObserver.disconnect();
                paneObserver = null;
            }
            clearTimeout(rid);
            if (layoutTimer !== null) {
                clearTimeout(layoutTimer);
                layoutTimer = null;
            }
            if (intersectionObserver) {
                intersectionObserver.disconnect();
                intersectionObserver = null;
            }
            uploadRequests.forEach((request) => {
                if (request && request.readyState !== 4) {
                    try { request.abort(); } catch (e) {}
                }
            });
            uploadRequests.clear();
            try { cm.off('change', onMarkdownChange); } catch (e) {}
            try { cm.off('paste', onPaste); } catch (e) {}
            try { cm.off('drop', onDrop); } catch (e) {}
            if (aceEditor) {
                try { aceEditor.destroy(); } catch (e) {}
                aceEditor = null;
            }
            $editor.find('*').addBack().stop(true, true).off();
            const wrapper = cm.getWrapperElement && cm.getWrapperElement();
            if (wrapper && wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
        };

        return {
            cm: cm,
            // Flush the 120ms preview debounce before a form submits. This keeps the
            // hidden canonical HTML in sync even when the user types and immediately clicks.
            getHTML: () => {
                if (destroyed) return $textarea.val();
                clearTimeout(rid);
                if (aceEditor) {
                    $textarea.val(aceEditor.getValue());
                    return $textarea.val();
                }
                // Untouched markdown: submit the canonical HTML byte-for-byte (#952). Editors that
                // forbid raw HTML (tickets) keep always re-rendering, so their output stays sanitized.
                if (mdDirty || !allowRawHtml) render();
                return $textarea.val();
            },
            setHTML: (h) => {
                if (destroyed) return;
                const html = normalizeDefault(h ?? '');
                $textarea.val(html);
                $preview.html(allowRawHtml ? html : sanitizePreview(html));
                mdDirty = false;
                clearTimeout(rid);
                if (aceEditor) aceEditor.setValue(html, -1);
                cm.setValue(html ? html2md(html) : '');
                togglePh();
                queueLinkCheck();
            },
            destroy: destroy
        };
    }

    global.EditorV2 = {buildHtml: buildHtml, register: register};
})(window);
