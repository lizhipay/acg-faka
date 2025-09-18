!function () {

    const table = new Table("/admin/api/log/data", "#manage-log-table");
    let risk = ['<span class="badge badge-light-success">无风险</span>', '<span class="badge badge-light-danger">风险较高</span>'];

    table.setColumns([
        {
            field: 'manage', title: "管理员", formatter: function (val, item) {
                return '<span class="badge badge-light">' + item.nickname + '(' + item.email + ')' + '</span>';
            }
        }
        , {
            field: 'content', title: '日志'
        }
        , {
            field: 'create_time', title: '时间'
        }
        , {
            field: 'create_ip', title: 'IP'
        }
        , {
            field: 'ua', title: '浏览器'
        }
        , {
            field: 'risk', title: '评估', formatter: function (val, item) {
                return risk[item.risk];
            }
        }
    ]);
    table.setPagination(15, [15, 30, 50]);

    table.setSearch([
        {title: "Email", name: "equal-email", type: "input"},
        {title: "呢称", name: "equal-nickname", type: "input"},
        {title: "IP地址", name: "equal-create_ip", type: "input"},
        {title: "搜索日志", name: "search-content", type: "input"},
        {title: "操作时间", name: "between-create_time", type: "date"}
    ]);

    table.setState("risk", [
        {id: 0, name: "无风险"},
        {id: 1, name: "风险较高"},
    ]);

    table.render();
}();