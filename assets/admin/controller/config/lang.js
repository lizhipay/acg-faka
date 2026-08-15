!function () {
    let table;
    const namespace = '.mdConfigLangController';
    let controllerActive = true;
    if (typeof window.__mdConfigLangDestroy === 'function') window.__mdConfigLangDestroy();

    const LANGS = [
        {id: 'zh-tw', name: '繁體中文'},
        {id: 'en', name: 'English'},
        {id: 'ja', name: '日本語'}
    ];
    const STATUS = [
        {id: 0, name: format.badge(i18n('待翻译'), 'a-badge-danger')},
        {id: 1, name: format.badge(i18n('机器翻译'), 'a-badge-primary')},
        {id: 2, name: format.badge(i18n('人工确认'), 'a-badge-success')}
    ];

    const renderStat = () => {
        util.post({
            url: '/admin/api/lang/stat',
            data: {},
            loader: false,
            done: res => {
                if (!controllerActive) return;
                const list = (res.data && res.data.list) || [];
                const html = list.map(item => {
                    const label = (LANGS.find(l => l.id === item.lang) || {}).name || item.lang;
                    return `<div class="col-md-4">
                        <div class="card h-100"><div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold fs-5">${label}</span>
                                <span class="fs-3 fw-bold text-primary">${item.percent}%</span>
                            </div>
                            <div class="progress h-6px mb-2"><div class="progress-bar bg-primary" style="width:${item.percent}%"></div></div>
                            <div class="text-gray-600 fs-7">${i18n('已翻译')} ${item.translated} / ${item.total}，${i18n('待翻译')} ${item.pending}</div>
                        </div></div>
                    </div>`;
                }).join('');
                $('#lang-stat').html(html);
            }
        });
    };

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);

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
        {title: '语言', name: 'equal-lang', type: 'select', dict: LANGS},
        {title: '状态', name: 'equal-status', type: 'select', dict: STATUS},
        {title: '来源', name: 'equal-scene', type: 'select', dict: [
            {id: 'tpl', name: i18n('模板')},
            {id: 'js', name: i18n('前端')},
            {id: 'api', name: i18n('接口')},
            {id: 'dyn', name: i18n('动态内容')}
        ]}
    ]);
    _Dict.data['_lang_status'] = STATUS;
    table.setState('status', '_lang_status');
    table.setPagination(15, [15, 30, 50, 100, 200]);
    table.render();
    renderStat();

    $('.btn-lang-rebuild').off(namespace).on('click' + namespace, () => {
        util.post('/admin/api/lang/rebuild', {}, res => {
            if (!controllerActive) return;
            message.success(res.msg || i18n('缓存已重建'));
            renderStat();
        });
    });

    $('.btn-lang-retranslate').off(namespace).on('click' + namespace, () => {
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
    $('.btn-lang-scan').off(namespace).on('click' + namespace, () => {
        util.post('/admin/api/lang/scanPacks', {}, res => {
            if (!controllerActive || !table) return;
            message.success(res.msg || i18n('扫描完成'));
            table.refresh();
            renderStat();
        });
    });

    $('.btn-lang-del').off(namespace).on('click' + namespace, () => {
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
        $('.btn-lang-rebuild, .btn-lang-retranslate, .btn-lang-resend, .btn-lang-scan, .btn-lang-del').off(namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        window.__mdConfigLangDestroy = null;
    }

    window.__mdConfigLangDestroy = destroy;
    $(document).one('pjax:send', destroy);
}();
