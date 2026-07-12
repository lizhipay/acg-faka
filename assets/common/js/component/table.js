/* Shared bootstrap-table Chinese copy. Theme layers loaded after this file may
 * replace the empty-state markup while keeping the same common defaults. */
(function initCommonTableLocale() {
    const $ = window.jQuery;
    if (!$ || !$.fn || !$.fn.bootstrapTable || $.fn.bootstrapTable.__acgCommonLocale) {
        return;
    }

    $.fn.bootstrapTable.__acgCommonLocale = true;
    $.extend($.fn.bootstrapTable.defaults, {
        formatNoMatches: function () {
            return '<div class="component-table-empty">' +
                '<span class="component-table-empty__icon" aria-hidden="true">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M4 4h16v14H4z"></path><path d="M4 13h4l2 3h4l2-3h4"></path>' +
                '</svg></span><span>' + i18n('暂无数据') + '</span></div>';
        },
        formatLoadingMessage: function () {
            return i18n('正在加载') + '..';
        },
        formatShowingRows: function (from, to, total) {
            return total > 0 ? '第 ' + from + ' - ' + to + ' 条 · 共 ' + total + ' 条' : '共 0 条';
        },
        formatRecordsPerPage: function (number) {
            return '每页 ' + number + ' 条';
        }
    });
}());

