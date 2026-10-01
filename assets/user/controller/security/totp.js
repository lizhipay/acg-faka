/**
 * 会员两步验证（TOTP）设置页控制器。
 * 复用前台已全局加载的 util / message / layer / i18n / $.fn.qrcode（见主题 Footer）。
 */
!function () {
    if (!document.getElementById('totp-app')) return;

    const T = (s) => (typeof i18n === 'function' ? i18n(s) : s);
    const escapeText = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[c]));
    //本脚本由 ready() 以 ?v=版本 载入；按需加载 ipwhitelist.js 时沿用同一参数
    const scriptQuery = (() => {
        const el = document.currentScript;
        const src = (el && (el.getAttribute('data-ready-src') || el.getAttribute('src'))) || '';
        const at = src.indexOf('?');
        return at >= 0 ? src.slice(at) : '';
    })();
    let pendingSecret = '';
    let confirmMode = '';
    let fundOn = false;
    let fundTarget = false;
    let settleTimer = 0;

    const closeConfirm = () => {
        clearTimeout(settleTimer);
        //先收起溢出（恢复裁剪）再收合高度，保证向上收起时不溢出
        $('#totp-confirm').removeClass('is-settled').removeClass('is-open');
    };

    if (!document.getElementById('totp-style')) {
        const css = document.createElement('style');
        css.id = 'totp-style';
        css.textContent = `
        .totp-app{padding:4px 0;text-align:left}
        .totp-pane{max-width:680px}
        .totp-hint{color:var(--totp-muted,#8a8f99);padding:18px 2px}
        .totp-intro__title{font-size:16px;font-weight:600;margin-bottom:8px}
        .totp-intro__desc{color:var(--totp-muted,#8a8f99);line-height:1.9;font-size:13px;margin-bottom:20px;max-width:560px}
        /* 绑定：左二维码 + 右表单 */
        .totp-setup{display:flex;gap:32px;align-items:flex-start;flex-wrap:wrap}
        .totp-setup__left{flex:0 0 auto}
        .totp-setup__right{flex:1 1 300px;min-width:260px;max-width:380px}
        .totp-qr{width:172px;height:172px;background:#fff;border-radius:14px;padding:8px;box-shadow:0 4px 18px rgba(0,0,0,.12);display:flex;align-items:center;justify-content:center}
        .totp-qr img,.totp-qr canvas{width:156px;height:156px;display:block}
        .totp-secret{margin-top:14px;width:188px}
        .totp-secret__top{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;font-size:12px;color:var(--totp-muted,#8a8f99)}
        .totp-secret code{display:block;width:100%;box-sizing:border-box;text-align:center;font-family:ui-monospace,Menlo,Consolas,monospace;background:rgba(125,125,135,.14);padding:8px 6px;border-radius:8px;letter-spacing:2px;word-break:break-all;color:inherit;font-size:13px}
        .totp-steps{color:var(--totp-muted,#8a8f99);line-height:1.9;font-size:13px;margin:0 0 18px;padding-left:18px}
        .totp-field{margin-bottom:14px}
        .totp-field label{display:block;font-size:13px;font-weight:500;margin-bottom:7px}
        .totp-field .layui-input{height:42px;line-height:42px;border-radius:10px}
        .totp-mini,.totp-ghost,.totp-danger{border:1px solid rgba(125,125,135,.32);background:transparent;border-radius:8px;padding:7px 16px;font-size:13px;cursor:pointer;color:inherit;transition:all .15s}
        .totp-mini{padding:4px 12px;flex:0 0 auto}
        .totp-ghost:hover,.totp-mini:hover{border-color:var(--totp-accent,#5b7cfa);color:var(--totp-accent,#5b7cfa)}
        .totp-danger{border-color:rgba(230,80,80,.5);color:#e05656}
        .totp-danger:hover{background:rgba(230,80,80,.1)}
        .totp-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:22px}
        .totp-actions .uc-cta{margin-top:0}
        /* 已开启 */
        .totp-on__badge{display:inline-flex;align-items:center;gap:8px;color:#1fa971;font-weight:600;font-size:15px;margin-bottom:12px}
        .totp-on__badge .material-icons-outlined{font-size:20px}
        .totp-on__recovery{color:var(--totp-muted,#8a8f99);font-size:13px;margin-bottom:16px}
        .totp-on__fund{display:flex;align-items:center;justify-content:space-between;gap:16px;max-width:520px;border:1px solid rgba(125,125,135,.2);border-radius:12px;padding:12px 16px;margin-bottom:18px}
        .totp-on__fund-title{font-weight:600;font-size:14px;display:block;margin-bottom:4px}
        .totp-on__fund-desc{color:var(--totp-muted,#8a8f99);font-size:12px;line-height:1.7}
        .totp-switch{flex:0 0 auto;width:46px;height:26px;border-radius:999px;background:rgba(125,125,135,.35);position:relative;cursor:pointer;transition:background .18s}
        .totp-switch i{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:left .18s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
        .totp-switch.is-on{background:#1fa971}
        .totp-switch.is-on i{left:23px}
        .totp-on__ops{display:flex;flex-wrap:wrap;gap:12px}
        .totp-confirm{max-width:380px;max-height:0;opacity:0;overflow:hidden;border-top:1px dashed transparent;transition:max-height .32s cubic-bezier(.4,0,.2,1),opacity .24s ease,margin-top .32s ease,padding-top .32s ease}
        .totp-confirm.is-open{max-height:340px;opacity:1;margin-top:22px;padding-top:18px;border-top-color:rgba(125,125,135,.28)}
        /* 展开动画结束后放开溢出，否则输入框聚焦光圈会被 overflow:hidden 裁掉左右两边 */
        .totp-confirm.is-settled{overflow:visible}
        .totp-confirm__inner{transform:translateY(-6px);transition:transform .32s cubic-bezier(.4,0,.2,1)}
        .totp-confirm.is-open .totp-confirm__inner{transform:translateY(0)}
        .totp-confirm__title{font-size:14px;font-weight:600;margin-bottom:14px}
        @media(prefers-reduced-motion:reduce){.totp-confirm,.totp-confirm__inner{transition:none}}
        /* 恢复码 */
        .totp-codes__warn{display:flex;gap:10px;align-items:flex-start;background:rgba(245,166,35,.12);color:#b07d10;border-radius:12px;padding:13px 15px;font-size:13px;line-height:1.85;margin-bottom:16px;max-width:560px}
        .totp-codes__warn .material-icons-outlined{font-size:20px;flex:0 0 auto}
        .totp-codes__grid{display:grid;grid-template-columns:repeat(2,minmax(0,180px));gap:10px;margin-bottom:6px}
        .totp-codes__grid code{font-family:ui-monospace,Menlo,Consolas,monospace;background:rgba(125,125,135,.14);border-radius:8px;padding:10px;text-align:center;letter-spacing:2px;font-size:15px;color:inherit}
        @media(max-width:560px){.totp-codes__grid{grid-template-columns:1fr}.totp-setup__right{max-width:none}}
        /* 对接白名单入口（主题有独立页面时）：资金开关下方的提示条，外框沿用 .totp-on__fund 保持同宽 */
        .totp-ipwl-link{margin-top:-8px;gap:10px;font-size:12.5px;line-height:1.7;color:var(--totp-muted,#8a8f99)}
        .totp-ipwl-link>.material-icons-outlined{font-size:18px;flex:0 0 18px;width:18px;overflow:hidden}
        .totp-ipwl-link__text{flex:1 1 auto;min-width:0}
        .totp-ipwl-link__go{flex:0 0 auto;display:inline-flex;align-items:center;font-weight:600;white-space:nowrap;color:var(--totp-accent,#5b7cfa);text-decoration:none}
        .totp-ipwl-link__go .material-icons-outlined{font-size:18px;margin-right:-4px;transition:transform .15s ease}
        .totp-ipwl-link__go:hover .material-icons-outlined{transform:translateX(2px)}
        .totp-ipwl-link.is-warn{background:rgba(245,166,35,.1);border-color:rgba(245,166,35,.3);color:#b07d10}
        :root[data-theme=dark] .totp-ipwl-link.is-warn{color:#f0b54a}
        @media(prefers-color-scheme:dark){:root:not([data-theme=light]) .totp-ipwl-link.is-warn{color:#f0b54a}}
        @media(max-width:560px){.totp-ipwl-link{flex-wrap:wrap;align-items:flex-start}.totp-ipwl-link>.material-icons-outlined{margin-top:2px}.totp-ipwl-link__text{flex-basis:calc(100% - 28px)}.totp-ipwl-link__go{margin-left:28px}}
        `;
        document.head.appendChild(css);
    }

    const show = (id) => {
        ['totp-loading', 'totp-off', 'totp-setup', 'totp-on', 'totp-codes'].forEach((x) => {
            const el = document.getElementById(x);
            if (el) el.style.display = (x === id) ? '' : 'none';
        });
    };

    const post = (url, data, done, error) => util.post({
        url: url, data: data, loader: false,
        done: done,
        error: (res) => { message.error((res && res.msg) || T('操作失败')); error && error(res); },
        fail: () => { message.error(T('网络异常，请稍后重试')); error && error(); }
    });

    function refresh() {
        show('totp-loading');
        post('/user/api/security/totpStatus', {}, (res) => {
            const d = (res && res.data) || {};
            if (d.bound) {
                $('#totp-recovery-left').text(Number(d.recovery_left || 0));
                fundOn = !!d.fund_2fa;
                $('#totp-fund-switch').toggleClass('is-on', fundOn).attr('aria-checked', fundOn ? 'true' : 'false');
                closeConfirm();
                show('totp-on');
                ipwlEntry.sync(true, fundOn, Number(d.ip_whitelist || 0));
            } else {
                show('totp-off');
                ipwlEntry.sync(false, false, Number(d.ip_whitelist || 0));
            }
        });
    }

    function copyText(text, okMsg) {
        const done = () => message.success(okMsg || T('已复制'));
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, () => fallback());
        } else {
            fallback();
        }
        function fallback() {
            const ta = document.createElement('textarea');
            ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { message.error(T('复制失败，请手动复制')); }
            ta.remove();
        }
    }

    function renderCodes(codes) {
        const grid = document.getElementById('totp-codes-grid');
        grid.innerHTML = '';
        (codes || []).forEach((c) => {
            const code = document.createElement('code');
            code.textContent = c;
            grid.appendChild(code);
        });
        document.getElementById('totp-codes-done').dataset.plain = (codes || []).join('\n');
        show('totp-codes');
    }

    // 对接白名单入口（白名单与两步验证无关：登记了就生效；开了资金操作二次验证时清单为空一律拒绝）：
    //  - 主题放了 #totp-ipwl-link（有独立的「对接白名单」页）：资金开关下方给提示 + 跳转
    //  - 否则把白名单卡片内嵌在本页（已开启时接在资金开关后，未开启时放在页尾），由 ipwhitelist.js 渲染
    //    （按需加载，沿用本脚本的版本参数破缓存）
    const ipwlEntry = (() => {
        const PAGE_URL = '/user/security/ipWhitelist';
        let inlineRoot = null;
        let loading = false;

        function hint(box, fundOn, count) {
            if (!fundOn && count <= 0) {
                box.style.display = 'none';
                return;
            }
            const none = count <= 0;
            box.className = 'totp-on__fund totp-ipwl-link' + (none ? ' is-warn' : '');
            box.innerHTML = `<span class="material-icons-outlined" aria-hidden="true">${none ? 'info' : 'fact_check'}</span>`
                + `<span class="totp-ipwl-link__text">${escapeText(none
                    ? T('对接接口（店铺共享、API）只放行白名单里的服务器 IP 用余额下单。你还没有添加，目前对接下单会被拒绝。')
                    : T('对接白名单已启用：对接接口（店铺共享、API）只放行其中的 %d 个来源用余额下单。')
                        .replace(/\s*%d\s*/, (m) => m.replace(/\s/g, '\u00a0').replace('%d', String(count))))}</span>`
                + `<a class="totp-ipwl-link__go" href="${PAGE_URL}">${escapeText(none ? T('去添加') : T('管理白名单'))}`
                + '<span class="material-icons-outlined" aria-hidden="true">chevron_right</span></a>';
            box.style.display = '';
        }

        function inline(bound) {
            const pane = document.getElementById(bound ? 'totp-on' : 'totp-off');
            if (!pane) return;
            if (!inlineRoot) {
                inlineRoot = document.createElement('section');
                inlineRoot.dataset.ipwl = 'inline';
            }
            const fund = bound ? pane.querySelector('.totp-on__fund') : null;
            if (fund) {
                if (fund.nextElementSibling !== inlineRoot) fund.after(inlineRoot);
            } else if (inlineRoot.parentElement !== pane) {
                if (!bound) inlineRoot.style.marginTop = '22px';
                pane.appendChild(inlineRoot);
            }
            if (bound) inlineRoot.style.marginTop = '';
            if (window.IpWhitelistPanel) {
                window.IpWhitelistPanel.refresh(inlineRoot);
            } else if (!loading && typeof ready === 'function') {
                loading = true;
                ready('/assets/user/controller/security/ipwhitelist.js' + scriptQuery);
            }
        }

        return {
            sync(bound, fundOn, count) {
                const box = document.getElementById('totp-ipwl-link');
                if (box) {
                    hint(box, bound && fundOn, count);
                } else {
                    inline(bound);
                }
            }
        };
    })();

    // 开启：拉密钥 → 显示二维码
    $('#totp-start').on('click', function () {
        post('/user/api/security/totpSecret', {}, (res) => {
            const d = (res && res.data) || {};
            pendingSecret = d.secret || '';
            $('#totp-secret-text').text(pendingSecret);
            $('#totp-code').val(''); $('#totp-pw').val('');
            const qr = $('#totp-qr').empty();
            try {
                qr.qrcode({text: d.uri, width: 160, height: 160});
                const canvas = qr.find('canvas')[0];
                if (canvas) { const img = new Image(); img.src = canvas.toDataURL('image/png'); qr.empty().append(img); }
            } catch (e) {
                qr.text(T('二维码生成失败，请手动输入密钥'));
            }
            show('totp-setup');
        });
    });

    $('#totp-copy').on('click', () => copyText(pendingSecret, T('密钥已复制')));
    $('#totp-cancel').on('click', refresh);

    $('#totp-enable').on('click', function () {
        const code = ($('#totp-code').val() || '').trim();
        const pw = $('#totp-pw').val() || '';
        if (!/^\d{6}$/.test(code)) { message.error(T('请输入 6 位数字动态码')); return; }
        if (pw === '') { message.error(T('请输入你的登录密码')); return; }
        post('/user/api/security/totpEnable', {secret: pendingSecret, code: code, password: pw}, (res) => {
            message.success((res && res.msg) || T('两步验证已开启'));
            renderCodes(res && res.data && res.data.recovery);
        });
    });

    // 已开启：关闭 / 重新生成 → 行内确认表单
    function openConfirm(mode) {
        confirmMode = mode;
        const title = mode === 'disable' ? T('关闭两步验证')
            : (mode === 'regen' ? T('重新生成恢复码')
                : (fundTarget ? T('开启资金操作验证') : T('关闭资金操作验证')));
        $('#totp-confirm-title').text(title);
        $('#totp-confirm-pw').val(''); $('#totp-confirm-code').val('');
        const $c = $('#totp-confirm');
        if (!$c.hasClass('is-open')) {
            $c.addClass('is-open');
            clearTimeout(settleTimer);
            //动画结束后放开溢出并聚焦（此时光圈不会被裁）
            settleTimer = setTimeout(() => {
                $c.addClass('is-settled');
                try { $('#totp-confirm-pw').trigger('focus'); } catch (e) {}
            }, 340);
        }
    }
    $('#totp-disable').on('click', () => openConfirm('disable'));
    $('#totp-regen').on('click', () => openConfirm('regen'));
    $('#totp-fund-switch').on('click', function () {
        fundTarget = !fundOn;
        openConfirm('fund');
    });
    $('#totp-confirm-cancel').on('click', closeConfirm);

    $('#totp-confirm-ok').on('click', function () {
        const pw = $('#totp-confirm-pw').val() || '';
        const code = ($('#totp-confirm-code').val() || '').trim();
        if (pw === '') { message.error(T('请输入你的登录密码')); return; }
        if (code === '') { message.error(T('请输入动态码或备用恢复码')); return; }
        if (confirmMode === 'disable') {
            post('/user/api/security/totpDisable', {password: pw, code: code}, (res) => {
                message.success((res && res.msg) || T('两步验证已关闭'));
                refresh();
            });
        } else if (confirmMode === 'fund') {
            post('/user/api/security/fundGuardSet', {enable: fundTarget ? 1 : 0, password: pw, code: code}, (res) => {
                message.success((res && res.msg) || T('已保存'));
                refresh();
            });
        } else {
            post('/user/api/security/totpRecovery', {password: pw, code: code}, (res) => {
                renderCodes(res && res.data && res.data.recovery);
            });
        }
    });

    $('#totp-codes-copy').on('click', function () {
        copyText(document.getElementById('totp-codes-done').dataset.plain || '', T('恢复码已复制'));
    });
    $('#totp-codes-done').on('click', refresh);

    refresh();
}();
