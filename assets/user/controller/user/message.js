!function () {
    const $page = $('.uc-message-page').first();
    if (!$page.length || !$page.find('#message-table').length) return;
    const pageNode = $page.get(0);
    const pageEpoch = (Number(window.__ucMessagePageEpoch) || 0) + 1;
    window.__ucMessagePageEpoch = pageEpoch;
    const isPageCurrent = () => Number(window.__ucMessagePageEpoch) === pageEpoch
        && document.contains(pageNode)
        && $page.find('#message-table').length > 0;
    $(document)
        .off('pjax:send.ucMessagePageLifecycle pjax:popstate.ucMessagePageLifecycle')
        .on('pjax:send.ucMessagePageLifecycle pjax:popstate.ucMessagePageLifecycle', () => {
            if (Number(window.__ucMessagePageEpoch) === pageEpoch) {
                window.__ucMessagePageEpoch = pageEpoch + 1;
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
    const mobileCardQuery = mountFujiPage && window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;
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
        if (!focusAfterReload) return;
        const target = focusAfterReload;
        focusAfterReload = null;
        requestAnimationFrame(() => {
            if (target && document.contains(target) && typeof target.focus === 'function') target.focus();
        });
    });
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

    function syncMessageState(resetPage = false) {
        if (typeof window.ucMessageNotifyChanged === 'function') {
            window.ucMessageNotifyChanged({resetPage: resetPage});
        } else if (resetPage) {
            table.reload({pageNumber: 1});
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
        message.ask(ids.length > 1 ? `确定删除选中的 ${ids.length} 条消息吗？删除后无法恢复。` : '确定删除这条消息吗？删除后无法恢复。', () => {
            util.post('/user/api/message/del', {list: ids}, () => {
                message.success('消息已删除');
                syncMessageState();
            });
        });
    }

    $page.find('[data-message-action="delete-selected"]').on('click', () => {
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
        else table.refresh(false);
    });
}();
