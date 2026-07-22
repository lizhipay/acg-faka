!function () {
    const namespace = '.mdManageLogController';
    let controllerActive = true;
    let table;

    if (typeof window.__mdManageLogDestroy === 'function') window.__mdManageLogDestroy();

    table = new Table("/admin/api/log/data", "#manage-log-table");
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    let risk = ['<span class="badge badge-light-success">无风险</span>', '<span class="badge badge-light-danger">风险较高</span>'];

    table.setColumns([
        {
            field: 'manage', title: "管理员", formatter: function (val, item) {
                return '<div class="md-user-cell__text"><span class="md-user-cell__name">' + escapeHtml(item.nickname) + '</span><span class="md-user-cell__sub">' + escapeHtml(item.email) + '</span></div>';
            }
        }
        , {
            field: 'content', title: '日志', formatter: value => escapeHtml(value)
        }
        , {
            field: 'create_time', title: '时间', formatter: value => escapeHtml(value)
        }
        , {
            field: 'create_ip', title: 'IP', formatter: value => escapeHtml(value)
        }
        , {
            field: 'ua', title: '浏览器', formatter: value => escapeHtml(value)
        }
        , {
            field: 'risk', title: '评估', formatter: function (val, item) {
                return risk[Number(item.risk)] || '<span class="badge badge-light-secondary">未知</span>';
            }
        }
    ]);
    table.setPagination(15, [15, 30, 50]);

    table.setSearch([
        {title: "Email", name: "equal-email", type: "input"},
        {title: "昵称", name: "equal-nickname", type: "input"},
        {title: "IP地址", name: "equal-create_ip", type: "input"},
        {title: "搜索日志", name: "search-content", type: "input"},
        {title: "操作时间", name: "between-create_time", type: "date"}
    ]);

    table.setState("risk", [
        {id: 0, name: "无风险"},
        {id: 1, name: "风险较高"},
    ]);

    table.render();

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdManageLogDestroy === destroy) delete window.__mdManageLogDestroy;
    }

    window.__mdManageLogDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
