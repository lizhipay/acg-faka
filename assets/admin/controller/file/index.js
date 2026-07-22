/**
 * 文件管理（acg_upload）：列表 / 上传 / 下载 / 复制链接 / 编辑备注 / 删除。
 * 复用后台通用组件 Table / component.popup / util / message / layer。
 */
!function () {
    const namespace = '.mdFileController';
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const controllerLayers = new Set();
    let controllerActive = true;
    let deletePreviewPending = false;
    let deletePending = false;
    let table;
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const openControllerLayer = options => {
        const originalEnd = options.end;
        let index;
        try {
            index = layer.open({
                ...options,
                end: function () {
                    controllerLayers.delete(index);
                    if (typeof originalEnd === 'function') return originalEnd.apply(this, arguments);
                }
            });
        } catch (error) {
            if (typeof originalEnd === 'function') originalEnd();
            throw error;
        }
        if (controllerActive) controllerLayers.add(index); else layer.close(index);
        return index;
    };
    if (typeof window.__mdFileDestroy === 'function') window.__mdFileDestroy();

    function formatSize(bytes, exists) {
        bytes = parseInt(bytes) || 0;
        if (!exists) return '<span class="text-danger">已丢失</span>';
        if (bytes <= 0) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function fileIcon(type) {
        return ({ image: 'fa-file-image', message: 'fa-file-image', ticket: 'fa-file-image', video: 'fa-file-video', doc: 'fa-file-lines', other: 'fa-file' })[type] || 'fa-file';
    }

    function fileTypeLabel(type) {
        return ({
            image: '图片',
            message: '消息图片',
            ticket: '工单图片',
            video: '视频 / 音频',
            doc: '文档',
            other: '其他'
        })[type] || '其他';
    }

    function safePublicUrl(path) {
        try {
            const url = new URL(String(path || ''), window.location.origin);
            const allowed = url.pathname.startsWith('/assets/cache/general/')
                || url.pathname.startsWith('/assets/cache/user/');
            if (url.origin !== window.location.origin || !allowed) return '';
            return url.href;
        } catch (_) {
            return '';
        }
    }

    function safeDownloadUrl(path) {
        const value = String(path || '');
        return /^\/admin\/api\/file\/download\?id=[1-9]\d*$/.test(value) ? value : '';
    }

    function deleteFiles(ids) {
        if (deletePending || !controllerActive) return;
        deletePending = true;
        util.post({
            url: '/admin/api/file/del',
            data: {list: ids},
            done: function (res) {
                deletePending = false;
                if (!controllerActive) return;
                const cleanupComplete = res?.data?.cleanup_complete !== false;
                if (cleanupComplete) {
                    message.success(res?.msg || '文件已删除');
                } else {
                    message.alert(escapeHtml(res?.msg || '文件记录已删除，但隔离文件仍待运维清理'), 'warning');
                }
                table.refresh();
            },
            error: function (res) {
                deletePending = false;
                if (controllerActive) message.alert(escapeHtml(res?.msg || '文件删除已被阻止'), 'warning');
            },
            fail: function () {
                deletePending = false;
                if (controllerActive) message.error('网络异常，未执行文件删除');
            }
        });
    }

    function confirmFileDelete(ids) {
        if (deletePreviewPending || deletePending || !controllerActive) return;
        deletePreviewPending = true;
        util.post({
            url: '/admin/api/file/deleteImpact',
            data: {list: ids},
            done: function (res) {
                deletePreviewPending = false;
                if (!controllerActive) return;
                const impact = res?.data || {};
                const fileCount = Number(impact.file_count || 0);
                const referenceCount = Number(impact.reference_count || 0);
                const details = `数据库记录 ${fileCount} 个；磁盘文件 ${Number(impact.existing_file_count || 0)} 个；缩略图 ${Number(impact.thumbnail_count || 0)} 个；已丢失文件 ${Number(impact.missing_file_count || 0)} 个。`;
                if (impact.can_delete !== true) {
                    message.alert(
                        `<div style="text-align:left;line-height:1.8;">
                            <div><b>删除预检未通过。</b></div>
                            <div style="margin-top:8px;">${details}</div>
                            <div>不存在记录 ${Number(impact.missing_record_count || 0)} 个；安全路径 ${Number(impact.safe_path_count || 0)} 个；受保护路径 ${Number(impact.protected_count || 0)} 个。</div>
                            <div>可靠识别的业务引用 ${referenceCount} 条，其中工单凭证 ${Number(impact.ticket_reference_count || 0)} 条、同路径上传记录 ${Number(impact.upload_path_reference_count || 0)} 条。</div>
                            <div>工单正文图片 ${Number(impact.ticket_message_reference_count || 0)} 条、系统消息正文图片 ${Number(impact.system_message_reference_count || 0)} 条。</div>
                            <div style="margin-top:8px;color:#d14343;">系统已整批阻止删除。请先解除业务引用；危险路径不会被删除。</div>
                            <div style="margin-top:8px;color:#64748b;font-size:12px;">${escapeHtml(impact.reference_scope || '')}</div>
                        </div>`,
                        'warning'
                    );
                    return;
                }
                message.ask(
                    `<div style="text-align:left;line-height:1.8;">
                        <div><b>将永久删除 ${fileCount} 个文件记录。</b></div>
                        <div style="margin-top:8px;">${details}</div>
                        <div>业务引用 ${referenceCount} 条；所有路径均已通过上传目录边界检查。</div>
                        <div style="margin-top:8px;color:#d14343;">文件会先原子移入非公开隔离区，数据库提交后再清理；操作无法恢复。</div>
                    </div>`,
                    function () { deleteFiles(ids); },
                    '确认永久删除文件？',
                    '确认删除'
                );
            },
            error: function (res) {
                deletePreviewPending = false;
                if (controllerActive) message.alert(escapeHtml(res?.msg || '无法计算删除影响，已阻止删除'), 'warning');
            },
            fail: function () {
                deletePreviewPending = false;
                if (controllerActive) message.error('网络异常，无法预览删除影响，已阻止删除');
            }
        });
    }

    function openImagePreview(row) {
        const previewUrl = row && safePublicUrl(row.url);
        if (!row || row.previewable !== true || !row.exists || !previewUrl) return false;
        const mobile = mobileAdminEnabled();
        const image = document.createElement('img');
        image.src = previewUrl;
        image.alt = row.name || '图片预览';
        image.style.cssText = mobile
            ? 'display:block;width:100%;height:100%;object-fit:contain;background:#0f1419;'
            : 'display:block;width:auto;max-width:90vw;max-height:90vh;';
        // Layer 的 DOM 内容模式要求节点已经挂载；否则只会留下遮罩而看不到预览。
        image.style.display = 'none';
        document.body.appendChild(image);
        openControllerLayer({
            type: 1,
            title: mobile ? '图片预览' : false,
            closeBtn: mobile ? 1 : 0,
            anim: mobile ? 2 : 5,
            area: mobile ? ['100%', '100%'] : 'auto',
            skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task md-file-preview-layer' : 'md-file-preview-layer',
            shadeClose: true,
            maxmin: false,
            resize: !mobile,
            move: !mobile,
            content: $(image),
            success: function (layero) {
                image.style.display = 'block';
                layero.attr('role', 'dialog').attr('aria-label', '图片预览');
            },
            end: function () {
                image.remove();
            }
        });
        return true;
    }

    table = new Table("/admin/api/file/data", "#file-table");

    table.setColumns([
        { checkbox: true },
        {
            field: 'note', title: '文件', formatter: function (v, row) {
                const name = escapeHtml(row.name || '未知文件');
                const path = escapeHtml(row.path || '路径不安全，已受保护');
                const note = v ? '<span class="md-file__note">' + escapeHtml(v) + '</span>' : '';
                const previewUrl = safePublicUrl(row.thumb_url || row.url);
                const previewable = row.previewable === true && row.exists && Boolean(previewUrl);
                const thumb = previewable
                    ? '<img src="' + escapeHtml(previewUrl) + '" class="md-file__thumb" alt="">'
                    : '<span class="md-file__thumb md-file__thumb--icon"><i class="fa-duotone fa-regular ' + fileIcon(row.type) + '"></i></span>';
                return '<div class="md-file' + (previewable ? ' md-file--previewable' : '') + '">' + thumb +
                    '<div class="md-file__text"><span class="md-file__name" title="' + path + '">' + name + '</span>' + note + '</div></div>';
            },
            events: {
                // 双击可预览的图片单元 → 放大预览
                'dblclick .md-file--previewable': function (event, value, row) {
                    openImagePreview(row);
                }
            }
        },
        { field: 'type', title: '类型', formatter: function (v) { return '<span class="badge badge-light-info">' + escapeHtml(fileTypeLabel(v)) + '</span>'; } },
        { field: 'size', title: '大小', formatter: function (v, row) { return formatSize(v, row.exists); } },
        {
            field: 'user_id', title: '归属', formatter: function (v, row) {
                return v
                    ? '<span class="a-badge a-badge-primary">' + escapeHtml(row.user ? row.user.username : ('用户#' + v)) + '</span>'
                    : '<span class="a-badge a-badge-secondary">后台</span>';
            }
        },
        { field: 'create_time', title: '上传时间', formatter: function (v) { return escapeHtml(v); } },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-download', class: 'text-success',
                    show: function (row) { return Boolean(safeDownloadUrl(row.download_url)); },
                    click: function (event, value, row) {
                        const url = safeDownloadUrl(row.download_url);
                        if (url) window.open(url, '_blank', 'noopener');
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-link', class: 'text-primary',
                    show: function (row) { return Boolean(safePublicUrl(row.copy_url)); },
                    click: function (event, value, row) {
                        const url = safePublicUrl(row.copy_url);
                        if (url) util.copyTextToClipboard(url, function () { message.success('链接已复制到剪贴板'); });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square', class: 'text-warning',
                    click: function (event, value, row) {
                        let submitting = false;
                        component.popup({
                            title: util.icon('fa-duotone fa-regular fa-pen-to-square me-1') + '编辑备注',
                            width: '440px', height: 'auto', autoPosition: true,
                            tab: [{ name: '编辑备注', form: [{ title: '备注', name: 'note', type: 'input', placeholder: '最多 32 字，留空则清除' }] }],
                            assign: { note: row.note || '' },
                            submit: function (data, index) {
                                if (!controllerActive || submitting) return;
                                submitting = true;
                                util.post({
                                    url: '/admin/api/file/note',
                                    data: { id: row.id, note: data.note },
                                    done: function (res) {
                                        if (!controllerActive) return;
                                        message.success(res?.msg || '备注已保存');
                                        layer.close(index);
                                        table.refresh();
                                    },
                                    error: function (res) {
                                        submitting = false;
                                        if (controllerActive) message.error(res?.msg || '备注保存失败');
                                    },
                                    fail: function () {
                                        submitting = false;
                                        if (controllerActive) message.error('网络异常，备注未保存');
                                    }
                                });
                            },
                            renderComplete: unique => $('.' + unique + ' input[name="note"]').attr({maxlength: '32', autocomplete: 'off'})
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: function (event, value, row) {
                        confirmFileDelete([row.id]);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-eye text-primary', class: 'admin-mobile-operation-only text-primary', title: '预览',
                    show: function (row) { return mobileAdminEnabled() && row.previewable === true && row.exists; },
                    click: function (event, value, row) { openImagePreview(row); }
                }
            ]
        }
    ]);

    table.setPagination(15, [15, 30, 50, 100]);

    table.setSearch([
        {
            title: '类型', name: 'equal-type', type: 'select', dict: [
                { id: 'image', name: '图片' },
                { id: 'message', name: '消息图片' },
                { id: 'ticket', name: '工单图片' },
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
    $('#file-upload-input').off('change');
    util.bindButtonUpload('#file-upload-input', '/admin/api/file/upload', function (res) {
        if (!controllerActive) return;
        message.success('上传成功');
        table.refresh();
    });
    $('#file-upload-input').off('change' + namespace).on('change' + namespace, function () {
        const input = this;
        window.setTimeout(function () { if (input.isConnected) $(input).val(''); }, 0);
    });
    $('.file-upload-btn').off(namespace).on('click' + namespace, function () { $('#file-upload-input').click(); });

    // 工具栏：批量删除
    $('.file-batch-del').off(namespace).on('click' + namespace, function () {
        const ids = table.getSelectionIds();
        if (!ids.length) { layer.msg('请至少勾选一个文件'); return; }
        confirmFileDelete(ids);
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        deletePreviewPending = false;
        deletePending = false;
        $('.file-upload-btn, .file-batch-del').off(namespace);
        // bindButtonUpload uses an unnamespaced change handler; this input is
        // page-owned, so remove both that handler and the reset handler.
        $('#file-upload-input').off('change');
        $(document).off('pjax:beforeReplace' + namespace);
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdFileDestroy === destroy) delete window.__mdFileDestroy;
    }

    window.__mdFileDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
