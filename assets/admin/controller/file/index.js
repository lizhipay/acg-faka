/**
 * 文件管理（acg_upload）：列表 / 上传 / 下载 / 复制链接 / 编辑备注 / 删除。
 * 复用后台通用组件 Table / component.popup / util / message / layer。
 */
!function () {

    function formatSize(bytes) {
        bytes = parseInt(bytes) || 0;
        if (bytes <= 0) return '<span class="text-danger">已丢失</span>';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function fileIcon(type) {
        return ({ image: 'fa-file-image', video: 'fa-file-video', doc: 'fa-file-lines', other: 'fa-file' })[type] || 'fa-file';
    }

    function fullUrl(path) {
        return window.location.origin + path;
    }

    const table = new Table("/admin/api/file/data", "#file-table");

    table.setColumns([
        { checkbox: true },
        {
            field: 'note', title: '文件', formatter: function (v, row) {
                const name = (row.path || '').split('/').pop();
                const note = v ? '<span class="md-file__note">' + v + '</span>' : '';
                const previewable = row.type === 'image' && row.exists;
                const thumb = previewable
                    ? '<img src="' + (row.thumb_url || row.path) + '" class="md-file__thumb" alt="">'
                    : '<span class="md-file__thumb md-file__thumb--icon"><i class="fa-duotone fa-regular ' + fileIcon(row.type) + '"></i></span>';
                return '<div class="md-file' + (previewable ? ' md-file--previewable' : '') + '">' + thumb +
                    '<div class="md-file__text"><span class="md-file__name" title="' + row.path + '">' + name + '</span>' + note + '</div></div>';
            },
            events: {
                // 双击可预览的图片单元 → 放大预览
                'dblclick .md-file--previewable': function (event, value, row) {
                    layer.open({
                        type: 1, title: false, closeBtn: 0, anim: 5, area: 'auto', shadeClose: true,
                        content: '<img src="' + row.path + '" style="display:block;width:auto;max-width:90vw;max-height:90vh;">'
                    });
                }
            }
        },
        { field: 'type', title: '类型', formatter: function (v) { return '<span class="badge badge-light-info">' + v + '</span>'; } },
        { field: 'size', title: '大小', sort: true, formatter: function (v) { return formatSize(v); } },
        {
            field: 'user_id', title: '归属', formatter: function (v, row) {
                return v
                    ? '<span class="a-badge a-badge-primary">' + (row.user ? row.user.username : ('用户#' + v)) + '</span>'
                    : '<span class="a-badge a-badge-secondary">后台</span>';
            }
        },
        { field: 'create_time', title: '上传时间' },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-download', class: 'text-success',
                    click: function (event, value, row) { window.open('/admin/api/file/download?id=' + row.id); }
                },
                {
                    icon: 'fa-duotone fa-regular fa-link', class: 'text-primary',
                    click: function (event, value, row) {
                        util.copyTextToClipboard(fullUrl(row.path), function () { message.success('链接已复制到剪贴板'); });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square', class: 'text-warning',
                    click: function (event, value, row) {
                        component.popup({
                            title: util.icon('fa-duotone fa-regular fa-pen-to-square me-1') + '编辑备注',
                            width: '440px', height: 'auto', autoPosition: true,
                            tab: [{ name: '编辑备注', form: [{ title: '备注', name: 'note', type: 'input', placeholder: '最多 32 字，留空则清除' }] }],
                            assign: { note: row.note || '' },
                            submit: function (data, index) {
                                util.post('/admin/api/file/note', { id: row.id, note: data.note }, function (res) {
                                    message.success(res.msg);
                                    layer.close(index);
                                    table.refresh();
                                });
                            }
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: function (event, value, row) {
                        message.ask('删除后文件将从磁盘和数据库永久移除，无法恢复！', function () {
                            util.post('/admin/api/file/del', { list: [row.id] }, function (res) {
                                message.success(res.msg);
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        }
    ]);

    table.setPagination(15, [15, 30, 50, 100]);

    table.setSearch([
        {
            title: '类型', name: 'equal-type', type: 'select', dict: [
                { id: 'image', name: '图片' },
                { id: 'video', name: '视频 / 音频' },
                { id: 'doc', name: '文档' },
                { id: 'other', name: '其他' }
            ]
        },
        { title: '文件路径', name: 'search-path', type: 'input' },
        { title: '备注', name: 'search-note', type: 'input' },
        { title: '上传时间', name: 'between-create_time', type: 'date' }
    ]);

    table.render();

    // 工具栏：上传（隐藏 input，按钮触发；bindButtonUpload 会把文件以 file 字段 POST 到接口）
    util.bindButtonUpload('#file-upload-input', '/admin/api/file/upload', function (res) {
        message.success('上传成功');
        table.refresh();
    });
    $('.file-upload-btn').click(function () { $('#file-upload-input').click(); });

    // 工具栏：批量删除
    $('.file-batch-del').click(function () {
        const ids = table.getSelectionIds();
        if (!ids.length) { layer.msg('请至少勾选一个文件'); return; }
        message.ask('确认删除选中的 ' + ids.length + ' 个文件？删除后不可恢复！', function () {
            util.post('/admin/api/file/del', { list: ids }, function (res) {
                message.success(res.msg);
                table.refresh();
            });
        });
    });
}();
