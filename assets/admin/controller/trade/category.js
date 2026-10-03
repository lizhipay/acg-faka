!function () {
    let table;
    const namespace = '.mdTradeCategoryController';
    let controllerActive = true;
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    // 新增 / 修改弹窗、删除预览、启停确认：公共实现在 category-actions.js（与商品管理页左侧的分类树共用）
    const isActive = () => controllerActive;
    const modal = (title, assign = {}) => MdCategoryActions.openEditor(title, assign, {isActive, done: () => table?.refresh()});

    if (typeof window.__mdTradeCategoryDestroy === 'function') window.__mdTradeCategoryDestroy();

    // 拖动排序：公共实现在 drag-sort.js（分类管理、商品管理共用，行为保持一模一样）。
    // 电脑版拖「排序」列旁的手柄；手机版卡片列表长按整张卡片拖动（手柄列在手机版里自动隐藏）
    const dragEnabled = Boolean(window.MdTableDragSort);
    let dragSort = null;

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
                    message.error(res?.msg || i18n('分类更新失败'));
                    refresh();
                },
                fail: () => {
                    message.error('网络异常，分类未更新');
                    refresh();
                }
            });
        };
        if (isStatus) {
            MdCategoryActions.confirmStatus(row ? [row] : [], Number(data.status), submit, {cancel: refresh});
            return;
        }
        submit();
    });
    table.setTree(3);
    table.onComplete(() => dragSort?.sync());
    if (dragEnabled) {
        dragSort = MdTableDragSort.attach({
            table,
            selector: '#category-table',
            namespace: namespace + 'Drag',
            url: '/admin/api/category/reorder',
            tree: true,
            isActive: () => controllerActive,
            hint: '按住拖动，调整同级分类的顺序',
            singleText: '这一层级下只有这一个分类，不需要排序',
            // 按名称搜索、按状态筛选时，列表里的同级分类不完整，拖了会把没显示的那几个的顺序写乱
            blockedReason: () => {
                if (String(table?.queryParams?.['search-name'] ?? '').trim() !== '') {
                    return '正在按名称搜索，列表不完整，请先清空搜索再拖动排序';
                }
                if (String(table?.getState?.()?.value ?? '') !== '') {
                    return '正在按状态筛选，列表不完整，请切到「全部」再拖动排序';
                }
                return '';
            }
        });
    }
    table.setColumns([
        {checkbox: true},
        {field: 'icon', title: '', type: "image", style: "border-radius:25%;", width: 28},
        {
            field: 'owner', title: '创建者', formatter: (_, __) => mdOwnerCell(_)
        },
        {
            field: 'name', title: '分类名称',
            formatter: (value, row) => {
                const ownerId = Number(row?.owner?.id ?? row?.owner ?? 0) || 0;
                return ownerId === 0 ? String(value ?? '') : escapeHtml(value);
            }
        }
        , ...(dragEnabled ? [MdTableDragSort.column()] : [])
        //排序：拖动左侧手柄，或直接改数字（越小越前）。分类树始终按真实顺序展示，所以不再提供表头升降序切换
        , {field: 'sort', title: '排序(越小越前)', type: "input", reload: true}
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
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + i18n("修改分类"), row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        MdCategoryActions.confirmDelete([row], previewToken => {
                            util.post('/admin/api/category/del', {list: [row.id], preview_token: previewToken}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        }, {isActive});
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

    //分类是树：分页会把父级不在同一页的子分类整行丢掉，本身也没有意义，全量展示
    table.disablePagination();
    table.render();


    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> ${i18n('添加分类')}`);
    });

    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品分类进行操作！"));
            return;
        }

        MdCategoryActions.confirmDelete(table.getSelections(), previewToken => {
            util.post("/admin/api/category/del", {list: data, preview_token: previewToken}, res => {
                message.success("删除成功")
                table.refresh();
            });
        }, {isActive});
    });

    $('.start').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个分类进行操作！"));
            return;
        }
        MdCategoryActions.confirmStatus(table.getSelections(), 1, () => {
            util.post("/admin/api/category/status", {list: data, status: 1}, res => {
                message.success("启用成功");
                table.refresh();
            });
        }, {desktopConfirm: true});
    });

    $('.stop').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个分类进行操作！"));
            return;
        }
        MdCategoryActions.confirmStatus(table.getSelections(), 0, () => {
            util.post("/admin/api/category/status", {list: data, status: 0}, res => {
                message.success("停用成功");
                table.refresh();
            });
        }, {desktopConfirm: true});
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        dragSort?.destroy();
        dragSort = null;
        $('.btn-app-create, .btn-app-del, .start, .stop').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeCategoryDestroy === destroy) delete window.__mdTradeCategoryDestroy;
    }

    window.__mdTradeCategoryDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);


}();
