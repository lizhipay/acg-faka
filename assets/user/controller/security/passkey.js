/**
 * 会员通行密钥（passkey）管理页：列表、添加（先验账号密码）、就地重命名、删除。
 * 复用前台已加载的 util / message / i18n；依赖 /assets/user/js/webauthn.js（window.UserWebAuthn）。
 * 页面只需保留 #pk-app 及其内部各 id，外壳与按钮样式由主题决定。
 */
!function () {
    const app = document.getElementById('pk-app');
    if (!app) return;

    const T = (s) => (typeof i18n === 'function' ? i18n(s) : s);
    const WA = window.UserWebAuthn;
    const supported = !!(WA && WA.supported());
    const $ = (id) => document.getElementById(id);
    // 会员中心是 pjax 换页：异步回调回来时页面可能已换走，别再碰旧 DOM
    const alive = () => app.isConnected;
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    if (!$('pk-style')) {
        const css = document.createElement('style');
        css.id = 'pk-style';
        css.textContent = `
        .pk-app{text-align:left}
        .pk-app [hidden]{display:none!important}
        .pk-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .pk-head__title{font-size:16px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px}
        .pk-count{font-size:12px;font-weight:500;color:var(--totp-muted,#8a8f99)}
        .pk-head__desc{color:var(--totp-muted,#8a8f99);font-size:13px;line-height:1.8;max-width:540px;text-wrap:pretty}
        .pk-head__ops{display:flex;gap:10px}
        .pk-head__ops .uc-cta{margin-top:0}
        :where(.pk-head__ops) button:disabled{opacity:.55;cursor:not-allowed;filter:none;transform:none}
        .pk-notice{display:flex;gap:10px;align-items:flex-start;border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:13px;line-height:1.75;background:rgba(245,166,35,.12);color:#a8740c;max-width:640px}
        .pk-notice .material-icons-outlined{font-size:19px;flex:0 0 auto;margin-top:1px}
        [data-theme="dark"] .pk-notice{color:#f0b54a}
        .pk-form{max-width:420px;max-height:0;opacity:0;overflow:hidden;transition:max-height .32s cubic-bezier(.4,0,.2,1),opacity .24s ease,margin-bottom .32s ease}
        .pk-form.is-open{max-height:480px;opacity:1;margin-bottom:20px}
        .pk-form.is-settled{overflow:visible;max-height:none}
        .pk-form__inner{border:1px solid rgba(125,125,135,.22);border-radius:14px;padding:18px 18px 16px;transform:translateY(-6px);transition:transform .32s cubic-bezier(.4,0,.2,1)}
        .pk-form.is-open .pk-form__inner{transform:none}
        .pk-form__title{font-size:14px;font-weight:600;margin-bottom:4px}
        .pk-form__desc{color:var(--totp-muted,#8a8f99);font-size:12.5px;line-height:1.7;margin-bottom:14px}
        .pk-actions{display:flex;flex-wrap:wrap;align-items:stretch;gap:12px;margin-top:18px}
        .pk-actions .uc-cta{margin-top:0}
        .pk-actions button:disabled{opacity:.6;cursor:progress}
        .pk-field{margin-bottom:14px}
        .pk-field label{display:block;font-size:13px;font-weight:500;margin-bottom:7px}
        .pk-field .layui-input{height:42px;line-height:42px;border-radius:10px}
        .pk-ghost{border:1px solid rgba(125,125,135,.32);background:transparent;border-radius:8px;padding:7px 16px;font-size:13px;cursor:pointer;color:inherit;transition:border-color .15s,color .15s}
        .pk-ghost:hover{border-color:var(--totp-accent,#5b7cfa);color:var(--totp-accent,#5b7cfa)}
        .pk-status{color:var(--totp-muted,#8a8f99);font-size:13px;padding:10px 0}
        .pk-list{display:flex;flex-direction:column;gap:12px}
        .pk-item{display:flex;align-items:center;gap:14px;border:1px solid rgba(125,125,135,.2);border-radius:14px;padding:13px 12px 13px 16px;transition:border-color .2s,background-color .2s}
        .pk-item:hover{border-color:rgba(125,125,135,.36)}
        .pk-item.is-new{animation:pk-new 2.4s ease-out}
        @keyframes pk-new{0%,35%{border-color:rgba(31,169,113,.6);background:rgba(31,169,113,.1)}}
        .pk-item__ico{width:42px;height:42px;flex:0 0 auto;border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(125,125,135,.14)}
        .pk-item__ico .material-icons-outlined{font-size:22px}
        .pk-item__main{flex:1 1 auto;min-width:0}
        .pk-item__name{font-weight:600;font-size:14px;line-height:1.5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .pk-item__meta{display:flex;flex-wrap:wrap;align-items:center;color:var(--totp-muted,#8a8f99);font-size:12px;margin-top:3px;line-height:1.7}
        .pk-seg{white-space:nowrap}
        .pk-dot{opacity:.55;margin:0 6px}
        .pk-item__acts{display:flex;gap:2px;flex:0 0 auto}
        .pk-icon{width:36px;height:36px;border-radius:10px;border:none;background:transparent;color:var(--totp-muted,#8a8f99);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;transition:background-color .15s,color .15s}
        .pk-icon .material-icons-outlined{font-size:20px}
        .pk-icon:hover{background:rgba(125,125,135,.14);color:inherit}
        .pk-icon.pk-del:hover{background:rgba(230,80,80,.1);color:#e05656}
        .pk-icon.pk-save:hover{background:rgba(31,169,113,.12);color:#1fa971}
        .pk-icon:focus-visible,.pk-ghost:focus-visible{outline:2px solid var(--totp-accent,#5b7cfa);outline-offset:1px}
        .pk-icon:disabled{opacity:.5;cursor:progress}
        .pk-item.is-renaming .pk-item__acts{display:none}
        .pk-item.is-renaming .pk-item__name{overflow:visible}
        .pk-rename{display:flex;align-items:center;gap:4px;max-width:340px}
        .pk-rename .pk-icon{flex:0 0 auto}
        .pk-rename .layui-input{flex:1 1 auto;min-width:0;width:100%;height:34px;line-height:34px;border-radius:8px;font-weight:500}
        .pk-empty{text-align:center;padding:32px 16px 28px;border:1px dashed rgba(125,125,135,.32);border-radius:16px}
        .pk-empty__art{width:64px;height:64px;margin:0 auto 14px;border-radius:20px;display:flex;align-items:center;justify-content:center;background:rgba(125,125,135,.12)}
        .pk-empty__art .material-icons-outlined{font-size:34px}
        .pk-empty__title{font-weight:600;font-size:15px;margin-bottom:6px}
        .pk-empty__desc{color:var(--totp-muted,#8a8f99);font-size:13px;line-height:1.8;max-width:420px;margin:0 auto;text-wrap:balance}
        .pk-tip{display:flex;gap:8px;align-items:flex-start;margin-top:18px;font-size:12.5px;line-height:1.7;color:var(--totp-muted,#8a8f99);max-width:640px}
        .pk-tip .material-icons-outlined{font-size:17px;color:#1fa971;flex:0 0 auto;margin-top:1px}
        @media(max-width:560px){.pk-head__ops{width:100%}.pk-head__ops button{flex:1 1 auto;justify-content:center}.pk-item{padding:12px 8px 12px 12px;gap:12px}.pk-icon{width:40px;height:40px}.pk-form{max-width:none}.pk-item__meta{column-gap:12px}.pk-dot{display:none}}
        @media(prefers-reduced-motion:reduce){.pk-form,.pk-form__inner{transition:none}.pk-item.is-new{animation:none}}
        `;
        document.head.appendChild(css);
    }

    const ICONS = {sync: 'cloud_done', platform: 'computer', security_key: 'usb', unknown: 'fingerprint'};

    const request = (url, data) => new Promise((resolve, reject) => util.post({
        url: url,
        data: data || {},
        loader: false,
        done: (res) => resolve(res || {}),
        error: (res) => reject(res || {}),
        fail: () => reject({msg: T('网络异常，请稍后重试')})
    }));

    // 主题可以省略的装饰性节点一律容错
    const setHidden = (id, hidden) => {
        const el = $(id);
        if (el) el.hidden = hidden;
    };
    const setStatus = (text) => {
        const el = $('pk-status');
        if (!el) return;
        el.style.display = text ? '' : 'none';
        el.textContent = text || '';
    };
    // 输入法选字时按的回车/Esc 不算提交
    const composing = (e) => e.isComposing || e.keyCode === 229;

    const required = ['pk-list', 'pk-add', 'pk-form', 'pk-pw', 'pk-go', 'pk-cancel'].filter((id) => !$(id));
    if (required.length) {
        console.warn('[passkey] 页面缺少必要节点：' + required.join(', '));
        return;
    }

    let items = [];
    let limit = 10;
    let formTimer = 0;
    let adding = false;
    let addCtl = null; // 进行中的添加流程，取消时中止

    function syncAddButton() {
        const btn = $('pk-add');
        const full = items.length >= limit;
        btn.disabled = !supported || full;
        btn.title = !supported ? T('当前环境不支持通行密钥') : (full ? T('通行密钥数量已达上限，请先删除不再使用的') : '');
    }

    function itemHtml(c) {
        const meta = [];
        if (c.provider && c.provider !== c.name) meta.push(esc(T(c.provider)));
        meta.push(esc(T('添加于')) + ' ' + esc(String(c.created_time || '-').slice(0, 10)));
        meta.push(c.last_used_relative ? esc(T('最近使用')) + ' ' + esc(c.last_used_relative) : esc(T('尚未使用')));
        const metaHtml = meta.map((m) => `<span class="pk-seg">${m}</span>`).join('<span class="pk-dot" aria-hidden="true">·</span>');
        return `<div class="pk-item" data-id="${Number(c.id)}">`
            + `<div class="pk-item__ico" aria-hidden="true"><span class="material-icons-outlined">${ICONS[c.kind] || ICONS.unknown}</span></div>`
            + `<div class="pk-item__main">`
            + `<div class="pk-item__name">${esc(c.name)}</div>`
            + `<div class="pk-item__meta">${metaHtml}</div>`
            + `</div>`
            + `<div class="pk-item__acts">`
            + `<button type="button" class="pk-icon pk-edit" title="${esc(T('重命名'))}" aria-label="${esc(T('重命名'))}：${esc(c.name)}"><span class="material-icons-outlined" aria-hidden="true">edit</span></button>`
            + `<button type="button" class="pk-icon pk-del" title="${esc(T('删除'))}" aria-label="${esc(T('删除'))}：${esc(c.name)}"><span class="material-icons-outlined" aria-hidden="true">delete_outline</span></button>`
            + `</div>`
            + `</div>`;
    }

    const rowOf = (id) => $('pk-list').querySelector(`.pk-item[data-id="${Number(id)}"]`);
    const focusEl = (el) => el && el.focus({preventScroll: false});

    function render(highlightId) {
        setStatus('');
        $('pk-list').innerHTML = items.map(itemHtml).join('');
        setHidden('pk-empty', items.length > 0);
        const count = $('pk-count');
        if (count) count.textContent = items.length ? `${items.length} / ${limit}` : '';
        if (highlightId) {
            const row = rowOf(highlightId);
            row && row.classList.add('is-new');
        }
        syncAddButton();
    }

    function load(highlightId) {
        return request('/user/api/security/passkeyList').then((res) => {
            if (!alive()) return;
            const d = res.data || {};
            if (!Array.isArray(d.list)) {
                setStatus(T('读取失败，请刷新重试'));
                return;
            }
            items = d.list;
            limit = Number(d.max) || limit;
            setHidden('pk-tip-2fa', !d.totp);
            render(highlightId);
            if (supported) {
                WA.signalAccepted(d.rp_id, d.user_handle, items.map((c) => c.credential_id));
            }
        }, (res) => {
            if (!alive()) return;
            setStatus(res.msg || T('读取失败，请刷新重试'));
        });
    }

    function openForm() {
        const form = $('pk-form');
        if (form.classList.contains('is-open')) {
            $('pk-pw').focus();
            return;
        }
        $('pk-pw').value = '';
        form.inert = false;
        form.classList.add('is-open');
        $('pk-add').setAttribute('aria-expanded', 'true');
        clearTimeout(formTimer);
        // 展开动画结束后放开溢出与高度，聚焦光圈才不会被裁掉、长文案也不会溢出
        formTimer = setTimeout(() => {
            if (!alive()) return;
            form.classList.add('is-settled');
            $('pk-pw').focus({preventScroll: true});
            // 手机上表单展开后「继续」常落在屏幕外或底部导航下面：整块卷进可视区（主题用 scroll-padding 让出固定栏）
            const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            form.scrollIntoView({block: 'nearest', behavior: reduce ? 'auto' : 'smooth'});
        }, 340);
    }

    function closeForm() {
        clearTimeout(formTimer);
        if (addCtl) {
            addCtl.abort();
            addCtl = null;
        }
        const form = $('pk-form');
        const hadFocus = form.contains(document.activeElement);
        form.classList.remove('is-settled', 'is-open');
        form.inert = true;
        $('pk-add').setAttribute('aria-expanded', 'false');
        $('pk-pw').value = '';
        if (hadFocus) focusEl($('pk-add'));
    }

    function setAdding(busy) {
        adding = busy;
        const go = $('pk-go');
        if (!go) return;
        go.disabled = busy;
        go.setAttribute('aria-busy', busy ? 'true' : 'false');
        const text = $('pk-go-text');
        if (text) text.textContent = busy ? T('请在设备上完成验证…') : T('继续');
    }

    async function add() {
        if (adding) return;
        const password = $('pk-pw').value;
        if (password === '') {
            message.error(T('请输入你的登录密码'));
            $('pk-pw').focus();
            return;
        }
        const ctl = new AbortController();
        addCtl = ctl;
        setAdding(true);
        try {
            const options = (await request('/user/api/security/passkeyRegisterOptions', {password: password})).data;
            if (ctl.signal.aborted) return;
            let credential;
            try {
                credential = await WA.create(options, {signal: ctl.signal});
            } catch (e) {
                if (ctl.signal.aborted || (e && e.name === 'AbortError')) return;
                if (e && e.name === 'NotAllowedError') {
                    message.info(T('未完成通行密钥验证'));
                } else {
                    message.error(WA.errorText(e, 'create'));
                }
                return;
            }
            if (ctl.signal.aborted) return;
            const res = await request('/user/api/security/passkeyRegister', credential);
            message.success(res.msg || T('通行密钥已添加'));
            if (!alive()) return;
            const newId = res.data && res.data.id;
            addCtl = null;
            closeForm();
            await load(newId);
            if (alive() && newId) {
                const row = rowOf(newId);
                focusEl(row && row.querySelector('.pk-edit'));
            }
        } catch (res) {
            if (!ctl.signal.aborted) message.error(res.msg || T('添加失败，请重试'));
        } finally {
            if (addCtl === ctl) addCtl = null;
            setAdding(false);
            // 「继续」在验证期间被停用，焦点会掉到页面最外层：失败/取消后还回表单（表单已收起就回到添加钮）
            if (alive() && (document.activeElement === document.body || document.activeElement === null)) {
                focusEl($('pk-form').classList.contains('is-open') ? $('pk-pw') : $('pk-add'));
            }
        }
    }

    function startRename(row) {
        const id = Number(row.dataset.id);
        const item = items.find((c) => c.id === id);
        if (!item || row.querySelector('.pk-rename')) return;
        const nameBox = row.querySelector('.pk-item__name');
        nameBox.innerHTML = `<div class="pk-rename">`
            + `<input type="text" class="layui-input" maxlength="32" value="${esc(item.name)}" aria-label="${esc(T('通行密钥名称'))}">`
            + `<button type="button" class="pk-icon pk-save" title="${esc(T('保存'))}" aria-label="${esc(T('保存'))}"><span class="material-icons-outlined" aria-hidden="true">check</span></button>`
            + `<button type="button" class="pk-icon pk-cancel" title="${esc(T('取消'))}" aria-label="${esc(T('取消'))}"><span class="material-icons-outlined" aria-hidden="true">close</span></button>`
            + `</div>`;
        row.classList.add('is-renaming');
        const input = nameBox.querySelector('input');
        input.focus();
        input.select();
    }

    function stopRename(row, refocus) {
        const item = items.find((c) => c.id === Number(row.dataset.id));
        row.querySelector('.pk-item__name').textContent = item ? item.name : '';
        row.classList.remove('is-renaming');
        delete row.dataset.busy;
        if (refocus) focusEl(row.querySelector('.pk-edit'));
    }

    function saveRename(row) {
        if (row.dataset.busy) return;
        const input = row.querySelector('.pk-rename input');
        const name = (input.value || '').trim();
        if (name === '') {
            message.error(T('请输入名称'));
            input.focus();
            return;
        }
        const item = items.find((c) => c.id === Number(row.dataset.id));
        if (item && item.name === name) {
            stopRename(row, true);
            return;
        }
        row.dataset.busy = '1';
        const controls = row.querySelectorAll('.pk-rename input, .pk-rename button');
        controls.forEach((el) => { el.disabled = true; });
        request('/user/api/security/passkeyRename', {id: Number(row.dataset.id), name: name}).then((res) => {
            if (item) item.name = (res.data && res.data.name) || name;
            message.success(res.msg || T('已重命名'));
            if (!alive()) return;
            stopRename(row, true);
            const label = item ? item.name : name;
            row.querySelector('.pk-edit').setAttribute('aria-label', T('重命名') + '：' + label);
            row.querySelector('.pk-del').setAttribute('aria-label', T('删除') + '：' + label);
        }, (res) => {
            message.error(res.msg || T('重命名失败'));
            if (!alive()) return;
            delete row.dataset.busy;
            controls.forEach((el) => { el.disabled = false; });
            input.focus();
            load(); // 可能已在别处删掉，重新同步列表
        });
    }

    function remove(row) {
        const id = Number(row.dataset.id);
        const item = items.find((c) => c.id === id);
        const next = row.nextElementSibling || row.previousElementSibling;
        const nextId = next ? next.dataset.id : null;
        const text = `<b>${esc(item ? item.name : '')}</b><br>${esc(T('删除后，这个通行密钥将不能再登录本站；设备上的副本可在系统的密码管理中一并移除。'))}`;
        message.ask(text, () => {
            request('/user/api/security/passkeyDelete', {id: id}).then((res) => {
                message.success(res.msg || T('已删除'));
                if (!alive()) return;
                load().then(() => {
                    if (!alive()) return;
                    const target = nextId && rowOf(nextId);
                    focusEl(target ? target.querySelector('.pk-edit') : $('pk-add'));
                });
            }, (res) => {
                message.error(res.msg || T('删除失败'));
                if (alive()) load();
            });
        }, T('删除通行密钥'), T('删除'), {focusCancel: true});
    }

    if (!supported) {
        setHidden('pk-unsupported', false);
    }
    $('pk-form').inert = !$('pk-form').classList.contains('is-open');
    syncAddButton();

    $('pk-add').addEventListener('click', openForm);
    $('pk-cancel').addEventListener('click', closeForm);
    $('pk-go').addEventListener('click', add);
    $('pk-pw').addEventListener('keydown', (e) => {
        if (composing(e)) return;
        if (e.key === 'Enter') {
            e.preventDefault();
            add();
        } else if (e.key === 'Escape') {
            e.stopPropagation();
            closeForm();
        }
    });

    $('pk-list').addEventListener('click', (e) => {
        const row = e.target.closest('.pk-item');
        if (!row) return;
        if (e.target.closest('.pk-edit')) startRename(row);
        else if (e.target.closest('.pk-del')) remove(row);
        else if (e.target.closest('.pk-save')) saveRename(row);
        else if (e.target.closest('.pk-cancel')) stopRename(row, true);
    });
    $('pk-list').addEventListener('keydown', (e) => {
        if (!e.target.matches('.pk-rename input') || composing(e)) return;
        const row = e.target.closest('.pk-item');
        if (e.key === 'Enter') {
            e.preventDefault();
            saveRename(row);
        } else if (e.key === 'Escape') {
            e.stopPropagation();
            if (!row.dataset.busy) stopRename(row, true);
        }
    });

    load();
}();