class Table {
    #handleStateButtonClick($this, table) {
        $(`.${table.unique}-query .table-switch-state button`).removeClass("active");
        $this.addClass("active");
        let value = $this.attr("data-value");
        let data = {};
        if (value !== undefined) {
            data["equal-" + table.stateField] = value;
        } else {
            data["equal-" + table.stateField] = "";
        }
        table.reload({pageNumber: 1, query: data});
    }

    #isRowDetailOpen(index) {
        let $tr = this.$table.find('tr[data-index="' + index + '"]');
        let $detailView = $tr.next('.detail-view');
        return $detailView.length > 0;
    }

    #createRequest() {
        if (typeof this.queryUrl == "string") {
            this.options.url = this.queryUrl;
            this.options.method = "post";
        } else {
            this.options.data = this.queryUrl;
        }
    }

    constructor(urlOrData, container) {
        //表单构造参数
        this.options = {container: container};

        //列数据
        this.columns = [];
        this.handleColumns = {};

        this.$deleteSelector = null;
        this.$table = $(container); // 表格容器
        this.queryParams = null; // 查询参数
        this.secret = CryptoJS.MD5(new Date().getTime().toString()).toString();
        this.unique = util.generateRandStr(8); // 唯一标识

        // 详情显示
        this.detail = null;
        this.isShowDetail = false;

        //悬浮消息
        this.floatMessage = null;
        this.isFloatMessage = false;
        this.floatMessageMap = {};

        //按钮版详细内容
        this.isShowButtonDetail = false;
        this.buttonDetail = {}

        //列详情弹窗（绑定到某列的单击/双击）
        this.isColumnDetail = false;
        this.columnDetail = null;

        // 是否显示工具栏
        this.isShowToolbar = false;
        this.layuiForm = layui.form;

        //搜索功能
        this.search = null;

        //添加工具栏
        this.$table.append(`<div class="${this.unique}-query"></div>`);
        this.$toolbar = $(`.${this.unique}-query`);

        // 状态切换工具栏
        this.$state = null;
        this.stateField = null;

        //分页数据
        this.pagination = {size: 10, list: [10, 20, 50, 100, 500, 1000]};
        this.isPagination = true;

        //是否可以单选
        this.singleSelect = false;

        this.cardView = false;


        //回调方法
        this.fn = {
            complete: [],
            response: []
        }


        this.queryUrl = urlOrData; //查询地址
        this.updateUrl = null; //更新地址
        this.response = null; //返回数据
        this.handleData = {}; //处理过的返回数据，ID键值对
        //如果查询地址是静态数据
        if (typeof this.queryUrl == "object") {
            this.queryUrl.forEach(item => {
                this.handleData[item.id] = item;
            });
        }

        this.isTreeTable = false; //是否树形表格
    }

    setWhere(field, value) {
        if (!this.queryParams) {
            this.queryParams = {};
        }
        this.queryParams[field] = value;
    }

    setColumns(columns) {
        const hackTable = getVar("HACK_ROUTE_TABLE_COLUMNS");
        if (hackTable instanceof Array) {
            hackTable.forEach(item => {
                if (this.queryUrl === item.route) {
                    for (let i = 0; i < columns.length; i++) {
                        const column = columns[i];
                        if (column.field === item.field) {
                            if (item.direction === "after") {
                                columns.splice(i + 1, 0, evalResults(item.code));
                            } else {
                                columns.splice(i, 0, evalResults(item.code));
                                i++;
                            }
                        }
                    }
                }
            });
        }
        this.columns = this.columns.concat(this.#getPreprocessColumns(columns));
    }

    /**
     * 设置分页数据
     * @param size
     * @param list
     */
    setPagination(size, list) {
        this.pagination.size = size;
        this.pagination.list = list;
    }

    /**
     * 关闭分页
     */
    disablePagination() {
        this.isPagination = false;
    }

    setDetail(columnsOrCallback) {
        this.isShowDetail = true;
        this.columns.unshift(this.#getPreprocessColumn({
            field: 'detail_view',
            title: "",
            width: 55,
            type: 'button',
            buttons: [{
                icon: 'fa-duotone fa-regular fa-plus',
                class: "detail-view",
                click: (event, value, row, index) => {
                    let dom = $(event.currentTarget);
                    if (!this.#isRowDetailOpen(index)) {
                        this.$table.bootstrapTable('expandRow', index);
                        dom.fadeOut(100, function () {
                            dom.html(util.icon("fa-duotone fa-regular fa-minus")).fadeIn(100);
                        });
                    } else {
                        this.$table.bootstrapTable('collapseRow', index);
                        dom.fadeOut(100, function () {
                            dom.html(util.icon("fa-duotone fa-regular fa-plus")).fadeIn(100);
                        });
                    }
                }
            }]
        }));
        this.detail = columnsOrCallback;
    }

    setFloatMessage(columnsOrCallback) {
        this.isFloatMessage = true;
        this.floatMessage = columnsOrCallback;
    }

    setButtonDetail(columnsOrCallback) {
        this.isShowButtonDetail = true;
        this.buttonDetail = columnsOrCallback;
        this.columns.unshift(this.#getPreprocessColumn({
            field: 'detail_view',
            title: "",
            width: 55,
            type: 'button',
            buttons: [{
                icon: 'fa-duotone fa-regular fa-circle-info',
                tips: "更多信息",
                class: "text-primary",
                click: (event, value, item, index) => {
                    let html = `<table class="table table-bordered table-hover"><tbody>`;


                    this.buttonDetail.forEach(det => {
                        det.title && (det.title = i18n(det.title));
                        let val = (det.formatter ? det.formatter(util.parseStringObject(item, util.replaceDotWithHyphen(det.field)), item) : util.parseStringObject(item, util.replaceDotWithHyphen(det.field)) ?? "-");

                        if (det.dict) {
                            const uuid = util.generateRandStr(10);
                            html += `<tr><td>${det.title}</td><td><span class="${uuid}">${util.icon("fa-duotone fa-regular fa-spinner icon-spin")}</span></td></tr>`;
                            _Dict.advanced(det.dict, res => {
                                res.forEach(v => {
                                    if (v.id == val) {
                                        util.timer(() => {
                                            return new Promise(resolve => {
                                                if ($(`.${uuid}`).length > 0) {
                                                    $(`.${uuid}`).html(v.name);
                                                    resolve(false);
                                                    return;
                                                }
                                                resolve(true);
                                            });
                                        }, 50, true);
                                    }
                                });
                            });
                        } else {
                            if (val === "" || val === undefined || val === null) {
                                val = "-";
                            }
                            html += `<tr><td>${det.title}</td><td>${val}</td></tr>`;
                        }
                    });


                    html += `</tbody></table>`;


                    component.popup({
                        submit: false,
                        shadeClose: true,
                        maxmin: false,
                        tab: [
                            {
                                name: util.plainText(item.name),
                                form: [
                                    {
                                        title: false, name: "custom", type: "custom", complete: (form, dom) => {
                                            dom.html(html);
                                        }
                                    },
                                ]
                            },
                        ],
                        autoPosition: true,
                        content: {
                            css: {
                                height: "auto",
                                overflow: "inherit"
                            }
                        },
                        height: "auto",
                        width: "400px",
                        done: () => {

                        }
                    });

                }
            }]
        }));
    }

    /**
     * 通用「列详情弹窗」：把一套字段绑定到某一列的单击/双击事件上，MUI 风格弹窗展示；
     * hover 该列时提示「双击/单击查看详细信息」。数据结构与 setFloatMessage/setButtonDetail 一致。
     * 必须在 setColumns() 之后调用（改的是已预处理的列对象）。
     * @param opt { column, trigger('dblclick'默认|'click'), title(string|(row)=>string), fields[], hint?, header?(默认true) }
     */
    setColumnDetail(opt) {
        if (!opt || !opt.column) {
            return;
        }
        const trigger = (opt.trigger === 'click') ? 'click' : 'dblclick';
        const hint = i18n(opt.hint ?? (trigger === 'dblclick' ? '双击查看详细信息' : '单击查看详细信息'));
        const tipKey = this.unique + "-coldetail-tip";

        this.isColumnDetail = true;
        this.columnDetail = {
            column: opt.column,
            trigger: trigger,
            fields: opt.fields || [],
            title: opt.title,
            header: opt.header !== false
        };

        const column = this.handleColumns[opt.column];
        if (!column) {
            //必须在 setColumns() 之后调用，且列必须存在
            util.debug("setColumnDetail: 未找到列 -> " + opt.column, "#ff4f33");
            return;
        }

        //包裹原 formatter，套一层触发元素（保留 mdUserCell 等原有单元格样式）
        const original = column.formatter;
        column.formatter = (val, item) => {
            let inner;
            if (typeof original === "function") {
                inner = original(val, item);
            } else {
                inner = (val === "" || val === undefined || val === null) ? "-" : val;
            }
            return `<div class="md-detail-trigger">${inner ?? ''}</div>`;
        };
        column.fn = {formatter: column.formatter};

        //挂列级事件（随每次 body 渲染重挂，刷新/排序/翻页都不丢）
        const events = column.events || {};
        events[`${trigger} .md-detail-trigger`] = (event, value, row, index) => {
            this.#openColumnDetail(row);
        };
        events[`mouseenter .md-detail-trigger`] = (event, value, row, index) => {
            cache.set(tipKey, layer.tips(hint, event.currentTarget, {
                tips: [1, '#501536'], time: 0
            }));
        };
        events[`mouseleave .md-detail-trigger`] = (event, value, row, index) => {
            layer.close(cache.get(tipKey));
        };
        column.events = events;
    }

    #openColumnDetail(item) {
        const det = this.columnDetail;
        let rows = "";

        det.fields.forEach(f => {
            f.title && (f.title = i18n(f.title));
            let val = (f.formatter ? f.formatter(util.parseStringObject(item, util.replaceDotWithHyphen(f.field)), item) : util.parseStringObject(item, util.replaceDotWithHyphen(f.field)) ?? "-");

            if (f.dict) {
                const uuid = util.generateRandStr(10);
                rows += `<div class="md-detail__row"><span class="md-detail__label">${f.title}</span><span class="md-detail__value"><span class="${uuid}">${util.icon("fa-duotone fa-regular fa-spinner icon-spin")}</span></span></div>`;
                _Dict.advanced(f.dict, res => {
                    res.forEach(v => {
                        if (v.id == val) {
                            util.timer(() => {
                                return new Promise(resolve => {
                                    if ($(`.${uuid}`).length > 0) {
                                        $(`.${uuid}`).html(v.name);
                                        resolve(false);
                                        return;
                                    }
                                    resolve(true);
                                });
                            }, 50, true);
                        }
                    });
                });
            } else {
                if (val === "" || val === undefined || val === null) {
                    val = "-";
                }
                rows += `<div class="md-detail__row"><span class="md-detail__label">${f.title}</span><span class="md-detail__value">${val}</span></div>`;
            }
        });

        //可选头部：复用 mdUserCell（头像 + 用户名 + ID）
        const header = (det.header && typeof window.mdUserCell === "function") ? `<div class="md-detail__header">${mdUserCell(item)}</div>` : "";
        const html = `<div class="md-detail">${header}<div class="md-detail__body">${rows}</div></div>`;

        //标题：string | (row)=>string，兜底用户名
        let title = det.title;
        if (typeof title === "function") {
            title = title(item);
        }
        title = title ? i18n(title) : (item?.username ?? item?.name ?? i18n("详细信息"));

        component.popup({
            submit: false,
            shadeClose: true,
            maxmin: false,
            tab: [
                {
                    name: util.plainText(title),
                    form: [
                        {
                            title: false, name: "custom", type: "custom", complete: (form, dom) => {
                                dom.html(html);
                            }
                        },
                    ]
                },
            ],
            autoPosition: true,
            content: {
                css: {
                    height: "auto",
                    overflow: "inherit"
                }
            },
            height: "auto",
            width: "460px"
        });
    }

    enableCardView() {
        this.cardView = true;
    }


    /**
     *
     * @param search
     * @param button
     */
    setSearch(search, button = true) {
        const hackSearch = getVar("HACK_ROUTE_TABLE_SEARCH");

        if (hackSearch instanceof Array) {
            hackSearch.forEach(item => {
                if (this.queryUrl === item.route) {
                    for (let i = 0; i < search.length; i++) {
                        const column = search[i];
                        if (column.name === item.name) {
                            if (item.direction === "after") {
                                search.splice(i + 1, 0, evalResults(item.code));
                            } else {
                                search.splice(i, 0, evalResults(item.code));
                                i++;
                            }
                        }
                    }
                }
            });
        }

        this.isShowToolbar = true;
        this.search = new Search(this.$toolbar, search, data => {
            this.$table.bootstrapTable('refresh', {
                silent: false, pageNumber: 1, query: data
            });
        }, button);
    }


    setUpdate(urlOrCallback) {
        this.updateUrl = urlOrCallback;
    }

    #idObjToList(array = []) {
        let list = [];
        array.forEach(item => {
            list.push(item.id);
        });
        return list;
    }

    fullTextSearch(keywords) {
        this.$table.find("tbody").find("tr").each(function () {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(keywords));
        });
    }


    getSelections() {
        return this.$table.bootstrapTable('getSelections');
    }

    getSelectionIds() {
        return this.#idObjToList(this.getSelections());
    }

    getSearchData() {
        return this.search.getData();
    }


    /**
     * 设置删除选中数据
     * @param selector
     * @param urlOrCallback
     */
    setDeleteSelector(selector, urlOrCallback) {
        this.$deleteSelector = $(selector);
        this.$deleteSelector.click(() => {
            let data = this.#idObjToList(this.getSelections());
            if (data.length == 0) {
                message.alert("请勾选您希望删除的数据项", "error");
                return;
            }
            if (typeof urlOrCallback == "function") {
                urlOrCallback(data);
            } else if (typeof urlOrCallback == "string") {
                this.#deleteDatabase(urlOrCallback, data, () => {
                    this.refresh();
                });
            }
        });
    }

    getState() {
        let value = $(`.${this.unique}-query .table-switch-state button[class=active]`).attr("data-value");
        if (value === undefined) {
            value = "";
        }
        return {field: this.stateField, value: value};
    }

    /**
     * 添加状态切换工具栏
     * @param field
     * @param dict
     */
    setState(field, dict) {
        const $this = this;
        this.isShowToolbar = true;
        this.$toolbar.append(`<div class="table-switch-state ${this.search != null ? 'mt-26' : ''}"><button type="button" class="active">${i18n("全部")}</button></div>`);
        this.$state = this.$toolbar.find('.table-switch-state');
        this.$state.find("button").click(function () {
            $this.#handleStateButtonClick($(this), $this);
        });
        this.stateField = field;
        _Dict.advanced(dict, res => {
            res.forEach(state => {
                let $button = $(`<button type="button" data-value="${state.id}">${util.plainText(state.name)}</button>`);
                $button.click(function () {
                    $this.#handleStateButtonClick($(this), $this);
                });
                this.$state.append($button);
            });
        });
    }

    getExpandedRows() {
        let expandedRows = [];
        if (!this.isTreeTable) {
            return expandedRows;
        }

        this.$table.find('tr').each(function () {
            if ($(this).hasClass('treegrid-expanded')) {
                expandedRows.push($(this).data('id'));
            }
        });
        return expandedRows;
    }

    restoreExpandedRows(expandedRows) {
        expandedRows.forEach(id => {
            this.$table.find(`[data-id="${id}"]`).treegrid('expand');
        });
    }

    /**
     * 启用并设置树形结构
     * @param treeShowFieldIndex
     * @param treeShowField
     * @param idField
     * @param parentIdField
     * @param expand 默认展开
     */
    setTree(treeShowFieldIndex = 1, treeShowField = "name", idField = "id", parentIdField = "pid", expand = true) {
        this.options.parentIdField = parentIdField;
        this.options.treeShowField = treeShowField;
        this.options.treeShowFieldIndex = treeShowFieldIndex;
        this.options.idField = idField;
        this.isTreeTable = true;
        const $this = this;
        this.onComplete(() => {
            let columns = this.$table.bootstrapTable('getOptions').columns;
            if (columns && columns[0][1].visible) {
                let opt = {
                    treeColumn: treeShowFieldIndex,
                    onChange: function () {
                        $this.$table.bootstrapTable('resetView');
                    }
                };
                !expand && (opt.initialState = 'collapsed');
                this.$table.treegrid(opt);
            }
        });
    }

    /**
     * 启用单选模式
     */
    enableSingleSelect() {
        this.singleSelect = true;
    }


    /**
     * 网页下发数据时回调
     * @param callback
     */
    onResponse(callback) {
        this.fn.response.push(callback);
    }

    /**
     * 表格渲染完成时回调
     * @param callback
     */
    onComplete(callback) {
        this.fn.complete.push(callback);
    }

    #getPreprocessColumn(column) {
        const type = column?.type, hasDict = column.hasOwnProperty("dict"),
            tableReload = column.reload ? 'reload="true"' : "";

        column?.title && (column.title = i18n(column.title));

        //排序功能
        if (column.hasOwnProperty("sort") && column.sort === true) {
            column["title"] = column.title + ` <span style='cursor: pointer;' data-field='${column.field}' class='btn-sort'><i class="fa-duotone fa-regular fa-arrow-up-arrow-down"></i></span>`;
        }
        //检测是否有formatter
        typeof column.formatter == "function" && (column.fn = {formatter: column.formatter});
        column.hasOwnProperty("class") && (column.cellStyle = {classes: column.class});
        switch (type) {
            case "input":
                column.formatter = (val, item) => {
                    if (typeof column.show == "function") {
                        if (!column.show(item)) {
                            return '-';
                        }
                    }

                    if (val === "" || val === undefined || val === null) {
                        val = "-";
                    }
                    typeof column?.fn?.formatter == "function" && (val = column.fn.formatter(val, item));
                    return `<input class="metadata-text" data-field="${column.field}" data-id="${item.id}" type="text" value="${val}"  ${tableReload}>`;
                }
                break;
            case "switch":
                column.formatter = (val, item) => {
                    if (typeof column.show == "function") {
                        if (!column.show(item)) {
                            return '-';
                        }
                    }
                    column.text && (column.text = i18n(column.text));
                    typeof column?.fn?.formatter == "function" && (val = column.fn.formatter(val, item));
                    const layText = column.text ? ` lay-text="${column.text}" ` : '';
                    const layClass = column.text ? 'layui-switch-text' : '';
                    const checked = val == 1 ? "checked" : '';
                    return `<div class="nowrap layui-form ${layClass}"><input ${checked} data-field="${column.field}" data-id="${item.id}" lay-filter="${this.unique}-switch" type="checkbox" lay-skin="switch" ${layText} ${tableReload}></div>`;
                }
                break;
            case "select":
                column.formatter = (val, item) => {
                    if (typeof column.show == "function") {
                        if (!column.show(item)) {
                            return '-';
                        }
                    }

                    typeof column?.fn?.formatter == "function" && (val = column.fn.formatter(val, item));
                    let html = `<select lay-ignore class="metadata-select" data-field="${column.field}" data-id="${item.id}" ${tableReload}>`
                    _Dict.advanced(column.dict, res => {
                        res.forEach(dt => {
                            html += `<option value="${dt.id}" ${(val === dt.id ? 'selected' : '')}>${dt.name}</option>`;
                        });
                    })
                    return html + '</select>';
                }
                break;
            case "button":
                let events = {};
                let html = '';
                column.buttons.forEach((s, i) => {
                    const setKey = this.unique + "-button-hover-" + i, hide = s.hide ? ' hide ' : '';
                    // 语义色若写在图标(icon:'... text-danger')上而按钮没写,提到按钮 <a> 上,
                    // 让 MUI outlined 边框(取 currentColor)与图标同色,避免"红图标+蓝边框"错配。
                    let btnCls = s.class ?? "";
                    const colorMatch = (s.icon ?? "").match(/\btext-(?:danger|success|primary|warning|info|secondary|dark)\b/);
                    if (colorMatch && !btnCls.includes(colorMatch[0])) {
                        btnCls = (btnCls + " " + colorMatch[0]).trim();
                    }
                    html += `<a type="button" role="button" tabindex="0" class="a-badge-glass ${hide + btnCls} me-1 mb-1 index-${i}">${s.icon ? `<i class="${s.icon}"></i> ` : ""}<span class="btn-title">${s.title ?? ""}</span></a>`;
                    events['click .index-' + i] = s.click;
                    events['keydown .index-' + i] = function (event, value, row, index) {
                        if (!['Enter', ' '].includes(event.key)) return;
                        event.preventDefault();
                        s.click && s.click(event, value, row, index);
                    };
                    events[`mouseenter .index-${i}`] = function (event, value, row, index) {
                        if (s.tips) {
                            s.tips = i18n(s.tips);
                            cache.set(setKey, layer.tips(s.tips, event.currentTarget, {
                                tips: [1, '#501536'], time: 0
                            }));
                        }
                        s.mouseenter && s.mouseenter(event, value, row, index);
                    };
                    events[`mouseleave .index-${i}`] = function (event, value, row, index) {
                        if (s.tips) {
                            layer.close(cache.get(setKey));
                        }
                        s.mouseleave && s.mouseleave(event, value, row, index);
                    };
                });
                column.formatter = (val, item) => {
                    if (typeof column.show == "function") {
                        if (!column.show(item)) {
                            return '-';
                        }
                    }

                    let temp = html;
                    column.buttons.forEach((s, i) => {
                        let show = s.show ? s.show(item) : true;
                        let regex = new RegExp(`<a[^>]*\\bindex-${i}\\b[^>]*>[\\s\\S]*?<\/a>`, 'g');

                        if (!show) {
                            temp = temp.replace(regex, '');
                        } else {
                            if (typeof s?.formatter === 'function') {
                                temp = temp.replace(regex, () => {
                                    const html = s.formatter(item);
                                    if (!temp) {
                                        return '';
                                    }
                                    return `<span class="index-${i}">${html ?? ''}</span>`;
                                });
                            }
                            temp && (temp = `<span data-id="${item.id}">${temp}</span>`);
                        }
                    });
                    return temp === "" ? "<span class='text-gray'>-</span>" : temp;
                }
                column.events = events;
                break;
            case "image":
                let circle = column.style ?? '';
                column.formatter = (val, item) => {
                    if (typeof column.show == "function") {
                        if (!column.show(item)) {
                            return '-';
                        }
                    }

                    typeof column?.fn?.formatter == "function" && (val = column.fn.formatter(val, item));
                    if (val === "" || val === undefined || val === null) {
                        return '-';
                    }
                    return `<img style="${circle}" class="render-image" role="button" tabindex="0" src="${val}" data-id="${item.id}" alt="放大图片">`;
                }
                break;
            default:
                if (hasDict) {
                    column.formatter = (val, item) => {
                        if (typeof column.show == "function") {
                            if (!column.show(item)) {
                                return '-';
                            }
                        }

                        const result = _Dict.result(column.dict, val);
                        if (result != undefined) {
                            return result;
                        }
                        const uuid = util.generateRandStr(10);
                        _Dict.advanced(column.dict, res => {
                            res.forEach(v => {
                                if (v.id == val) {
                                    $(`.${uuid}`).parent("td").html(v.name);
                                }
                            });
                        });
                        return util.icon("fa-duotone fa-regular fa-spinner icon-spin " + uuid);
                    }
                } else if (!hasDict && !column.hasOwnProperty('formatter')) {
                    column.formatter = function (content, item) {
                        if (typeof column.show == "function") {
                            if (!column.show(item)) {
                                return '-';
                            }
                        }

                        if (content) {
                            return i18n(content);
                        }

                        if (content === "") {
                            return "-";
                        }

                        return content;
                    }
                }
        }

        this.handleColumns[column.field] = column;
        return column;
    }

    #getPreprocessColumns(columns) {
        for (const index in columns) {
            columns[index] = this.#getPreprocessColumn(columns[index]);
        }
        return columns;
    }

    #createOptions() {
        this.options = Object.assign(this.options, {
            pageSize: this.pagination.size,
            pageList: this.pagination.list,
            showRefresh: false,
            cache: true,
            iconsPrefix: "fa",
            showToggle: false,
            toolbar: this.isShowToolbar ? `.${this.unique}-query` : '',
            cardView: this.cardView,
            pagination: this.isPagination,
            pageNumber: 1,
            singleSelect: this.singleSelect,
            // onColumnSwitch: function (field, checked) {
            //     console.log(field, checked);
            // },
            // showColumns: true,
            sidePagination: 'server',
            contentType: "application/x-www-form-urlencoded",
            dataType: "json",
            processData: false,
            queryParamsType: 'limit',
            detailViewIcon: false,
            detailView: this.isShowDetail,
            columns: this.columns,
            // ajaxOptions: () => {
            //     return {
            //         headers: {
            //             Secret: this.secret, Signature: util.generateSignature(this.queryParams, this.secret)
            //         }
            //     };
            // },
            queryParams: (params) => {
                params.page = (params.offset / params.limit) + 1;
                if (this.queryParams) {
                    for (const key in params) {
                        this.queryParams[key] = params[key];
                    }
                } else {
                    this.queryParams = params;
                }

                //自动搜索功能
                let searchData = this.search?.getData();
                if (searchData) {
                    for (const dataKey in searchData) {
                        if (searchData[dataKey] !== "") {
                            this.queryParams[dataKey] = searchData[dataKey];
                        }
                    }
                }

                util.debug("POST(↑):" + this.queryUrl, "#ff4f33", this.queryParams);
                return this.queryParams;
            },
            responseHandler: (response, xhr) => {
                this.search?.resetButton();
                this.response = response;
                if (response?.data?.list) {
                    response.data.list.forEach(item => {
                        this.handleData[item.id] = item;
                    });
                }

                util.debug("POST(↓):" + this.queryUrl, "#0bbf4a", response);
                this.fn.response.forEach(call => {
                    typeof call == "function" && call(response);
                });


                return {
                    "total": response.data.total, "rows": response.data.list
                }
            },
            detailFormatter: (index, item, element) => {
                if (!this.isShowDetail) {
                    return '';
                }

                if (typeof this.detail == "function") {
                    return this.detail(item);
                } else if (typeof this.detail == "object") {
                    let html = '<table class="open-detail-view"><tbody>';
                    this.detail.forEach(det => {
                        det.title && (det.title = i18n(det.title));
                        let val = (det.formatter ? det.formatter(util.parseStringObject(item, util.replaceDotWithHyphen(det.field)), item) : util.parseStringObject(item, util.replaceDotWithHyphen(det.field)) ?? "-");
                        if (val && val != "-") {
                            html += '<tr><td>' + det.title + '</td><td>' + val + '</td></tr>';
                        }
                    });
                    html += '</tbody></table>';
                    return html;
                }
                return '';
            },
            onPostBody: () => {


                this.fn.complete.forEach(call => {
                    typeof call == "function" && call(this.$table, this.unique, this.response);
                });

                const _this = this;
                let isCtrlPressed = false;

                if (this.isFloatMessage) {


                    // 监听键盘事件，检测Ctrl键是否按下
                    $(document).on('keydown', function (event) {
                        if (event.key === 'Control' && $(`.lock-hotkeys`).length > 0 && isCtrlPressed === false) {
                            isCtrlPressed = true;
                            $(`.lock-hotkeys`).html(`按Shift或<b style="cursor: pointer;" class="lock-hotkeys-cancel text-primary">点我关闭</b>`).css("color", "#40e440");
                        }

                        if (event.key === 'Shift' && isCtrlPressed === true) {
                            isCtrlPressed = false;
                            for (const tipsId in _this.floatMessageMap) {
                                layer.close(_this.floatMessageMap[tipsId]);
                                delete _this.floatMessageMap[tipsId];
                            }
                        }
                    });


                    $(document).on('click', '.lock-hotkeys-cancel', function () {
                        isCtrlPressed = false;
                        for (const tipsId in _this.floatMessageMap) {
                            layer.close(_this.floatMessageMap[tipsId]);
                            delete _this.floatMessageMap[tipsId];
                        }
                    });


                    this.$table.find('tbody tr').hover(
                        function () {
                            if (isCtrlPressed) {
                                return;
                            }
                            const index = $(this).data('index');
                            const item = _this.$table.bootstrapTable('getData')[index];

                            let html = `<b style="color: #ff2e2e;" class="lock-hotkeys">按Ctrl锁住窗口</b><br>`;
                            _this.floatMessage.forEach(det => {
                                det.title && (det.title = i18n(det.title));
                                let val = (det.formatter ? det.formatter(util.parseStringObject(item, util.replaceDotWithHyphen(det.field)), item) : util.parseStringObject(item, util.replaceDotWithHyphen(det.field)) ?? "-");

                                if (det.dict) {
                                    const uuid = util.generateRandStr(10);
                                    html += det.title + "：" + `<span class="${uuid}">${util.icon("fa-duotone fa-regular fa-spinner icon-spin")}</span><br>`;
                                    _Dict.advanced(det.dict, res => {
                                        res.forEach(v => {
                                            if (v.id == val) {
                                                util.timer(() => {
                                                    return new Promise(resolve => {
                                                        if ($(`.${uuid}`).length > 0) {
                                                            $(`.${uuid}`).html(v.name);
                                                            resolve(false);
                                                            return;
                                                        }
                                                        resolve(true);
                                                    });
                                                }, 50, true);
                                            }
                                        });
                                    });
                                } else {
                                    if (val === "" || val === undefined || val === null) {
                                        val = "-";
                                    }
                                    html += det.title + "：" + val + "\n";
                                }
                            });

                            item?.id && (_this.floatMessageMap[item?.id] = layer.tips(html.replaceAll("\n", "<br>"), this, {
                                tips: 1,
                                time: 0,
                                maxWidth: 920
                            }));
                        },
                        function () {
                            if (isCtrlPressed) {
                                return;
                            }
                            const index = $(this).data('index');
                            const item = _this.$table.bootstrapTable('getData')[index];
                            item?.id && layer.close(_this.floatMessageMap[item?.id]);
                            item?.id && (delete _this.floatMessageMap[item?.id]);
                        }
                    );
                }

                this.#loadTableSuccess();
            },
            rowAttributes: function (row, index) {
                return {
                    'data-id': row.id
                };
            },
            onLoadSuccess: data => {
                data.rows.forEach(row => {
                    if (row.checked === true) {
                        this.$table.bootstrapTable('checkBy', {field: 'id', values: [row.id]});
                    }
                });
            }
        });
    }

    #getData(id) {
        if (this.handleData.hasOwnProperty(id)) {
            return this.handleData[id];
        }
        return null;
    }

    #triggerChange(value, field, id) {
        const handleColumn = this.handleColumns[field];
        typeof handleColumn?.change == "function" && handleColumn.change(value, this.#getData(id));
    }

    /**
     *
     * @param value
     * @param field
     * @param id
     * @param reload
     */
    #updateDatabase(value, field, id, reload = false) {
        this.#triggerChange(value, field, id);

        if (this.updateUrl == null) {
            return;
        }
        let data = {};
        data[field] = value;
        data["id"] = id;

        if (typeof this.updateUrl == "function") {
            this.updateUrl(data);
        } else if (typeof this.updateUrl == "string") {
            util.post(this.updateUrl, data, res => {
                message.success("已更新 (｡•ᴗ-)");
                reload && this.refresh(true);
            });
        }
    }


    #deleteDatabase(url, list, done = null) {
        message.ask("一旦数据被遗弃，您将无法恢复它！", () => {
            util.post(url, {list: list}, res => {
                message.alert('您选择的数据已被系统永久删除。', 'success');
                done && done(res);
            });
        });
    }

    refresh(silent = true) {
        let expandedRows = this.getExpandedRows();
        this.$table.bootstrapTable('refresh', {silent: silent});

        if (this.isTreeTable) {
            this.$table.on('post-body.bs.table', () => {
                this.restoreExpandedRows(expandedRows);
            });
        }
    }

    reload(options) {
        this.$table.bootstrapTable('refresh', Object.assign({silent: true}, options));
    }

    #loadTableSuccess() {
        this.$table.addClass(this.unique);
        const $this = this;

        //监听文本框
        $(`.${this.unique} .metadata-text`).change(function () {
            $this.#updateDatabase(this.value, $(this).attr("data-field"), $(this).attr("data-id"), $(this).attr("reload"));
        });

        //监听下拉框
        $(`.${this.unique} .metadata-select`).change(function () {
            $this.#updateDatabase(this.value, $(this).attr("data-field"), $(this).attr("data-id"), $(this).attr("reload"));
        });

        //监听开关
        this.layuiForm.on(`switch(${this.unique}-switch)`, function (data) {
            $this.#updateDatabase(data.elem.checked ? 1 : 0, $(data.elem).attr("data-field"), $(data.elem).attr("data-id"), $(data.elem).attr("reload"));
        });

        $(`.${this.unique} .render-image`).on('click keydown', function (event) {
            if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
            event.preventDefault();
            component.previewImage($(this).attr("src"));
        });

        //排序组件
        let $btnSort = $(`.${this.unique} .btn-sort`);
        $btnSort.off('click');
        $btnSort.click(function () {
            let field = $(this).attr("data-field");
            let key = $this.unique + "_sort_" + field;
            let temp = cache.has(key) ? parseInt(cache.get(key)) : 0;
            if (temp >= 3) {
                temp = 0;
            }

            let rule = ["asc", "desc", ""];
            let css = ["fa-duotone fa-regular fa-arrow-up-1-9", "fa-duotone fa-regular fa-arrow-up-9-1", "fa-duotone fa-regular fa-arrow-up-arrow-down"];
            $(this).html(util.icon(css[temp]));
            $this.reload({pageNumber: 1, query: {sort_rule: rule[temp], sort_field: field}});
            temp++;
            cache.set(key, temp);
        });

        this.layuiForm.render();
    }


    #registerGlobalEvent() {
        if (this.isTreeTable) {
            this.$table.off('check.bs.table uncheck.bs.table')
            this.$table.on('check.bs.table uncheck.bs.table', (e, row) => {
                const isChecked = e.type === 'check';
                const allData = this.$table.bootstrapTable('getData', {useCurrentPage: false});
                const children = allData.filter(item => item.pid === row.id);
                children.forEach(child => {
                    this.$table.bootstrapTable(isChecked ? 'checkBy' : 'uncheckBy', {
                        field: 'id',
                        values: [child.id]
                    });
                });
            });
        }
    }

    /**
     * 渲染表格
     */
    render() {
        const $this = this;
        //表单构造参数
        this.#createOptions();
        this.#createRequest();
        this.$table.bootstrapTable(this.options);
        this.#registerGlobalEvent();
    }
}
