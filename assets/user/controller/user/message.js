!function () {
    const $page = $('.uc-message-page').first();
    if (!$page.length || !$page.find('#message-table').length) return;
    const pageNode = $page.get(0);
    const pageEpoch = (Number(window.__ucMessagePageEpoch) || 0) + 1;
    window.__ucMessagePageEpoch = pageEpoch;
    let cleanupPage = () => {};
    const isPageCurrent = () => Number(window.__ucMessagePageEpoch) === pageEpoch
        && document.contains(pageNode)
        && $page.find('#message-table').length > 0;
    $(document)
        .off('pjax:send.ucMessagePageLifecycle pjax:popstate.ucMessagePageLifecycle')
        .on('pjax:send.ucMessagePageLifecycle pjax:popstate.ucMessagePageLifecycle', () => {
            if (Number(window.__ucMessagePageEpoch) === pageEpoch) {
                window.__ucMessagePageEpoch = pageEpoch + 1;
                cleanupPage();
            }
        });

    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

    const normalizeMessage = row => {
        row = row || {};
        const source = row.message || row.system_message || row;
        return {
            id: row.id || row.user_message_id || source.user_message_id || 0,
            message_id: row.message_id || source.id || 0,
            title: row.title || source.title || '未命名消息',
            summary: row.summary || source.summary || '',
            content: row.content || source.content || '',
            jump_url: row.jump_url || source.jump_url || row.url || source.url || row.link_url || source.link_url || '',
            create_time: row.create_time || source.create_time || '',
            update_time: row.update_time || source.update_time || '',
            read_time: row.read_time || null,
            became_read: row.became_read,
            unread_count: row.unread_count
        };
    };

    const table = new Table('/user/api/message/data', '#message-table');
    const mountFujiPage = $page.hasClass('mf-message-page');
    const newYorkPage = $page.hasClass('ny-message-page');
    const cartoonPage = $page.is('[data-cartoon-message-page]');
    const cartoonMobileQuery = cartoonPage && window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;
    const mobileCardQuery = mountFujiPage && window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;
    let cartoonSelectionMode = false;
    let cartoonKeyword = '';
    let cartoonStatus = '';
    let cartoonSnapshot = null;
    if (mobileCardQuery && mobileCardQuery.matches) {
        table.enableCardView();
    }
    let focusAfterReload = null;
    table.setPagination(10, [10]);
    table.setColumns([
        {checkbox: true},
        {
            field: 'title',
            title: '消息内容',
            formatter: (_, row) => {
                const item = normalizeMessage(row);
                return `<div class="uc-message-cell${item.read_time ? '' : ' is-unread'}">
                    <span class="uc-message-cell__dot"></span>
                    <span class="uc-message-cell__copy">
                        <strong>${escapeHtml(item.title)}</strong>
                        <small>${escapeHtml(item.summary || '点击查看消息详情')}</small>
                    </span>
                </div>`;
            }
        },
        {
            field: 'read_time',
            title: '状态',
            formatter: value => value
                ? '<span class="uc-message-status is-read"><span class="material-icons-outlined" aria-hidden="true">drafts</span>已读</span>'
                : '<span class="uc-message-status is-unread"><span class="material-icons-outlined" aria-hidden="true">mark_email_unread</span>未读</span>'
        },
        {field: 'create_time', title: '接收时间'},
        {
            field: 'operation',
            title: '操作',
            type: 'button',
            buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-eye',
                    class: 'text-primary',
                    title: '查看',
                    click: (event, value, row) => openMessage(row.id, event.currentTarget)
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: 'text-danger',
                    title: '删除',
                    click: (event, value, row) => deleteMessages([row.id])
                }
            ]
        }
    ]);
    table.setState('status', [
        {id: 0, name: '未读'},
        {id: 1, name: '已读'}
    ]);
    table.onResponse(response => {
        const total = Math.max(0, Number(response && response.data && response.data.total) || 0);
        $page.find('.uc-card__sub').text(total > 0 ? `共 ${total} 条消息 · 打开后自动标记为已读` : '打开消息后自动标记为已读');
    });
    table.onComplete(() => {
        cartoonPage && renderCartoonMobile(table.getMobileSnapshot('complete'));
        if (!focusAfterReload) return;
        const target = focusAfterReload;
        focusAfterReload = null;
        requestAnimationFrame(() => {
            if (target && document.contains(target) && typeof target.focus === 'function') target.focus();
        });
    });

    if (cartoonPage) {
        $page.find('#message-table').on(
            'admin:table:ready.ucCartoonMessage admin:table:update.ucCartoonMessage',
            (event, payload) => renderCartoonMobile(payload && payload.snapshot ? payload.snapshot : table.getMobileSnapshot('event'))
        );
    }
    table.render();

    if (mobileCardQuery) {
        if (window.__mfMessageCardQuery && window.__mfMessageCardHandler) {
            if (typeof window.__mfMessageCardQuery.removeEventListener === 'function') {
                window.__mfMessageCardQuery.removeEventListener('change', window.__mfMessageCardHandler);
            } else if (typeof window.__mfMessageCardQuery.removeListener === 'function') {
                window.__mfMessageCardQuery.removeListener(window.__mfMessageCardHandler);
            }
        }
        const syncCardView = event => {
            const $table = $('#message-table');
            const instance = $table.data('bootstrap.table');
            if (!instance || !instance.options || Boolean(instance.options.cardView) === Boolean(event.matches)) return;
            $table.bootstrapTable('toggleView');
        };
        window.__mfMessageCardQuery = mobileCardQuery;
        window.__mfMessageCardHandler = syncCardView;
        if (typeof mobileCardQuery.addEventListener === 'function') {
            mobileCardQuery.addEventListener('change', syncCardView);
        } else if (typeof mobileCardQuery.addListener === 'function') {
            mobileCardQuery.addListener(syncCardView);
        }
    }

    function isCartoonMobile() {
        return Boolean(cartoonMobileQuery && cartoonMobileQuery.matches);
    }

    function selectedCartoonIds() {
        return cartoonSnapshot && cartoonSnapshot.selection
            ? cartoonSnapshot.selection.ids.map(Number).filter(id => id > 0)
            : table.getSelectionIds().map(Number).filter(id => id > 0);
    }

    function clearCartoonSelection() {
        table.getSelectionIds().forEach(id => table.setRowSelected(id, false));
    }

    function leaveCartoonSelectionMode() {
        cartoonSelectionMode = false;
        clearCartoonSelection();
        syncCartoonSelectionUi();
    }

    function syncCartoonSelectionUi() {
        if (!cartoonPage) return;
        const mobile = isCartoonMobile();
        const ids = selectedCartoonIds();
        const $modeButton = $page.find('[data-message-action="delete-selected"]');
        const $modeIcon = $modeButton.find('.material-icons-outlined').first();
        const $modeLabel = $modeButton.contents().filter(function () { return this.nodeType === Node.TEXT_NODE; }).last();
        const $bar = $page.find('[data-message-selection-bar]');
        const rowStates = cartoonSnapshot && cartoonSnapshot.selection ? cartoonSnapshot.selection.rowStates : [];
        const selectable = rowStates.filter(item => item.selectable && !item.disabled);
        const selected = selectable.filter(item => item.selected);

        if (!mobile) {
            $modeIcon.text('delete_sweep');
            $modeLabel.length ? $modeLabel[0].nodeValue = '删除选中' : $modeButton.append(document.createTextNode('删除选中'));
            $modeButton.attr('aria-pressed', 'false');
            $bar.prop('hidden', true);
            return;
        }

        $modeIcon.text(cartoonSelectionMode ? 'close' : 'checklist');
        $modeLabel.length
            ? $modeLabel[0].nodeValue = cartoonSelectionMode ? '取消选择' : '选择消息'
            : $modeButton.append(document.createTextNode(cartoonSelectionMode ? '取消选择' : '选择消息'));
        $modeButton.attr('aria-pressed', cartoonSelectionMode ? 'true' : 'false');
        $page.toggleClass('is-message-selecting', cartoonSelectionMode);
        $bar.prop('hidden', !cartoonSelectionMode);
        $page.find('[data-message-selection-count]').text(`已选 ${ids.length} 条`);
        $page.find('[data-message-selection-delete]').prop('disabled', ids.length < 1);
        $page.find('[data-message-selection-all]')
            .prop('disabled', selectable.length < 1)
            .text(selectable.length > 0 && selected.length === selectable.length ? '取消全选' : '全选本页');
    }

    function cartoonMessageCard(row, index, selectedIds) {
        const item = normalizeMessage(row);
        const selected = selectedIds.includes(Number(item.id));
        const title = escapeHtml(item.title);
        const summary = escapeHtml(item.summary || '点击查看消息详情');
        const time = escapeHtml(item.create_time || '时间未知');
        const stateIcon = item.read_time ? 'drafts' : 'mark_email_unread';
        const stateText = item.read_time ? '已读' : '未读';
        return `<article class="uc-message-mobile-card${item.read_time ? '' : ' is-unread'}${selected ? ' is-selected' : ''}" data-message-card-id="${Number(item.id)}">
            <button type="button" class="uc-message-mobile-card__select" data-message-select="${Number(item.id)}" aria-label="${selected ? '取消选择' : '选择'}消息：${title}" aria-pressed="${selected ? 'true' : 'false'}">
                <span class="material-icons-outlined" aria-hidden="true">${selected ? 'check_circle' : 'radio_button_unchecked'}</span>
            </button>
            <button type="button" class="uc-message-mobile-card__content" data-message-open="${Number(item.id)}">
                <span class="uc-message-mobile-card__topline">
                    <span class="uc-message-mobile-card__state"><span class="material-icons-outlined" aria-hidden="true">${stateIcon}</span>${stateText}</span>
                    <time>${time}</time>
                </span>
                <strong>${title}</strong>
                <span class="uc-message-mobile-card__summary">${summary}</span>
            </button>
            <div class="uc-message-mobile-card__actions">
                <button type="button" data-message-open="${Number(item.id)}"><span class="material-icons-outlined" aria-hidden="true">visibility</span>查看详情</button>
                <button type="button" class="is-danger" data-message-delete="${Number(item.id)}"><span class="material-icons-outlined" aria-hidden="true">delete_outline</span>删除</button>
            </div>
        </article>`;
    }

    function renderCartoonMobile(snapshot) {
        if (!cartoonPage || !snapshot || !isPageCurrent()) return;
        cartoonSnapshot = snapshot;
        const $list = $page.find('[data-message-mobile-list]');
        if (!$list.length) return;
        const status = snapshot.status || {};
        const pagination = snapshot.pagination || {pageNumber: 1, totalPages: 0, total: 0};
        const total = Math.max(0, Number(pagination.total) || 0);
        const rows = Array.isArray(snapshot.rows) ? snapshot.rows : [];
        const selectedIds = snapshot.selection ? snapshot.selection.ids.map(Number) : [];

        $list.attr('aria-busy', status.loading ? 'true' : 'false');
        if (status.loading) {
            $list.html('<div class="uc-message-mobile-loading" aria-label="正在加载消息"><i></i><i></i><i></i></div>');
            $page.find('[data-message-mobile-result]').text('正在加载消息');
        } else if (status.error) {
            $list.html('<div class="uc-message-mobile-feedback is-error"><span class="material-icons-outlined" aria-hidden="true">cloud_off</span><strong>消息加载失败</strong><small>网络可能暂时不可用，请重新加载。</small><button type="button" data-message-retry><span class="material-icons-outlined" aria-hidden="true">refresh</span>重新加载</button></div>');
            $page.find('[data-message-mobile-result]').text('加载失败，未显示旧数据');
            $page.find('.uc-card__sub').text('消息加载失败 · 可重新加载');
        } else if (!rows.length) {
            $list.html(`<div class="uc-message-mobile-feedback"><span class="material-icons-outlined" aria-hidden="true">${cartoonKeyword || cartoonStatus !== '' ? 'search_off' : 'inbox'}</span><strong>${cartoonKeyword || cartoonStatus !== '' ? '没有找到符合条件的消息' : '暂时没有消息'}</strong><small>${cartoonKeyword || cartoonStatus !== '' ? '换个关键词或筛选条件试试。' : '收到的新消息会出现在这里。'}</small></div>`);
            $page.find('[data-message-mobile-result]').text('共 0 条消息');
        } else {
            $list.html(rows.map((row, index) => cartoonMessageCard(row, index, selectedIds)).join(''));
            $page.find('[data-message-mobile-result]').text(`共 ${total} 条消息`);
        }

        const currentPage = Math.max(1, Number(pagination.pageNumber) || 1);
        const totalPages = Math.max(0, Number(pagination.totalPages) || 0);
        $page.find('[data-message-mobile-page]').text(totalPages > 0 ? `第 ${currentPage} / ${totalPages} 页` : '');
        const $pager = $page.find('[data-message-mobile-pagination]');
        $pager.prop('hidden', Boolean(status.loading || status.error || totalPages <= 1));
        $pager.find('[data-message-page="previous"]').prop('disabled', currentPage <= 1);
        $pager.find('[data-message-page="next"]').prop('disabled', currentPage >= totalPages);
        $pager.find('[data-message-page-label]').text(`${currentPage} / ${Math.max(1, totalPages)}`);
        syncCartoonSelectionUi();
    }

    function applyCartoonFilters() {
        if (!cartoonPage) return;
        cartoonKeyword = String($page.find('[data-message-mobile-search] input[name="keyword"]').val() || '').trim();
        leaveCartoonSelectionMode();
        table.reload({
            pageNumber: 1,
            query: {keyword: cartoonKeyword, 'equal-status': cartoonStatus}
        });
    }

    if (cartoonPage) {
        const $searchInput = $page.find('[data-message-mobile-search] input[name="keyword"]');
        $page
            .off('.ucCartoonMessage')
            .on('submit.ucCartoonMessage', '[data-message-mobile-search]', event => {
                event.preventDefault();
                applyCartoonFilters();
            })
            .on('input.ucCartoonMessage', '[data-message-mobile-search] input[name="keyword"]', event => {
                $page.find('[data-message-search-clear]').prop('hidden', !String(event.currentTarget.value || '').length);
            })
            .on('click.ucCartoonMessage', '[data-message-search-clear]', () => {
                $searchInput.val('').trigger('input').focus();
                cartoonKeyword = '';
                applyCartoonFilters();
            })
            .on('click.ucCartoonMessage', '[data-message-status]', event => {
                const $button = $(event.currentTarget);
                cartoonStatus = String($button.attr('data-message-status') || '');
                $page.find('[data-message-status]').removeClass('is-active').attr('aria-pressed', 'false');
                $button.addClass('is-active').attr('aria-pressed', 'true');
                applyCartoonFilters();
            })
            .on('click.ucCartoonMessage', '[data-message-open]', event => {
                const id = Number($(event.currentTarget).attr('data-message-open'));
                if (cartoonSelectionMode) {
                    table.setRowSelected(id, !selectedCartoonIds().includes(id));
                    return;
                }
                openMessage(id, event.currentTarget);
            })
            .on('click.ucCartoonMessage', '[data-message-select]', event => {
                const id = Number($(event.currentTarget).attr('data-message-select'));
                table.setRowSelected(id, !selectedCartoonIds().includes(id));
            })
            .on('click.ucCartoonMessage', '[data-message-delete]', event => {
                deleteMessages([Number($(event.currentTarget).attr('data-message-delete'))]);
            })
            .on('click.ucCartoonMessage', '[data-message-selection-cancel]', leaveCartoonSelectionMode)
            .on('click.ucCartoonMessage', '[data-message-selection-all]', () => {
                const rowStates = cartoonSnapshot && cartoonSnapshot.selection ? cartoonSnapshot.selection.rowStates : [];
                const selectable = rowStates.filter(item => item.selectable && !item.disabled);
                const select = selectable.some(item => !item.selected);
                selectable.forEach(item => table.setRowSelected(item.id, select));
            })
            .on('click.ucCartoonMessage', '[data-message-selection-delete]', () => deleteMessages(selectedCartoonIds()))
            .on('click.ucCartoonMessage', '[data-message-page]', event => {
                if (!cartoonSnapshot) return;
                const pagination = cartoonSnapshot.pagination;
                const direction = $(event.currentTarget).attr('data-message-page');
                const nextPage = pagination.pageNumber + (direction === 'previous' ? -1 : 1);
                leaveCartoonSelectionMode();
                table.reload({pageNumber: Math.max(1, Math.min(pagination.totalPages, nextPage))});
            })
            .on('click.ucCartoonMessage', '[data-message-retry]', () => table.getLoadState().retry());

        const syncCartoonViewport = () => {
            if (!isCartoonMobile()) cartoonSelectionMode = false;
            syncCartoonSelectionUi();
        };
        if (cartoonMobileQuery) {
            if (typeof cartoonMobileQuery.addEventListener === 'function') cartoonMobileQuery.addEventListener('change', syncCartoonViewport);
            else if (typeof cartoonMobileQuery.addListener === 'function') cartoonMobileQuery.addListener(syncCartoonViewport);
        }
        cleanupPage = () => {
            $page.off('.ucCartoonMessage');
            $page.find('#message-table').off('.ucCartoonMessage');
            if (cartoonMobileQuery) {
                if (typeof cartoonMobileQuery.removeEventListener === 'function') cartoonMobileQuery.removeEventListener('change', syncCartoonViewport);
                else if (typeof cartoonMobileQuery.removeListener === 'function') cartoonMobileQuery.removeListener(syncCartoonViewport);
            }
        };
    }

    function syncMessageState(options = {}) {
        if (typeof options === 'boolean') options = {resetPage: options};
        if (typeof window.ucMessageNotifyChanged === 'function') {
            window.ucMessageNotifyChanged(options);
        } else if (options.resetPage) {
            table.reload({pageNumber: 1});
        } else if (options.pageNumber) {
            table.reload({pageNumber: options.pageNumber});
        } else {
            table.refresh(false);
        }
    }

    function detailPayload(response) {
        return normalizeMessage(response && response.data && response.data.message ? response.data.message : response.data);
    }

    function openMessage(id, trigger) {
        const $trigger = $(trigger || []);
        if (!id || !isPageCurrent() || $trigger.hasClass('is-loading')) return;
        $trigger.addClass('is-loading');
        util.post({
            url: '/user/api/message/detail',
            data: {id: id},
            loader: false,
            done: response => {
                if (!isPageCurrent()) return;
                $trigger.removeClass('is-loading');
                const detail = detailPayload(response);
                if (typeof window.ucMessageRefresh === 'function') window.ucMessageRefresh();
                detail.onClose = () => {
                    if (!isPageCurrent()) return;
                    focusAfterReload = $page.find('.table-switch-state button.active').get(0)
                        || document.querySelector('.uc-message-btn');
                    syncMessageState(false);
                };
                if (newYorkPage) {
                    if (typeof window.nyMessagePresentDetail === 'function') {
                        window.nyMessagePresentDetail(detail);
                    } else {
                        message.error('消息阅读器尚未准备好，请稍后重试');
                    }
                } else {
                    component.previewMessage(detail);
                }
            },
            error: response => {
                if (!isPageCurrent()) return;
                $trigger.removeClass('is-loading');
                message.error((response && response.msg) || '消息读取失败');
            },
            fail: () => {
                if (!isPageCurrent()) return;
                $trigger.removeClass('is-loading');
                message.error('消息读取失败，请稍后重试');
            }
        });
    }

    function deleteMessages(ids) {
        ids = (Array.isArray(ids) ? ids : []).map(Number).filter(id => id > 0);
        if (!ids.length) {
            message.alert('请先选择需要删除的消息', 'error');
            return;
        }
        const pagination = table.getPagination();
        message.ask(ids.length > 1 ? `确定删除选中的 ${ids.length} 条消息吗？删除后无法恢复。` : '确定删除这条消息吗？删除后无法恢复。', () => {
            util.post('/user/api/message/del', {list: ids}, () => {
                message.success('消息已删除');
                cartoonSelectionMode = false;
                const remaining = Math.max(0, pagination.total - ids.length);
                const lastPage = Math.max(1, Math.ceil(remaining / Math.max(1, pagination.pageSize)));
                syncMessageState({pageNumber: Math.min(pagination.pageNumber, lastPage)});
            });
        });
    }

    $page.find('[data-message-action="delete-selected"]').on('click', () => {
        if (cartoonPage && isCartoonMobile()) {
            cartoonSelectionMode = !cartoonSelectionMode;
            if (!cartoonSelectionMode) clearCartoonSelection();
            syncCartoonSelectionUi();
            return;
        }
        deleteMessages(table.getSelectionIds());
    });

    $page.find('[data-message-action="clear"]').on('click', () => {
        message.ask('确定清空全部消息吗？此操作无法恢复。', () => {
            util.post('/user/api/message/clear', {}, () => {
                message.success('全部消息已清空');
                syncMessageState(true);
            });
        });
    });

    $(document).off('uc:message-changed.ucMessagePage').on('uc:message-changed.ucMessagePage', (event, options = {}) => {
        if (!$('#message-table').length) return;
        if (options.resetPage) table.reload({pageNumber: 1});
        else if (options.pageNumber) table.reload({pageNumber: options.pageNumber});
        else table.refresh(false);
    });
}();
