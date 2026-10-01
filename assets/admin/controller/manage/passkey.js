!function () {
    const listEl = document.getElementById('passkey-list');
    const addBtn = document.getElementById('passkey-add-btn');
    const hint = document.getElementById('passkey-hint');
    if (!addBtn) {
        return;
    }

    const supported = !!(window.AdminWebAuthn && AdminWebAuthn.supported());
    if (!supported) {
        addBtn.disabled = true;
        if (hint) {
            hint.style.display = 'block';
            hint.textContent = i18n('当前环境不支持通行密钥（需 HTTPS 安全环境）');
        }
    }

    function postP(url, data) {
        return new Promise((resolve, reject) => {
            util.post({
                url: url,
                data: data || {},
                loader: false,
                done: r => resolve(r && r.data ? r.data : {}),
                error: r => reject(r || {}),
                fail: () => reject({msg: i18n('网络错误')})
            });
        });
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function render(list) {
        if (!listEl) {
            return;
        }
        if (!list || !list.length) {
            listEl.innerHTML = '<div class="pk-empty text-muted fs-7">' + i18n('尚未添加通行密钥') + '</div>';
            return;
        }
        let html = '';
        list.forEach(c => {
            const used = c.last_used_relative ? (i18n('最近使用') + '：' + esc(c.last_used_relative)) : i18n('从未使用');
            html += '<div class="pk-item" data-id="' + c.id + '">'
                + '<span class="pk-item__ico"><span class="material-icons-outlined">fingerprint</span></span>'
                + '<div class="pk-item__main">'
                + '<div class="pk-item__name pk-name">' + esc(c.name) + '</div>'
                + '<div class="pk-item__meta">' + esc(c.created_relative) + ' · ' + used + '</div>'
                + '</div>'
                + '<div class="pk-item__acts">'
                + '<button type="button" class="btn btn-sm btn-icon btn-active-color-primary pk-rename" title="' + i18n('重命名') + '"><span class="material-icons-outlined">edit</span></button>'
                + '<button type="button" class="btn btn-sm btn-icon btn-active-color-danger pk-del" title="' + i18n('删除') + '"><span class="material-icons-outlined">delete</span></button>'
                + '</div>'
                + '</div>';
        });
        listEl.innerHTML = html;
    }

    function load() {
        postP('/admin/api/manage/passkeyList').then(d => render(d.list || [])).catch(() => {
        });
    }

    async function add() {
        if (!supported) {
            return;
        }
        const res = await message.prompt({
            title: i18n('添加通行密钥'),
            text: i18n('给这个设备起个名字，方便区分'),
            input: 'text',
            inputValue: '',
            inputPlaceholder: i18n('例如：我的 MacBook')
        });
        if (!res || !res.isConfirmed) {
            return;
        }
        // 新增通行密钥须先验证当前密码（服务端强制），避免离座/XSS 时被静默植入后门。
        const pw = await message.prompt({
            title: i18n('添加通行密钥'),
            text: i18n('请输入你的登录密码'),
            input: 'password',
            inputValue: '',
            inputPlaceholder: i18n('请输入你的登录密码')
        });
        if (!pw || !pw.isConfirmed) {
            return;
        }
        try {
            const options = await postP('/admin/api/manage/passkeyRegisterOptions', {password: pw.value || ''});
            const cred = await AdminWebAuthn.create(options);
            cred.name = (res.value && String(res.value).trim()) ? String(res.value).trim() : '通行密钥';
            await postP('/admin/api/manage/passkeyRegister', cred);
            message.success(i18n('通行密钥添加成功'));
            load();
        } catch (e) {
            const msg = (e && e.msg) ? e.msg : (window.AdminWebAuthn ? AdminWebAuthn.errorText(e) : i18n('添加失败'));
            message.error(msg);
        }
    }

    addBtn.addEventListener('click', add);

    if (listEl) {
        listEl.addEventListener('click', async (e) => {
            const row = e.target.closest('[data-id]');
            if (!row) {
                return;
            }
            const id = parseInt(row.getAttribute('data-id'), 10);
            if (e.target.closest('.pk-del')) {
                message.ask(i18n('确定删除这个通行密钥？删除后该设备将无法用它登录。'), () => {
                    postP('/admin/api/manage/passkeyDelete', {id: id})
                        .then(load)
                        .catch(err => message.error((err && err.msg) || i18n('删除失败')));
                }, i18n('删除通行密钥'), i18n('删除'));
            } else if (e.target.closest('.pk-rename')) {
                const current = row.querySelector('.pk-name') ? row.querySelector('.pk-name').textContent : '';
                message.prompt({
                    title: i18n('重命名通行密钥'),
                    input: 'text',
                    inputValue: current
                }).then(res => {
                    if (!res || !res.isConfirmed) {
                        return;
                    }
                    postP('/admin/api/manage/passkeyRename', {id: id, name: res.value})
                        .then(load)
                        .catch(err => message.error((err && err.msg) || i18n('重命名失败')));
                });
            }
        });
    }

    load();
}();
