const component = new class Component {
    isRowDetailOpen($table, index) {
        let $tr = $table.find('tr[data-index="' + index + '"]');
        let $detailView = $tr.next('.detail-view');
        return $detailView.length > 0;
    }

    /**
     *
     * @param url
     * @param value
     * @param field
     * @param id
     */
    updateDatabase(url, value, field, id, table = null, reload = false) {
        let data = {};
        data[field] = value;
        data["id"] = id;
        util.post(url, data, res => {
            message.success("已更新 (｡•ᴗ-)");
            table && reload && table.bootstrapTable('refresh', {silent: true});
        });
    }

    deleteDatabase(url, list, done = null) {
        message.ask("一旦数据被遗弃，您将无法恢复它！", () => {
            util.post(url, {list: list}, res => {
                message.alert('您选择的数据已被系统永久删除。', 'success');
                done && done(res);
            });
        });
    }

    /**
     *
     * @param opt
     */
    popup(opt = {}) {
        const submitTab = getVar("HACK_SUBMIT_TAB"), submitForm = getVar("HACK_SUBMIT_FORM");

        if (submitTab instanceof Array) {
            submitTab.forEach(tmp => {
                if (tmp.submit == opt.submit) {
                    opt?.tab?.push(evalResults(tmp.code));
                }
            });
        }

        if (submitForm instanceof Array) {
            submitForm.forEach(tmp => {
                if (tmp.submit == opt.submit) {
                    for (let i = 0; i < opt?.tab?.length; i++) {
                        const forms = opt?.tab[i]?.form;
                        for (let j = 0; j < forms?.length; j++) {
                            const form = forms[j];
                            if (form.name == tmp.field) {
                                if (tmp.direction === "after") {
                                    opt?.tab[i]?.form?.splice(j + 1, 0, evalResults(tmp.code));
                                } else {
                                    opt?.tab[i]?.form?.splice(j, 0, evalResults(tmp.code));
                                    j++;
                                }
                            }
                        }
                    }
                }
            });
        }

        let form = new Form(opt);
        let tab = form.getTab();
        let area = '680px';

        if (opt.width && opt.height) {
            area = [opt.width, opt.height];
        } else if (opt.width) {
            area = opt.width;
        }

        if (!util.isPc()) {
            area = ["100%", "100%"];
        }

        //弹窗参数
        let openOption = {
            shade: opt.shade ?? 0.3,
            btn: opt.submit ? [(opt.confirmText ? i18n(opt.confirmText) : null) ?? util.icon("fa-duotone fa-regular fa-floppy-disk me-1 text-success") + i18n("保存"), util.icon('fa-duotone fa-regular fa-xmark me-1 text-warning') + i18n('取消')] : false,
            area: area,
            maxmin: opt.maxmin ?? true,
            closeBtn: opt.closeBtn ?? 1,
            shadeClose: opt.shadeClose ?? false,
            anim: 4,
            yes: (index, lay) => {
                let data = form.getData();
                if (!form.validator()) {
                    return;
                }

                if (typeof opt.submit == "function") {
                    opt.submit(data, index);
                    return;
                }
                opt.submit && (util.post(opt.submit, data, res => {
                    layer.close(index);
                    if (opt.message !== false) {
                        if (!res.msg || res.msg == "success") {
                            message.alert(opt.message ?? '您提交的数据已被系统存储(｡•ᴗ-)_', 'success');
                        } else {
                            message.alert(res.msg, 'success');
                        }
                    }
                    opt.done && opt.done(res, data);
                }, error => {
                    opt.error && opt.error(error);
                    message.alert(error.msg, 'error');
                }));
            },
            success: (lay, layIndex, that) => {
                let contentElem = $(lay).find('.layui-layer-content');

                form.setIndex(layIndex);
                form.registerEvent();
                $('.component-popup.' + form.getUnique()).append('<img src="/assets/common/images/ks.webp" class="component-popup-acg">');


                if (opt.content && util.isPc()) {
                    if (opt.content.css) {
                        for (const cssKey in opt.content.css) {
                            contentElem.css(cssKey, opt.content.css[cssKey]);
                        }
                    }
                }

                if (opt.autoPosition && util.isPc()) {
                    this.resizeObserver($(lay).find(".layui-layer-content"), event => {
                        const heightValue = util.getDomHeight($(lay).find(".layui-layer-content")),
                            overflowValue = $(lay).find(".layui-layer-content").css("overflow");

                        if (/^\d+px$/.test(heightValue) && overflowValue == "auto") {
                            $(lay).find(".layui-layer-content").css("height", "auto");
                        }

                        let height = $(lay).find(".layui-layer-content").height() + 60 + 56;
                        if (height > $(window).height()) {
                            const autoHeight = $(window).height() - 155;
                            $(lay).find(".layui-layer-content").css("height", `${autoHeight}px`).css("overflow", "");
                        }

                        layer.iframeAuto(layIndex);
                        that.offset();
                    });
                }

                typeof opt.renderComplete == "function" && opt.renderComplete(form.getUnique(), layIndex);
            },
            end: () => {
                opt.end && opt.end();
            },
            full: (layero, index, that) => {
                let $handle = layero.addClass("border-none");
                $handle.find(".layui-layer-title").addClass("border-none");
                $handle.find(".layui-layer-btn").addClass("border-none");
            },
            restore: (layero, index, that) => {
                let $handle = layero.removeClass("border-none");
                $handle.find(".layui-layer-title").removeClass("border-none");
                $handle.find(".layui-layer-btn").removeClass("border-none");
            }
        };

        if (tab.length === 1) {
            //单选卡
            openOption.type = 1;
            openOption.content = tab[0].content;
            openOption.title = tab[0].title;
            openOption.skin = 'component-popup ' + form.getUnique();
            layer.open(openOption);
        } else {
            //多选卡
            openOption.tab = tab;
            openOption.skin = 'layui-layer-tab component-popup ' + form.getUnique();
            layer.tab(openOption);
        }
    }


    idObjToList(array = []) {
        let list = [];
        array.forEach(item => {
            list.push(item.id);
        });
        return list;
    }


    async loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.body.appendChild(script);
        });
    }

    async run(scripts = []) {
        for (const src of scripts) {
            await this.loadScript(src);
        }
    }


    resizeObserver(element, done) {
        if ('ResizeObserver' in window) {
            let resizeObserver = new ResizeObserver(function (entries) {
                for (let entry of entries) {
                    done && done(entry);
                }
            });
            resizeObserver.observe(element.get(0));
        }
    }

}