!function () {
    const $page = $('.uc-ticket-page').first();
    if (!$page.length || !$page.find('.uc-ticket-board').length) return;

    const TYPE = {
        0: {label: '售前咨询', icon: 'question_answer', className: 'is-presale'},
        1: {label: '售后支持', icon: 'handyman', className: 'is-aftersale'}
    };
    const PRIORITY = {
        0: {label: '低优先级', className: 'is-low'},
        1: {label: '中优先级', className: 'is-medium'},
        2: {label: '高优先级', className: 'is-high'}
    };
    const STATUS = {
        0: {label: '待客服回复', icon: 'schedule', className: 'is-waiting'},
        1: {label: '待我回复', icon: 'mark_chat_unread', className: 'is-reply'},
        2: {label: '已解决', icon: 'task_alt', className: 'is-resolved'},
        3: {label: '已关闭', icon: 'lock', className: 'is-closed'}
    };

    const state = {page: 1, limit: 8, keyword: '', status: '', type: '', total: 0};
    let requestVersion = 0;
    let searchTimer = null;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function number(value, fallback) {
        const result = Number(value);
        return Number.isFinite(result) ? result : fallback;
    }

    function prettyTime(value) {
        if (!value) return '刚刚';
        const normalized = String(value).replace(/-/g, '/');
        const time = new Date(normalized).getTime();
        if (!Number.isFinite(time)) return escapeHtml(value);
        const seconds = Math.max(0, Math.floor((Date.now() - time) / 1000));
        if (seconds < 60) return '刚刚';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} 分钟前`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} 小时前`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)} 天前`;
        return escapeHtml(value);
    }

    function contextHtml(row) {
        if (number(row.type, 0) === 0 && row.commodity_name) {
            return `<span class="uc-ticket-row__context"><span class="material-icons-outlined">inventory_2</span>${escapeHtml(row.commodity_name)}</span>`;
        }
        if (row.order_trade_no) {
            const guest = number(row.order_source, number(row.order_source_code, 0)) === 2 || row.order_source === 'guest' ? '<em>游客订单</em>' : '';
            return `<span class="uc-ticket-row__context"><span class="material-icons-outlined">receipt_long</span>${escapeHtml(row.order_trade_no)}${guest}</span>`;
        }
        return '<span class="uc-ticket-row__context is-empty"><span class="material-icons-outlined">link_off</span>未关联商品或订单</span>';
    }

    function renderRow(row) {
        const type = TYPE[number(row.type, 0)] || TYPE[0];
        const priority = PRIORITY[number(row.priority, 1)] || PRIORITY[1];
        const status = STATUS[number(row.status, 0)] || STATUS[0];
        const unread = Math.max(0, number(row.user_unread, 0));
        const excerpt = row.last_message_excerpt || '工单已创建，等待进一步沟通。';
        const sender = number(row.last_sender_type, 0) === 1 ? '客服' : (number(row.last_sender_type, 0) === 2 ? '系统' : '我');
        const time = row.last_message_time || row.update_time || row.create_time;

        return `<a class="uc-ticket-row${unread > 0 ? ' has-unread' : ''}" href="/user/ticket/detail?id=${encodeURIComponent(row.id)}">
            <span class="uc-ticket-row__rail ${status.className}"></span>
            <span class="uc-ticket-row__icon ${type.className}"><span class="material-icons-outlined">${type.icon}</span></span>
            <span class="uc-ticket-row__main">
                <span class="uc-ticket-row__top">
                    <span class="uc-ticket-row__number">${escapeHtml(row.ticket_no || `#${row.id}`)}</span>
                    <span class="uc-ticket-pill ${type.className}">${type.label}</span>
                    <span class="uc-ticket-pill ${priority.className}"><i></i>${priority.label}</span>
                    <span class="uc-ticket-pill ${status.className}"><span class="material-icons-outlined">${status.icon}</span>${status.label}</span>
                    ${unread > 0 ? `<span class="uc-ticket-row__unread">${unread > 99 ? '99+' : unread} 条新回复</span>` : ''}
                </span>
                <strong class="uc-ticket-row__title">${escapeHtml(row.title || '未命名工单')}</strong>
                <span class="uc-ticket-row__excerpt"><b>${sender}：</b>${escapeHtml(excerpt)}</span>
                <span class="uc-ticket-row__bottom">${contextHtml(row)}<span class="uc-ticket-row__time"><span class="material-icons-outlined">update</span>${prettyTime(time)}</span></span>
            </span>
            <span class="uc-ticket-row__go"><span class="material-icons-outlined">chevron_right</span></span>
        </a>`;
    }

    function updateStats(stats) {
        stats = stats || {};
        const all = ['pending_admin', 'pending_user', 'resolved', 'closed']
            .reduce((sum, key) => sum + Math.max(0, number(stats[key], 0)), 0);
        $('[data-stat="all"]').text(all);
        ['pending_admin', 'pending_user', 'resolved', 'closed'].forEach(key => {
            $(`[data-stat="${key}"]`).text(Math.max(0, number(stats[key], 0)));
        });
        if (window.ucTicketRefreshBadge) window.ucTicketRefreshBadge();
    }

    function updatePagination() {
        const pages = Math.max(1, Math.ceil(state.total / state.limit));
        const $pagination = $('.uc-ticket-pagination');
        $pagination.prop('hidden', state.total <= state.limit);
        $pagination.find('[data-page-action="prev"]').prop('disabled', state.page <= 1);
        $pagination.find('[data-page-action="next"]').prop('disabled', state.page >= pages);
        $pagination.find('.uc-ticket-pagination__meta').text(`第 ${state.page} / ${pages} 页 · 共 ${state.total} 张`);
    }

    function showLoading() {
        $('.uc-ticket-loading').show();
        $('.uc-ticket-list').hide();
        $('.uc-ticket-empty, .uc-ticket-error').prop('hidden', true);
        $('.uc-ticket-pagination').prop('hidden', true);
    }

    function showError(response) {
        const detail = response && response.msg ? String(response.msg) : '';
        const pendingUpgrade = detail.includes('数据库') || detail.includes('3.5.1');
        $('.uc-ticket-loading').hide();
        $('.uc-ticket-list').hide().empty();
        $('.uc-ticket-empty').prop('hidden', true);
        $('.uc-ticket-error').prop('hidden', false);
        $('.uc-ticket-error strong').text(pendingUpgrade ? '工单功能尚未启用' : '暂时无法读取工单');
        $('.uc-ticket-error small').text(pendingUpgrade ? '请联系管理员完成 3.5.1 数据库升级' : '请检查网络后再试一次');
        $('.uc-ticket-pagination').prop('hidden', true);
    }

    function loadTickets() {
        const version = ++requestVersion;
        showLoading();
        util.post({
            url: '/user/api/ticket/data',
            data: {
                page: state.page,
                limit: state.limit,
                keyword: state.keyword,
                status: state.status,
                type: state.type,
                priority: ''
            },
            loader: false,
            done: res => {
                if (version !== requestVersion) return;
                const payload = res && res.data ? res.data : {};
                if (payload.ready === false) {
                    showError({msg: '工单数据库尚未升级，请先完成 3.5.1 数据库升级'});
                    return;
                }
                const list = Array.isArray(payload.list) ? payload.list : [];
                state.total = Math.max(0, number(payload.total, list.length));
                updateStats(payload.stats);
                $('.uc-ticket-loading').hide();
                $('.uc-ticket-error').prop('hidden', true);

                if (!list.length) {
                    $('.uc-ticket-list').hide().empty();
                    const filtered = state.keyword !== '' || state.status !== '' || state.type !== '';
                    $('.uc-ticket-empty strong').text(filtered ? '没有符合条件的工单' : '这里还没有工单');
                    $('.uc-ticket-empty p').text(filtered ? '换一个关键词或筛选条件，也许能找到你要的记录。' : '遇到疑问时，不必反复寻找客服入口，创建一张工单就能持续跟进。');
                    $('.uc-ticket-empty').prop('hidden', false);
                } else {
                    $('.uc-ticket-empty').prop('hidden', true);
                    $('.uc-ticket-list').html(list.map(renderRow).join('')).fadeIn(140);
                }
                updatePagination();
            },
            error: response => {
                if (version === requestVersion) showError(response);
            },
            fail: () => {
                if (version === requestVersion) showError();
            }
        });
    }

    $('.uc-ticket-stat').on('click', function () {
        state.status = String($(this).data('status') == null ? '' : $(this).data('status'));
        state.page = 1;
        $('.uc-ticket-stat').removeClass('is-active');
        $(this).addClass('is-active');
        loadTickets();
    });

    $('select[name="ticket-type"]').on('change', function () {
        state.type = this.value;
        state.page = 1;
        loadTickets();
    });

    $('input[name="ticket-keyword"]').on('input', function () {
        const value = this.value.trim();
        $('.uc-ticket-search').toggleClass('has-value', value !== '');
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.keyword = value;
            state.page = 1;
            loadTickets();
        }, 320);
    }).on('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            clearTimeout(searchTimer);
            state.keyword = this.value.trim();
            state.page = 1;
            loadTickets();
        }
    });

    $('.uc-ticket-search__clear').on('click', function () {
        const $input = $('input[name="ticket-keyword"]');
        $input.val('').trigger('input').focus();
    });

    $('[data-page-action]').on('click', function () {
        if (this.disabled) return;
        state.page += $(this).data('page-action') === 'next' ? 1 : -1;
        state.page = Math.max(1, state.page);
        loadTickets();
        document.querySelector('.uc-ticket-board')?.scrollIntoView({behavior: 'smooth', block: 'start'});
    });

    $('.uc-ticket-error button').on('click', loadTickets);
    loadTickets();
}();
