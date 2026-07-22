!function () {
    'use strict';

    const $tableElement = $('#message-table');
    if (!$tableElement.length) return;

    const emailEnabled = String($('.md-message-page').attr('data-email-enabled') || '0') === '1';

    const audienceOptions = [
        {id: 0, name: '全体用户'},
        {id: 1, name: '会员等级'},
        {id: 2, name: '指定用户'}
    ];
    const sendMessageIcon = '<svg class="md-message-send-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 6.5h10a2 2 0 0 1 2 2v2.25M3.5 7l5 4 5-4M3.5 6.5v9a2 2 0 0 0 2 2h7.25M14 14h6m-2.5-2.5L20 14l-2.5 2.5"/></svg>';

    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const inputMeta = (name, attributes) => form => {
        $('.' + form.unique + ' input[name="' + name + '"]').attr(attributes);
    };

    const positiveInt = value => {
        const number = Number(value);
        return Number.isInteger(number) && number > 0 ? number : 0;
    };

    const recipientCount = row => Math.max(0, Number(row?.recipient_count ?? row?.audience_count ?? 0) || 0);

    const audienceTypeName = type => {
        const item = audienceOptions.find(option => Number(option.id) === Number(type));
        return item ? item.name : '未知范围';
    };

    const audienceName = row => {
        const type = Number(row?.audience_type);
        if (type === 0) return '全部正常会员';
        return String(row?.audience_name || (type === 1 ? '指定会员等级' : '指定会员'));
    };

    const detailObject = payload => {
        const data = payload?.message || payload?.detail || payload || {};
        return typeof data === 'object' && !Array.isArray(data) ? data : {};
    };

    const openMessagePreview = id => {
        id = positiveInt(id);
        if (!id || !pageActive) return;
        util.post('/admin/api/message/detail', {id: id}, response => {
            if (!pageActive) return;
            const item = detailObject(response.data);
            if (!positiveInt(item.id)) {
                message.error('消息详情读取失败');
                return;
            }
            if (typeof component.previewMessage !== 'function') {
                message.error('消息预览组件尚未加载');
                return;
            }
            component.previewMessage(item);
        });
    };

    const contentIsEmpty = value => {
        const template = document.createElement('template');
        template.innerHTML = String(value || '');
        return template.content.textContent.trim() === '' && !template.content.querySelector('img,table');
    };

    const utf8Length = value => {
        value = String(value || '');
        if (window.TextEncoder) return new TextEncoder().encode(value).length;
        return unescape(encodeURIComponent(value)).length;
    };

    const validJumpUrl = value => {
        const url = String(value || '').trim();
        if (!url) return true;
        if (/[\\\u0000-\u001f\u007f]/.test(url) || url.startsWith('//')) return false;
        if (url.startsWith('/')) return true;
        try {
            return ['http:', 'https:'].includes(new URL(url).protocol);
        } catch (error) {
            return false;
        }
    };

    const normalizeUsers = payload => {
        const source = payload?.list || payload?.rows || (Array.isArray(payload) ? payload : []);
        return source.reduce((items, user) => {
            const id = positiveInt(user?.id);
            if (!id) return items;
            const username = String(user?.username || ('用户 #' + id));
            const group = String(user?.group_name || '').trim();
            items.push({
                value: id,
                name: escapeHtml(username + ' · ID ' + id + (group ? ' · ' + group : ''))
            });
            return items;
        }, []);
    };

    let table = null;
    let pageActive = true;
    let composeGeneration = 0;

    const renderAudienceSummary = (form, state) => {
        const $container = form.getCustomDom('audience_preview');
        if (!$container.length) return;

        let countText = '请选择接收范围';
        let countClass = '';
        if (state.loading) {
            countText = '正在统计…';
            countClass = ' is-loading';
        } else if (state.count !== null) {
            countText = Number(state.count).toLocaleString('zh-CN') + ' 人';
            countClass = state.count > 0 ? ' is-ready' : ' is-empty';
        } else if (state.error) {
            countText = state.error;
            countClass = ' is-empty';
        }

        const type = Number(state.type);
        const description = type === 0
            ? '发送时快照全部正常会员，后续新注册会员不会收到。'
            : (type === 1
                ? '发送时快照该等级的正常会员，后续等级变化不会补发。'
                : '仅发送给当前选中的单一正常会员。');

        $container.html(
            '<div class="md-message-audience-summary' + countClass + '">' +
            '<span class="md-message-audience-summary__icon"><i class="fa-duotone fa-regular fa-users"></i></span>' +
            '<div><span>预计接收</span><strong>' + escapeHtml(countText) + '</strong><p>' + escapeHtml(description) + '</p></div>' +
            '</div>'
        );
    };

    const requestAudienceCount = (form, state, type, audienceId) => {
        type = Number(type);
        audienceId = positiveInt(audienceId);
        const requestNumber = ++state.countRequest;
        state.type = type;
        state.audienceId = audienceId;
        state.error = '';

        if (type === 2) {
            state.loading = false;
            state.count = audienceId ? 1 : null;
            renderAudienceSummary(form, state);
            return;
        }

        if (type === 1 && !audienceId) {
            state.loading = false;
            state.count = null;
            renderAudienceSummary(form, state);
            return;
        }

        state.loading = true;
        state.count = null;
        renderAudienceSummary(form, state);

        util.post({
            loader: false,
            url: '/admin/api/message/audienceCount',
            data: {audience_type: type, audience_id: audienceId},
            done: response => {
                if (!pageActive || !state.active || requestNumber !== state.countRequest) return;
                const payload = response.data || {};
                state.loading = false;
                state.count = Math.max(0, Number(payload.count ?? payload.audience_count ?? 0) || 0);
                renderAudienceSummary(form, state);
            },
            error: response => {
                if (!pageActive || !state.active || requestNumber !== state.countRequest) return;
                state.loading = false;
                state.count = null;
                state.error = response?.msg || '统计失败，请重试';
                renderAudienceSummary(form, state);
            },
            fail: () => {
                if (!pageActive || !state.active || requestNumber !== state.countRequest) return;
                state.loading = false;
                state.count = null;
                state.error = '网络异常，请重试';
                renderAudienceSummary(form, state);
            }
        });
    };

    const updateAudienceFields = (form, state, type) => {
        if (!state.active) return;
        type = Number(type);
        state.type = type;
        form.hide('audience_group_id');
        form.hide('audience_user_picker');

        if (type === 1) {
            form.show('audience_group_id');
            requestAudienceCount(form, state, type, state.groupId);
            return;
        }
        if (type === 2) {
            form.show('audience_user_picker');
            requestAudienceCount(form, state, type, state.userId);
            return;
        }
        requestAudienceCount(form, state, 0, 0);
    };

    const registerUserPicker = (form, state, $container) => {
        if (!window.xmSelect) {
            $container.html('<div class="md-message-picker-error">会员选择组件加载失败</div>');
            return;
        }

        let searchRequest = 0;
        state.userPicker = xmSelect.render({
            el: $container.get(0),
            name: 'audience_user_id',
            radio: true,
            clickClose: true,
            filterable: true,
            remoteSearch: true,
            delay: 350,
            autoRow: true,
            language: 'zn',
            tips: '输入会员名或 ID 搜索',
            searchTips: '输入会员名或 ID 搜索',
            empty: '没有匹配的正常会员',
            remoteMethod: (keyword, callback) => {
                keyword = String(keyword || '').trim();
                const currentRequest = ++searchRequest;
                if (!keyword) {
                    callback([]);
                    return;
                }
                util.post({
                    loader: false,
                    url: '/admin/api/message/users',
                    data: {keywords: keyword, keyword: keyword, limit: 20},
                    done: response => {
                        if (!pageActive || !state.active || currentRequest !== searchRequest) return;
                        callback(normalizeUsers(response.data));
                    },
                    error: response => {
                        if (!pageActive || !state.active || currentRequest !== searchRequest) return;
                        callback([]);
                        message.error(response?.msg || '会员搜索失败');
                    },
                    fail: () => {
                        if (!pageActive || !state.active || currentRequest !== searchRequest) return;
                        callback([]);
                        message.error('会员搜索失败，请检查网络');
                    }
                });
            },
            on: selection => {
                const selected = selection.arr || [];
                state.userId = positiveInt(selected[0]?.value);
                if (Number(state.type) === 2) {
                    requestAudienceCount(form, state, 2, state.userId);
                }
            }
        });
        return state.userPicker;
    };

    const validateCompose = (formData, editing, state, messageId) => {
        const title = String(formData.title || '').trim();
        const content = String(formData.content || '');
        const jumpUrl = String(formData.jump_url || '').trim();

        if (!title) {
            layer.msg('请输入消息标题');
            return null;
        }
        if (Array.from(title).length > 100) {
            layer.msg('消息标题不能超过 100 个字');
            return null;
        }
        if (contentIsEmpty(content)) {
            layer.msg('请输入消息内容');
            return null;
        }
        if (utf8Length(content) > 100 * 1024) {
            layer.msg('消息内容不能超过 100KB');
            return null;
        }

        const contentTemplate = document.createElement('template');
        contentTemplate.innerHTML = content;
        if (contentTemplate.content.querySelectorAll('img').length > 8) {
            layer.msg('一条消息最多插入 8 张图片');
            return null;
        }
        if (!validJumpUrl(jumpUrl)) {
            layer.msg('跳转地址只支持站内 / 开头地址或 HTTP(S) 地址');
            return null;
        }

        const payload = {title: title, content: content, jump_url: jumpUrl};
        if (editing) {
            payload.id = positiveInt(messageId);
            return payload;
        }

        if (emailEnabled) {
            payload.send_email = Number(formData.send_email) === 1 ? 1 : 0;
        }

        const type = Number(formData.audience_type);
        if (![0, 1, 2].includes(type)) {
            layer.msg('请选择接收范围');
            return null;
        }

        let audienceId = 0;
        if (type === 1) audienceId = positiveInt(formData.audience_group_id);
        if (type === 2) audienceId = positiveInt(formData.audience_user_id || state.userId);
        if (type > 0 && !audienceId) {
            layer.msg(type === 1 ? '请选择会员等级' : '请选择指定会员');
            return null;
        }
        if (state.loading) {
            layer.msg('正在统计接收人数，请稍候');
            return null;
        }
        if (state.count === null) {
            layer.msg('请先确认接收人数');
            return null;
        }
        if (state.count < 1) {
            layer.msg('当前接收范围内没有可发送的正常会员');
            return null;
        }
        if (Number(state.type) !== type || positiveInt(state.audienceId) !== audienceId) {
            layer.msg('接收范围已变化，请等待人数重新统计');
            return null;
        }

        payload.audience_type = type;
        payload.audience_id = audienceId;
        return payload;
    };

    const setSubmitBusy = (layerIndex, busy) => {
        const $button = $('#layui-layer' + layerIndex).find('.layui-layer-btn0');
        $button.toggleClass('is-loading', busy)
            .attr('aria-disabled', busy ? 'true' : 'false')
            .css('pointer-events', busy ? 'none' : '');
    };

    const openComposer = item => {
        if (!pageActive) return;
        const editing = positiveInt(item?.id) > 0;
        const generation = ++composeGeneration;
        const state = {
            active: true,
            type: 0,
            groupId: 0,
            userId: 0,
            count: null,
            loading: false,
            error: '',
            countRequest: 0,
            userPicker: null,
            saving: false,
            confirming: false
        };

        const audienceFields = editing ? [
            {
                title: '接收范围', name: 'audience_snapshot', type: 'custom', submit: false,
                complete: (form, $container) => {
                    const type = Number(item.audience_type);
                    $container.html(
                        '<div class="md-message-audience-readonly">' +
                        '<span class="md-message-audience-readonly__icon"><i class="fa-duotone fa-regular fa-lock"></i></span>' +
                        '<div><strong>' + escapeHtml(audienceTypeName(type)) + ' · ' + escapeHtml(audienceName(item)) + '</strong>' +
                        '<span>已投递 ' + recipientCount(item).toLocaleString('zh-CN') + ' 人，编辑不会改变接收人和已读状态。</span></div>' +
                        '</div>'
                    );
                }
            }
        ] : [
            {
                title: '接收范围', name: 'audience_type', type: 'radio', dict: audienceOptions, default: 0,
                required: true,
                change: (form, value) => updateAudienceFields(form, state, value),
                complete: (form, value) => updateAudienceFields(form, state, value)
            },
            {
                title: '会员等级', name: 'audience_group_id', type: 'select', dict: 'user_group,id,name',
                placeholder: '请选择会员等级', hide: true,
                change: (form, value) => {
                    state.groupId = positiveInt(value);
                    if (Number(state.type) === 1) requestAudienceCount(form, state, 1, state.groupId);
                }
            },
            {
                title: '指定会员', name: 'audience_user_picker', type: 'custom', hide: true, submit: false,
                complete: (form, $container) => registerUserPicker(form, state, $container)
            },
            {
                title: false, name: 'audience_preview', type: 'custom', submit: false,
                complete: form => renderAudienceSummary(form, state)
            }
        ];

        const messageFields = [
            {
                title: '消息标题', name: 'title', type: 'input', required: true,
                placeholder: '请输入消息标题，最多 100 字',
                inputmode: 'text', enterkeyhint: 'next',
                complete: inputMeta('title', {autocomplete: 'off'})
            },
            {
                title: '消息内容', name: 'content', type: 'editorv2', required: true,
                placeholder: '支持 Markdown 与图片，最多 8 张图片',
                uploadUrl: '/admin/api/message/upload',
                height: 300,
                allowHtmlSource: false,
                allowRawHtml: false
            },
            {
                title: '点击跳转地址', name: 'jump_url', type: 'input',
                placeholder: '可选，例如 /user/purchase/record 或 https://example.com',
                inputmode: 'url', enterkeyhint: 'done',
                complete: inputMeta('jump_url', {inputmode: 'url', autocomplete: 'url', autocapitalize: 'none', spellcheck: 'false'})
            }
        ];

        if (!editing && emailEnabled) {
            messageFields.push({
                title: '发送邮件通知', name: 'send_email', type: 'switch', default: 0,
                placeholder: '发送|不发送',
                tips: '仅向本次接收用户中已绑定有效邮箱的会员发送，邮件失败不会影响站内消息。'
            });
        }

        messageFields.push(
            {
                title: false, name: 'compose_tip', type: 'custom', submit: false,
                complete: (form, $container) => {
                    $container.html('<div class="md-message-compose-tip"><i class="fa-duotone fa-regular fa-shield-check"></i><span>内容会在服务端再次安全过滤；设置跳转地址后，消息弹窗才会显示“前往地址”。</span></div>');
                }
            }
        );

        const formFields = audienceFields.concat(messageFields);

        const assign = editing ? {
            id: positiveInt(item.id),
            title: String(item.title || ''),
            content: String(item.content || ''),
            jump_url: String(item.jump_url || '')
        } : {};

        component.popup({
            width: '780px',
            height: 'min(980px, calc(100vh - 48px))',
            autoPosition: false,
            maxmin: false,
            confirmText: editing ? '保存修改' : sendMessageIcon + '确认发送',
            tab: [{
                name: editing
                    ? util.icon('fa-duotone fa-regular fa-pen-to-square me-1') + '编辑消息'
                    : sendMessageIcon + '创建消息',
                form: formFields
            }],
            assign: assign,
            submit: (formData, layerIndex) => {
                if (generation !== composeGeneration) return;
                const payload = validateCompose(formData, editing, state, item?.id);
                if (!payload) return;

                const save = () => {
                    if (state.saving) return;
                    state.saving = true;
                    setSubmitBusy(layerIndex, true);
                    util.post({
                        url: '/admin/api/message/save',
                        data: payload,
                        done: response => {
                            if (!pageActive) return;
                            layer.close(layerIndex);
                            const email = response?.data?.email;
                            if (!editing && email?.enabled) {
                                const sent = Math.max(0, Number(email.sent) || 0);
                                const failed = Math.max(0, Number(email.failed) || 0);
                                const skipped = Math.max(0, Number(email.skipped) || 0);
                                message.success('站内消息已发送；邮件成功 ' + sent + ' 封、失败 ' + failed + ' 封，' + skipped + ' 人未绑定有效邮箱');
                            } else {
                                message.success(response.msg || (editing ? '消息已更新' : '消息已发送'));
                            }
                            table.refresh(false);
                        },
                        error: response => {
                            if (!pageActive) return;
                            state.saving = false;
                            setSubmitBusy(layerIndex, false);
                            message.error(response?.msg || '保存失败');
                        },
                        fail: () => {
                            if (!pageActive) return;
                            state.saving = false;
                            setSubmitBusy(layerIndex, false);
                            message.error('保存失败，请检查网络');
                        }
                    });
                };

                if (!editing && Number(payload.audience_type) < 2) {
                    if (state.confirming) return;
                    state.confirming = true;
                    setSubmitBusy(layerIndex, true);
                    Swal.fire({
                        title: '确认发送消息？',
                        html: '将向当前范围内的 <b>' + Number(state.count).toLocaleString('zh-CN') + '</b> 名会员发送，发送后接收范围不可修改。' +
                            (Number(payload.send_email) === 1 ? '<br><span style="display:inline-block;margin-top:8px">同时向其中已绑定有效邮箱的会员发送邮件通知。</span>' : ''),
                        icon: 'warning',
                        showCancelButton: true,
                        cancelButtonText: '取消',
                        confirmButtonText: sendMessageIcon + '确认发送'
                    }).then(result => {
                        state.confirming = false;
                        if (result.isConfirmed || result.value) {
                            save();
                            return;
                        }
                        setSubmitBusy(layerIndex, false);
                    });
                    return;
                }
                save();
            },
            renderComplete: unique => {
                $('.component-popup.' + unique).addClass('md-message-compose-layer');
            },
            end: () => {
                state.active = false;
                state.countRequest++;
                if (generation === composeGeneration) composeGeneration++;
            }
        });
    };

    const loadAndEdit = id => {
        id = positiveInt(id);
        if (!id || !pageActive) return;
        util.post('/admin/api/message/detail', {id: id}, response => {
            if (!pageActive) return;
            const item = detailObject(response.data);
            if (!positiveInt(item.id)) {
                message.error('消息详情读取失败');
                return;
            }
            openComposer(item);
        });
    };

    const removeMessage = id => {
        id = positiveInt(id);
        if (!id || !pageActive) return;
        message.ask('删除后消息会从所有会员的消息中心永久消失，且无法恢复。确认删除吗？', () => {
            if (!pageActive) return;
            util.post('/admin/api/message/del', {id: id}, response => {
                if (!pageActive) return;
                message.success(response.msg || '消息已永久删除');
                table.refresh(false);
            });
        });
    };

    table = new Table('/admin/api/message/data', '#message-table');
    table.setPagination(10, [10, 20, 50, 100]);
    table.setColumns([
        {
            field: 'title', title: '消息', formatter: (value, row) => {
                const id = positiveInt(row.id);
                const summary = String(row.summary || '').trim() || '暂无消息摘要';
                return '<div class="md-message-title-cell" role="button" tabindex="0" data-message-id="' + id + '" aria-label="查看消息">' +
                    '<strong>' + escapeHtml(value || '未命名消息') + '</strong>' +
                    '<span>' + escapeHtml(summary) + '</span></div>';
            },
            events: {
                'click .md-message-title-cell': (event, value, row) => openMessagePreview(row.id),
                'keydown .md-message-title-cell': (event, value, row) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openMessagePreview(row.id);
                    }
                }
            }
        },
        {
            field: 'audience_type', title: '接收范围', formatter: (value, row) => {
                const type = Number(value);
                const modifier = [0, 1, 2].includes(type) ? ' md-message-audience--' + type : '';
                return '<div class="md-message-audience' + modifier + '"><span>' + escapeHtml(audienceTypeName(type)) + '</span>' +
                    '<strong>' + escapeHtml(audienceName(row)) + '</strong></div>';
            }
        },
        {
            field: 'recipient_count', title: '接收人数', formatter: (value, row) => {
                return '<span class="md-message-count"><strong>' + recipientCount(row).toLocaleString('zh-CN') + '</strong> 人</span>';
            }
        },
        {
            field: 'jump_url', title: '跳转', formatter: value => value
                ? '<span class="md-message-jump is-set"><i class="fa-duotone fa-regular fa-arrow-up-right-from-square"></i>已设置</span>'
                : '<span class="md-message-jump"><i class="fa-duotone fa-regular fa-minus"></i>无跳转</span>'
        },
        {
            field: 'create_time', title: '发送与更新', formatter: (value, row) => {
                const createdBy = row.manage_name ? ' · ' + escapeHtml(row.manage_name) : '';
                const updatedBy = row.update_manage_name ? ' · ' + escapeHtml(row.update_manage_name) : '';
                return '<div class="md-message-time"><span>' + sendMessageIcon + escapeHtml(value || '-') + createdBy + '</span>' +
                    '<span><i class="fa-duotone fa-regular fa-pen"></i>' + escapeHtml(row.update_time || value || '-') + updatedBy + '</span></div>';
            }
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-eye text-primary', title: '查看',
                    click: (event, value, row) => openMessagePreview(row.id)
                },
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square text-warning', title: '编辑',
                    click: (event, value, row) => loadAndEdit(row.id)
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger', title: '删除',
                    click: (event, value, row) => removeMessage(row.id)
                }
            ]
        }
    ]);
    table.setSearch([
        {title: '消息标题', name: 'keyword', type: 'input', inputmode: 'search', enterkeyhint: 'search'},
        {title: '接收范围', name: 'equal-audience_type', type: 'select', dict: audienceOptions},
        {title: '发送时间', name: 'between-create_time', type: 'date'}
    ]);
    table.render();

    $('.btn-message-create').off('click.messageAdmin').on('click.messageAdmin', () => openComposer(null));
    $(document)
        .off('pjax:beforeReplace.mdMessageController')
        .one('pjax:beforeReplace.mdMessageController', () => {
            pageActive = false;
            composeGeneration++;
            $('.btn-message-create').off('click.messageAdmin');
            if (typeof Swal !== 'undefined') Swal.close();
        });
}();
