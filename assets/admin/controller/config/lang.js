!function () {
    let table;
    const namespace = '.mdConfigLangController';
    let controllerActive = true;
    if (typeof window.__mdConfigLangDestroy === 'function') window.__mdConfigLangDestroy();

    //语言列表由核心的语言注册表下发：站长停用了哪几种、自己加了哪几种，这里都要跟着变
    let LANGS = [];
    let REGISTRY = [];
    let OPTIONS = [];
    const STATUS = [
        {id: 0, name: format.badge(i18n('待翻译'), 'a-badge-danger')},
        {id: 1, name: format.badge(i18n('机器翻译'), 'a-badge-primary')},
        {id: 2, name: format.badge(i18n('人工确认'), 'a-badge-success')}
    ];

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);

    const langCard = item => {
        const off = !item.enabled;
        //源语言是整套翻译的基准：没有"待翻译"的概念，也不能停用或删除
        const meta = item.source
            ? `<div class="text-gray-600 fs-7">${i18n('源语言，全站文案以它为准')}</div>`
            : `<div class="progress h-6px mb-2"><div class="progress-bar ${off ? 'bg-secondary' : 'bg-primary'}" style="width:${item.percent}%"></div></div>
               <div class="text-gray-600 fs-7">${i18n('已翻译')} ${item.translated} / ${item.total}，${i18n('待翻译')} ${item.pending}</div>`;

        //源语言不能停用：整套翻译都以它为基准
        const actions = [];
        if (!item.source) {
            actions.push(`<button class="btn btn-sm ${off ? 'btn-light-primary' : 'btn-light'} py-1 px-3 btn-lang-toggle" data-code="${escapeHtml(item.code)}">${off ? i18n('启用') : i18n('停用')}</button>`);
            actions.push(`<button class="btn btn-sm btn-light py-1 px-3 btn-lang-seed" data-code="${escapeHtml(item.code)}">${i18n('补齐词条')}</button>`);
        }
        if (!item.builtin) {
            actions.push(`<button class="btn btn-sm btn-light-danger py-1 px-3 btn-lang-remove" data-code="${escapeHtml(item.code)}">${i18n('删除')}</button>`);
        }

        return `<div class="col-md-4">
            <div class="card h-100 ${off ? 'bg-light' : ''}"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>
                        <span class="fw-bold fs-5 ${off ? 'text-gray-600' : ''}">${escapeHtml(item.name)}</span>
                        <span class="badge badge-light ms-2">${escapeHtml(item.code)}</span>
                        ${off ? `<span class="badge badge-light-danger ms-1">${i18n('已停用')}</span>` : ''}
                        ${item.builtin ? '' : `<span class="badge badge-light-info ms-1">${i18n('自定义')}</span>`}
                    </span>
                    ${item.source ? '' : `<span class="fs-3 fw-bold ${off ? 'text-gray-500' : 'text-primary'}">${item.percent}%</span>`}
                </div>
                ${meta}
                ${actions.length === 0 ? '' : `<div class="d-flex gap-2 mt-3">${actions.join('')}</div>`}
            </div></div>
        </div>`;
    };

    const loadRegistry = after => {
        util.post({
            url: '/admin/api/lang/langs',
            data: {},
            loader: false,
            done: res => {
                if (!controllerActive) return;
                REGISTRY = (res.data && res.data.list) || [];
                OPTIONS = (res.data && res.data.options) || [];
                //词条表的语言筛选要含停用语言：停用只是不对外提供，后台还得能维护它的译文
                LANGS = REGISTRY.filter(item => !item.source).map(item => ({
                    id: item.code,
                    name: item.enabled ? item.name : `${item.name}（${i18n('已停用')}）`
                }));
                $('#lang-stat').html(REGISTRY.map(langCard).join(''));
                if (typeof after === 'function') after();
            }
        });
    };
    const renderStat = () => loadRegistry();

    //只能从内置语言表里挑：语言代码要当缓存文件名和 Cookie 值用，显示名和翻译目标描述
    //也一并由语言表给定——名字写错就直接错给访客看，提示词写含糊 AI 译文就跑偏。
    const langModal = () => {
        if (OPTIONS.length === 0) {
            layer.msg(i18n('语言表里的语言都已经添加过了'));
            return;
        }

        component.popup({
            submit: '/admin/api/lang/langSave',
            tab: [{
                name: `${util.icon('fa-duotone fa-regular fa-language')} ${i18n('添加语言')}`,
                form: [
                    {
                        title: '语言', name: 'code', type: 'select', dict: OPTIONS, search: true,
                        placeholder: '输入中文名、母语名或语言代码搜索',
                        tips: '显示名称与交给 AI 的翻译目标描述都已内置，不用填。添加后点「补齐词条」就能让翻译插件把整站翻过去。'
                    },
                    {
                        title: '启用', name: 'enabled', type: 'switch', default: 1,
                        placeholder: '启用|停用',
                        tips: '停用后访客不再能切到该语言，翻译插件也不再为它花钱翻译；已有译文原样保留，随时可以再启用。'
                    }
                ]
            }],
            assign: {},
            autoPosition: true,
            height: 'auto',
            width: '640px',
            done: () => {
                if (!controllerActive) return;
                renderStat();
                if (table) table.refresh();
            }
        });
    };

    const editModal = row => {
        const langName = (LANGS.find(l => l.id === row.lang) || {name: row.lang}).name;
        component.popup({
            submit: '/admin/api/lang/save',
            tab: [
                {
                    name: `${util.icon('fa-duotone fa-regular fa-pen-to-square')} ${i18n('编辑译文')} · ${langName}`,
                    form: [
                        {title: 'id', name: 'id', type: 'input', hide: true},
                        {
                            title: false, name: 'lang_edit_source', type: 'custom', submit: false,
                            complete: (form, dom) => {
                                dom.html(`<div class="alert alert-primary" style="margin:0;word-break:break-word;white-space:pre-wrap;"><div style="font-size:12px;opacity:.75;margin-bottom:6px;">${i18n('原文（简体中文）')}</div>${escapeHtml(row.source)}</div>`);
                            }
                        },
                        {
                            title: '译文',
                            name: 'text',
                            type: 'textarea',
                            height: 120,
                            placeholder: '留空表示清空译文并回到待翻译状态',
                            tips: '保存后该词条会标记为「人工确认」，翻译插件不会再覆盖它。'
                        }
                    ]
                }
            ],
            assign: row,
            autoPosition: true,
            height: 'auto',
            width: '640px',
            done: () => {
                if (!controllerActive || !table) return;
                table.refresh();
                renderStat();
            }
        });
    };

    //语言注册表先到手，词条表的「语言」列与筛选下拉才有正确的名字可用
    const buildTable = () => {
        table = new Table('/admin/api/lang/data', '#lang-table');

        table.setColumns([
            {checkbox: true},
            {field: 'source', title: '原文（简体中文）'},
            {
                field: 'lang', title: '语言', class: 'nowrap', width: 110,
                formatter: value => (LANGS.find(l => l.id === value) || {name: value}).name
            },
            {field: 'text', title: '译文', formatter: value => (value === null || value === '') ? '-' : escapeHtml(value)},
            {field: 'scene', title: '来源', class: 'nowrap', width: 90},
            {field: 'status', title: '状态', dict: '_lang_status', class: 'nowrap', width: 110},
            {field: 'update_time', title: '更新时间', class: 'nowrap', width: 160},
            {
                field: 'operation', title: '操作', class: 'nowrap', width: 70, type: 'button', buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-pen-to-square',
                        class: 'text-primary',
                        title: '编辑',
                        click: (event, value, row, index) => editModal(row)
                    }
                ]
            }
        ]);
        table.setSearch([
            {title: '原文/译文', name: 'search-source', type: 'input'},
            //默认只列启用中的语言，挑一个停用语言才会把它的词条翻出来
            {title: '语言', name: 'equal-lang', type: 'select', dict: LANGS, placeholder: '启用中的语言'},
            {title: '状态', name: 'equal-status', type: 'select', dict: STATUS},
            {title: '来源', name: 'equal-scene', type: 'select', dict: [
                {id: 'tpl', name: i18n('模板')},
                {id: 'js', name: i18n('前端')},
                {id: 'api', name: i18n('接口')},
                {id: 'dyn', name: i18n('动态内容')},
                {id: 'meta', name: i18n('插件元数据')}
            ]}
        ]);
        //搜索词里带 <div、<style、<img 这类标签，会被 WAF 的 XSS 规则把**整个请求**拦掉，
        //返回「当前会话不安全，请刷新网页」——而商品详情词条恰恰就是这些标签构成的，
        //于是「翻译词条根本搜不到」（GitHub #888）。换成十六进制传，服务端解回来：
        //十六进制只有 0-9a-f，拼不出防火墙规则里任何一个关键词。
        table.setParamsFilter(params => {
            let kw = params['search-source'];
            if (typeof kw === 'string' && kw !== '') {
                //搜索组件交出来的值是预先 encodeURIComponent 过的（表单管线的历史补偿），
                //要先还原成真正的关键词再编码，否则搜的是 %E5%95%86 这串字面量
                try { kw = decodeURIComponent(kw); } catch (e) { /* 半截百分号就按原样用 */ }
                delete params['search-source'];
                params['search-source-hex'] = Array.from(new TextEncoder().encode(kw))
                    .map(b => b.toString(16).padStart(2, '0')).join('');
            }
            return params;
        });
        _Dict.data['_lang_status'] = STATUS;
        table.setState('status', '_lang_status');
        table.setPagination(15, [15, 30, 50, 100, 200]);
        table.render();
    };

    loadRegistry(buildTable);

    $('.btn-lang-add').off(namespace).on('click' + namespace, () => langModal());

    //语言卡片是异步渲染出来的，事件委托到容器上，免得每次刷新都要重新绑一遍
    $('#lang-stat').off(namespace).on('click' + namespace, '.btn-lang-toggle', function () {
        const row = REGISTRY.find(item => item.code === $(this).data('code'));
        if (!row) return;
        const enable = !row.enabled;
        const apply = () => util.post('/admin/api/lang/langSave', {code: row.code, enabled: enable ? 1 : 0}, res => {
            if (!controllerActive) return;
            message.success(res.msg || i18n('（＾∀＾）保存成功'));
            renderStat();
            if (table) table.refresh();
        });
        //停用会立刻对所有访客生效，问一句；启用没有破坏性，直接来
        if (enable) {
            apply();
            return;
        }
        message.ask(i18n('停用「{0}」后访客不再能切到它，翻译插件也不再为它翻译。已有译文会保留，随时可以再启用。确认停用？')
            .replace('{0}', row.name), apply);
    }).on('click' + namespace, '.btn-lang-seed', function () {
        const row = REGISTRY.find(item => item.code === $(this).data('code'));
        if (!row) return;
        //新语言在库里一条记录都没有，只靠访客访问时的 miss 收集要攒很久才够用
        message.ask(i18n('把现有词条全量复制一份给「{0}」并投递给翻译插件，已有译文不会被覆盖，确认？').replace('{0}', row.name), () => {
            util.post('/admin/api/lang/langSeed', {code: row.code}, res => {
                if (!controllerActive) return;
                message.success(res.msg || i18n('已补齐'));
                renderStat();
                if (table) table.refresh();
            });
        });
    }).on('click' + namespace, '.btn-lang-remove', function () {
        const row = REGISTRY.find(item => item.code === $(this).data('code'));
        if (!row) return;
        message.ask(i18n('删除「{0}」会同时清空它的全部译文（{1} 条），且不可恢复。只是暂时不想用的话请改为「停用」。确认删除？')
            .replace('{0}', row.name).replace('{1}', row.total), () => {
            util.post('/admin/api/lang/langDel', {code: row.code}, res => {
                if (!controllerActive) return;
                message.success(res.msg || i18n('删除成功'));
                renderStat();
                if (table) table.refresh();
            });
        });
    });

    $('.btn-lang-rebuild').off(namespace).on('click' + namespace, () => {
        util.post('/admin/api/lang/rebuild', {}, res => {
            if (!controllerActive) return;
            message.success(res.msg || i18n('缓存已重建'));
            renderStat();
        });
    });

    $('.btn-lang-retranslate').off(namespace).on('click' + namespace, () => {
        if (!table) return;
        const data = table.getSelectionIds();
        if (data.length === 0) {
            layer.msg(i18n('请至少勾选 1 个词条'));
            return;
        }
        message.ask(i18n('将清空选中词条的译文并交给翻译插件重新翻译，确认？'), () => {
            util.post('/admin/api/lang/retranslate', {list: data}, res => {
                if (!controllerActive || !table) return;
                message.success(res.msg || i18n('已标记重译'));
                table.refresh();
                renderStat();
            });
        });
    });

    //队列文件可能因崩溃、清盘、插件重装丢失，导致词条卡在"待翻译"却没人再投递；
    //这里把库里所有待翻条目重新丢回队列，不动已有译文
    $('.btn-lang-resend').off(namespace).on('click' + namespace, () => {
        message.ask(i18n('把所有待翻译词条重新投递给翻译插件，已有译文不受影响，确认？'), () => {
            util.post('/admin/api/lang/resend', {}, res => {
                if (!controllerActive || !table) return;
                message.success(res.msg || i18n('已补投'));
                table.refresh();
                renderStat();
            });
        });
    });

    //扩展自带词包：{插件/模板目录}/Lang/{语言}.json。
    //正常安装扩展会自动导入，手工丢进目录或改过 json 时用这个补一次
    //词条按 md5(原文) 寻址，改商品名就是一条新原文、旧的没人认领；这里扫存量
    $('.btn-lang-recycle').off(namespace).on('click' + namespace, () => {
        message.ask(i18n('扫描全部动态词条（商品名、描述、分类名这些），把已经没有任何地方在引用的删掉。日常保存/删除商品时已会自动回收，这个按钮是清历史存量的。确认清理？'), () => {
            util.post('/admin/api/lang/recycle', {}, res => {
                if (!controllerActive) return;
                message.success(res.msg || i18n('清理完成'));
                if (table) table.refresh();
                renderStat();
            });
        });
    });

    $('.btn-lang-scan').off(namespace).on('click' + namespace, () => {
        util.post('/admin/api/lang/scanPacks', {}, res => {
            if (!controllerActive || !table) return;
            message.success(res.msg || i18n('扫描完成'));
            table.refresh();
            renderStat();
        });
    });

    $('.btn-lang-del').off(namespace).on('click' + namespace, () => {
        if (!table) return;
        const data = table.getSelectionIds();
        if (data.length === 0) {
            layer.msg(i18n('请至少勾选 1 个词条'));
            return;
        }
        message.ask(i18n('删除后该词条会在下次出现时重新入库，确认删除？'), () => {
            util.post('/admin/api/lang/del', {list: data}, res => {
                if (!controllerActive || !table) return;
                message.success(res.msg || i18n('删除成功'));
                table.refresh();
                renderStat();
            });
        });
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-lang-rebuild, .btn-lang-retranslate, .btn-lang-resend, .btn-lang-scan, .btn-lang-del, .btn-lang-add, .btn-lang-recycle').off(namespace);
        $('#lang-stat').off(namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        window.__mdConfigLangDestroy = null;
    }

    window.__mdConfigLangDestroy = destroy;
    $(document).one('pjax:send', destroy);
}();
