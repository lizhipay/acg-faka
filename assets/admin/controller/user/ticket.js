!function () {
    const $tableElement = $('#ticket-table');
    const $drawer = $('.md-ticket-drawer').first();
    if (!$tableElement.length || !$drawer.length) return;

    const esc = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const numberText = value => Number(value || 0).toLocaleString('zh-CN');
    const drafts = new Map();
    const seenMessages = new Set();
    const $panel = $drawer.find('.md-ticket-drawer__panel');
    const $messages = $drawer.find('#ticket-message-list');
    const $sync = $drawer.find('.md-ticket-sync-state');
    const $replyButton = $drawer.find('.ticket-reply-action');
    const $resolveButton = $drawer.find('.ticket-resolve-action');
    const $closeButton = $drawer.find('.ticket-close-action');
    const $historyButton = $drawer.find('.md-ticket-history-more');
    const $editorHost = $drawer.find('#ticket-reply-editor');

    let table = null;
    let ticket = null;
    let ticketId = 0;
    let editorApi = null;
    let lastMessageId = 0;
    let minMessageId = 0;
    let polling = false;
    let historyLoading = false;
    let busy = false;
    let pollTimer = null;
    let closeTimer = null;
    let listRefreshTimer = null;
    let composerViewportFrame = 0;
    let refocusComposerAfterExpand = false;
    let session = 0;
    let previousFocus = null;
    let pageDestroyed = false;

    const isTerminal = status => Number(status) >= 2;
    const isOpen = () => $drawer.hasClass('is-open') && !$drawer.prop('hidden');
    const isCurrent = (token, id) => !pageDestroyed && token === session && id === ticketId && isOpen();
    const statusText = status => ({0: '待客服', 1: '等待用户', 2: '已解决', 3: '已关闭'})[Number(status)] || '处理中';

    const setMobileMenu = open => {
        const active = !!open && isOpen();
        $drawer.toggleClass('is-mobile-menu-open', active);
        $drawer.find('.md-ticket-mobile-menu').attr('aria-hidden', active ? 'false' : 'true');
        $drawer.find('.md-ticket-mobile-more').attr('aria-expanded', active ? 'true' : 'false');
    };

    const setContextSheet = open => {
        const active = !!open && isOpen();
        $drawer.toggleClass('is-context-open', active);
        $drawer.find('.md-ticket-context').attr('aria-hidden', active ? 'false' : 'true');
    };

    const setComposerExpanded = open => {
        const $composer = $drawer.find('.md-ticket-composer');
        const active = !!open && !$composer.prop('hidden');
        $composer.toggleClass('is-expanded', active);
        $composer.find('.md-ticket-composer-tools')
            .attr('aria-expanded', active ? 'true' : 'false')
            .attr('aria-label', active ? '收起回复工具' : '展开回复工具');
        requestAnimationFrame(() => {
            $panel.scrollTop(0);
            editorApi?.cm?.refresh();
        });
        setTimeout(() => $panel.scrollTop(0), 80);
    };

    const syncComposerViewport = () => {
        if (composerViewportFrame) return;
        composerViewportFrame = requestAnimationFrame(() => {
            composerViewportFrame = 0;
            if (!isOpen() || !editorApi?.cm) return;
            if (window.matchMedia && !window.matchMedia('(max-width: 991.98px)').matches) return;
            const input = typeof editorApi.cm.getInputField === 'function'
                ? editorApi.cm.getInputField()
                : null;
            if (!input || document.activeElement !== input) return;
            editorApi.cm.refresh();
            if (typeof editorApi.cm.scrollIntoView === 'function') {
                editorApi.cm.scrollIntoView(editorApi.cm.getCursor(), 14);
            }
            const messages = $messages.get(0);
            if (messages) messages.scrollTop = messages.scrollHeight;
        });
    };

    const resetMobileLayers = () => {
        setMobileMenu(false);
        setContextSheet(false);
        setComposerExpanded(false);
    };

    const safeImageUrl = value => {
        const url = String(value || '').trim();
        try {
            const normalized = url.replace(/[\u0000-\u0020\u007f-\u009f]/g, '');
            const parsed = new URL(normalized, window.location.origin);
            return ['http:', 'https:'].includes(parsed.protocol) ? url : '';
        } catch (error) {
            return '';
        }
    };

    const sanitizeRichHtml = value => {
        const template = document.createElement('template');
        template.innerHTML = String(value || '');
        const allowedTags = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR', 'PRE', 'CODE', 'A', 'IMG', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD']);
        const dangerousTags = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'META', 'LINK']);
        const walk = node => {
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                const tag = child.tagName;
                if (!allowedTags.has(tag)) {
                    if (dangerousTags.has(tag)) child.remove();
                    else {
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
                    const href = safeImageUrl(child.getAttribute('href'));
                    if (href) child.setAttribute('href', href); else child.removeAttribute('href');
                    child.setAttribute('target', '_blank');
                    child.setAttribute('rel', 'noopener noreferrer nofollow');
                }
                if (tag === 'IMG') {
                    const src = safeImageUrl(child.getAttribute('src'));
                    if (src) child.setAttribute('src', src); else child.remove();
                }
                walk(child);
            });
        };
        walk(template.content);
        return template.innerHTML;
    };

    const contentIsEmpty = html => {
        const holder = document.createElement('div');
        holder.innerHTML = html || '';
        return holder.textContent.trim() === '' && !holder.querySelector('img,video,audio,table');
    };

    const safeUser = user => {
        if (!user) return null;
        return {
            id: esc(user.id),
            username: esc(user.username),
            avatar: safeImageUrl(user.avatar) ? esc(user.avatar) : ''
        };
    };

    const updateStats = stats => {
        stats = stats || {};
        $('.ticket-stat-pending').text(numberText(stats.pending_admin));
        $('.ticket-stat-user').text(numberText(stats.pending_user));
        $('.ticket-stat-resolved').text(numberText(stats.resolved));
        $('.ticket-stat-closed').text(numberText(stats.closed));
        $('.ticket-stat-today').text(numberText(stats.today));

        const pending = Number(stats.pending_admin || 0);
        $('.ticket-admin-badge').text(pending > 99 ? '99+' : pending).prop('hidden', pending < 1);
    };

    const refreshList = (delay = 0) => {
        clearTimeout(listRefreshTimer);
        listRefreshTimer = setTimeout(() => {
            if (!pageDestroyed && table) table.refresh(true);
        }, delay);
    };

    const ticketCell = row => {
        const unread = Number(row.manage_unread || 0) > 0;
        const id = Number(row.id);
        const excerpt = row.last_message_excerpt
            ? `<span class="md-ticket-cell__excerpt">${esc(row.last_message_excerpt)}</span>`
            : '<span class="md-ticket-cell__excerpt">尚无沟通摘要</span>';
        return `<div class="md-ticket-cell${unread ? ' md-ticket-cell--unread' : ''}" role="button" tabindex="0" data-ticket-id="${Number.isInteger(id) && id > 0 ? id : ''}" aria-label="查看工单 ${esc(row.ticket_no || '')}">`
            + `<span class="md-ticket-cell__top"><span class="md-ticket-unread-dot" aria-hidden="true"></span><span class="md-ticket-cell__number">${esc(row.ticket_no)}</span></span>`
            + `<strong class="md-ticket-cell__title">${esc(row.title || '未命名工单')}</strong>${excerpt}</div>`;
    };

    const typePriorityCell = row => `<div class="md-ticket-badge-stack">${_Dict.result('_ticket_type', row.type) || '-'}${_Dict.result('_ticket_priority', row.priority) || ''}</div>`;

    const relationCell = row => {
        if (Number(row.type) === 0) {
            if (!row.commodity_name) return '<span class="md-ticket-muted">未关联商品</span>';
            return `<div class="md-ticket-relation"><span class="md-ticket-relation__icon"><i class="fa-duotone fa-regular fa-box"></i></span><div><strong>${esc(row.commodity_name)}</strong><span>咨询商品</span></div></div>`;
        }
        if (!row.order_trade_no) return '<span class="md-ticket-muted">未关联订单</span>';
        const source = row.order_source === 2 || row.order_source === 'guest' ? '游客订单' : '账号订单';
        return `<div class="md-ticket-relation"><span class="md-ticket-relation__icon md-ticket-relation__icon--order"><i class="fa-duotone fa-regular fa-receipt"></i></span><div><strong>${esc(row.order_trade_no)}</strong><span>${source}</span></div></div>`;
    };

    const activityCell = row => {
        const actor = Number(row.last_sender_type) === 0 ? '用户' : (Number(row.last_sender_type) === 1 ? '管理员' : '系统');
        const excerpt = row.last_message_excerpt ? esc(row.last_message_excerpt) : '暂无新动态';
        return `<div class="md-ticket-activity"><span><b>${actor}</b> · ${excerpt}</span><time>${esc(row.last_message_time || row.update_time || row.create_time || '-')}</time></div>`;
    };

    const setSyncState = (text, loading = false) => {
        $sync.toggleClass('is-loading', loading).html(
            `<i class="fa-duotone fa-regular ${loading ? 'fa-spinner icon-spin' : 'fa-circle-check'}"></i> ${esc(text)}`
        );
    };

    const stopPolling = () => {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = null;
        polling = false;
    };

    const setBusy = value => {
        busy = value;
        const terminal = ticket ? isTerminal(ticket.status) : true;
        $replyButton.prop('disabled', value || terminal);
        $resolveButton.prop('disabled', value || terminal);
        $closeButton.prop('disabled', value || terminal);
        $drawer.toggleClass('md-ticket-is-busy', value).attr('aria-busy', value ? 'true' : 'false');
    };

    const applyStatus = status => {
        if (!ticket) return;
        ticket.status = Number(status);
        $drawer.find('.md-ticket-detail-status').html(_Dict.result('_ticket_status', ticket.status) || '');
        $drawer.find('.md-ticket-mobile-status').text(statusText(ticket.status));

        const terminal = isTerminal(ticket.status);
        $drawer.find('.md-ticket-composer').prop('hidden', terminal);
        $drawer.find('.md-ticket-terminal').prop('hidden', !terminal);
        $drawer.find('.md-ticket-live-dot').toggleClass('is-terminal', terminal);
        $drawer.find('.md-ticket-terminal strong').text(ticket.status === 2 ? '这张工单已经解决' : '这张工单已经关闭');
        $drawer.find('.md-ticket-terminal span').text(ticket.status === 2
            ? '管理员已给出最终答复，历史沟通仍会完整保留。'
            : '工单已被管理员直接关闭，历史沟通仍会完整保留。');
        if (terminal) {
            stopPolling();
            drafts.delete(ticketId);
            if (editorApi) editorApi.setHTML('');
            setSyncState('记录已归档');
        }
        setBusy(busy);
    };

    const messageAvatar = messageItem => {
        if (Number(messageItem.sender_type) === 0) {
            const user = ticket?.user || {};
            const image = safeImageUrl(user.avatar);
            if (image) return `<img src="${esc(image)}" alt="">`;
            return esc(String(user.username || '用').charAt(0).toUpperCase());
        }
        return '<i class="fa-duotone fa-regular fa-user-shield"></i>';
    };

    const messageNode = messageItem => {
        const senderType = Number(messageItem.sender_type);
        const kind = Number(messageItem.kind || 0);
        if (senderType === 2 || kind === 2) {
            return $(`<div class="md-ticket-event" data-message-id="${Number(messageItem.id) || 0}"><span><i class="fa-duotone fa-regular fa-lock-keyhole"></i>${esc(messageItem.content || '工单状态已更新')}</span><time>${esc(messageItem.create_time || '')}</time></div>`);
        }

        const admin = senderType === 1;
        const header = admin
            ? `<header><time>${esc(messageItem.create_time || '')}</time></header>`
            : `<header><div><strong>${esc(messageItem.sender_name || ticket?.user?.username || '')}</strong></div><time>${esc(messageItem.create_time || '')}</time></header>`;
        const html = `<article class="md-ticket-message ${admin ? 'md-ticket-message--admin' : 'md-ticket-message--user'}" data-message-id="${Number(messageItem.id) || 0}">`
            + `<div class="md-ticket-message__avatar">${messageAvatar(messageItem)}</div>`
            + `<div class="md-ticket-message__main">${header}`
            + `<div class="md-ticket-message__bubble markdown-body">${sanitizeRichHtml(messageItem.content)}</div></div></article>`;
        const $node = $(html);
        $node.find('a').attr({target: '_blank', rel: 'noopener noreferrer'});
        $node.find('img').attr({loading: 'lazy', role: 'button', tabindex: '0', 'aria-label': '预览工单图片'});
        return $node;
    };

    const scrollToLatest = smooth => {
        const element = $messages.get(0);
        if (!element) return;
        element.scrollTo({top: element.scrollHeight, behavior: smooth ? 'smooth' : 'auto'});
    };

    const appendMessages = (list, initial = false) => {
        const element = $messages.get(0);
        const wasNearBottom = !element || (element.scrollHeight - element.scrollTop - element.clientHeight < 120);
        if (initial) {
            seenMessages.clear();
            $messages.empty();
        }

        let added = 0;
        (Array.isArray(list) ? list : []).forEach(item => {
            const id = Number(item.id || 0);
            if (id && seenMessages.has(id)) return;
            if (id) {
                seenMessages.add(id);
                lastMessageId = Math.max(lastMessageId, id);
                minMessageId = minMessageId === 0 ? id : Math.min(minMessageId, id);
            }
            $messages.append(messageNode(item));
            added++;
        });

        if (initial && added === 0) {
            $messages.html('<div class="md-ticket-empty"><i class="fa-duotone fa-regular fa-comment"></i><strong>还没有沟通记录</strong><span>用户提交的内容会显示在这里</span></div>');
        } else if (added > 0) {
            $messages.find('.md-ticket-empty').remove();
        }

        if (initial || (added > 0 && wasNearBottom)) {
            requestAnimationFrame(() => scrollToLatest(!initial));
        }
        return added;
    };

    const prependMessages = list => {
        const element = $messages.get(0);
        if (!element) return 0;
        const oldHeight = element.scrollHeight;
        const oldTop = element.scrollTop;
        let added = 0;
        const boundary = minMessageId;
        const items = (Array.isArray(list) ? list : []).filter(item => {
            const id = Number(item.id || 0);
            if (!id || seenMessages.has(id) || (boundary > 0 && id >= boundary)) return false;
            seenMessages.add(id);
            return true;
        });
        if (items.length) minMessageId = Math.min(...items.map(item => Number(item.id)));
        for (let index = items.length - 1; index >= 0; index--) {
            $messages.prepend(messageNode(items[index]));
            added++;
        }
        if (added > 0) element.scrollTop = oldTop + (element.scrollHeight - oldHeight);
        return added;
    };

    const renderUserContext = user => {
        user = user || {};
        const avatar = safeImageUrl(user.avatar);
        const visual = avatar
            ? `<img src="${esc(avatar)}" alt="">`
            : `<span>${esc(String(user.username || '?').charAt(0).toUpperCase())}</span>`;
        $drawer.find('.ticket-user-context').html(`<div class="md-ticket-person"><div class="md-ticket-person__avatar">${visual}</div><div><strong>${esc(user.username || '未知用户')}</strong><span>会员 ID · ${esc(user.id || '-')}</span></div></div>`);
    };

    const contextRow = (label, value, strong = false) => `<div class="md-ticket-context-row"><span>${esc(label)}</span><${strong ? 'strong' : 'em'}>${esc(value || '-')}</${strong ? 'strong' : 'em'}></div>`;

    const renderRelationContext = current => {
        if (Number(current.type) === 0) {
            const commodity = current.commodity || {};
            const cover = safeImageUrl(commodity.cover);
            const visual = cover ? `<img src="${esc(cover)}" alt="">` : '<i class="fa-duotone fa-regular fa-box"></i>';
            $drawer.find('.ticket-relation-context').html(`<div class="md-ticket-product"><div class="md-ticket-product__cover">${visual}</div><div><span>咨询商品</span><strong>${esc(commodity.name || current.commodity_name || '未关联商品')}</strong><em>商品 ID · ${esc(commodity.id || '-')}</em></div></div>`);
            return;
        }

        const order = current.order || {};
        const guest = Number(current.order_source) === 2 || current.order_source === 'guest';
        $drawer.find('.ticket-relation-context').html(
            `<div class="md-ticket-order-head"><span class="md-ticket-relation__icon md-ticket-relation__icon--order"><i class="fa-duotone fa-regular fa-receipt"></i></span><div><span>${guest ? '游客订单' : '账号订单'}</span><strong>${esc(order.trade_no || current.order_trade_no || '未关联订单')}</strong></div></div>`
            + `<div class="md-ticket-context-rows">${contextRow('订单金额', order.amount !== undefined ? `¥${order.amount}` : '-')}${contextRow('购买数量', order.card_num)}${contextRow('下单时间', order.create_time)}${contextRow('支付时间', order.pay_time)}</div>`
        );
    };

    const renderProof = current => {
        const proof = safeImageUrl(current.proof_path || current.proof_url || current.proof?.url);
        const $card = $drawer.find('.ticket-proof-card');
        if (Number(current.type) !== 1 || !proof) {
            $card.prop('hidden', true);
            return;
        }
        $card.prop('hidden', false);
        $drawer.find('.ticket-proof-context').html(`<button type="button" class="md-ticket-proof" data-proof="${esc(proof)}"><img src="${esc(proof)}" alt="购买凭证"><span><i class="fa-duotone fa-regular fa-eye"></i>点击查看原图</span></button>`);
    };

    const renderTicket = current => {
        ticket = current;
        const user = current.user || {};
        const memberName = String(user.username || '未知用户').trim() || '未知用户';
        const memberId = user.id == null ? '' : String(user.id).trim();
        $drawer.find('.md-ticket-number').text(current.ticket_no || `工单 #${current.id}`);
        $drawer.find('.md-ticket-hero__title').text(current.title || '未命名工单');
        $drawer.find('.md-ticket-mobile-title').text(current.title || '未命名工单');
        $drawer.find('.md-ticket-mobile-member').text(memberName + (memberId ? `#${memberId}` : ''));
        $drawer.find('.md-ticket-detail-type').html(_Dict.result('_ticket_type', Number(current.type)) || '');
        $drawer.find('.md-ticket-detail-priority').html(_Dict.result('_ticket_priority', Number(current.priority)) || '');
        $drawer.find('.md-ticket-detail-time').html(`<i class="fa-duotone fa-regular fa-clock"></i> 创建于 ${esc(current.create_time || '-')}`);
        renderUserContext(current.user);
        renderRelationContext(current);
        renderProof(current);
        $drawer.find('.ticket-time-context').html(`<div class="md-ticket-context-rows">${contextRow('创建时间', current.create_time, true)}${contextRow('最后更新', current.update_time || current.last_message_time, true)}${contextRow('工单编号', current.ticket_no, true)}</div>`);
        applyStatus(current.status);
    };

    const clearEditorState = () => {
        try {
            editorApi?.cm?.getInputField()?.blur();
            editorApi?.setHTML('');
        } catch (error) {}
    };

    const destroyEditor = () => {
        clearEditorState();
        try { editorApi?.destroy?.(); } catch (error) {}
        editorApi = null;
        $editorHost.empty();
    };

    const saveDraft = () => {
        if (!ticketId || !editorApi || (ticket && isTerminal(ticket.status))) return;
        try {
            const content = editorApi.getHTML();
            if (contentIsEmpty(content)) drafts.delete(ticketId); else drafts.set(ticketId, content);
        } catch (error) {}
    };

    const initEditor = () => {
        if (!ticket || isTerminal(ticket.status) || !window.EditorV2) return;
        const draft = drafts.get(ticketId) || '';
        if (editorApi) {
            editorApi.setHTML(draft);
            setTimeout(() => editorApi?.cm?.refresh(), 0);
            return;
        }
        const host = $editorHost.get(0);
        if (!host) return;
        host.innerHTML = EditorV2.buildHtml({
            name: 'content',
            placeholder: '输入清晰、具体的回复，让用户知道问题如何继续处理…',
            allowHtmlSource: false,
            allowRawHtml: false
        });
        editorApi = EditorV2.register(host, {
            name: 'content',
            uploadUrl: '/admin/api/ticket/upload',
            height: 230,
            value: draft,
            allowHtmlSource: false,
            allowRawHtml: false
        });
    };

    const resetDrawerContent = () => {
        ticket = null;
        seenMessages.clear();
        lastMessageId = 0;
        minMessageId = 0;
        polling = false;
        historyLoading = false;
        $drawer.removeClass('has-error').addClass('is-loading');
        resetMobileLayers();
        $drawer.find('.md-ticket-number').text('正在读取工单');
        $drawer.find('.md-ticket-hero__title').text('请稍候…');
        $drawer.find('.md-ticket-mobile-title').text('工单处理');
        $drawer.find('.md-ticket-mobile-member').text('正在读取…');
        $drawer.find('.md-ticket-mobile-status').text('连接中');
        $drawer.find('.md-ticket-detail-type, .md-ticket-detail-priority, .md-ticket-detail-status, .md-ticket-detail-time').empty();
        $drawer.find('.md-ticket-live-dot').removeClass('is-terminal');
        $drawer.find('.md-ticket-hero__actions, .md-ticket-context, .md-ticket-composer').prop('hidden', false);
        $drawer.find('.md-ticket-terminal, .ticket-proof-card').prop('hidden', true);
        $drawer.find('.ticket-user-context, .ticket-relation-context, .ticket-time-context').html('<div class="md-ticket-context-skeleton"></div>');
        $drawer.find('.ticket-proof-context').empty();
        $messages.html('<div class="md-ticket-loading"><span></span><span></span><span></span><p>正在整理沟通记录</p></div>');
        $historyButton.prop('hidden', true).prop('disabled', false).removeClass('is-loading').find('span').text('加载更早的沟通记录');
        setSyncState('正在连接', true);
        setBusy(true);
    };

    const showFatal = text => {
        stopPolling();
        $drawer.removeClass('is-loading').addClass('has-error').attr('aria-busy', 'false');
        $drawer.find('.md-ticket-number').text('读取失败');
        $drawer.find('.md-ticket-hero__title').text('无法读取这张工单');
        $drawer.find('.md-ticket-mobile-title').text('读取失败');
        $drawer.find('.md-ticket-mobile-member').text('请返回后重试');
        $drawer.find('.md-ticket-mobile-status').text('连接失败');
        $drawer.find('.md-ticket-detail-type, .md-ticket-detail-priority, .md-ticket-detail-status, .md-ticket-detail-time').empty();
        $messages.html(`<div class="md-ticket-empty md-ticket-empty--error"><i class="fa-duotone fa-regular fa-circle-exclamation"></i><strong>${esc(text || '工单不存在或已失效')}</strong><span>你可以关闭抽屉后重试</span><button type="button" class="btn btn-light-primary md-ticket-fatal-close">关闭详情</button></div>`);
        $drawer.find('.md-ticket-context, .md-ticket-composer, .md-ticket-terminal, .md-ticket-hero__actions').prop('hidden', true);
        $historyButton.prop('hidden', true);
        busy = false;
        setSyncState('读取失败');
    };

    const pollMessages = () => {
        if (!ticket || isTerminal(ticket.status) || polling || busy || document.hidden || !isOpen()) return;
        const token = session;
        const id = ticketId;
        polling = true;
        setSyncState('同步中', true);
        const finishPoll = text => {
            if (!isCurrent(token, id)) return;
            polling = false;
            if (ticket && isTerminal(ticket.status)) {
                setSyncState('记录已归档');
                return;
            }
            setSyncState(text);
        };
        util.post({
            url: '/admin/api/ticket/messages',
            data: {id: id, after_id: lastMessageId},
            loader: false,
            error: () => finishPoll('稍后重试'),
            fail: () => finishPoll('稍后重试'),
            done: response => {
                if (!isCurrent(token, id)) return;
                const data = response.data || {};
                const previousStatus = Number(ticket.status);
                const added = appendMessages(data.list || []);
                if (data.status !== undefined) applyStatus(data.status);
                if (data.last_message_time) {
                    ticket.last_message_time = data.last_message_time;
                    $drawer.find('.ticket-time-context .md-ticket-context-row:nth-child(2) strong').text(data.last_message_time);
                }
                if (added > 0 || previousStatus !== Number(ticket.status)) {
                    refreshList(80);
                    $(document).trigger('ticket:badge-refresh');
                }
                finishPoll('已同步');
            }
        });
    };

    const startPolling = () => {
        stopPolling();
        if (ticket && !isTerminal(ticket.status) && isOpen()) {
            pollTimer = setInterval(pollMessages, 15000);
        }
    };

    const loadDetail = (token, id) => {
        util.post({
            url: '/admin/api/ticket/detail',
            data: {id: id, limit: 100},
            loader: false,
            done: response => {
                if (!isCurrent(token, id)) return;
                const data = response.data || {};
                if (!data.ticket) {
                    showFatal('没有找到这张工单');
                    return;
                }
                renderTicket(data.ticket);
                appendMessages(data.messages, true);
                $historyButton.prop('hidden', !data.has_more);
                initEditor();
                $drawer.removeClass('is-loading').attr('aria-busy', 'false');
                setBusy(false);
                setSyncState(isTerminal(ticket.status) ? '记录已归档' : '已同步');
                startPolling();
                refreshList(80);
                $(document).trigger('ticket:badge-refresh');
            },
            error: response => { if (isCurrent(token, id)) showFatal(response?.msg || '工单读取失败'); },
            fail: () => { if (isCurrent(token, id)) showFatal('网络连接失败，请稍后重试'); }
        });
    };

    const openDrawer = (rawId, trigger = null) => {
        const id = Number(rawId);
        if (!Number.isInteger(id) || id < 1) {
            message.error('工单编号不正确');
            return;
        }
        if (isOpen() && ticketId === id) {
            $panel.trigger('focus');
            return;
        }

        const alreadyOpen = isOpen();
        if (alreadyOpen) saveDraft();
        stopPolling();
        clearEditorState();
        clearTimeout(closeTimer);
        closeTimer = null;
        session++;
        ticketId = id;
        const token = session;
        if (!alreadyOpen) previousFocus = trigger || document.activeElement;

        $drawer.attr({'data-ticket-id': id, 'aria-hidden': 'false'}).prop('hidden', false);
        document.body.classList.add('md-ticket-drawer-open');
        resetDrawerContent();
        // Force the initial off-canvas state to paint before the transition starts.
        void $drawer.get(0).offsetWidth;
        $drawer.addClass('is-open');
        setTimeout(() => { if (isCurrent(token, id)) $panel.trigger('focus'); }, 40);
        loadDetail(token, id);
    };

    const loadEarlier = () => {
        if (historyLoading || !minMessageId || !ticket || !isOpen()) return;
        const token = session;
        const id = ticketId;
        historyLoading = true;
        $historyButton.prop('disabled', true).addClass('is-loading').find('span').text('正在加载…');
        const finish = (hasMore, text = '加载更早的沟通记录') => {
            if (!isCurrent(token, id)) return;
            historyLoading = false;
            $historyButton.prop('disabled', false).removeClass('is-loading').prop('hidden', !hasMore).find('span').text(text);
        };
        util.post({
            url: '/admin/api/ticket/messages',
            data: {id: id, before_id: minMessageId, limit: 50},
            loader: false,
            done: response => {
                if (!isCurrent(token, id)) return;
                const data = response.data || {};
                prependMessages(data.list || []);
                finish(!!data.has_more);
            },
            error: () => finish(true, '加载失败，点击重试'),
            fail: () => finish(true, '加载失败，点击重试')
        });
    };

    const sendReply = mode => {
        if (!editorApi || busy || !ticket || isTerminal(ticket.status) || !isOpen()) return;
        const token = session;
        const id = ticketId;
        setBusy(true);
        setTimeout(() => {
            if (!isCurrent(token, id) || !editorApi) return;
            const content = editorApi.getHTML();
            if (contentIsEmpty(content)) {
                message.warning('请先填写回复内容');
                setBusy(false);
                editorApi.cm?.focus();
                return;
            }
            util.post({
                url: '/admin/api/ticket/reply',
                data: {id: id, content: content, mode: mode},
                loader: false,
                done: response => {
                    drafts.delete(id);
                    refreshList(0);
                    $(document).trigger('ticket:badge-refresh');
                    if (!isCurrent(token, id)) return;
                    const data = response.data || {};
                    if (data.message) appendMessages([data.message]);
                    if (data.status !== undefined) applyStatus(data.status);
                    editorApi?.setHTML('');
                    setComposerExpanded(false);
                    message.success(mode === 'resolve' ? '回复已发送，工单已解决' : '回复已发送');
                    setBusy(false);
                },
                error: response => {
                    if (!isCurrent(token, id)) return;
                    message.error(response?.msg || '回复发送失败');
                    setBusy(false);
                },
                fail: () => {
                    if (!isCurrent(token, id)) return;
                    message.error('网络连接失败，请稍后重试');
                    setBusy(false);
                }
            });
        }, 150);
    };

    const closeTicket = () => {
        if (busy || !ticket || isTerminal(ticket.status) || !isOpen()) return;
        const token = session;
        const id = ticketId;
        message.ask('直接关闭不会发送答复，关闭后用户将无法继续发言。历史记录会完整保留。', () => {
            if (!isCurrent(token, id) || busy) return;
            setBusy(true);
            util.post({
                url: '/admin/api/ticket/close',
                data: {id: id},
                loader: false,
                done: response => {
                    drafts.delete(id);
                    refreshList(0);
                    $(document).trigger('ticket:badge-refresh');
                    if (!isCurrent(token, id)) return;
                    const data = response.data || {};
                    if (data.message) appendMessages([data.message]);
                    applyStatus(data.status ?? 3);
                    setBusy(false);
                    message.success('工单已关闭');
                },
                error: response => {
                    if (!isCurrent(token, id)) return;
                    message.error(response?.msg || '关闭工单失败');
                    setBusy(false);
                },
                fail: () => {
                    if (!isCurrent(token, id)) return;
                    message.error('网络连接失败，请稍后重试');
                    setBusy(false);
                }
            });
        }, '直接关闭工单？', '确认关闭');
    };

    const closeDrawer = (refresh = true) => {
        if (!isOpen()) return;
        saveDraft();
        stopPolling();
        const closedSession = ++session;
        const closedTicketId = ticketId;
        const focusTarget = previousFocus;
        previousFocus = null;
        historyLoading = false;
        busy = false;
        resetMobileLayers();
        $drawer.removeClass('is-open is-loading md-ticket-is-busy').attr({'aria-hidden': 'true', 'aria-busy': 'false'});
        document.body.classList.remove('md-ticket-drawer-open');
        if (refresh) refreshList(0);

        clearTimeout(closeTimer);
        closeTimer = setTimeout(() => {
            if (closedSession !== session || isOpen()) return;
            clearEditorState();
            ticket = null;
            ticketId = 0;
            $drawer.attr('data-ticket-id', '').prop('hidden', true);
        }, 420);
        setTimeout(() => {
            const replacement = $tableElement.find(`.md-ticket-cell[data-ticket-id="${closedTicketId}"]`).get(0);
            const target = replacement || (focusTarget && document.contains(focusTarget) ? focusTarget : document.getElementById('ticket-list-title'));
            if (!target) return;
            if (target.id === 'ticket-list-title') target.setAttribute('tabindex', '-1');
            try { target.focus(); } catch (error) {}
        }, 120);
    };

    table = new Table('/admin/api/ticket/data', '#ticket-table');
    table.setPagination(15, [15, 30, 50, 100]);
    table.setColumns([
        {field: 'ticket_no', title: '工单', formatter: (_, row) => ticketCell(row)},
        {field: 'user', title: '用户', formatter: value => mdUserCell(safeUser(value))},
        {field: 'type', title: '类型 / 优先级', formatter: (_, row) => typePriorityCell(row)},
        {field: 'relation', title: '关联信息', formatter: (_, row) => relationCell(row)},
        {field: 'status', title: '状态', dict: '_ticket_status'},
        {field: 'last_message_time', title: '最后动态', formatter: (_, row) => activityCell(row)},
        {
            field: 'operation', title: '', type: 'button', buttons: [{
                icon: 'fa-duotone fa-regular fa-right-long',
                class: 'text-primary',
                tips: '查看工单',
                click: (event, value, row) => openDrawer(row.id, event?.currentTarget || null)
            }]
        }
    ]);

    table.setSearch([
        {title: '工单号或标题', name: 'keyword', type: 'input', width: 210, inputmode: 'search', enterkeyhint: 'search'},
        {title: '工单类型', name: 'type', type: 'select', dict: '_ticket_type'},
        {title: '优先级', name: 'priority', type: 'select', dict: '_ticket_priority'},
        {title: '提交用户', name: 'equal-user_id', type: 'remoteSelect', dict: 'user,id,username', width: 190},
        {title: '关联订单号', name: 'order_trade_no', type: 'input', width: 190, inputmode: 'search', enterkeyhint: 'search'},
        {title: '创建时间', name: 'between-create_time', type: 'date'}
    ]);

    table.onResponse(response => updateStats(response?.data?.stats));
    table.render();

    $('.md-ticket-state-tabs').off('.mdTicketDrawer').on('click.mdTicketDrawer', 'button', function () {
        const $button = $(this);
        $button.siblings().removeClass('active').attr('aria-selected', 'false');
        $button.addClass('active').attr('aria-selected', 'true');
        table.setWhere('status', $button.attr('data-status') ?? '');
        table.reload({pageNumber: 1});
    });

    $tableElement.off('.mdTicketDrawer')
        .on('click.mdTicketDrawer', '.md-ticket-cell[data-ticket-id]', function () {
            openDrawer($(this).attr('data-ticket-id'), this);
        })
        .on('keydown.mdTicketDrawer', '.md-ticket-cell[data-ticket-id]', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            openDrawer($(this).attr('data-ticket-id'), this);
        });

    $drawer.off('.mdTicketDrawer')
        .on('focusin.mdTicketDrawer', '#ticket-reply-editor textarea', syncComposerViewport)
        .on('pointerdown.mdTicketDrawer', '.md-ticket-composer-tools', () => {
            const input = typeof editorApi?.cm?.getInputField === 'function'
                ? editorApi.cm.getInputField()
                : null;
            refocusComposerAfterExpand = !!input && document.activeElement === input;
        })
        .on('click.mdTicketDrawer', '.md-ticket-drawer__shade, .md-ticket-drawer__close, .md-ticket-fatal-close', () => closeDrawer())
        .on('click.mdTicketDrawer', '.md-ticket-mobile-more', () => setMobileMenu(!$drawer.hasClass('is-mobile-menu-open')))
        .on('click.mdTicketDrawer', '.md-ticket-mobile-menu__shade, .md-ticket-mobile-menu__cancel', () => setMobileMenu(false))
        .on('click.mdTicketDrawer', '.md-ticket-mobile-context-action', () => {
            setMobileMenu(false);
            setContextSheet(true);
        })
        .on('click.mdTicketDrawer', '.md-ticket-context-shade, .md-ticket-context-sheet-close', () => setContextSheet(false))
        .on('click.mdTicketDrawer', '.md-ticket-composer-tools', () => {
            const expanding = !$drawer.find('.md-ticket-composer').hasClass('is-expanded');
            setComposerExpanded(expanding);
            if (expanding && refocusComposerAfterExpand) editorApi?.cm?.focus();
            refocusComposerAfterExpand = false;
        })
        .on('click.mdTicketDrawer', '.md-ticket-history-more', loadEarlier)
        .on('click.mdTicketDrawer', '.ticket-reply-action', () => sendReply('reply'))
        .on('click.mdTicketDrawer', '.ticket-resolve-action', () => {
            if (busy) return;
            setMobileMenu(false);
            message.ask('这条回复会作为最终答复发送，并把工单标记为“已解决”。', () => sendReply('resolve'), '回复并解决工单？', '确认发送');
        })
        .on('click.mdTicketDrawer', '.ticket-close-action', () => {
            setMobileMenu(false);
            closeTicket();
        })
        .on('click.mdTicketDrawer', '.md-ticket-proof', function () {
            component.previewImage(safeImageUrl($(this).attr('data-proof')));
        })
        .on('click.mdTicketDrawer', '.markdown-body img', function () {
            component.previewImage(safeImageUrl($(this).attr('src')));
        })
        .on('keydown.mdTicketDrawer', '.markdown-body img', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            component.previewImage(safeImageUrl($(this).attr('src')));
        });

    $(document).off('.mdTicketDrawer')
        .on('admin:mobile:viewportchange.mdTicketDrawer', syncComposerViewport)
        .on('keydown.mdTicketDrawer', event => {
            if (!isOpen()) return;
            if (event.key === 'Escape') {
                if ($('.layui-layer-dialog:visible, .layui-layer-page:visible').length) return;
                event.preventDefault();
                if ($drawer.hasClass('is-mobile-menu-open')) {
                    setMobileMenu(false);
                    return;
                }
                if ($drawer.hasClass('is-context-open')) {
                    setContextSheet(false);
                    return;
                }
                closeDrawer();
                return;
            }
            if (event.key !== 'Tab') return;
            const $focusable = $panel.find('a[href], button:not(:disabled), input:not(:disabled), textarea:not(:disabled), select:not(:disabled), [tabindex]:not([tabindex="-1"])').filter(':visible');
            if (!$focusable.length) {
                event.preventDefault();
                $panel.trigger('focus');
                return;
            }
            const first = $focusable.get(0);
            const last = $focusable.get($focusable.length - 1);
            if (!$panel.get(0).contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        })
        .on('visibilitychange.mdTicketDrawer', () => {
            if (!document.hidden && isOpen()) pollMessages();
        });

    const destroyPage = () => {
        if (pageDestroyed) return;
        pageDestroyed = true;
        session++;
        stopPolling();
        clearTimeout(closeTimer);
        clearTimeout(listRefreshTimer);
        if (composerViewportFrame) cancelAnimationFrame(composerViewportFrame);
        composerViewportFrame = 0;
        refocusComposerAfterExpand = false;
        destroyEditor();
        drafts.clear();
        if (typeof Swal !== 'undefined') Swal.close();
        document.body.classList.remove('md-ticket-drawer-open');
        resetMobileLayers();
        $drawer.removeClass('is-open').attr('aria-hidden', 'true').prop('hidden', true);
        $(document).off('.mdTicketDrawer');
        $(window).off('.mdTicketDrawer');
    };

    $(document).on('pjax:send.mdTicketDrawer pjax:beforeReplace.mdTicketDrawer', destroyPage);
    $(window).off('beforeunload.mdTicketDrawer').on('beforeunload.mdTicketDrawer', destroyPage);
}();
