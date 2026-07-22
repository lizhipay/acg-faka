const component = new class Component {
    escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    safeNavigationUrl(value) {
        const url = String(value ?? '').trim();
        if (!url || /[\u0000-\u0020\u007f-\u009f\\]/.test(url)) return '';
        try {
            if (/^\/(?!\/)/.test(url)) {
                const parsed = new URL(url, window.location.origin);
                return parsed.origin === window.location.origin
                    ? parsed.pathname + parsed.search + parsed.hash
                    : '';
            }
            if (!/^https?:\/\//i.test(url)) return '';
            const parsed = new URL(url);
            return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '';
        } catch (error) {
            return '';
        }
    }

    safeMessageImageUrl(value) {
        const safeUrl = this.safeNavigationUrl(value);
        if (!safeUrl) return '';
        try {
            const parsed = new URL(safeUrl, window.location.origin);
            if (parsed.origin !== window.location.origin) return '';
            return /^\/assets\/cache\/general\/message\/[a-f0-9]{32}\.(?:jpe?g|png|webp)$/i.test(parsed.pathname)
                ? parsed.pathname
                : '';
        } catch (error) {
            return '';
        }
    }

    sanitizeRichHtml(value) {
        const template = document.createElement('template');
        template.innerHTML = String(value ?? '');
        const allowedTags = new Set([
            'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL', 'BLOCKQUOTE',
            'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR', 'PRE',
            'CODE', 'A', 'IMG', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD'
        ]);
        const dangerousTags = new Set([
            'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM',
            'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'META', 'LINK'
        ]);
        const walk = node => {
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                const tag = child.tagName;
                if (!allowedTags.has(tag)) {
                    if (dangerousTags.has(tag)) {
                        child.remove();
                    } else {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                    }
                    return;
                }
                Array.from(child.attributes).forEach(attribute => {
                    const name = attribute.name.toLowerCase();
                    let keep = false;
                    if (tag === 'A' && ['href', 'title'].includes(name)) keep = true;
                    if (tag === 'IMG' && ['src', 'alt', 'title', 'width', 'height'].includes(name)) keep = true;
                    if (tag === 'CODE' && name === 'class' && /^language-[\w-]+$/.test(attribute.value)) keep = true;
                    if (!keep) child.removeAttribute(attribute.name);
                });
                if (tag === 'A') {
                    const href = this.safeNavigationUrl(child.getAttribute('href'));
                    if (href) child.setAttribute('href', href); else child.removeAttribute('href');
                    child.setAttribute('target', '_blank');
                    child.setAttribute('rel', 'noopener noreferrer nofollow');
                }
                if (tag === 'IMG') {
                    const src = this.safeMessageImageUrl(child.getAttribute('src'));
                    if (src) {
                        child.setAttribute('src', src);
                        child.setAttribute('loading', 'lazy');
                        child.setAttribute('role', 'button');
                        child.setAttribute('tabindex', '0');
                        child.setAttribute('aria-label', child.getAttribute('alt') || '查看大图');
                    } else {
                        child.remove();
                        return;
                    }
                }
                walk(child);
            });
        };
        walk(template.content);
        return template.innerHTML;
    }

    sanitizeInlineHtml(value) {
        const template = document.createElement('template');
        template.innerHTML = String(value ?? '');
        const allowedTags = new Set(['B', 'STRONG', 'BR', 'SPAN', 'A']);
        const dangerousTags = new Set([
            'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'TEMPLATE',
            'NOSCRIPT', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'OPTION',
            'META', 'LINK', 'BASE', 'VIDEO', 'AUDIO', 'CANVAS', 'XMP', 'PLAINTEXT',
            'NOEMBED', 'LISTING', 'TITLE', 'FRAME', 'FRAMESET'
        ]);
        const normalizeColor = value => {
            const probe = document.createElement('span');
            probe.style.color = String(value ?? '').trim();
            return probe.style.color;
        };
        const normalizeLink = value => {
            const source = String(value ?? '').trim();
            if (!/^https?:\/\//i.test(source) || /[\u0000-\u0020\u007f-\u009f\\]/.test(source)) return '';
            try {
                const url = new URL(source);
                return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : '';
            } catch (error) {
                return '';
            }
        };
        const walk = node => {
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                const tag = String(child.tagName || '').toUpperCase();
                if (!allowedTags.has(tag)) {
                    if (dangerousTags.has(tag)) {
                        child.remove();
                    } else {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                    }
                    return;
                }

                const color = tag === 'BR' ? '' : normalizeColor(child.style.color);
                const href = tag === 'A' ? normalizeLink(child.getAttribute('href')) : '';
                Array.from(child.attributes).forEach(attribute => child.removeAttribute(attribute.name));
                if (color) child.style.color = color;
                if (tag === 'A') {
                    if (!href) {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                        return;
                    }
                    child.setAttribute('href', href);
                    child.setAttribute('target', '_blank');
                    child.setAttribute('rel', 'noopener noreferrer nofollow');
                }
                walk(child);
            });
        };
        walk(template.content);
        return template.innerHTML;
    }

    plainInlineText(value) {
        const template = document.createElement('template');
        template.innerHTML = this.sanitizeInlineHtml(value);
        return (template.content.textContent || '').trim();
    }

    previewMessage(messageData = {}) {
        const title = this.escapeHtml(messageData.title || '消息通知');
        const content = this.sanitizeRichHtml(messageData.content || '');
        const createTime = this.escapeHtml(messageData.create_time || messageData.update_time || '');
        const jumpUrl = this.safeNavigationUrl(messageData.jump_url || messageData.url || '');
        const mobile = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const previousFocus = document.activeElement;
        let eventNamespace = '';
        const contentHtml = `<article class="md-message-preview" role="document">`
            + `<header class="md-message-preview__header">`
            + `<span class="md-message-preview__icon" aria-hidden="true"><span class="material-icons-outlined">notifications</span></span>`
            + `<div class="md-message-preview__heading"><span>MESSAGE</span><h2>${title}</h2>${createTime ? `<time>${createTime}</time>` : ''}</div>`
            + `<button type="button" class="md-message-preview__close" aria-label="关闭消息"><span class="material-icons-outlined">close</span></button>`
            + `</header><div class="md-message-preview__content markdown-body">${content}</div></article>`;

        return layer.open({
            type: 1,
            title: false,
            closeBtn: 0,
            maxmin: false,
            resize: false,
            shade: 0.42,
            shadeClose: true,
            anim: reduceMotion ? -1 : 4,
            isOutAnim: !reduceMotion,
            area: mobile ? ['100%', '100%'] : '600px',
            skin: 'md-message-layer',
            content: contentHtml,
            btn: jumpUrl ? [`${util.icon('fa-duotone fa-regular fa-arrow-up-right-from-square')} 前往地址`] : false,
            yes: index => {
                const parsed = new URL(jumpUrl, window.location.origin);
                layer.close(index);
                if (parsed.origin === window.location.origin) {
                    window.location.href = parsed.href;
                } else {
                    window.open(parsed.href, '_blank', 'noopener,noreferrer');
                }
            },
            success: (layero, index) => {
                const $layer = $(layero);
                const headingId = `md-message-preview-title-${index}`;
                eventNamespace = `.mdMessagePreview${index}`;
                $layer.attr({role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': headingId});
                $layer.find('.md-message-preview__heading h2').attr('id', headingId);
                $layer.find('.md-message-preview__close').on('click', () => layer.close(index));
                $layer.find('.md-message-preview__content img').on('click keydown', event => {
                    if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
                    event.preventDefault();
                    const src = this.safeMessageImageUrl(event.currentTarget.getAttribute('src'));
                    if (src) this.previewImage(src);
                });
                if (jumpUrl) {
                    const parsed = new URL(jumpUrl, window.location.origin);
                    $layer.find('.layui-layer-btn0')
                        .attr({
                            href: jumpUrl,
                            target: parsed.origin === window.location.origin ? '_self' : '_blank',
                            rel: 'noopener noreferrer'
                        })
                        .on('click.mdMessageLink', event => event.preventDefault());
                }
                $(document).on(`keydown${eventNamespace}`, event => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        layer.close(index);
                        return;
                    }
                    if (event.key !== 'Tab') return;
                    const focusable = $layer
                        .find('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])')
                        .filter(':visible')
                        .toArray();
                    if (!focusable.length) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                });
                $layer.find('.md-message-preview__close').trigger('focus');
            },
            end: () => {
                if (eventNamespace) $(document).off(eventNamespace);
                if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) {
                    previousFocus.focus();
                }
                if (typeof messageData.onClose === 'function') messageData.onClose();
            }
        });
    }

    previewImage(imageUrl) {
        if (!imageUrl) return;
        const isMobile = Boolean(window.AdminMobile && typeof window.AdminMobile.isEnabled === 'function' && window.AdminMobile.isEnabled());
        const previousFocus = document.activeElement;
        const safeUrl = String(imageUrl)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        layer.open({
            type: 1,
            title: false,
            closeBtn: isMobile ? 1 : 0,
            anim: isMobile ? 0 : 5,
            area: isMobile ? ['100vw', '100dvh'] : 'auto',
            skin: isMobile ? 'md-image-preview-layer' : '',
            shadeClose: true,
            content: `<div class="md-image-preview"><img src="${safeUrl}" alt="图片预览"></div>`,
            success: ($layer) => {
                if (!isMobile) return;
                $layer.attr({role: 'dialog', 'aria-modal': 'true', 'aria-label': '图片预览'});
                $layer.find('.layui-layer-close').attr('aria-label', '关闭图片预览');
            },
            end: () => {
                if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) {
                    previousFocus.focus();
                }
            }
        });
    }

    isRowDetailOpen($table, index) {
        let $tr = $table.find('tr[data-index="' + index + '"]');
        let $detailView = $tr.next('.detail-view');
        return $detailView.length > 0;
    }

    /**
     *
     * @param url
     * @param value
     * @param field
     * @param id
     */
    updateDatabase(url, value, field, id, table = null, reload = false) {
        let data = {};
        data[field] = value;
        data["id"] = id;
        util.post(url, data, res => {
            message.success("已更新 (｡•ᴗ-)");
            table && reload && table.bootstrapTable('refresh', {silent: true});
        });
    }

    deleteDatabase(url, list, done = null) {
        message.ask("一旦数据被遗弃，您将无法恢复它！", () => {
            util.post(url, {list: list}, res => {
                message.alert('您选择的数据已被系统永久删除。', 'success');
                done && done(res);
            });
        });
    }

    /**
     *
     * @param opt
     */
    popup(opt = {}) {
        const submitTab = getVar("HACK_SUBMIT_TAB"), submitForm = getVar("HACK_SUBMIT_FORM");
        const hookSubmit = opt.submitRoute ?? opt.submit;

        if (submitTab instanceof Array) {
            submitTab.forEach(tmp => {
                if (tmp.submit == hookSubmit) {
                    opt?.tab?.push(evalResults(tmp.code));
                }
            });
        }

        if (submitForm instanceof Array) {
            submitForm.forEach(tmp => {
                if (tmp.submit == hookSubmit) {
                    for (let i = 0; i < opt?.tab?.length; i++) {
                        const forms = opt?.tab[i]?.form;
                        for (let j = 0; j < forms?.length; j++) {
                            const form = forms[j];
                            if (form.name == tmp.field) {
                                if (tmp.direction === "after") {
                                    opt?.tab[i]?.form?.splice(j + 1, 0, evalResults(tmp.code));
                                } else {
                                    opt?.tab[i]?.form?.splice(j, 0, evalResults(tmp.code));
                                    j++;
                                }
                            }
                        }
                    }
                }
            });
        }

        let form = new Form(opt);
        let tab = form.getTab();
        const closePopup = index => {
            index !== undefined && index !== null && layer.close(index);
        };
        const submitPopup = (index = null, close = closePopup) => {
            let data = form.getData();
            if (!form.validator()) {
                return false;
            }

            if (typeof opt.submit == "function") {
                opt.submit(data, index);
                return true;
            }
            if (opt.submit) {
                util.post(opt.submit, data, res => {
                    close(index);
                    if (opt.message !== false) {
                        if (!res.msg || res.msg == "success") {
                            message.alert(opt.message ?? '您提交的数据已被系统存储(｡•ᴗ-)_', 'success');
                        } else {
                            message.alert(res.msg, 'success');
                        }
                    }
                    opt.done && opt.done(res, data);
                }, error => {
                    opt.error && opt.error(error);
                    message.alert(error.msg, 'error');
                });
            }
            return true;
        };
        const registerPopup = index => {
            form.setIndex(index);
            form.registerEvent();
            typeof opt.renderComplete == "function" && opt.renderComplete(form.getUnique(), index);
        };

        // A mobile presenter may replace only the visual container. It receives
        // the final hook-processed Form and the exact desktop submit path.
        const popupContext = {
            options: opt,
            form: form,
            tab: tab,
            tabs: tab,
            submit: submitPopup,
            close: closePopup,
            register: registerPopup
        };
        try {
            const presenter = window.AdminMobile?.presentPopup;
            const presented = typeof presenter === 'function'
                ? presenter.call(window.AdminMobile, popupContext)
                : false;
            if (presented === true || presented?.handled === true) {
                return presented;
            }
        } catch (error) {
            util.debug('AdminMobile popup presenter fallback: ' + error.message, '#ff4f33');
        }

        // Admin mobile is viewport-driven rather than UA-driven. If its
        // presenter is temporarily unavailable (for example during script or
        // PJAX lifecycle boundaries), keep the legacy Layer path mobile-safe
        // instead of falling back to a desktop width on Chrome's desktop UA.
        const useAdminMobileFallback = window.AdminMobile?.isEnabled?.() === true;
        let legacyPopupIndex = null;
        let legacyResizeObserver = null;
        let legacyPopupDestroyed = false;
        let legacyEndCalled = false;
        const legacyLifecycleEvent = 'pjax:beforeReplace.componentPopup' + form.getUnique();
        const restoreDrawerScroll = () => {
            if (!isDrawer) {
                return;
            }
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        };
        const destroyLegacyPopup = () => {
            if (legacyResizeObserver) {
                try {
                    legacyResizeObserver.disconnect();
                } catch (error) {
                    util.debug('Component popup ResizeObserver destroy skipped: ' + form.getUnique(), '#ff4f33');
                }
                legacyResizeObserver = null;
            }
            $(document).off(legacyLifecycleEvent);
            restoreDrawerScroll();
            if (legacyPopupDestroyed) {
                return;
            }
            legacyPopupDestroyed = true;
            if (typeof form.destroy === 'function') {
                try {
                    form.destroy();
                } catch (error) {
                    util.debug('Component popup Form destroy skipped: ' + form.getUnique(), '#ff4f33');
                }
            }
        };
        const closeLegacyPopupForPage = () => {
            const index = legacyPopupIndex ?? (typeof form.getIndex === 'function' ? form.getIndex() : null);
            if (index !== undefined && index !== null) {
                try {
                    layer.close(index);
                } catch (error) {
                    util.debug('Component popup close during PJAX skipped: ' + form.getUnique(), '#ff4f33');
                }
            }
            // Layer may defer its end callback until the exit animation ends,
            // while PJAX replaces the owning DOM immediately.
            destroyLegacyPopup();
        };

        let area = '680px';

        if (opt.width && opt.height) {
            area = [opt.width, opt.height];
        } else if (opt.width) {
            area = opt.width;
        }

        // Right-side drawer variant (opt.drawer:true): a full-height panel flush to the
        // right edge. Same form logic / tabs / submit as the modal — only presentation differs.
        const isDrawer = opt.drawer === true && util.isPc() && !useAdminMobileFallback;
        if (isDrawer) {
            const drawerWidth = (opt.width && opt.width !== 'auto') ? opt.width : '620px';
            area = [drawerWidth, '100%'];
        }

        if (useAdminMobileFallback || !util.isPc()) {
            area = ["100%", "100%"];
        }

        //弹窗参数
        let openOption = {
            shade: opt.shade ?? 0.3,
            btn: opt.submit ? [(opt.confirmText ? i18n(opt.confirmText) : null) ?? util.icon("fa-duotone fa-regular fa-floppy-disk me-1 text-success") + i18n("保存"), util.icon('fa-duotone fa-regular fa-xmark me-1 text-warning') + i18n('取消')] : false,
            area: area,
            maxmin: useAdminMobileFallback ? false : (opt.maxmin ?? true),
            closeBtn: opt.closeBtn ?? 1,
            shadeClose: opt.shadeClose ?? false,
            anim: useAdminMobileFallback ? 2 : 4,
            yes: (index, lay) => {
                submitPopup(index);
            },
            success: (lay, layIndex, that) => {
                let contentElem = $(lay).find('.layui-layer-content');

                legacyPopupIndex = layIndex;
                form.setIndex(layIndex);
                form.registerEvent();
                // Drawer: lock background scroll (also removes the page scrollbar so the
                // drawer sits flush to the viewport edge); pad the body to avoid a reflow shift.
                if (isDrawer) {
                    const sw = window.innerWidth - document.documentElement.clientWidth;
                    document.body.style.overflow = 'hidden';
                    if (sw > 0) document.body.style.paddingRight = sw + 'px';
                }
                if (!useAdminMobileFallback) {
                    $('.component-popup.' + form.getUnique()).append('<img src="/assets/common/images/ks.webp" class="component-popup-acg">');
                }


                if (opt.content && util.isPc() && !useAdminMobileFallback) {
                    if (opt.content.css) {
                        for (const cssKey in opt.content.css) {
                            contentElem.css(cssKey, opt.content.css[cssKey]);
                        }
                    }
                }

                if (opt.autoPosition && util.isPc() && !isDrawer && !useAdminMobileFallback) {
                    legacyResizeObserver = this.resizeObserver($(lay).find(".layui-layer-content"), event => {
                        const content = $(lay).find(".layui-layer-content");

                        if (opt.adaptiveHeight === true) {
                            content.css({
                                height: "auto",
                                maxHeight: "calc(100vh - 155px)",
                                overflowY: "auto"
                            });

                            layer.iframeAuto(layIndex);
                            that.offset();
                            return;
                        }

                        const heightValue = util.getDomHeight(content),
                            overflowValue = content.css("overflow");

                        if (/^\d+px$/.test(heightValue) && overflowValue == "auto") {
                            content.css("height", "auto");
                        }

                        let height = content.height() + 60 + 56;
                        if (height > $(window).height()) {
                            const autoHeight = $(window).height() - 155;
                            content.css("height", `${autoHeight}px`).css("overflow", "");
                        }

                        layer.iframeAuto(layIndex);
                        that.offset();
                    });
                }

                typeof opt.renderComplete == "function" && opt.renderComplete(form.getUnique(), layIndex);
            },
            end: () => {
                if (legacyEndCalled) {
                    return;
                }
                legacyEndCalled = true;
                destroyLegacyPopup();
                opt.end && opt.end();
            },
            full: (layero, index, that) => {
                let $handle = layero.addClass("border-none");
                $handle.find(".layui-layer-title").addClass("border-none");
                $handle.find(".layui-layer-btn").addClass("border-none");
            },
            restore: (layero, index, that) => {
                let $handle = layero.removeClass("border-none");
                $handle.find(".layui-layer-title").removeClass("border-none");
                $handle.find(".layui-layer-btn").removeClass("border-none");
            }
        };

        if (useAdminMobileFallback) {
            openOption.resize = false;
            openOption.move = false;
        }

        if (isDrawer) {
            openOption.offset = 'r';      // flush to the right edge, full height
            openOption.anim = -1;         // disable layer's scale anim; CSS slides it in
            openOption.isOutAnim = true;
            openOption.maxmin = false;    // no maximize for a drawer
            openOption.move = false;      // fixed position (no drag)
        }
        const drawerSkin = isDrawer ? ' component-drawer' : '';
        const mobileFallbackSkin = useAdminMobileFallback ? ' admin-mobile-layer-popup admin-mobile-layer-popup--task' : '';
        const mobileFallbackContent = content => useAdminMobileFallback
            ? '<div class="admin-mobile-popup-form">' + content + '</div>'
            : content;

        try {
            if (tab.length === 1) {
                //单选卡
                openOption.type = 1;
                openOption.content = mobileFallbackContent(tab[0].content);
                openOption.title = tab[0].title;
                openOption.skin = 'component-popup ' + form.getUnique() + drawerSkin + mobileFallbackSkin;
                legacyPopupIndex = layer.open(openOption);
            } else {
                //多选卡
                openOption.tab = useAdminMobileFallback
                    ? tab.map(item => Object.assign({}, item, {content: mobileFallbackContent(item.content)}))
                    : tab;
                openOption.skin = 'layui-layer-tab component-popup ' + form.getUnique() + drawerSkin + mobileFallbackSkin;
                legacyPopupIndex = layer.tab(openOption);
            }
            if (!legacyPopupDestroyed) {
                $(document).off(legacyLifecycleEvent).one(legacyLifecycleEvent, closeLegacyPopupForPage);
            }
        } catch (error) {
            const failedIndex = legacyPopupIndex ?? (typeof form.getIndex === 'function' ? form.getIndex() : null);
            if (failedIndex !== undefined && failedIndex !== null) {
                try {
                    layer.close(failedIndex);
                } catch (closeError) {
                    util.debug('Component popup close after failure skipped: ' + form.getUnique(), '#ff4f33');
                }
            }
            destroyLegacyPopup();
            throw error;
        }
    }


    idObjToList(array = []) {
        let list = [];
        array.forEach(item => {
            list.push(item.id);
        });
        return list;
    }


    async loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.body.appendChild(script);
        });
    }

    async run(scripts = []) {
        for (const src of scripts) {
            await this.loadScript(src);
        }
    }


    resizeObserver(element, done) {
        if (!('ResizeObserver' in window)) {
            return null;
        }
        const target = element?.jquery ? element.get(0) : element;
        if (!target) {
            return null;
        }
        const resizeObserver = new ResizeObserver(function (entries) {
            for (let entry of entries) {
                done && done(entry);
            }
        });
        resizeObserver.observe(target);
        return resizeObserver;
    }

}
