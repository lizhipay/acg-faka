!function () {
    const T = (s) => (typeof i18n === 'function' ? i18n(s) : s);
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => htmlEntities[c]);

    if (!document.getElementById('slog-style')) {
        const css = document.createElement('style');
        css.id = 'slog-style';
        css.textContent = `
        .slog-app{text-align:left}
        .slog-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px}
        .slog-head__title{font-size:16px;font-weight:600;margin-bottom:6px}
        .slog-head__ops{display:flex;flex-wrap:wrap;gap:10px}
        .slog-chip,.totp-ghost{display:inline-flex;align-items:center;gap:5px;border:1px solid rgba(125,125,135,.32);background:transparent;border-radius:999px;padding:7px 14px;font-size:13px;line-height:1;cursor:pointer;color:inherit;transition:all .15s}
        .slog-chip .material-icons-outlined,.totp-ghost .material-icons-outlined{font-size:16px}
        .totp-ghost{border-radius:8px}
        .slog-chip:hover,.totp-ghost:hover{border-color:var(--totp-accent,#5b7cfa);color:var(--totp-accent,#5b7cfa)}
        .slog-chip.is-on,.slog-chip.is-on:hover{color:#e05656;border-color:rgba(230,80,80,.5);background:rgba(230,80,80,.1)}
        .slog-status{color:var(--totp-muted,#8a8f99);font-size:13px;padding:26px 0;text-align:center}
        .slog-list{display:flex;flex-direction:column}
        .slog-item{display:flex;gap:13px;padding:14px 2px;border-bottom:1px solid rgba(125,125,135,.16)}
        .slog-item:last-child{border-bottom:none}
        .slog-item__ico{flex:0 0 auto;width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center}
        .slog-item__ico .material-icons-outlined{font-size:20px}
        .slog-item__main{flex:1 1 auto;min-width:0}
        .slog-item__top{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .slog-item__title{font-size:14px;font-weight:600}
        .slog-risk{font-size:11px;color:#e05656;border:1px solid rgba(230,80,80,.5);border-radius:999px;padding:1px 8px;white-space:nowrap}
        .slog-item__detail{color:inherit;opacity:.72;font-size:12.5px;margin-top:3px;word-break:break-word}
        .slog-item__meta{display:flex;align-items:center;flex-wrap:wrap;gap:5px;color:var(--totp-muted,#8a8f99);font-size:12px;margin-top:5px;line-height:1.7}
        .slog-item__meta .material-icons-outlined{font-size:14px;opacity:.85}
        .slog-dot{opacity:.5}
        .slog-more-wrap{text-align:center;padding:16px 0 2px}
        @media(max-width:520px){.slog-head__ops{width:100%}}
        `;
        document.head.appendChild(css);
    }

    const LABELS = {
        login: '登录成功', login_passkey: '通行密钥登录', login_fail: '登录失败', logout: '退出登录',
        password: '修改密码', email: '修改邮箱', phone: '修改手机号', settlement: '修改资料/结算方式',
        totp_on: '开启两步验证', totp_off: '关闭两步验证', totp_recovery: '重置备用恢复码',
        fund_2fa_on: '开启资金二次验证', fund_2fa_off: '关闭资金二次验证', fund_verify: '通过资金二次验证',
        device_revoke: '退出设备', cash: '申请兑现', transfer: '下级转账',
        passkey_add: '添加通行密钥', passkey_remove: '删除通行密钥', app_key_reset: '重置对接密钥',
        ip_whitelist_add: '添加对接白名单 IP', ip_whitelist_remove: '移除对接白名单 IP', api_ip_denied: '对接下单被拒'
    };
    const ICONS = {
        login: 'login', login_passkey: 'fingerprint', login_fail: 'gpp_bad', logout: 'logout',
        password: 'password', email: 'mail', phone: 'smartphone', settlement: 'account_balance_wallet',
        totp_on: 'verified_user', totp_off: 'gpp_bad', totp_recovery: 'vpn_key',
        fund_2fa_on: 'shield', fund_2fa_off: 'remove_moderator', fund_verify: 'verified',
        device_revoke: 'devices', cash: 'payments', transfer: 'swap_horiz',
        passkey_add: 'fingerprint', passkey_remove: 'fingerprint', app_key_reset: 'key',
        ip_whitelist_add: 'dns', ip_whitelist_remove: 'dns', api_ip_denied: 'gpp_maybe'
    };
    const TONES = {
        login: 'success', login_passkey: 'success', logout: 'success',
        login_fail: 'danger', totp_off: 'danger', fund_2fa_off: 'danger', api_ip_denied: 'danger',
        password: 'warning', cash: 'warning', transfer: 'warning', device_revoke: 'warning', passkey_remove: 'warning', app_key_reset: 'warning', ip_whitelist_add: 'warning', ip_whitelist_remove: 'warning',
        email: 'primary', phone: 'primary', settlement: 'primary',
        totp_on: 'primary', totp_recovery: 'primary', fund_2fa_on: 'primary', fund_verify: 'primary', passkey_add: 'primary'
    };
    const TONE_COLOR = {success: '#1fa971', danger: '#e5484d', warning: '#d98a00', primary: '#447cf3', secondary: '#8a94a6'};
    const DETAIL_ACTIONS = new Set(['transfer', 'cash', 'device_revoke', 'login_fail', 'passkey_add', 'passkey_remove']);
    const metaIcon = t => t === 'mobile' ? 'smartphone' : (t === 'tablet' ? 'tablet_mac' : 'computer');

    let page = 1, onlyRisk = false, loading = false;

    const $list = () => document.getElementById('slog-list');
    const $status = () => document.getElementById('slog-status');
    const $more = () => document.getElementById('slog-more');

    function itemHtml(r) {
        const tone = TONES[r.action] || 'secondary';
        const color = TONE_COLOR[tone] || TONE_COLOR.secondary;
        const icon = ICONS[r.action] || 'history';
        const title = T(LABELS[r.action] || r.action || '-');
        const riskChip = Number(r.risk) === 1 ? `<span class="slog-risk">${esc(T('风险较高'))}</span>` : '';
        const detail = (DETAIL_ACTIONS.has(r.action) && r.content) ? `<div class="slog-item__detail">${esc(r.content)}</div>` : '';
        return `<div class="slog-item">`
            + `<div class="slog-item__ico" style="color:${color};background:${color}1a;"><span class="material-icons-outlined">${esc(icon)}</span></div>`
            + `<div class="slog-item__main">`
            + `<div class="slog-item__top"><span class="slog-item__title">${esc(title)}</span>${riskChip}</div>`
            + detail
            + `<div class="slog-item__meta"><span class="material-icons-outlined">${esc(metaIcon(r.device_type))}</span>${esc(r.client || '-')} <span class="slog-dot">·</span> ${esc(r.ip || '-')} <span class="slog-dot">·</span> ${esc(r.create_relative || r.create_time || '-')}</div>`
            + `</div>`
            + `</div>`;
    }

    function load(reset) {
        if (loading) return;
        loading = true;
        if (reset) {
            page = 1;
            $list().innerHTML = '';
            $status().style.display = '';
            $status().textContent = T('正在读取安全日志…');
            $more().style.display = 'none';
        }
        util.post({
            url: '/user/api/security/logs',
            data: {page: page, risk: onlyRisk ? 1 : 0},
            loader: false,
            done: (res) => {
                loading = false;
                const d = (res && res.data) || {};
                const list = d.list || [];
                if (reset && list.length === 0) {
                    $status().style.display = '';
                    $status().textContent = onlyRisk ? T('没有风险记录') : T('暂时没有安全记录');
                    $more().style.display = 'none';
                    return;
                }
                $status().style.display = 'none';
                $list().insertAdjacentHTML('beforeend', list.map(itemHtml).join(''));
                $more().style.display = d.more ? '' : 'none';
            },
            error: (res) => {
                loading = false;
                $status().style.display = '';
                $status().textContent = (res && res.msg) || T('读取失败，请稍后重试');
            },
            fail: () => {
                loading = false;
                $status().style.display = '';
                $status().textContent = T('网络异常，请稍后重试');
            }
        });
    }

    $('#slog-refresh').off('click.slog').on('click.slog', () => load(true));
    $('#slog-only-risk').off('click.slog').on('click.slog', function () {
        onlyRisk = !onlyRisk;
        this.setAttribute('aria-pressed', onlyRisk ? 'true' : 'false');
        this.classList.toggle('is-on', onlyRisk);
        load(true);
    });
    $('#slog-more').off('click.slog').on('click.slog', () => {
        page += 1;
        load(false);
    });

    load(true);
}();
