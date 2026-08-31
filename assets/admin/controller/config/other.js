!function () {
    const namespace = '.mdConfigOtherController';
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    let controllerActive = true;
    let saveInFlight = false;
    //常用货币预设：仅前端联动回填（下拉无 name 不随表单提交），服务端只做格式校验，自定义随意
    const CURRENCY_PRESETS = [
        {code: 'CNY', symbol: '¥', decimals: '2', name: '人民币'},
        {code: 'USD', symbol: '$', decimals: '2', name: '美元'},
        {code: 'EUR', symbol: '€', decimals: '2', name: '欧元'},
        {code: 'JPY', symbol: '円', decimals: '0', name: '日元'},
        {code: 'GBP', symbol: '£', decimals: '2', name: '英镑'},
        {code: 'HKD', symbol: 'HK$', decimals: '2', name: '港币'},
        {code: 'TWD', symbol: 'NT$', decimals: '2', name: '新台币'},
        {code: 'KRW', symbol: '₩', decimals: '0', name: '韩元'},
        {code: 'SGD', symbol: 'S$', decimals: '2', name: '新加坡元'},
        {code: 'MYR', symbol: 'RM', decimals: '2', name: '马来西亚林吉特'},
        {code: 'THB', symbol: '฿', decimals: '2', name: '泰铢'},
        {code: 'VND', symbol: '₫', decimals: '0', name: '越南盾'},
        {code: 'IDR', symbol: 'Rp', decimals: '0', name: '印尼盾'},
        {code: 'PHP', symbol: '₱', decimals: '2', name: '菲律宾比索'},
        {code: 'INR', symbol: '₹', decimals: '2', name: '印度卢比'},
        {code: 'RUB', symbol: '₽', decimals: '2', name: '俄罗斯卢布'},
        {code: 'AUD', symbol: 'A$', decimals: '2', name: '澳元'},
        {code: 'CAD', symbol: 'C$', decimals: '2', name: '加元'},
        {code: 'BRL', symbol: 'R$', decimals: '2', name: '巴西雷亚尔'},
        {code: 'TRY', symbol: '₺', decimals: '2', name: '土耳其里拉'}
    ];
    const initialCurrencyCode = String($('#data-form input[name="currency_code"]').val() || 'CNY').trim().toUpperCase();
    let _substation_display_list = [];
    let substationDisplaySet = new Set();
    const displayPending = new Set();
    try {
        const parsedList = JSON.parse(document.getElementById('md-config-substation-source')?.value || '[]');
        if (Array.isArray(parsedList)) _substation_display_list = parsedList;
    } catch (error) {}
    util.isEmptyOrNotJson(_substation_display_list) && (_substation_display_list = []);
    const setSubstationDisplayList = value => {
        _substation_display_list = Array.isArray(value) ? value : [];
        substationDisplaySet = new Set(_substation_display_list.map(id => String(id)));
    };
    setSubstationDisplayList(_substation_display_list);

    if (typeof window.__mdConfigOtherDestroy === 'function') window.__mdConfigOtherDestroy();

    function formRevision() {
        const form = document.getElementById('data-form');
        return form && window.AdminMobile?.pageWorkflows?.getRevision ? window.AdminMobile.pageWorkflows.getRevision(form) : null;
    }

    function emitFormState(name, revision) {
        const form = document.getElementById('data-form');
        if (form) document.dispatchEvent(new CustomEvent(name, {detail: {form: form, revision: revision}}));
    }

    function setSaveBusy(busy) {
        const $button = $('#data-form .save-data');
        $button.prop('disabled', busy).toggleClass('disabled', busy);
        if (busy) {
            $button.attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        } else {
            $button.removeAttr('aria-busy aria-disabled');
        }
    }

    function syncCallbackIpRules() {
        const toggle = document.getElementById('callback-ip-whitelist');
        const row = document.getElementById('callback-ip-whitelist-rules-row');
        const rules = document.getElementById('callback-ip-whitelist-rules');
        if (!toggle || !row || !rules) return;
        const enabled = toggle.checked;
        row.hidden = !enabled;
        row.setAttribute('aria-hidden', enabled ? 'false' : 'true');
        toggle.setAttribute('aria-expanded', enabled ? 'true' : 'false');
        rules.required = enabled;
    }

    function updateSubstationVisibility(row, type) {
        const id = row?.user?.id;
        const key = String(id ?? '');
        if (!key || displayPending.has(key) || !controllerActive) return;
        displayPending.add(key);
        util.post({
            url: "/admin/api/config/setSubstationDisplayList",
            data: {id: id, type: type},
            done: res => {
                displayPending.delete(key);
                if (!controllerActive) return;
                setSubstationDisplayList(res?.data);
                layer.msg(res?.msg || i18n('显示状态已更新'));
                table.refresh();
            },
            error: res => {
                displayPending.delete(key);
                if (controllerActive) message.error(res?.msg || i18n('主站显示状态更新失败'));
            },
            fail: () => {
                displayPending.delete(key);
                if (controllerActive) message.error('网络异常，主站显示状态未更新');
            }
        });
    }


    const table = new Table("/admin/api/config/getBusiness", "#substation_display_list");

    table.setColumns([
        {
            field: 'user', title: '商家', formatter: (item) => mdUserCell(item)
        },
        {
            field: 'shop_name', title: '店铺名称'
        },
        {
            field: 'subdomain', title: '子域名'
        },
        {
            field: 'topdomain', title: '独立域名'
        },
        {
            field: 'business_level', title: '店铺等级', formatter: format.group
        },
        {
            field: 'status', title: '主站显示', formatter: function (val, item) {
                let html = '';
                if (substationDisplaySet.has(String(item?.user?.id ?? ''))) {
                    html += '<span class="badge badge-light-success">' + i18n('已显示') + '</span>';
                } else {
                    html += '<span class="badge badge-light-danger">' + i18n('已隐藏') + '</span>';
                }
                return html;
            }
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-eye-slash',
                    class: "text-danger",
                    show: item => substationDisplaySet.has(String(item?.user?.id ?? '')),
                    click: (event, value, row, index) => {
                        updateSubstationVisibility(row, 1);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-eye',
                    class: 'text-primary',
                    show: item => !substationDisplaySet.has(String(item?.user?.id ?? '')),
                    click: (event, value, row, index) => {
                        updateSubstationVisibility(row, 0);
                    }
                }
            ]
        },
    ]);

    table.render();

    function syncCurrencyPresetSelection() {
        const $preset = $('#currency-preset');
        if (!$preset.length) return;
        const code = String($('#data-form input[name="currency_code"]').val() || '').trim().toUpperCase();
        const matched = CURRENCY_PRESETS.some(p => p.code === code);
        //change.select2 只刷新下拉自身的显示，不惊动业务 change（否则会反过来重填代码框）
        $preset.val(matched ? code : 'custom').trigger('change.select2');
    }

    function initCurrencyPreset() {
        const $preset = $('#currency-preset');
        if (!$preset.length) return;
        $preset.empty();
        CURRENCY_PRESETS.forEach(p => {
            $preset.append($('<option></option>').attr('value', p.code).text(i18n(p.name) + ' (' + p.code + ' ' + p.symbol + ')'));
        });
        $preset.append($('<option value="custom"></option>').text(i18n('自定义…')));
        syncCurrencyPresetSelection();
        $preset.off(namespace).on('change' + namespace, function () {
            const preset = CURRENCY_PRESETS.find(p => p.code === this.value);
            if (!preset) return; //选「自定义」时不动现有输入，让站长自己填
            $('#data-form input[name="currency_code"]').val(preset.code);
            $('#data-form input[name="currency_symbol"]').val(preset.symbol);
            $('#data-form select[name="currency_decimals"]').val(preset.decimals).trigger('change');
            //汇率必须是站长自己查的实时值，预设绝不代填——CNY 是基准币恒为 1，
            //其它币种清空汇率框并聚焦，防止拿旧币种的汇率去换算（因子=1 的坑）
            const $rate = $('#data-form input[name="currency_rate"]');
            if (preset.code === 'CNY') {
                $rate.val('1').trigger('input');
            } else {
                $rate.val('').trigger('input').trigger('focus');
            }
        });
    }
    initCurrencyPreset();

    $('#data-form')
        .off(namespace)
        .on('input' + namespace + ' change' + namespace, 'input, textarea, select', function () {
            emitFormState('admin:mobile:form-dirty');
        })
        .on('change' + namespace, 'input[name="callback_ip_whitelist"]', syncCallbackIpRules)
        .on('input' + namespace, 'input[name="currency_code"]', syncCurrencyPresetSelection);
    syncCallbackIpRules();

    //全站数据换算：切币种 + 按汇率一次性改写全站金额（余额/账单/历史订单/商品定价…），不可逆
    let convertInFlight = false;
    $('#currency-convert-btn').off(namespace).on('click' + namespace, function () {
        if (!controllerActive || convertInFlight) return;
        const $btn = $(this);
        const fromCode = String($btn.data('current-code') || 'CNY').toUpperCase();
        const fromRate = parseFloat($btn.data('current-rate')) || 1;
        const toCode = String($('#data-form input[name="currency_code"]').val() || '').trim().toUpperCase();
        const toSymbol = String($('#data-form input[name="currency_symbol"]').val() || '').trim();
        const toRate = parseFloat($('#data-form input[name="currency_rate"]').val());
        const toDecimals = String($('#data-form select[name="currency_decimals"]').val() || '2');

        if (!toCode || !toSymbol || !Number.isFinite(toRate) || toRate <= 0) {
            message.alert(i18n('请先在上方填好目标货币的代码、符号与汇率'), 'warning');
            return;
        }
        if (toCode === fromCode) {
            message.alert(i18n('目标币种与当前币种相同，无需换算；只调汇率不改数据直接保存设置即可'), 'warning');
            return;
        }
        if (Math.abs(fromRate / toRate - 1) < 1e-9) {
            message.alert(i18n('目标汇率与当前汇率相同，换算不会改变任何数字；请先把汇率改成新币种的真实汇率'), 'warning');
            return;
        }

        const factor = fromRate / toRate;
        const factorText = factor.toFixed(6).replace(/0+$/, '').replace(/\.$/, '');
        const sample = (100 * factor).toFixed(2);

        message.ask(
            `<div style="text-align:left;line-height:1.8;">
                <div><b>${i18n('站点货币')} ${escapeHtml(fromCode)} → ${escapeHtml(toCode)}${i18n('，并换算全站金额')}</b></div>
                <div style="margin-top:8px;">${i18n('换算因子')} = ${fromRate} ÷ ${toRate} = <b>${escapeHtml(factorText)}</b>（${i18n('示例')}: 100 ${escapeHtml(fromCode)} → ${sample} ${escapeHtml(toCode)}）</div>
                <div style="margin-top:8px;">${i18n('将改写：会员余额与硬币、账单、历史订单与充值单、提现记录、商品定价（含种类/批发/SKU/成本与会员等级定制价）、卡密溢价、固定金额的优惠券与手续费、加价模板固定值、充值与提现阈值、充值赠送规则。')}</div>
                <div style="margin-top:8px;" class="text-muted">${i18n('百分比类配置与已提交网关的 CNY 快照不受影响；在途未支付订单仍按原快照正常回调。')}</div>
                <div style="margin-top:8px;color:#d14343;"><b>${i18n('本操作不可逆，请先备份数据库再继续。')}</b></div>
            </div>`,
            () => {
                message.dangerPrompt(
                    i18n('将按汇率一次性改写全站所有金额数据（含历史订单），此操作无法撤销。'),
                    toCode,
                    /* 执行换算 */
                    () => {
                        convertInFlight = true;
                        $btn.prop('disabled', true).addClass('disabled');
                        util.post({
                            url: '/admin/api/config/currencyConvert',
                            data: {
                                currency_code: toCode,
                                currency_symbol: toSymbol,
                                currency_rate: $('#data-form input[name="currency_rate"]').val(),
                                currency_decimals: toDecimals
                            },
                            done: res => {
                                const summary = res?.data?.summary || {};
                                const rows = Object.keys(summary)
                                    .map(k => `<tr><td style="padding:2px 14px 2px 0;color:#64748b;">${escapeHtml(k)}</td><td style="text-align:right;"><b>${Number(summary[k]) || 0}</b></td></tr>`)
                                    .join('');
                                Swal.fire({
                                    title: i18n('换算完成'),
                                    icon: 'success',
                                    html: `<div style="text-align:left;line-height:1.7;">
                                        <div>${i18n('共改写')} <b>${Number(res?.data?.total) || 0}</b> ${i18n('行数据，站点货币已切换为')} <b>${escapeHtml(toCode)}</b>。</div>
                                        <table style="margin-top:10px;font-size:13px;">${rows}</table>
                                    </div>`,
                                    confirmButtonText: i18n('好的')
                                }).then(() => location.reload());
                            },
                            error: res => {
                                convertInFlight = false;
                                $btn.prop('disabled', false).removeClass('disabled');
                                message.error(res?.msg || i18n('换算失败，数据未改动'));
                            },
                            fail: () => {
                                convertInFlight = false;
                                $btn.prop('disabled', false).removeClass('disabled');
                                message.error(i18n('网络异常，请先核对数据是否已换算，不要盲目重试'));
                            }
                        });
                    }
                );
            },
            i18n('切换并换算全站金额？'),
            i18n('下一步'),
            //备份确认环节：不勾「我已备份好数据库」就点不了下一步
            {
                input: 'checkbox',
                inputPlaceholder: i18n('我已备份好数据库'),
                inputValidator: value => value ? undefined : i18n('请先备份数据库，再勾选确认')
            }
        );
    });

    $('.save-data').off(namespace).on('click' + namespace, function () {
        if (!controllerActive || saveInFlight) return;
        //切换币种前二次确认：纯重标注，库里数字不换算
        const nextCurrencyCode = String($('#data-form input[name="currency_code"]').val() || '').trim().toUpperCase();
        if (nextCurrencyCode && nextCurrencyCode !== initialCurrencyCode) {
            message.ask(
                `<div style="text-align:left;line-height:1.8;">
                    <div><b>${i18n('站点货币')} ${escapeHtml(initialCurrencyCode)} → ${escapeHtml(nextCurrencyCode)}</b></div>
                    <div style="margin-top:8px;">${i18n('这是纯重标注：商品价格、余额、历史订单里的数字一个都不会换算，只是换个符号按新货币理解。')}</div>
                    <div style="margin-top:8px;color:#d14343;">${i18n('切换后请自行按新币种调整商品定价。')}</div>
                    <div style="margin-top:8px;" class="text-muted">${i18n('汇率只用于提交支付时把订单金额换算成人民币，不影响已下单的订单。')}</div>
                </div>`,
                () => submitOther(),
                i18n('确认切换站点货币？'),
                i18n('确认切换')
            );
            return;
        }
        submitOther();
    });

    function submitOther() {
        if (!controllerActive || saveInFlight) return;
        const revision = formRevision();
        saveInFlight = true;
        setSaveBusy(true);
        util.post({
            url: "/admin/api/config/other",
            data: util.arrayToObject($("#data-form").serializeArray()),
            done: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                layer.msg(res.msg || i18n('保存成功'));
                emitFormState('admin:mobile:form-saved', revision);
            },
            error: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                if (window.AdminMobile?.isEnabled?.()) window.AdminMobile?.pageWorkflows?.focusFormError?.(document.getElementById('data-form'), res?.msg);
                message.error(res?.msg || i18n('其他设置保存失败'));
            },
            fail: () => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                message.error('网络异常，其他设置未保存');
            }
        });
    }

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        saveInFlight = false;
        displayPending.clear();
        setSaveBusy(false);
        $('#data-form, .save-data').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && typeof table.destroy === 'function') table.destroy();
        if (window.__mdConfigOtherDestroy === destroy) delete window.__mdConfigOtherDestroy;
    }

    window.__mdConfigOtherDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
