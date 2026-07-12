/*
 * EditorV2 — reusable self-built Markdown editor (CodeMirror source + marked live
 * preview + preview toggle ⇄ ACE HTML-source mode). Storage stays HTML: a hidden
 * <textarea class="text-container" name="..."> always holds the rendered HTML, so
 * save/buyer-render/DB are unchanged. Used by the form widget (type:"editorv2") and
 * standalone editors (e.g. the 店铺公告 notice on the settings page).
 *
 * Globals used at runtime: jQuery ($), i18n, layer, ace, CodeMirror, marked,
 * TurndownService, localStorage.
 */
(function (global) {
    let ev2Seq = 0;

    function toolbarHtml() {
        const tb = (cmd, icon, title) => `<button type="button" class="ev2-tb" data-cmd="${cmd}" title="${i18n(title)}"><i class="fa-duotone fa-regular ${icon}"></i></button>`;
        return tb('bold', 'fa-bold', '粗体')
            + tb('italic', 'fa-italic', '斜体')
            + tb('heading', 'fa-heading', '标题')
            + tb('ul', 'fa-list-ul', '无序列表')
            + tb('ol', 'fa-list-ol', '有序列表')
            + tb('quote', 'fa-quote-right', '引用')
            + tb('code', 'fa-code', '代码块')
            + tb('link', 'fa-link', '链接')
            + tb('image', 'fa-image', '图片')
            + tb('table', 'fa-table', '表格');
    }

    // Build the editor markup. The hidden textarea is left EMPTY here (register() sets
    // its value via .val(), which is HTML-injection-safe) — never interpolate stored
    // HTML into innerHTML.
    function buildHtml(opt) {
        const name = opt.name;
        const ph = opt.placeholder ?? '';
        const allowHtmlSource = opt.allowHtmlSource !== false;
        return `<div class="ev2-editor" data-mode="md" data-preview="on">`
            + `<div class="ev2-bar"><div class="ev2-tools">${toolbarHtml()}</div>`
            + `<div class="ev2-actions">`
            + `<button type="button" class="ev2-preview-toggle active" title="${i18n('预览开关')}" aria-pressed="true"><i class="fa-duotone fa-regular fa-eye"></i></button>`
            + (allowHtmlSource ? `<button type="button" data-type="0" class="ev2-mode-toggle" title="${i18n('HTML 源码')}"><i class="fa-duotone fa-regular fa-code me-1"></i>HTML</button>` : '')
            + `</div></div>`
            + `<div class="ev2-body"><div class="ev2-cm"><div class="ev2-ph">${ph}</div></div>`
            + `<div class="ev2-preview markdown-body"></div></div>`
            + `<input type="file" class="ev2-image" accept="image/*" style="display:none">`
            + `<textarea class="text-container" style="display:none;" name="${name}"></textarea>`
            + `</div>`;
    }

    // Wire an existing .ev2-editor. rootEl may be the .ev2-editor itself or an ancestor.
    // opt: { name, uploadUrl, height, value (initial HTML), onChange(html),
    //        allowHtmlSource (default true), allowRawHtml (default true) }
    function register(rootEl, opt) {
        opt = opt || {};
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

        // --- converters (store stays HTML: markdown is only the authoring layer) ---
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

        // component.popup registers the form before layui adds its entrance-animation
        // class. Any refresh queued immediately here can therefore run while the whole
        // popup is translated/rotated and make CodeMirror cache transformed character
        // coordinates. Wait until layui has removed its animation class, then measure.
        const popupLayer = $editor.closest('.layui-layer').get(0);
        let layoutReady = !popupLayer;
        let layoutTimer = null;
        const refreshEditor = () => {
            if (cmHost.isConnected) cm.refresh();
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

        // --- live render: markdown -> HTML -> hidden textarea + preview (debounced) ---
        let rid;
        const render = () => {
            const src = cm.getValue();
            const html = src.trim() === '' ? '' : md2html(src);
            $textarea.val(html);
            $preview.html(html);
            togglePh();
            opt.onChange && opt.onChange(html);
        };
        cm.on('change', () => {
            clearTimeout(rid);
            rid = setTimeout(render, 120);
        });

        // --- toolbar ---
        $editor.find('.ev2-tb').on('click', function () {
            const cmd = $(this).data('cmd');
            if (cmd === 'image') {
                $imgInput.trigger('click');
                return;
            }
            applyCmd(cm, cmd);
            cm.focus();
        });

        // --- image upload (reuse the same endpoint + response shape as the old editor) ---
        const uploadImage = (file) => {
            if (!file) return;
            const fd = new FormData();
            fd.append('file', file);
            $.ajax({
                url: uploadUrl + '?mime=image', type: 'POST', data: fd,
                processData: false, contentType: false,
                success: (res) => {
                    if (res.code !== 200) {
                        layer.msg(res.msg);
                        return;
                    }
                    cm.replaceSelection(`![](${res.data.url})`);
                    cm.focus();
                },
                error: () => layer.msg(i18n('图片上传失败，文件可能过大'))
            });
        };
        $imgInput.on('change', function () {
            uploadImage(this.files && this.files[0]);
            this.value = '';
        });
        cm.on('paste', (cmi, e) => {
            const items = e.clipboardData && e.clipboardData.items;
            if (!items) return;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image') === 0) {
                    e.preventDefault();
                    uploadImage(items[i].getAsFile());
                }
            }
        });
        cm.on('drop', (cmi, e) => {
            const files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length && files[0].type && files[0].type.indexOf('image') === 0) {
                e.preventDefault();
                uploadImage(files[0]);
            }
        });

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
                const html = cm.getValue().trim() === '' ? '' : md2html(cm.getValue());
                $textarea.val(html);
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
                    opt.onChange && opt.onChange(h);
                });
            } else {
                $btn.attr('data-type', 0).html('<i class="fa-duotone fa-regular fa-code me-1"></i>HTML');
                const html = $textarea.val();
                cm.setValue(html.trim() === '' ? '' : html2md(html));
                $preview.html(html);
                $('#' + aceId).remove();
                aceEditor = null;
                $editor.attr('data-mode', 'md');
                $body.show();
                $prevToggle.show();
                togglePh();
                queueRefresh();
            }
        });

        // --- CodeMirror mis-measures while hidden (layui tab / collapsed panel): refresh on reveal ---
        try {
            const io = new IntersectionObserver((entries) => {
                entries.forEach((en) => {
                    if (en.isIntersecting) queueRefresh();
                });
            });
            io.observe(cmHost);
        } catch (e) {}

        return {
            cm: cm,
            // Flush the 120ms preview debounce before a form submits. This keeps the
            // hidden canonical HTML in sync even when the user types and immediately clicks.
            getHTML: () => {
                clearTimeout(rid);
                render();
                return $textarea.val();
            },
            setHTML: (h) => { cm.setValue((h && normalizeDefault(h)) ? html2md(h) : ''); }
        };
    }

    global.EditorV2 = {buildHtml: buildHtml, register: register};
})(window);
