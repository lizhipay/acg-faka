/**
 * 对接白名单 IP 面板。登记了来源就只放行清单内的来源用余额下单（与是否开启两步验证无关）；
 * 开启资金操作二次验证的账号清单为空时，对接接口（店铺共享 / API）一律拒绝。
 * 两种挂载方式（data-ipwl）：
 *  - page：默认模板的「对接白名单」独立页面（#ipwl-app）
 *  - inline：没有独立页面的主题，由 totp.js 插在两步验证页的资金开关下方
 * 表单与按钮沿用 totp-confirm / totp-field / uc-cta / totp-ghost，主题对这些类的换皮会自动生效。
 */
!function () {
    const T = (s) => (typeof i18n === 'function' ? i18n(s) : s);
    const entities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => entities[c]);
    const icon = (name) => `<span class="material-icons-outlined" aria-hidden="true">${name}</span>`;
    const reduceMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    //最近的可捲动祖先（没有就用 window）。不用 scrollIntoView：它会连 overflow:hidden 的祖先一起捲，把版面捲歪
    const scrollParent = (el) => {
        for (let node = el.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            const overflow = getComputedStyle(node).overflowY;
            if ((overflow === 'auto' || overflow === 'scroll' || overflow === 'overlay') && node.scrollHeight > node.clientHeight) {
                return node;
            }
        }
        return null;
    };
    let seq = 0;

    if (!document.getElementById('ipwl-style')) {
        const css = document.createElement('style');
        css.id = 'ipwl-style';
        css.textContent = `
        .ipwl{text-align:left}
        .ipwl--page{padding:4px 0}
        .ipwl__inner{flex:1 1 auto;min-width:0}
        .ipwl__head{display:flex;align-items:center;justify-content:space-between;gap:14px}
        .ipwl__title{display:flex;align-items:center;gap:8px;min-width:0;font-weight:600;font-size:14px}
        .ipwl--page .ipwl__title{font-size:16px}
        .ipwl__count{font-weight:500;font-size:11.5px;line-height:18px;padding:0 7px;border-radius:999px;border:1px solid rgba(125,125,135,.28);color:var(--totp-muted,#8a8f99);font-variant-numeric:tabular-nums;white-space:nowrap}
        .ipwl__desc{color:var(--totp-muted,#8a8f99);font-size:12px;line-height:1.7;margin:4px 0 0}
        .ipwl--page .ipwl__desc{font-size:13px;line-height:1.9;margin-top:8px}
        .ipwl__add{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto;padding:5px 12px 5px 9px;white-space:nowrap}
        .ipwl__add .material-icons-outlined{font-size:17px}
        .ipwl__add:disabled,.ipwl__denied-item .totp-mini:disabled{opacity:.45;cursor:not-allowed}
        .ipwl__status{display:flex;align-items:center;gap:10px;margin-top:16px;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.6;background:rgba(125,125,135,.08);color:var(--totp-muted,#8a8f99)}
        .ipwl__status:empty{display:none}
        .ipwl__status .material-icons-outlined{font-size:18px;flex:0 0 auto}
        .ipwl__status-text{flex:1 1 auto;min-width:0}
        .ipwl__status a{flex:0 0 auto;display:inline-flex;align-items:center;font-weight:600;white-space:nowrap;color:var(--totp-accent,#5b7cfa);text-decoration:none}
        .ipwl__status a .material-icons-outlined{font-size:18px;margin-right:-4px}
        .ipwl__status.is-on{background:rgba(31,169,113,.1);color:#178a5c}
        .ipwl__status.is-warn{background:rgba(245,166,35,.12);color:#b07d10}
        .ipwl__loading{color:var(--totp-muted,#8a8f99);font-size:13px;padding:18px 2px}
        .ipwl__list{list-style:none;margin:12px 0 0;padding:0}
        .ipwl__item{display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid rgba(125,125,135,.14);transition:opacity .18s ease,transform .18s ease}
        .ipwl--page .ipwl__list{margin-top:14px}
        .ipwl--page .ipwl__item{gap:13px;padding:14px 2px;border-top:0;border-bottom:1px solid rgba(125,125,135,.16)}
        .ipwl__item.is-new{animation:ipwl-in .36s cubic-bezier(.2,.8,.2,1)}
        .ipwl__item.is-leaving{opacity:0;transform:translateX(8px)}
        @keyframes ipwl-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
        .ipwl__ico{flex:0 0 auto;width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;color:var(--totp-accent,#5b7cfa);background:rgba(91,124,250,.12);background:color-mix(in srgb,var(--totp-accent,#5b7cfa) 13%,transparent)}
        .ipwl--page .ipwl__ico{width:38px;height:38px;border-radius:11px}
        .ipwl__ico .material-icons-outlined{font-size:18px}
        .ipwl--page .ipwl__ico .material-icons-outlined{font-size:20px}
        .ipwl__main{flex:1 1 auto;min-width:0}
        .ipwl__line{display:flex;align-items:baseline;flex-wrap:wrap;gap:2px 10px}
        .ipwl__ip,.ipwl__denied-item code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13.5px;font-weight:600;letter-spacing:.2px;word-break:break-all;color:inherit;background:none;padding:0}
        .ipwl__note{font-size:12.5px;color:var(--totp-muted,#8a8f99);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ipwl__meta{font-size:12px;color:var(--totp-muted,#8a8f99);margin-top:2px}
        .ipwl__meta>span{white-space:nowrap}
        .ipwl__dot{font-style:normal}
        .ipwl__meta .is-live{color:#1fa971}
        .ipwl__del{flex:0 0 auto;width:32px;height:32px;border:0;border-radius:50%;background:transparent;color:var(--totp-muted,#8a8f99);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s}
        .ipwl__del:hover,.ipwl__del:focus-visible{background:rgba(230,80,80,.1);color:#e05656}
        .ipwl__del .material-icons-outlined{font-size:19px}
        .ipwl__empty{display:flex;gap:9px;align-items:flex-start;margin-top:12px;padding:10px 12px;border-radius:10px;background:rgba(245,166,35,.12);color:#b07d10;font-size:12.5px;line-height:1.7}
        .ipwl--page .ipwl__empty{margin-top:16px;padding:22px 16px;border-radius:12px;font-size:13px;justify-content:center;background:transparent;border:1px dashed rgba(125,125,135,.3);color:var(--totp-muted,#8a8f99)}
        .ipwl__empty.is-quiet{background:rgba(125,125,135,.08);color:var(--totp-muted,#8a8f99)}
        .ipwl__empty .material-icons-outlined,.ipwl__denied-title .material-icons-outlined{font-size:18px;flex:0 0 auto}
        .ipwl__denied{margin-top:12px;padding-top:12px;border-top:1px dashed rgba(125,125,135,.28)}
        .ipwl--page .ipwl__denied{margin-top:22px;padding:14px 16px 10px;border:1px solid rgba(245,166,35,.3);border-radius:14px;background:rgba(245,166,35,.06)}
        .ipwl__denied-title{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#b07d10}
        .ipwl__denied-tip{color:var(--totp-muted,#8a8f99);font-size:12px;line-height:1.7;margin:4px 0 6px}
        .ipwl__denied-item{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;padding:5px 0}
        .ipwl__denied-item code{white-space:nowrap}
        .ipwl__denied-item span{flex:1 1 auto;min-width:0;font-size:12px;color:var(--totp-muted,#8a8f99)}
        .ipwl__denied-item .totp-mini{margin-left:auto}
        .ipwl__hint{color:var(--totp-muted,#8a8f99);font-size:12px;line-height:1.6;margin-top:6px}
        .ipwl__help{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:24px}
        .ipwl__tip{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:12px;border:1px solid rgba(125,125,135,.14);background:rgba(125,125,135,.06)}
        .ipwl__tip>.material-icons-outlined{flex:0 0 auto;font-size:18px;margin-top:1px;color:var(--totp-muted,#8a8f99)}
        .ipwl__tip-title{font-size:12.5px;font-weight:600;margin-bottom:2px}
        .ipwl__tip-text{font-size:12px;line-height:1.7;color:var(--totp-muted,#8a8f99)}
        .ipwl .totp-confirm{max-width:none}
        .ipwl .totp-confirm.is-open{max-height:560px}
        .ipwl .totp-confirm__inner{max-width:380px}
        :where(.ipwl--page) .totp-ghost,:where(.ipwl--page) .totp-mini{border:1px solid rgba(125,125,135,.32);background:transparent;border-radius:8px;padding:7px 16px;font-size:13px;cursor:pointer;color:inherit;transition:all .15s}
        :where(.ipwl--page) .totp-mini{padding:4px 12px;flex:0 0 auto}
        :where(.ipwl--page) .totp-ghost:hover,:where(.ipwl--page) .totp-mini:hover{border-color:var(--totp-accent,#5b7cfa);color:var(--totp-accent,#5b7cfa)}
        :where(.ipwl--page) .totp-field{margin-bottom:14px}
        :where(.ipwl--page) .totp-field label{display:block;font-size:13px;font-weight:500;margin-bottom:7px}
        :where(.ipwl--page) .totp-field .layui-input{height:42px;line-height:42px;border-radius:10px}
        :where(.ipwl--page) .totp-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:22px}
        :where(.ipwl--page) .totp-actions .uc-cta{margin-top:0}
        :where(.ipwl--page) .totp-confirm{max-height:0;opacity:0;overflow:hidden;border-top:1px dashed transparent;transition:max-height .32s cubic-bezier(.4,0,.2,1),opacity .24s ease,margin-top .32s ease,padding-top .32s ease}
        :where(.ipwl--page) .totp-confirm.is-open{opacity:1;margin-top:22px;padding-top:18px;border-top-color:rgba(125,125,135,.28)}
        :where(.ipwl--page) .totp-confirm.is-settled{overflow:visible}
        :where(.ipwl--page) .totp-confirm__inner{transform:translateY(-6px);transition:transform .32s cubic-bezier(.4,0,.2,1)}
        :where(.ipwl--page) .totp-confirm.is-open .totp-confirm__inner{transform:translateY(0)}
        :where(.ipwl--page) .totp-confirm__title{font-size:14px;font-weight:600;margin-bottom:14px}
        :where(:root[data-theme=dark]) .ipwl--inline .ipwl__empty:not(.is-quiet),:where(:root[data-theme=dark]) .ipwl__denied-title,:where(:root[data-theme=dark]) .ipwl__status.is-warn{color:#f0b54a}
        :where(:root[data-theme=dark]) .ipwl__status.is-on{color:#4cc98f}
        @media(prefers-color-scheme:dark){:where(:root:not([data-theme=light])) .ipwl--inline .ipwl__empty:not(.is-quiet),:where(:root:not([data-theme=light])) .ipwl__denied-title,:where(:root:not([data-theme=light])) .ipwl__status.is-warn{color:#f0b54a}:where(:root:not([data-theme=light])) .ipwl__status.is-on{color:#4cc98f}}
        @media(max-width:860px){.ipwl__help{grid-template-columns:1fr}}
        @media(prefers-reduced-motion:reduce){.ipwl__item,.ipwl__item.is-new{animation:none;transition:none}:where(.ipwl--page) .totp-confirm,:where(.ipwl--page) .totp-confirm__inner{transition:none}}
        `;
        document.head.appendChild(css);
    }

    const post = (url, data, done, error) => util.post({
        url: url, data: data, loader: false,
        done: done,
        error: (res) => { message.error((res && res.msg) || T('操作失败')); error && error(res); },
        fail: () => { message.error(T('网络异常，请稍后重试')); error && error(); }
    });

    class Panel {
        constructor(root) {
            this.root = root;
            this.page = root.dataset.ipwl === 'page';
            this.id = 'ipwl' + (++seq);
            this.state = {list: [], limit: 20, denied: [], bound: true, fund_2fa: true};
            this.loaded = false;
            this.freshId = 0;
            this.timer = 0;
            root.classList.add('ipwl', this.page ? 'ipwl--page' : 'ipwl--inline');
            //内嵌时外框直接用资金开关那张卡的类：宽度、边框、各主题换皮都与它一致
            if (!this.page) root.classList.add('totp-on__fund');
            root.setAttribute('aria-labelledby', this.id + '-title');
            root.innerHTML = this.skeleton();
            this.bind();
        }

        $(selector) {
            return $(this.root).find(selector);
        }

        skeleton() {
            const id = this.id;
            return `<div class="ipwl__inner">
                <div class="ipwl__head">
                    <div class="ipwl__title" id="${id}-title">${esc(T('对接白名单 IP'))}<span class="ipwl__count"></span></div>
                    <button type="button" class="totp-ghost ipwl__add">${icon('add')}${esc(T('添加 IP'))}</button>
                </div>
                <p class="ipwl__desc">${esc(this.page
                    ? T('对接接口（店铺共享、API）用这个账号的余额下单时，只放行这里登记的服务器 IP。添加第一个 IP 即启用，与是否开启两步验证无关；开启了「资金操作二次验证」的账号，清单为空时一律拒绝。')
                    : T('登记 IP 后，对接接口（店铺共享、API）只接受下列来源用余额下单；开启资金操作二次验证时，清单为空一律拒绝。'))}</p>
                ${this.page ? '<div class="ipwl__status"></div>' : ''}
                <div class="totp-confirm ipwl__form">
                    <div class="totp-confirm__inner">
                        <div class="totp-confirm__title">${esc(T('添加白名单 IP'))}</div>
                        <div class="totp-field">
                            <label for="${id}-ip">${esc(T('IP 地址或网段'))}</label>
                            <input type="text" id="${id}-ip" class="layui-input ipwl__in-ip" maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="${esc(T('例如 203.0.113.8 或 203.0.113.0/24'))}">
                            <div class="ipwl__hint"></div>
                        </div>
                        <div class="totp-field">
                            <label for="${id}-note">${esc(T('备注（选填）'))}</label>
                            <input type="text" id="${id}-note" class="layui-input ipwl__in-note" maxlength="32" autocomplete="off" placeholder="${esc(T('例如：主站服务器'))}">
                        </div>
                        <div class="totp-field ipwl__f-code">
                            <label for="${id}-code">${esc(T('动态码'))}</label>
                            <input type="text" id="${id}-code" class="layui-input ipwl__in-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="${esc(T('验证器上的 6 位数字'))}">
                        </div>
                        <div class="totp-field ipwl__f-pw" style="display:none;">
                            <label for="${id}-pw">${esc(T('账号密码'))}</label>
                            <input type="password" id="${id}-pw" class="layui-input ipwl__in-pw" autocomplete="current-password" placeholder="${esc(T('请输入你的登录密码'))}">
                        </div>
                        <div class="totp-actions">
                            <button type="button" class="uc-cta ipwl__save">${icon('check')}${esc(T('加入白名单'))}</button>
                            <button type="button" class="totp-ghost ipwl__cancel">${esc(T('取消'))}</button>
                        </div>
                    </div>
                </div>
                <div class="ipwl__body"><div class="ipwl__loading">${esc(T('正在读取对接白名单…'))}</div></div>
                ${this.page ? `<div class="ipwl__help">
                    ${this.tip('edit_note', T('支持的写法'), T('单个 IPv4 / IPv6 地址，或网段，例如 203.0.113.0/24；IPv4 网段最大到 /16，IPv6 最大到 /48。'))}
                    ${this.tip('key', T('数量与验证'), T('最多登记 20 条；添加需要验证身份（开了两步验证输入动态码，没开输入账号密码），移除不需要。'))}
                    ${this.tip('filter_alt', T('影响范围'), T('只限制对接接口用余额下单；拉取商品、查询库存与订单不受影响。'))}
                </div>` : ''}
            </div>`;
        }

        tip(name, title, text) {
            return `<div class="ipwl__tip">${icon(name)}<div><div class="ipwl__tip-title">${esc(title)}</div><div class="ipwl__tip-text">${esc(text)}</div></div></div>`;
        }

        load() {
            post('/user/api/security/ipWhitelist', {}, (res) => {
                this.state = Object.assign({}, this.state, (res && res.data) || {});
                this.loaded = true;
                this.render();
            });
        }

        renderStatus() {
            if (!this.page) return;
            const s = this.state;
            let tone = '', ico = 'info', text;
            if ((s.list || []).length) {
                tone = 'is-on';
                ico = 'verified_user';
                text = T('已启用：对接接口只放行清单里的来源，其余一律拒绝。');
            } else if (s.bound && s.fund_2fa) {
                tone = 'is-warn';
                text = T('清单还是空的：这个账号开启了资金操作二次验证，对接接口目前无法用余额下单。');
            } else {
                text = T('未启用：清单为空时，对接接口不限制来源；添加第一个 IP 后，就只放行清单里的来源。');
            }
            this.$('.ipwl__status').attr('class', 'ipwl__status ' + tone)
                .html(`${icon(ico)}<span class="ipwl__status-text">${esc(text)}</span>`);
        }

        render() {
            const s = this.state;
            const list = s.list || [];
            const denied = s.denied || [];
            const limit = Number(s.limit) || 20;
            const full = list.length >= limit;
            this.$('.ipwl__count').text(`${list.length} / ${limit}`);
            this.$('.ipwl__add').prop('disabled', full).attr('title', full ? T('已达上限，请先移除不用的') : '');
            this.$('.ipwl__f-code').toggle(!!s.bound);
            this.$('.ipwl__f-pw').toggle(!s.bound);
            //只有会拒单的状态（清单不为空，或开了资金验证）才会记下被拒的来源，否则「先下一单」找不到 IP
            this.$('.ipwl__hint').text(list.length || (s.bound && s.fund_2fa)
                ? T('不知道服务器 IP？先从服务器发起一次对接下单，被拒绝的 IP 会列在「最近被拒绝的来源」里，点「加入白名单」即可。')
                : T('不知道服务器 IP？在调用接口的那台服务器上查一下它的公网出口 IP；添加第一个 IP 之后，其他来源的对接下单会被拒绝，并列在「最近被拒绝的来源」里。'));
            this.renderStatus();

            let html = '';
            if (!list.length) {
                if (this.page) {
                    html += `<div class="ipwl__empty"><span>${esc(T('还没有登记任何 IP，点「添加 IP」开始。'))}</span></div>`;
                } else if (s.bound && s.fund_2fa) {
                    html += `<div class="ipwl__empty">${icon('info')}<span>${esc(T('还没有添加 IP。添加之前，对接接口无法用这个账号的余额下单。'))}</span></div>`;
                } else {
                    html += `<div class="ipwl__empty is-quiet">${icon('info')}<span>${esc(T('还没有添加 IP，对接接口目前不限制来源。'))}</span></div>`;
                }
            } else {
                html += '<ul class="ipwl__list">' + list.map((e) => {
                    const used = e.last_used_relative
                        ? `<span class="is-live">${esc(T('最近放行'))} ${esc(e.last_used_relative)}</span>`
                        : `<span>${esc(T('尚未放行过'))}</span>`;
                    const added = `<span>${esc(T('添加于'))} ${esc(String(e.create_time || '').slice(0, 16))}</span>`;
                    return `<li class="ipwl__item${Number(e.id) === this.freshId ? ' is-new' : ''}">`
                        + `<span class="ipwl__ico">${icon(String(e.ip).includes('/') ? 'lan' : 'dns')}</span>`
                        + `<div class="ipwl__main"><div class="ipwl__line"><code class="ipwl__ip">${esc(e.ip)}</code>`
                        + (e.note ? `<span class="ipwl__note">${esc(e.note)}</span>` : '')
                        + `</div><div class="ipwl__meta">${used}<i class="ipwl__dot" aria-hidden="true"> · </i>${added}</div></div>`
                        + `<button type="button" class="ipwl__del" data-id="${Number(e.id)}" data-ip="${esc(e.ip)}" title="${esc(T('移除'))}" aria-label="${esc(T('移除'))} ${esc(e.ip)}">${icon('delete_outline')}</button>`
                        + '</li>';
                }).join('') + '</ul>';
            }
            if (denied.length) {
                html += `<div class="ipwl__denied"><div class="ipwl__denied-title">${icon('gpp_maybe')}${esc(T('最近被拒绝的来源'))}</div>`
                    + `<p class="ipwl__denied-tip">${esc(T('这些 IP 用这个账号的对接密钥下单时被拒绝了。是你自己的服务器就加入白名单；不认识的话，建议立即重置对接密钥。'))}</p>`
                    + denied.map((d) => `<div class="ipwl__denied-item"><code>${esc(d.ip)}</code><span>${esc(d.relative)}</span>`
                        + `<button type="button" class="totp-mini" data-ip="${esc(d.ip)}"${full ? ' disabled' : ''}>${esc(T('加入白名单'))}</button></div>`).join('')
                    + '</div>';
            }
            this.$('.ipwl__body').html(html);
            this.freshId = 0;
        }

        openForm(ip) {
            const $form = this.$('.ipwl__form');
            this.$('.ipwl__in-ip').val(ip || '');
            this.$('.ipwl__in-note').val('');
            this.$('.ipwl__in-code, .ipwl__in-pw').val('');
            const $focus = this.$(ip ? (this.state.bound ? '.ipwl__in-code' : '.ipwl__in-pw') : '.ipwl__in-ip');
            //从「最近被拒绝的来源」点进来时，表单可能在可视区上方；有的主题是内层容器在捲動，要捲那个容器
            const scroller = scrollParent($form[0]);
            const top = $form[0].getBoundingClientRect().top - (scroller ? scroller.getBoundingClientRect().top : 0);
            if (top < 0) {
                const to = {top: (scroller ? scroller.scrollTop : window.scrollY) + top - 96, behavior: reduceMotion() ? 'auto' : 'smooth'};
                scroller ? scroller.scrollTo(to) : window.scrollTo(to);
            }
            if ($form.hasClass('is-open')) {
                $focus.trigger('focus');
                return;
            }
            $form.addClass('is-open');
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                $form.addClass('is-settled');
                try { $focus[0].focus({preventScroll: true}); } catch (e) {}
            }, 340);
        }

        closeForm() {
            clearTimeout(this.timer);
            this.$('.ipwl__form').removeClass('is-settled').removeClass('is-open');
        }

        save() {
            const ip = (this.$('.ipwl__in-ip').val() || '').trim();
            const bound = !!this.state.bound;
            const $proof = this.$(bound ? '.ipwl__in-code' : '.ipwl__in-pw');
            const proof = bound ? ($proof.val() || '').trim() : ($proof.val() || '');
            if (ip === '') { message.error(T('请输入 IP 地址或网段')); this.$('.ipwl__in-ip').trigger('focus'); return; }
            if (bound && !/^\d{6}$/.test(proof)) { message.error(T('请输入 6 位数字动态码')); $proof.trigger('focus'); return; }
            if (!bound && proof === '') { message.error(T('请输入你的登录密码')); $proof.trigger('focus'); return; }
            const $btn = this.$('.ipwl__save').prop('disabled', true);
            const before = new Set((this.state.list || []).map((e) => Number(e.id)));
            const data = {ip: ip, note: (this.$('.ipwl__in-note').val() || '').trim()};
            data[bound ? 'code' : 'password'] = proof;
            post('/user/api/security/ipWhitelistAdd', data, (res) => {
                $btn.prop('disabled', false);
                message.success((res && res.msg) || T('已加入白名单'));
                this.state = Object.assign({}, this.state, (res && res.data) || {});
                const added = (this.state.list || []).find((e) => !before.has(Number(e.id)));
                this.freshId = added ? Number(added.id) : 0;
                this.closeForm();
                this.render();
            }, () => {
                $btn.prop('disabled', false);
                $proof.val('').trigger('focus');
            });
        }

        remove(button) {
            const ip = button.dataset.ip || '';
            message.ask(T('移除后，来自这个 IP 的对接下单会被拒绝。确定移除？') + ' ' + esc(ip), () => {
                post('/user/api/security/ipWhitelistRemove', {id: Number(button.dataset.id) || 0}, (res) => {
                    message.success((res && res.msg) || T('已移出白名单'));
                    this.state = Object.assign({}, this.state, (res && res.data) || {});
                    const row = button.closest('.ipwl__item');
                    if (row && !reduceMotion()) {
                        row.classList.add('is-leaving');
                        setTimeout(() => this.render(), 180);
                    } else {
                        this.render();
                    }
                });
            });
        }

        bind() {
            const $root = $(this.root);
            $root.on('click', '.ipwl__add', () => this.openForm(''));
            $root.on('click', '.ipwl__cancel', () => this.closeForm());
            $root.on('click', '.ipwl__save', () => this.save());
            $root.on('click', '.ipwl__del', (e) => this.remove(e.currentTarget));
            $root.on('click', '.ipwl__denied-item .totp-mini', (e) => this.openForm(e.currentTarget.dataset.ip || ''));
            $root.on('keydown', '.ipwl__form input', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); this.save(); }
                else if (e.key === 'Escape') { this.closeForm(); }
            });
        }
    }

    function mount(root) {
        if (!root.__ipwl) {
            root.__ipwl = new Panel(root);
        }
        root.__ipwl.load();
    }

    window.IpWhitelistPanel = {
        refresh(root) {
            if (root) mount(root);
        }
    };

    document.querySelectorAll('[data-ipwl]').forEach(mount);
}();
