!function () {
    let pluginUnbindTable, proUnbindTable, _GroupPrice;

    /* 安装/更新进度卡片（液态玻璃）。
       为什么没有百分比：下载和解压全在服务端，前端只有"发出请求"和"收到响应"两个
       时刻，中间拿不到任何真实进度。上一版用指数曲线假装爬到 95%，看着专业，其实
       是在编数字——包小的时候卡在 60% 空等，包大的时候早早顶到 95% 一动不动，比没有
       进度条还让人心慌。这版换成诚实的不确定态：
         · 梭形进度条只表达"在动"，不承诺进度
         · 右下角是真实的已用时长（mm:ss），久了再逐级补安抚文案
         · 卡片上放插件自己的图标、名字和版本——你点的是哪个，卡片上就是哪个
       颜色走 --md-* 主题变量，跟着后台亮/暗色和主色走，不再写死一套苹果蓝。
       自包含：首次调用注入一次 <style>（scoped .tk-inst-*），改这里即生效。 */
    const tkProgress = (() => {
        let styled = false;
        const living = new Set();          //未结束的卡片，pjax 切页时统一收掉
        const ensureStyle = () => {
            if (styled) return;
            styled = true;
            const s = document.createElement('style');
            s.textContent = `
/* 自己声明 box-sizing：这套样式不依赖宿主页面的 reset，
   content-box 下卡片得靠 flex-shrink 才收得住，宽度就不好算了 */
.tk-inst,.tk-inst *{box-sizing:border-box}
.tk-inst{position:fixed;inset:0;z-index:99990;display:flex;align-items:center;justify-content:center;padding:20px;
 background:rgba(16,18,26,.34);-webkit-backdrop-filter:blur(10px) saturate(120%);backdrop-filter:blur(10px) saturate(120%);
 opacity:0;transition:opacity .32s cubic-bezier(.32,.72,0,1)}
.tk-inst.is-in{opacity:1}
.tk-inst-card{
 --tk-a:var(--md-primary,#0a84ff);
 --tk-a2:color-mix(in srgb,var(--md-primary,#0a84ff) 42%,#69d2ff);
 --tk-ok:#22b07d;--tk-ok2:#5ad9a8;--tk-no:var(--md-error,#d32f2f);
 --tk-fg:var(--md-on-surface,rgba(0,0,0,.87));--tk-fg2:var(--md-on-surface-med,rgba(0,0,0,.6));
 --tk-card:rgba(255,255,255,.92);
 width:min(376px,100%);padding:30px 28px 22px;border-radius:26px;background:var(--tk-card);
 -webkit-backdrop-filter:blur(34px) saturate(180%);backdrop-filter:blur(34px) saturate(180%);
 border:1px solid rgba(255,255,255,.7);
 box-shadow:inset 0 1px 0 rgba(255,255,255,.95),0 2px 6px rgba(14,18,32,.06),0 34px 74px -24px rgba(14,18,32,.5);
 transform:scale(.94) translateY(12px);opacity:0;
 transition:transform .42s cubic-bezier(.32,.72,0,1),opacity .34s cubic-bezier(.32,.72,0,1)}
.tk-inst.is-in .tk-inst-card{transform:none;opacity:1}
.tk-inst-art{position:relative;width:66px;height:66px;margin:0 auto 18px;animation:tk-bob 2.6s ease-in-out infinite}
@keyframes tk-bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
.tk-inst-halo{position:absolute;inset:-12px;border-radius:28px;
 background:radial-gradient(closest-side,color-mix(in srgb,var(--tk-a) 42%,transparent),transparent 72%);
 animation:tk-halo 2.4s ease-in-out infinite}
@keyframes tk-halo{0%,100%{opacity:.5;transform:scale(.92)}50%{opacity:1;transform:scale(1.08)}}
.tk-inst-ico{position:relative;display:flex;align-items:center;justify-content:center;width:66px;height:66px;
 border-radius:19px;overflow:hidden;color:#fff;font-size:26px;
 background:linear-gradient(135deg,var(--tk-a),var(--tk-a2));
 box-shadow:0 12px 26px -10px color-mix(in srgb,var(--tk-a) 78%,transparent),inset 0 1px 0 rgba(255,255,255,.34)}
.tk-inst-ico img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.tk-inst-badge{position:absolute;right:-4px;bottom:-4px;width:26px;height:26px;border-radius:50%;
 display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;
 background:linear-gradient(135deg,var(--tk-ok),var(--tk-ok2));
 box-shadow:0 4px 12px -3px rgba(16,120,86,.7),0 0 0 3px var(--tk-card);
 transform:scale(0);opacity:0;transition:transform .42s cubic-bezier(.34,1.56,.64,1),opacity .2s}
.tk-inst.is-done .tk-inst-badge,.tk-inst.is-fail .tk-inst-badge{transform:scale(1);opacity:1}
.tk-inst.is-fail .tk-inst-badge{background:linear-gradient(135deg,var(--tk-no),color-mix(in srgb,var(--tk-no) 55%,#fff));
 box-shadow:0 4px 12px -3px color-mix(in srgb,var(--tk-no) 70%,transparent),0 0 0 3px var(--tk-card)}
.tk-inst-name{margin:0;text-align:center;font-size:16px;font-weight:650;line-height:1.4;color:var(--tk-fg);
 overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-inst-meta{margin:5px 0 0;text-align:center;font-size:12px;line-height:1.4;color:var(--tk-fg2);
 overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-inst-track{position:relative;height:5px;margin-top:20px;border-radius:99px;overflow:hidden;
 background:color-mix(in srgb,var(--tk-fg) 11%,transparent)}
.tk-inst-fill{position:absolute;top:0;bottom:0;left:-42%;width:42%;border-radius:99px;
 background:linear-gradient(90deg,transparent,var(--tk-a),var(--tk-a2),transparent);
 animation:tk-shuttle 1.45s cubic-bezier(.62,.06,.36,.94) infinite}
/* 第二道梭形，错开半个周期。单独一道在扫出右端到重新进入左端之间有一段空档，
   进度条会显得卡住了；两道轮流补位，任何时刻轨道上都有东西在动。用伪元素做，
   不多加 DOM，成功/失败时连同动画一起收掉 */
.tk-inst-track::after{content:"";position:absolute;top:0;bottom:0;left:-42%;width:42%;border-radius:99px;
 background:linear-gradient(90deg,transparent,var(--tk-a),var(--tk-a2),transparent);
 animation:tk-shuttle 1.45s cubic-bezier(.62,.06,.36,.94) .72s infinite}
.tk-inst.is-done .tk-inst-track::after,.tk-inst.is-fail .tk-inst-track::after{display:none}
@keyframes tk-shuttle{0%{transform:translateX(0)}100%{transform:translateX(338%)}}
.tk-inst-foot{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-top:12px;
 font-size:12px;line-height:1.5;color:var(--tk-fg2)}
.tk-inst-stage{flex:1 1 auto;min-width:0}
.tk-inst-time{flex:none;font-variant-numeric:tabular-nums;letter-spacing:.02em;opacity:0;transition:opacity .35s}
.tk-inst-time.is-on{opacity:1}
.tk-inst-time > i{margin-right:4px;font-size:10px}
.tk-inst.is-done .tk-inst-art,.tk-inst.is-fail .tk-inst-art{animation:none}
.tk-inst.is-done .tk-inst-halo,.tk-inst.is-fail .tk-inst-halo{animation:none;opacity:0;transition:opacity .3s}
.tk-inst.is-done .tk-inst-fill{animation:none;left:0;transform:none;
 background:linear-gradient(90deg,var(--tk-ok),var(--tk-ok2))}
.tk-inst.is-fail .tk-inst-fill{animation:none;left:0;transform:none;width:100%;
 background:linear-gradient(90deg,var(--tk-no),color-mix(in srgb,var(--tk-no) 60%,#fff))}
.tk-inst.is-done .tk-inst-stage{color:var(--tk-ok);font-weight:600}
.tk-inst.is-fail .tk-inst-stage{color:var(--tk-no);font-weight:600}
.tk-inst.is-fail .tk-inst-card{animation:tk-shake .42s cubic-bezier(.36,.07,.19,.97)}
@keyframes tk-shake{10%,90%{transform:translateX(-2px)}30%,70%{transform:translateX(4px)}50%{transform:translateX(-5px)}}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .tk-inst-card{
 --tk-card:rgba(30,32,40,.92);border-color:rgba(255,255,255,.09);
 box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 34px 74px -24px rgba(0,0,0,.75)}}
:root[data-theme="dark"] .tk-inst-card{--tk-card:rgba(30,32,40,.92);border-color:rgba(255,255,255,.09);
 box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 34px 74px -24px rgba(0,0,0,.75)}
@media (prefers-reduced-motion:reduce){
 .tk-inst-art,.tk-inst-halo{animation:none}
 .tk-inst-fill,.tk-inst-track::after{animation-duration:2.8s;animation-timing-function:linear}
 .tk-inst.is-fail .tk-inst-card{animation:none}}
`;
            document.head.appendChild(s);
        };
        //已用时长走 mm:ss：纯数字，不用翻译，中英日繁都读得懂
        const clock = seconds => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        return {
            //pjax 切页时如果还有卡片挂着，直接收掉，别把遮罩留在新页面上
            closeAll() {
                living.forEach(close => close());
                living.clear();
            },
            start(options) {
                ensureStyle();
                const opt = options || {};
                const iconUrl = normalizeHttpUrl(opt.icon);
                const wrap = document.createElement('div');
                wrap.className = 'tk-inst';
                wrap.innerHTML =
                    `<div class="tk-inst-card" role="status" aria-live="polite" aria-busy="true">
                        <div class="tk-inst-art">
                            <span class="tk-inst-halo" aria-hidden="true"></span>
                            <span class="tk-inst-ico">
                                <i class="fa-duotone fa-regular ${escapeHtml(opt.glyph || 'fa-cloud-arrow-down')}" aria-hidden="true"></i>
                                ${iconUrl ? `<img src="${escapeHtml(iconUrl)}" alt="" onerror="this.remove()">` : ''}
                            </span>
                            <span class="tk-inst-badge" aria-hidden="true"><i class="fa-duotone fa-regular fa-check"></i></span>
                        </div>
                        <p class="tk-inst-name">${escapeHtml(opt.name || '')}</p>
                        <p class="tk-inst-meta">${escapeHtml(opt.meta || '')}</p>
                        <div class="tk-inst-track" role="progressbar" aria-label="${escapeHtml(opt.stage || '')}"><span class="tk-inst-fill"></span></div>
                        <div class="tk-inst-foot">
                            <span class="tk-inst-stage">${escapeHtml(opt.stage || '')}</span>
                            <span class="tk-inst-time"><i class="fa-duotone fa-regular fa-clock" aria-hidden="true"></i><span class="tk-inst-sec"></span></span>
                        </div>
                    </div>`;
                document.body.appendChild(wrap);

                const fill = wrap.querySelector('.tk-inst-fill');
                const stage = wrap.querySelector('.tk-inst-stage');
                const timeBox = wrap.querySelector('.tk-inst-time');
                const secBox = wrap.querySelector('.tk-inst-sec');
                const badge = wrap.querySelector('.tk-inst-badge i');
                //入场靠加 is-in 触发过渡。rAF 在后台标签页里不触发，只用它的话卡片会停在
                //全透明状态——看不见，却照样是个铺满全屏的遮罩挡着点击。补一个定时器兜底。
                const reveal = () => wrap.classList.add('is-in');
                requestAnimationFrame(reveal);
                setTimeout(reveal, 60);

                //计时用真实时间差而不是累加 tick：后台标签页里 setInterval 会被节流，
                //累加会越走越慢，读秒就不准了
                const startedAt = Date.now();
                let hinted = 0;
                const tick = setInterval(() => {
                    const seconds = Math.floor((Date.now() - startedAt) / 1000);
                    if (seconds >= 3) {
                        secBox.textContent = clock(seconds);
                        timeBox.classList.add('is-on');
                    }
                    //久了逐级换文案：先解释为什么慢，再明确"别关页面"
                    if (seconds >= 45 && hinted < 2) {
                        hinted = 2;
                        stage.textContent = i18n('仍在进行中，请不要关闭或刷新页面');
                    } else if (seconds >= 15 && hinted < 1) {
                        hinted = 1;
                        stage.textContent = i18n('安装包较大时会久一些，请不要关闭页面');
                    }
                }, 1000);

                const close = () => {
                    clearInterval(tick);
                    living.delete(close);
                    wrap.classList.remove('is-in');
                    setTimeout(() => wrap.remove(), 340);
                };
                living.add(close);

                const settle = (state, text) => {
                    clearInterval(tick);
                    wrap.classList.add(state);
                    const card = wrap.querySelector('.tk-inst-card');
                    if (card) card.setAttribute('aria-busy', 'false');
                    if (text) stage.textContent = text;
                    return card;
                };

                return {
                    succeed(doneText) {
                        settle('is-done', doneText);
                        if (badge) badge.className = 'fa-duotone fa-regular fa-check';
                        //梭形停下后从 0 铺满，比直接跳到 100% 顺眼。
                        //同入场：rAF 在后台标签页不触发，加定时器兜底，免得进度条停在空槽
                        fill.style.width = '0%';
                        const flood = () => {
                            fill.style.transition = 'width .42s cubic-bezier(.32,.72,0,1)';
                            fill.style.width = '100%';
                        };
                        requestAnimationFrame(flood);
                        setTimeout(flood, 60);
                        const track = wrap.querySelector('.tk-inst-track');
                        if (track) track.setAttribute('aria-valuenow', '100');
                        setTimeout(close, 900);
                    },
                    fail(failText) {
                        settle('is-fail', failText || i18n('安装未完成'));
                        if (badge) badge.className = 'fa-duotone fa-regular fa-xmark';
                        //失败要看得见：停一下再走，否则卡片一闪而过，只剩一句错误提示
                        setTimeout(close, 900);
                    }
                };
            }
        };
    })();
    const namespace = '.mdStoreHomeController';
    let controllerActive = true;
    let authPopupOpen = false;
    let storeAuthenticated = false;
    let purchaseConfirming = false;
    let purchaseRequesting = false;
    const controllerTimers = new Set();
    const scheduleControllerTask = (callback, delay) => {
        const timer = setTimeout(() => {
            controllerTimers.delete(timer);
            if (controllerActive) callback();
        }, delay);
        controllerTimers.add(timer);
        return timer;
    };
    const destroyNestedTable = table => {
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
    };
    if (typeof window.__mdStoreHomeDestroy === 'function') window.__mdStoreHomeDestroy();
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const renderStoreInlineHtml = value => typeof component !== 'undefined' && typeof component.sanitizeInlineHtml === 'function'
        ? component.sanitizeInlineHtml(value)
        : escapeHtml(value);
    const storePlainText = value => typeof component !== 'undefined' && typeof component.plainInlineText === 'function'
        ? component.plainInlineText(value)
        : util.plainText(String(value ?? ''));
    const normalizePurchasePrice = value => {
        if (value === undefined || value === null || String(value).trim() === '') return null;
        const price = Number(value);
        return Number.isFinite(price) && price > 0 ? price : null;
    };
    const normalizeHttpUrl = value => {
        if (!value) return null;
        try {
            const url = new URL(String(value), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
        } catch (error) {
            return null;
        }
    };
    //进度卡片副标题："v1.0.3 · 通用扩展"。类型字典里存的是带徽章的 HTML，要扒成纯文字
    const pluginMeta = row => [
        row?.version ? 'v' + row.version : '',
        _Dict.result('_store_plugin_type', row?.type)
    ].map(part => storePlainText(part).trim()).filter(Boolean).join(' · ');
    const normalizeCaptchaSource = value => {
        const source = String(value ?? '').trim();
        if (!source) return null;
        const match = source.match(/^data:image\/(png|jpe?g|gif|webp);base64,([\s\S]+)$/i);
        const mime = match ? `image/${match[1].toLowerCase()}` : 'image/png';
        const payload = (match ? match[2] : source).replace(/\s+/g, '').replace(/=+$/, '');
        if (payload.length < 16 || !/^[a-z0-9+/]+$/i.test(payload) || payload.length % 4 === 1) return null;
        return `data:${mime};base64,${payload}${'='.repeat((4 - payload.length % 4) % 4)}`;
    };
    const captchaBlobUrl = source => {
        if (!source || typeof URL?.createObjectURL !== 'function') return null;
        try {
            const parts = source.match(/^data:(image\/(?:png|jpe?g|gif|webp));base64,(.+)$/i);
            if (!parts) return null;
            const binary = window.atob(parts[2]);
            const bytes = new Uint8Array(binary.length);
            for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
            return URL.createObjectURL(new Blob([bytes], {type: parts[1]}));
        } catch (error) {
            return null;
        }
    };
    const requestPurchase = purchase => {
        const send = () => {
            if (!controllerActive || purchaseRequesting) return;
            purchaseRequesting = true;
            const options = {
                url: '/admin/api/app/purchase',
                data: {
                    type: purchase.type,
                    plugin_id: purchase.pluginId,
                    payType: purchase.payType
                },
                done: res => {
                    purchaseRequesting = false;
                    if (!controllerActive) return;
                    const checkoutUrl = normalizeHttpUrl(res?.data?.url);
                    if (!checkoutUrl) {
                        message.error('支付订单已返回，但收银台地址无效，请勿重复付款并联系管理员。');
                        return;
                    }
                    layer.msg(escapeHtml(res?.msg || '支付订单已创建'));
                    window.location.assign(checkoutUrl);
                },
                error: res => {
                    purchaseRequesting = false;
                    if (controllerActive) message.error(storePlainText(res?.msg) || '支付订单创建失败。');
                },
                fail: () => {
                    purchaseRequesting = false;
                    if (controllerActive) message.error('网络异常，支付订单未创建。');
                }
            };
            util.post(options);
        };

        if (!mobileAdminEnabled()) {
            send();
            return;
        }
        if (purchaseConfirming || purchaseRequesting || !controllerActive) return;
        const purchasePrice = normalizePurchasePrice(purchase.price);
        if (purchasePrice === null) {
            message.warning('商品价格尚未加载完成，请稍后重试。');
            return;
        }
        const payNames = {0: '支付宝', 1: '微信支付', 2: 'USDT（TRC20）'};
        const productName = escapeHtml(purchase.productName || '应用商店商品');
        const price = escapeHtml(purchasePrice);
        const payName = escapeHtml(payNames[purchase.payType] || '所选支付方式');
        purchaseConfirming = true;
        Swal.fire({
            title: '确认创建支付订单',
            html: `<div style="text-align:left;line-height:1.8;">
                <div><b>${i18n('购买项目：')}</b>${productName}</div>
                <div><b>${i18n('商品价格：')}</b>¥${price}</div>
                <div><b>${i18n('支付方式：')}</b>${payName}</div>
                <div class="mt-2 text-danger">${i18n('确认后将创建支付订单并跳转至外部收银台；付款完成后会生成对应授权，不能通过返回本页撤销。')}</div>
            </div>`,
            icon: 'warning',
            showCancelButton: true,
            cancelButtonText: '暂不购买',
            confirmButtonText: '确认并前往支付'
        }).then(result => {
            purchaseConfirming = false;
            if (result.isConfirmed === true || result.value === true) send();
        });
    };
    const openExternal = value => {
        if (!value || value === '#') return false;
        const source = /^[a-z][a-z0-9+.-]*:/i.test(value) ? value : 'https://' + value;
        const url = normalizeHttpUrl(source);
        if (!url) return false;
        window.open(url, '_blank', 'noopener,noreferrer');
        return true;
    };
    const renderExternalLink = value => {
        if (!value || value === '#') return '-';
        const source = /^[a-z][a-z0-9+.-]*:/i.test(value) ? value : 'https://' + value;
        const url = normalizeHttpUrl(source);
        return url ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(value)}</a>` : escapeHtml(value);
    };
    const renderPluginIdentity = item => {
        const icon = normalizeHttpUrl(item?.icon);
        const iconHtml = icon ? `<img src="${escapeHtml(icon)}" class="md-plugin__icon" alt="">` : '<span class="md-plugin__icon material-icons-outlined" aria-hidden="true">apps</span>';
        return `<div class="md-plugin">${iconHtml}<span class="md-plugin__name">${renderStoreInlineHtml(item?.plugin_name || '')}</span></div>`;
    };
    const configureEnterpriseCta = ($button, reconnect = false) => {
        const title = reconnect ? '重新开通企业版' : '开通企业版';
        const description = reconnect ? '当前设备重新授权 · 其他设备不受影响' : '全部插件免费 · 专属技术支持';

        $button
            .addClass('admin-mobile-store-enterprise-cta admin-mobile-store-enterprise-cta--primary')
            .attr({
                'data-admin-mobile-label': title,
                'data-admin-mobile-description': description
            });

        $button.html(`<span class="material-icons-outlined admin-mobile-store-enterprise-cta__icon" aria-hidden="true">workspace_premium</span>
            <span class="admin-mobile-store-enterprise-cta__copy">
                <strong class="admin-mobile-store-enterprise-cta__title">${title}</strong>
                <small class="admin-mobile-store-enterprise-cta__description">${description}</small>
            </span>
            <span class="material-icons-outlined admin-mobile-store-enterprise-cta__arrow" aria-hidden="true">arrow_forward</span>`);
    };
    const configureEnterpriseBindCta = $button => {
        const title = '绑定专业版/企业版';
        const description = '转移已有授权 · 原设备将解除绑定';

        $button
            .addClass('admin-mobile-store-enterprise-cta admin-mobile-store-enterprise-cta--secondary')
            .attr({
                'data-admin-mobile-label': title,
                'data-admin-mobile-description': description
            });

        $button.html(`<span class="material-icons-outlined admin-mobile-store-enterprise-cta__icon" aria-hidden="true">link</span>
            <span class="admin-mobile-store-enterprise-cta__copy">
                <strong class="admin-mobile-store-enterprise-cta__title">${title}</strong>
                <small class="admin-mobile-store-enterprise-cta__description">${description}</small>
            </span>
            <span class="material-icons-outlined admin-mobile-store-enterprise-cta__arrow" aria-hidden="true">arrow_forward</span>`);
    };
    const table = new Table("/admin/api/app/plugins", "#plugin-table");
    const $StoreRoot = $('.store-content').first();
    const $StoreContent = $StoreRoot.parent();

    function showServiceState(type, title, copy, retry) {
        if (!controllerActive) return;
        const $container = $StoreRoot.find('#kt_content_container').first();
        if (!$container.length) return;

        $StoreContent.show();
        $container.children('.card').not('.admin-store-service-state').hide();
        $container.children('.admin-store-auth-gate').remove();

        const loading = type === 'loading';
        const stateClass = loading ? '' : ' admin-mobile-load-state--error';
        const indicator = loading
            ? '<span class="admin-mobile-load-spinner" aria-hidden="true"></span>'
            : '<span class="material-icons-outlined" aria-hidden="true">cloud_off</span>';
        const button = typeof retry === 'function'
            ? '<button type="button" class="btn btn-light-primary admin-store-service-retry">' + i18n('重新加载') + '</button>'
            : '';
        const liveRole = loading ? 'status' : 'alert';
        let $state = $container.children('.admin-store-service-state').first();
        if (!$state.length) {
            $state = $('<section class="card mb-5 admin-store-service-state"></section>').prependTo($container);
        }
        $state.html(`<div class="card-body admin-mobile-load-state${stateClass}" role="${liveRole}" aria-live="polite">
            ${indicator}<strong>${escapeHtml(title)}</strong><small>${escapeHtml(copy)}</small>${button}
        </div>`).show();
        $state.find('.admin-store-service-retry')
            .off('click.mdStoreServiceRetry')
            .on('click.mdStoreServiceRetry', retry || $.noop);
    }

    function clearServiceState(showCards = true) {
        const $container = $StoreRoot.find('#kt_content_container').first();
        $container.children('.admin-store-service-state').remove();
        if (showCards) $container.children('.card').not('.admin-store-auth-gate').show();
    }

    if (mobileAdminEnabled()) {
        showServiceState('loading', '正在连接应用商店', '正在读取账户、授权和应用列表。');
    } else {
        $StoreContent.hide();
    }

    function showAuthGate() {
        if (!controllerActive || storeAuthenticated) return;
        const $container = $StoreRoot.find('#kt_content_container').first();
        if (!$container.length) return;

        clearServiceState(false);
        $StoreContent.show();
        $container.children('.card').not('.admin-store-auth-gate').hide();

        let $gate = $container.children('.admin-store-auth-gate').first();
        if (!$gate.length) {
            $gate = $(`<section class="card mb-5 admin-store-auth-gate" aria-labelledby="admin-store-auth-title">
                <div class="card-body admin-mobile-empty d-flex align-items-center justify-content-center flex-column text-center py-10 px-6">
                    <span class="material-icons-outlined text-primary mb-2" aria-hidden="true">storefront</span>
                    <strong id="admin-store-auth-title" class="fs-2">${i18n('登录应用商店')}</strong>
                    <p class="text-muted mb-3">${i18n('登录后可查看已购买资源、授权和应用商店内容。')}</p>
                    <button type="button" class="btn btn-primary admin-store-auth-open">
                        <i class="fa-duotone fa-regular fa-right-to-bracket" aria-hidden="true"></i>
                        ${i18n('登录或注册')}
                    </button>
                </div>
            </section>`).prependTo($container);
        }

        $gate.show().find('.admin-store-auth-open')
            .off('click.mdStoreAuthGate')
            .on('click.mdStoreAuthGate', _Auth);
    }

    function hideAuthGate() {
        const $container = $StoreRoot.find('#kt_content_container').first();
        $container.children('.admin-store-auth-gate').remove();
        clearServiceState(true);
    }

    function _Auth() {
        showAuthGate();
        if (authPopupOpen || !controllerActive) return;
        authPopupOpen = true;

        try {
            component.popup({
            submit: false,
            mobileTitle: '应用商店账户',
            tab: [
                {
                    name: '登录',
                    form: [
                        {
                            title: false,
                            name: "login_page",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="admin-store-auth-panel admin-store-auth-panel--login">
                  <div class="admin-store-auth-intro">
                    <span class="material-icons-outlined" aria-hidden="true">lock</span>
                    <div>
                      <strong>${i18n('登录应用商店')}</strong>
                      <p>${i18n('访问应用商店需要登录，应用商店可以下载大量插件和模版')}</p>
                    </div>
                  </div>

                  <div class="form-store-login admin-store-auth-form">
                    <div class="form-floating admin-store-auth-field">
                      <input type="text" class="form-control" id="login-username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="${i18n('用户名')}">
                      <label class="form-label" for="login-username">${i18n('用户名')}</label>
                    </div>

                    <div class="form-floating admin-store-auth-field">
                      <input type="password" class="form-control" id="login-password" name="password" autocomplete="current-password" autocapitalize="none" spellcheck="false" placeholder="${i18n('请输入密码')}">
                      <label class="form-label" for="login-password">${i18n('密码')}</label>
                    </div>

                    <button type="button" class="admin-mobile-button admin-mobile-button--primary admin-store-auth-submit btn-login">
                      <span class="material-icons-outlined" aria-hidden="true">login</span>
                      <span>${i18n('登录应用商店')}</span>
                    </button>
                  </div>
                </div>`);

                                const $loginForm = dom.find('.form-store-login');
                                const $username = $loginForm.find('#login-username');
                                const $password = $loginForm.find('#login-password');
                                const $submit = $loginForm.find('.btn-login');
                                let loginSubmitting = false;
                                const restoreLogin = () => {
                                    loginSubmitting = false;
                                    $submit.prop('disabled', false).removeAttr('aria-busy');
                                };
                                const submitLogin = () => {
                                    if (!controllerActive || loginSubmitting) return;
                                    loginSubmitting = true;
                                    $submit.prop('disabled', true).attr('aria-busy', 'true');
                                    util.post({
                                        url: "/admin/api/app/login",
                                        data: {
                                            username: $username.val(),
                                            password: $password.val()
                                        },
                                        done: () => {
                                            if (!controllerActive) return;
                                            message.success("登录成功");
                                            window.location.reload();
                                        },
                                        error: res => {
                                            restoreLogin();
                                            if (controllerActive) message.error(storePlainText(res?.msg) || '登录失败，请检查账号和密码。');
                                        },
                                        fail: () => {
                                            restoreLogin();
                                            if (controllerActive) message.error('网络异常，应用商店登录请求未完成。');
                                        }
                                    });
                                };

                                $password.on("keydown", function (e) {
                                    if (e.key === "Enter" || e.keyCode === 13) {
                                        e.preventDefault();
                                        submitLogin();
                                    }
                                });
                                $submit.on('click', submitLogin);
                            }
                        }
                    ]
                },
                {
                    name: '注册',
                    form: [
                        {
                            title: false,
                            name: "register_page",
                            type: "custom",
                            complete: (form, dom) => {
                                let captchaCookie = null;
                                let captchaLoading = false;
                                let registerSubmitting = false;
                                let captchaObjectUrl = '';
                                let captchaViewActive = true;

                                dom.html(`<div class="admin-store-auth-panel admin-store-auth-panel--register">
                  <div class="admin-store-auth-intro">
                    <span class="material-icons-outlined" aria-hidden="true">person_add</span>
                    <div>
                      <strong>${i18n('创建应用商店账户')}</strong>
                      <p>${i18n('账号忘记或丢失无法找回，请在注册时妥善保管账号和密码')}</p>
                    </div>
                  </div>

                  <div class="form-store-register admin-store-auth-form">
                    <div class="form-floating admin-store-auth-field">
                      <input type="text" class="form-control" id="register-username" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="${i18n('用户名')}">
                      <label class="form-label" for="register-username">${i18n('用户名')}</label>
                    </div>

                    <div class="form-floating admin-store-auth-field">
                      <input type="password" class="form-control" id="register-password" autocomplete="new-password" autocapitalize="none" spellcheck="false" placeholder="${i18n('请设置登录密码')}">
                      <label class="form-label" for="register-password">${i18n('登录密码')}</label>
                    </div>

                    <div class="admin-store-auth-captcha-row">
                      <div class="form-floating admin-store-auth-field">
                        <input type="text" class="form-control" id="register-captcha" inputmode="text" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="${i18n('请输入验证码')}">
                        <label class="form-label" for="register-captcha">${i18n('图形验证码')}</label>
                      </div>
                      <button type="button" class="admin-store-auth-captcha is-loading" aria-label="${i18n('刷新图形验证码')}" aria-busy="true">
                        <img class="img-captcha-register" alt="${i18n('图形验证码')}">
                        <span class="admin-store-auth-captcha__state" aria-hidden="true"><span class="material-icons-outlined">refresh</span></span>
                      </button>
                    </div>
                    <small class="admin-store-auth-captcha-hint">${i18n('看不清？点击图片刷新')}</small>

                    <button type="button" class="admin-mobile-button admin-mobile-button--primary admin-store-auth-submit btn-register">
                      <span class="material-icons-outlined" aria-hidden="true">person_add</span>
                      <span>${i18n('创建账户')}</span>
                    </button>
                  </div>
                </div>`);

                                const $registerForm = dom.find('.form-store-register');
                                const $username = $registerForm.find('#register-username');
                                const $password = $registerForm.find('#register-password');
                                const $captcha = $registerForm.find('#register-captcha');
                                const $imageCode = $registerForm.find('.img-captcha-register');
                                const $submit = $registerForm.find('.btn-register');
                                const $captchaRefresh = $registerForm.find('.admin-store-auth-captcha').length
                                    ? $registerForm.find('.admin-store-auth-captcha')
                                    : $imageCode;
                                const revokeCaptchaObjectUrl = () => {
                                    if (!captchaObjectUrl) return;
                                    URL.revokeObjectURL(captchaObjectUrl);
                                    captchaObjectUrl = '';
                                };
                                const setCaptchaFailed = () => {
                                    if (!captchaViewActive) return;
                                    revokeCaptchaObjectUrl();
                                    captchaLoading = false;
                                    captchaCookie = null;
                                    $captchaRefresh.removeClass('is-loading').addClass('is-error').removeAttr('aria-busy');
                                    $imageCode.removeAttr('aria-busy');
                                };
                                const renderCaptcha = value => {
                                    if (!captchaViewActive) return false;
                                    const source = normalizeCaptchaSource(value);
                                    revokeCaptchaObjectUrl();
                                    $imageCode.off('.mdStoreCaptcha');
                                    if (!source) {
                                        setCaptchaFailed();
                                        return false;
                                    }

                                    let fallbackAttempted = false;
                                    $imageCode
                                        .on('load.mdStoreCaptcha', function () {
                                            if (!captchaViewActive) return;
                                            if (!this.naturalWidth || !this.naturalHeight) return;
                                            captchaLoading = false;
                                            $captchaRefresh.removeClass('is-loading is-error').removeAttr('aria-busy');
                                            $imageCode.removeAttr('aria-busy');
                                        })
                                        .on('error.mdStoreCaptcha', function () {
                                            if (!captchaViewActive) return;
                                            if (!fallbackAttempted) {
                                                fallbackAttempted = true;
                                                const objectUrl = captchaBlobUrl(source);
                                                if (objectUrl) {
                                                    captchaObjectUrl = objectUrl;
                                                    $imageCode.attr('src', objectUrl);
                                                    return;
                                                }
                                            }
                                            setCaptchaFailed();
                                        })
                                        .attr('src', source);
                                    return true;
                                };
                                if (form && typeof form.registerDisposable === 'function') {
                                    form.registerDisposable(() => {
                                        captchaViewActive = false;
                                        captchaLoading = false;
                                        captchaCookie = null;
                                        $imageCode.off('.mdStoreCaptcha');
                                        revokeCaptchaObjectUrl();
                                    });
                                }

                                function _register_captcha(loader = false) {
                                    if (!controllerActive || !captchaViewActive || captchaLoading) return;
                                    captchaLoading = true;
                                    $captchaRefresh.addClass('is-loading').removeClass('is-error').attr('aria-busy', 'true');
                                    $imageCode.attr('aria-busy', 'true');
                                    util.post({
                                        url: '/admin/api/app/captcha?type=captcha_reg',
                                        loader: loader,
                                        done: res => {
                                            if (!controllerActive || !captchaViewActive) return;
                                            const responseCookie = res?.data?.cookie;
                                            if (!responseCookie || typeof responseCookie !== 'object' || !String(responseCookie.GOLANG_ID ?? '')) {
                                                setCaptchaFailed();
                                                message.error('验证码校验凭据无效，请点击图片重试。');
                                                return;
                                            }
                                            captchaCookie = responseCookie;
                                            if (!renderCaptcha(res?.data?.base64)) {
                                                message.error('验证码图片数据无效，请点击图片重试。');
                                            }
                                        },
                                        error: res => {
                                            if (!controllerActive || !captchaViewActive) return;
                                            setCaptchaFailed();
                                            message.error(storePlainText(res?.msg) || '验证码加载失败，请点击图片重试。');
                                        },
                                        fail: () => {
                                            if (!controllerActive || !captchaViewActive) return;
                                            setCaptchaFailed();
                                            message.error('网络异常，验证码加载失败，请点击图片重试。');
                                        }
                                    });
                                }

                                _register_captcha();

                                $captchaRefresh.on('click', () => {
                                    _register_captcha(false);
                                });

                                const restoreRegister = () => {
                                    registerSubmitting = false;
                                    $submit.prop('disabled', false).removeAttr('aria-busy');
                                };
                                $submit.click(() => {
                                    if (!controllerActive || registerSubmitting) return;
                                    if (!captchaCookie) {
                                        message.warning('验证码尚未加载，请点击验证码图片重试。');
                                        return;
                                    }
                                    registerSubmitting = true;
                                    $submit.prop('disabled', true).attr('aria-busy', 'true');
                                    util.post({
                                        url: "/admin/api/app/register",
                                        data: {
                                            username: $username.val(),
                                            password: $password.val(),
                                            captcha: $captcha.val(),
                                            cookie: captchaCookie
                                        },
                                        done: () => {
                                            if (!controllerActive) return;
                                            message.success("注册成功");
                                            window.location.reload();
                                        },
                                        error: res => {
                                            restoreRegister();
                                            if (!controllerActive) return;
                                            message.error(storePlainText(res?.msg) || '注册失败，请检查填写内容。');
                                            _register_captcha(false);
                                        },
                                        fail: () => {
                                            restoreRegister();
                                            if (controllerActive) message.error('网络异常，注册请求未完成。');
                                        }
                                    });
                                });
                            }
                        }
                    ]
                }
            ],
            closeBtn: 1,
            maxmin: false,
            autoPosition: true,
            width: "456px",
            end: () => {
                authPopupOpen = false;
                showAuthGate();
            }
            });
        } catch (error) {
            authPopupOpen = false;
            showAuthGate();
            throw error;
        }


    }

    function _Bill(plugin = {}) {
        let billModalIndex = 0;
        let tabs = [];
        let enterpriseTabIndex = -1;

        if (!util.isEmptyOrNotJson(plugin)) {
            const pluginPrice = normalizePurchasePrice(plugin.price);
            const pluginPriceText = pluginPrice === null ? '价格加载中' : `¥${escapeHtml(pluginPrice)}`;
            const pluginOriginalPrice = pluginPrice === null ? '请稍后重试' : `${i18n('原价')}:${escapeHtml(pluginPrice * 2)}`;
            const pluginDisabled = pluginPrice === null ? ' aria-disabled="true"' : '';
            const pluginId = Number(plugin.id) || 0;
            tabs.push({
                name: `<div class="common-item">${normalizeHttpUrl(plugin?.icon) ? `<img src="${escapeHtml(normalizeHttpUrl(plugin.icon))}" class="item-icon" style="width:20px;height:20px;" alt="">` : ''}<div class="item-name" style="font-size:1rem;">${renderStoreInlineHtml(plugin?.plugin_name || '')}</div></div>`,
                form: [
                    {
                        title: false,
                        name: "introduce_plugin",
                        type: "custom",
                        complete: (form, dom) => {
                            let payList = '', selectedSubscription, selectAmount;
                            dom.html(`<div>     
<div class="alert alert-success" role="alert">
                    <p class="mb-0">
                      ${i18n('您所购买的插件，将统一归属于您的应用商店账户名下。无论您更换服务器或重新安装程序，只需登录购买时所使用的应用商店账户，即可迅速将产品绑定至新的网站上。')}
                    </p>
                  </div>          
            
                    <div class="mb-3 store-introduce">
                      ${renderStoreInlineHtml(i18n(plugin.description))}
                    </div>
                    
                    <div class="subscription-container">
                        <div class="layout-box">
                                <div class="title"><i class="fa-duotone fa-regular fa-clock"></i> ${i18n('订阅类型')}</div>
                                <div class="subscription-list online-pay"><div class="subscription-item" data-amount="${pluginPrice === null ? '' : escapeHtml(pluginPrice)}"${pluginDisabled}><span class="text-warning fs-3 fw-bold">${pluginPriceText}</span><span class="text-muted" style="font-size:13px;text-decoration:line-through;">${pluginOriginalPrice}</span><span class="text-warning" style="font-size:12px;">${i18n('终身可用')}</span></div></div>
                        </div>
                        
                    
                     
                        
                        <div class="layout-box">
                                        <div class="title"><i class="fa-duotone fa-regular fa-star-shooting"></i> ${i18n('付款购买')} ${plugin.group > 0 ? `<span class="text-success"> ${i18n('此插件企业版免费用，开通企业版更省钱更超值！')}<a href="javascript:void(0);" class="text-primary open-group-enterprise-click">${i18n('点我开企业版')}</a></span>` : ""}</div>
                                            <div class="pay-list online-pay">
                                                <div data-id="${pluginId}" data-type="0" data-pay="0" class="pay-item online-pay-click"${pluginDisabled}><img class="item-icon" src="/assets/common/images/alipay.png"><span>${i18n('支付宝')}</span></div>
                                                <div data-id="${pluginId}" data-type="0" data-pay="1" class="pay-item online-pay-click"${pluginDisabled}><img class="item-icon" src="/assets/common/images/wx.png"><span>${i18n('微信支付')}</span></div>
                                                <div data-id="${pluginId}" data-type="0" data-pay="2" class="pay-item online-pay-click"${pluginDisabled}><img class="item-icon" src="/assets/common/images/usdt.png"><span>USDT(TRC20)</span></div>
                                            </div>
   
                        </div> 
                    </div>
              </div>`);

                            dom.find('.open-group-enterprise-click').off('click.mdStoreEnterpriseTab').on('click.mdStoreEnterpriseTab', event => {
                                event.preventDefault();
                                event.stopPropagation();
                                if (enterpriseTabIndex < 0) return;

                                const mobileActivator = window.AdminMobile?.activatePopupTab;
                                if (typeof mobileActivator === 'function'
                                    && mobileActivator.call(window.AdminMobile, event.currentTarget, enterpriseTabIndex, true)) {
                                    return;
                                }

                                const $popup = $(event.currentTarget).closest('.component-popup');
                                const $desktopTab = $popup.find('.layui-layer-title > span').eq(enterpriseTabIndex);
                                if ($desktopTab.length) {
                                    $desktopTab.trigger('mousedown');
                                    $popup.find('.layui-layer-content').scrollTop(0);
                                }
                            });

                            const $onlinePay = dom.find(".online-pay-click"); 
                            $onlinePay.click(function () {
                                const type = $(this).data("type");
                                const pay = $(this).data("pay");
                                let pluginId = $(this).data("id");
                                pluginId = pluginId ? pluginId : 0;
                                requestPurchase({
                                    type: type,
                                    pluginId: pluginId,
                                    payType: pay,
                                    productName: storePlainText(plugin.plugin_name || plugin.name || '应用插件'),
                                    price: pluginPrice
                                });
                            });
                        }
                    }
                ]
            });
        }

        if (util.isEmptyOrNotJson(plugin) || plugin.group > 0) {
            enterpriseTabIndex = tabs.length;
            tabs.push({
                name: `<div class="common-item open-group-enterprise"><i class="fa-duotone fa-regular fa-user me-1"></i> <div class="item-name" style="font-size: 1rem;">${i18n('开通企业版')}(${i18n('推荐')})</div></div>`,
                form: [
                    {
                        title: false,
                        name: "introduce_group",
                        type: "custom",
                        complete: (form, dom) => {
                            let payList = '', selectedSubscription, selectAmount;
                            const enterprisePrice = normalizePurchasePrice(_GroupPrice);
                            const enterprisePriceText = enterprisePrice === null ? '价格加载中' : `¥${escapeHtml(enterprisePrice)}`;
                            const enterpriseOriginalPrice = enterprisePrice === null ? '请稍后重试' : `${i18n('原价')}:${escapeHtml(enterprisePrice * 2)}`;
                            const enterpriseDisabled = enterprisePrice === null ? ' aria-disabled="true"' : '';
                            dom.html(`<div>     
<div class="alert alert-success" role="alert">
                    <p class="mb-0">
                      ${i18n('您所购买的企业版，将统一归属于您的应用商店账户名下。无论您更换服务器或重新安装程序，只需登录购买时所使用的应用商店账户，即可迅速将产品绑定至新的网站上。')}
                    </p>
                  </div>          
            
                    <div class="mb-3 store-introduce text-success">
                     <p class="text-danger">1.${i18n('全部官方插件')}/${i18n('主题免费使用，包括后期会继续上架数百上千种插件')}/${i18n('主题')}</p>
                     <p>2.${i18n('技术支持')}</p>
                     <p>3.${i18n('企业版专属售后通道')}</p>
                     <p>4.${i18n('内侧版、预览版抢先体验')}</p>
                     <p>5.${i18n('企业版专用功能建议通道，可有效提交新功能需求')}</p>
                    </div>
                    
                    <div class="subscription-container">
                        <div class="layout-box">
                                <div class="title">${i18n('订阅类型')}</div>
                                <div class="subscription-list online-pay"><div class="subscription-item"${enterpriseDisabled}><span class="text-warning fs-3 fw-bold">${enterprisePriceText}</span><span class="text-muted" style="font-size:13px;text-decoration:line-through;">${enterpriseOriginalPrice}</span><span class="text-warning" style="font-size:12px;">${i18n('终身可用')}</span></div></div>
                        </div>
                        <div class="layout-box">
                                        <div class="title">${i18n('付款购买')}</div>
                                            <div class="pay-list online-pay">
                                                <div data-id="0" data-type="2" data-pay="0" class="pay-item online-pay-click"${enterpriseDisabled}><img class="item-icon" src="/assets/common/images/alipay.png"><span>${i18n('支付宝')}</span></div>
                                                <div data-id="0" data-type="2" data-pay="1" class="pay-item online-pay-click"${enterpriseDisabled}><img class="item-icon" src="/assets/common/images/wx.png"><span>${i18n('微信支付')}</span></div>
                                                <div data-id="0" data-type="2" data-pay="2" class="pay-item online-pay-click"${enterpriseDisabled}><img class="item-icon" src="/assets/common/images/usdt.png"><span>USDT(TRC20)</span></div>
                                            </div>
   
                        </div>
                    </div>
              </div>`);
                            const $onlinePay = dom.find(".online-pay-click");
                            $onlinePay.click(function () {
                                if (enterprisePrice === null) {
                                    message.warning('企业版价格尚未加载完成，请关闭窗口后稍后重试。');
                                    return;
                                }
                                const type = $(this).data("type");
                                const pay = $(this).data("pay");
                                let pluginId = $(this).data("id");
                                pluginId = pluginId ? pluginId : 0;
                                requestPurchase({
                                    type: type,
                                    pluginId: pluginId,
                                    payType: pay,
                                    productName: '企业版',
                                    price: enterprisePrice
                                });
                            });
                        }
                    }
                ]
            })
        }


        component.popup({
            submit: false,
            tab: tabs,
            maxmin: false,
            autoPosition: true,
            width: "780px",
            renderComplete: (unique, index) => {
                billModalIndex = index;
            }
        });
    }

    function _BindPro() {
        component.popup({
            submit: (data, _index) => {
                const ids = proUnbindTable.getSelectionIds();
                if (ids.length == 0) {
                    layer.msg(i18n("请选择要解绑的授权"));
                    return;
                }
                message.ask(`${i18n('您正在将授权转移至当前机器，转移后，原机器的授权将失效！')}`, () => {
                    if (!controllerActive) return;
                    util.post('/admin/api/app/bindLevel', {
                        auth_id: ids[0]
                    }, res => {
                        if (!controllerActive) return;
                        layer.close(_index);
                        window.location.reload();
                    });
                }, "授权转移至本机", "确认转移");
            },
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-user-shield") + " 检查授权",
                    form: [
                        {
                            title: false,
                            name: "custom",
                            type: "custom",
            complete: (obj, dom) => {
                                dom.html('<div class="mcy-card"><table id="pro-unbind-table"></table></div>');
                                destroyNestedTable(proUnbindTable);
                                proUnbindTable = new Table(`/admin/api/app/levels`, "#pro-unbind-table");
                                proUnbindTable.setColumns([
                                    {checkbox: true},
                                    {
                                        field: 'server_ip',
                                        title: '服务器IP'
                                    },
                                    {
                                        field: 'level', title: '产品名称',
                                        formatter: function (val, item) {
                                            if (item.level == 0) {
                                                return '<span class="a-badge a-badge-primary">' + i18n('专业版') + '</span>';
                                            }
                                            return '<span class="a-badge a-badge-success">' + i18n('企业版') + '</span>';
                                        }
                                    },
                                    {
                                        field: 'app_key', title: '授权指纹',
                                        formatter: function (val, item) {
                                            return '<span class="a-badge a-badge-primary">' + escapeHtml(item?.app_key || '-') + '</span>';
                                        }
                                    },
                                    {
                                        field: 'expire_date', title: '到期时间',
                                        formatter: function (val, item) {
                                            return '<span class="a-badge a-badge-success">' + escapeHtml(item?.expire_date || '-') + '</span>';
                                        }
                                    }]);
                                proUnbindTable.enableSingleSelect();
                                proUnbindTable.disablePagination();
                                proUnbindTable.render();
                            }
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "820px",
            maxmin: false,
            shadeClose: true,
            confirmText: `<i class="fa-duotone fa-regular fa-lock-hashtag"></i> ${i18n('解绑授权至本机器')}`,
            done: () => {
                table.refresh();
            },
            end: () => {
                destroyNestedTable(proUnbindTable);
                proUnbindTable = null;
            }
        });
    }

    util.post({
        url: "/admin/api/app/service",
        loader: false,
        done: res => {
            if (!controllerActive) return;
            if (res?.data?.id <= 0) {
                _Auth();
                return;
            }

            storeAuthenticated = true;
            hideAuthGate();

            if (res?.data?.developer == 0) {
                $(`a[href="/admin/store/developer"]`).remove();
                $(`.breadcrumb-item`).remove();
            }

            if (!res?.data?.level) {
                const $UpdatePro = $(`.update-pro`);
                const $BindPro = $(`.bind-pro`);
                const reconnectEnterprise = Boolean(res?.data?.is_have_level);
                configureEnterpriseCta($UpdatePro, reconnectEnterprise);
                $(`.store-toolbar`).show();
                $UpdatePro.show().off(namespace).on('click' + namespace, () => _Bill());

                if (reconnectEnterprise) {
                    configureEnterpriseBindCta($BindPro);
                    $BindPro.show().off(namespace).on('click' + namespace, () => _BindPro());
                }
            }

            $StoreContent.show();
            table.setColumns([
                {
                    field: 'plugin_name', title: '软件名称', formatter: function (val, item) {
                        return renderPluginIdentity(item);
                    }
                }
                ,
                {
                    field: 'user', title: '开发商', formatter: function (val, item) {
                        if (item?.user?.official == 1) {
                            return '<span class="a-badge a-badge-success">' + i18n('官方') + '</span>';
                        }
                        return '<span class="a-badge a-badge-light">' + escapeHtml(item?.user?.username || '-') + '</span>';
                    }
                }
                ,
                {
                    field: 'type', title: '类型', dict: '_store_plugin_type'
                }
                ,
                {
                    field: 'description', title: '简介', formatter: value => renderStoreInlineHtml(value || '-')
                },
                {
                    field: 'web_site', title: '官网', formatter: renderExternalLink
                },
                {
                    field: 'version', title: '版本', formatter: function (val, item) {
                        return '<span class="a-badge a-badge-secondary">' + escapeHtml(item?.version || '-') + '</span>';
                    }
                },
                {
                    field: 'price', title: '价格', formatter: function (val, item) {
                        if (item.price == 0) {
                            return format.badge(`${i18n('免费')}`, "a-badge-success");
                        }

                        let html = " <span class='a-badge a-badge-danger'>￥" + escapeHtml(item.price) + "</span> ";
                        if (item.group == 1) {
                            html += format.badge(`${i18n('专业版免费')}`, "a-badge-primary");
                            html += format.badge(`${i18n('企业版免费')}`, "a-badge-success");
                        }

                        if (item.group == 2) {
                            html += format.badge(`${i18n('企业版免费')}`, "a-badge-success");
                        }
                        return `<span class="a-badge-group nowrap">${html}</span>`;
                    }
                },
                {
                    field: 'price', title: '到期时间', formatter: function (val, item) {
                        if (item.price == 0) {
                            return "-";
                        }
                        if (item?.has?.has == true) {
                            return "<span class='a-badge a-badge-success'>" + escapeHtml(item?.has?.expire || '-') + "</span>";
                        }
                        return "<span class='a-badge a-badge-light'>" + i18n('未开通') + "</span>";
                    }
                },
                {
                    field: 'operation', title: '', type: 'button', buttons: [
                        {
                            icon: 'fa-duotone fa-regular fa-plus',
                            title: "安装",
                            show: item => (item?.has?.has == true && item.install == 0) || (item.price == 0 && item.install == 0),
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                message.ask(`${i18n('您正在安装插件')} <b class="text-primary">${escapeHtml(storePlainText(row.plugin_name))}</b>，${i18n('是否继续')}`, () => {
                                    if (!controllerActive) return;
                                    // 液态玻璃进度卡片替代 layui 转圈。loader:false 关掉默认无文案遮罩。
                                    const _installing = tkProgress.start({
                                        name: storePlainText(row.plugin_name),
                                        //类型字典存的是带徽章标签的 HTML，扒成纯文字再用
                                        meta: pluginMeta(row),
                                        icon: row.icon,
                                        glyph: 'fa-cloud-arrow-down',
                                        stage: i18n('正在下载并安装…')
                                    });
                                    util.post({
                                        url: '/admin/api/app/install',
                                        data: {
                                            plugin_key: row.plugin_key,
                                            type: row.type,
                                            plugin_id: row.id
                                        },
                                        loader: false,
                                        //先让卡片把失败态演完（红角标 + 轻微抖动）再抛具体原因，
                                        //否则报错弹层和卡片同时出现，谁也没看清
                                        error: res => {
                                            _installing.fail();
                                            scheduleControllerTask(() => message.error(res.msg), 950);
                                        },
                                        fail: () => { _installing.fail(); },
                                        done: res => {
                                        _installing.succeed(i18n('安装完成'));
                                        if (!controllerActive) return;
                                        scheduleControllerTask(() => { table.refresh(); }, 500);
                                        // 等进度卡片铺满 100%、绿角标弹完并淡出，再弹"前往…"询问，
                                        // 避免盖住完成态（卡片停 900ms + 淡出 340ms）
                                        scheduleControllerTask(() => {
                                            if (!controllerActive) return;
                                            if (row.type == 1) {
                                                message.ask("支付插件安装成功，是否立即前往配置？", () => {
                                                    if (!controllerActive) return;
                                                    window.location.href = "/admin/pay/plugin";
                                                }, `${i18n('安装成功')}`, "前往支付扩展");
                                            } else if (row.type == 2) {
                                                message.ask("网站模版安装成功，是否前往网站设置？", () => {
                                                    if (!controllerActive) return;
                                                    window.location.href = "/admin/config/index";
                                                }, `${i18n('安装成功')}`, "前往网站设置");
                                            } else {
                                                message.ask("插件安装成功，是否前往插件管理？", () => {
                                                    if (!controllerActive) return;
                                                    window.location.href = "/admin/plugin/index";
                                                }, `${i18n('安装成功')}`, "前往插件管理");
                                            }
                                        }, 1280);
                                    }});
                                }, "安装插件", "确认安装");
                            }
                        },
                        {
                            title: "更新",
                            show: item => ((item?.has?.has == true && item.install == 1) || (item.price == 0 && item.install == 1)) && item.version != item.local_version,
                            class: "text-primary",
                            formatter: (item) => {
                                if (item.version != item.local_version) {
                                    return `<a type="button" class="a-badge-glass text-primary me-1 mb-1"><i class="fa-duotone fa-regular fa-arrows-rotate-reverse"></i> <span class="btn-title">${i18n('更新')}( <span class="text-danger">${escapeHtml(item.local_version || '-')}</span> ➩ <b class="text-success">${escapeHtml(item.version || '-')}</b>)</span></a>`;
                                }
                            },
                            click: (event, value, row, index) => {
                                const updateContent = escapeHtml(row?.update_content || '该更新没有提供说明').replace(/\n/g, '<br>');
                                message.ask(updateContent, () => {
                                    if (!controllerActive) return;
                                    // 同装插件：液态玻璃进度卡片（更新图标），关掉默认无文案转圈
                                    const _upgrading = tkProgress.start({
                                        name: storePlainText(row.plugin_name),
                                        //更新场景副标题直接给版本跨度，比只写新版本有用
                                        meta: `${storePlainText(row.local_version || '-')} → ${storePlainText(row.version || '-')}`,
                                        icon: row.icon,
                                        glyph: 'fa-arrows-rotate',
                                        stage: i18n('正在下载并更新…')
                                    });
                                    util.post({
                                        url: '/admin/api/app/upgrade',
                                        data: {
                                            plugin_key: row.plugin_key,
                                            type: row.type,
                                            plugin_id: row.id
                                        },
                                        loader: false,
                                        //同装插件：先演完失败态再报原因
                                        error: res => {
                                            _upgrading.fail();
                                            scheduleControllerTask(() => message.error(res.msg), 950);
                                        },
                                        fail: () => { _upgrading.fail(); },
                                        done: res => {
                                        _upgrading.succeed(i18n('更新完成'));
                                        if (!controllerActive) return;
                                        table.refresh();
                                        // 等完成态展示完再弹提示
                                        scheduleControllerTask(() => {
                                            if (!controllerActive) return;
                                            message.info(storePlainText(res?.msg) || '应用更新完成');
                                        }, 1280);
                                    }});
                                }, `<b class="text-primary"><i class="fa-duotone fa-regular fa-sparkles"></i> ${escapeHtml(storePlainText(row.plugin_name))}</b> <span class="text-primary" style="font-size:14px;">${escapeHtml(row.local_version || '-')}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span class="text-success" style="font-size:14px;">${escapeHtml(row.version || '-')}</span>`, "立即更新")

                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-lock-hashtag',
                            title: "解绑",
                            show: item => item.price > 0 && item?.has?.has == false && item.owned == true,
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: (data, _index) => {
                                        const ids = pluginUnbindTable.getSelectionIds();
                                        if (ids.length == 0) {
                                            layer.msg(i18n("请选择要解绑的授权"));
                                            return;
                                        }

                                        message.ask(`${i18n('您正在将授权转移至当前机器，转移后，原机器的授权将失效！')}`, () => {
                                            if (!controllerActive) return;
                                            util.post('/admin/api/app/unbind', {
                                                auth_id: ids[0]
                                            }, res => {
                                                if (!controllerActive) return;
                                                layer.close(_index);
                                                table.refresh();
                                            });
                                        }, "授权转移至本机", "确认转移");
                                    },
                                    tab: [
                                        {
                                            name: util.icon("fa-duotone fa-regular fa-lock-hashtag") + " 检查授权",
                                            form: [
                                                {
                                                    title: false,
                                                    name: "custom",
                                                    type: "custom",
                                                    complete: (obj, dom) => {
                                                        dom.html('<div class="mcy-card"><table id="plugin-unbind-table"></table></div>');
                                                        destroyNestedTable(pluginUnbindTable);
                                                        const pluginId = Number(row?.id);
                                                        if (!Number.isSafeInteger(pluginId) || pluginId <= 0) {
                                                            dom.html('<div class="alert alert-danger mb-0">' + i18n('应用编号无效，请刷新页面后重试。') + '</div>');
                                                            return;
                                                        }
                                                        pluginUnbindTable = new Table(`/admin/api/app/purchaseRecords?plugin_id=${pluginId}`, "#plugin-unbind-table");
                                                        pluginUnbindTable.setColumns([
                                                            {checkbox: true},
                                                            {
                                                                field: 'server_ip',
                                                                title: '服务器IP'
                                                            },
                                                            {
                                                                field: 'app_key', title: '授权指纹',
                                                                formatter: function (val, item) {
                                                                    return '<span class="a-badge a-badge-primary">' + escapeHtml(item?.app_key || '-') + '</span>';
                                                                }
                                                            },
                                                            {
                                                                field: 'expire_date', title: '到期时间',
                                                                formatter: function (val, item) {
                                                                    return '<span class="a-badge a-badge-success">' + escapeHtml(item?.expire_date || '-') + '</span>';
                                                                }
                                                            }]);
                                                        pluginUnbindTable.enableSingleSelect();
                                                        pluginUnbindTable.disablePagination();
                                                        pluginUnbindTable.render();
                                                    }
                                                },
                                            ]
                                        },
                                    ],
                                    autoPosition: true,
                                    height: "auto",
                                    width: "720px",
                                    maxmin: false,
                                    shadeClose: true,
                                    confirmText: `<i class="fa-duotone fa-regular fa-lock-hashtag"></i> ${i18n('解绑授权至本机器')}`,
                                    done: () => {
                                        table.refresh();
                                    },
                                    end: () => {
                                        destroyNestedTable(pluginUnbindTable);
                                        pluginUnbindTable = null;
                                    }
                                });
                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-trash-can',
                            title: "卸载",
                            show: item => item?.has?.has == true && item.install == 1 || (item.price == 0 && item.install == 1),
                            class: "text-danger",
                            click: (event, value, row, index) => {
                                message.ask(`<div style="text-align:left;line-height:1.8"><div>${i18n('您正在卸载插件')} <b class="text-danger">${escapeHtml(storePlainText(row.plugin_name))}</b>。</div><div style="margin-top:8px;color:#d14343">${i18n('卸载会物理删除插件目录及其文件，无法恢复；请确认已完成必要备份。')}</div></div>`, () => {
                                    if (!controllerActive) return;
                                    util.post('/admin/api/app/uninstall', {
                                        plugin_key: row.plugin_key,
                                        type: row.type
                                    }, res => {
                                        if (!controllerActive) return;
                                        table.refresh();
                                    });
                                }, "卸载插件", "确认卸载");
                            }
                        }, {
                            icon: 'fa-duotone fa-regular fa-cart-shopping',
                            title: "购买",
                            show: item => item.price > 0 && item?.has?.has == false,
                            formatter: (item) => {
                                return `<a type="button" class="a-badge-glass text-primary me-1 mb-1"><i class="fa-duotone fa-regular fa-cart-shopping"></i> <span class="btn-title">${item.owned == true ? i18n("重新购买") : i18n("立即购买")}</a>`;
                            },
                            class: "text-success",
                            click: (event, value, row, index) => {
                                _Bill(row);
                            }
                        }, {
                            icon: 'fa-duotone fa-regular fa-earth-asia text-primary',
                            class: 'admin-mobile-operation-only text-primary',
                            title: '访问官网',
                            show: row => mobileAdminEnabled() && Boolean(row.web_site) && row.web_site !== '#',
                            click: (event, value, row) => openExternal(row.web_site)
                        },
                    ]
                }
            ]);

            table.setPagination(20, [20, 30, 50, 100, 200]);

            table.setSearch([
                {title: "搜索应用..", name: "keywords", type: "input"}
            ]);

            /* ── 按作者筛选 ────────────────────────────────────────────────
               作者名单只有商店那边有（本地库里没这张表），所以没法像别的下拉框
               那样写死在 setSearch 里。等接口回来再用 createSearch 插到关键词
               后面：拿不到名单就整个不出现，而不是杵一个只有「全部」的空框。
               旧版商店没有 /store/authors，服务端会返回空列表，页面照常工作。 */
            util.post({
                url: '/admin/api/app/authors',
                loader: false,
                error: false,
                done: res => {
                    if (!controllerActive || !table.search) return;
                    const authors = Array.isArray(res?.data) ? res.data : [];
                    if (!authors.length) return;
                    table.search.createSearch({
                        title: "作者",
                        name: "author_id",
                        type: "select",
                        //十来个作者，给个搜索框比翻列表快
                        search: true,
                        dict: authors,
                        //旁边的分类胶囊是点一下立刻筛，这里跟着走，不用再点一次查询
                        change: search => search.submit()
                    }, "keywords", "after");
                }
            });

            table.onResponse(data => {
                _GroupPrice = data?.purchase?.enterprise;
            });

            table.setState("owner", "_store_plugin_owner");
            table.render();
        },
        error: () => {
            if (!controllerActive) return;
            _Auth();
        },
        fail: () => {
            if (!controllerActive) return;
            showServiceState(
                'error',
                '应用商店暂时无法连接',
                '网络请求未完成，登录状态和页面数据都没有改变。',
                () => window.location.reload()
            );
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        controllerTimers.forEach(timer => clearTimeout(timer));
        controllerTimers.clear();
        //安装途中切页的话，遮罩会连同它的计时器留在新页面上，这里一并收掉
        tkProgress.closeAll();
        $('.update-pro, .bind-pro').off(namespace);
        $('.admin-store-auth-open').off(namespace).off('click.mdStoreAuthGate');
        $('.admin-store-service-retry').off('click.mdStoreServiceRetry');
        $(document).off('pjax:beforeReplace' + namespace);
        destroyNestedTable(pluginUnbindTable);
        destroyNestedTable(proUnbindTable);
        destroyNestedTable(table);
        pluginUnbindTable = null;
        proUnbindTable = null;
        if (typeof Swal !== 'undefined') Swal.close();
        purchaseConfirming = false;
        purchaseRequesting = false;
        authPopupOpen = false;
        if (window.__mdStoreHomeDestroy === destroy) delete window.__mdStoreHomeDestroy;
    }

    window.__mdStoreHomeDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
