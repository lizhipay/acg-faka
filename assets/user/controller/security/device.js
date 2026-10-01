/**
 * 会员登录设备（会话）管理：列出在线设备、逐台退出、退出其他、退出全部。
 * 复用前台已加载的 util / message / i18n。
 */
!function () {
    if (!document.getElementById('dev-app')) return;
    const T = (s) => (typeof i18n === 'function' ? i18n(s) : s);

    if (!document.getElementById('dev-style')) {
        const css = document.createElement('style');
        css.id = 'dev-style';
        css.textContent = `
        .dev-app{text-align:left}
        .dev-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .dev-head__title{font-size:16px;font-weight:600;margin-bottom:6px}
        .dev-head__desc{color:var(--totp-muted,#8a8f99);font-size:13px;line-height:1.8;max-width:520px}
        .dev-head__ops{display:flex;flex-wrap:wrap;gap:10px}
        .dev-status{color:var(--totp-muted,#8a8f99);font-size:13px;padding:8px 0}
        .dev-list{display:flex;flex-direction:column;gap:12px}
        .dev-item{display:flex;align-items:center;gap:14px;border:1px solid rgba(125,125,135,.2);border-radius:14px;padding:14px 16px}
        .dev-item.is-current{border-color:rgba(31,169,113,.5);background:rgba(31,169,113,.06)}
        .dev-item__ico{width:40px;height:40px;flex:0 0 auto;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(125,125,135,.14)}
        .dev-item__ico .material-icons-outlined{font-size:22px}
        .dev-item__main{flex:1 1 auto;min-width:0}
        .dev-item__name{font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .dev-item__badge{font-size:11px;color:#1fa971;border:1px solid rgba(31,169,113,.5);border-radius:999px;padding:1px 8px}
        .dev-item__meta{color:var(--totp-muted,#8a8f99);font-size:12px;margin-top:4px;line-height:1.7;word-break:break-all}
        .dev-item__kick{flex:0 0 auto}
        .totp-ghost,.totp-danger{border:1px solid rgba(125,125,135,.32);background:transparent;border-radius:8px;padding:7px 14px;font-size:13px;cursor:pointer;color:inherit;transition:all .15s}
        .totp-ghost:hover{border-color:var(--totp-accent,#5b7cfa);color:var(--totp-accent,#5b7cfa)}
        .totp-danger{border-color:rgba(230,80,80,.5);color:#e05656}
        .totp-danger:hover{background:rgba(230,80,80,.1)}
        @media(max-width:520px){.dev-item{align-items:flex-start}.dev-item__kick{align-self:center}}
        `;
        document.head.appendChild(css);
    }

    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const icon = (type) => type === 'mobile' ? 'smartphone' : (type === 'tablet' ? 'tablet' : 'computer');

    const post = (url, data, done, error) => util.post({
        url: url, data: data, loader: false,
        done: done,
        error: (res) => { message.error((res && res.msg) || T('操作失败')); error && error(res); },
        fail: () => { message.error(T('网络异常，请稍后重试')); error && error(); }
    });

    function render(list) {
        const box = document.getElementById('dev-list');
        box.innerHTML = '';
        (list || []).forEach((s) => {
            const item = document.createElement('div');
            item.className = 'dev-item' + (s.current ? ' is-current' : '');
            const kick = s.current
                ? ''
                : `<button type="button" class="totp-ghost dev-item__kick" data-id="${Number(s.id)}">${esc(T('退出'))}</button>`;
            item.innerHTML =
                `<div class="dev-item__ico"><span class="material-icons-outlined">${icon(s.device_type)}</span></div>` +
                `<div class="dev-item__main">` +
                `<div class="dev-item__name">${esc(s.device_name || T('未知设备'))}${s.current ? `<span class="dev-item__badge">${esc(T('当前设备'))}</span>` : ''}</div>` +
                `<div class="dev-item__meta">${esc(T('登录'))}：${esc(s.login_ip || '-')} · ${esc(s.created_relative || s.created_time || '-')}<br>${esc(T('最近活跃'))}：${esc(s.last_ip || '-')} · ${esc(s.last_seen_relative || s.last_seen_time || '-')}</div>` +
                `</div>${kick}`;
            box.appendChild(item);
        });
        if (!list || !list.length) {
            box.innerHTML = `<div class="dev-status">${esc(T('没有在线设备'))}</div>`;
        }
    }

    function refresh() {
        document.getElementById('dev-status').style.display = '';
        document.getElementById('dev-status').textContent = T('正在读取登录设备…');
        document.getElementById('dev-list').innerHTML = '';
        post('/user/api/security/deviceSessions', {}, (res) => {
            document.getElementById('dev-status').style.display = 'none';
            render((res && res.data && res.data.list) || []);
        }, () => {
            document.getElementById('dev-status').textContent = T('读取失败，请刷新重试');
        });
    }

    // 逐台退出
    $(document).off('click.devkick').on('click.devkick', '#dev-list .dev-item__kick', function () {
        const id = $(this).data('id');
        message.ask(T('确定退出这台设备吗？'), () => {
            post('/user/api/security/revokeDeviceSession', {id: id}, (res) => {
                message.success((res && res.msg) || T('已退出'));
                refresh();
            });
        });
    });

    $('#dev-refresh').on('click', refresh);

    $('#dev-revoke-others').on('click', function () {
        message.ask(T('退出当前设备以外的所有设备？'), () => {
            post('/user/api/security/revokeOtherDeviceSessions', {}, (res) => {
                message.success((res && res.msg) || T('已退出其他设备'));
                refresh();
            });
        });
    });

    $('#dev-revoke-all').on('click', function () {
        message.ask(T('退出全部设备（包括当前）？之后需要重新登录。'), () => {
            post('/user/api/security/revokeAllDeviceSessions', {}, (res) => {
                message.success((res && res.msg) || T('全部设备已退出'));
                setTimeout(() => { window.location.href = '/user/authentication/login'; }, 1000);
            });
        });
    });

    refresh();
}();
