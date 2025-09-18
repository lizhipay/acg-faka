class Search {

    constructor(elm, opt, click = null, button = true) {
        this.unique = util.generateRandStr(8);
        this.opt = opt;
        this.item = {};
        elm.append('<form class="layui-form-item layui-form table-search ' + this.unique + '" onsubmit="return false;"></form>');
        let instance = $("." + this.unique);
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

        button && this.registerButton(instance, click);
    }

    createSearch(item, targetName, sequence = "after") {
        item.title = i18n(item.title);
        this.item[item.name] = item;
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
        $('.' + this.unique + " .e-" + name)?.remove();
    }


    getWidth(item) {
        return item.hasOwnProperty('width') ? 'style="width:' + (util.isPc() ? item.width + "px" : "100%") + ';"' : '';
    }

    getClass(item) {
        let classes = '';
        if (item?.align) {
            classes += ` text-${item.align} `;
        }
        return classes.trim();
    }

    inputHtml(item) {
        return `<div class="layui-input-inline ${(item.hide ? 'hide' : '')} e-${item.name}" ${this.getWidth(item)}>
                    <input type="text" class="layui-input ${this.getClass(item)}" ${this.getWidth(item)} placeholder="${item.title}" name="${item.name}" value="${item.default ?? ''}">
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
        let html = '';
        html += ' <div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + item.name + '" ' + width + '>\n' +
            '        <input type="text" class="layui-input between-date-' + item.name + '" name="' + start + '" placeholder="' + i18n("从") + " " + item.title + '"  value="">\n' +
            '      </div>';
        html += '<div class="layui-input-inline text-center' + (item.hide ? 'hide' : '') + ' e-' + item.name + '" style="width: 10px;">\n' +
            '                            ~ \n' +
            '                        </div>';
        html += ' <div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + item.name + '" ' + width + '>\n' +
            '        <input type="text" class="layui-input between-date-' + item.name + '" name="' + end + '" placeholder="' + i18n("到") + " " + item.title + '"  value="">\n' +
            '      </div>';
        return html;
    }

    dateRegister(item) {
        layui.laydate.render({
            elem: '.' + this.unique + ' .between-date-' + item.name,
            type: 'datetime'
        });
    }

    selectHtml(item) {
        return '<div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + item.name + '" ' + this.getWidth(item) + '>\n' +
            '                    <select lay-filter="' + this.unique + item.name + '" ' + (item.search === true ? 'lay-search=""' : '') + '  name="' + item.name + '"></select>\n' +
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
        selectInstance.html(`<option value="">${item.title}</option>`);

        if (item.hasOwnProperty('dict')) {
            _Dict.advanced(item.dict, res => {
                res.forEach(s => {
                    selectInstance.append(' <option value="' + s.id + '"  ' + (parseInt(item.default) === parseInt(s.id) ? "selected" : "") + '>' + s.name.replace(/(<([^>]+)>)/ig, "") + '</option>');
                });
                layui.form.render();
            });
        }

        layui.form.on('select(' + _this.unique + item.name + ')', event => {
            item.change && item.change(_this, event.value);
        });

        item.complete && item.complete(_this);
    }

    selectAddOption(name, key, value) {
        let selectInstance = $('.' + this.unique + ' select[name=' + name + ']');
        selectInstance.append(' <option value="' + key + '">' + value.replace(/(<([^>]+)>)/ig, "") + '</option>');
        layui.form.render();
    }

    selectClearOption(name) {
        let item = this.item[name];
        $('.' + this.unique + ' select[name=' + name + ']').html('<option value="">' + item.title + '</option>');
        layui.form.render();
    }


    remoteSelectHtml(item) {
        return '<div class="layui-input-inline ' + (item.hide ? 'hide' : '') + ' e-' + item.name + '" ' + this.getWidth(item) + '>\n' +
            '                    <span class="' + item.name + '"></span>\n' +
            '                        </div>';
    }

    getBlockHtml(item, html) {
        return `<div class="layui-input-inline ${(item.hide ? 'hide' : '')} e-${item.name}"  ${this.getWidth(item)}>${html}</div>`;
    }

    treeSelectHtml(item) {
        return this.getBlockHtml(item, `<span class="tree-${item.name}"></span>`);
    }

    treeSelectReload(name, dict = null) {
        let item = this.item[name];
        dict && (item.dict = dict);
        this.treeSelectRegister(item);
    }

    treeSelectRegister(item) {
        let _this = this;
        $('.' + this.unique + " .tree-" + item.name).html(`<input type="text" lay-filter="${this.unique + item.name}" class="layui-input ${this.unique + item.name}"><input name="${item.name}"  type="hidden" class="layui-input"">`);
        layui.treeSelect.render({
            // 选择器
            elem: '.' + _this.unique + item.name,
            // 数据
            data: item.dict,
            // 异步加载方式：get/post，默认get
            //type: 'post',
            // 占位符
            placeholder: item.title,
            // 是否开启搜索功能：true/false，默认false
            search: true,
            // 点击回调
            click: function (d) {
                $('.' + _this.unique + " input[name=" + item.name + "]").val(d.current.id);
                item.change && item.change(_this, d.current.id);
            },
            // 加载完成后的回调函数
            success: function (d) {
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
        xmSelect.render({
            el: "." + this.unique + " ." + item.name,
            size: 'mini',
            style: {
                height: '28px'
            },
            radio: true,
            autoRow: true,
            name: item.name,
            // data: initValue,
            tips: item.title,
            searchTips: item.title,
            //  toolbar: {show: true},
            filterable: true,
            remoteSearch: true,
            language: 'zn',
            remoteMethod: (val, cb, show) => {
                //这里如果val为空, 则不触发搜索
                if (!val) {
                    return cb([]);
                }
                _Dict.advanced(`${item.dict}&keywords=${val}`, data => {
                    let boxesData = [];
                    data.forEach(s => {
                        boxesData.push({name: s.name, value: s.id});
                    });
                    cb(boxesData);
                });
            },
            on: function (arr) {
                if (arr.change.length > 0) {
                    item.change && item.change(_this, arr.change[0].value, arr.isAdd);
                }
            }
        });
        item.complete && item.complete(_this);
    }

    registerButton(instance, click) {
        instance.append('<div class="layui-input-inline"><button type="button" class="layui-btn layui-btn-primary layui-btn-sm query-button">' +
            '<i class="fa-duotone fa-regular fa-magnifying-glass"></i> <span class="btn-name">' + i18n('查询') + '</span></button></div>');
        const $btn = $("." + this.unique + ' .query-button');
        $btn.click(() => {
            $btn.find(".btn-name").html(i18n("搜索中") + "..");
            click && click(this.getData());
        });
    }

    resetButton() {
        $("." + this.unique + ' .query-button .btn-name').html(i18n('查询'));
    }


    getData() {
        return util.paramsToJSONObject($("." + this.unique).serialize());
    }

    hide(name) {
        $('.' + this.unique + " .e-" + name).fadeOut(100);
    }

    show(name) {
        $('.' + this.unique + " .e-" + name).fadeIn(100);
    }

}