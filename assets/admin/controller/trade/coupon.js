!function () {
    let table, _createForms = [], _createSearchs = [];
    const namespace = '.mdTradeCouponController';
    let controllerActive = true;
    let createSkuRevision = 0;
    let searchSkuRevision = 0;
    let createConfirmPending = false;
    let createPending = false;
    let deletePreviewPending = false;
    let exportPending = false;
    const exportRequests = new Set();
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const safeImageUrl = value => {
        const raw = String(value ?? '').trim();
        if (!raw) return '';
        try {
            const url = new URL(raw, window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (_) {
            return '';
        }
    };
    const safeDynamicKey = value => {
        const key = String(value ?? '');
        return key.length > 0 && key.length <= 64 && !/[<>"'`\\\u0000-\u001F\u007F]/u.test(key) ? key : '';
    };
    const ownerCell = item => {
        if (!item) {
            return '<div class="md-user-cell"><span class="md-user-cell__avatar md-user-cell__avatar--ph">'
                + '<i class="fa-duotone fa-regular fa-shop"></i></span>'
                + '<div class="md-user-cell__text"><span class="md-user-cell__name">主站</span>'
                + '<span class="md-user-cell__id">系统</span></div></div>';
        }
        const name = String(item.username ?? '');
        const id = String(item.id ?? '');
        const avatarUrl = safeImageUrl(item.avatar);
        const avatar = avatarUrl
            ? `<img src="${escapeHtml(avatarUrl)}" class="md-user-cell__avatar" alt="">`
            : `<span class="md-user-cell__avatar md-user-cell__avatar--ph">${escapeHtml((name.charAt(0) || '?').toUpperCase())}</span>`;
        return `<div class="md-user-cell">${avatar}<div class="md-user-cell__text"><span class="md-user-cell__name">${escapeHtml(name)}</span><span class="md-user-cell__id">${escapeHtml(id)}</span></div></div>`;
    };
    const formBody = payload => {
        const body = new URLSearchParams();
        Object.keys(payload || {}).forEach(key => {
            const value = payload[key];
            if (Array.isArray(value)) {
                value.forEach(item => body.append(`${key}[]`, item));
                return;
            }
            if (value !== undefined && value !== null) body.append(key, value);
        });
        return body.toString();
    };
    const postCouponExportRequest = async (url, payload) => {
        const request = new AbortController();
        exportRequests.add(request);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: formBody(payload),
                signal: request.signal
            });
            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                const json = await response.json();
                if (!response.ok || json.code !== 200) throw new Error(json.msg || '请求失败');
                return {json: json};
            }
            if (!response.ok) throw new Error('服务器无法完成优惠券导出');
            return {blob: await response.blob()};
        } finally {
            exportRequests.delete(request);
        }
    };
    const downloadCouponExport = async (payload, count) => {
        Loading.show();
        try {
            const result = await postCouponExportRequest('/admin/api/coupon/export', {
                ...payload,
                expected_count: count
            });
            if (!result.blob) throw new Error('服务器没有返回导出文件');
            const url = URL.createObjectURL(result.blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `优惠券导出-${count}-${new Date().toISOString().slice(0, 10)}.txt`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            if (controllerActive) message.success(`已安全导出 ${count} 张优惠券`);
        } catch (error) {
            if (controllerActive) message.alert(error.message || '导出失败', 'error');
        } finally {
            Loading.hide();
        }
    };
    const confirmCouponDelete = (list, rows, done) => {
        if (deletePreviewPending || !controllerActive) return;
        deletePreviewPending = true;
        util.post({
            url: '/admin/api/coupon/deleteImpact',
            data: {list: list},
            done: res => {
                deletePreviewPending = false;
                if (!controllerActive) return;
                const impact = res.data || {};
                const couponCount = Number(impact.coupon_count || list.length);
                if (impact.can_delete !== true) {
                    message.alert(
                        `所选 ${couponCount} 张优惠券中，包含 ${Number(impact.used_count || 0)} 张已使用优惠券、${Number(impact.trade_no_count || 0)} 张带最后使用订单号的优惠券，另有 ${Number(impact.order_reference_count || 0)} 笔订单引用。系统已整批阻止删除，以保护历史记录。`,
                        'warning'
                    );
                    return;
                }
                const preview = (rows || [])
                    .map(row => row && row.code)
                    .filter(code => code !== undefined && code !== null && String(code) !== '')
                    .slice(0, 4)
                    .map(code => escapeHtml(code));
                const previewText = preview.length > 0
                    ? `<br><br>券码预览：${preview.join('、')}${couponCount > preview.length ? ' 等' : ''}`
                    : '';
                message.ask(
                    `将永久删除 <b>${couponCount} 张未使用优惠券</b>（其中锁定 ${Number(impact.locked_count || 0)} 张）。${previewText}<br><br>删除后无法恢复，确认继续吗？`,
                    done,
                    '确认永久删除优惠券',
                    '确认删除'
                );
            },
            error: res => {
                deletePreviewPending = false;
                if (controllerActive) message.error(res?.msg || '无法计算删除影响，已阻止删除');
            },
            fail: () => {
                deletePreviewPending = false;
                if (controllerActive) message.error('网络异常，无法预览删除影响，已阻止删除');
            }
        });
    };
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const controllerLayers = new Set();
    if (typeof window.__mdTradeCouponDestroy === 'function') window.__mdTradeCouponDestroy();
    const openControllerLayer = options => {
        const originalEnd = options.end;
        let index;
        index = layer.open({
            ...options,
            end: function () {
                controllerLayers.delete(index);
                if (typeof originalEnd === 'function') return originalEnd.apply(this, arguments);
            }
        });
        if (controllerActive) controllerLayers.add(index); else layer.close(index);
        return index;
    };

    // 生成优惠券成功后的结果弹窗(对标查看卡密 .md-secret):成功/失败徽章 + 券码块 + 复制/下载
    const openCouponResult = (data) => {
        const codes = data.code || '';
        const okN = Number(data.success || 0);
        const failN = Number(data.error || 0);
        const mobile = mobileAdminEnabled();
        const meta = `<span class="a-badge a-badge-success">成功 ${okN} 张</span>`
            + (failN > 0 ? `<span class="a-badge a-badge-danger">失败 ${failN} 张</span>` : '');
        openControllerLayer({
            type: 1,
            title: `${util.icon("fa-duotone fa-regular fa-circle-check")} 优惠券生成成功`,
            area: mobile ? ["100%", "100%"] : '480px',
            skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task md-coupon-result-layer' : 'md-coupon-result-layer',
            maxmin: false,
            resize: !mobile,
            move: !mobile,
            shadeClose: true,
            content: `<div class="md-secret"><div class="md-secret__meta">${meta}</div><div class="md-secret__code">${escapeHtml(codes)}</div><div class="md-secret__bar"><button type="button" class="md-secret__btn" data-act="copy">${util.icon("fa-duotone fa-regular fa-copy")} 复制</button><button type="button" class="md-secret__btn md-secret__btn--primary" data-act="download">${util.icon("fa-duotone fa-regular fa-download")} 下载</button></div></div>`,
            success: (layero) => {
                layero.find('[data-act="copy"]').on('click', () => {
                    util.copyTextToClipboard(codes, () => message.success('优惠券已复制'));
                });
                layero.find('[data-act="download"]').on('click', () => {
                    const blob = new Blob([codes], {type: 'text/plain;charset=utf-8'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `优惠券_${okN}张.txt`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                });
            }
        });
    };

    const createCoupon = () => {
        if (!controllerActive || createPending || createConfirmPending) return;
        _createForms = [];
        createSkuRevision += 1;
        const mobile = mobileAdminEnabled();
        const submit = mobile ? ((data, popupIndex) => {
            if (createPending || createConfirmPending || !controllerActive) return;
            const num = Number(data.num);
            const money = Number(data.money);
            const life = Number(data.life);
            const mode = Number(data.mode);
            const prefix = String(data.prefix || '').trim().toUpperCase();
            if (!Number.isInteger(num) || num < 1 || num > 1000) {
                message.error('生成数量必须是 1 到 1000 之间的整数');
                return;
            }
            if (!Number.isFinite(money) || money <= 0 || (mode === 1 && money > 1)) {
                message.error(mode === 1 ? '百分比抵扣必须大于 0 且小于或等于 1' : '请输入大于 0 的金额');
                return;
            }
            if (!Number.isInteger(life) || life < 1 || life > 1000000) {
                message.error('可用次数必须是 1 到 1000000 之间的整数');
                return;
            }
            if (prefix && !/^[A-Z0-9_-]{1,16}$/.test(prefix)) {
                message.error('优惠券前缀仅支持 1 到 16 位字母、数字、下划线或短横线');
                return;
            }
            const scope = Number(data.commodity_id) > 0
                ? `指定商品 ID ${Number(data.commodity_id)}`
                : (Number(data.category_id) > 0 ? `指定分类 ID ${Number(data.category_id)}` : '全场通用');
            createConfirmPending = true;
            Swal.fire({
                title: `确认生成 ${num} 张优惠券`,
                html: `<div style="text-align:left;line-height:1.8;">
                    <div><b>抵扣范围：</b>${escapeHtml(scope)}</div>
                    <div><b>抵扣方式：</b>${mode === 1 ? `${money * 10} 折` : `¥${money}`}</div>
                    <div><b>每张可用：</b>${life} 次</div>
                    <div><b>有效期：</b>${escapeHtml(data.expire_time || '永久有效')}</div>
                    <div><b>券码前缀：</b>${escapeHtml(prefix || '无前缀')}</div>
                    <div style="margin-top:10px;color:#9a6700;">确认后会立即创建券码；请在结果页复制或下载并妥善保管。</div>
                </div>`,
                icon: 'question',
                showCancelButton: true,
                cancelButtonText: '返回修改',
                confirmButtonText: '确认生成'
            }).then(result => {
                createConfirmPending = false;
                if (!(result.isConfirmed === true || result.value === true) || !controllerActive || createPending) return;
                createPending = true;
                util.post({
                    url: '/admin/api/coupon/save',
                    data: data,
                    done: res => {
                        createPending = false;
                        if (!controllerActive) return;
                        layer.close(popupIndex);
                        table.refresh();
                        openCouponResult(res.data || {});
                    },
                    error: res => {
                        createPending = false;
                        if (controllerActive) message.alert(res?.msg || '优惠券生成失败', 'error');
                    },
                    fail: () => {
                        createPending = false;
                        if (controllerActive) message.error('网络异常，优惠券未生成');
                    }
                });
            }).catch(() => { createConfirmPending = false; });
        }) : '/admin/api/coupon/save';
        component.popup({
            submit: submit,
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-ticket") + " 生成优惠券",
                    form: [
                        {
                            title: "商品分类",
                            name: "category_id",
                            type: "select",
                            dict: "category->owner=0,id,name",
                            placeholder: "对商品分类下的所有商品进行折扣，不选则全场",
                            search: true
                        },
                        {
                            title: "选择商品",
                            name: "commodity_id",
                            type: "select",
                            dict: "commodity->owner=0 and delivery_way=0 and (shared_id is null or shared_id=0),id,name",
                            placeholder: "请选择商品",
                            search: true,
                            change: (_, __) => {
                                const revision = ++createSkuRevision;
                                _.setRadio("race_get_mode", 0, true);
                                _.setInput("race_input", "");
                                _.hide("race");
                                _.hide("race_input");
                                _.clearComponent("race");
                                _.hide("race_get_mode");
                                _.setSelected("category_id", "");
                                _createForms.forEach(k => _.removeForm(k));
                                _createForms.length = 0;
                                if (__ > 0) {
                                    _.hide("category_id");
                                    util.get(`/admin/api/card/sku?commodityId=${__}`, data => {
                                        if (!controllerActive || _.isDestroyed || revision !== createSkuRevision) return;
                                        if (!util.isEmptyOrNotJson(data?.category)) {
                                            let i = 0;
                                            for (const cKey in data.category) {
                                                _.addRadio("race", cKey, cKey, i === 0);
                                                i++;
                                            }
                                            _.show("race");
                                            _.show(`race_get_mode`);
                                        }
                                        if (!util.isEmptyOrNotJson(data?.sku)) {
                                            for (const rawKey in data.sku) {
                                                const sKey = safeDynamicKey(rawKey);
                                                if (!sKey) continue;
                                                let dict = [];
                                                for (const sk in data.sku[rawKey]) {
                                                    dict.push({id: sk, name: sk});
                                                }
                                                _.createForm({
                                                    title: escapeHtml(sKey),
                                                    name: `sku.${sKey}`,
                                                    type: "radio",
                                                    dict: dict
                                                }, "race", "after");
                                                _createForms.push(`sku-${sKey}`);
                                            }
                                        }
                                    });
                                } else {
                                    _.show("category_id");
                                }
                            }
                        },
                        {
                            title: "种类获取方法",
                            name: "race_get_mode",
                            type: "radio",
                            dict: [{id: 0, name: "自动获取"}, {id: 1, name: "手动填写(如独立设置了会员等级)"}],
                            hide: true,
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.hide("race");
                                    _.show("race_input");
                                } else {
                                    _.show("race");
                                    _.hide("race_input");
                                }
                            }
                        },
                        {
                            title: "商品种类",
                            name: "race_input",
                            type: "input",
                            placeholder: "请填写商品种类",
                            hide: true
                        },
                        {
                            title: "商品种类",
                            name: "race",
                            type: "radio",
                            placeholder: "商品类别，一般你用不着，而且不懂不要乱填哦，想用请查看说明文档",
                            hide: true
                        },
                        {
                            title: "备注信息",
                            name: "note",
                            type: "input",
                            placeholder: "备注信息(可空)，方便查询某次生成的优惠券"
                        },
                        {
                            title: "抵扣模式",
                            name: "mode",
                            type: "radio",
                            dict: [
                                {
                                    id: 0,
                                    name: "金额抵扣"
                                },
                                {
                                    id: 1,
                                    name: "百分比抵扣(按照商品价格)"
                                }
                            ],
                            default: 0
                        },
                        {
                            title: "面值(金额/百分比)",
                            name: "money",
                            type: "number",
                            placeholder: "金额或者百分比(小数代替范围：0~1)"
                        },
                        {
                            title: "过期时间",
                            name: "expire_time",
                            type: "date",
                            placeholder: "过了该时间优惠券自动失效，不填代表永不过期"
                        },
                        {
                            title: "可用次数",
                            name: "life",
                            type: "number",
                            placeholder: "该优惠券可以使用次数",
                            default: "1"
                        },
                        {
                            title: "券码前缀",
                            name: "prefix",
                            type: "input",
                            placeholder: "请输入优惠券代码前缀，可留空",
                            default: "ACG",
                            regex: {value: "^[A-Za-z0-9_-]{1,16}$", message: "前缀仅支持 1 到 16 位字母、数字、下划线或短横线"}
                        },
                        {title: "生成数量", name: "num", type: "number", placeholder: "每次最多生成 1000 张", default: 1, required: true}
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: (res) => {
                if (!controllerActive) return;
                table.refresh();
                openCouponResult(res?.data || {});
            }
        });
    }

    table = new Table("/admin/api/coupon/data", "#coupon-table");
    table.setFloatMessage([
        {field: 'create_time', title: '创建时间', formatter: value => escapeHtml(value || '-')}
        , {
            field: 'service_time', title: '使用时间', formatter: value => escapeHtml(value || '-')
        }
        , {
            field: 'trade_no', title: '订单号(最后使用)', formatter: value => escapeHtml(value || '-')
        }
    ]);
    table.setColumns([
        {checkbox: true},
        {
            field: 'code', title: '券码', formatter: value => escapeHtml(value)
        }
        , {
            field: 'mode', title: '抵扣模式', dict: "_coupon_mode"
        }
        , {
            field: 'money', title: '面值', formatter: (_, __) => {
                const money = Number(_);
                if (__.mode == 1) {
                    return format.badge(escapeHtml((Number.isFinite(money) ? money * 10 : 0) + "折"), "a-badge-success");
                }
                return format.badge(escapeHtml(`￥${Number.isFinite(money) ? money : 0}`), "a-badge-primary");
            }
        }
        , {
            field: 'commodity', title: '抵扣范围', formatter: function (val, item) {
                if (!item.commodity && !item.category) {
                    return '<span class="text-danger">全场通用</span>';
                }

                if (!item.commodity && item.category) {
                    return '<span class="text-primary">[商品分类] -&gt; </span>' + escapeHtml(item.category.name);
                }

                let d = format.badge(escapeHtml(item.commodity.name), "a-badge-success");

                if (item.race) {
                    d += format.badge(escapeHtml(`种类:${item.race}`), "a-badge-info");
                }

                if (!util.isEmptyOrNotJson(item.sku)) {
                    for (const skuKey in item.sku) {
                        d += format.badge(escapeHtml(`${skuKey}:${item.sku[skuKey]}`), "a-badge-info");
                    }
                }

                return d;
            }
        }

        , {
            field: 'expire_time', title: '到期时间', formatter: function (val, item) {
                if (!item.expire_time) {
                    return format.badge("永久", "a-badge-success");
                }
                return format.badge(escapeHtml(item.expire_time), "a-badge-warning");
            }
        }
        , {field: 'life', title: '剩余次数', formatter: value => escapeHtml(value)}
        , {field: 'use_life', title: '已使用次数', formatter: value => escapeHtml(value)}
        , {field: 'note', title: '备注信息', formatter: value => escapeHtml(value)}
        , {
            field: 'status', title: '状态', dict: "_coupon_status"
        }
        , {
            field: 'owner', title: '所属者', formatter: value => ownerCell(value)
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole',
                    class: "text-primary",
                    show: _ => _.status == 0,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/coupon/lock', {list: [row.id]}, res => {
                            message.success(`【${escapeHtml(row.code)}】已锁定，本次实际锁定 ${Number(res.data?.count || 0)} 张`);
                            table.refresh();
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-lock-keyhole-open',
                    class: "text-success",
                    show: _ => _.status == 2,
                    click: (event, value, row, index) => {
                        util.post('/admin/api/coupon/unlock', {list: [row.id]}, res => {
                            message.success(`【${escapeHtml(row.code)}】已解锁，本次实际解锁 ${Number(res.data?.count || 0)} 张`);
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        confirmCouponDelete([row.id], [row], () => {
                            util.post('/admin/api/coupon/del', {list: [row.id]}, res => {
                                message.success(`已删除 ${Number(res.data?.count || 0)} 张优惠券`);
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);
    table.setSearch([
        {title: "券码", name: "equal-code", type: "input"},
        {title: "备注信息", name: "equal-note", type: "input"},
        {title: "券面值", name: "equal-money", type: "input"},
        {title: "会员ID，0=系统", name: "equal-owner", type: "input"},
        {title: "商品分类", name: "equal-category_id", type: "select", dict: "category,id,name", search: true},
        {
            title: "查询商品",
            name: "equal-commodity_id",
            type: "select",
            dict: "commodity,id,name",
            change: (_, __) => {
                const revision = ++searchSkuRevision;
                _.hide("equal-race");
                _.selectClearOption("equal-race");
                _createSearchs.forEach(k => _.removeSearch(k));
                _createSearchs.length = 0;
                if (__ > 0) {
                    util.get(`/admin/api/card/sku?commodityId=${__}`, data => {
                        if (!controllerActive || revision !== searchSkuRevision) return;
                        if (!util.isEmptyOrNotJson(data?.category)) {
                            let i = 0;
                            for (const cKey in data.category) {
                                _.selectAddOption("equal-race", cKey, cKey);
                                i++;
                            }
                            _.show("equal-race");
                        }
                        if (!util.isEmptyOrNotJson(data?.sku)) {
                            for (const rawKey in data.sku) {
                                const sKey = safeDynamicKey(rawKey);
                                if (!sKey) continue;
                                let dict = [];
                                for (const sk in data.sku[rawKey]) {
                                    dict.push({id: sk, name: sk});
                                }
                                _.createSearch({
                                    title: escapeHtml(sKey),
                                    name: `equal-sku-${sKey}`,
                                    type: "select",
                                    dict: dict
                                }, "equal-race", "after");
                                _createSearchs.push(`equal-sku-${sKey}`);
                            }
                        }
                    });
                }
            },
            search: true
        },
        {title: "商品种类", name: "equal-race", type: "select", hide: true},
    ]);
    table.setState("status", "_coupon_status");
    table.render();

    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        createCoupon();
    });

    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个优惠券再进行操作！");
            return;
        }
        confirmCouponDelete(data, table.getSelections(), () => {
            util.post("/admin/api/coupon/del", {list: data}, res => {
                message.success(`已删除 ${Number(res.data?.count || 0)} 张优惠券`)
                table.refresh();
            });
        });
    });
    $('.btn-app-lock').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个优惠券进行操作！");
            return;
        }

        message.ask(`确认锁定选中的 <b>${data.length} 张优惠券</b>吗？锁定后未使用优惠券将暂时不可用。`, () => {
            util.post("/admin/api/coupon/lock", {list: data}, res => {
                message.success(`已锁定 ${Number(res.data?.count || 0)} 张优惠券`)
                table.refresh();
            });
        }, '确认锁定优惠券', '确认锁定');
    });

    $('.btn-app-unlock').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg("请至少勾选1个优惠券进行操作！");
            return;
        }
        message.ask(`确认解锁选中的 <b>${data.length} 张优惠券</b>吗？解锁后优惠券将恢复可用。`, () => {
            util.post("/admin/api/coupon/unlock", {list: data}, res => {
                message.success(`已解锁 ${Number(res.data?.count || 0)} 张优惠券`)
                table.refresh();
            });
        }, '确认解锁优惠券', '确认解锁');
    });

    $('.btn-app-export').off(namespace).on('click' + namespace, async function () {
        if (exportPending || !controllerActive) return;
        exportPending = true;
        const $button = $(this);
        $button.prop('disabled', true).attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        const payload = Object.assign({}, table.getSearchData());
        if (Object.prototype.hasOwnProperty.call(payload, 'equal-code')) {
            payload.coupon_code_secret = payload['equal-code'];
            delete payload['equal-code'];
        }
        const state = table.getState();
        if (state.field && String(state.value ?? '') !== '') {
            payload[`equal-${state.field}`] = state.value;
        }

        Loading.show();
        try {
            const preview = await postCouponExportRequest('/admin/api/coupon/exportImpact', payload);
            if (!controllerActive) return;
            const impact = preview.json?.data || {};
            const count = Number(impact.count || impact.total || 0);
            if (!Number.isInteger(count) || count < 1) throw new Error('服务器没有返回有效的导出数量');
            const scope = impact.has_filter
                ? '当前筛选条件'
                : '<span style="color:#d32f2f;font-weight:700">未设置筛选条件，将导出全部优惠券</span>';
            const detail = `${scope}，精确命中 <b>${count} 张优惠券</b>。<br><br>未使用 ${Number(impact.normal_count || 0)} 张、已使用 ${Number(impact.used_count || 0)} 张、锁定 ${Number(impact.locked_count || 0)} 张。<br><br>导出文件包含敏感券码；筛选和券码均使用 POST 传输，不会写入浏览器地址或历史记录。单次最多 ${Number(impact.max_count || 5000)} 张。`;
            message.ask(
                detail,
                () => downloadCouponExport(payload, count),
                '确认导出敏感优惠券',
                '确认下载'
            );
        } catch (error) {
            if (controllerActive) message.alert(error.message || '无法预览导出范围', 'error');
        } finally {
            exportPending = false;
            if (controllerActive && $button.get(0)?.isConnected) {
                $button.prop('disabled', false).removeAttr('aria-busy aria-disabled');
            }
            Loading.hide();
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        createSkuRevision += 1;
        searchSkuRevision += 1;
        createConfirmPending = false;
        createPending = false;
        deletePreviewPending = false;
        exportPending = false;
        $('.btn-app-create, .btn-app-del, .btn-app-lock, .btn-app-unlock, .btn-app-export').off(namespace).prop('disabled', false).removeAttr('aria-busy aria-disabled');
        $(document).off('pjax:beforeReplace' + namespace);
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        exportRequests.forEach(request => request.abort());
        exportRequests.clear();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeCouponDestroy === destroy) delete window.__mdTradeCouponDestroy;
    }

    window.__mdTradeCouponDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
