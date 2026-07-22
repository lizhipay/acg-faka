!function () {
    let pluginUnbindTable, proUnbindTable, _GroupPrice;
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
                <div><b>购买项目：</b>${productName}</div>
                <div><b>商品价格：</b>¥${price}</div>
                <div><b>支付方式：</b>${payName}</div>
                <div class="mt-2 text-danger">确认后将创建支付订单并跳转至外部收银台；付款完成后会生成对应授权，不能通过返回本页撤销。</div>
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
            ? '<button type="button" class="btn btn-light-primary admin-store-service-retry">重新加载</button>'
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
                    <strong id="admin-store-auth-title" class="fs-2">登录应用商店</strong>
                    <p class="text-muted mb-3">登录后可查看已购买资源、授权和应用商店内容。</p>
                    <button type="button" class="btn btn-primary admin-store-auth-open">
                        <i class="fa-duotone fa-regular fa-right-to-bracket" aria-hidden="true"></i>
                        登录或注册
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
            const pluginOriginalPrice = pluginPrice === null ? '请稍后重试' : `原价:${escapeHtml(pluginPrice * 2)}`;
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
                      您所购买的插件，将统一归属于您的应用商店账户名下。无论您更换服务器或重新安装程序，只需登录购买时所使用的应用商店账户，即可迅速将产品绑定至新的网站上。
                    </p>
                  </div>          
            
                    <div class="mb-3 store-introduce">
                      ${renderStoreInlineHtml(i18n(plugin.description))}
                    </div>
                    
                    <div class="subscription-container">
                        <div class="layout-box">
                                <div class="title"><i class="fa-duotone fa-regular fa-clock"></i> 订阅类型</div>
                                <div class="subscription-list online-pay"><div class="subscription-item" data-amount="${pluginPrice === null ? '' : escapeHtml(pluginPrice)}"${pluginDisabled}><span class="text-warning fs-3 fw-bold">${pluginPriceText}</span><span class="text-muted" style="font-size:13px;text-decoration:line-through;">${pluginOriginalPrice}</span><span class="text-warning" style="font-size:12px;">终身可用</span></div></div>
                        </div>
                        
                    
                     
                        
                        <div class="layout-box">
                                        <div class="title"><i class="fa-duotone fa-regular fa-star-shooting"></i> 付款购买 ${plugin.group > 0 ? `<span class="text-success"> 此插件企业版免费用，开通企业版更省钱更超值！<a href="javascript:void(0);" class="text-primary open-group-enterprise-click">点我开企业版</a></span>` : ""}</div>
                                            <div class="pay-list online-pay">
                                                <div data-id="${pluginId}" data-type="0" data-pay="0" class="pay-item online-pay-click"${pluginDisabled}><img class="item-icon" src="/assets/common/images/alipay.png"><span>支付宝</span></div>
                                                <div data-id="${pluginId}" data-type="0" data-pay="1" class="pay-item online-pay-click"${pluginDisabled}><img class="item-icon" src="/assets/common/images/wx.png"><span>微信支付</span></div>
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
                name: `<div class="common-item open-group-enterprise"><i class="fa-duotone fa-regular fa-user me-1"></i> <div class="item-name" style="font-size: 1rem;">开通企业版(推荐)</div></div>`,
                form: [
                    {
                        title: false,
                        name: "introduce_group",
                        type: "custom",
                        complete: (form, dom) => {
                            let payList = '', selectedSubscription, selectAmount;
                            const enterprisePrice = normalizePurchasePrice(_GroupPrice);
                            const enterprisePriceText = enterprisePrice === null ? '价格加载中' : `¥${escapeHtml(enterprisePrice)}`;
                            const enterpriseOriginalPrice = enterprisePrice === null ? '请稍后重试' : `原价:${escapeHtml(enterprisePrice * 2)}`;
                            const enterpriseDisabled = enterprisePrice === null ? ' aria-disabled="true"' : '';
                            dom.html(`<div>     
<div class="alert alert-success" role="alert">
                    <p class="mb-0">
                      您所购买的企业版，将统一归属于您的应用商店账户名下。无论您更换服务器或重新安装程序，只需登录购买时所使用的应用商店账户，即可迅速将产品绑定至新的网站上。
                    </p>
                  </div>          
            
                    <div class="mb-3 store-introduce text-success">
                     <p class="text-danger">1.全部官方插件/主题免费使用，包括后期会继续上架数百上千种插件/主题</p>
                     <p>2.技术支持</p>
                     <p>3.企业版专属售后通道</p>
                     <p>4.内侧版、预览版抢先体验</p>
                     <p>5.企业版专用功能建议通道，可有效提交新功能需求</p>
                    </div>
                    
                    <div class="subscription-container">
                        <div class="layout-box">
                                <div class="title">订阅类型</div>
                                <div class="subscription-list online-pay"><div class="subscription-item"${enterpriseDisabled}><span class="text-warning fs-3 fw-bold">${enterprisePriceText}</span><span class="text-muted" style="font-size:13px;text-decoration:line-through;">${enterpriseOriginalPrice}</span><span class="text-warning" style="font-size:12px;">终身可用</span></div></div>
                        </div>
                        <div class="layout-box">
                                        <div class="title">付款购买</div>
                                            <div class="pay-list online-pay">
                                                <div data-id="0" data-type="2" data-pay="0" class="pay-item online-pay-click"${enterpriseDisabled}><img class="item-icon" src="/assets/common/images/alipay.png"><span>支付宝</span></div>
                                                <div data-id="0" data-type="2" data-pay="1" class="pay-item online-pay-click"${enterpriseDisabled}><img class="item-icon" src="/assets/common/images/wx.png"><span>微信支付</span></div>
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
                    layer.msg("请选择要解绑的授权");
                    return;
                }
                message.ask(`您正在将授权转移至当前机器，转移后，原机器的授权将失效！`, () => {
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
                                                return '<span class="a-badge a-badge-primary">专业版</span>';
                                            }
                                            return '<span class="a-badge a-badge-success">企业版</span>';
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
            confirmText: `<i class="fa-duotone fa-regular fa-lock-hashtag"></i> 解绑授权至本机器`,
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
                            return '<span class="a-badge a-badge-success">官方</span>';
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
                            return format.badge(`免费`, "a-badge-success");
                        }

                        let html = " <span class='a-badge a-badge-danger'>￥" + escapeHtml(item.price) + "</span> ";
                        if (item.group == 1) {
                            html += format.badge(`专业版免费`, "a-badge-primary");
                            html += format.badge(`企业版免费`, "a-badge-success");
                        }

                        if (item.group == 2) {
                            html += format.badge(`企业版免费`, "a-badge-success");
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
                        return "<span class='a-badge a-badge-light'>未开通</span>";
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
                                message.ask(`您正在安装插件 <b class="text-primary">${escapeHtml(storePlainText(row.plugin_name))}</b>，是否继续`, () => {
                                    if (!controllerActive) return;
                                    util.post('/admin/api/app/install', {
                                        plugin_key: row.plugin_key,
                                        type: row.type,
                                        plugin_id: row.id
                                    }, res => {
                                        if (!controllerActive) return;
                                        scheduleControllerTask(() => {
                                            table.refresh();
                                        }, 500);

                                        if (row.type == 1) {
                                            message.ask("支付插件安装成功，是否立即前往配置？", () => {
                                                if (!controllerActive) return;
                                                window.location.href = "/admin/pay/plugin";
                                            }, `安装成功`, "前往支付扩展");

                                        } else if (row.type == 2) {
                                            message.ask("网站模版安装成功，是否前往网站设置？", () => {
                                                if (!controllerActive) return;
                                                window.location.href = "/admin/config/index";
                                            }, `安装成功`, "前往网站设置");
                                        } else {
                                            message.ask("插件安装成功，是否前往插件管理？", () => {
                                                if (!controllerActive) return;
                                                window.location.href = "/admin/plugin/index";
                                            }, `安装成功`, "前往插件管理");
                                        }
                                    });
                                }, "安装插件", "确认安装");
                            }
                        },
                        {
                            title: "更新",
                            show: item => ((item?.has?.has == true && item.install == 1) || (item.price == 0 && item.install == 1)) && item.version != item.local_version,
                            class: "text-primary",
                            formatter: (item) => {
                                if (item.version != item.local_version) {
                                    return `<a type="button" class="a-badge-glass text-primary me-1 mb-1"><i class="fa-duotone fa-regular fa-arrows-rotate-reverse"></i> <span class="btn-title">更新( <span class="text-danger">${escapeHtml(item.local_version || '-')}</span> ➩ <b class="text-success">${escapeHtml(item.version || '-')}</b>)</span></a>`;
                                }
                            },
                            click: (event, value, row, index) => {
                                const updateContent = escapeHtml(row?.update_content || '该更新没有提供说明').replace(/\n/g, '<br>');
                                message.ask(updateContent, () => {
                                    if (!controllerActive) return;
                                    util.post('/admin/api/app/upgrade', {
                                        plugin_key: row.plugin_key,
                                        type: row.type,
                                        plugin_id: row.id
                                    }, res => {
                                        if (!controllerActive) return;
                                        message.info(storePlainText(res?.msg) || '应用更新完成');
                                        table.refresh();
                                    });
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
                                            layer.msg("请选择要解绑的授权");
                                            return;
                                        }

                                        message.ask(`您正在将授权转移至当前机器，转移后，原机器的授权将失效！`, () => {
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
                                                            dom.html('<div class="alert alert-danger mb-0">应用编号无效，请刷新页面后重试。</div>');
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
                                    confirmText: `<i class="fa-duotone fa-regular fa-lock-hashtag"></i> 解绑授权至本机器`,
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
                                message.ask(`<div style="text-align:left;line-height:1.8"><div>您正在卸载插件 <b class="text-danger">${escapeHtml(storePlainText(row.plugin_name))}</b>。</div><div style="margin-top:8px;color:#d14343">卸载会物理删除插件目录及其文件，无法恢复；请确认已完成必要备份。</div></div>`, () => {
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
                                return `<a type="button" class="a-badge-glass text-primary me-1 mb-1"><i class="fa-duotone fa-regular fa-cart-shopping"></i> <span class="btn-title">${item.owned == true ? "重新购买" : "立即购买"}</a>`;
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
