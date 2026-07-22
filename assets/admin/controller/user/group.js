!function () {
    let table, CommodityGroupTable, CommodityListTable;
    const namespace = '.mdUserGroupController';
    let controllerActive = true;
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '/favicon.ico';
        } catch (error) {
            return '/favicon.ico';
        }
    };
    const normalizeCommodityIds = value => {
        const ids = [];
        (Array.isArray(value) ? value : []).forEach(candidate => {
            const id = typeof candidate === 'number'
                ? candidate
                : (/^\d+$/.test(String(candidate || '').trim()) ? Number(candidate) : 0);
            if (Number.isSafeInteger(id) && id > 0 && !ids.includes(id)) ids.push(id);
        });
        return ids.sort((left, right) => left - right);
    };
    const setInputMeta = (unique, name, attributes) => {
        const input = document.querySelector(`.${unique} [name="${name}"]`);
        if (!input) return;
        Object.entries(attributes).forEach(([key, value]) => input.setAttribute(key, value));
    };

    if (typeof window.__mdUserGroupDestroy === 'function') window.__mdUserGroupDestroy();

    const confirmCommodityGroupDelete = (list, done) => {
        util.post('/admin/api/commodityGroup/deleteImpact', {list: list}, res => {
            if (!controllerActive) return;
            const impact = res.data || {};
            const groupNames = (Array.isArray(impact.group_names) ? impact.group_names : []).map(escapeHtml).join('、') || '所选商品分组';
            const levelNames = (Array.isArray(impact.affected_level_names) ? impact.affected_level_names : []).map(escapeHtml).join('、');
            const levelText = Number(impact.affected_level_count || 0) > 0
                ? `<br><br>同时会从 <b>${Number(impact.affected_level_count)} 个会员等级</b>中清除对应折扣：${levelNames}`
                : '<br><br>没有会员等级使用这些分组折扣。';
            message.ask(
                `将永久删除 <b>${Number(impact.group_count || list.length)} 个商品分组</b>：${groupNames}${levelText}<br><br>商品本身不会被删除。确认继续吗？`,
                () => controllerActive && done(),
                '确认删除商品分组',
                '确认删除'
            );
        });
    };

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/group/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "等级图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            height: 64,
                            required: true
                        },
                        {
                            title: "等级名称",
                            name: "name",
                            type: "input",
                            placeholder: "请输入等级名称",
                            inputmode: "text",
                            enterkeyhint: "next",
                            required: true
                        },
                        {
                            title: "累计元气",
                            name: "recharge",
                            type: "input",
                            placeholder: "请输入累计元气",
                            tips: "当会员元气累计达到这个数量时，将会自动升级为该会员等级，元气=充值/消费1:1获得",
                            inputmode: "decimal",
                            enterkeyhint: "done",
                            required: true
                        }
                    ]
                },
                {
                    name: `${util.icon("fa-duotone fa-regular fa-tags me-1")}商品折扣`,
                    hide: !assign.hasOwnProperty("id"),
                    form: [
                        {
                            title: false,
                            name: "discount_config",
                            type: "custom",
                            complete: (form, dom) => {
                                if (assign.id > 0) {
                                    form.show("discount_config");
                                    dom.html(`<div class="mcy-card"><table id="discount-config-table"></table></div>`);
                                    util.get("/admin/api/group/commodityGroupData?id=" + assign.id, data => {
                                        if (!controllerActive || form.isDestroyed) return;

                                        let discountTable = new Table(data, "#discount-config-table");
                                        form.registerDisposable(discountTable);


                                        discountTable.setColumns([
                                            {
                                                field: 'name', title: '商品分组'
                                            },
                                            {
                                                field: 'value',
                                                title: '折扣(百分比,如填写50,则商品价格×0.5)',
                                                type: "input",
                                                inputmode: "decimal",
                                                enterkeyhint: "done"
                                            },
                                        ]);

                                        discountTable.onComplete($table => {
                                            $table.find('input.metadata-text').attr({
                                                inputmode: 'decimal',
                                                enterkeyhint: 'done',
                                                autocomplete: 'off'
                                            });
                                        });


                                        discountTable.setUpdate(data => {
                                            util.post({
                                                loader: false,
                                                url: "/admin/api/group/setDiscountConfig",
                                                data: {
                                                    group_id: assign.id,
                                                    id: data.id,
                                                    value: data.value
                                                },
                                                done: () => {
                                                    if (controllerActive && !form.isDestroyed) layer.msg("折扣已生效");
                                                }
                                            });


                                        });
                                        discountTable.render();
                                    });

                                }
                            }
                        }
                    ]
                },
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "480px",
            renderComplete: unique => {
                setInputMeta(unique, 'name', {
                    inputmode: 'text',
                    enterkeyhint: 'next',
                    autocomplete: 'off'
                });
                setInputMeta(unique, 'recharge', {
                    inputmode: 'decimal',
                    enterkeyhint: 'done',
                    autocomplete: 'off'
                });
            },
            done: () => {
                if (controllerActive) table.refresh();
            }
        });
    }
    const CommodityGroupModal = (title, assign = {}) => {
            CommodityListTable = null;
            component.popup({
                submit: (_, __) => {
                    if (!controllerActive) return;
                    if (assign?.id && typeof CommodityListTable == "object" && !CommodityListTable.isDestroyed) {
                        _.commodity_list = normalizeCommodityIds(CommodityListTable.getSelectionIds());
                    }
                    delete _.commodity_list1;
                    util.post("/admin/api/commodityGroup/save", _, () => {
                        if (!controllerActive) return;
                        message.success("保存成功");
                        CommodityGroupTable.refresh();
                        layer.close(__);
                    });
                },
                tab: [
                    {
                        name: title,
                        form: [
                            {
                                title: "分组名称",
                                name: "name",
                                type: "input",
                                placeholder: "请输入分组名称",
                                inputmode: "text",
                                enterkeyhint: "done",
                                required: true
                            },
                            {
                                title: false,
                                name: "commodity_list1",
                                type: "custom",
                                hide: !assign?.id,
                                complete: (form, dom) => {
                                    if (assign.id > 0) {
                                        const useMobileTree = mobileAdminEnabled();
                                        const mobileSearchId = `commodity-group-mobile-keyword-${form.unique}`;
                                        const initialSelection = JSON.stringify(normalizeCommodityIds(assign.commodity_list));
                                        const mobileSearch = useMobileTree ? `<div data-commodity-group-mobile-search style="display:grid;gap:8px;margin:0 0 14px;">
                                            <label for="${mobileSearchId}" style="font-weight:700;">搜索商品</label>
                                            <input id="${mobileSearchId}" class="layui-input" type="search" inputmode="search" enterkeyhint="search" autocomplete="off" placeholder="输入商品名称">
                                            <small role="status" aria-live="polite" style="color:var(--admin-mobile-muted,#7a7f86);"></small>
                                        </div>` : '';
                                        dom.html(`<input type="hidden" name="commodity_list1" value="${escapeHtml(initialSelection)}">${mobileSearch}<div class="mcy-card"><table id="commodity-table"></table></div>`);
                                        CommodityListTable = new Table(`/admin/api/commodityGroup/list?id=${assign.id}${useMobileTree ? '&mobile=1' : ''}`, dom.find("#commodity-table"));
                                        form.registerDisposable(CommodityListTable, instance => {
                                            if (instance && typeof instance.destroy === 'function') instance.destroy();
                                            if (CommodityListTable === instance) CommodityListTable = null;
                                        });
                                        CommodityListTable.setTree(1);
                                        CommodityListTable.setSearch([
                                            {
                                                title: "商品关键词搜索",
                                                name: "keyword",
                                                type: "input",
                                                width: 320,
                                                align: 'center',
                                                change: (search, val) => {
                                                    CommodityListTable.fullTextSearch(val.toLowerCase());
                                                }
                                            }
                                        ], false);
                                        CommodityListTable.setColumns([
                                            {checkbox: true},
                                            {
                                                field: 'name',
                                                title: '商品名称',
                                                class: "nowrap"
                                            }
                                        ]);
                                        CommodityListTable.disablePagination();
                                        CommodityListTable.render();

                                        const $selectionFingerprint = dom.find('input[name="commodity_list1"]');
                                        const syncSelectionFingerprint = () => {
                                            if (!controllerActive || form.isDestroyed || !CommodityListTable || CommodityListTable.isDestroyed) return;
                                            $selectionFingerprint.val(JSON.stringify(normalizeCommodityIds(CommodityListTable.getSelectionIds())));
                                        };
                                        const selectionNamespace = `.mdCommodityGroupSelection${CommodityListTable.unique}`;
                                        if (useMobileTree) {
                                            CommodityListTable.$table.on(
                                                `check.bs.table${selectionNamespace} uncheck.bs.table${selectionNamespace}`,
                                                (event, row) => {
                                                    if (!row || row.node_type !== 'category') return;
                                                    const rows = CommodityListTable.$table.bootstrapTable('getData', {useCurrentPage: false}) || [];
                                                    const childrenByParent = new Map();
                                                    rows.forEach(item => {
                                                        const parentId = String(item?.pid ?? '');
                                                        if (!childrenByParent.has(parentId)) childrenByParent.set(parentId, []);
                                                        childrenByParent.get(parentId).push(item);
                                                    });
                                                    const queue = [String(row.id)];
                                                    const seen = new Set(queue);
                                                    const descendants = [];
                                                    while (queue.length) {
                                                        const parentId = queue.shift();
                                                        (childrenByParent.get(parentId) || []).forEach(item => {
                                                            const itemId = String(item?.id ?? '');
                                                            if (!itemId || seen.has(itemId)) return;
                                                            seen.add(itemId);
                                                            queue.push(itemId);
                                                            descendants.push(item.id);
                                                        });
                                                    }
                                                    if (descendants.length) {
                                                        CommodityListTable.$table.bootstrapTable(
                                                            event.type === 'check' ? 'checkBy' : 'uncheckBy',
                                                            {field: 'id', values: descendants}
                                                        );
                                                    }
                                                }
                                            );
                                        }
                                        CommodityListTable.$table.on(`admin:table:ready${selectionNamespace} admin:table:update${selectionNamespace}`, (event, payload) => {
                                            if (!payload || payload.reason === 'selection' || event.type === 'admin:table:ready') syncSelectionFingerprint();
                                        });
                                        CommodityListTable.$table.on(
                                            `check.bs.table${selectionNamespace} uncheck.bs.table${selectionNamespace} ` +
                                            `check-all.bs.table${selectionNamespace} uncheck-all.bs.table${selectionNamespace}`,
                                            syncSelectionFingerprint
                                        );
                                        form.registerDisposable(null, () => {
                                            if (CommodityListTable && CommodityListTable.$table) CommodityListTable.$table.off(selectionNamespace);
                                        });

                                        const $mobileSearch = dom.find('[data-commodity-group-mobile-search]');
                                        if ($mobileSearch.length) {
                                            const $input = $mobileSearch.find('input');
                                            const $status = $mobileSearch.find('[role="status"]');
                                            const eventNamespace = `.mdCommodityGroupSearch${CommodityListTable.unique}`;
                                            let frame = 0;
                                            let query = '';
                                            const applyMobileSearch = () => {
                                                frame = 0;
                                                if (!controllerActive || form.isDestroyed || !CommodityListTable || CommodityListTable.isDestroyed) return;
                                                const host = document.querySelector(`[data-admin-mobile-table="${CommodityListTable.unique}"]`);
                                                if (!host) {
                                                    $status.text('正在加载商品列表…');
                                                    return;
                                                }
                                                const cards = Array.from(host.querySelectorAll('.admin-mobile-data-card'));
                                                const cardsByKey = new Map();
                                                cards.forEach(card => {
                                                    const key = String(card.getAttribute('data-admin-mobile-tree-key') || '');
                                                    if (key) cardsByKey.set(key, card);
                                                    card.hidden = false;
                                                });
                                                const directMatches = query ? cards.filter(card => {
                                                    const heading = card.querySelector('.admin-mobile-card-heading strong');
                                                    return String(heading?.textContent || '').toLocaleLowerCase().includes(query);
                                                }) : cards;
                                                const visibleKeys = new Set();
                                                directMatches.forEach(card => {
                                                    let current = card;
                                                    const visited = new Set();
                                                    while (current) {
                                                        const key = String(current.getAttribute('data-admin-mobile-tree-key') || '');
                                                        if (!key || visited.has(key)) break;
                                                        visited.add(key);
                                                        visibleKeys.add(key);
                                                        const parentKey = String(current.getAttribute('data-admin-mobile-tree-parent-key') || '');
                                                        current = parentKey ? cardsByKey.get(parentKey) : null;
                                                    }
                                                });
                                                host.classList.toggle('is-tree-searching', Boolean(query));
                                                cards.forEach(card => {
                                                    const key = String(card.getAttribute('data-admin-mobile-tree-key') || '');
                                                    card.classList.toggle('is-hidden-by-search', Boolean(query) && !visibleKeys.has(key));
                                                });
                                                host.querySelectorAll('[data-admin-mobile-tree-toggle]').forEach(button => {
                                                    button.disabled = Boolean(query);
                                                    button.setAttribute('aria-disabled', query ? 'true' : 'false');
                                                });
                                                $status.text(query ? `找到 ${directMatches.length} 个匹配项` : `共 ${cards.length} 项`);
                                            };
                                            const scheduleMobileSearch = () => {
                                                if (frame) cancelAnimationFrame(frame);
                                                frame = requestAnimationFrame(applyMobileSearch);
                                            };
                                            $input.on('input' + eventNamespace, function () {
                                                query = String(this.value || '').trim().toLocaleLowerCase();
                                                CommodityListTable.fullTextSearch(query);
                                                scheduleMobileSearch();
                                            });
                                            CommodityListTable.$table.on(`admin:table:ready${eventNamespace} admin:table:update${eventNamespace}`, scheduleMobileSearch);
                                            form.registerDisposable(null, () => {
                                                if (frame) cancelAnimationFrame(frame);
                                                $input.off(eventNamespace);
                                                if (CommodityListTable && CommodityListTable.$table) CommodityListTable.$table.off(eventNamespace);
                                            });
                                            scheduleMobileSearch();
                                        }
                                    }
                                }
                            }
                        ]
                    }
                ],
                assign: assign,
                autoPosition: true,
                height: "auto",
                width: "720px",
                renderComplete: unique => {
                    setInputMeta(unique, 'name', {
                        inputmode: 'text',
                        enterkeyhint: 'done',
                        autocomplete: 'off'
                    });
                },
                done: () => {
                    if (controllerActive) CommodityGroupTable.refresh();
                }
            });
    }

    table = new Table("/admin/api/group/data", "#user-group");
    table.setUpdate("/admin/api/group/save");
    table.setColumns([
        {
            field: 'name', title: '等级名称', formatter: (name, row) => {
                const icon = escapeHtml(safeImageUrl(row.icon));
                return `<div class="md-plugin"><img src="${icon}" class="md-plugin__icon" alt=""><span class="md-plugin__name">${escapeHtml(row.name)}</span></div>`;
            }
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    title: "修改",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改等级", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    title: "删除",
                    click: (event, value, row, index) => {
                        message.ask("是否删除该等级？", () => {
                            util.post("/admin/api/group/del", {id: row.id}, () => {
                                if (!controllerActive) return;
                                table.refresh();
                                layer.msg("删除成功");
                            })
                        });
                    }
                }
            ]
        },
    ]);
    table.disablePagination();
    table.render();


    $('.btn-group-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加等级`);
    });


    $('.btn-commodity-group-create').off(namespace).on('click' + namespace, function () {
        CommodityGroupModal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加商品分组`);
    });


    CommodityGroupTable = new Table("/admin/api/commodityGroup/data", "#commodity-group");
    CommodityGroupTable.setColumns([
        {
            field: 'name', title: '分组名称'
        },
        {
            field: 'count', title: '商品', formatter: (_, __) => __?.commodity_list?.length
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-gear',
                    class: "text-primary",
                    title: "设置",
                    click: (event, value, row, index) => {
                        CommodityGroupModal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改商品分组", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    title: "删除",
                    click: (event, value, row, index) => {
                        confirmCommodityGroupDelete([row.id], () => {
                            util.post("/admin/api/commodityGroup/del", {list: [row.id]}, () => {
                                if (!controllerActive) return;
                                CommodityGroupTable.refresh();
                                message.success("删除成功");
                            })
                        });
                    }
                }
            ]
        }
    ]);
    CommodityGroupTable.disablePagination();
    CommodityGroupTable.render();

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-group-create, .btn-commodity-group-create').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        [CommodityListTable, CommodityGroupTable, table].forEach(instance => {
            if (instance && !instance.isDestroyed && typeof instance.destroy === 'function') instance.destroy();
        });
        CommodityListTable = null;
        CommodityGroupTable = null;
        table = null;
        if (window.__mdUserGroupDestroy === destroy) delete window.__mdUserGroupDestroy;
    }

    window.__mdUserGroupDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);

}();
