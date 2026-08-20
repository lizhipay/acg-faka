!function () {
    const namespace = '.mdPayApiController';
    let table, plugins = [], handles = [];
    let deletePreviewPending = false, deletePending = false;
    let controllerActive = true, pluginRequest = null;
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    const safeImageUrl = (value, fallback = '/favicon.ico') => {
        const source = String(value ?? '').trim();
        if (!source) return fallback;
        try {
            const resolved = new URL(source, window.location.href);
            return ['http:', 'https:'].includes(resolved.protocol) ? resolved.href : fallback;
        } catch (error) {
            return fallback;
        }
    };

    //只放行 http/https，挡住 javascript: 这类协议——地址是插件返回的，不能直接塞进 href
    const normalizeHttpUrl = value => {
        const source = String(value ?? '').trim();
        if (!source) return '';
        try {
            const resolved = new URL(source, window.location.href);
            return ['http:', 'https:'].includes(resolved.protocol) ? resolved.href : '';
        } catch (error) {
            return '';
        }
    };

    //本控制器开出去的 layer，切页时要一并关掉，别留在 DOM 里
    const controllerLayers = new Set();
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

    if (typeof window.__mdPayApiDestroy === 'function') window.__mdPayApiDestroy();

    const deletePayments = list => {
        if (deletePending) return;
        deletePending = true;
        util.post({
            url: '/admin/api/pay/del',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.success(res?.msg || i18n('操作完成'));
                table.refresh();
            },
            error: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.error(res?.msg || i18n('支付接口删除已被阻止'));
            },
            fail: () => {
                if (!controllerActive) return;
                deletePending = false;
                message.error('网络异常，未执行删除');
            }
        });
    };

    const restorePayments = list => {
        if (deletePending) return;
        deletePending = true;
        util.post({
            url: '/admin/api/pay/restore',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.success(res?.msg || i18n('已恢复'));
                table.refresh();
            },
            error: res => {
                if (!controllerActive) return;
                deletePending = false;
                message.error(res?.msg || i18n('恢复失败'));
            },
            fail: () => {
                if (!controllerActive) return;
                deletePending = false;
                message.error('网络异常，未执行恢复');
            }
        });
    };

    const confirmPayDelete = list => {
        if (deletePreviewPending || deletePending) return;
        deletePreviewPending = true;
        util.post({
            url: '/admin/api/pay/deleteImpact',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                deletePreviewPending = false;
                const impact = res?.data || {};
                const count = Number(impact.payment_count || 0);
                const names = Array.isArray(impact.names) && impact.names.length
                    ? impact.names.map(escapeHtml).join(i18n('、'))
                    : i18n('所选支付接口');
                const more = count > Number(impact.names?.length || 0) ? ` ${i18n('等')} ${count} ${i18n('个接口')}` : '';
                const orderDetail = `${i18n('商品订单')} ${Number(impact.order_count || 0)} ${i18n('笔（已支付')} ${Number(impact.paid_order_count || 0)}、${i18n('未支付')} ${Number(impact.pending_order_count || 0)}）`;
                const rechargeDetail = `${i18n('充值订单')} ${Number(impact.recharge_count || 0)} ${i18n('笔（已支付')} ${Number(impact.paid_recharge_count || 0)}、${i18n('未支付')} ${Number(impact.pending_recharge_count || 0)}）`;
                const nameLine = (arr, total) => {
                    const shown = (Array.isArray(arr) ? arr : []).map(escapeHtml).join(i18n('、'));
                    return shown + (total > (arr?.length || 0) ? ` ${i18n('等')} ${total} ${i18n('个')}` : '');
                };

                if (impact.can_proceed !== true) {
                    const archivedOnly = Number(impact.already_archived_count || 0) > 0
                        && Number(impact.missing_count || 0) === 0
                        && Number(impact.built_in_count || 0) === 0
                        && Number(impact.commodity_enabled_count || 0) === 0
                        && Number(impact.recharge_enabled_count || 0) === 0;
                    message.alert(
                        `<div style="text-align:left;line-height:1.8;">
                            <div><b>${i18n('所选接口：')}</b>${names}${more}</div>
                            <div style="margin-top:10px;">${orderDetail}<br>${rechargeDetail}</div>
                            <div>${i18n('内置接口')} ${Number(impact.built_in_count || 0)} ${i18n('个；已失效选项')} ${Number(impact.missing_count || 0)} ${i18n('个；已归档')} ${Number(impact.already_archived_count || 0)} ${i18n('个。')}</div>
                            <div>${i18n('仍启用商品下单')} ${Number(impact.commodity_enabled_count || 0)} ${i18n('个；仍启用余额充值')} ${Number(impact.recharge_enabled_count || 0)} ${i18n('个。')}</div>
                            <div class="mt-2 text-danger">${archivedOnly ? i18n('所选接口均已归档，无需重复操作。') : i18n('已阻止操作：内置接口不可移除；仍在启用中的接口请先停用。')}</div>
                        </div>`,
                        'warning'
                    );
                    return;
                }

                const deleteCount = Number(impact.delete_count || 0);
                const archiveCount = Number(impact.archive_count || 0);
                const sections = [];
                if (deleteCount > 0) {
                    sections.push(`<div><b class="text-danger">${i18n('将永久删除')} ${deleteCount} ${i18n('个（无历史引用）：')}</b>${nameLine(impact.delete_names, deleteCount)}</div>`);
                }
                if (archiveCount > 0) {
                    sections.push(`<div><b class="text-warning">${i18n('将转为归档')} ${archiveCount} ${i18n('个（有历史订单/充值引用）：')}</b>${nameLine(impact.archive_names, archiveCount)}</div>`);
                }
                if (Number(impact.already_archived_count || 0) > 0) {
                    sections.push(`<div>${i18n('已归档（跳过）')} ${Number(impact.already_archived_count)} ${i18n('个。')}</div>`);
                }

                message.ask(
                    `<div style="text-align:left;line-height:1.8;">
                        ${sections.join('')}
                        <div style="margin-top:10px;">${orderDetail}<br>${rechargeDetail}</div>
                        <div class="mt-2 text-muted">${i18n('归档的接口不再出现在列表和前台，历史订单显示不受影响，可在「已归档」筛选中随时恢复；永久删除无法恢复。')}</div>
                    </div>`,
                    () => deletePayments(list),
                    i18n('确认移除支付接口？'),
                    i18n('确认移除')
                );
            },
            error: res => {
                if (!controllerActive) return;
                deletePreviewPending = false;
                message.error(res?.msg || i18n('无法计算删除影响，已阻止删除'));
            },
            fail: () => {
                if (!controllerActive) return;
                deletePreviewPending = false;
                message.error('网络异常，无法预览删除影响，已阻止删除');
            }
        });
    };

    const loadPlugins = async () => {
        const layIndex = layer.load(1, {
            shade: [0.3, 'var(--md-surface)']
        });

        try {
            pluginRequest = $.ajax({
                type: 'post',
                url: '/admin/api/pay/getPlugins',
                async: true,
                dataType: 'json'
            });
            const res = await pluginRequest;
            if (!controllerActive) return false;
            if (res?.code !== 200) {
                throw new Error(res?.msg || i18n('支付插件加载失败'));
            }

            const list = Array.isArray(res?.data?.list) ? res.data.list : [];
            plugins = [];
            list.forEach(item => {
                if (!item || item.id == null) return;
                plugins[item.id] = item;
            });
            handles = list
                .filter(item => item && item.id != null)
                .map(item => ({
                    id: item.id,
                    name: item?.info?.name || item?.name || `${i18n('支付插件')} ${item.id}`
                }));
        } catch (error) {
            if (!controllerActive || error?.statusText === 'abort') return false;
            plugins = [];
            handles = [];
            message.error(error?.message || i18n('支付插件加载失败，请刷新后重试'));
        } finally {
            pluginRequest = null;
            layer.close(layIndex);
        }
        return controllerActive;
    };

    //「自定义Code」这一项在 radio 里的占位值。用户真填了内容之后，
    //这个 radio 的 value 会被就地改写成填的内容，所以提交出去的还是 code 字段本身。
    const CUSTOM_CODE = '__custom__';

    //把「自定义Code」输入框里的内容，就地写进 code 单选框的 value。
    //这样提交时序列化到的还是 code 字段本身，不需要额外字段、也不用接管提交流程。
    //自定义那一项永远是最后加进去的，所以取 :last 是确定的。
    const syncCustomCode = unique => {
        const $root = $('.' + unique);
        const $input = $root.find('[data-code-custom]');
        const $radio = $root.find('input[name="code"]').last();
        if (!$input.length || !$radio.length) return;
        const text = String($input.val() ?? '').trim();
        //留空时保留占位值，服务端会以"自定义支付代码格式不对"回绝，比提交个空串清楚
        $radio.val(text || CUSTOM_CODE);
    };

    //保存的 code 不在插件声明的 options 里 = 站长当初自己填的
    const isCustomCode = (handle, code) => {
        const text = String(code ?? '');
        if (text === '') return false;
        const options = plugins[handle]?.info?.options || {};
        return !Object.prototype.hasOwnProperty.call(options, text);
    };

    //某插件的配置档列表。getPlugins 里已经带回来了，不用再发一次请求。
    const pluginConfigs = function (handle) {
        const list = plugins[handle]?.configs;
        return Array.isArray(list) ? list : [];
    };

    //列表里展示这一行用的是哪套配置。配置档被删掉时要看得出来，不能静默显示成空白。
    const getConfigName = function (handle, configId) {
        const list = pluginConfigs(handle);
        if (list.length === 0) {
            return '-';
        }
        const hit = list.find(item => item.id == configId);
        if (!hit) {
            return `<span class="a-badge a-badge-danger">${escapeHtml(i18n('已失效'))}</span>`;
        }
        return escapeHtml(hit.name);
    };

    let getType = function (handle, code) {
        if (handle == null) {
            return '-';
        }

        if (!plugins[handle]) {
            return '-';
        }

        return escapeHtml(plugins[handle]?.info?.options?.[code] ?? '-');
    }

    let getPluginName = function (handle) {
        if (handle == null) {
            return '-';
        }

        if (!plugins[handle]) {
            return '-';
        }

        const icon = escapeHtml(safeImageUrl(plugins[handle]?.icon));
        const name = escapeHtml(plugins[handle]?.info?.name ?? '');
        return `<div class="md-plugin"><img src="${icon}" class="md-plugin__icon" alt=""><span class="md-plugin__name">${name}</span></div>`;
    }


    //把弹窗高度贴合内容，避免出现内部滚动条
    const fitLayerToContent = (layero, index) => {
        const $lay = $(layero);
        const $content = $lay.find('.layui-layer-content').first();
        const $panel = $content.find('.md-paytest').first();
        if (!$content.length || !$panel.length) return;

        const titleHeight = $lay.find('.layui-layer-title').outerHeight() || 0;
        //先放开限制再量，才量得到内容真实高度
        $content.css({height: 'auto', maxHeight: 'none', overflowY: 'visible'});
        //多给 2px：内容区高度和 scrollHeight 之间常有舍入误差，差一点就会冒出一条发丝滚动条
        const needed = $content[0].scrollHeight + 2;
        //留点余量给标题栏和上下呼吸空间
        const room = window.innerHeight - titleHeight - 40;

        if (needed <= room) {
            $content.css({height: 'auto', maxHeight: 'none', overflowY: 'visible'});
            layer.style(index, {height: (needed + titleHeight) + 'px'});
        } else {
            //真的比屏幕还高就只能滚，但至少把可用高度吃满
            $content.css({height: room + 'px', maxHeight: room + 'px', overflowY: 'auto'});
            layer.style(index, {height: (room + titleHeight) + 'px'});
        }

        //改完高度重新居中
        const top = Math.max(8, (window.innerHeight - ($lay.outerHeight() || 0)) / 2 + $(window).scrollTop());
        $lay.css('top', top + 'px');
    };

    //拨测结果：把插件返回的支付信息摊开给站长看。能扫码就出二维码，扫一下就知道网关认不认。
    const showTestResult = (row, data) => {
        const typeText = {2: i18n('跳转支付页'), 3: i18n('站内收银台'), 4: i18n('表单提交')}[Number(data?.type)] || `type=${escapeHtml(String(data?.type))}`;
        const url = String(data?.url || '');
        const safeUrl = normalizeHttpUrl(url);
        const optionKeys = Object.keys(data?.option || {});

        const urlBlock = url
            ? `<div class="md-paytest__row">
                   <span class="md-paytest__k">${i18n('支付地址')}</span>
                   <span class="md-paytest__v"><code class="md-paytest__url">${escapeHtml(url)}</code></span>
               </div>`
            : `<div class="md-paytest__row">
                   <span class="md-paytest__k">${i18n('支付地址')}</span>
                   <span class="md-paytest__v text-muted">${i18n('插件没有返回地址（站内收银台/表单提交类型属正常）')}</span>
               </div>`;

        const html = `<div class="md-paytest">
            <div class="md-paytest__ok"><i class="fa-duotone fa-regular fa-circle-check"></i>${i18n('下单成功，网关已受理')}</div>
            <div class="md-paytest__row"><span class="md-paytest__k">${i18n('支付接口')}</span><span class="md-paytest__v">${escapeHtml(String(data?.pay_name || row?.name || ''))}</span></div>
            <div class="md-paytest__row"><span class="md-paytest__k">${i18n('订单号')}</span><span class="md-paytest__v"><code>${escapeHtml(String(data?.trade_no || ''))}</code></span></div>
            <div class="md-paytest__row"><span class="md-paytest__k">${i18n('金额')}</span><span class="md-paytest__v">￥${escapeHtml(String(data?.amount || ''))}</span></div>
            <div class="md-paytest__row"><span class="md-paytest__k">${i18n('返回类型')}</span><span class="md-paytest__v">${typeText}</span></div>
            ${urlBlock}
            <div class="md-paytest__row">
                <span class="md-paytest__k">${i18n('回调地址')}</span>
                <span class="md-paytest__v"><code class="md-paytest__url">${escapeHtml(String(data?.callback_url || ''))}</code></span>
            </div>
            ${optionKeys.length ? `<div class="md-paytest__row"><span class="md-paytest__k">${i18n('附加参数')}</span><span class="md-paytest__v">${optionKeys.map(escapeHtml).join(i18n('、'))}</span></div>` : ''}
            ${safeUrl ? `<div class="md-paytest__qr"><div class="md-paytest__qrbox" id="md-paytest-qr"></div></div>` : ''}
            <div class="md-paytest__state" id="md-paytest-state" data-state="waiting">
                <i class="fa-duotone fa-regular fa-spinner-third md-paytest__spin"></i>
                <span class="md-paytest__state-text">${i18n('等待支付中…付款后这里会实时变成已支付')}</span>
            </div>
            ${data?.ip_whitelist ? `<div class="md-paytest__warn md-paytest__warn--danger"><i class="fa-duotone fa-regular fa-shield-halved"></i>${i18n('回调IP白名单已开启：网关的回调会先经过白名单校验，来源IP不在名单内会被 403 拒绝，表现就是这里一直等待支付中。')}</div>` : ''}
            <div class="md-paytest__warn"><i class="fa-duotone fa-regular fa-triangle-exclamation"></i>${i18n('这是一笔拨测单，不会产生真实订单，付款不会到账也不会发货，请用最小金额。')}</div>
            ${safeUrl ? `<div class="md-paytest__act"><a href="${escapeHtml(safeUrl)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-light-primary"><i class="fa-duotone fa-regular fa-arrow-up-right-from-square"></i> ${i18n('在新标签打开')}</a></div>` : ''}
        </div>`;

        openControllerLayer({
            type: 1,
            title: util.icon('fa-duotone fa-regular fa-vial-circle-check') + ' ' + i18n('拨测结果'),
            area: [util.isMobile() ? '94%' : '560px', 'auto'],
            content: html,
            success: (layero, index) => {
                if (safeUrl) {
                    try {
                        $('#md-paytest-qr').qrcode({render: 'canvas', width: 148, height: 148, text: safeUrl});
                    } catch (error) {
                        $('#md-paytest-qr').html(`<span class="text-muted">${i18n('二维码生成失败，请直接复制上面的地址')}</span>`);
                    }
                }
                //layui 给 area:'auto' 的高度会被视口夹一刀，内容一多就出滚动条。
                //这里按内容实际高度重新量一次，屏幕放得下就撑开，放不下才退回滚动。
                fitLayerToContent(layero, index);
                pollTestState(String(data?.trade_no || ''), index);
            },
            end: () => stopPolling()
        });
    };

    //拨测状态轮询。拨测单不进订单表，状态记在服务端的临时文件里，
    //网关回调打到 /user/api/order/callbackTest.{订单号} 后落款，这里每 2 秒取一次。
    let pollTimer = null;
    const stopPolling = () => {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    };

    const renderState = (state, message) => {
        const $box = $('#md-paytest-state');
        if (!$box.length) return false;

        const map = {
            waiting: {icon: 'fa-spinner-third md-paytest__spin', text: i18n('等待支付中…付款后这里会实时变成已支付')},
            paid: {icon: 'fa-circle-check', text: i18n('已收到支付回调，验签通过')},
            failed: {icon: 'fa-circle-xmark', text: i18n('回调校验失败')},
            trade_failed: {icon: 'fa-circle-xmark', text: i18n('下单失败')},
            expired: {icon: 'fa-clock', text: i18n('拨测记录已过期（超过 1 小时）')}
        };
        const hit = map[state] || map.waiting;

        $box.attr('data-state', state);
        $box.html(`<i class="fa-duotone fa-regular ${hit.icon}"></i><span class="md-paytest__state-text">${hit.text}${message ? '：' + escapeHtml(message) : ''}</span>`);
        return true;
    };

    const pollTestState = (tradeNo, layerIndex) => {
        stopPolling();
        if (!tradeNo) return;

        //回调是外部网关打进来的，可能几秒也可能几分钟；10 分钟没动静就别再问了
        const deadline = Date.now() + 10 * 60 * 1000;

        pollTimer = setInterval(() => {
            //弹窗关了/切页了就停，别在后台空转
            if (!controllerActive || !$('#md-paytest-state').length) {
                stopPolling();
                return;
            }
            if (Date.now() > deadline) {
                stopPolling();
                renderState('expired', '');
                return;
            }

            $.post('/admin/api/pay/testState', {trade_no: tradeNo}).done(res => {
                if (res?.code !== 200) return;
                const state = String(res?.data?.status || 'waiting');
                //pending/waiting 都还没等到回调，继续轮询
                renderState(state === 'pending' ? 'waiting' : state, String(res?.data?.message || ''));
                if (['paid', 'failed', 'trade_failed', 'expired'].includes(state)) {
                    stopPolling();
                    if (state === 'paid') message.success(i18n('拨测单已收到支付回调'));
                }
            });
        }, 2000);
    };

    //用一个跟真实订单号同形的随机号做默认值：回调URL按订单号寻址，纯数字才会走新形态那条分支
    const mockTradeNo = () => {
        const pad = n => String(n).padStart(2, '0');
        const d = new Date();
        const rand = () => String(Math.floor(Math.random() * 900) + 100);
        return rand() + String(d.getFullYear()).slice(2) + pad(d.getMonth() + 1) + pad(d.getDate())
            + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds()) + rand();
    };

    const testModal = row => {
        component.popup({
            title: util.icon('fa-duotone fa-regular fa-vial-circle-check') + ' ' + i18n('拨测支付接口'),
            tab: [{
                name: escapeHtml(String(row?.name || '')),
                form: [
                    {
                        title: "模拟订单号",
                        name: "trade_no",
                        type: "input",
                        required: true,
                        default: mockTradeNo(),
                        placeholder: "6-32 位字母、数字、下划线或短横线",
                        tips: i18n('已按真实订单号的格式生成了一个，可以自己改')
                    },
                    {
                        title: "金额",
                        name: "amount",
                        type: "input",
                        required: true,
                        default: "0.01",
                        placeholder: "例如 0.01",
                        tips: i18n('会在网关那边生成一笔真实的待支付订单，建议用最小金额，上限 100')
                    }
                ]
            }],
            width: "520px",
            height: "auto",
            autoPosition: true,
            confirmText: '开始拨测',
            //自己接管提交：拨测要的是把返回结果摊开看，不是简单的成功提示
            submit: (data, index) => {
                util.post({
                    url: '/admin/api/pay/test',
                    data: {id: row.id, trade_no: data.trade_no, amount: data.amount},
                    done: res => {
                        if (!controllerActive) return;
                        layer.close(index);
                        showTestResult(row, res?.data || {});
                    },
                    error: res => message.error(res?.msg || i18n('拨测失败'))
                });
            }
        });
    };

    const modal = (title, assign = {}) => {

        let codeOptions = [];
        let configOptions = [];

        if (assign?.handle && assign?.code) {
            const plg = plugins[assign?.handle];
            if (plg) {
                for (const index in plg?.info?.options) {
                    codeOptions.push({
                        id: index,
                        name: plg?.info?.options[index]
                    })
                }
                codeOptions.push({id: CUSTOM_CODE, name: i18n('自定义')});
            }
        }

        //编辑一个当初填了自定义 code 的接口：radio 要落在「自定义」上，输入框要带出原值
        const customCode = isCustomCode(assign?.handle, assign?.code) ? String(assign.code) : '';

        //编辑态先把当前插件的配置档铺好，否则下拉框第一次渲染是空的
        if (assign?.handle) {
            configOptions = pluginConfigs(assign.handle);
        }

        //换插件/进表单时重填「支付配置」下拉框。select 不像 radio 会自动选中第一项，
        //必须显式 setSelected，否则校验会卡在占位项上。
        const fillConfigs = (form, handle) => {
            const list = pluginConfigs(handle);
            form.clearOption("pay_config_id");
            list.forEach(item => form.addOption("pay_config_id", item.id, item.name));
            if (list.length > 0) {
                const current = list.some(item => item.id == assign?.pay_config_id)
                    ? assign.pay_config_id
                    : list[0].id;
                form.setSelected("pay_config_id", current);
                form.show("pay_config_id");
            } else {
                form.hide("pay_config_id");
            }
            return list;
        };

        component.popup({
            submit: '/admin/api/pay/save',
            tab: [
                {
                    name: title,
                    form: [
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
                            title: "支付名称",
                            name: "name",
                            required: true,
                            type: "input",
                            placeholder: "请输入支付方式名称"
                        },
                        {
                            title: "支付插件",
                            name: "handle",
                            type: "select",
                            dict: handles,
                            required: true,
                            placeholder: "请选择支付插件",
                            tips: assign?.id ? i18n('已有支付接口不能更换所属插件；如需更换，请新建接口。') : '',
                            default: 0,
                            change: (form, value) => {
                                const plg = plugins[value];
                                if (plg) {
                                    form.clearComponent("code");
                                    for (const index in plg?.info?.options) {
                                        form.addRadio("code", index, plg?.info?.options[index], assign?.code == index);
                                    }
                                    //插件没列全的通道，站长可以自己填
                                    form.addRadio("code", CUSTOM_CODE, i18n('自定义'), false);
                                    form.show("code");
                                    form.hide("code_custom");
                                    $('.' + form.getUnique()).find('[data-code-custom]').val('');
                                } else {
                                    form.clearComponent("code");
                                    form.hide("code");
                                    form.hide("code_custom");
                                }
                                const list = fillConfigs(form, value);
                                if (plg && list.length === 0) {
                                    message.error(i18n('该插件还没有支付配置，请先到「支付插件 → 配置档案」新建一套'));
                                }
                            }
                        },
                        {
                            title: "支付配置",
                            name: "pay_config_id",
                            type: "select",
                            dict: configOptions,
                            hide: !assign?.handle,
                            required: true,
                            placeholder: "请选择支付配置",
                            //插件不能改，配置可以改——给已有接口换商户号正是这功能的主要用途
                            tips: i18n('同一个插件可以保存多套商户配置，在「支付插件 → 配置档案」里维护。')
                        },
                        {
                            title: "支付方式",
                            name: "code",
                            type: "radio",
                            dict: codeOptions,
                            hide: !assign?.code,
                            required: true,
                            default: customCode ? CUSTOM_CODE : (assign?.code ?? ''),
                            tips: assign?.id ? i18n('可切换当前插件支持的其他支付方式。') : '',
                            change: (form, value) => {
                                if (String(value) === CUSTOM_CODE) {
                                    form.show("code_custom");
                                } else {
                                    form.hide("code_custom");
                                }
                                //刚切过来时 radio 的 value 还是占位符，这里补一次
                                syncCustomCode(form.getUnique());
                            }
                        },
                        {
                            title: "自定义Code",
                            name: "code_custom",
                            type: "input",
                            hide: !customCode,
                            default: customCode,
                            placeholder: "插件没列出的支付代码，例如 alipay_h5",
                            tips: i18n('直接发给支付网关的通道代码，最长32位。自定义代码走跳转支付，不使用站内收银台模板。')
                        },
                        {
                            title: "显示终端",
                            name: "equipment",
                            type: "radio",
                            dict: "_pay_equipment",
                            default: 0
                        },
                        {
                            title: "下单手续费",
                            name: "cost",
                            type: "input",
                            placeholder: "不设置手续费请留空",
                            tips: i18n("单笔固定：每笔订单固定手续费") + "<br>" + i18n("百分比：使用小数代替，比如0.01")
                        },
                        {
                            title: "手续费模式",
                            name: "cost_type",
                            type: "radio",
                            dict: [
                                {id: 0, name: "单笔固定"},
                                {id: 1, name: "百分比(使用小数代替)"}
                            ],
                            default: 0
                        },
                        {title: "商品下单", name: "commodity", type: "switch", text: "启用"},
                        {title: "会员充值", name: "recharge", type: "switch", text: "启用"},
                        {title: "显示排序", name: "sort", type: "input", placeholder: "越小显示靠前"},
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            content: {
                css: {
                    height: "auto",
                    overflow: "inherit"
                }
            },
            height: "auto",
            width: "680px",
            renderComplete: unique => {
                const $root = $('.' + unique);
                $root.find('input[name="cost"]').attr({inputmode: 'decimal', autocomplete: 'off'});
                $root.find('input[name="sort"]').attr({inputmode: 'numeric', autocomplete: 'off'});

                //自定义Code 只是个录入口，值靠 syncCustomCode 写进 code 单选框。
                //必须把 name 摘掉：服务端 SAVE_FIELDS 是严格白名单，多提交一个字段会直接报未授权。
                $root.find('input[name="code_custom"]')
                    .removeAttr('name')
                    .attr({'data-code-custom': '1', autocomplete: 'off', spellcheck: 'false'});
                $root.off('input' + namespace, '[data-code-custom]')
                    .on('input' + namespace, '[data-code-custom]', () => syncCustomCode(unique));
                syncCustomCode(unique);

                //编辑一个自定义 code 的接口时，要把「自定义」这一项勾上。
                //不能靠字段的 default：popup 会用 assign.code（比如 alipay_h5）覆盖它，
                //而选项里那一项的 id 是占位符，匹配不上，radioRegister 就会退回勾选第一项。
                if (customCode) {
                    const $codes = $root.find('input[name="code"]');
                    $codes.prop('checked', false);
                    $codes.last().prop('checked', true);
                    layui.form.render();
                }
                if (!assign?.id) return;
                const $locked = $root.find('select[name="handle"]');
                $locked.prop('disabled', true).attr({'aria-disabled': 'true', 'data-pay-identifier-locked': 'true'});
                $locked.next('.layui-form-select').addClass('layui-disabled').css('pointer-events', 'none');
                $root.find('.component-handle .layui-form-select').addClass('layui-disabled').css('pointer-events', 'none');
            },
            done: () => {
                table.refresh();
            }
        });
    }

    const initializeTable = () => {
        table = new Table("/admin/api/pay/data", "#pay-table");
        table.setUpdate("/admin/api/pay/save");
        table.setColumns([
            {checkbox: true, formatter: (_, row) => ({disabled: Number(row.id) === 1})},
            {
                field: 'name', title: '支付名称', formatter: (_, __) => {
                    const icon = escapeHtml(safeImageUrl(__.icon));
                    const name = escapeHtml(__.name ?? '');
                    const archivedBadge = Number(__.archived) === 1 ? `<span class="a-badge a-badge-warning" style="margin-left:8px;">${i18n('已归档')}</span>` : '';
                    return `<div class="md-pay"><img src="${icon}" class="md-pay__icon" alt=""><span class="md-pay__name">${name}</span>${archivedBadge}</div>`;
                }
            }
        , {
            field: 'plugin', title: '所属插件', formatter: function (val, item) {
                if (item.id == 1) {
                    return '-';
                }
                return getPluginName(item.handle);
            }
        }
        , {
            //只读。表格里误点一下就改变钱的去向，改配置一律走编辑弹窗。
            field: 'pay_config', title: '支付配置', formatter: function (val, item) {
                if (item.id == 1) {
                    return '-';
                }
                return getConfigName(item.handle, item.pay_config_id);
            }
        }
        , {
            field: 'cost', title: '手续费', formatter: function (val, item) {
                if (item.id == 1) {
                    return '-';
                }
                if (item.cost == 0) {
                    return '<span class="a-badge a-badge-danger" >' + i18n('未启用') + '</span>';
                }
                if (item.cost_type == 0) {
                    return '<span class="a-badge a-badge-success" >￥' + escapeHtml(item.cost) + '</span>';
                } else {
                    return '<span class="a-badge a-badge-primary" >' + (item.cost * 100) + '%</span>';
                }
            }
        }
        , {
            field: 'create_time', title: '创建时间', show: _ => _.id != 1
        }
        , {
            field: 'type', title: '支付方式', formatter: function (val, item) {
                if (item.id == 1) {
                    return '-';
                }
                return '<span class="a-badge a-badge-success">' + getType(item.handle, item.code) + '</span>';
            }
        },
        {
            field: 'equipment',
            title: '终端控制',
            show: _ => _.id != 1,
            dict: "_pay_equipment",
            reload: true
        }, {
            field: 'commodity', title: '商品下单', show: _ => _.id != 1, type: "switch", text: "开启|关闭", reload: true
        }
        , {
            field: 'recharge', title: '余额充值', show: _ => _.id != 1, type: "switch", text: "开启|关闭", reload: true
        }, {field: 'sort', title: '排序(越小越前)', show: _ => _.id != 1, sort: true, type: "input", reload: true}
        ,
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-vial-circle-check',
                    class: 'text-success',
                    //只出图标，跟旁边的改/删保持一致——渲染器里 title 是会显示成文字的，所以不能写
                    //余额走站内扣款、归档接口已下线，都没有网关可测
                    show: item => item.id != 1 && item.handle !== '#system' && Number(item.archived) !== 1,
                    click: (event, value, row, index) => {
                        testModal(row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    show: item => item.id != 1 && Number(item.archived) !== 1,
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + i18n("修改支付接口"), row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    show: item => item.id != 1 && Number(item.archived) !== 1,
                    click: (event, value, row, index) => {
                        confirmPayDelete([row.id]);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-rotate-left',
                    class: 'text-success',
                    title: '恢复',
                    show: item => Number(item.archived) === 1,
                    click: (event, value, row, index) => {
                        message.ask(
                            `${i18n('恢复后，接口')} <b>${escapeHtml(row.name ?? '')}</b> ${i18n('将回到接口列表并处于停用状态，需手动重新启用。确认恢复吗？')}`,
                            () => restorePayments([row.id]),
                            i18n('确认恢复支付接口？'),
                            i18n('确认恢复')
                        );
                    }
                }
            ]
        },
        ]);
        table.setSearch([
            {title: "支付名称", name: "search-name", type: "input"},
            {
                title: "商品下单-状态", name: "equal-commodity", type: "select", dict: "_common_status"
            },
            {
                title: "余额充值-状态", name: "equal-recharge", type: "select", dict: "_common_status"
            },
            {
                title: "接口状态", name: "equal-archived", type: "select", dict: [
                    {id: 0, name: "使用中"},
                    {id: 1, name: "已归档"}
                ]
            }
        ]);
        table.setState("handle", handles);

        table.render();


        $('.btn-app-create').off(namespace).on('click' + namespace, function () {
            modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> ${i18n('添加支付接口')}`);
        });


        $('.btn-app-del').off(namespace).on('click' + namespace, () => {
            let data = table.getSelectionIds();
            if (data.length == 0) {
                layer.msg(i18n("请至少勾选1个支付方式进行操作！"));
                return;
            }

            confirmPayDelete(data);
        });
    };

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        deletePreviewPending = false;
        deletePending = false;
        if (pluginRequest && typeof pluginRequest.abort === 'function') pluginRequest.abort();
        if (typeof Swal !== 'undefined') Swal.close();
        stopPolling();
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        $('.btn-app-create, .btn-app-del').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (window.__mdPayApiDestroy === destroy) delete window.__mdPayApiDestroy;
    }

    window.__mdPayApiDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
    loadPlugins().then(function (ready) {
        if (ready && controllerActive) initializeTable();
    });
}();
