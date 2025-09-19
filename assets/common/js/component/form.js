class Form {

    constructor(opt) {
        this.widget = {num: 0};
        this.attribute = {num: 0};
        this.tab = [];
        this.unique = util.generateRandStr(8);
        this.data = {};
        this.opt = opt;
        this.form = {};

        //html editor register
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });

        opt.tab.forEach((item, index) => {
            if (item.hide === true) {
                item.name = "";
            }
            let d = `<div class="layui-card-body"><form class="layui-form layui-form-pane ${this.unique + index}" lay-filter="${this.unique + index}">`;

            item.form.forEach((form, ix) => {
                form.title && (form.title = i18n(form.title));
                form.placeholder && (form.placeholder = i18n(form.placeholder));
                form.name = util.replaceDotWithHyphen(form.name);
                (opt.hasOwnProperty('assign') && util.checkPropertyExistence(opt.assign, form.name)) && (form.default = util.parseStringObject(opt.assign, form.name));
                this.data[form.name] = {
                    hide: form.hide ? ' hide' : '',
                    titleHide: !form.title ? 'hide' : '',
                    blockMarginZero: !form.title ? "margin-left-zero" : ''
                }

                this.form[form.name] = form;
                switch (form.type) {
                    case 'input':
                        d += this.inputHtml(form, "text");
                        break;
                    case 'date':
                        d += this.inputHtml(form, "text");
                        break;
                    case 'number':
                        d += this.inputHtml(form, "number");
                        break;
                    case 'password':
                        d += this.inputHtml(form, "password");
                        break;
                    case 'checkbox':
                    case 'radio':
                    case 'widget':
                        d += this.getBlockHtml(form, util.icon("icon-loading", "icon-spin", "icon-18px"));
                        break;
                    case 'attribute':
                        d += this.getBlockHtml(form);
                        break;
                    case 'select':
                        d += this.selectHtml(form);
                        break;
                    case 'switch':
                        d += this.switchHtml(form);
                        break;
                    case 'textarea':
                        d += this.textareaHtml(form);
                        break;
                    case 'editor':
                        d += this.editorHtml(form);
                        break;
                    case 'html':
                        d += this.htmlHtml(form);
                        break;
                    case 'image':
                        d += this.imageHtml(form);
                        break;
                    case 'file':
                        d += this.fileHtml(form);
                        break;
                    case 'treeCheckbox':
                        d += this.treeCheckboxHtml(form);
                        break;
                    case 'treeSelect':
                        d += this.treeSelectHtml(form);
                        break;
                    case 'custom':
                        d += this.getBlockHtml(form);
                        break;
                }
            });
            d += `</form></div>`;

            item.name && (item.name = i18n(item.name));
            this.tab.push({
                title: item.name,
                content: d
            });
        });
    }

    setIndex(index) {
        this.index = index;
    }

    getIndex() {
        return this.index;
    }

    getMap(name = null) {
        name = util.replaceDotWithHyphen(name);
        let map = cache.get(this.unique);
        if (name) {
            return map[name];
        }
        return map;
    }

    setData(name, val) {
        let map = cache.get(this.unique);
        if (!map) {
            map = {};
        }
        map[name] = val;
        cache.set(this.unique, map);
    }

    validator() {
        let data = this.getData();
        for (let i = 0; i < this.opt.tab.length; i++) {
            let item = this.opt.tab[i];
            for (let j = 0; j < item.form.length; j++) {
                let form = item.form[j];
                let value = util.parseStringObject(data, util.replaceDotWithHyphen(form.name));
                if (form.required === true && value === "") {
                    layer.msg(`「${form.title ? util.plainText(form.title) : form.name}」${i18n('不能为空值')}`);
                    return false;
                }
                if (form.regex && value) {
                    const pattern = new RegExp(form.regex.value);
                    if (!pattern.test(value)) {
                        layer.msg(`${form.regex.message}`);
                        return false;
                    }
                }
            }
        }
        return true;
    }

    getBlockHtml(form, widgetHtml = "") {
        return `<div class="layui-form-item block-${form.name} ${this.data[form.name]['hide']}">
            <label class="layui-form-label ${this.data[form.name].titleHide}">${form.title}${form.required === true ? util.icon('fa-duotone fa-regular fa-asterisk text-danger fs-10 ms-1 icon-top-2') : ''}</label>
            <div class="${this.data[form.name].blockMarginZero} layui-input-block component-${form.name} component-content" >
            ${widgetHtml}
            </div>
            </div>`;
    }

    inputHtml(form, type = "text") {
        return this.getBlockHtml(form, `<input ${form.disabled ? "disabled" : ""} name="${form.name}" placeholder="${form.placeholder}" type="${type}" class="layui-input" value="${(form.default ?? "")}">`);
    }

    selectHtml(form) {
        return this.getBlockHtml(form, `<select lay-filter="${this.unique + form.name}" name="${form.name}" ${form.search ? ' lay-search=""' : ""}><option value="">${form.placeholder ?? i18n('请选择')}</option></select>`);
    }

    switchHtml(form) {
        return this.getBlockHtml(form, `<input lay-filter="${this.unique + form.name}" name="${form.name}" type="checkbox" lay-skin="switch" ${form.default == 1 ? "checked" : ""} lay-text="${form.placeholder ?? 'ON|OFF'}" value="1">`);
    }

    textareaHtml(form) {
        return this.getBlockHtml(form, `<textarea ${form.disabled ? "disabled" : ""} ${form.hasOwnProperty('height') ? 'style="height:' + (Number.isInteger(form.height) ? form.height + "px" : form.height) + '"' : ''} name="${form.name}" placeholder="${form.placeholder}" class="layui-textarea">${form.default ?? ""}</textarea>`);
    }

    editorHtml(form) {
        return this.getBlockHtml(form, `<div class="editor-wrapper"><div><button data-type="0" class="button-switch-${form.name}" type="button" style="width: 100%;border: none;background: rgba(255, 255, 255, 0.35);border-radius: 5px 5px 0 0;color: #c9b8b8;"><i class="fa-duotone fa-regular fa-code me-1"></i>HTML</button></div><div class="editor-content"><div class="toolbar-container"></div><div class="editor-container"></div></div><textarea class="text-container" style="display: none;" name="${form.name}">${form.default ?? ""}</textarea></div>`);
    }

    htmlHtml(form) {
        //return this.getBlockHtml(form, `<textarea name="${form.name}">${form.default ?? ""}</textarea>`);
        return this.getBlockHtml(form, `<div  style="width: 100%;height: ${form.height ? (Number.isInteger(form.height) ? form.height + "px" : form.height) : '400px'}" id="${this.unique}-${form.name}-editor"></div>`);
    }

    imageHtml(form) {
        if (form.photoAlbumUrl) {
            form.title = form.title ? form.title += `<a class="photo-album" style="position: relative;top: 2px;cursor:pointer;">${util.icon('fa-duotone fa-regular fa-image text-success ms-1 fs-5')}</a>` : form?.title;
        }

        form.title += `<a class="external-input" style="position: relative;top: 2px;cursor:pointer;">${util.icon('fa-duotone fa-regular fa-link ms-1 fs-5 text-primary')}</a>`;

        return this.getBlockHtml(form, `<input name="${form.name}" placeholder="${i18n("输入网络图片地址")}" type="text" class="layui-input" value="${form.default ?? ""}" style="display: none;"><div class="image-render"></div>`);
    }

    fileHtml(form) {
        return this.getBlockHtml(form, `<input name="${form.name}" placeholder="${i18n("输入文件网络地址")}" type="text" class="layui-input" value="${form.default ?? ""}" style="display: none;"><div class="file-render"></div>`);
    }

    treeCheckboxHtml(form) {
        return this.getBlockHtml(form, `<div class="treeCheckbox"></div>`);
    }

    treeSelectHtml(form) {
        return this.getBlockHtml(form, `<input type="text" lay-filter="${this.unique + form.name}" class="layui-input tree-select"><input name="${form.name}"  type="hidden" class="layui-input" value="${form.default ?? ""}">`);
    }


    hide(name) {
        $('.' + this.unique + " .block-" + util.replaceDotWithHyphen(name)).hide();
    }

    show(name) {
        $('.' + this.unique + " .block-" + util.replaceDotWithHyphen(name)).fadeIn(100);
    }

    setInput(name, val) {
        name = util.replaceDotWithHyphen(name);
        $('.' + this.unique + " input[name=" + name + "]").val(val);
        this.triggerOtherPopupChange(name, val);
    }

    setCustom(name, val) {
        name = util.replaceDotWithHyphen(name);
        $(`.${this.unique} .component-${name}`).html(val);
        this.triggerOtherPopupChange(name, val);
    }

    getCustomDom(name) {
        name = util.replaceDotWithHyphen(name);
        return $(`.${this.unique} .component-${name}`);
    }

    setTextarea(name, val) {
        name = util.replaceDotWithHyphen(name);
        $('.' + this.unique + " textarea[name=" + name + "]").val(val);
        this.triggerOtherPopupChange(name, val);
    }

    appendTextarea(name, val) {
        name = util.replaceDotWithHyphen(name);
        $(`.${this.unique} textarea[name=${name}]`).append(val + "\n");
        this.triggerOtherPopupChange(name, val);
    }

    clearComponent(name) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + " .component-" + name);
        instance.html('');
        layui.form.render(instance);
    }


    addCheckbox(name, val, title, checked = false, disabled = false, initialize = false) {
        const form = this.form[name];
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + ' .component-' + name);
        instance.append('<input ' + (disabled ? 'disabled' : '') + ' ' + (form.tag ? 'lay-skin="tag"' : '') + '  lay-filter="' + this.unique + name + '"  type="checkbox" ' + (checked ? 'checked' : '') + ' value="' + val + '" name="' + name + '[]" title="' + title.replace(/(<([^>]+)>)/ig, "") + '">');

        if (!initialize) {
            layui.form.render(instance.find('input'));
        }
    }


    setCheckbox(name, val, checked) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + " .component-" + name + " input[value=" + val + "]");
        instance.prop('checked', checked);
        layui.form.render(instance);
        this.triggerOtherPopupChange(name, val, checked);
    }

    delCheckbox(name, val) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + " .component-" + name + " input[value=" + val + "]");
        instance.next().remove();
        instance.remove();
        layui.form.render($('.' + this.unique + ' .component-' + name + ' input[type=checkbox]'));
    }

    addRadio(name, val, title, checked = false, disabled = false) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + ' .component-' + name);
        instance.append('<input ' + (disabled ? 'disabled' : '') + ' lay-filter="' + this.unique + name + '"  type="radio" ' + (checked ? 'checked' : '') + ' value="' + val + '" name="' + name + '" title="' + title.replace(/(<([^>]+)>)/ig, "") + '">');
        layui.form.render(instance.find('input'));
    }

    setRadio(name, val, checked) {
        name = util.replaceDotWithHyphen(name);
        let main = $('.' + this.unique + ' .component-' + name + ' input[type=radio]');
        let instance = $('.' + this.unique + " .component-" + name + " input[value=" + val + "]");
        main.prop('checked', false);
        instance.prop('checked', checked);
        layui.form.render(main);
        this.triggerOtherPopupChange(name, val);
    }

    delRadio(name, val) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + " .component-" + name + " input[value=" + val + "]");
        instance.next().remove();
        instance.remove();
        layui.form.render($('.' + this.unique + ' .component-' + name + ' input[type=radio]'));
    }


    addOption(name, val, title, selected = false, initialize = false) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + ' .component-' + name + " select");
        instance.append('<option value="' + val + '"  ' + (selected ? 'selected' : '') + '>' + title.replace(/(<([^>]+)>)/ig, "") + '</option>');
        if (!initialize) {
            layui.form.render(instance);
        }
    }

    delOption(name, val) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + " .component-" + name + " select option[value=" + val + "]");
        instance.remove();
        layui.form.render($('.' + this.unique + ' .component-' + name + " select"));
    }


    clearOption(name) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + ' .component-' + name + " select");
        instance.html('<option value="">' + (this.form[name].placeholder ?? i18n('请选择')) + '</option>');
        layui.form.render(instance);
    }

    setSelected(name, val) {
        name = util.replaceDotWithHyphen(name);
        let main = $('.' + this.unique + ' .component-' + name + " select");
        main.val(val);
        layui.form.render(main);
        this.triggerOtherPopupChange(name, val);
    }

    addWidget(name, instance = null, val = {}) {
        name = util.replaceDotWithHyphen(name);
        this.widget.num++;
        let unique = util.generateRandStr(12);
        let _this = this;
        let after = true;

        if (!instance) {
            instance = $('.' + this.unique + ' .component-' + name);
            after = false;
        }


        let html = '' +
            '<div class="widget-block widget-block-' + unique + '">' +
            '<div class="widget-general widget-w120">' +
            '<select name="type-' + name + '[]" lay-filter="widget-type-' + unique + '">' +
            '<option ' + (val.type == "text" ? "selected" : "") + '  value="text">' + i18n("文本框") + '</option>' +
            '<option ' + (val.type == "password" ? "selected" : "") + ' value="password">' + i18n("密码框") + '</option>' +
            '<option ' + (val.type == "number" ? "selected" : "") + ' value="number">' + i18n("数字框") + '</option>' +
            '<option ' + (val.type == "select" ? "selected" : "") + ' value="select">' + i18n("下拉框") + '</option>' +
            '<option ' + (val.type == "checkbox" ? "selected" : "") + ' value="checkbox">' + i18n("多选框") + '</option>' +
            '<option ' + (val.type == "radio" ? "selected" : "") + ' value="radio">' + i18n("单选框") + '</option>' +
            '<option ' + (val.type == "textarea" ? "selected" : "") + ' value="textarea">' + i18n("文本域") + '</option>' +
            '</select></div> ' +
            '<input type="text"  name="title-' + name + '[]" placeholder="' + i18n("控件名称") + '" class="layui-input widget-general widget-w120" value="' + (val.hasOwnProperty("cn") ? val.cn : "") + '"> ' +
            '<input value="' + (val.name ?? "") + '" name="name-' + name + '[]" type="text" placeholder="' + i18n("英文名") + '" class="layui-input widget-general widget-w140"> ' +
            '<input value="' + (val.placeholder ?? "") + '" name="placeholder-' + name + '[]" type="text" placeholder="' + i18n("输入前提示内容") + '" class="layui-input widget-general widget-w160"> ' +
            '<input value="' + (val.regex ?? "") + '"  name="regex-' + name + '[]" type="text" placeholder="' + i18n("正则验证") + '" class="layui-input widget-general widget-w140"> ' +
            '<input value="' + (val.error ?? "") + '" name="error-' + name + '[]" type="text" placeholder="' + i18n("正则匹配错误提示") + '" class="layui-input widget-general widget-w160"> ' +
            '<div style="display: inline-block;margin-left: 2px;"><i class="layui-icon widget-add-' + unique + '" style="color: #23a148;cursor: pointer;font-size: 16px;font-weight: bold;">&#xe61f;</i> <i class="layui-icon widget-del-' + unique + '" style="color: #eb8181;cursor: pointer;font-size: 16px;font-weight: bold;">&#x1006;</i></div>' +
            '<textarea  name="data-' + name + '[]" type="text" placeholder="' + i18n("请提供配置可选择的多个数据，例子：&#10;大熊猫=dxm,小熊猫=xxm&#10;多个数据使用逗号分割，格式：[显示名称]=[数据内容]") + '" class="layui-textarea widget-data widget-data-' + unique + '">' + (val.dict ?? "") + '</textarea> ' +
            '</div>';

        after ? instance.after(html) : instance.append(html);

        let widgetDataDomInstance = $('.widget-data-' + unique);

        layui.form.on('select(widget-type-' + unique + ')', event => {
            switch (event.value) {
                case 'select':
                case 'checkbox':
                case 'radio':
                    widgetDataDomInstance.show(150);
                    break;
                default:
                    widgetDataDomInstance.hide(150);
            }
        });

        $('.widget-add-' + unique).click(function () {
            _this.addWidget(name, $(this).parent().parent(), {});
        });

        $('.widget-del-' + unique).click(function () {
            if (_this.widget.num <= 1) {
                layer.msg("(⁎˃ᆺ˂)" + i18n("饶命，请留下最后一只独苗"));
                return;
            }
            let dom = $(this).parent().parent();
            dom.fadeOut('fast', function () {
                dom.remove();
                _this.widget.num--;
            });
        });

        $('.widget-block-' + unique).show(150);

        if ((val.type == "select" || val.type == "checkbox" || val.type == "radio")) {
            widgetDataDomInstance.show();
        }

        layui.form.render();
    }

    addAttribute(name, instance = null, val = {}) {
        name = util.replaceDotWithHyphen(name);
        this.attribute.num++;
        let unique = util.generateRandStr(12);
        let _this = this;
        let after = true;

        if (!instance) {
            instance = $('.' + this.unique + ' .component-' + name);
            after = false;
        }

        let html = '' +
            '<div class="widget-block widget-block-' + unique + '">' +
            '<input value="' + (val.name ?? "") + '" name="name-' + name + '[]" type="text" placeholder="' + i18n("属性名称") + '" class="layui-input widget-general widget-w220"> ' +
            '<input value="' + (val.value ?? "") + '" name="value-' + name + '[]" type="text" placeholder="' + i18n("属性内容") + '" class="layui-input widget-general widget-w500"> ' +
            '<div style="display: inline-block;margin-left: 2px;"><i class="layui-icon widget-add-' + unique + '" style="color: #23a148;cursor: pointer;font-size: 16px;font-weight: bold;">&#xe61f;</i> <i class="layui-icon widget-del-' + unique + '" style="color: #eb8181;cursor: pointer;font-size: 16px;font-weight: bold;">&#x1006;</i></div>' +
            '</div>';

        after ? instance.after(html) : instance.append(html);

        $('.widget-add-' + unique).click(function () {
            _this.addAttribute(name, $(this).parent().parent(), {});
        });

        $('.widget-del-' + unique).click(function () {
            if (_this.attribute.num <= 1) {
                layer.msg("(⁎˃ᆺ˂)" + i18n("饶命，请留下最后一只独苗"));
                return;
            }
            let dom = $(this).parent().parent();
            dom.fadeOut('fast', function () {
                dom.remove();
                _this.attribute.num--;
            });
        });

        $('.widget-block-' + unique).show(150);

        layui.form.render();
    }

    setSwitch(name, checked) {
        name = util.replaceDotWithHyphen(name);
        let instance = $('.' + this.unique + " .component-" + name + " input[type=checkbox]");
        instance.prop('checked', checked);
        layui.form.render(instance);
        this.triggerOtherPopupChange(name, checked);
    }

    setEditor(name, val) {
        name = util.replaceDotWithHyphen(name);
        cache.get(this.unique + name).setHtml(val);
    }

    setHtml(name, val) {
        name = util.replaceDotWithHyphen(name);
        cache.get(this.unique + name).setValue(val);
    }

    triggerOtherPopupChange(name, ...arg) {
        name = util.replaceDotWithHyphen(name);
        let form = this.form[name];
        (form && form.change) && form.change(this, ...arg);
    }

    setImage(name, val) {
        name = util.replaceDotWithHyphen(name);
        const form = this.form[name];

        $(`.${this.unique} .component-${form.name} input[name=${form.name}]`).val(val);
        this.uploadImage({
            container: `.${this.unique} .component-${form.name} .image-render`,
            imageUrl: val,
            title: form.placeholder,
            height: form.height,
            uploadUrl: form.uploadUrl,
            input: `.${this.unique} .component-${form.name} input[name=${form.name}]`,
            change: (url, data) => {
                this.setData(form.name, url);
                form.change && form.change(this, url, data);
            }
        });
    }

    uploadImage(opt = {}) {
        const layUpload = layui.upload;
        const imageContainer = $(opt.container);
        const inputContainer = $(opt.input);
        if (opt.imageUrl) {
            imageContainer.html('<img class="image-upload" src="' + opt.imageUrl + '" alt="' + opt.title + '" style="height:' + (opt.height ?? 200) + 'px;">');
        } else {
            imageContainer.html('<button type="button" class="layui-btn btn-upload image-upload">' + util.icon("fa-duotone fa-regular fa-camera me-1 text-white fs-5") + opt.title + '</button>');
        }
        layUpload.render({
            elem: opt.container + ' .image-upload'
            , url: util.appendParamToUrl(opt.uploadUrl, "mime=image")
            , accept: 'images'
            , acceptMime: 'image/*'
            , exts: 'jpg|png|gif|bmp|jpeg|ico|webp'
            , size: 1024 * 50
            , done: res => {
                if (res.code === 200) {
                    opt.imageUrl = res.data.url;
                    inputContainer.val(res.data.url);
                    opt.change && opt.change(res.data.url, res.data);
                    this.uploadImage(opt);
                    return;
                }
                opt.imageUrl = null;
                layer.msg(res.msg);
                this.uploadImage(opt);
            }
            , progress: function (n) {
                let percent = n + '%';
                imageContainer.html('<div class="layui-progress layui-progress-fileUpload" lay-showpercent="true"><div class="layui-progress-bar" lay-percent="' + percent + '" style="width: ' + percent + ';"><span class="layui-progress-text">' + (n >= 100 ? 'RTX4090TI渲染中..' : percent) + '</span></div></div>');
            }
        });
    }

    uploadFile(opt = {}) {
        const layUpload = layui.upload;
        const fileContainer = $(opt.container);
        const inputContainer = $(opt.input);
        let startTime, startBytes, file, fileSize;

        opt.title = opt.fileUrl ? opt.fileUrl.split('/').slice(-1) : opt.title;
        let classes = 'btn-upload';

        if (!opt.form.title) {
            classes = "btn-upload-plus";
        }

        fileContainer.html('<button type="button" data-percentage="0" class="layui-btn ' + classes + ' file-upload"><i class="layui-icon layui-icon-file-b"></i> <span class="file-text">' + opt.title + '</span></button>');

        let $options = {
            elem: opt.container + ' .file-upload'
            , url: util.appendParamToUrl(opt.uploadUrl, "mime=other")
            , accept: 'file'
            , acceptMime: '*/*'
            , done: res => {
                if (res.code === 200) {
                    inputContainer.val(res.data.url);
                    opt.change && opt.change(res.data.url, res.data);
                    opt.fileUrl = res.data.url;
                    this.uploadFile(opt);
                    return;
                }
                opt.fileUrl = null;
                layer.msg(res.msg);
                this.uploadFile(opt);
            }
            , before: (obj) => {
                startTime = new Date().getTime();
                startBytes = 0;
                let files = obj.pushFile();
                file = files[Object.keys(files)[0]];
                fileSize = file.size;
                fileContainer.find('.file-upload').attr("disabled", true);
            }
            , progress: function (n) {
                let uploadProgress = util.getUploadProgress(fileSize, startTime, n / 100);
                let instance = fileContainer.find('.file-upload');

                let percent = '<span class="block-size-22 text-color-d044f1">' + util.icon("icon-round-loading", "icon-spin") + ' 进度:' + n + '%</span>' +
                    '<span class="block-size-22 text-color-ff7991">' + util.icon("icon-119") + ' 上行:' + uploadProgress.speed + '</span>' +
                    '<span class="block-size-34 text-color-6079ff">' + util.icon("icon-wenjianjia") + ' 已上传:' + uploadProgress.size + '</span>' +
                    '<span class="block-size-22 text-color-f38815">' + util.icon("icon-shijian") + ' 已用时:' + uploadProgress.time + '</span>';

                instance.css("width", "100%");
                util.updateProgress(instance, n);
                if (n >= 100) {
                    instance.attr("disabled", false);
                    percent = "正在读取文件信息..";
                }

                instance.html(percent);
            }
        };

        if (opt.form.setting) {
            for (const settingKey in opt.form.setting) {
                $options[settingKey] = opt.form.setting[settingKey];
            }
        }

        layUpload.render($options);
    }


    getTab() {
        return this.tab;
    }

    getUnique() {
        return this.unique;
    }

    getData(target = null) {
        let obj = {};
        let _this = this;
        this.opt.tab.forEach((item, index) => {
            let serializeArray = util.arrayToObject($('.' + _this.unique + index).serializeArray());
            obj = Object.assign(obj, serializeArray);
        });

        this.opt.tab.forEach((item, index) => {
            item.form.forEach((form, ix) => {
                switch (form.type) {
                    case 'checkbox':
                    case 'treeCheckbox':
                        !obj.hasOwnProperty(form.name) && (obj[form.name] = []);
                        break;
                    case 'treeSelect':
                        (this.opt.hasOwnProperty("assign") && this.opt.assign.id == obj[form.name]) && (delete obj[form.name]);
                        break;
                    case 'switch':
                        !obj.hasOwnProperty(form.name) && (obj[form.name] = 0);
                        break;
                    case 'input':
                        let color = cache.get(_this.unique + form.name + 'color');
                        let bold = cache.get(_this.unique + form.name + 'bold');

                        if (color || bold) {
                            let css = '';
                            if (color) {
                                css += 'color: ' + color + ';';
                            }
                            if (bold) {
                                css += 'font-weight: bold;';
                            }
                            obj[form.name] = '<span style=\'' + css + '\'>' + obj[form.name] + '</span>';
                        }
                        break;
                    case 'widget':
                        let json = [];
                        obj["name-" + form.name].forEach((name, index) => {
                            if (name != "") {
                                json.push({
                                    cn: obj["title-" + form.name][index],
                                    name: name,
                                    placeholder: obj["placeholder-" + form.name][index],
                                    type: obj["type-" + form.name][index],
                                    regex: obj["regex-" + form.name][index],
                                    error: obj["error-" + form.name][index],
                                    dict: obj["data-" + form.name][index]
                                });
                            }
                        });
                        delete obj["title-" + form.name];
                        delete obj["placeholder-" + form.name];
                        delete obj["type-" + form.name];
                        delete obj["regex-" + form.name];
                        delete obj["error-" + form.name];
                        delete obj["name-" + form.name];
                        delete obj["data-" + form.name];
                        obj[form.name] = encodeURIComponent(JSON.stringify(json));
                        break;
                    case 'attribute':
                        let attributes = [];
                        obj["name-" + form.name].forEach((name, index) => {
                            if (name != "") {
                                attributes.push({
                                    name: obj["name-" + form.name][index],
                                    value: obj["value-" + form.name][index]
                                });
                            }
                        });
                        delete obj["name-" + form.name];
                        delete obj["value-" + form.name];
                        obj[form.name] = encodeURIComponent(JSON.stringify(attributes));
                        break;
                    case 'html':
                        obj[form.name] = this.getMap(form.name);
                        break;
                }

                if (form.submit === false) {
                    delete obj[form.name];
                }
            });
        });

        (this.opt.hasOwnProperty('assign') && this.opt.assign.hasOwnProperty('id')) && (obj.id = this.opt.assign.id);


        const data = util.parseNestedKeysFromJSON(obj);


        delete data.btSelectAll;
        delete data.btSelectItem;

        if (!target) {
            return data;
        }

        return util.parseStringObject(data, util.replaceDotWithHyphen(target));
    }

    registerEvent() {
        let opt = this.opt;
        opt.tab.forEach((item, index) => {
            item.form.forEach((form, ix) => {
                //   (opt.hasOwnProperty('assign') && opt.assign.hasOwnProperty(form.name)) && (form.default = opt.assign[form.name]);
                (opt.hasOwnProperty('assign') && util.checkPropertyExistence(opt.assign, form.name)) && (form.default = util.parseStringObject(opt.assign, form.name));
                let instance = null;
                this.setData(form.name, form.default);
                switch (form.type) {
                    case 'input':
                    case 'number':
                    case 'password':
                        this.inputRegister(form);
                        break;
                    case 'date':
                        this.dateRegister(form);
                        break;
                    case 'textarea':
                        this.textareaRegister(form);
                        break;
                    case 'checkbox':
                        this.checkboxRegister(form);
                        break;
                    case 'radio':
                        this.radioRegister(form);
                        break;
                    case 'switch':
                        this.switchRegister(form);
                        break;
                    case 'select':
                        this.selectRegister(form);
                        break;
                    case 'editor':
                        this.editorRegister(form);
                        break;
                    case 'html':
                        this.htmlRegister(form);
                        break;
                    case 'image':
                        this.imageRegister(form);
                        break;
                    case 'file':
                        this.fileRegister(form);
                        break;
                    case 'treeCheckbox':
                        this.treeCheckboxRegister(form);
                        break;
                    case 'treeSelect':
                        this.treeSelectRegister(form);
                        break;
                    case 'widget':
                        this.widgetRegister(form);
                        break;
                    case 'attribute':
                        this.attributeRegister(form);
                        break;
                    case 'custom':
                        this.customRegister(form);
                        break;
                }
                this.tipsRegister(form);
                this.registerBlockCss(form);
            });
        });

        layui.form.render();
    }

    tipsRegister(form) {
        if (form.tips) {
            let tipsIndex = 0;
            $('.' + this.unique + ' .component-' + form.name).hover(function () {
                tipsIndex = layer.tips(i18n(form.tips), this, {
                    tips: [1, '#501536'],
                    time: 0
                });
            }, function () {
                layer.close(tipsIndex);
            });
        }
    }

    inputRegister(form) {
        //监听input值改变事件
        let instance = $('.' + this.unique + ' input[name=' + form.name + ']');
        let _this = this;

        instance.change(function () {
            let val = $(this).val();
            _this.setData(form.name, val);
            form.change && form.change(_this, val);
        });

        form.complete && form.complete(this, instance.val());
    }


    dateRegister(form) {
        let instance = $(`.${this.unique} input[name=${form.name}]`);
        let _this = this;
        layui.laydate.render({
            elem: `.${this.unique} input[name=${form.name}]`,
            type: 'datetime'
        });

        instance.change(function () {
            let val = $(this).val();
            _this.setData(form.name, val);
            form.change && form.change(_this, val);
        });
        form.complete && form.complete(this, instance.val());
    }

    textareaRegister(form) {
        let instance = $('.' + this.unique + ' textarea[name=' + form.name + ']');
        let _this = this;
        instance.change(function () {
            let val = $(this).val();
            _this.setData(form.name, val);
            form.change && form.change(_this, val, instance);
        });
        form.complete && form.complete(_this, instance.val(), instance);
    }

    checkboxRegister(form) {
        let _this = this;
        let val = [];

        if (util.checkPropertyExistence(this.opt.assign, form.name)) {
            val = util.parseStringObject(this.opt.assign, form.name);
        } else if (typeof form.default == "object") {
            val = form.default;
        }

        _Dict.advanced(form.dict, res => {
            _this.clearComponent(form.name);
            res.forEach(s => {
                _this.addCheckbox(form.name, s.id, s.name, val.indexOf(s.id) !== -1 || val.indexOf(s.id.toString()) !== -1, form.disable ? form.disable.includes(s.id) : false, true);
            });
            layui.form.on('checkbox(' + _this.unique + form.name + ')', event => {
                _this.setData(form.name, event);
                form.change && form.change(_this, event.value, event.elem.checked);
            });
            form.complete && form.complete(_this, form.default ?? []);
            layui.form.render();
        });
    }

    radioRegister(form) {
        let _this = this;
        _Dict.advanced(form.dict, res => {
            let checkedValue = null;
            _this.clearComponent(form.name);
            res.forEach((s, index) => {
                let checked = s.id == form.default || index == 0;
                checked && (checkedValue = s.id);
                _this.addRadio(form.name, s.id, s.name, checked, form.disable ? form.disable.includes(s.id) : false);
            });
            form.complete && form.complete(_this, checkedValue);
        });

        layui.form.on('radio(' + _this.unique + form.name + ')', event => {
            _this.setData(form.name, event.value);
            form.change && form.change(_this, event.value);
        });
        layui.form.render();
    }

    switchRegister(form) {
        let _this = this;
        layui.form.on('switch(' + _this.unique + form.name + ')', event => {
            _this.setData(form.name, event.elem.checked);
            form.change && form.change(_this, event.elem.checked);
        });
        form.complete && form.complete(_this, form.default == "1");
        layui.form.render();
    }

    selectRegister(form) {
        let _this = this;
        _Dict.advanced(form.dict, res => {
            res.forEach((s, index) => {
                _this.addOption(form.name, s.id, s.name, s.id == form.default, true);
            });
            form.complete && form.complete(_this, form.default ?? null);
            layui.form.render();
        });

        layui.form.on('select(' + _this.unique + form.name + ')', event => {
            _this.setData(form.name, event.value);
            form.change && form.change(_this, event.value);
        });
    }

    editorRegister(form) {
        let _this = this, wangEditor = window.wangEditor;
        const editor = new wangEditor(`.${_this.unique} .component-${form.name} .editor-container`);

        const textarea = $('.' + _this.unique + ' .component-' + form.name + ' .text-container');
        const htmlContainer = $('.' + _this.unique + ' .component-' + form.name + ' .html-container');
        const editorContent = $('.' + _this.unique + ' .component-' + form.name + ' .editor-content');
        const editorWrapper = $('.' + _this.unique + ' .component-' + form.name + ' .editor-wrapper');
        editor.config.onchange = function (html) {
            textarea.val(html);
        }
        editor.config.zIndex = 0;
        editor.config.uploadFileName = 'file';
        editor.config.uploadImgServer = form.uploadUrl + "?mime=image";
        editor.config.uploadImgAccept = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        editor.config.uploadImgMaxLength = 1;
        editor.config.uploadImgTimeout = 60 * 1000;
        editor.config.uploadImgMaxSize = 50 * 1024 * 1024; //50M
        editor.config.uploadImgHooks = {
            customInsert: function (insertImgFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertImgFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("图片上传失败，文件可能过大");
            },
        }
        editor.config.uploadVideoServer = form.uploadUrl + "?mime=video";
        editor.config.uploadVideoName = 'file'
        editor.config.uploadVideoHooks = {
            customInsert: function (insertVideoFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertVideoFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("视频上传失败，文件可能过大");
            },
        }

        if (form.hasOwnProperty("height")) {
            editor.config.height = form.height;
        } else {
            editor.config.height = 480;
        }

        editor.create();
        form.default && editor.txt.html(form.default);
        form.default && textarea.val(form.default);


        $('.' + _this.unique + ' .component-' + form.name + ' .button-switch-' + form.name).click(function () {
            let _obj = $(this);
            let type = _obj.attr("data-type");
            if (type == 0) {
                const toolbarWidth = $(`.${_this.unique} .component-${form.name} .editor-container .w-e-toolbar`).width();
                const heightDifference = toolbarWidth > 1000 ? 40 : 80;

                _obj.attr("data-type", 1);
                _obj.html('<i class="fa-duotone fa-regular fa-pen-paintbrush me-1"></i>' + i18n("写作"));
                editorWrapper.append(`<div id="${_this.unique}-${form.name}-html" style="margin-top:10px;width:100%;height: ${form.height ? form.height + heightDifference + "px" : `${480 + heightDifference}px`} "></div>`);
                const editor = ace.edit(`${_this.unique}-${form.name}-html`, {
                    theme: "ace/theme/chrome",
                    mode: "ace/mode/html"
                });
                editor.getSession().setUseWrapMode(true);
                editor.setOption("showPrintMargin", false);
                editor.setValue(textarea.val());
                editor.getSession().on('change', function (delta) {
                    const currentContent = editor.getValue();
                    textarea.val(currentContent);
                    form.change && form.change(_this, currentContent);
                });
                editorContent.hide();
                htmlContainer.fadeIn(150);
            } else {
                _obj.attr("data-type", 0);
                _obj.html('<i class="fa-duotone fa-regular fa-code me-1"></i>HTML');
                editor.txt.html(textarea.val());
                $(`#${_this.unique}-${form.name}-html`).remove();
                editorContent.fadeIn(150);
            }
        });


        form.complete && form.complete(_this, form.default);
        cache.set(_this.unique + form.name, editor);

        layui.form.render();
    }

    htmlRegister(form) {
        let _this = this;
        if (['html', 'javascript', 'css'].includes(form.language ?? "html")) {
            util.loadScripts(`/assets/common/js/editor/code/lib/beautify/${form.language ?? "html"}.js`);
        }
        const editor = ace.edit(`${this.unique}-${form.name}-editor`, {
            theme: "ace/theme/chrome",
            mode: "ace/mode/" + (form.language ?? "html")
        });
        editor.commands.addCommand({
            name: 'formatCode',
            bindKey: {win: 'Ctrl-Alt-L', mac: 'Command-Option-L'},
            exec: function (editor) {
                let code = editor.getValue();
                switch (form.language ?? "html") {
                    case "html":
                        code = html_beautify(code, {indent_size: 2});
                        break;
                    case "javascript":
                        code = js_beautify(code, {indent_size: 2});
                        break;
                    case "css":
                        code = css_beautify(code, {indent_size: 2});
                        break;
                }
                editor.setValue(code, -1);
            }
        });

        if (form.autoWrap === true) {
            editor.getSession().setUseWrapMode(true);
        }

        editor.setOption("showPrintMargin", false);
        editor.getSession().on('change', function (delta) {
            const currentContent = editor.getValue();
            _this.setData(form.name, currentContent);
            form.change && form.change(_this, currentContent);
        });

        form.default && editor.setValue(form.default);
        form.default && (_this.setData(form.name, form.default));
        form.complete && form.complete(_this, form.default);
        if (form.disabled) {
            editor.setReadOnly(true);
            editor.renderer.$cursorLayer.element.style.display = "none";
        }
        cache.set(this.unique + form.name, editor);
    }

    imageRegister(form) {
        let _this = this;
        this.uploadImage({
            container: `.${_this.unique} .component-${form.name} .image-render`,
            imageUrl: form.default,
            title: form.placeholder,
            height: form.height,
            uploadUrl: form.uploadUrl,
            input: $(`.${_this.unique} .component-${form.name} input[name=${form.name}]`),
            change: (url, data) => {
                _this.setData(form.name, url);
                form.change && form.change(_this, url, data);
            }
        });
        form.complete && form.complete(_this, form.default);

        let tipsIndex, externalInputTipsIndex;
        const $externalInput = $(`.${_this.unique} .block-${form.name} .external-input`);
        $externalInput.click(() => {
            const defaultUrl = $(`.${this.unique} .component-${form.name} input[name=${form.name}]`).val();
            component.popup({
                submit: (data, index) => {
                    if (!data.url) {
                        layer.msg("外链不能为空");
                        return;
                    }
                    _this.setImage(form.name, data.url);
                    form.change && form.change(_this, data.url, {
                        append: {thumb_url: data.url}
                    });
                    layer.close(index);
                },
                tab: [
                    {
                        name: util.icon('fa-duotone fa-regular fa-link') + " 设置外部图片链接",
                        form: [
                            {
                                title: false,
                                name: "url",
                                type: "input",
                                placeholder: "图片外链，需要 http:// 或 https:// 开头",
                                tips: "图片外链，需要 http:// 或 https:// 开头",
                                default: defaultUrl
                            }
                        ]
                    },

                ],
                autoPosition: true,
                height: "auto",
                width: "560px",
                maxmin: false,
                shadeClose: true,
                assign: {}
            });
        });

        $externalInput.hover(function () {
            externalInputTipsIndex = layer.tips(i18n("外部链接"), this, {
                tips: [2, '#501536'],
                time: 0
            });
        }, function () {
            layer.close(externalInputTipsIndex);
        });

        if (form.photoAlbumUrl) {
            //注册相册
            const $photoAlbum = $(`.${_this.unique} .block-${form.name} .photo-album`);
            $photoAlbum.click(function () {
                let popupIndex = null;
                component.popup({
                    submit: false,
                    tab: [
                        {
                            name: util.icon("fa-duotone fa-regular fa-image") + " 相册",
                            form: [
                                {
                                    name: "photo_album",
                                    type: "custom",
                                    complete: (pop, dom) => {
                                        dom.html(`<div class="block-content"><table id="photo-album-table"></table>`);
                                        const table = new Table(form.photoAlbumUrl, dom.find('#photo-album-table'));
                                        table.setPagination(30, [30, 50, 200, 500, 1000]);
                                        table.setWhere("equal-type", "image");
                                        table.setWhere("display_scope", 1);
                                        table.setColumns([
                                            {
                                                field: 'path', title: '', formatter: (path, item) => {
                                                    return `<img class="photo-album-selected" src="${item.thumb_url ?? path}">`;
                                                },
                                                events: {
                                                    'click .photo-album-selected': (event, path, item) => {
                                                        _this.setImage(form.name, path);
                                                        layer.close(popupIndex);
                                                        form.change && form.change(_this, path, {
                                                            append: {thumb_url: item.thumb_url ?? path}
                                                        });
                                                    }
                                                }
                                            },
                                        ]);
                                        table.render();
                                    }
                                },
                            ]
                        }
                    ],
                    assign: {},
                    autoPosition: true,
                    shadeClose: true,
                    maxmin: false,
                    width: "730px",
                    renderComplete: (unique, index) => {
                        popupIndex = index;
                        $(`.${unique} .layui-card-body`).css("padding-top", "0").find(".block-content").css("padding", "0");
                    }
                });
            });
            $photoAlbum.hover(function () {
                tipsIndex = layer.tips(i18n("相册"), this, {
                    tips: [2, '#501536'],
                    time: 0
                });
            }, function () {
                layer.close(tipsIndex);
            });
        }

        layui.form.render();
    }

    fileRegister(form) {
        this.uploadFile({
            form: form,
            container: '.' + this.unique + ' .component-' + form.name + ' .file-render',
            fileUrl: form.default,
            title: form.placeholder,
            height: form.height,
            uploadUrl: form.uploadUrl,
            input: '.' + this.unique + ' .component-' + form.name + ' input[name=' + form.name + ']',
            change: (url, data) => {
                this.setData(form.name, url);
                form.change && form.change(url, data);
            }
        });
        form.complete && form.complete(this, form.default);
        layui.form.render();
    }

    treeCheckboxRegister(form) {
        let _this = this;
        _Dict.advanced(form.dict, res => {
            layui.authtree.render('.' + _this.unique + ' .component-' + form.name + ' .treeCheckbox', res, {
                inputname: form.name + '[]'
                , layfilter: _this.unique + form.name
                , childKey: 'children'
                , valueKey: 'id'
                , 'theme': 'auth-skin-universal'
                , autowidth: true
                , openchecked: false
                , autochecked: true
                , checkedKey: form.default ?? []
            });
            layui.authtree.on('change(' + _this.unique + form.name + ')', function (data) {
                let checked = layui.authtree.getChecked('.' + _this.unique + ' .component-' + form.name + ' .treeCheckbox');
                _this.setData(form.name, checked);
                form.change && form.change(_this, checked);
            });

            form.complete && form.complete(_this, form.default);
        });

        layui.form.render();
    }

    treeSelectRegister(form) {
        let _this = this;
        layui.treeSelect.render({
            // 选择器
            elem: '.' + _this.unique + ' .component-' + form.name + ' .tree-select',
            // 数据
            data: form.dict,
            // 异步加载方式：get/post，默认get
            //type: 'post',
            // 占位符
            placeholder: form.placeholder,
            // 是否开启搜索功能：true/false，默认false
            search: true,
            //禁用父级
            parent: form?.parent ?? true,
            // 点击回调
            click: function (d) {
                $('.' + _this.unique + "  .component-" + form.name + " input[name=" + form.name + "]").val(d.current.id);
                form.change && form.change(_this, d.current.id);
            },
            // 加载完成后的回调函数
            success: function (d) {
                if (form.default) {
                    layui.treeSelect.checkNode(_this.unique + form.name, parseInt(form.default));
                }
                form.complete && form.complete(_this, form.default);
            }
        });

        layui.form.render();
    }


    widgetRegister(form) {
        this.clearComponent(form.name);
        let preset = form.default ? JSON.parse(form.default) : [];
        if (preset.length <= 0) {
            this.addWidget(form.name);
        } else {
            preset.forEach(widget => {
                this.addWidget(form.name, null, widget);
            });
        }
        form.complete && form.complete(this, form.default);
        layui.form.render();
    }

    attributeRegister(form) {
        let preset = form.default ? JSON.parse(form.default) : [];
        if (preset.length <= 0) {
            this.addAttribute(form.name);
        } else {
            preset.forEach(widget => {
                this.addAttribute(form.name, null, widget);
            });
        }
        form.complete && form.complete(this, form.default);
        layui.form.render();
    }

    customRegister(form) {
        form.complete && form.complete(this, $('.' + this.unique + ' .component-' + form.name));
    }

    createForm(form, targetName, sequence = "after") {
        form.title && (form.title = i18n(form.title));
        form.name = util.replaceDotWithHyphen(form.name);

        this.data[form.name] = {
            hide: form.hide ? ' hide' : '',
            titleHide: !form.title ? 'hide' : '',
            blockMarginZero: !form.title ? "margin-left-zero" : ''
        }

        let d = "";
        switch (form.type) {
            case 'input':
                d = this.inputHtml(form, "text");
                break;
            case 'date':
                d = this.inputHtml(form, 'text');
                break;
            case 'number':
                d = this.inputHtml(form, "number");
                break;
            case 'password':
                d = this.inputHtml(form, "password");
                break;
            case 'textarea':
                d = this.textareaHtml(form);
                break;
            case 'checkbox':
                d = this.getBlockHtml(form, util.icon("icon-loading", "icon-spin", "icon-18px"));
                break;
            case 'radio':
                d = this.getBlockHtml(form, util.icon("icon-loading", "icon-spin", "icon-18px"));
                break;
            case 'switch':
                d = this.switchHtml(form)
                break;
            case 'select':
                d = this.selectHtml(form);
                break;
            case 'editor':
                d = this.editorHtml(form);
                break;
            case 'html':
                d = this.htmlHtml(form);
                break;
            case 'image':
                d = this.imageHtml(form);
                break;
            case 'file':
                d = this.fileHtml(form);
                break;
            case 'treeCheckbox':
                d = this.treeCheckboxHtml(form);
                break;
            case 'treeSelect':
                d = this.treeSelectHtml(form);
                break;
            case 'widget':
                d = this.getBlockHtml(form);
                break;
            case 'custom':
                d = this.getBlockHtml(form);
                break;
        }


        let instance = $('.' + this.unique + " .block-" + targetName);
        if (sequence == "after") {
            instance.after(d);
        } else {
            instance.before(d);
        }

        switch (form.type) {
            case 'input':
            case 'number':
            case 'password':
                this.inputRegister(form);
                break;
            case 'date':
                this.dateRegister(form);
                break;
            case 'textarea':
                this.textareaRegister(form);
                break;
            case 'checkbox':
                this.checkboxRegister(form);
                break;
            case 'radio':
                this.radioRegister(form);
                break;
            case 'switch':
                this.switchRegister(form);
                break;
            case 'select':
                this.selectRegister(form);
                break;
            case 'editor':
                this.editorRegister(form);
                break;
            case 'html':
                this.htmlRegister(form);
                break;
            case 'image':
                this.imageRegister(form);
                break;
            case 'file':
                this.fileRegister(form);
                break;
            case 'treeCheckbox':
                this.treeCheckboxRegister(form);
                break;
            case 'treeSelect':
                this.treeSelectRegister(form);
                break;
            case 'widget':
                this.widgetRegister(form);
                break;
            case 'custom':
                this.customRegister(form);
                break;
        }

        layui.form.render();
        this.form[form.name] = form;
    }

    removeForm(name) {
        let instance = $('.' + this.unique + " .block-" + name);
        instance.remove();
    }

    getDomHeight(name) {
        let instance = $('.' + this.unique + ' .block-' + name);
        return instance.height();
    }

    registerBlockCss(form) {
        let instance = $("." + this.unique + " .block-" + form.name);
        if (form.css) {
            for (const cssKey in form.css) {
                instance.css(cssKey, form.css[cssKey]);
            }
        }
    }
}

