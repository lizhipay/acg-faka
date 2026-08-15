!function () {
    const namespace = '.mdSharedPriceTemplateController';
    let table, controllerActive = true;
    let groupRevision = 0;

    if (typeof window.__mdPriceTemplateDestroy === 'function') window.__mdPriceTemplateDestroy();

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);

    //商品名常带 <b>/<span> 等美化标签，预览里只要纯文本，
    //否则会把标签本身当内容显示出来
    const plainText = value => {
        const holder = document.createElement('div');
        holder.innerHTML = String(value ?? '');
        return (holder.textContent || '').trim();
    };

    const BASE = [
        {id: 0, name: '成本价'},
        {id: 1, name: '当前售价'}
    ];
    const TYPE = [
        {id: 1, name: '百分比 (%)'},
        {id: 0, name: '固定金额 (元)'}
    ];
    const ROUNDING = [
        {id: 0, name: '不取整（保留小数）'},
        {id: 1, name: '四舍五入到整元'},
        {id: 2, name: '向上取整到整元'}
    ];

    const typeName = value => (TYPE.find(t => t.id === Number(value)) || {}).name || '-';
    //加价值的可读写法：百分比 +30%、固定金额 +3.00 元；负数即为降价
    const ruleText = (type, value) => {
        const number = Number(value || 0);
        const sign = number >= 0 ? '+' : '';
        return Number(type) === 1 ? `${sign}${number}%` : `${sign}${number.toFixed(2)} 元`;
    };

    /**
     * 模板编辑弹窗。会员等级规则用一张表编辑，值留空＝该等级不参与模板（保留商品原有配置）。
     */
    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/priceTemplate/save',
            tab: [
                {
                    name: title,
                    form: [
                        {title: 'id', name: 'id', type: 'input', hide: true},
                        {title: 'level_config', name: 'level_config', type: 'textarea', hide: true},
                        {
                            title: '模板名称',
                            name: 'name',
                            type: 'input',
                            required: true,
                            placeholder: '例如：对接商品-标准加价'
                        },
                        {
                            title: '加价基准',
                            name: 'base',
                            type: 'radio',
                            dict: BASE,
                            default: 0,
                            tips: '成本价＝商品的进货价；当前售价＝商品现在的游客价。对接商品同步上游价格后，通常选「成本价」。'
                        },
                        {
                            title: '游客价加价方式',
                            name: 'guest_type',
                            type: 'radio',
                            dict: TYPE,
                            default: 1
                        },
                        {
                            title: '游客价加价值',
                            name: 'guest_value',
                            type: 'input',
                            inputmode: 'decimal',
                            default: '0',
                            tips: '百分比填 30 表示在基准价上 +30%；固定金额填 3 表示 +3 元。填负数即为降价。'
                        },
                        {
                            title: '会员价加价方式',
                            name: 'user_type',
                            type: 'radio',
                            dict: TYPE,
                            default: 1
                        },
                        {
                            title: '会员价加价值',
                            name: 'user_value',
                            type: 'input',
                            inputmode: 'decimal',
                            default: '0',
                            tips: '登录用户看到的基准价（未单独设置等级价时生效）。'
                        },
                        {
                            title: '价格取整',
                            name: 'rounding',
                            type: 'select',
                            dict: ROUNDING,
                            default: 0,
                            tips: '按百分比加价后常出现小数，可统一取整到整元。'
                        },
                        {
                            title: false,
                            name: 'level_rules',
                            type: 'custom',
                            submit: false,
                            complete: (form, dom) => {
                                const revision = ++groupRevision;
                                dom.html(`<div class="mcy-card"><div class="text-gray-600 fs-7 mb-3">${i18n('会员等级加价：留空表示该等级不参与本模板，应用时保留商品原有的等级价。')}</div><table id="tpl-group-table"></table></div>`);

                                util.get('/admin/api/group/data', res => {
                                    if (!controllerActive || form.isDestroyed || revision !== groupRevision) return;

                                    let config = {};
                                    try {
                                        const raw = form.getData('level_config');
                                        config = JSON.parse(raw ? decodeURIComponent(raw) : '{}') || {};
                                    } catch (e) {
                                        config = {};
                                    }

                                    const list = (res.list || []).map(item => {
                                        const rule = config[item.id] || {};
                                        return Object.assign({}, item, {
                                            rule_type: rule.type ?? 1,
                                            rule_value: rule.value ?? ''
                                        });
                                    });

                                    const sync = () => form.setTextarea('level_config', JSON.stringify(config));
                                    const groupTable = new Table(list, dom.find('#tpl-group-table'));

                                    groupTable.setColumns([
                                        {
                                            field: 'name', title: '会员等级', class: 'nowrap',
                                            formatter: (_, row) => format.group(row)
                                        },
                                        {
                                            field: 'rule_type', title: '加价方式', type: 'select', dict: TYPE,
                                            change: (value, row) => {
                                                config[row.id] = config[row.id] || {};
                                                config[row.id].type = Number(value);
                                                if (config[row.id].value === undefined || config[row.id].value === '') delete config[row.id];
                                                sync();
                                            }
                                        },
                                        {
                                            field: 'rule_value', title: '加价值', type: 'input',
                                            //表格组件会把空值渲染成 "-"，这里还原成空白：本列留空＝该等级不参与模板
                                            formatter: value => (value === '-' ? '' : value),
                                            change: (value, row) => {
                                                if (value === '' || value === null) {
                                                    delete config[row.id];
                                                    sync();
                                                    return;
                                                }
                                                if (isNaN(Number(value))) {
                                                    layer.msg(i18n('加价值必须是数字'));
                                                    return;
                                                }
                                                config[row.id] = config[row.id] || {};
                                                config[row.id].type = Number(config[row.id].type ?? row.rule_type ?? 1);
                                                config[row.id].value = Number(value);
                                                sync();
                                            }
                                        }
                                    ]);
                                    groupTable.render();
                                });
                            }
                        }
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            height: 'auto',
            width: '720px',
            done: () => {
                if (!controllerActive || !table) return;
                table.refresh();
            }
        });
    };

    /**
     * 应用弹窗：选范围 -> 预览命中数量与前几条价格变化 -> 确认执行。
     */
    const applyModal = row => {
        component.popup({
            tab: [
                {
                    name: `${util.icon('fa-duotone fa-regular fa-wand-magic-sparkles')} ${i18n('应用模板')}：${escapeHtml(row.name)}`,
                    form: [
                        {title: 'template_id', name: 'template_id', type: 'input', hide: true},
                        {
                            title: '应用范围',
                            name: 'scope',
                            type: 'radio',
                            dict: [
                                {id: 'shared', name: '仅对接商品'},
                                {id: 'category', name: '指定分类'},
                                {id: 'all', name: '全部自营商品'}
                            ],
                            default: 'shared',
                            change: (form, value) => {
                                value === 'category' ? form.show('category_id') : form.hide('category_id');
                            }
                        },
                        {
                            title: '商品分类',
                            name: 'category_id',
                            type: 'select',
                            dict: 'category->owner=0,id,name',
                            search: true,
                            hide: true,
                            placeholder: '请选择分类'
                        }
                    ]
                }
            ],
            assign: {template_id: row.id, scope: 'shared'},
            autoPosition: true,
            //分类下拉展开时会超出弹层高度，默认的 overflow 会把它裁掉，这里放它出去
            content: {
                css: {
                    height: 'auto',
                    overflow: 'inherit'
                }
            },
            height: 'auto',
            width: '520px',
            confirmText: i18n('预览影响'),
            submit: (data, index) => {
                util.post('/admin/api/priceTemplate/applyImpact', data, res => {
                    if (!controllerActive) return;
                    const impact = res.data || {};
                    const total = Number(impact.total || 0);
                    const affected = Number(impact.affected ?? total);
                    const skipped = Number(impact.skipped || 0);
                    if (total === 0) {
                        message.alert(i18n('当前范围内没有可应用的商品'), 'warning');
                        return;
                    }
                    if (affected === 0) {
                        message.alert(`${i18n('命中')} ${total} ${i18n('个商品，但它们的')}${escapeHtml(impact.base_label || '')}${i18n('都是 0，套用后价格会变成 0，已全部跳过。请先补齐')}${escapeHtml(impact.base_label || '')}${i18n('，或改用其他加价基准。')}`, 'warning');
                        return;
                    }
                    if (impact.exceeded) {
                        message.alert(`${i18n('命中')} ${total} ${i18n('个商品，超过单次上限')} ${Number(impact.limit)} ${i18n('个，请缩小范围后分批应用')}`, 'warning');
                        return;
                    }

    const cell = 'padding:7px 12px;border-bottom:1px solid rgba(var(--md-on-surface-rgb,60,64,67),.08);';
                    const rows = (impact.preview || []).map(item => {
                        //种类商品的价格在配置参数里，商品单价不变，标注出来免得以为没生效
                        const catTip = Number(item.category_count || 0) > 0
                            ? `<div style="font-size:11px;color:#9aa0a6;margin-top:2px;">${i18n('含')} ${Number(item.category_count)} ${i18n('个种类价，随配置参数一起加价')}</div>`
                            : '';
                        return `<tr>
                        <td style="${cell}">${escapeHtml(plainText(item.name))}${catTip}</td>
                        <td style="${cell}text-align:right;">${escapeHtml(item.base)}</td>
                        <td style="${cell}text-align:right;color:#9aa0a6;">${escapeHtml(item.old_price)}</td>
                        <td style="${cell}text-align:right;font-weight:700;color:#1a73e8;">${escapeHtml(item.new_price)}</td>
                        <td style="${cell}text-align:right;color:#9aa0a6;">${escapeHtml(item.old_user_price)}</td>
                        <td style="${cell}text-align:right;font-weight:700;color:#1a73e8;">${escapeHtml(item.new_user_price)}</td>
                    </tr>`;
                    }).join('');

                    const skipTip = skipped > 0
                        ? `<div class="mb-2 text-warning">${i18n('另有')} <b>${skipped}</b> ${i18n('个商品的')}${escapeHtml(impact.base_label || '')}${i18n('为 0，会被自动跳过（避免价格被算成 0）。')}</div>`
                        : '';
                    const detail = `<div style="text-align:left;">
                        <div class="mb-3">${i18n('本次将影响')} <b>${affected}</b> ${i18n('个商品，以下为前几条的价格变化预览：')}</div>
                        ${skipTip}
                        <div style="overflow-x:auto;"><table style="width:100%;font-size:13px;border-collapse:collapse;white-space:nowrap;">
                            <thead><tr style="color:#9aa0a6;font-size:12px;">
                                <th style="${cell}text-align:left;white-space:normal;">${i18n('商品')}</th>
                                <th style="${cell}text-align:right;">${i18n('基准')}</th>
                                <th style="${cell}text-align:right;">${i18n('原游客价')}</th>
                                <th style="${cell}text-align:right;">${i18n('新游客价')}</th>
                                <th style="${cell}text-align:right;">${i18n('原会员价')}</th>
                                <th style="${cell}text-align:right;">${i18n('新会员价')}</th>
                            </tr></thead>
                            <tbody>${rows}</tbody>
                        </table></div>
                        <div class="mt-3 text-danger">${i18n('价格将被直接覆盖，且没有一键撤销，请确认预览结果无误。')}</div>
                    </div>`;

                    message.ask(detail, () => {
                        util.post('/admin/api/priceTemplate/apply', data, done => {
                            if (!controllerActive) return;
                            message.success(done.msg || i18n('已应用'));
                            layer.close(index);
                        });
                    }, i18n('确认应用加价模板？'), i18n('确认应用'), {
                        width: 'min(92vw, 860px)',
                        //style.bundle.css 给所有 Swal 的正文区写死了 max-height:200px，
                        //预览表格会被压成三四行；这里只对本弹窗放开
                        customClass: {htmlContainer: 'md-price-preview'}
                    });
                });
                return false; //预览与执行都在这里处理，不走组件默认提交
            }
        });
    };

    table = new Table('/admin/api/priceTemplate/data', '#price-template-table');
    table.setColumns([
        {checkbox: true},
        {field: 'name', title: '模板名称'},
        {
            field: 'base', title: '加价基准', class: 'nowrap',
            formatter: value => format.badge((BASE.find(b => b.id === Number(value)) || {name: '-'}).name, 'a-badge-primary')
        },
        {
            field: 'guest_value', title: '游客价', class: 'nowrap',
            formatter: (value, row) => format.badge(ruleText(row.guest_type, value), 'a-badge-success')
        },
        {
            field: 'user_value', title: '会员价', class: 'nowrap',
            formatter: (value, row) => format.badge(ruleText(row.user_type, value), 'a-badge-info')
        },
        {
            field: 'level_config', title: '会员等级', class: 'nowrap',
            formatter: value => {
                let count = 0;
                try {
                    count = Object.keys(JSON.parse(value || '{}') || {}).length;
                } catch (e) {
                    count = 0;
                }
                return count > 0 ? format.badge(`${count} ${i18n('个等级')}`, 'a-badge-warning') : '-';
            }
        },
        {
            field: 'rounding', title: '取整', class: 'nowrap',
            formatter: value => (ROUNDING.find(r => r.id === Number(value)) || {name: '-'}).name
        },
        {field: 'create_time', title: '创建时间', class: 'nowrap'},
        {
            field: 'operation', title: '操作', class: 'nowrap', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-wand-magic-sparkles',
                    class: 'text-success',
                    title: '应用',
                    click: (event, value, row) => applyModal(row)
                },
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: 'text-primary',
                    title: '编辑',
                    click: (event, value, row) => {
                        modal(util.icon('fa-duotone fa-regular fa-pen-to-square') + ' ' + i18n('编辑加价模板'), row);
                    }
                }
            ]
        }
    ]);
    table.setSearch([{title: '模板名称', name: 'search-name', type: 'input'}]);
    table.render();

    $('.btn-tpl-create').off(namespace).on('click' + namespace, () => {
        modal(util.icon('fa-duotone fa-regular fa-circle-plus') + ' ' + i18n('新建加价模板'));
    });

    $('.btn-tpl-del').off(namespace).on('click' + namespace, () => {
        const data = table.getSelectionIds();
        if (data.length === 0) {
            layer.msg(i18n('请至少勾选 1 个模板'));
            return;
        }
        message.ask(i18n('删除模板不会影响已应用到商品上的价格，确认删除？'), () => {
            util.post('/admin/api/priceTemplate/del', {list: data}, res => {
                if (!controllerActive || !table) return;
                message.success(res.msg || i18n('删除成功'));
                table.refresh();
            });
        }, i18n('确认删除加价模板？'), i18n('确认删除'));
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        groupRevision += 1;
        $('.btn-tpl-create, .btn-tpl-del').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdPriceTemplateDestroy === destroy) delete window.__mdPriceTemplateDestroy;
    }

    window.__mdPriceTemplateDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
