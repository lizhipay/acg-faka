/**
 * 商品分类的公共操作：新增 / 修改弹窗、删除（先预览代价再确认）、启用 / 停用（级联确认）。
 * 分类管理页（category.js）与商品管理页左侧的分类树（category-tree.js）共用，两边行为保持一模一样。
 *
 * 用法：
 *   MdCategoryActions.openEditor(title, row, {done, isActive});
 *   MdCategoryActions.confirmDelete(rows, previewToken => {...}, {isActive});
 *   MdCategoryActions.confirmStatus(rows, status, done, {desktopConfirm, detailed, cancel});
 *     电脑版默认直接执行（desktopConfirm 时简短确认）；detailed 时电脑版也弹出说明连带范围的确认框（手机版总是弹）
 */
window.MdCategoryActions = (() => {
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const alive = options => typeof options?.isActive !== 'function' || options.isActive();

    const confirmDelete = (rows, done, options = {}) => {
        const selected = (Array.isArray(rows) ? rows : []).filter(Boolean);
        const ids = selected.map(row => Number(row.id)).filter(id => Number.isInteger(id) && id > 0);
        if (!ids.length) {
            message.error('没有可删除的分类');
            return;
        }
        const names = selected.slice(0, 4).map(row => escapeHtml(row.name || `ID ${row.id}`));
        const more = selected.length > names.length ? ` ${i18n('等')} ${selected.length} ${i18n('个分类')}` : '';
        util.post({
            url: '/admin/api/category/deleteImpact',
            data: {list: ids},
            done: res => {
                if (!alive(options)) return;
                const impact = res?.data || {};
                const n = v => escapeHtml(v ?? 0);
                const line = (label, value, unit, note) =>
                    `<div><b>${i18n(label)}</b>${n(value)} ${i18n(unit)}${note ? `<span style="opacity:.65;"> ${i18n(note)}</span>` : ''}</div>`;
                // 这些引用全部会被自动清理，不再是「阻止删除」的理由；弹窗只负责把代价说清楚
                const impactSummary = `<div style="text-align:left;line-height:1.8;">
                    <div><b>${i18n('所选分类：')}</b>${names.join('、') || i18n('当前所选分类')}${more}</div>
                    <div style="margin-top:10px;padding:10px 12px;border-radius:12px;background:rgba(127,127,127,.09);">
                        ${line('将删除分类：', impact.scope_count ?? impact.category_count, '个', (impact.descendant_count ?? 0) > 0 ? `（${i18n('含下级')} ${escapeHtml(impact.descendant_count)} ${i18n('个')}）` : '')}
                        ${line('连带删除商品：', impact.commodity_count, '个')}
                        ${line('连带删除订单：', impact.order_count, '笔')}
                        ${line('连带删除卡密：', impact.card_count, '张')}
                        ${line('连带删除优惠券：', impact.coupon_count, '张')}
                        ${line('自动解除商户分类映射：', impact.user_category_count, '条')}
                        ${line('自动清空网站默认分类引用：', impact.config_reference_count, '条')}
                    </div>`;
                const previewToken = String(impact.preview_token || '');
                if (!previewToken) {
                    message.error('服务器未返回有效的删除预览凭证，已阻止删除');
                    return;
                }
                Swal.fire({
                    title: selected.length > 1 ? `${i18n('确认删除')} ${selected.length} ${i18n('个所选分类')}` : i18n('确认删除分类'),
                    html: `${impactSummary}<div style="margin-top:10px;color:#d14343;">${i18n('分类连同其下级分类、分类内商品及这些商品的订单、工单、卡密、优惠券会被一并删除。预览凭证')} 3 ${i18n('分钟内有效，范围在此期间变化会要求重新预览；操作不可撤销。')}</div></div>`,
                    icon: 'warning',
                    showCancelButton: true,
                    cancelButtonText: i18n('取消'),
                    confirmButtonText: i18n('确认永久删除')
                }).then(result => {
                    if (result.isConfirmed === true || result.value === true) done(previewToken);
                });
            },
            error: res => message.error(res?.msg || i18n('无法计算删除影响，已阻止删除')),
            fail: () => message.error('网络异常，已阻止删除')
        });
    };

    const confirmStatus = (rows, status, done, options = {}) => {
        const selected = (Array.isArray(rows) ? rows : []).filter(Boolean);
        const enabling = Number(status) === 1;
        if (!mobileAdminEnabled() && !options.detailed) {
            if (options.desktopConfirm) message.ask(null, done); else done();
            return;
        }
        const names = selected.slice(0, 4).map(row => escapeHtml(row.name || `ID ${row.id}`));
        Swal.fire({
            title: enabling ? i18n('确认启用分类') : i18n('确认停用分类'),
            html: `<div style="text-align:left;line-height:1.8;">
                <div><b>${i18n('所选分类：')}</b>${names.join('、') || `${i18n('共')} ${selected.length} ${i18n('个分类')}`}</div>
                <div style="margin-top:10px;">${enabling
                    ? i18n('为保证层级完整，系统会同时启用所选分类尚未启用的上级分类。')
                    : i18n('系统会同时停用所选分类下的全部子分类，相关商品将不再通过这些分类展示。')}</div>
            </div>`,
            icon: enabling ? 'question' : 'warning',
            showCancelButton: true,
            cancelButtonText: i18n('取消'),
            confirmButtonText: enabling ? i18n('确认启用') : i18n('确认停用')
        }).then(result => {
            if (result.isConfirmed === true || result.value === true) done();
            else if (typeof options.cancel === 'function') options.cancel();
        });
    };

    const openEditor = (title, assign = {}, options = {}) => {
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
                            parent: true,
                            clearToZero: true
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
                    name: util.icon("fa-duotone fa-regular fa-user") + i18n(" 会员等级"),
                    form: [
                        {
                            name: "user",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="mcy-card"><table id="category-group-table"></table></div>`);

                                util.get("/admin/api/group/data", res => {
                                    if (!alive(options) || form.isDestroyed) return;
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
                if (alive(options) && typeof options.done === 'function') options.done();
            }
        });
    };

    return {confirmDelete, confirmStatus, openEditor, escapeHtml};
})();
