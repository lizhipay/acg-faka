!function () {
    let _substation_display_list = JSON.parse(getVar("_substation_display_list"));
    util.isEmptyOrNotJson(_substation_display_list) && (_substation_display_list = []);



    const table = new Table("/admin/api/config/getBusiness", "#substation_display_list");

    table.setColumns([
        {
            field: 'user', title: '商家', formatter: format.user
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
                if (_substation_display_list.indexOf(item.user.id) != -1) {
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
                    show: item => _substation_display_list.indexOf(item.user.id) != -1,
                    click: (event, value, row, index) => {
                        util.post("/admin/api/config/setSubstationDisplayList", {
                            id: row.user.id,
                            type: 1
                        }, res => {
                            _substation_display_list = res.data;
                            layer.msg(res.msg);
                            table.refresh();
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-eye',
                    class: 'text-primary',
                    show: item => _substation_display_list.indexOf(item.user.id) == -1,
                    click: (event, value, row, index) => {
                        util.post("/admin/api/config/setSubstationDisplayList", {
                            id: row.user.id,
                            type: 0
                        }, res => {
                            _substation_display_list = res.data;
                            layer.msg(res.msg);
                            table.refresh();
                        });
                    }
                }
            ]
        },
    ]);

    table.render();


    $('.save-data').click(function () {
        util.post("/admin/api/config/other", util.arrayToObject($("#data-form").serializeArray()), res => {
            layer.msg(res.msg);
        });
    });
}();