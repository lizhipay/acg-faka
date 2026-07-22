!function () {
    const namespace = '.mdStoreDeveloperController';
    let controllerActive = true;
    let table;
    if (typeof window.__mdStoreDeveloperDestroy === 'function') window.__mdStoreDeveloperDestroy();
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
        : String(value ?? '').replace(/<[^>]*>/g, '').trim();
    const normalizeHttpUrl = value => {
        if (!value || value === '#') return null;
        const raw = String(value).trim();
        const source = raw.startsWith('/') || /^[a-z][a-z0-9+.-]*:/i.test(raw) ? raw : 'https://' + raw;
        try {
            const url = new URL(source, window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url : null;
        } catch (error) {
            return null;
        }
    };
    const openExternal = value => {
        const url = normalizeHttpUrl(value);
        if (!url) return false;
        window.open(url.href, '_blank', 'noopener,noreferrer');
        return true;
    };
    const renderExternalLink = value => {
        const url = normalizeHttpUrl(value);
        return url ? `<a href="${escapeHtml(url.href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(value)}</a>` : escapeHtml(value || '-');
    };
    const renderPluginIdentity = item => {
        const icon = normalizeHttpUrl(item?.icon);
        const iconHtml = icon ? `<img src="${escapeHtml(icon.href)}" class="md-plugin__icon" alt="">` : '<span class="md-plugin__icon material-icons-outlined" aria-hidden="true">developer_mode</span>';
        return `<div class="md-plugin">${iconHtml}<span class="md-plugin__name">${renderStoreInlineHtml(item?.plugin_name || '')}</span></div>`;
    };
    table = new Table("/admin/api/app/developerPlugins", "#dev-plugin-table");
    const $StoreRoot = $('.store-content').first();
    const $StoreContent = $StoreRoot.parent();

    function showServiceState(type, title, copy, retry) {
        if (!controllerActive) return;
        const $container = $StoreRoot.find('#kt_content_container').first();
        if (!$container.length) return;

        $StoreContent.show();
        $container.children('.card').not('.admin-store-service-state').hide();
        const loading = type === 'loading';
        const stateClass = loading ? '' : ' admin-mobile-load-state--error';
        const indicator = loading
            ? '<span class="admin-mobile-load-spinner" aria-hidden="true"></span>'
            : '<span class="material-icons-outlined" aria-hidden="true">cloud_off</span>';
        const button = typeof retry === 'function'
            ? '<button type="button" class="btn btn-light-primary admin-store-service-retry">重新加载</button>'
            : '';
        let $state = $container.children('.admin-store-service-state').first();
        if (!$state.length) {
            $state = $('<section class="card mb-5 admin-store-service-state"></section>').prependTo($container);
        }
        $state.html(`<div class="card-body admin-mobile-load-state${stateClass}" role="${loading ? 'status' : 'alert'}" aria-live="polite">
            ${indicator}<strong>${escapeHtml(title)}</strong><small>${escapeHtml(copy)}</small>${button}
        </div>`).show();
        $state.find('.admin-store-service-retry')
            .off('click.mdStoreDeveloperRetry')
            .on('click.mdStoreDeveloperRetry', retry || $.noop);
    }

    function clearServiceState() {
        const $container = $StoreRoot.find('#kt_content_container').first();
        $container.children('.admin-store-service-state').remove();
        $container.children('.card').show();
    }

    const createSingleSubmit = (url, actionName, onDone) => {
        let submitting = false;
        return (data, index) => {
            if (!controllerActive) return;
            if (submitting) {
                layer.msg(`${actionName}正在提交，请勿重复操作`);
                return;
            }
            submitting = true;
            util.post({
                url: url,
                data: data,
                done: res => {
                    if (!controllerActive) return;
                    if (index !== undefined && index !== null) layer.close(index);
                    message.success(res?.msg && res.msg !== 'success' ? (storePlainText(res.msg) || `${actionName}成功`) : `${actionName}成功`);
                    if (typeof onDone === 'function') onDone(res);
                },
                error: res => {
                    submitting = false;
                    if (controllerActive) message.error(storePlainText(res?.msg) || `${actionName}失败，请检查填写内容。`);
                },
                fail: () => {
                    submitting = false;
                    if (controllerActive) message.error(`网络异常，${actionName}请求未完成。`);
                }
            });
        };
    };

    if (mobileAdminEnabled()) {
        showServiceState('loading', '正在进入开发者中心', '正在核对账号权限并读取应用列表。');
    } else {
        $StoreContent.hide();
    }

    function _Modal() {
        component.popup({
            submit: createSingleSubmit('/admin/api/app/developerCreatePlugin', '创建应用', () => table.refresh()),
            tab: [
                {
                    name: `${util.icon("fa-duotone fa-regular fa-layer-plus")} 创建插件`,
                    form: [
                        {
                            title: "插件图标",
                            name: "icon",
                            type: "image",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            placeholder: "120*120",
                            required: true,
                            width: 60
                        },
                        {
                            title: "插件标识",
                            name: "plugin_key",
                            required: true,
                            type: "input",
                            placeholder: "插件唯一标识，仅支持字母，也就是你插件文件夹的名字",
                            regex: {
                                value: '^[A-Za-z]+$',
                                message: '插件标识仅支持英文字母'
                            }
                        },
                        {
                            title: "插件名字",
                            name: "plugin_name",
                            required: true,
                            type: "input",
                            placeholder: "插件名称"
                        },

                        {
                            title: "插件类型",
                            name: "type",
                            required: true,
                            type: "radio",
                            dict: "_store_plugin_type",
                            default: 0
                        },
                        {
                            title: "免费组",
                            name: "group",
                            type: "radio",
                            dict: [
                                {id: 0, name: "不启用"},
                                {id: 1, name: "专业版/企业版免费使用"},
                                {id: 2, name: "企业版免费使用"},
                            ],
                            default: 0
                        },
                        {
                            title: "版本号",
                            name: "version",
                            type: "input",
                            placeholder: "版本号",
                            required: true,
                            default: "1.0.0"
                        },
                        {
                            title: "插件简介",
                            name: "description",
                            type: "textarea",
                            placeholder: "插件简介，60字内",
                            required: true,
                            height: 100
                        },
                        {
                            title: "插件官网",
                            name: "web_site",
                            type: "input",
                            placeholder: "可以是插件演示地址，或者您的个人博客，如果是非法网站将会被替换成#"
                        },
                        {
                            title: "插件价格",
                            name: "price",
                            type: "input",
                            placeholder: "可忽略不填，自动默认免费"
                        },
                    ]
                },
            ],

            autoPosition: true,
            adaptiveHeight: true,
            confirmText: `${util.icon("fa-duotone fa-regular fa-layer-plus")} 确认提交`,
            renderComplete: unique => {
                const $form = $('.' + unique);
                $form.find('input[name="icon"]').attr({
                    inputmode: 'url',
                    autocomplete: 'off',
                    autocapitalize: 'none',
                    spellcheck: 'false',
                    maxlength: '2048'
                });
                $form.find('input[name="plugin_key"]').attr({
                    autocapitalize: 'none',
                    autocomplete: 'off',
                    spellcheck: 'false',
                    maxlength: '64'
                });
                $form.find('input[name="plugin_name"]').attr({autocomplete: 'off', maxlength: '64'});
                $form.find('input[name="version"]').attr({autocomplete: 'off', autocapitalize: 'none', spellcheck: 'false', maxlength: '32'});
                $form.find('input[name="web_site"]').attr({
                    inputmode: 'url',
                    autocomplete: 'url',
                    autocapitalize: 'none',
                    spellcheck: 'false',
                    maxlength: '255'
                });
                $form.find('input[name="price"]').attr({inputmode: 'decimal', autocomplete: 'off', maxlength: '16'});
                $form.find('textarea[name="description"]').attr('maxlength', '60');
            },
            width: "680px"
        });
    }


    util.post({
        url: "/admin/api/app/service",
        loader: false,
        done: res => {
            if (!controllerActive) return;
            if (res?.data?.id <= 0 || res?.data?.developer == 0) {
                window.location.href = "/admin/store/home";
                return;
            }

            clearServiceState();
            $StoreContent.show();
            table.setColumns([
                {
                    field: 'plugin_name', title: '应用名称', formatter: function (val, item) {
                        return renderPluginIdentity(item);
                    }
                }
                ,
                {
                    field: 'plugin_key', title: '标识', formatter: value => escapeHtml(value || '-')
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
                    field: 'price', title: '市场售价', formatter: function (val, item) {
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
                    field: 'status', title: '状态', dict: "_developer_plugin_status"
                },
                {

                    field: 'operation', title: '', type: 'button', buttons: [
                        {
                            icon: 'fa-duotone fa-regular fa-circle-dollar',
                            title: "定价",
                            show: item => item.status != 2,
                            class: "text-success",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: createSingleSubmit('/admin/api/app/developerPluginPriceSet', '更新定价', () => table.refresh()),
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-circle-dollar")} 市场定价`,
                                            form: [
                                                {
                                                    title: false,
                                                    name: "price",
                                                    type: "input",
                                                    placeholder: "市场出售价格，0=免费"
                                                }
                                            ]
                                        },

                                    ],
                                    assign: row,
                                    autoPosition: true,
                                    maxmin: false,
                                    height: "auto",
                                    renderComplete: unique => {
                                        $('.' + unique + ' input[name="price"]').attr({
                                            inputmode: 'decimal',
                                            autocomplete: 'off',
                                            maxlength: '16'
                                        });
                                    },
                                    width: "280px"
                                });
                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-cloud-arrow-up',
                            title: "上传安装包",
                            show: item => item.status == 0,
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: createSingleSubmit('/admin/api/app/developerCreateKit', '上传安装包', () => table.refresh()),
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 上传安装包`,
                                            form: [
                                                {
                                                    title: false,
                                                    name: "resource",
                                                    uploadUrl: '/admin/api/upload/send',
                                                    type: "file",
                                                    required: true,
                                                    exts: "zip",
                                                    acceptMime: ".zip",
                                                    placeholder: "点击上传或拖动文件(.zip)",
                                                    tips: "插件安装包请直接在您插件根目录进行打包，而不是将插件文件夹也一起打包上来，并且仅支持zip打包方式，请勿设置压缩包密码，如果插件带数据库，请将数据库安装SQL命令写到install.sql中(sql文件中不要带注释)，并且放置在插件根目录"
                                                },
                                            ]
                                        },

                                    ],
                                    assign: row,
                                    autoPosition: true,
                                    adaptiveHeight: true,
                                    confirmText: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 确认提交`,
                                    width: "380px"
                                });
                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-arrows-rotate',
                            title: "更新插件",
                            show: item => item.status == 1,
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: createSingleSubmit('/admin/api/app/developerUpdatePlugin', '上传更新包', () => table.refresh()),
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 上传更新包`,
                                            form: [
                                                {
                                                    title: false,

                                                    name: "audit_resource",
                                                    uploadUrl: '/admin/api/upload/send',
                                                    type: "file",
                                                    required: true,
                                                    exts: "zip",
                                                    acceptMime: ".zip",
                                                    placeholder: "点击上传或拖动文件(.zip)",
                                                    tips: '更新包说明，如果带有更新数据库的情况下，请仔细编写update.sql放置插件更新包的根目录（请使用SQL命令检测当前更改项是否可以更改再去更改,否则产生错误将使插件更新失败，并且该update.sql应该从最初始版本累计，sql文件中不要带注释），如果是支付扩展或者通用扩展，请一定要删除配置文件Config.php'
                                                },
                                                {
                                                    title: "版本号",
                                                    name: "audit_version",
                                                    type: "input",
                                                    placeholder: "这个更新包内Info信息中的版本号，请填写一致",
                                                    required: true
                                                },
                                                {
                                                    title: "更新内容",
                                                    name: "audit_update_content",
                                                    type: "textarea",
                                                    height: 200,
                                                    placeholder: "必填，否则会导致插件无法更新",
                                                    required: true
                                                },
                                            ]
                                        },
                                    ],

                                    assign: row,
                                    autoPosition: true,
                                    adaptiveHeight: true,
                                    confirmText: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} 确认提交`,
                                    renderComplete: unique => {
                                        $('.' + unique + ' input[name="audit_version"]').attr({
                                            autocapitalize: 'none',
                                            autocomplete: 'off',
                                            spellcheck: 'false',
                                            maxlength: '32'
                                        });
                                    },
                                    width: "580px"
                                });
                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-earth-asia text-primary',
                            class: 'admin-mobile-operation-only text-primary',
                            title: '访问官网',
                            show: row => mobileAdminEnabled() && Boolean(row.web_site) && row.web_site !== '#',
                            click: (event, value, row) => openExternal(row.web_site)
                        }
                    ]
                }
            ]);

            table.setPagination(20, [20, 50, 100, 200]);
            table.render();

            $('.developerCreatePlugin').off(namespace).on('click' + namespace, () => {
                _Modal();
            });
        },
        error: () => {
            if (!controllerActive) return;
            window.location.href = "/admin/store/home";
        },
        fail: () => {
            if (!controllerActive) return;
            showServiceState(
                'error',
                '开发者中心暂时无法连接',
                '网络请求未完成，账号权限和应用数据都没有改变。',
                () => window.location.reload()
            );
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.developerCreatePlugin').off(namespace);
        $('.admin-store-service-retry').off('click.mdStoreDeveloperRetry');
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdStoreDeveloperDestroy === destroy) delete window.__mdStoreDeveloperDestroy;
    }

    window.__mdStoreDeveloperDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
