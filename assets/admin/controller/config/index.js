!function () {
    const _notice = document.getElementById('md-config-notice-source')?.value ?? '';
    let _themes = [];
    try {
        const parsedThemes = JSON.parse(document.getElementById('md-config-themes-source')?.value || '[]');
        if (Array.isArray(parsedThemes)) _themes = parsedThemes;
    } catch (error) {}
    const namespace = '.mdConfigIndexController';
    //下拉项内按钮的捕获阶段拦截器，销毁时要按引用移除
    let themeActCapture = null;
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    let controllerActive = true;
    let noticeEditor = null;
    let formReady = false;
    let formDirty = false;
    let dirtyRevision = 0;
    let saveInFlight = false;

    if (typeof window.__mdConfigIndexDestroy === 'function') window.__mdConfigIndexDestroy();

    function formElement() {
        return document.getElementById('data-form');
    }

    function formRevision() {
        const form = formElement();
        return form && window.AdminMobile?.pageWorkflows?.getRevision ? window.AdminMobile.pageWorkflows.getRevision(form) : null;
    }

    function emitFormState(name, revision) {
        const form = formElement();
        if (!form) return;
        document.dispatchEvent(new CustomEvent(name, {detail: {form: form, revision: revision}}));
    }

    function markFormDirty() {
        if (!controllerActive || !formReady) return;
        formDirty = true;
        dirtyRevision += 1;
        emitFormState('admin:mobile:form-dirty');
    }

    function mobileGuardEnabled() {
        return window.AdminMobile?.isEnabled?.() === true;
    }

    function setMainSaveBusy(busy) {
        const $button = $('#data-form .save-data');
        $button.prop('disabled', busy).toggleClass('disabled', busy);
        if (busy) {
            $button.attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        } else {
            $button.removeAttr('aria-busy aria-disabled');
        }
    }

    function setPopupSaveBusy(index, busy) {
        const $button = $(`#layui-layer${index} .layui-layer-btn0`);
        $button.toggleClass('layui-disabled', busy).css('pointer-events', busy ? 'none' : '');
        if (busy) {
            $button.attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        } else {
            $button.removeAttr('aria-busy aria-disabled');
        }
    }

    function destroy() {
        if (!controllerActive) return;
        formReady = false;
        formDirty = false;
        saveInFlight = false;
        setMainSaveBusy(false);
        controllerActive = false;
        if (noticeEditor && typeof noticeEditor.destroy === 'function') noticeEditor.destroy();
        noticeEditor = null;
        $('#data-form, #data-form input[name="admin_entrance_secret"], #data-form input[name="admin_entrance_clear"], .save-data').off(namespace);
        $(document).off('click' + namespace, '[data-theme-update-all]');
        //下拉项内按钮用的是捕获阶段监听，pjax 切页后不移除会残留
        if (themeActCapture) {
            document.removeEventListener('mousedown', themeActCapture, true);
            document.removeEventListener('mouseup', themeActCapture, true);
            document.removeEventListener('click', themeActCapture, true);
            themeActCapture = null;
        }
        if (window.mdSettingsSelectOption) delete window.mdSettingsSelectOption;
        if (window.mdSettingsSelectSelection) delete window.mdSettingsSelectSelection;
        $(window).off('beforeunload' + namespace);
        $(document)
            .off('pjax:beforeReplace' + namespace)
            .off('pjax:click' + namespace)
            .off('pjax:beforeSend' + namespace);
        if (window.__mdConfigIndexDestroy === destroy) delete window.__mdConfigIndexDestroy;
    }

    window.__mdConfigIndexDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);

    function _NoticeEditor() {
        const $mount = $('.notice-editor');
        if (!$mount.length) return;
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });
        $mount.html(EditorV2.buildHtml({name: 'notice', placeholder: i18n('填写店铺公告，支持 Markdown 语法')}));
        noticeEditor = EditorV2.register($mount.get(0), {
            name: 'notice',
            uploadUrl: '/admin/api/upload/send',
            height: 480,
            value: _notice ?? "",
            onChange: markFormDirty
        });
    }


    function _UploadLogoAndBackground() {

        util.bindButtonUpload(".upload-logo", "/admin/api/upload/send?mime=image", data => {
            if (!controllerActive) return;
            $('input[name=logo]').val(data.url);
            markFormDirty();
            layer.msg(i18n('图标上传成功，但需要保存后才会生效'));
            $('.image-input-wrapper').css({
                "background-image": `url(${data.url})`
            });
        });

        util.bindButtonUpload(".background-upload", "/admin/api/upload/send?mime=image", data => {
            if (!controllerActive) return;
            layer.msg(i18n('背景图片上传成功，需要保存才会生效'));
            $('input[name=background_url]').val(data.url);
            markFormDirty();
        });

        util.bindButtonUpload(".background-mobile-upload", "/admin/api/upload/send?mime=image", data => {
            if (!controllerActive) return;
            layer.msg(i18n('手机背景图片上传成功，需要保存才会生效'));
            $('input[name=background_mobile_url]').val(data.url);
            markFormDirty();
        });
    }


    function _ThemeSetting() {
        let themes = {};
        _themes.forEach(item => {
            themes[item.info.KEY] = item;
        });

        function modal(values = {}, contextLabel = '模板设置') {
            const themeKey = String(values?.info?.KEY || '');
            if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(themeKey)) {
                layer.msg(i18n('模板信息不完整，请刷新页面后重试'));
                return;
            }
            let submit = [];
            if (Array.isArray(values.submit)) {
                submit = [
                    {
                        name: `<i class="fa-duotone fa-regular fa-gear-code"></i> ${escapeHtml(values.info.NAME)}`,
                        form: values.submit
                    },
                ];
            } else if (typeof values.submit === "string" && values.submit.trim() != "") {
                try {
                    submit = eval(values.submit) ?? [];
                } catch (error) {
                    layer.msg(i18n('模板设置定义无法解析，请联系模板作者'));
                    return;
                }
            }

            if (!Array.isArray(submit) || submit.length === 0) {
                layer.msg(i18n("该模板暂时没有可设置的选项"));
                return;
            }

            const endpoint = `/admin/api/plugin/setThemeConfig?id=${encodeURIComponent(themeKey)}`;
            let themeSaveInFlight = false;
            component.popup({
                mobileTitle: `${contextLabel} · ${String(values.info.NAME || i18n('模板'))}`,
                submitRoute: endpoint,
                submit: (data, index) => {
                    if (themeSaveInFlight || !controllerActive) return;
                    themeSaveInFlight = true;
                    setPopupSaveBusy(index, true);
                    util.post({
                        url: endpoint,
                        data: data,
                        done: () => {
                            if (!controllerActive) return;
                            layer.close(index);
                            window.location.reload();
                        },
                        error: res => {
                            if (!controllerActive) return;
                            themeSaveInFlight = false;
                            setPopupSaveBusy(index, false);
                            message.error(res?.msg || '模板设置保存失败');
                        },
                        fail: () => {
                            if (!controllerActive) return;
                            themeSaveInFlight = false;
                            setPopupSaveBusy(index, false);
                            message.error('网络异常，模板设置未保存');
                        }
                    });
                },
                tab: submit,
                autoPosition: true,
                height: "auto",
                assign: values.setting,
                width: "660px",
                fitTabs: true
            });
        }


        /* ── 下拉项内的「更新 / 设置 / 卸载」按钮 ──────────────────────
           原来每个下拉右边配一个「模板设置」按钮，只能配置当前选中的那个；
           有更新也只是显示一行提示，得自己跑去应用商店。现在三个动作都直接
           放到每一项后面，不用切换当前选中的模板，右侧按钮因此移除。 */
        const THEME_SELECTS = ['user_theme', 'user_mobile_theme', 'user_center_theme', 'user_center_mobile_theme'];

        //该模板是否正被四个位置中的任何一个使用（读当前下拉的实时值，不是保存前的值）
        const themeInUse = key => THEME_SELECTS.some(name => {
            const v = $(`select[name=${name}]`).val();
            return v && String(v) === String(key);
        });

        //图标只有商店缓存里有；缺图标的主题用首字母色块占位，避免裂图
        const themeIcon = (theme, name) => {
            if (theme && theme.icon) {
                return `<img class="md-theme-opt__icon" src="${escapeHtml(theme.icon)}" alt="" loading="lazy"`
                    + ` data-acg-fallback="placeholder" data-acg-ph-class="md-theme-opt__icon md-theme-opt__icon--ph"`
                    + ` data-ph="${escapeHtml(String(name || '#').trim().charAt(0))}">`;
            }
            return `<span class="md-theme-opt__icon md-theme-opt__icon--ph">${escapeHtml(String(name || '#').trim().charAt(0))}</span>`;
        };

        //模板简介取 Config.php 的 INFO.DESCRIPTION；有的模板把简介直接写成名字
        //（如「默认模板」），那行等于白占一行高度，这种和空的一样不渲染
        const themeDesc = theme => {
            const desc = String(theme?.info?.DESCRIPTION ?? '').trim();
            if (!desc) return '';
            return desc === String(theme?.info?.NAME ?? '').trim() ? '' : desc;
        };

        const themeAuthor = theme => String(theme?.info?.AUTHOR ?? '').trim();

        /* 一栏两行：上行「名称 + 作者」，下行简介。
           作者跟着名称走而不是另起一行——全站二十个模板只有两个作者名，翻来覆去
           重复，单独占一行纯属噪音；名称行右边本来就空着，正好收留它。
           简介是唯一会被截断的那部分，所以独占一整行。 */
        const themeText = (label, desc, author) => `<span class="md-theme-opt__text">`
            + `<span class="md-theme-opt__head"><span class="md-theme-opt__name">${label}</span>`
            + (author ? `<span class="md-theme-opt__author" title="${i18n('作者')}：${escapeHtml(author)}">`
                + `<i class="fa-duotone fa-regular fa-user-pen" aria-hidden="true"></i>${escapeHtml(author)}</span>` : '')
            + `</span>`
            + (desc ? `<span class="md-theme-opt__desc" title="${escapeHtml(desc)}">${escapeHtml(desc)}</span>` : '')
            + `</span>`;

        window.mdSettingsSelectOption = function (el) {
            if (!el || THEME_SELECTS.indexOf(el.name) === -1) return null;
            return function (state) {
                //搜索框、分组标题等没有 id 的节点原样返回
                if (!state || !state.id) return state.text;
                const theme = themes[state.id];
                const label = escapeHtml(state.text);
                //「跟随PC模板」这类非主题项没有对应数据，只渲染文字
                if (!theme) return `<span class="md-theme-opt"><span class="md-theme-opt__name">${label}</span></span>`;

                let acts = `<button type="button" class="md-theme-opt__btn md-theme-opt__btn--cfg" data-theme-act="setting" data-theme-key="${escapeHtml(state.id)}" title="${i18n('模板配置')}">`
                    + `<i class="fa-duotone fa-regular fa-gear"></i><span>${i18n('模板配置')}</span></button>`;
                //正在被任一位置使用的模板不给卸载入口（服务端也会再拦一道）
                if (!themeInUse(state.id)) {
                    acts += `<button type="button" class="md-theme-opt__btn md-theme-opt__btn--del" data-theme-act="uninstall" data-theme-key="${escapeHtml(state.id)}" title="${i18n('卸载模板')}">`
                        + `<i class="fa-duotone fa-regular fa-trash-can"></i><span>${i18n('卸载')}</span></button>`;
                }
                if (theme.have_update === true) {
                    acts = `<button type="button" class="md-theme-opt__btn md-theme-opt__btn--up" data-theme-act="update" data-theme-key="${escapeHtml(state.id)}" title="${i18n('更新到')} v${escapeHtml(theme.update_version || '')}">`
                        + `<i class="fa-duotone fa-regular fa-arrow-up-from-bracket"></i> v${escapeHtml(theme.update_version || '')}</button>` + acts;
                }
                return `<span class="md-theme-opt">${themeIcon(theme, theme.info && theme.info.NAME)}`
                    + themeText(label, themeDesc(theme), themeAuthor(theme))
                    + `<span class="md-theme-opt__acts">${acts}</span></span>`;
            };
        };

        //选中后显示在输入框里的内容：带图标，但不要按钮
        window.mdSettingsSelectSelection = function (el) {
            if (!el || THEME_SELECTS.indexOf(el.name) === -1) return null;
            return function (state) {
                if (!state || !state.id) return state.text;
                const theme = themes[state.id];
                const label = escapeHtml(state.text);
                if (!theme) return `<span class="md-theme-sel">${label}</span>`;
                return `<span class="md-theme-sel">${themeIcon(theme, theme.info && theme.info.NAME)}`
                    + `<span class="md-theme-opt__name">${label}</span></span>`;
            };
        };

        //material.js 先于本控制器执行，它初始化 select2 时上面的钩子还不存在，
        //所以这里注册完必须让它重建一次，否则选项仍是原始文本、按钮出不来。
        if (typeof window.mdReinitSettingsSelect2 === 'function') {
            window.mdReinitSettingsSelect2(THEME_SELECTS);
        }

        /* ── 更新提示条 ─────────────────────────────────────────────
           模板有新版本时，只在下拉里露一个小徽章太容易被忽略——站长根本不会
           挨个展开下拉去看。这里在模板区顶部挂一条横幅，直接说清有几个待更新、
           分别是谁，并提供一键全部更新。 */
        function pendingThemes() {
            return _themes.filter(t => t && t.have_update === true && t.plugin_id);
        }

        function renderUpdateBanner() {
            $('.md-theme-alert').remove();
            const list = pendingThemes();
            if (!list.length) return;

            const chips = list.map(t => `<span class="md-theme-alert__chip">`
                + themeIcon(t, t.info && t.info.NAME)
                + `<span>${escapeHtml(t.info.NAME)}</span>`
                + `<i>v${escapeHtml(t.info.VERSION)} → v${escapeHtml(t.update_version || '')}</i></span>`).join('');

            const html = `<div class="md-theme-alert" role="status">
                <span class="md-theme-alert__glyph" aria-hidden="true"><i class="fa-duotone fa-regular fa-arrow-up-from-bracket"></i></span>
                <div class="md-theme-alert__body">
                    <p class="md-theme-alert__title">${i18n('有')} ${list.length} ${i18n('个模板可以更新')}</p>
                    <div class="md-theme-alert__chips">${chips}</div>
                </div>
                <button type="button" class="md-theme-alert__btn" data-theme-update-all>
                    <span class="md-theme-alert__btn-text">${i18n('全部更新')}</span>
                </button>
            </div>`;

            //挂在模板区标题之后、第一个模板行之前
            const $firstRow = $('select[name=user_theme]').closest('.row.mb-6');
            if ($firstRow.length) $firstRow.before(html);
        }

        //串行更新：接口会读写模板目录，并发跑容易互相踩到
        function updateAllThemes($btn) {
            const list = pendingThemes();
            if (!list.length) return;

            const $text = $btn.find('.md-theme-alert__btn-text');
            $btn.prop('disabled', true).addClass('is-busy');

            let index = 0;
            const failed = [];

            const step = () => {
                if (!controllerActive) return;
                if (index >= list.length) {
                    if (failed.length) {
                        $btn.prop('disabled', false).removeClass('is-busy');
                        $text.text(i18n('全部更新'));
                        message.error(i18n('以下模板更新失败：') + failed.join('、'));
                        return;
                    }
                    $text.text(i18n('更新完成，正在刷新…'));
                    window.location.reload();
                    return;
                }

                const theme = list[index];
                $text.text(`${i18n('正在更新')} ${theme.info.NAME}（${index + 1}/${list.length}）`);

                util.post('/admin/api/app/upgrade', {
                    plugin_key: theme.info.KEY,
                    type: theme.plugin_type,
                    plugin_id: theme.plugin_id
                }, res => {
                    if (res.code != 200) failed.push(theme.info.NAME);
                    index++;
                    step();
                }, () => {           //接口返回错误
                    failed.push(theme.info.NAME);
                    index++;
                    step();
                }, () => {           //网络失败
                    failed.push(theme.info.NAME);
                    index++;
                    step();
                });
            };
            step();
        }

        renderUpdateBanner();

        $(document).off('click' + namespace, '[data-theme-update-all]')
            .on('click' + namespace, '[data-theme-update-all]', function () {
                const $btn = $(this);
                if ($btn.prop('disabled')) return;
                const list = pendingThemes();
                message.ask(
                    `${i18n('将依次更新以下模板：')}<b class="text-primary">${escapeHtml(list.map(t => t.info.NAME).join('、'))}</b><br>`
                    + `<span class="text-muted">${i18n('更新过程中请不要关闭页面。')}</span>`,
                    () => updateAllThemes($btn),
                    `<b>${i18n('更新')} ${list.length} ${i18n('个模板')}</b>`,
                    i18n('开始更新')
                );
            });

        function runThemeAct(btn) {
            const key = btn.getAttribute('data-theme-key');
            const theme = themes[key];
            if (!theme) return;

            $('select[data-control="select2"]').select2('close');

            const act = btn.getAttribute('data-theme-act');

            if (act === 'setting') {
                modal(theme, i18n('模板设置'));
                return;
            }

            if (act === 'uninstall') {
                if (themeInUse(key)) {
                    layer.msg(i18n('该模板正在使用中，请先切换到其它模板再卸载'));
                    return;
                }
                message.ask(
                    `${i18n('你想要卸载')} <b class="text-danger">${escapeHtml(theme.info.NAME)}</b> ${i18n('吗，该操作会删除模板全部文件，且无法恢复，请慎重操作！')}`,
                    () => {
                        if (!controllerActive) return;
                        util.post('/admin/api/app/uninstall', {
                            plugin_key: theme.info.KEY,
                            type: 2
                        }, res => {
                            if (!controllerActive) return;
                            message.info(res.msg);
                            if (res.code == 200) window.location.reload();
                        });
                    }
                );
                return;
            }

            if (!theme.plugin_id) {
                layer.msg(i18n('缺少应用商店信息，请到应用商店手动更新'));
                return;
            }
            const content = escapeHtml(theme.update_content || i18n('该更新没有提供说明')).replace(/\n/g, '<br>');
            message.ask(content, () => {
                if (!controllerActive) return;
                util.post('/admin/api/app/upgrade', {
                    plugin_key: theme.info.KEY,
                    type: theme.plugin_type,
                    plugin_id: theme.plugin_id
                }, res => {
                    if (!controllerActive) return;
                    message.info(res.msg);
                    if (res.code == 200) window.location.reload();
                });
            }, `<b class="text-primary">${escapeHtml(theme.info.NAME)}</b> <span style="font-size:14px;">v${escapeHtml(theme.info.VERSION)}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span class="text-success" style="font-size:14px;">v${escapeHtml(theme.update_version || '')}</span>`, i18n('立即更新'));
        }

        /* select2 是在下拉容器 .select2-results 上监听 mouseup 来选中选项的，
           位置比 document 更靠内。若把拦截绑在 document 上（冒泡阶段），
           select2 早就先执行完了 —— 表现就是"点按钮 = 选中该项并关闭下拉"。
           所以这里用捕获阶段在 document 上拦截：捕获自顶向下，早于任何冒泡处理器。 */
        if (themeActCapture) {
            document.removeEventListener('mousedown', themeActCapture, true);
            document.removeEventListener('mouseup', themeActCapture, true);
            document.removeEventListener('click', themeActCapture, true);
        }
        themeActCapture = function (e) {
            const btn = e.target && e.target.closest ? e.target.closest('[data-theme-act]') : null;
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            //只在 click 阶段执行动作，mousedown/mouseup 仅用于阻断 select2
            if (e.type === 'click') runThemeAct(btn);
        };
        document.addEventListener('mousedown', themeActCapture, true);
        document.addEventListener('mouseup', themeActCapture, true);
        document.addEventListener('click', themeActCapture, true);
    }

    function _EntranceControl() {
        const $input = $('#data-form input[name="admin_entrance_secret"]');
        const $clear = $('#data-form input[name="admin_entrance_clear"]');
        const sync = () => {
            const clearing = $clear.prop('checked') === true;
            if (clearing) $input.val('');
            $input.prop('readonly', clearing).attr('aria-disabled', clearing ? 'true' : 'false');
        };
        $clear.off(namespace).on('change' + namespace, sync);
        $input.off(namespace).on('input' + namespace, function () {
            if (this.value !== '' && $clear.prop('checked')) {
                $clear.prop('checked', false);
                sync();
            }
        });
        sync();
    }


    function _Save() {
        $('#data-form').off(namespace).on('input' + namespace + ' change' + namespace, 'input, textarea, select', markFormDirty);
        $(window).off('beforeunload' + namespace).on('beforeunload' + namespace, event => {
            if (!formDirty) return;
            event.preventDefault();
            if (event.originalEvent) event.originalEvent.returnValue = '';
            return '';
        });
        const guardDesktopPjaxLeave = event => {
            if (!formDirty || mobileGuardEnabled()) return;
            if (window.confirm(i18n('当前网站设置还有未保存的修改，确定离开吗？'))) {
                formDirty = false;
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            return false;
        };
        $(document)
            .off('pjax:click' + namespace)
            .off('pjax:beforeSend' + namespace)
            .on('pjax:click' + namespace, guardDesktopPjaxLeave)
            .on('pjax:beforeSend' + namespace, guardDesktopPjaxLeave);
        const submitSettings = () => {
            if (saveInFlight || !controllerActive) return;
            if (noticeEditor && typeof noticeEditor.getHTML === 'function') noticeEditor.getHTML();
            const revision = formRevision();
            const localRevision = dirtyRevision;
            const entranceInput = document.querySelector('#data-form input[name="admin_entrance_secret"]');
            const entranceClear = document.querySelector('#data-form input[name="admin_entrance_clear"]');
            const submittedEntrance = entranceInput?.value ?? '';
            const submittedEntranceClear = entranceClear?.checked === true;
            const payload = util.arrayToObject($("#data-form").serializeArray());
            saveInFlight = true;
            setMainSaveBusy(true);
            util.post({
                url: "/admin/api/config/setting",
                data: payload,
                done: res => {
                    if (!controllerActive) return;
                    saveInFlight = false;
                    setMainSaveBusy(false);
                    if (dirtyRevision === localRevision) formDirty = false;
                    if (entranceInput?.isConnected && entranceInput.value === submittedEntrance) entranceInput.value = '';
                    if (entranceClear?.isConnected && entranceClear.checked === submittedEntranceClear) {
                        entranceClear.checked = false;
                        if (entranceInput?.isConnected) {
                            entranceInput.removeAttribute('readonly');
                            entranceInput.removeAttribute('aria-disabled');
                        }
                    }
                    layer.msg(res.msg);
                    emitFormState('admin:mobile:form-saved', revision);
                },
                error: res => {
                    if (!controllerActive) return;
                    saveInFlight = false;
                    setMainSaveBusy(false);
                    if (mobileGuardEnabled()) window.AdminMobile?.pageWorkflows?.focusFormError?.(formElement(), res?.msg);
                    message.error(res?.msg || '网站设置保存失败');
                },
                fail: () => {
                    if (!controllerActive) return;
                    saveInFlight = false;
                    setMainSaveBusy(false);
                    message.error('网络异常，网站设置未保存');
                }
            });
        };
        $('.save-data').off(namespace).on('click' + namespace, function () {
            if (saveInFlight || !controllerActive) return;
            const entranceClear = document.querySelector('#data-form input[name="admin_entrance_clear"]');
            if (entranceClear?.checked === true) {
                message.ask(
                    '关闭后，后台安全入口将立即失效。请确认这不是为保存其他设置而误勾选。',
                    submitSettings,
                    '确认关闭后台安全入口？',
                    '确认关闭并保存'
                );
                return;
            }
            submitSettings();
        });
    }

    _NoticeEditor();
    _UploadLogoAndBackground();
    _ThemeSetting();
    _EntranceControl();
    _Save();
    formReady = true;
}();
