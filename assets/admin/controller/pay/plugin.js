!function () {
    let table, _LogPid;
    const namespace = '.mdPayPluginController';
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '/favicon.ico';
        } catch (error) {
            return '/favicon.ico';
        }
    };
    const controllerLayers = new Set();
    let controllerActive = true;
    if (typeof window.__mdPayPluginDestroy === 'function') window.__mdPayPluginDestroy();
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
    const pluginUpdate = {
        items: null,
        updateNum: 0,
        countedKeys: new Set(),
        init() {
            if (!this.items) {
                let items = localStorage.getItem("pluginVersions");
                if (items) {
                    try {
                        const parsed = JSON.parse(items);
                        this.items = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
                    } catch (error) {
                        this.items = {};
                    }
                } else {
                    this.items = {};
                }
            }
        },
        getPlugin(key) {
            this.init();
            if (!this.items || !Object.prototype.hasOwnProperty.call(this.items, key)) {
                return null;
            }
            return this.items[key];
        },
        getAvailable(key, version) {
            const plugin = this.getPlugin(key);
            return plugin && version != plugin.version ? plugin : null;
        },
        renderButton(key, version) {
            const plugin = this.getAvailable(key, version);
            if (!plugin) {
                return "";
            }

            if (!this.countedKeys.has(key)) {
                this.countedKeys.add(key);
                this.updateNum++;
            }

            $('#updateNum').html('<b class="text-danger">[' + this.updateNum + ']' + i18n('个插件需要更新') + '</b>');

            return ' <span style="cursor: pointer;" class="badge badge-light-success updatePlugin">' + i18n('更新-&gt;') + escapeHtml(plugin.version) + '</span>';
        }
    }

    const runPluginUpdate = row => {
        const plugin = pluginUpdate.getPlugin(row.id);
        if (!plugin) {
            message.error("初始化更新失败，请刷新页面重试");
            return;
        }
        const updateContent = escapeHtml(plugin?.update_content || i18n('该更新没有提供说明')).replace(/\n/g, '<br>');
        message.ask(updateContent, () => {
            if (!controllerActive) return;
            util.post('/admin/api/app/upgrade', {
                plugin_key: row.id,
                type: plugin.type,
                plugin_id: plugin.id
            }, res => {
                if (!controllerActive) return;
                message.info(res.msg);
                if (res.code == 200) window.location.reload();
            });
        }, `<b class="text-primary"><i class="fa-duotone fa-regular fa-sparkles"></i> ${escapeHtml(row?.info?.name)}</b> <span class="text-primary" style="font-size:14px;">${escapeHtml(row?.info?.version)}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span class="text-success" style="font-size:14px;">${escapeHtml(plugin.version)}</span>`, i18n("立即更新"));
    };

    //这几个插件的验签密钥归属于 app/Plugin 那一侧的通用插件（手机App配对、自己的表和队列都在那边），
    //pay 这一侧配多套也只能换验签用的 key，换不了收款的那台设备，界面上要说清楚免得站长白配。
    //UsdtPay 2.0 起多套配置就是多个收款钱包，已经不是"只能区分验签密钥"的形态，从名单里摘掉
    const SHARED_KEY_PLUGINS = ['AlipayPersonalPay', 'WeChatPersonalPay', 'BuiltPayExtend'];

    const enc = value => encodeURIComponent(String(value ?? ''));

    /**
     * 配置表单顶部的商户配置切换条。
     *
     * 绝大多数站点一个插件只用一套配置，所以入口仍是原来那个「配置」按钮，点开直接是表单，
     * 不该为了少数多商户的场景让所有人先过一层列表。多配置只是表单顶上多一排 chip，
     * 想切就切，想加就加——功能在手边，但不挡路。
     */
    const profileBarHtml = (row, profiles, active) => {
        const chips = profiles.map(p => {
            const isActive = Number(p.id) === Number(active.id);
            const used = Array.isArray(p.in_use) ? p.in_use.length : 0;
            const badge = used > 0 ? `<span class="md-payprofile__use">${used}</span>` : '';
            return `<div class="md-payprofile__chip${isActive ? ' is-active' : ''}" data-profile-id="${Number(p.id)}" data-profile-name="${escapeHtml(p.name)}" title="${escapeHtml(p.name)}">
                        <span class="md-payprofile__name">${escapeHtml(p.name)}</span>${badge}
                    </div>`;
        }).join('');

        //哪些支付接口在用这套配置——渲染成带图标的徽标，比一串顿号分隔的名字好认，
        //改配置之前一眼就知道会影响到哪几个收款渠道
        const used = Array.isArray(active.in_use) ? active.in_use : [];
        const badges = used.map(item => {
            const name = escapeHtml(item?.name ?? '');
            return `<span class="md-payprofile__badge" title="${name}">
                        <img src="${escapeHtml(safeImageUrl(item?.icon))}" alt="">
                        <span class="md-payprofile__badge-name">${name}</span>
                    </span>`;
        }).join('');

        const usage = used.length
            ? `<span class="md-payprofile__usage-label">${i18n('正被')} <b>${used.length}</b> ${i18n('个支付接口使用')}</span><span class="md-payprofile__used">${badges}</span>`
            : `<span class="md-payprofile__usage-label md-payprofile__usage-label--free"><i class="fa-duotone fa-regular fa-circle-check"></i>${i18n('还没有支付接口在用，可以放心修改')}</span>`;

        const note = SHARED_KEY_PLUGINS.includes(row.id)
            ? `<div class="md-payprofile__note">${i18n('注意：该插件的收款设备由「插件管理」里的同名插件统一提供，多套配置只能区分验签密钥，换不了收款设备。')}</div>`
            : '';

        //默认配置是这个插件的兜底，删掉之后新建支付接口就没得选了，所以只给改名不给删。
        //服务端 deletePluginConfig 里也拦了同一条规则，不是只靠藏按钮。
        const canDelete = active.is_default !== true;

        return `<div class="md-payprofile">
            <div class="md-payprofile__head">
                <span class="md-payprofile__title"><i class="fa-duotone fa-regular fa-layer-group"></i>${i18n('商户配置')}</span>
                <span class="md-payprofile__ops">
                    <span class="md-payprofile__op md-payprofile__op--rename"><i class="fa-duotone fa-regular fa-pen"></i>${i18n('改名')}</span>
                    ${canDelete ? `<span class="md-payprofile__op md-payprofile__op--danger md-payprofile__op--delete"><i class="fa-duotone fa-regular fa-trash"></i>${i18n('删除')}</span>` : ''}
                </span>
            </div>
            <div class="md-payprofile__list">
                ${chips}
                <div class="md-payprofile__chip md-payprofile__chip--add" title="${i18n('新增一套商户配置')}">
                    <i class="fa-duotone fa-regular fa-plus"></i>${i18n('新增')}
                </div>
            </div>
            <div class="md-payprofile__foot">${usage}${note}</div>
        </div>`;
    };

    /**
     * 起名弹窗。layui 的 prompt 默认不认回车，输入完还得去点确定，很别扭——这里补上，
     * 顺便自动聚焦并选中原名字，改名时直接打字就能覆盖。
     */
    const promptName = (title, value, onOk) => {
        layer.prompt({
            title: title,
            formType: 0,
            value: value || '',
            maxlength: 16,
            success: layero => {
                const $input = $(layero).find('.layui-layer-input').first();
                $input.trigger('focus').trigger('select');
                $input.off('keydown.payprofile').on('keydown.payprofile', e => {
                    if (e.key === 'Enter' || e.keyCode === 13) {
                        e.preventDefault();
                        $(layero).find('.layui-layer-btn0').trigger('click');
                    }
                });
            }
        }, (name, index) => {
            layer.close(index);
            onOk(name);
        });
    };

    /**
     * 打开某插件的配置表单。configId 为空则打开排序最前的那一套。
     */
    const openConfig = (row, configId = null, keepPlace = null, preloaded = null) => {
        if (!row?.id) return;

        //纯切换不用重新拉数据——数据都在手上，再发一次请求只会让旧弹窗关掉后干等一个来回，
        //中间那段空白才是"重新打开"的观感来源。只有增删改之后才需要真的刷新。
        if (Array.isArray(preloaded) && preloaded.length > 0) {
            const hit = preloaded.find(p => Number(p.id) === Number(configId)) || preloaded[0];
            renderConfigForm(row, preloaded, hit, keepPlace);
            return;
        }

        util.post({
            url: '/admin/api/pay/getPluginConfigs',
            data: {handle: row.id},
            done: res => {
                if (!controllerActive) return;
                const profiles = Array.isArray(res?.data?.profiles) ? res.data.profiles : [];

                //一套都没有（比如插件是升级后新装的）就先建一套默认的，站长无感
                if (profiles.length === 0) {
                    util.post({
                        url: '/admin/api/pay/createPluginConfig',
                        data: {handle: row.id, name: i18n('默认配置')},
                        done: () => controllerActive && openConfig(row),
                        error: r => message.error(r?.msg || i18n('配置初始化失败'))
                    });
                    return;
                }

                const active = profiles.find(p => Number(p.id) === Number(configId)) || profiles[0];
                renderConfigForm(row, profiles, active, keepPlace);
            },
            error: res => message.error(res?.msg || i18n('配置加载失败'))
        });
    };

    /**
     * 渲染某一套配置的表单。
     *
     * assign 用 {插件行, ...该套配置的值} 合并：Submit.js 里会 eval 到 `assign?.version` 这类表达式，
     * 每套配置的 version 可能不同，必须读到本套的值。能撞名的键（id/handle/name/status…）都在
     * 服务端的 $protected 黑名单里，不可能成为配置键，所以这个展开顺序是安全的。
     */
    const renderConfigForm = (row, profiles, active, keepPlace = null) => {
        let submit = [];
        const values = active.config || {};
        const source = active.submit ?? row.submit;
        const title = util.icon("fa-duotone fa-regular fa-gear") + " " + escapeHtml(row?.info?.name);

        if (Array.isArray(source)) {
            submit = [{name: title, form: source}];
        } else if (typeof source === "string" && source.trim() !== "") {
            try {
                const assign = Object.assign({}, row, values);
                submit = eval(source);
            } catch (error) {
                message.error('支付插件配置定义无法解析，请联系插件作者');
                return;
            }
        }

        if (!Array.isArray(submit) || submit.length === 0) {
            message.error('该支付插件没有可配置项目');
            return;
        }

        component.popup({
            submit: `/admin/api/pay/setPluginConfig?id=${enc(row.id)}&config_id=${enc(active.id)}`,
            //第三方插件的 HACK_SUBMIT_* 钩子按 URL 字符串匹配，给它老地址，别因为多了个参数就失配
            submitRoute: `/admin/api/pay/setPluginConfig?id=${enc(row.id)}`,
            tab: submit,
            assign: values,
            autoPosition: true,
            height: "auto",
            width: "680px",
            fitTabs: true,
            renderComplete: (unique, layIndex) => {
                //切换配置是重建表单（这样每套配置的字段可见性、必填、密钥脱敏状态才一定是对的，
                //不会出现"看到的是B、存进去的是A的值"这种把钱打错商户的事）。
                //但重建不该让人看见——把入场动画去掉、位置钉在原处，看起来就只是内容换了。
                if (keepPlace) {
                    const $lay = $('.' + unique);
                    //layui 的入场动画（layer-anim-04 是一段旋转+淡入）是靠 class 加上去的，
                    //而它加 class 的时机不一定早于这里，光 removeClass 抢不稳。
                    //直接用 inline !important 把 animation 摁死，谁先谁后都不影响。
                    $lay.removeClass((i, cls) => (cls.match(/layer-anim\S*/g) || []).join(' '));
                    if ($lay[0]) {
                        $lay[0].style.setProperty('animation', 'none', 'important');
                        //弹窗的 top/left 带 CSS 过渡：新层是在 layui 的默认位置生成的，
                        //下面把它钉回原位时会"滑"过去半秒，看着就像窗口又动了一次。
                        //落位这一小段先把过渡关掉，钉完再还回去（不然用户拖动弹窗就没有缓动了）。
                        $lay[0].style.setProperty('transition', 'none', 'important');
                    }
                    //钉住上边缘而不是重新居中：不同配置的表单高度不一样，居中会让整个弹窗上下跳，
                    //盯着看的那一行字就跑了。上边缘不动、内容往下长，眼睛不用重新找位置。
                    const pin = () => {
                        const height = $lay.outerHeight() || 0;
                        const maxTop = Math.max(8, $(window).scrollTop() + window.innerHeight - height - 8);
                        $lay.css({left: keepPlace.left, top: Math.min(parseFloat(keepPlace.top) || 0, maxTop) + 'px'});
                    };
                    //autoPosition 的 ResizeObserver 渲染完还会再居中一次，时机不固定；
                    //与其猜它什么时候动手，不如盯着 style 变化，谁改了就改回来。
                    //过渡上面已经关掉了，所以每次改回来都是瞬间生效，看不到来回拉扯。
                    pin();
                    const watcher = new MutationObserver(() => {
                        if (parseFloat($lay.css('top')) !== parseFloat(keepPlace.top)) pin();
                    });
                    watcher.observe($lay[0], {attributes: true, attributeFilter: ['style']});
                    //只盯落位这一小段，之后交还控制权（比如用户自己拖动弹窗）
                    setTimeout(() => {
                        watcher.disconnect();
                        $lay[0] && $lay[0].style.removeProperty('transition');
                    }, 900);
                }
                bindProfileBar(unique, layIndex, row, profiles, active);
            },
            done: () => {
                if (controllerActive) table.refresh();
            }
        });
    };

    /**
     * 把切换条塞进表单顶部并接上事件。
     */
    const bindProfileBar = (unique, layIndex, row, profiles, active) => {
        const $root = $('.' + unique);
        if (!$root.length) return;

        //注意 .{unique} 拿到的是 layer 的最外层，直接 prepend 会插到标题栏之前，
        //跟右上角的最小化/关闭按钮叠在一起。要放进内容区。
        const $body = $root.find('.layui-layer-content').first();
        ($body.length ? $body : $root).prepend(profileBarHtml(row, profiles, active));

        /**
         * 重开表单。preloaded 传了就同步重建（切换配置走这条，肉眼看不出重开），
         * 不传则重新拉一次数据（增删改之后必须刷新）。
         */
        const reopen = (configId, preloaded = null) => {
            //记住当前位置，并让旧弹窗立刻消失——不放关闭动画，避免"缩一下再弹一个"的观感
            const $lay = $('.' + unique);
            const place = $lay.length ? {top: $lay.css('top'), left: $lay.css('left')} : null;
            $lay.hide();
            //遮罩交给 layer.close 自己回收（它内部是同步 remove，不是淡出）。
            //手动去摘会打乱它的记账，把新遮罩也一起干掉，弹窗就没有背景蒙层了。
            layer.close(layIndex);
            openConfig(row, configId, place, preloaded);
        };

        //切到另一套
        $root.on('click', '.md-payprofile__chip[data-profile-id]', function () {
            const id = Number($(this).data('profile-id'));
            if (id === Number(active.id)) return;
            reopen(id, profiles);
        });

        //新增一套
        $root.on('click', '.md-payprofile__chip--add', function () {
            promptName(i18n('新配置的名称'), '', name => {
                util.post({
                    url: '/admin/api/pay/createPluginConfig',
                    data: {handle: row.id, name: name},
                    done: r => {
                        message.success(r?.msg || i18n('添加成功'));
                        reopen(Number(r?.data?.id) || null);
                    },
                    error: r => message.error(r?.msg || i18n('添加失败'))
                });
            });
        });

        //改名
        $root.on('click', '.md-payprofile__op--rename', function () {
            promptName(i18n('新的配置名称'), active.name, name => {
                util.post({
                    url: '/admin/api/pay/renamePluginConfig',
                    data: {handle: row.id, config_id: active.id, name: name},
                    done: r => {
                        message.success(r?.msg || i18n('修改成功'));
                        reopen(Number(active.id));
                    },
                    error: r => message.error(r?.msg || i18n('修改失败'))
                });
            });
        });

        //删除
        $root.on('click', '.md-payprofile__op--delete', function () {
            const used = Array.isArray(active.in_use) ? active.in_use : [];
            const warn = used.length
                ? `<div class="mt-2 text-danger">${i18n('该配置正被')} ${used.length} ${i18n('个支付接口使用，需要先把它们改用其他配置。')}</div>`
                : '';
            message.ask(
                `${i18n('删除后不可恢复，确定删除配置「')}${escapeHtml(active.name)}${i18n('」吗？')}${warn}`,
                () => util.post({
                    url: '/admin/api/pay/delPluginConfig',
                    data: {handle: row.id, config_id: active.id},
                    done: r => {
                        message.success(r?.msg || i18n('删除成功'));
                        reopen(null);
                    },
                    error: r => message.error(r?.msg || i18n('删除失败'))
                }),
                i18n('确认删除配置？'),
                i18n('确认删除')
            );
        });
    };

    table = new Table("/admin/api/pay/getPlugins", "#pay-plugin-table");
    table.setColumns([
        {
            field: 'plugin_name', title: '插件名称', formatter: function (val, item) {
                return `<div class="md-plugin"><img src="${escapeHtml(safeImageUrl(item?.icon))}" class="md-plugin__icon" alt=""><span class="md-plugin__name">${escapeHtml(item?.info?.name)}</span></div>`;
            }
        }
        , {
            field: 'operation', class: "nowrap", title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-gear',
                    class: 'text-primary',
                    title: '配置',
                    show: item => Array.isArray(item?.submit)
                        ? item.submit.length > 0
                        : (typeof item?.submit === 'string' && item.submit.trim() !== ''),
                    click: (event, value, row, index) => {
                        openConfig(row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-bug',
                    title: '日志',
                    click: (event, value, row, index) => {
                        let mapItem = row, logPid = _LogPid = util.generateRandStr(16);
                        util.post('/admin/api/pay/getPluginLog', {handle: mapItem.id}, res => {
                            if (!controllerActive) return;
                            const mobile = mobileAdminEnabled();
                            const initialLog = res?.data?.log ?? '';
                            let $logText = null;
                            openControllerLayer({
                                type: 1,
                                shade: 0.4,
                                shadeClose: true,
                                title: '<i class="fa-duotone fa-regular fa-ban-bug"></i> ' + i18n('日志'),
                                btn: [i18n("清空日志"), i18n("关闭")],
                                content: '<textarea class="log-textarea form-control" style="width:100%;height:100%;resize:none;"></textarea>',
                                area: mobile ? ["100%", "100%"] : ["860px", "660px"],
                                skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task admin-mobile-layer-popup--danger-action md-pay-plugin-log-layer' : 'md-pay-plugin-log-layer',
                                maxmin: !mobile,
                                resize: !mobile,
                                move: !mobile,
                                yes: (index, layero) => {
                                    message.ask('清空后，当前支付插件的全部日志将被永久删除，且无法恢复。确认继续吗？', () => {
                                        if (!controllerActive || _LogPid !== logPid) return;
                                        util.post('/admin/api/pay/ClearPluginLog', {handle: mapItem.id}, res => {
                                            if (!controllerActive || _LogPid !== logPid || !$logText) return;
                                            $logText.val('');
                                            layer.msg(i18n("日志已清空"));
                                        });
                                    }, i18n('确认清空日志？'), i18n('确认清空'));
                                    return false;
                                },
                                success: (layero, index) => {
                                    $logText = layero.find('.log-textarea').first();
                                    $logText.val(initialLog);
                                    util.timer(() => {
                                        return new Promise(resolve => {
                                            if (!controllerActive || _LogPid !== logPid || !$logText) {
                                                resolve(false);
                                                return;
                                            }
                                            util.post({
                                                url: '/admin/api/pay/getPluginLog',
                                                data: {handle: mapItem.id},
                                                loader: false,
                                                done: res => {
                                                    if (!controllerActive || _LogPid !== logPid) {
                                                        resolve(false);
                                                        return;
                                                    }
                                                    const nextLog = res?.data?.log ?? '';
                                                    if (nextLog != $logText.val()) {
                                                        $logText.val(nextLog);
                                                    }
                                                    resolve(true);
                                                },
                                                error: () => resolve(controllerActive && _LogPid === logPid),
                                                fail: () => resolve(controllerActive && _LogPid === logPid)
                                            });
                                        });
                                    }, 1500);
                                },
                                end: () => {
                                    if (_LogPid === logPid) _LogPid = null;
                                    $logText = null;
                                }
                            });
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-arrows-rotate text-success',
                    class: 'admin-mobile-operation-only text-success',
                    title: '更新插件',
                    show: row => {
                        return mobileAdminEnabled() && Boolean(pluginUpdate.getAvailable(row.id, row?.info?.version));
                    },
                    click: (event, value, row) => runPluginUpdate(row)
                }
            ]
        }
        , {
            field: 'version',
            class: "nowrap",
            title: '<span id="updateNum">' + i18n('版本号') + '</span>',
            formatter: function (val, item) {
                const currentVersion = item?.info?.version;
                return '<span class="md-version">v' + escapeHtml(currentVersion) + '</span>' + pluginUpdate.renderButton(item.id, currentVersion);
            }
            ,
            events: {
                'click .updatePlugin': function (event, value, row, index) {
                    runPluginUpdate(row);
                }
            }
        }
        , {
            field: 'options', title: '功能', formatter: function (val, item) {
                let list = [];
                for (const key in (item?.info?.options || {})) {
                    list.push(format.badge(escapeHtml(item.info.options[key]), "a-badge-success"));
                }
                return list.length ? format.badgeGroup(list.join("")) : "-";
            }
        }
        , {
            field: 'info.description',
            title: '简介',
            class: "break-spaces",
            formatter: value => escapeHtml(value)
        },
        {
            field: 'config.top',
            title: 'TOP',
            class: "nowrap",
            type: "switch",
            text: "置顶|无",
            reload: true,
            change: (state, row) => {
                util.post({
                    url: `/admin/api/pay/setPluginConfig?id=${encodeURIComponent(row.id)}`,
                    data: {top: state},
                    done: () => {
                        if (!controllerActive) return;
                        table.$table.bootstrapTable('refresh', {silent: true, pageNumber: 1});
                    },
                    error: res => {
                        if (!controllerActive) return;
                        message.error(res?.msg || i18n('置顶状态保存失败'));
                        table.refresh(true);
                    },
                    fail: () => {
                        if (!controllerActive) return;
                        message.error('网络异常，置顶状态未保存');
                        table.refresh(true);
                    }
                });
            }
        },
        {
            field: 'author', title: '作者', formatter: function (val, item) {
                if (item?.info?.author == "#" || !item?.info?.author) {
                    return '-';
                }
                return '<span class="md-author"><i class="fa-duotone fa-regular fa-user"></i>' + escapeHtml(item?.info?.author) + '</span>';
            }
        }
        , {
            field: 'uninstall', title: '卸载', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        message.ask(`${i18n('你想要卸载')} <b class="text-danger">${escapeHtml(row?.info?.name ?? row.id)}</b> ${i18n('吗，该操作会清空插件所有数据，且无法恢复，请慎重操作！')}`, () => {
                            if (!controllerActive) return;
                            util.post('/admin/api/app/uninstall', {
                                plugin_key: row.id,
                                type: 1
                            }, res => {
                                if (!controllerActive) return;
                                message.success("卸载成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        }
    ]);

    table.onResponse(response => {
        pluginUpdate.updateNum = 0;
        pluginUpdate.countedKeys.clear();
        $(`#updateNum`).html(i18n("版本号"));
        (response?.data?.list ?? []).forEach(item => {
            const available = pluginUpdate.getAvailable(item.id, item?.info?.version);
            item.__adminMobilePayUpdateVersion = available?.version ?? '';
        });
    });

    table.disablePagination();
    table.render();

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        _LogPid = null;
        $(document).off('pjax:beforeReplace' + namespace);
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (typeof Swal !== 'undefined') Swal.close();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdPayPluginDestroy === destroy) delete window.__mdPayPluginDestroy;
    }

    window.__mdPayPluginDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
