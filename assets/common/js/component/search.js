class Search {

    escapeAttribute(value) {
        const entities = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
        return String(value ?? '').replace(/[&<>"']/g, character => entities[character]);
    }

    constructor(elm, opt, click = null, button = true) {
        this.unique = util.generateRandStr(8);
        this.opt = opt;
        this.item = {};
        this.controls = {};
        this.click = click;
        this.isDestroyed = false;
        this.layuiEvents = [];
        elm.append('<form class="layui-form-item layui-form table-search ' + this.unique + '" onsubmit="return false;"></form>');
        let instance = $("." + this.unique);
        this.$instance = instance;
        opt.forEach(item => {
            item.title = i18n(item.title);
            this.item[item.name] = item;
            //let width = item.hasOwnProperty('width') ? 'style="width:' + (util.isPc() ? item.width + "px" : "100%") + ';"' : '';
            //设置默认值
            switch (item.type) {
                case "input":
                    instance.append(this.inputHtml(item));
                    this.inputRegister(item);
                    break;
                case "date":
                    instance.append(this.dateHtml(item));
                    this.dateRegister(item);
                    break;
                case "select":
                    instance.append(this.selectHtml(item));
                    this.selectRegister(item);
                    break;
                case "remoteSelect":
                    instance.append(this.remoteSelectHtml(item));
                    this.remoteSelectRegister(item);
                    break;
                case "treeSelect":
                    instance.append(this.treeSelectHtml(item))
                    this.treeSelectRegister(item);
                    break;
            }
        });

        button && this.registerButton(instance);
    }

    createSearch(item, targetName, sequence = "after") {
        item.title = i18n(item.title);
        this.item[item.name] = item;
        this.opt.push(item);
        let target = $('.' + this.unique + " .e-" + targetName);
        //设置默认值
        let d;
        switch (item.type) {
            case "input":
                d = this.inputHtml(item);
                sequence == "after" ? target.after(d) : target.before(d);
                this.inputRegister(item);
                break;
            case "date":
                d = this.dateHtml(item);
                sequence == "after" ? target.after(d) : target.before(d);
                this.dateRegister(item);
                break;
            case "select":
                d = this.selectHtml(item);
                sequence == "after" ? target.after(d) : target.before(d);
                this.selectRegister(item);
                break;
            case "remoteSelect":
                d = this.remoteSelectHtml(item);
                sequence == "after" ? target.after(d) : target.before(d);
                this.remoteSelectRegister(item);
                break;
            case "treeSelect":
                d = this.treeSelectHtml(item);
                sequence == "after" ? target.after(d) : target.before(d);
                this.treeSelectRegister(item);
                break;
        }
    }


    removeSearch(name) {
        this.#disposeControl(name);
        $('.' + this.unique + " .e-" + name)?.remove();
        delete this.item[name];
        delete this.controls[name];
        this.opt = this.opt.filter(item => item.name !== name);
    }


    getWidth(item) {
        if (!item.hasOwnProperty('width')) {
            return '';
        }
        if (!util.isPc()) {
            return 'style="width:100%;"';
        }
        const source = String(item.width ?? '').trim();
        if (!/^\d+(?:\.\d+)?(?:px|rem|em|%|vw)?$/i.test(source)) {
            return '';
        }
        const width = /[a-z%]$/i.test(source) ? source : source + 'px';
        return 'style="width:' + width + ';"';
    }

    getClass(item) {
        const align = String(item?.align ?? '').toLowerCase();
        return ['left', 'center', 'right', 'start', 'end'].includes(align) ? `text-${align}` : '';
    }

    inputHtml(item) {
        return `<div class="layui-input-inline ${(item.hide ? 'hide' : '')} e-${this.escapeAttribute(item.name)} mui-sf" ${this.getWidth(item)}>
                    <input type="text" class="layui-input ${this.getClass(item)}" ${this.getWidth(item)} placeholder=" " name="${this.escapeAttribute(item.name)}" value="${this.escapeAttribute(item.default)}">
                    <label class="mui-sf__label">${this.escapeAttribute(item.title)}</label>
                </div>`;
    }

    inputRegister(item) {
        let _this = this;
        $('.' + this.unique + ' input[name=' + item.name + ']').on('input', function () {
            item.change && item.change(_this, this.value);
        }).on('keydown', function (event) {
            if (event.keyCode === 13) {
                $(this).parent().parent().find('.query-button').click();
            }
        });
    }

    dateHtml(item) {
        let start = item.name.replace('between', 'betweenStart');
        let end = item.name.replace('between', 'betweenEnd');
        let width = this.getWidth(item);
        // 用 .mui-sf + .mui-sf__label + placeholder=" "(同 inputHtml),让前/后台的 MUI notch 浮动标签机制接管
        let html = '';
        html += '<div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + this.escapeAttribute(item.name) + ' mui-sf" ' + width + '>\n' +
            '    <input type="text" class="layui-input between-date-' + this.escapeAttribute(item.name) + '" name="' + this.escapeAttribute(start) + '" placeholder=" " value="">\n' +
            '    <label class="mui-sf__label">' + this.escapeAttribute(i18n("从") + ' ' + item.title) + '</label>\n' +
            '</div>';
        html += '<div class="layui-input-inline text-center ' + (item.hide ? 'hide' : '') + ' e-' + this.escapeAttribute(item.name) + '" style="width: 10px;">~</div>';
        html += '<div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + this.escapeAttribute(item.name) + ' mui-sf" ' + width + '>\n' +
            '    <input type="text" class="layui-input between-date-' + this.escapeAttribute(item.name) + '" name="' + this.escapeAttribute(end) + '" placeholder=" " value="">\n' +
            '    <label class="mui-sf__label">' + this.escapeAttribute(i18n("到") + ' ' + item.title) + '</label>\n' +
            '</div>';
        return html;
    }

    dateRegister(item) {
        this.controls[item.name] = layui.laydate.render({
            elem: '.' + this.unique + ' .between-date-' + item.name,
            type: 'datetime'
        });
    }

    selectHtml(item) {
        return '<div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + this.escapeAttribute(item.name) + ' mui-sf mui-sf--select" ' + this.getWidth(item) + '>\n' +
            '                    <select lay-filter="' + this.escapeAttribute(this.unique + item.name) + '" ' + (item.search === true ? 'lay-search=""' : '') + '  name="' + this.escapeAttribute(item.name) + '"></select>\n' +
            '                    <label class="mui-sf__label">' + this.escapeAttribute(item.title) + '</label>\n' +
            '                        </div>';
    }

    selectReload(name, dict = null) {
        let item = this.item[name];
        dict && (item.dict = dict);
        this.selectRegister(item);
    }

    selectRegister(item) {
        let _this = this;
        let selectInstance = $('.' + this.unique + ' select[name=' + item.name + ']');
        selectInstance.html(`<option value="">${i18n('全部')}</option>`);

        if (item.hasOwnProperty('dict')) {
            _Dict.advanced(item.dict, res => {
                if (this.isDestroyed) return;
                res.forEach(s => {
                    const option = $('<option>').val(s.id).text(String(s.name ?? '').replace(/(<([^>]+)>)/ig, ''));
                    option.prop('selected', parseInt(item.default) === parseInt(s.id));
                    selectInstance.append(option);
                });
                layui.form.render();
            });
        }

        const eventName = 'select(' + _this.unique + item.name + ')';
        if (typeof layui.off === 'function') layui.off(eventName, 'form');
        this.layuiEvents = this.layuiEvents.filter(binding => binding.event !== eventName || binding.module !== 'form');
        this.layuiEvents.push({module: 'form', event: eventName});
        layui.form.on(eventName, event => {
            if (_this.isDestroyed) return;
            item.change && item.change(_this, event.value);
        });

        item.complete && item.complete(_this);
    }

    selectAddOption(name, key, value) {
        let selectInstance = $('.' + this.unique + ' select[name=' + name + ']');
        selectInstance.append($('<option>').val(key).text(String(value ?? '').replace(/(<([^>]+)>)/ig, '')));
        layui.form.render();
    }

    selectClearOption(name) {
        let item = this.item[name];
        $('.' + this.unique + ' select[name=' + name + ']').empty().append($('<option>').val('').text(String(item.title ?? '')));
        layui.form.render();
    }


    remoteSelectHtml(item) {
        return '<div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + this.escapeAttribute(item.name) + ' mui-sf mui-sf--select" ' + this.getWidth(item) + '>\n' +
            '                    <span class="' + this.escapeAttribute(item.name) + '"></span>\n' +
            '                    <label class="mui-sf__label">' + this.escapeAttribute(item.title) + '</label>\n' +
            '                        </div>';
    }

    getBlockHtml(item, html) {
        return `<div class="layui-input-inline ${(item.hide ? 'hide' : '')} e-${item.name}"  ${this.getWidth(item)}>${html}</div>`;
    }

    treeSelectHtml(item) {
        return `<div class="layui-input-inline ${(item.hide ? 'hide' : '')} e-${this.escapeAttribute(item.name)} mui-sf mui-sf--select" ${this.getWidth(item)}>
                    <span class="tree-${this.escapeAttribute(item.name)}"></span>
                    <label class="mui-sf__label">${this.escapeAttribute(item.title)}</label>
                </div>`;
    }

    treeSelectReload(name, dict = null) {
        let item = this.item[name];
        dict && (item.dict = dict);
        this.treeSelectRegister(item);
    }

    treeSelectRegister(item) {
        let _this = this;
        $('.' + this.unique + " .tree-" + item.name).html(`<input type="text" lay-filter="${this.escapeAttribute(this.unique + item.name)}" class="layui-input ${this.escapeAttribute(this.unique + item.name)}"><input name="${this.escapeAttribute(item.name)}" type="hidden" class="layui-input">`);
        this.controls[item.name] = layui.treeSelect.render({
            // 选择器
            elem: '.' + _this.unique + item.name,
            // 数据
            data: item.dict,
            // 异步加载方式：get/post，默认get
            //type: 'post',
            // 占位符用「全部」，避免和上浮的 mui-sf 浮动标签（item.title）重复
            placeholder: i18n('全部'),
            // 是否开启搜索功能：true/false，默认false
            search: true,
            // 点击回调
            click: function (d) {
                if (_this.isDestroyed) return;
                $('.' + _this.unique + " input[name=" + item.name + "]").val(d.current.id);
                item.change && item.change(_this, d.current.id);
            },
            // 加载完成后的回调函数
            success: function (d) {
                if (_this.isDestroyed) return;
                /*                if (form.default) {
                                    layui.treeSelect.checkNode(_this.unique + item.name, parseInt(item.default));
                                }
                                item.complete && item.complete(_this, item.default);*/
            }
        });

        layui.form.render();
    }

    remoteSelectRegister(item) {
        let _this = this;
        this.controls[item.name] = xmSelect.render({
            el: "." + this.unique + " ." + item.name,
            size: 'mini',
            style: {
                height: '28px'
            },
            radio: true,
            autoRow: true,
            name: item.name,
            // data: initValue,
            tips: i18n(item.placeholder || '全部'),
            searchTips: item.title,
            //  toolbar: {show: true},
            filterable: true,
            remoteSearch: true,
            language: 'zn',
            remoteMethod: (val, cb, show) => {
                if (this.isDestroyed) return cb([]);
                //这里如果val为空, 则不触发搜索
                if (!val) {
                    return cb([]);
                }
                _Dict.advanced(`${item.dict}&keywords=${val}`, data => {
                    if (this.isDestroyed) return cb([]);
                    let boxesData = [];
                    data.forEach(s => {
                        boxesData.push({name: String(s.name ?? '').replace(/(<([^>]+)>)/ig, ''), value: s.id});
                    });
                    cb(boxesData);
                });
            },
            on: function (arr) {
                if (_this.isDestroyed) return;
                if (arr.change.length > 0) {
                    item.change && item.change(_this, arr.change[0].value, arr.isAdd);
                }
            }
        });
        item.complete && item.complete(_this);
    }

    registerButton(instance) {
        instance.append('<div class="layui-input-inline"><button type="button" class="layui-btn layui-btn-primary layui-btn-sm query-button">' +
            '<i class="fa-duotone fa-regular fa-magnifying-glass"></i> <span class="btn-name">' + i18n('查询') + '</span></button></div>');
        const $btn = $("." + this.unique + ' .query-button');
        $btn.click(() => {
            this.submit();
        });
    }

    resetButton() {
        $("." + this.unique + ' .query-button .btn-name').html(i18n('查询'));
    }


    getData() {
        return util.paramsToJSONObject($("." + this.unique).serialize());
    }

    /**
     * Return the final search definitions after route hooks and runtime changes.
     * The returned objects can be annotated by a presenter without changing the
     * definitions used by the desktop form.
     */
    definitions() {
        return this.opt.map(item => Object.assign({}, item, item.type === 'date' ? {
            fields: {
                start: item.name.replace('between', 'betweenStart'),
                end: item.name.replace('between', 'betweenEnd')
            }
        } : {}));
    }

    getDefinitions() {
        return this.definitions();
    }

    /**
     * Read all search values, or one value by its submitted field name.
     */
    value(name = null) {
        const data = this.getData();
        if (name === null) {
            return data;
        }
        const item = this.item[name];
        if (item?.type === 'date') {
            return {
                start: data[item.name.replace('between', 'betweenStart')] ?? '',
                end: data[item.name.replace('between', 'betweenEnd')] ?? ''
            };
        }
        return data[name];
    }

    getValue(name = null) {
        return this.value(name);
    }

    #findDefinition(name) {
        if (this.item[name]) {
            return this.item[name];
        }
        return this.opt.find(item => item.type === 'date' && [
            item.name.replace('between', 'betweenStart'),
            item.name.replace('between', 'betweenEnd')
        ].includes(name));
    }

    #field(name) {
        return this.$instance.find('[name]').filter((_, element) => element.name === name);
    }

    #setField(name, value, notify = false) {
        const item = this.#findDefinition(name);
        const normalized = value === undefined || value === null ? '' : value;
        let notifiedByControl = false;

        if (item?.type === 'remoteSelect' && name === item.name) {
            const control = this.controls[item.name];
            if (control?.setValue) {
                let selected = Array.isArray(normalized) ? normalized : (normalized === '' ? [] : [normalized]);
                selected = selected.map(option => {
                    if (option && typeof option === 'object') {
                        return option;
                    }
                    return {name: String(option), value: option};
                });
                control.setValue(selected, null, notify);
                notifiedByControl = notify;
            } else {
                this.#field(name).val(normalized);
            }
        } else {
            this.#field(name).val(normalized);
            if (item?.type === 'treeSelect' && name === item.name) {
                const filter = this.unique + item.name;
                const $visible = this.$instance.find('.tree-' + item.name + ' input[type="text"]');
                if (normalized === '') {
                    $visible.val('');
                } else {
                    try {
                        this.controls[item.name]?.checkNode(filter, normalized);
                    } catch (error) {
                        util.debug('Search treeSelect value not found: ' + normalized, '#ff4f33');
                    }
                }
            }
        }

        if (notify && !notifiedByControl && typeof item?.change === 'function') {
            item.change(this, normalized);
        }
    }

    /**
     * Set one field or a map of submitted fields. Date definitions accept
     * [start, end] or {start, end} when addressed by their base name.
     */
    set(nameOrValues, value = null, notify = false) {
        if (nameOrValues && typeof nameOrValues === 'object' && !Array.isArray(nameOrValues)) {
            const shouldNotify = typeof value === 'boolean' ? value : notify;
            Object.entries(nameOrValues).forEach(([name, fieldValue]) => {
                this.set(name, fieldValue, shouldNotify);
            });
            return this;
        }

        const name = nameOrValues;
        const item = this.item[name];
        if (item?.type === 'date') {
            const startName = item.name.replace('between', 'betweenStart');
            const endName = item.name.replace('between', 'betweenEnd');
            const start = Array.isArray(value) ? value[0] : (value?.start ?? '');
            const end = Array.isArray(value) ? value[1] : (value?.end ?? '');
            this.#setField(startName, start, false);
            this.#setField(endName, end, false);
            if (notify && typeof item.change === 'function') {
                item.change(this, {start: start, end: end});
            }
        } else {
            this.#setField(name, value, notify);
        }
        layui.form.render();
        return this;
    }

    setValue(nameOrValues, value = null, notify = false) {
        return this.set(nameOrValues, value, notify);
    }

    /** Clear the search form without submitting unless requested. */
    reset(submit = false) {
        this.opt.forEach(item => {
            if (item.type === 'date') {
                this.set(item.name, item.default ?? ['', '']);
            } else {
                this.set(item.name, item.default ?? '');
            }
        });
        this.resetButton();
        submit && this.submit();
        return this;
    }

    /** Submit through the original table callback. */
    submit() {
        if (this.isDestroyed) return {};
        const data = this.getData();
        this.$instance.find('.query-button .btn-name').html(i18n('搜索中') + '..');
        typeof this.click === 'function' && this.click(data);
        return data;
    }

    #disposeControl(name) {
        const control = this.controls[name];
        try {
            if (typeof control?.destroy === 'function') control.destroy();
            else if (typeof control?.closed === 'function') control.closed();
            else if (typeof control?.close === 'function') control.close();
        } catch (error) {
            util.debug('Search control destroy skipped: ' + name, '#ff4f33');
        }
        delete this.controls[name];
    }

    destroy() {
        if (this.isDestroyed) return this;
        this.isDestroyed = true;

        if (typeof layui !== 'undefined' && typeof layui.off === 'function') {
            this.layuiEvents.forEach(binding => {
                try { layui.off(binding.event, binding.module); } catch (error) {}
            });
        }
        this.layuiEvents = [];

        Object.keys(this.controls).forEach(name => this.#disposeControl(name));

        this.$instance.find('.layui-treeSelect').each(function () {
            const $tree = $(this);
            const titleId = $tree.find('.layui-select-title').attr('id');
            const inputId = $tree.find('.layui-select-title input').attr('id');
            const bodyId = $tree.find('.layui-treeSelect-body').attr('id');
            titleId && $('body').off('click', '#' + titleId);
            inputId && $('body').off('input propertychange', '#' + inputId);
            $tree.attr('id') && $('body').off('click', '#' + $tree.attr('id') + ' .layui-anim');
            if (bodyId && $.fn.zTree && typeof $.fn.zTree.destroy === 'function') {
                try { $.fn.zTree.destroy(bodyId); } catch (error) {}
            }
        });

        this.$instance.find('[lay-key]').each(function () {
            const key = $(this).attr('lay-key');
            $('.layui-laydate').filter(function () {
                return $(this).attr('lay-key') === key || this.id === 'layui-laydate' + key;
            }).remove();
        });

        this.$instance.find('*').addBack().stop(true, true).off();
        this.$instance.remove();
        this.controls = {};
        this.item = {};
        this.opt = [];
        this.click = null;
        return this;
    }

    hide(name) {
        $('.' + this.unique + " .e-" + name).fadeOut(100);
    }

    show(name) {
        $('.' + this.unique + " .e-" + name).fadeIn(100);
    }

}
