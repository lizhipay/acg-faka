!function () {
    const $page = $('.uc-ticket-create-page');
    if (!$page.length) return;

    const state = {
        type: 0,
        priority: 1,
        orderMode: 'account',
        proofId: 0,
        proofPath: '',
        proofName: '',
        commodity: null,
        order: null,
        submitting: false
    };
    let editor = null;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function createOptionNode(item, kind) {
        if (!item || item.loading) return item && item.text ? item.text : '';
        const $row = $('<span class="uc-ticket-option"></span>');
        const cover = item.cover || '/favicon.ico';
        $('<img>').attr({src: cover, alt: ''}).appendTo($row);
        const $copy = $('<span class="uc-ticket-option__copy"></span>').appendTo($row);
        $('<strong>').text(item.text || '').appendTo($copy);
        if (kind === 'commodity') {
            $('<small>').text([item.category_name || item.category || '未分类', `商品 ID ${item.id}`].join(' · ')).appendTo($copy);
        } else {
            const meta = [item.trade_no || '', item.amount != null ? `￥${item.amount}` : '', item.pay_time || item.create_time || ''].filter(Boolean).join(' · ');
            $('<small>').text(meta).appendTo($copy);
        }
        return $row;
    }

    function initRemoteSelect(selector, endpoint, kind, placeholder) {
        const $select = $(selector);
        if (!$select.length || !$.fn.select2) return;
        $select.select2({
            width: '100%',
            placeholder: placeholder,
            allowClear: true,
            minimumInputLength: 0,
            language: {
                inputTooShort: () => '输入关键词可以更快找到',
                searching: () => '正在搜索…',
                noResults: () => '没有找到匹配结果',
                errorLoading: () => '加载失败，请稍后再试'
            },
            ajax: {
                url: endpoint,
                type: 'POST',
                dataType: 'json',
                delay: 250,
                data: params => ({keyword: params.term || '', page: params.page || 1, limit: 12}),
                processResults: (res, params) => {
                    const payload = res && res.code === 200 && res.data ? res.data : {};
                    const list = Array.isArray(payload.list) ? payload.list : [];
                    const page = params.page || 1;
                    return {
                        results: list.map(item => ({...item, id: item.id, text: kind === 'commodity' ? item.name : item.commodity_name})),
                        pagination: {more: page * 12 < Number(payload.total || 0)}
                    };
                }
            },
            templateResult: item => createOptionNode(item, kind),
            templateSelection: item => $('<span></span>').text(item.text || placeholder)
        }).on('select2:select', event => {
            if (kind === 'commodity') state.commodity = event.params.data;
            else state.order = event.params.data;
            updateSummary();
            if (kind === 'order') renderPickedOrder();
        }).on('select2:clear', () => {
            if (kind === 'commodity') state.commodity = null;
            else state.order = null;
            updateSummary();
            if (kind === 'order') renderPickedOrder();
        });
    }

    function renderPickedOrder() {
        const $picked = $('.uc-ticket-order-picked');
        if (!state.order) {
            $picked.prop('hidden', true).empty();
            return;
        }
        const cover = escapeHtml(state.order.cover || '/favicon.ico');
        const when = escapeHtml(state.order.pay_time || state.order.create_time || '');
        $picked.html(`<img src="${cover}" alt=""><span><small>已选择订单</small><strong>${escapeHtml(state.order.commodity_name || '商品订单')}</strong><em>${escapeHtml(state.order.trade_no || '')}${state.order.amount != null ? ` · ￥${escapeHtml(state.order.amount)}` : ''}${when ? ` · ${when}` : ''}</em></span><span class="material-icons-outlined">verified</span>`).prop('hidden', false);
    }

    function updateSummary() {
        const isAfter = state.type === 1;
        $('.uc-ticket-summary__type .material-icons-outlined').first().text(isAfter ? 'handyman' : 'question_answer');
        $('.uc-ticket-summary__type strong').text(isAfter ? '售后支持' : '售前咨询');
        $('[data-summary="priority"]').text(['低', '中', '高'][state.priority] || '中');

        let relation = '尚未选择';
        if (!isAfter && state.commodity) relation = state.commodity.text || state.commodity.name || '已选择商品';
        if (isAfter && state.orderMode === 'account' && state.order) relation = state.order.trade_no || '已选择订单';
        if (isAfter && state.orderMode === 'manual') relation = $('input[name="trade_no"]').val().trim() || '等待输入订单号';
        $('[data-summary="relation"]').text(relation).attr('title', relation);
        $('[data-summary="proof"]').text(isAfter ? (state.proofPath ? '已添加' : '等待上传') : '无需上传')
            .toggleClass('is-ready', isAfter && !!state.proofPath);
    }

    function switchType(type) {
        state.type = Number(type) === 1 ? 1 : 0;
        $('.uc-ticket-type').each(function () {
            const active = Number($(this).data('ticket-type')) === state.type;
            $(this).toggleClass('is-active', active).attr('aria-checked', String(active));
        });
        const isAfter = state.type === 1;
        $('.uc-ticket-relation--commodity').prop('hidden', isAfter);
        $('.uc-ticket-relation--order').prop('hidden', !isAfter);
        $('.uc-ticket-relation-title').text(isAfter ? '关联订单与购买凭证' : '关联商品');
        $('.uc-ticket-relation-sub').text(isAfter ? '选择本人订单或手动填写订单号，并上传购买凭证' : '可选，关联后客服能更快理解你的问题');
        updateSummary();
        setTimeout(() => {
            $('#ticket-commodity, #ticket-order').trigger('change.select2');
            editor && editor.cm && editor.cm.refresh();
        }, 0);
    }

    function switchOrderMode(mode) {
        state.orderMode = mode === 'manual' ? 'manual' : 'account';
        $('[data-order-mode]').each(function () {
            const active = $(this).data('order-mode') === state.orderMode;
            $(this).toggleClass('is-active', active).attr('aria-selected', String(active));
        });
        $('[data-order-panel]').prop('hidden', true);
        $(`[data-order-panel="${state.orderMode}"]`).prop('hidden', false);
        updateSummary();
    }

    function setPriority(priority) {
        state.priority = Math.max(0, Math.min(2, Number(priority)));
        $('[data-priority]').each(function () {
            const active = Number($(this).data('priority')) === state.priority;
            $(this).toggleClass('is-active', active).attr('aria-checked', String(active));
        });
        updateSummary();
    }

    function setProof(path, name, id) {
        state.proofId = Number(id) || 0;
        state.proofPath = path || '';
        state.proofName = name || '';
        $('input[name="proof_upload_id"]').val(state.proofId || '');
        $('input[name="proof_path"]').val(state.proofPath);
        const hasProof = state.proofPath !== '';
        $('.uc-ticket-proof__drop').prop('hidden', hasProof).removeClass('is-uploading').prop('disabled', false);
        $('.uc-ticket-proof__preview').prop('hidden', !hasProof);
        if (hasProof) {
            $('.uc-ticket-proof__preview img').attr('src', state.proofPath);
            $('.uc-ticket-proof__name').text(state.proofName || '已上传的图片');
        } else {
            $('.uc-ticket-proof__preview img').attr('src', '');
            $('.uc-ticket-proof__name').text('');
        }
        updateSummary();
    }

    function initProofUpload() {
        if (!layui.upload) return;
        layui.upload.render({
            elem: '.uc-ticket-proof__drop',
            url: '/user/api/ticket/upload',
            accept: 'images',
            acceptMime: 'image/jpeg,image/png,image/webp',
            exts: 'jpg|jpeg|png|webp',
            size: 10240,
            choose: obj => {
                const files = obj.pushFile();
                const first = files[Object.keys(files)[0]];
                state.proofName = first && first.name ? first.name : '购买凭证';
            },
            before: () => {
                $('.uc-ticket-proof__drop').addClass('is-uploading').prop('disabled', true)
                    .find('.uc-ticket-proof__copy strong').text('正在上传图片…');
            },
            done: res => {
                $('.uc-ticket-proof__drop .uc-ticket-proof__copy strong').text('上传购买凭证');
                if (!res || res.code !== 200 || !res.data || !res.data.url) {
                    $('.uc-ticket-proof__drop').removeClass('is-uploading').prop('disabled', false);
                    message.error(res && res.msg ? res.msg : '购买凭证上传失败');
                    return;
                }
                setProof(res.data.url, state.proofName, res.data.upload_id);
            },
            error: () => {
                $('.uc-ticket-proof__drop').removeClass('is-uploading').prop('disabled', false)
                    .find('.uc-ticket-proof__copy strong').text('上传购买凭证');
                message.error('上传失败，请检查网络后重试');
            }
        });
    }

    function editorContent() {
        if (!editor || !editor.cm) return '';
        const markdown = editor.cm.getValue().trim();
        if (!markdown) return '';
        return editor.getHTML();
    }

    function validationError() {
        const title = $('input[name="title"]').val().trim();
        const content = editorContent();
        if (state.type === 1) {
            if (state.orderMode === 'account' && !state.order) return '请选择需要售后支持的订单';
            if (state.orderMode === 'manual' && !$('input[name="trade_no"]').val().trim()) return '请输入需要售后支持的订单号';
            if (!state.proofPath) return '请上传购买凭证';
        }
        const titleLength = Array.from(title).length;
        if (titleLength < 4) return '工单标题至少需要 4 个字';
        if (titleLength > 100) return '工单标题不能超过 100 个字';
        if (!content) return '请填写问题详情';
        return '';
    }

    function submitTicket() {
        if (state.submitting) return;
        const error = validationError();
        if (error) {
            message.error(error);
            return;
        }
        state.submitting = true;
        const $button = $('.uc-ticket-submit');
        $button.prop('disabled', true).addClass('is-loading').find('span:last').text('正在提交…');
        util.post({
            url: '/user/api/ticket/create',
            data: {
                type: state.type,
                priority: state.priority,
                title: $('input[name="title"]').val().trim(),
                commodity_id: state.type === 0 && state.commodity ? state.commodity.id : '',
                order_id: state.type === 1 && state.orderMode === 'account' && state.order ? state.order.id : '',
                trade_no: state.type === 1 && state.orderMode === 'manual' ? $('input[name="trade_no"]').val().trim() : '',
                proof_upload_id: state.type === 1 ? state.proofId : '',
                proof_path: state.type === 1 ? state.proofPath : '',
                content: editorContent()
            },
            done: res => {
                message.success(`工单 ${res.data && res.data.ticket_no ? res.data.ticket_no : ''} 已创建`);
                const target = res.data && res.data.url ? res.data.url : `/user/ticket/detail?id=${encodeURIComponent(res.data.id)}`;
                setTimeout(() => { window.location.href = target; }, 650);
            },
            error: res => {
                state.submitting = false;
                $button.prop('disabled', false).removeClass('is-loading').find('span:last').text('提交工单');
                message.error(res && res.msg ? res.msg : '工单提交失败');
            },
            fail: () => {
                state.submitting = false;
                $button.prop('disabled', false).removeClass('is-loading').find('span:last').text('提交工单');
                message.error('网络连接失败，请稍后重试');
            }
        });
    }

    $('.uc-ticket-type').on('click', function () { switchType($(this).data('ticket-type')); });
    $('[data-order-mode]').on('click', function () { switchOrderMode($(this).data('order-mode')); });
    $('[data-priority]').on('click', function () { setPriority($(this).data('priority')); });
    $('input[name="trade_no"]').on('input', updateSummary);
    $('input[name="title"]').on('input', function () { $('.uc-ticket-char-count i').text(Array.from(this.value).length); });
    $('.uc-ticket-proof__remove').on('click', () => setProof('', ''));
    $('.uc-ticket-submit').on('click', submitTicket);

    initRemoteSelect('#ticket-commodity', '/user/api/ticket/commodityOptions', 'commodity', '搜索并选择相关商品（选填）');
    initRemoteSelect('#ticket-order', '/user/api/ticket/orderOptions', 'order', '搜索商品名称或订单号');
    initProofUpload();

    const $editor = $('#ticket-content-editor');
    if (window.EditorV2 && $editor.length) {
        $editor.html(EditorV2.buildHtml({
            name: 'content',
            placeholder: '请描述遇到的问题、出现问题的步骤，以及你希望得到的帮助…',
            allowHtmlSource: false,
            allowRawHtml: false
        }));
        editor = EditorV2.register($editor.get(0), {
            name: 'content',
            uploadUrl: '/user/api/ticket/upload',
            height: 340,
            allowHtmlSource: false,
            allowRawHtml: false
        });
    } else {
        $editor.html('<div class="uc-ticket-editor-error">编辑器加载失败，请刷新页面后再试。</div>');
    }

    switchType(0);
    switchOrderMode('account');
    setPriority(1);
}();
