!function () {
    const namespace = '.mdConfigOtherController';
    let controllerActive = true;
    let saveInFlight = false;
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
                layer.msg(res?.msg || '显示状态已更新');
                table.refresh();
            },
            error: res => {
                displayPending.delete(key);
                if (controllerActive) message.error(res?.msg || '主站显示状态更新失败');
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
                    html += '<span class="badge badge-light-success">已显示</span>';
                } else {
                    html += '<span class="badge badge-light-danger">已隐藏</span>';
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

    $('#data-form').off(namespace).on('input' + namespace + ' change' + namespace, 'input, textarea, select', function () {
        emitFormState('admin:mobile:form-dirty');
    });

    $('.save-data').off(namespace).on('click' + namespace, function () {
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
                layer.msg(res.msg || '保存成功');
                emitFormState('admin:mobile:form-saved', revision);
            },
            error: res => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                if (window.AdminMobile?.isEnabled?.()) window.AdminMobile?.pageWorkflows?.focusFormError?.(document.getElementById('data-form'), res?.msg);
                message.error(res?.msg || '其他设置保存失败');
            },
            fail: () => {
                if (!controllerActive) return;
                saveInFlight = false;
                setSaveBusy(false);
                message.error('网络异常，其他设置未保存');
            }
        });
    });

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
