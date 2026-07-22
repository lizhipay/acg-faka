!function () {
    let table;
    const namespace = '.mdManageController';
    let controllerActive = true;
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password
                ? url.href
                : '/favicon.ico';
        } catch (error) {
            return '/favicon.ico';
        }
    };

    if (typeof window.__mdManageDestroy === 'function') window.__mdManageDestroy();

    const modal = (title, assign = {}) => {
        if (!controllerActive) return;
        const isEdit = Number(assign.id || 0) > 0;
        let submitting = false;
        // Defensive even when an older API bundle is still cached: a stored
        // hash must never become the value submitted as a new plain password.
        const values = {...assign, password: ''};
        component.popup({
            submit: (data, index) => {
                if (!controllerActive || submitting) return;
                submitting = true;
                util.post({
                    url: '/admin/api/manage/save',
                    data: data,
                    done: res => {
                        if (!controllerActive) return;
                        if (index !== undefined && index !== null) layer.close(index);
                        message.success(res?.msg && res.msg !== 'success' ? res.msg : '管理员已保存');
                        if (res?.data?.reauthenticate) {
                            window.setTimeout(() => { window.location.href = '/admin/authentication/login'; }, 500);
                            return;
                        }
                        table.refresh();
                    },
                    error: res => {
                        submitting = false;
                        if (controllerActive) message.error(res?.msg || '管理员保存失败，请检查填写内容。');
                    },
                    fail: () => {
                        submitting = false;
                        if (controllerActive) message.error('网络异常，管理员资料未保存。');
                    }
                });
            },
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "头像", name: "avatar", type: "image", uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get', placeholder: "请选择图片", width: 100
                        },
                        {title: "Email", name: "email", type: "input", placeholder: "请输入邮箱", required: true, regex: {value: '^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$', message: '请输入正确的邮箱地址'}},
                        {title: "昵称", name: "nickname", type: "input", placeholder: "请输入昵称", required: true},
                        {title: "密码", name: "password", type: "password", placeholder: isEdit ? "不修改请留空" : "请输入至少 6 位密码", required: !isEdit, regex: {value: '^.{6,}$', message: '密码不能少于 6 位'}},
                        {
                            title: "类型", name: "type", type: "radio", dict: "_manage_type", default: 1
                        },
                        {title: "备注", name: "note", type: "input", placeholder: "备注信息"},
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                    ]
                }
            ],
            assign: values,
            autoPosition: true,
            height: "auto",
            width: "680px",
            renderComplete: unique => {
                const $form = $('.' + unique);
                $form.find('input[name="avatar"]').attr({
                    inputmode: 'url',
                    autocomplete: 'off',
                    autocapitalize: 'none',
                    spellcheck: 'false',
                    maxlength: '2048'
                });
                $form.find('input[name="email"]').attr({inputmode: 'email', autocomplete: 'email', autocapitalize: 'none', spellcheck: 'false', maxlength: '64'});
                $form.find('input[name="nickname"]').attr({autocomplete: 'off', maxlength: '32'});
                $form.find('input[name="password"]').attr({autocomplete: 'new-password', maxlength: '256'});
                $form.find('input[name="note"]').attr({autocomplete: 'off', maxlength: '255'});
            }
        });
    }

    table = new Table("/admin/api/manage/data", "#manage-table");
    table.setColumns([
        {
            field: 'avatar', title: '管理员', formatter: (val, item) => {
                const avatar = escapeHtml(safeImageUrl(item.avatar));
                const displayName = item.nickname || item.email || ('管理员 #' + (item.id || '-'));
                return `<div class="md-user-cell"><img src="${avatar}" class="md-user-cell__avatar" alt="">` +
                    `<div class="md-user-cell__text"><span class="md-user-cell__name">${escapeHtml(displayName)}</span>` +
                    `<span class="md-user-cell__sub">${escapeHtml(item.email)}</span></div></div>`;
            }
        }
        , {
            field: 'status', title: '状态', formatter: function (val, item) {
                if (item.status == 1) {
                    return format.badge("正常", "a-badge-success");
                }
                return format.badge("禁用", "a-badge-danger");
            }
        }
        , {
            field: 'type', title: '类型', dict: "_manage_type"
        }
        , {field: 'note', title: '备注', formatter: value => escapeHtml(value || '-')}
        , {field: 'create_time', title: '创建时间', formatter: value => escapeHtml(value || '-')}
        , {field: 'login_time', title: '登录时间', formatter: value => escapeHtml(value || '-')}
        , {field: 'login_ip', title: '登录IP', formatter: value => escapeHtml(value || '-')}
        , {field: 'last_login_time', title: '上次登录时间', formatter: value => escapeHtml(value || '-')}
        , {field: 'last_login_ip', title: '上次登录IP', formatter: value => escapeHtml(value || '-')},

        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    show: item => Number(item.type) !== 0,
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + " 修改管理员", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    show: item => Number(item.type) !== 0,
                    click: (event, value, row, index) => {
                        message.ask(`<div style="text-align:left;line-height:1.8"><div><b>管理员：</b>${escapeHtml(row.nickname || row.email || ('ID ' + row.id))}</div><div style="margin-top:8px;color:#d14343">删除后该账号将无法登录，此操作不可撤销。</div></div>`, () => {
                            util.post('/admin/api/manage/del', {list: [row.id]}, res => {
                                if (!controllerActive) return;
                                message.success("删除成功");
                                table.refresh();
                            });
                        }, '确认删除管理员？', '确认删除');
                    }
                }
            ]
        }
    ]);

    table.setSearch([
        {title: "管理员邮箱", name: "search-email", type: "input"},
        {title: "管理员昵称", name: "search-nickname", type: "input"},
        {title: "账号类型", name: "equal-type", type: "select", dict: "_manage_type"},
        {title: "登录 IP", name: "search-login_ip", type: "input"},
        {title: "创建时间", name: "between-create_time", type: "date"}
    ]);
    table.setPagination(15, [15, 30, 50, 100]);
    table.setState("status", "_common_status");
    table.render();

    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 创建管理员`);
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdManageDestroy === destroy) delete window.__mdManageDestroy;
    }

    window.__mdManageDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
