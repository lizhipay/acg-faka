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
    // 审核意见是审核员写的、渲染在开发者后台，属于跨用户内容：
    // marked 输出后再过一遍白名单净化（同 editorv2 预览的标准），禁掉原生 HTML 与非 http 链接。
    const sanitizeMarkdownHtml = html => {
        const template = document.createElement('template');
        template.innerHTML = html;
        const allowTags = ['p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'del', 's',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr'];
        template.content.querySelectorAll('*').forEach(node => {
            const tag = node.tagName.toLowerCase();
            if (!allowTags.includes(tag)) {
                node.replaceWith(document.createTextNode(node.textContent || ''));
                return;
            }
            Array.from(node.attributes).forEach(attr => {
                const name = attr.name.toLowerCase();
                if (!['href', 'title'].includes(name) || name.startsWith('on')) node.removeAttribute(attr.name);
            });
            const href = node.getAttribute('href');
            if (href !== null) {
                try {
                    const parsed = new URL(href.replace(/[\u0000-\u0020\u007f-\u009f]/g, ''), window.location.href);
                    if (!['http:', 'https:'].includes(parsed.protocol)) node.removeAttribute('href');
                } catch (e) {
                    node.removeAttribute('href');
                }
            }
            if (tag === 'a') {
                node.setAttribute('rel', 'noopener noreferrer nofollow');
                if (node.getAttribute('href')) node.setAttribute('target', '_blank');
            }
        });
        return template.innerHTML;
    };
    const renderMarkdown = (src, done) => {
        const parse = () => {
            try {
                const renderer = new marked.Renderer();
                renderer.html = token => escapeHtml(typeof token === 'string' ? token : (token?.text ?? token?.raw ?? ''));
                done(sanitizeMarkdownHtml(marked.parse(String(src ?? ''), {gfm: true, breaks: true, renderer})));
            } catch (e) {
                done(`<p>${escapeHtml(src)}</p>`);
            }
        };
        if (typeof marked !== 'undefined') return parse();
        util.loadScripts('/assets/common/js/editor/markdown/marked.min.js', parse);
    };
    // 弹窗内 Markdown 排版（作用域限定在 .md-reject-reason，注入一次）
    const ensureRejectReasonStyle = () => {
        if (document.getElementById('md-reject-reason-style')) return;
        const style = document.createElement('style');
        style.id = 'md-reject-reason-style';
        style.textContent = `
.md-reject-reason-body > :first-child { margin-top: 0; }
.md-reject-reason-body > :last-child { margin-bottom: 0; }
.md-reject-reason-body p { margin: 0 0 .6em; }
.md-reject-reason-body h1,.md-reject-reason-body h2,.md-reject-reason-body h3,
.md-reject-reason-body h4,.md-reject-reason-body h5,.md-reject-reason-body h6 { font-size: 15px; font-weight: 600; margin: .9em 0 .4em; }
.md-reject-reason-body ul,.md-reject-reason-body ol { margin: 0 0 .6em; padding-left: 1.5em; }
.md-reject-reason-body li { margin: .15em 0; }
.md-reject-reason-body blockquote { margin: 0 0 .6em; padding: .4em .9em; border-left: 3px solid var(--md-primary,#1976d2); background: var(--md-hover-overlay,rgba(0,0,0,.04)); border-radius: 0 6px 6px 0; }
.md-reject-reason-body code { padding: .1em .35em; border-radius: 4px; background: var(--md-hover-overlay,rgba(0,0,0,.06)); font-family: ui-monospace,Menlo,Consolas,monospace; font-size: 12.5px; }
.md-reject-reason-body pre { margin: 0 0 .6em; padding: .7em .9em; border-radius: 8px; background: #0c0f14; color: #d7dee9; overflow-x: auto; }
.md-reject-reason-body pre code { padding: 0; background: transparent; color: inherit; }
.md-reject-reason-body table { border-collapse: collapse; margin: 0 0 .6em; font-size: 13px; }
.md-reject-reason-body th,.md-reject-reason-body td { border: 1px solid var(--md-divider,#e6edf5); padding: .3em .6em; }
.md-reject-reason-body th { background: var(--md-hover-overlay,rgba(0,0,0,.04)); font-weight: 600; }
.md-reject-reason-body hr { border: 0; border-top: 1px solid var(--md-divider,#e6edf5); margin: .8em 0; }
.md-reject-reason-body a { color: var(--md-primary,#1976d2); text-decoration: underline; text-underline-offset: 2px; }`;
        document.head.appendChild(style);
    };
    // 驳回原因弹窗：表格里只放入口，完整意见（可能多条、带格式）在弹窗里读
    const openRejectReason = row => {
        ensureRejectReasonStyle();
        const reason = String(row?.error_reason ?? '').trim();
        // 已上架 + 更新被驳回：驳回的是更新包，指引要指向「更新插件」而不是「上传安装包」
        const isUpdateReject = Number(row?.status) === 1 && Number(row?.audit_review_status) === 3;
        const heading = isUpdateReject ? i18n('更新审核未通过') : i18n('审核未通过');
        const advice = isUpdateReject
            ? i18n('请按上述意见修改后，点击「更新插件」重新提交更新包审核；线上版本不受影响。')
            : i18n('请按上述意见修改后，点击「上传安装包」重新提交审核。');
        renderMarkdown(reason || i18n('审核未填写具体原因'), html => {
            layer.open({
                type: 1,
                // 插件名是站长自定义的富文本（带颜色的 <b>/<span>），走与列表同一套白名单净化渲染，而不是转义成源码
                title: `${util.icon('fa-duotone fa-regular fa-circle-xmark text-danger')} ${heading} · ${renderStoreInlineHtml(row?.plugin_name ?? '')}`,
                area: [Math.min(560, window.innerWidth - 24) + 'px', 'auto'],
                maxHeight: Math.floor(window.innerHeight * 0.8),
                shadeClose: true,
                content: `<div class="p-4 md-reject-reason" style="font-size:14px;line-height:1.75;word-break:break-word;">`
                    + `<div class="md-reject-reason-body">${html}</div>`
                    + `<div class="text-muted mt-3 pt-3" style="font-size:12px;border-top:1px solid var(--md-divider,#e6edf5);">`
                    + `${util.icon('fa-duotone fa-regular fa-lightbulb me-1')}${advice}</div></div>`
            });
        });
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
    // 「查看原因」入口：表格行是动态渲染的，用委托绑定；按 data-id 从当前页数据里取整行
    $(document).off('click' + namespace, '.dev-reject-reason').on('click' + namespace, '.dev-reject-reason', function (e) {
        e.preventDefault();
        const id = String($(this).data('id'));
        const rows = (table && typeof table.getData === 'function') ? table.getData() : [];
        const row = rows.find(r => String(r.id) === id);
        if (row) openRejectReason(row);
    });
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
            ? '<button type="button" class="btn btn-light-primary admin-store-service-retry">' + i18n('重新加载') + '</button>'
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
                layer.msg(`${actionName}${i18n('正在提交，请勿重复操作')}`);
                return;
            }
            submitting = true;
            util.post({
                url: url,
                data: data,
                done: res => {
                    if (!controllerActive) return;
                    if (index !== undefined && index !== null) layer.close(index);
                    message.success(res?.msg && res.msg !== 'success' ? (storePlainText(res.msg) || `${actionName}${i18n('成功')}`) : `${actionName}${i18n('成功')}`);
                    if (typeof onDone === 'function') onDone(res);
                },
                error: res => {
                    submitting = false;
                    if (controllerActive) message.error(storePlainText(res?.msg) || `${actionName}${i18n('失败，请检查填写内容。')}`);
                },
                fail: () => {
                    submitting = false;
                    if (controllerActive) message.error(`${i18n('网络异常，')}${actionName}${i18n('请求未完成。')}`);
                }
            });
        };
    };

    /**
     * 「服务端自动打包」说明块。
     *
     * 以前这里是一个必填的 zip 上传框，作者要自己压缩再传，翻车方式很多：
     * 忘了删 Config.php（等于把自己的密钥和启用状态发给所有买家）、
     * 把日志打进去、把插件文件夹本身也打进去、包内版本号和填的对不上被打回。
     * 现在默认由服务器从本机插件目录直接打，上传框降级成可选兜底。
     */
    const autoPackNotice = (dom, row, isUpdate) => {
        const dirMap = {0: 'app/Plugin/', 1: 'app/Pay/', 2: 'app/View/User/Theme/'};
        const dir = (dirMap[Number(row?.type)] || 'app/Plugin/') + (row?.plugin_key || '');
        const isTheme = Number(row?.type) === 2;

        const configLine = isTheme
            ? i18n('模版的 Config.php 是接口定义，会原样保留')
            : (isUpdate
                ? i18n('Config.php 整个剔除，不会覆盖用户站点的配置')
                : i18n('Config.php 清空为 return []; 不会带上本站密钥'));

        // 原本挂在「自带压缩包」上传框 tips 里的数据库说明；上传入口已移除，信息挪到这里
        const sqlLine = isUpdate
            ? i18n('需要改数据库时，把 update.sql 放在插件根目录（从最初版本累计、先检测再更改、不要写注释）')
            : i18n('需要建表时，把 install.sql 放在插件根目录（sql 文件中不要带注释）');

        dom.html(`
            <style>
            .dev-auto{border:1px solid var(--md-divider,#e6edf5);border-radius:10px;padding:12px 14px;
                      font-size:13px;line-height:1.8;background:rgba(46,125,50,.05)}
            .dev-auto b{color:var(--md-on-surface,#0f172a)}
            .dev-auto code{background:rgba(0,0,0,.06);border-radius:4px;padding:1px 6px;
                           font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;word-break:break-all}
            .dev-auto ul{margin:6px 0 0;padding-left:18px;color:var(--md-on-surface-med,#64748b);font-size:12px}
            </style>
            <div class="dev-auto">
                <b>${util.icon("fa-duotone fa-regular fa-wand-magic-sparkles")} ${i18n('服务器自动打包')}</b>
                <div style="margin-top:4px">${i18n('无需自己压缩，提交后直接从本机目录打包上传：')}<code>${dir}</code></div>
                <ul>
                    <li>${configLine}</li>
                    <li>${i18n('自动排除 runtime.log 等日志与运行态文件')}</li>
                    <li>${i18n('填写的版本号会写回插件的 Info，保证包内版本与提交一致')}</li>
                    <li>${sqlLine}</li>
                </ul>
            </div>`);
    };

    if (mobileAdminEnabled()) {
        showServiceState('loading', i18n('正在进入开发者中心'), i18n('正在核对账号权限并读取应用列表。'));
    } else {
        $StoreContent.hide();
    }

    function _Modal() {
        component.popup({
            submit: createSingleSubmit('/admin/api/app/developerCreatePlugin', i18n('创建应用'), () => table.refresh()),
            tab: [
                {
                    name: `${util.icon("fa-duotone fa-regular fa-layer-plus")} ${i18n('创建插件')}`,
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
                                message: i18n('插件标识仅支持英文字母')
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
            confirmText: `${util.icon("fa-duotone fa-regular fa-layer-plus")} ${i18n('确认提交')}`,
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


    let mcpBusy = false;
    let mcpState = null;
    let mcpClient = 'claude-code';
    let mcpSnippets = {};
    let mcpHints = {};

    // 客户端清单：id / 展示名 / 配置落点（代码窗标题栏展示）
    const MCP_CLIENTS = [
        {id: 'claude-code', name: 'Claude Code', file: 'Terminal'},
        {id: 'claude-desktop', name: 'Claude Desktop', file: 'claude_desktop_config.json'},
        {id: 'cursor', name: 'Cursor', file: '~/.cursor/mcp.json'},
        {id: 'cline', name: 'Cline', file: 'cline_mcp_settings.json'},
        {id: 'windsurf', name: 'Windsurf', file: '~/.codeium/windsurf/mcp_config.json'},
        {id: 'vscode', name: 'VS Code', file: '.vscode/mcp.json'},
        {id: 'codex', name: 'Codex', file: '~/.codex/config.toml'},
        {id: 'opencode', name: 'opencode', file: 'opencode.json'},
        {id: 'gemini-cli', name: 'Gemini CLI', file: '~/.gemini/settings.json'}
    ];

    function mcpCopyFallback(text, done) {
        const el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.focus();
        el.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            message.error(i18n('复制失败，请手动复制'));
        }
        document.body.removeChild(el);
    }

    function mcpCopy(text, btn) {
        if (!text) return;
        const done = () => {
            message.success(i18n('已复制'));
            // 按钮即时形变反馈：图标切成对勾，1.4s 后还原
            if (btn) {
                const $b = $(btn).addClass('copied');
                const $i = $b.find('i').first();
                const prev = $i.attr('class');
                $i.attr('class', 'fa-duotone fa-regular fa-check');
                setTimeout(() => {
                    if (!controllerActive) return;
                    $b.removeClass('copied');
                    $i.attr('class', prev);
                }, 1400);
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(() => mcpCopyFallback(text, done));
        } else {
            mcpCopyFallback(text, done);
        }
    }

    // Claude Desktop / Codex 走 mcp-remote 桥接：mcp-remote 默认拒绝非本机明文 HTTP，http 站点要加 --allow-http。
    // 其余客户端原生支持 HTTP，直接 url+headers，无需 Node.js/桥接（各家字段名不同）。
    function buildMcpSnippets(url, key) {
        const isHttp = /^http:\/\//i.test(url);
        const remoteArgs = ['-y', 'mcp-remote', url];
        if (isHttp) remoteArgs.push('--allow-http');
        remoteArgs.push('--header', `X-Access-Key:${key}`);
        const headers = {'X-Access-Key': key};
        mcpSnippets = {
            'claude-code': `claude mcp add --transport http acg-faka ${url} --header "X-Access-Key: ${key}"`,
            'claude-desktop': JSON.stringify({mcpServers: {'acg-faka': {command: 'npx', args: remoteArgs}}}, null, 2),
            'cursor': JSON.stringify({mcpServers: {'acg-faka': {url: url, headers: headers}}}, null, 2),
            'cline': JSON.stringify({mcpServers: {'acg-faka': {type: 'streamableHttp', url: url, headers: headers}}}, null, 2),
            'windsurf': JSON.stringify({mcpServers: {'acg-faka': {serverUrl: url, headers: headers}}}, null, 2),
            'vscode': JSON.stringify({servers: {'acg-faka': {type: 'http', url: url, headers: headers}}}, null, 2),
            'codex': `[mcp_servers.acg-faka]\ncommand = "npx"\nargs = ${JSON.stringify(remoteArgs)}`,
            'opencode': JSON.stringify({'$schema': 'https://opencode.ai/config.json', mcp: {'acg-faka': {type: 'remote', url: url, enabled: true, headers: headers}}}, null, 2),
            'gemini-cli': JSON.stringify({mcpServers: {'acg-faka': {httpUrl: url, headers: headers}}}, null, 2)
        };
        const bridged = isHttp ? i18n('（明文 HTTP 已自动附带 --allow-http）') : '';
        mcpHints = {
            'claude-code': i18n('在终端运行这行命令，重启 Claude Code 后即可看到 acg-faka 工具。'),
            'claude-desktop': i18n('粘贴进 设置 → 开发者 → 编辑配置，保存后 Cmd+Q 完全退出并重新打开，需已安装 Node.js') + bridged,
            'cursor': i18n('保存到该文件（或项目内 .cursor/mcp.json），在 设置 → MCP 里确认开启即可。'),
            'cline': i18n('Cline 面板 → MCP Servers → Configure 打开该文件粘贴；type 必须是 streamableHttp。'),
            'windsurf': i18n('保存后在 Cascade 的 MCP 面板点刷新；注意字段名是 serverUrl。'),
            'vscode': i18n('保存到项目 .vscode/mcp.json，Copilot Chat 切到 Agent 模式即可使用。'),
            'codex': i18n('追加到该文件末尾，保存后重启 Codex，需已安装 Node.js') + bridged,
            'opencode': i18n('保存到项目 opencode.json 或 ~/.config/opencode/opencode.json，重启生效。'),
            'gemini-cli': i18n('合并进该文件的 mcpServers 字段，重启 Gemini CLI 生效。')
        };
    }

    function renderMcpKey() {
        const $key = $('#mcp-key');
        if (!$key.length) return;
        const hidden = $key.attr('data-hidden') !== '0';
        const key = String(mcpState?.access_key || '');
        $key.text(hidden ? '•'.repeat(Math.min(44, Math.max(24, key.length))) : key);
        $('#mcp-reveal i').attr('class', hidden ? 'fa-duotone fa-regular fa-eye' : 'fa-duotone fa-regular fa-eye-slash');
    }

    function renderMcpClients() {
        const $box = $('#mcp-clients');
        if (!$box.length) return;
        $box.empty();
        MCP_CLIENTS.forEach(c => {
            $('<button type="button" class="mcp-client" role="tab"></button>')
                .attr('data-client', c.id)
                .attr('aria-selected', c.id === mcpClient ? 'true' : 'false')
                .toggleClass('active', c.id === mcpClient)
                .text(c.name)
                .appendTo($box);
        });
    }

    function updateMcpConfig() {
        const meta = MCP_CLIENTS.find(c => c.id === mcpClient) || MCP_CLIENTS[0];
        $('#mcp-config').text(mcpSnippets[mcpClient] || '');
        $('#mcp-config-file').text(meta.file);
        $('#mcp-config-hint').html(`${util.icon('fa-duotone fa-regular fa-circle-info')} ${escapeHtml(mcpHints[mcpClient] || '')}`);
    }

    function renderMcp(data) {
        if (!controllerActive || !data) return;
        const $card = $('#mcp-card');
        if (!$card.length) return;
        mcpState = data;
        $card.show();
        const configured = Boolean(data.configured);
        $('#mcp-unconfigured').toggleClass('d-none', configured);
        $('#mcp-configured').toggleClass('d-none', !configured);
        $('#mcp-https-warn').toggleClass('d-none', Boolean(data.https));
        $('#mcp-toggle').toggleClass('d-none', !configured).attr('aria-checked', data.enabled ? 'true' : 'false');

        const $status = $('#mcp-status');
        $status.removeClass('mcp-status--on');
        if (!configured) {
            $('#mcp-status-text').text(i18n('未配置'));
        } else if (data.enabled) {
            $status.addClass('mcp-status--on');
            $('#mcp-status-text').text(i18n('运行中'));
        } else {
            $('#mcp-status-text').text(i18n('已停用'));
        }

        if (configured) {
            buildMcpSnippets(String(data.url || ''), String(data.access_key || ''));
            renderMcpClients();
            updateMcpConfig();
            $('#mcp-url').text(String(data.url || ''));
            renderMcpKey();
        }
    }

    function loadMcp() {
        util.post({
            url: '/admin/api/mcp/info',
            loader: false,
            done: res => renderMcp(res?.data),
            error: () => $('#mcp-card').hide(),
            fail: () => $('#mcp-card').hide()
        });
    }

    function mcpAction(url) {
        if (mcpBusy) return;
        mcpBusy = true;
        util.post({
            url: url,
            done: res => {
                mcpBusy = false;
                if (!controllerActive) return;
                message.success(storePlainText(res?.msg) || i18n('操作成功'));
                renderMcp(res?.data);
            },
            error: res => {
                mcpBusy = false;
                if (controllerActive) message.error(storePlainText(res?.msg) || i18n('操作失败'));
            },
            fail: () => {
                mcpBusy = false;
                if (controllerActive) message.error(i18n('网络异常，请求未完成。'));
            }
        });
    }

    function initMcp() {
        const $card = $('#mcp-card');
        if (!$card.length) return;
        $card.off(namespace);
        $card.on('click' + namespace, '.mcp-client', function () {
            mcpClient = String($(this).data('client'));
            $('#mcp-clients .mcp-client').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');
            updateMcpConfig();
        });
        $card.on('click' + namespace, '[data-copy]', function () {
            const kind = $(this).data('copy');
            const text = kind === 'config' ? (mcpSnippets[mcpClient] || '')
                : kind === 'url' ? String(mcpState?.url || '')
                    : String(mcpState?.access_key || '');
            mcpCopy(text, this);
        });
        $card.on('click' + namespace, '#mcp-reveal', function () {
            const $key = $('#mcp-key');
            $key.attr('data-hidden', $key.attr('data-hidden') !== '0' ? '0' : '1');
            renderMcpKey();
        });
        $card.on('click' + namespace, '#mcp-generate, #mcp-reset', function () {
            const isReset = this.id === 'mcp-reset';
            const ask = isReset
                ? i18n('确定重置访问秘钥？旧秘钥将立即失效，已接入的 AI 工具需要重新配置。')
                : i18n('确定生成访问秘钥？');
            layer.confirm(ask, {icon: 3, title: i18n('提示')}, index => {
                layer.close(index);
                mcpAction('/admin/api/mcp/reset');
            });
        });
        $card.on('click' + namespace, '#mcp-toggle', function () {
            mcpAction('/admin/api/mcp/toggle');
        });
        loadMcp();
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
            initMcp();
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
                    // 不能同时写 dict 和 formatter：table.js 检测到 dict 会用自己的渲染器覆盖 formatter。
                    // 这里手动查同一份字典，好在驳回态下挂一个「查看原因」入口——
                    // 完整审核意见（多条、带 Markdown 格式）在弹窗里读，表格保持整洁。
                    field: 'status', title: '状态',
                    formatter: function (val, item) {
                        const badge = _Dict.result("_developer_plugin_status", val);
                        let html = badge !== undefined ? badge : escapeHtml(val);
                        // 单独成行 + 自身不折断：状态列很窄，跟徽标挤在一行会被从词中间断开
                        const reasonLink = `<div class="mt-1"><a href="#" data-acg-noop class="dev-reject-reason text-danger" data-id="${escapeHtml(item.id)}" style="font-size:12px;white-space:nowrap;">`
                            + `<i class="fa-duotone fa-regular fa-circle-info me-1"></i>${i18n('查看原因')}</a></div>`;
                        if (val == 2) {
                            html += reasonLink;
                        }
                        // audit_review_status 是全局审核状态：首次上架和更新包共用一个字段。
                        // 只有已上架(status=1)时它才表示"更新包"的审核进度，其它状态下与 status 同义，不重复展示。
                        if (val == 1) {
                            const review = Number(item.audit_review_status ?? 0);
                            if (review === 1) {
                                html += `<div class="mt-1">${format.badge(`${util.icon('fa-duotone fa-regular fa-hourglass-half')} ${i18n('更新审核中')}`, 'a-badge-warning')}</div>`;
                            } else if (review === 3) {
                                html += `<div class="mt-1">${format.badge(`${util.icon('fa-duotone fa-regular fa-circle-xmark')} ${i18n('更新被驳回')}`, 'a-badge-danger')}</div>` + reasonLink;
                            }
                            // review=2(更新已通过) 已合并进线上版本，无需额外标记；review=0 表示没有待审更新
                        }
                        return html;
                    }
                },
                {

                    field: 'operation', title: '', type: 'button', buttons: [
                        {
                            icon: 'fa-duotone fa-regular fa-circle-dollar',
                            title: "定价",
                            // 商店端 priceSet 不限制状态；驳回态也常需要顺手调价再重交
                            show: item => true,
                            class: "text-success",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: createSingleSubmit('/admin/api/app/developerPluginPriceSet', i18n('更新定价'), () => table.refresh()),
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-circle-dollar")} ${i18n('市场定价')}`,
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
                            // 0=开发中 2=审核驳回：驳回后改好了必须能重交，否则插件成死局，
                            // 开发者只能删掉重建（plugin_key 还会被旧记录占着）。商店端 createKit 已放行 [0,2]
                            show: item => item.status == 0 || item.status == 2,
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                const resubmit = row.status == 2;
                                const actionTitle = resubmit ? i18n('重新提交审核') : i18n('上传安装包');
                                component.popup({
                                    submit: createSingleSubmit('/admin/api/app/developerCreateKit', actionTitle, () => table.refresh()),
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} ${actionTitle}`,
                                            form: [
                                                {
                                                    title: false,
                                                    name: "reject_reason",
                                                    type: "custom",
                                                    hide: !resubmit,
                                                    complete: (form, dom) => {
                                                        if (!resubmit) return;
                                                        ensureRejectReasonStyle();
                                                        renderMarkdown(row.error_reason || i18n('未填写原因'), html => {
                                                            dom.html(`<div class="alert alert-danger py-2 px-3 mb-2" style="font-size:13px;line-height:1.6;">`
                                                                + `<b>${i18n('上次审核未通过')}</b>`
                                                                + `<div class="md-reject-reason-body mt-1">${html}</div>`
                                                                + `<span class="text-muted d-block mt-2">${i18n('请按上述意见修改后再提交，提交后将重新进入审核。')}</span></div>`);
                                                        });
                                                    }
                                                },
                                                {
                                                    title: false,
                                                    name: "auto_tips",
                                                    type: "custom",
                                                    complete: (form, dom) => autoPackNotice(dom, row, false)
                                                },
                                                {
                                                    title: "版本号",
                                                    name: "version",
                                                    type: "input",
                                                    default: row?.version || "",
                                                    placeholder: "如 1.0.0，会自动写入插件的 Info"
                                                },
                                            ]
                                        },

                                    ],
                                    assign: row,
                                    autoPosition: true,
                                    adaptiveHeight: true,
                                    confirmText: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} ${i18n('确认提交')}`,
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
                                const review = Number(row?.audit_review_status ?? 0);
                                component.popup({
                                    submit: createSingleSubmit('/admin/api/app/developerUpdatePlugin', i18n('上传更新包'), () => table.refresh()),
                                    tab: [
                                        {
                                            name: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} ${i18n('上传更新包')}`,
                                            form: [
                                                {
                                                    // 更新包审核状态提示：驳回时给出原因(Markdown)，审核中提醒再交会覆盖待审版本
                                                    title: false,
                                                    name: "review_notice",
                                                    type: "custom",
                                                    hide: review !== 1 && review !== 3,
                                                    complete: (form, dom) => {
                                                        if (review === 3) {
                                                            ensureRejectReasonStyle();
                                                            renderMarkdown(row.error_reason || i18n('未填写原因'), html => {
                                                                dom.html(`<div class="alert alert-danger py-2 px-3 mb-2" style="font-size:13px;line-height:1.6;">`
                                                                    + `<b>${i18n('上次更新审核未通过')}</b>`
                                                                    + `<div class="md-reject-reason-body mt-1">${html}</div>`
                                                                    + `<span class="text-muted d-block mt-2">${i18n('请按上述意见修改后再提交更新包；线上版本不受影响。')}</span></div>`);
                                                            });
                                                        } else if (review === 1) {
                                                            dom.html(`<div class="alert alert-warning py-2 px-3 mb-2" style="font-size:13px;line-height:1.6;">`
                                                                + `<b>${i18n('已有更新包正在审核中')}</b>`
                                                                + `<span class="text-muted d-block mt-1">${i18n('现在再提交会覆盖待审的版本并重新排队；无需修改请等待审核结果。')}</span></div>`);
                                                        }
                                                    }
                                                },
                                                {
                                                    title: false,
                                                    name: "auto_tips",
                                                    type: "custom",
                                                    complete: (form, dom) => autoPackNotice(dom, row, true)
                                                },
                                                {
                                                    title: "版本号",
                                                    name: "audit_version",
                                                    type: "input",
                                                    default: row?.version || "",
                                                    placeholder: "如 1.0.4，会自动写入插件的 Info",
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
                                    confirmText: `${util.icon("fa-duotone fa-regular fa-cloud-arrow-up")} ${i18n('确认提交')}`,
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

            // 开发者中心列表搜索。接口收的是裸参数名（keyword/status/type/audit_review_status），
            // 不是本项目常见的 search-/equal- 前缀写法，所以这里直接用字段本名。
            table.setSearch([
                {
                    title: "关键字",
                    name: "keyword",
                    type: "input",
                    placeholder: "纯英文数字按标识搜索，否则按应用名称搜索"
                },
                {title: "上架状态", name: "status", type: "select", dict: "_developer_plugin_status"},
                {title: "插件类型", name: "type", type: "select", dict: "_store_plugin_type"},
                {title: "更新审核状态", name: "audit_review_status", type: "select", dict: "_developer_plugin_audit_review_status"}
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
                i18n('开发者中心暂时无法连接'),
                i18n('网络请求未完成，账号权限和应用数据都没有改变。'),
                () => window.location.reload()
            );
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.developerCreatePlugin').off(namespace);
        $('#mcp-card').off(namespace);
        $('.admin-store-service-retry').off('click.mdStoreDeveloperRetry');
        $(document).off('click' + namespace, '.dev-reject-reason');
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdStoreDeveloperDestroy === destroy) delete window.__mdStoreDeveloperDestroy;
    }

    window.__mdStoreDeveloperDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
