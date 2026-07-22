!function () {
    let table;
    const namespace = '.mdTradeCategoryController';
    let controllerActive = true;
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const confirmCategoryDelete = (rows, done) => {
        const selected = (Array.isArray(rows) ? rows : []).filter(Boolean);
        const ids = selected.map(row => Number(row.id)).filter(id => Number.isInteger(id) && id > 0);
        if (!ids.length) {
            message.error('没有可删除的分类');
            return;
        }
        const names = selected.slice(0, 4).map(row => escapeHtml(row.name || `ID ${row.id}`));
        const more = selected.length > names.length ? ` 等 ${selected.length} 个分类` : '';
        util.post({
            url: '/admin/api/category/deleteImpact',
            data: {list: ids},
            done: res => {
                if (!controllerActive) return;
                const impact = res?.data || {};
                const impactSummary = `<div style="text-align:left;line-height:1.8;">
                    <div><b>所选分类：</b>${names.join('、') || '当前所选分类'}${more}</div>
                    <div style="margin-top:10px;padding:10px 12px;border-radius:12px;background:rgba(127,127,127,.09);">
                        <div><b>明确选择：</b>${escapeHtml(impact.category_count ?? 0)} 个分类</div>
                        <div><b>未选择的下级分类：</b>${escapeHtml(impact.unselected_descendant_count ?? 0)} 个</div>
                        <div><b>分类内商品：</b>${escapeHtml(impact.commodity_count ?? 0)} 个</div>
                        <div><b>分类优惠券：</b>${escapeHtml(impact.coupon_count ?? 0)} 张</div>
                        <div><b>商户分类映射：</b>${escapeHtml(impact.user_category_count ?? 0)} 条</div>
                        <div><b>网站默认分类引用：</b>${escapeHtml(impact.config_reference_count ?? 0)} 条</div>
                        <div><b>ThirdDockManage 克隆规则：</b>${escapeHtml(impact.third_dock_rule_count ?? 0)} 条</div>
                    </div>`;
                if (impact.can_delete !== true) {
                    message.alert(
                        `${impactSummary}<div style="margin-top:10px;color:#d14343;">系统已阻止删除，未删除任何数据。请先处理分类内商品、未选择的下级分类及上述直接引用；系统不会级联删除商品、优惠券、插件规则或历史数据。</div></div>`,
                        'warning'
                    );
                    return;
                }
                const previewToken = String(impact.preview_token || '');
                if (!previewToken) {
                    message.error('服务器未返回有效的删除预览凭证，已阻止删除');
                    return;
                }
                Swal.fire({
                    title: selected.length > 1 ? `确认删除 ${selected.length} 个所选分类` : '确认删除分类',
                    html: `${impactSummary}<div style="margin-top:10px;color:#d14343;">只会删除明确选择且不含商品、下级分类或任何业务引用的空分类。预览凭证 3 分钟内有效，范围变化会自动阻止删除；操作不可撤销。</div></div>`,
                    icon: 'warning',
                    showCancelButton: true,
                    cancelButtonText: '取消',
                    confirmButtonText: '确认永久删除'
                }).then(result => {
                    if (result.isConfirmed === true || result.value === true) done(previewToken);
                });
            },
            error: res => message.error(res?.msg || '无法计算删除影响，已阻止删除'),
            fail: () => message.error('网络异常，已阻止删除')
        });
    };

    if (typeof window.__mdTradeCategoryDestroy === 'function') window.__mdTradeCategoryDestroy();
    const confirmCategoryStatus = (rows, status, done, options = {}) => {
        const selected = (Array.isArray(rows) ? rows : []).filter(Boolean);
        const enabling = Number(status) === 1;
        if (!mobileAdminEnabled()) {
            if (options.desktopConfirm) message.ask(null, done); else done();
            return;
        }
        const names = selected.slice(0, 4).map(row => escapeHtml(row.name || `ID ${row.id}`));
        Swal.fire({
            title: enabling ? '确认启用分类' : '确认停用分类',
            html: `<div style="text-align:left;line-height:1.8;">
                <div><b>所选分类：</b>${names.join('、') || `共 ${selected.length} 个分类`}</div>
                <div style="margin-top:10px;">${enabling
                    ? '为保证层级完整，系统会同时启用所选分类尚未启用的上级分类。'
                    : '系统会同时停用所选分类下的全部子分类，相关商品将不再通过这些分类展示。'}</div>
            </div>`,
            icon: enabling ? 'question' : 'warning',
            showCancelButton: true,
            cancelButtonText: '取消',
            confirmButtonText: enabling ? '确认启用' : '确认停用'
        }).then(result => {
            if (result.isConfirmed === true || result.value === true) done();
            else if (typeof options.cancel === 'function') options.cancel();
        });
    };
    const modal = (title, assign = {}) => {
        const ownerId = Number(assign?.owner?.id ?? assign?.owner ?? 0) || 0;
        component.popup({
            submit: '/admin/api/category/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            name: "user_level_config",
                            type: "textarea",
                            hide: true
                        },
                        {
                            title: "父级分类",
                            name: "pid",
                            type: "treeSelect",
                            dict: `category->owner=${ownerId},id,name,pid&tree=true`,
                            placeholder: "父级分类，可不选",
                            parent: true
                        },
                        {
                            title: "图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            height: 64,
                            required: true
                        },
                        {
                            title: "分类名称",
                            name: "name",
                            type: "textarea",
                            height: 38,
                            placeholder: "请输入分类名称",
                            required: true
                        },
                        {title: "排序", name: "sort", type: "input", placeholder: "值越小，排名越靠前哦~"},
                        {
                            title: "隐藏分类",
                            name: "hide",
                            type: "switch",
                            text: "是",
                            default: 0,
                            tips: "隐藏分类后，游客将看不见该分类，但你可以通过右侧的《会员等级》来进行对指定的会员等级显示。"
                        },
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-user") + " 会员等级",
                    form: [
                        {
                            name: "user",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="mcy-card"><table id="category-group-table"></table></div>`);

                                util.get("/admin/api/group/data", res => {
                                    if (!controllerActive || form.isDestroyed) return;
                                    let raw = form.getData("user_level_config");
                                    let config = {};

                                    try {
                                        const source = raw ? String(raw) : "{}";
                                        let configStr = source;
                                        try { configStr = decodeURIComponent(source); } catch (error) {}
                                        config = JSON.parse(configStr);
                                        if (!config || typeof config !== "object" || Array.isArray(config)) config = {};
                                    } catch (e) {
                                        config = {};
                                    }

                                    for (let i = 0; i < res.list.length; i++) {
                                        res.list[i]['show'] = config[res.list[i].id]?.show ? 1 : 0;
                                    }

                                    const groupTable = new Table(res.list, dom.find('#category-group-table'));
                                    form.registerDisposable(groupTable);

                                    groupTable.setColumns([
                                        {
                                            field: 'name',
                                            title: '会员',
                                            class: 'nowrap',
                                            formatter: (_, __) => format.group(__)
                                        },
                                        {
                                            field: 'show',
                                            title: '绝对显示',
                                            type: 'switch',
                                            text: "启用|关闭",
                                            change: (_, __) => {
                                                config[__.id] = {"show": _};
                                                form.setTextarea("user_level_config", JSON.stringify(config));
                                            }
                                        }
                                    ]);
                                    groupTable.render();
                                });
                            }
                        }
                    ]
                },
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "680px",
            renderComplete: unique => {
                $('.' + unique + ' input[name="sort"]').attr({inputmode: 'numeric', autocomplete: 'off'});
            },
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/api/category/data", "#category-table");
    table.setUpdate(data => {
        const isStatus = Object.prototype.hasOwnProperty.call(data, 'status');
        const row = table.getRows().find(item => Number(item.id) === Number(data.id));
        const refresh = () => { if (controllerActive && table) table.refresh(true); };
        const submit = () => {
            const payload = isStatus
                ? {list: [data.id], status: Number(data.status)}
                : data;
            util.post({
                url: isStatus ? '/admin/api/category/status' : '/admin/api/category/save',
                data: payload,
                done: () => {
                    if (!controllerActive) return;
                    message.success('已更新 (｡•ᴗ-)');
                    refresh();
                },
                error: res => {
                    message.error(res?.msg || '分类更新失败');
                    refresh();
                },
                fail: () => {
                    message.error('网络异常，分类未更新');
                    refresh();
                }
            });
        };
        if (isStatus) {
            confirmCategoryStatus(row ? [row] : [], Number(data.status), submit, {cancel: refresh});
            return;
        }
        submit();
    });
    table.setTree(3);
    table.setColumns([
        {checkbox: true},
        {field: 'icon', title: '', type: "image", style: "border-radius:25%;", width: 28},
        {
            field: 'owner', title: '创建者', formatter: (_, __) => mdOwnerCell(_)
        },
        {
            field: 'name', title: '分类名称'
        }
        , {field: 'sort', title: '排序(越小越前)', sort: true, type: "input", reload: true}
        , {
            field: 'share_url', title: '推广链接', type: "button", buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-copy',
                    class: "text-primary",
                    title: "复制",
                    click: (event, value, row, index) => {
                        util.copyTextToClipboard(row.share_url, () => {
                            message.success("复制成功");
                        });
                    }
                },
            ]
        }
        , {
            field: 'hide', title: '隐藏', type: "switch", text: "隐藏|未隐藏"
        }
        , {
            field: 'status', title: '状态', type: "switch", text: "启用|停用", mobileConfirm: false
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + "修改分类", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        confirmCategoryDelete([row], previewToken => {
                            util.post('/admin/api/category/del', {list: [row.id], preview_token: previewToken}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);
    table.setSearch([
        {
            title: "商家，默认主站",
            name: "user_id",
            type: "remoteSelect",
            dict: "user->business_level>0,id,username"
        },
        {title: "分类名称", name: "search-name", type: "input"}
    ]);
    table.setState("status", "_common_status");

    table.render();


    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> 添加分类`);
    });

    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个商品分类进行操作！");
            return;
        }

        confirmCategoryDelete(table.getSelections(), previewToken => {
            util.post("/admin/api/category/del", {list: data, preview_token: previewToken}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });

    $('.start').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个分类进行操作！");
            return;
        }
        confirmCategoryStatus(table.getSelections(), 1, () => {
            util.post("/admin/api/category/status", {list: data, status: 1}, res => {
                message.success("启用成功");
                table.refresh();
            });
        }, {desktopConfirm: true});
    });

    $('.stop').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个分类进行操作！");
            return;
        }
        confirmCategoryStatus(table.getSelections(), 0, () => {
            util.post("/admin/api/category/status", {list: data, status: 0}, res => {
                message.success("停用成功");
                table.refresh();
            });
        }, {desktopConfirm: true});
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create, .btn-app-del, .start, .stop').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeCategoryDestroy === destroy) delete window.__mdTradeCategoryDestroy;
    }

    window.__mdTradeCategoryDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);


}();
