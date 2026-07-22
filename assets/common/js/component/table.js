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

function escapeTableHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[character]);
}

function renderTableDictionaryValue(target, value) {
    const element = target?.jquery ? target : $(target);
    if (!element?.length) {
        return;
    }

    const source = String(value ?? '');
    const sanitizer = window.SeattleTheme?.safeInlineHtml;
    if (typeof sanitizer === 'function') {
        const safeHtml = sanitizer(source);
        element.html(safeHtml || escapeTableHtml(util.plainText(source) || '-'));
        return;
    }

    element.text(util.plainText(source) || '-');
}

class Table {
    static getInstances() {
        Table.instances ??= new Set();
        return Table.instances;
    }

    static destroyAll(container = null) {
        const root = container?.jquery ? container.get(0) : container;
        Array.from(Table.getInstances()).forEach(table => {
            const element = table.$table?.get(0);
            if (!root || !element || root === element || $.contains(root, element)) {
                table.destroy();
            }
        });
    }

    #handleStateButtonClick($this, table) {
        $(`.${table.unique}-query .table-switch-state button`).removeClass("active");
        $this.addClass("active");
        let value = $this.attr("data-value");
        let data = {};
        const stateKey = "equal-" + table.stateField;
        if (value !== undefined) {
            data[stateKey] = value;
        } else {
            data[stateKey] = "";
        }
        if (table.search?.item?.[stateKey] && typeof table.search.setValue === "function") {
            table.search.setValue(stateKey, data[stateKey], false);
        }
        table.reload({pageNumber: 1, query: data});
        table.#scheduleLifecycleUpdate('state');
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
        this.eventNamespace = `.adminTable${this.unique}`;
        this.floatEventNamespace = `.adminTableFloat${this.unique}`;
        this.isRendered = false;
        this.isDestroyed = false;
        this.isDestroying = false;
        this.hasEmittedReady = false;
        this.lifecycleTimer = null;
        this.switchEventRegistered = false;
        this.loadState = {
            status: Array.isArray(urlOrData) ? 'success' : 'idle',
            error: null
        };
        this.mobileDictCache = new Map();
        this.mobileDictRequests = new Set();

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
        this.$table.data('adminTable', this);
        Table.getInstances().add(this);
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
                        const title = escapeTableHtml(det.title ? i18n(det.title) : '');
                        const source = util.parseStringObject(item, util.replaceDotWithHyphen(det.field));
                        const hasFormatter = typeof det.formatter === 'function';
                        let val = hasFormatter ? det.formatter(source, item) : (source ?? "-");

                        if (det.dict) {
                            const uuid = util.generateRandStr(10);
                            html += `<tr><td>${title}</td><td><span class="${uuid}">${util.icon("fa-duotone fa-regular fa-spinner icon-spin")}</span></td></tr>`;
                            _Dict.advanced(det.dict, res => {
                                res.forEach(v => {
                                    if (v.id == val) {
                                        util.timer(() => {
                                            return new Promise(resolve => {
                                                if ($(`.${uuid}`).length > 0) {
                                                    renderTableDictionaryValue($(`.${uuid}`), v.name);
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
                            if (!hasFormatter) {
                                val = escapeTableHtml(val);
                            }
                            html += `<tr><td>${title}</td><td>${val}</td></tr>`;
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
            const fieldTitle = escapeTableHtml(f.title ? i18n(f.title) : '');
            const source = util.parseStringObject(item, util.replaceDotWithHyphen(f.field));
            const hasFormatter = typeof f.formatter === 'function';
            let val = hasFormatter ? f.formatter(source, item) : (source ?? "-");

            if (f.dict) {
                const uuid = util.generateRandStr(10);
                rows += `<div class="md-detail__row"><span class="md-detail__label">${fieldTitle}</span><span class="md-detail__value"><span class="${uuid}">${util.icon("fa-duotone fa-regular fa-spinner icon-spin")}</span></span></div>`;
                _Dict.advanced(f.dict, res => {
                    res.forEach(v => {
                        if (v.id == val) {
                            util.timer(() => {
                                return new Promise(resolve => {
                                    if ($(`.${uuid}`).length > 0) {
                                        renderTableDictionaryValue($(`.${uuid}`), v.name);
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
                if (!hasFormatter) {
                    val = escapeTableHtml(val);
                }
                rows += `<div class="md-detail__row"><span class="md-detail__label">${fieldTitle}</span><span class="md-detail__value">${val}</span></div>`;
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

        // Column details are read-only. In the admin mobile layout, present the
        // existing rich detail markup in the shared App sheet instead of
        // routing it through the desktop-sized component popup. The desktop
        // path below remains the single fallback when mobile is unavailable.
        const mobile = window.AdminMobile;
        if (mobile?.isEnabled?.() === true && typeof mobile.openSheet === "function") {
            const content = document.createElement("div");
            content.className = "admin-mobile-table-detail";
            content.innerHTML = html;
            const presented = mobile.openSheet({
                id: `table-column-detail-${this.unique}`,
                title: util.plainText(title),
                subtitle: i18n("完整信息"),
                content: content,
                fullScreen: det.fields.length > 8,
                shadeClose: true
            });
            if (presented === true || presented?.handled === true) {
                return presented;
            }
        }

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

    #getFieldValue(row, field) {
        const path = String(field ?? '').trim();
        if (!row || !path) {
            return undefined;
        }
        return util.parseStringObject(row, util.replaceDotWithHyphen(path));
    }

    #setFieldValue(row, field, value) {
        const path = String(field ?? '').trim();
        if (!row || !path) {
            return false;
        }
        const segments = util.replaceDotWithHyphen(path).split('-').filter(Boolean);
        if (!segments.length) {
            return false;
        }
        let target = row;
        segments.slice(0, -1).forEach(segment => {
            if (!target[segment] || typeof target[segment] !== 'object') {
                target[segment] = {};
            }
            target = target[segment];
        });
        target[segments[segments.length - 1]] = value;
        return true;
    }

    #idObjToList(array = []) {
        const idField = this.options.idField || 'id';
        return array.map(item => this.#getFieldValue(item, idField));
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
        return this.search?.getData() ?? {};
    }

    /** Final, hook-processed columns without exposing the mutable column objects. */
    getColumns() {
        return this.columns.map(column => Object.assign({}, column, {
            buttons: Array.isArray(column.buttons)
                ? column.buttons.map(button => Object.assign({}, button))
                : column.buttons,
            mobileHidden: column.type === 'button' || Array.isArray(column.buttons),
            mobileFormatter: (value, row, index) => this.#mobileDisplayValue(column, row, index, value)
        }));
    }

    #mobileRawValue(column, row) {
        if (!column?.field) {
            return undefined;
        }
        return util.parseStringObject(row, util.replaceDotWithHyphen(column.field));
    }

    #mobileDictValue(column, value) {
        const direct = _Dict.result(column.dict, value);
        if (direct !== undefined) {
            return direct;
        }

        const cached = this.mobileDictCache.get(column);
        const cachedItem = cached?.find(item => item.id == value);
        if (cachedItem) {
            return cachedItem.name;
        }

        if (!this.mobileDictRequests.has(column)) {
            this.mobileDictRequests.add(column);
            _Dict.advanced(column.dict, list => {
                this.mobileDictCache.set(column, Array.isArray(list) ? list : []);
                this.#scheduleLifecycleUpdate('dict');
            });
        }
        return value;
    }

    #mobileRenderedValue(column, index) {
        if (!this.isRendered || this.isDestroyed || !column?.field) {
            return {found: false, value: undefined};
        }

        const headers = this.$table.find('thead th').toArray();
        const cellIndex = headers.findIndex(header =>
            String($(header).attr('data-field') ?? '') === String(column.field)
        );
        const $row = this.$table.find(`tbody > tr[data-index="${index}"]`).first();
        const $cell = cellIndex >= 0 ? $row.children('td').eq(cellIndex) : $();
        if (!$cell.length) {
            return {found: false, value: undefined};
        }

        const $input = $cell.find('input.metadata-text, textarea.metadata-text').first();
        if ($input.length) {
            return {found: true, value: $input.val()};
        }
        const $select = $cell.find('select.metadata-select').first();
        if ($select.length) {
            return {found: true, value: $select.find('option:selected').text()};
        }

        const clone = $cell.get(0).cloneNode(true);
        clone.querySelectorAll('script, style, input, select, textarea, button').forEach(node => node.remove());
        clone.querySelectorAll('br').forEach(node => node.replaceWith(document.createTextNode(' · ')));
        clone.querySelectorAll('.md-pair__row, .md-detail__row, .md-user-cell__id, .md-user-cell__sub, .md-file__note').forEach(node => {
            node.after(document.createTextNode(' · '));
        });
        const value = (clone.textContent || '')
            .replace(/\s*·\s*(?:·\s*)+/g, ' · ')
            .replace(/^\s*·|·\s*$/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (value) {
            return {found: true, value: value};
        }
        const image = clone.querySelector('img[alt], img[title]');
        const alternate = image?.getAttribute('alt') || image?.getAttribute('title');
        return alternate
            ? {found: true, value: alternate}
            : {found: false, value: undefined};
    }

    #mobileDisplayValue(column, row, index = 0, sourceValue = undefined) {
        if (!column || column.type === 'button' || Array.isArray(column.buttons)) {
            return null;
        }
        if (typeof column.show === 'function' && !column.show(row)) {
            return '-';
        }

        let value = sourceValue === undefined ? this.#mobileRawValue(column, row) : sourceValue;
        const hasDict = column.hasOwnProperty('dict');
        const formatter = column?.fn?.formatter;
        // When bootstrap-table has not mounted a cell (hidden/plugin-injected
        // columns), run the original formatter captured before preprocessing.
        if (typeof formatter === 'function' && (column.type || !hasDict)) {
            try {
                value = formatter(value, row, index);
            } catch (error) {
                util.debug('Table mobile formatter failed: ' + this.unique, '#ff4f33');
            }
        }
        if (column.type === 'switch') {
            const labels = String(column.text ?? i18n('开启|关闭')).split('|');
            return value == 1 || value === true
                ? i18n(labels[0] || '开启')
                : i18n(labels[1] || '关闭');
        }
        if (hasDict) {
            return this.#mobileDictValue(column, value);
        }
        return value;
    }

    getMobileDisplayValue(columnOrField, row, index = 0) {
        const column = typeof columnOrField === 'string'
            ? this.handleColumns[columnOrField]
            : this.handleColumns[columnOrField?.field] ?? columnOrField;
        const rendered = this.#mobileRenderedValue(column, index);
        if (rendered.found) {
            return rendered.value;
        }
        return this.#mobileDisplayValue(column, row, index);
    }

    /** Rows currently held by bootstrap-table (normally the visible page). */
    getRows() {
        if (this.isRendered && !this.isDestroyed) {
            try {
                return this.$table.bootstrapTable('getData') || [];
            } catch (error) {
                util.debug('Table data is not available: ' + this.unique, '#ff4f33');
            }
        }
        return Array.isArray(this.queryUrl) ? this.queryUrl : [];
    }

    getData() {
        return this.getRows();
    }

    getPagination() {
        let options = this.options;
        if (this.isRendered && !this.isDestroyed) {
            try {
                options = this.$table.bootstrapTable('getOptions') || options;
            } catch (error) {
                util.debug('Table pagination is not available: ' + this.unique, '#ff4f33');
            }
        }
        const pageSize = Number(options.pageSize || this.pagination.size || 0);
        const pageNumber = Number(options.pageNumber || 1);
        const total = Number(options.totalRows ?? this.response?.data?.total ?? this.getRows().length ?? 0);
        return {
            enabled: this.isPagination,
            pageNumber: pageNumber,
            pageSize: pageSize,
            total: total,
            totalPages: this.isPagination && pageSize > 0 ? Math.ceil(total / pageSize) : (total > 0 ? 1 : 0)
        };
    }

    #getSelectionRowState(selectionColumn, row, index) {
        let disabled = false;
        let selectable = true;

        const evaluate = (value, fallback) => {
            if (typeof value !== 'function') {
                return value ?? fallback;
            }
            try {
                return value(row, index);
            } catch (error) {
                util.debug('Table selection rule failed: ' + this.unique, '#ff4f33');
                return fallback;
            }
        };

        disabled = Boolean(evaluate(selectionColumn?.disabled, false));
        selectable = evaluate(selectionColumn?.selectable, true) !== false;

        // bootstrap-table uses the checkbox/radio formatter result to disable
        // individual rows. Preserve that final rule in the mobile snapshot even
        // when the desktop input is not currently mounted (for example, during a
        // lifecycle refresh).
        let selectItemName = 'btSelectItem';
        if (this.isRendered && !this.isDestroyed) {
            try {
                selectItemName = this.$table.bootstrapTable('getOptions')?.selectItemName || selectItemName;
            } catch (error) {
                util.debug('Table selection options are not available: ' + this.unique, '#ff4f33');
            }
        }
        const $input = this.isRendered && !this.isDestroyed
            ? this.$table.find(`tbody > tr[data-index="${index}"] input[type="checkbox"], tbody > tr[data-index="${index}"] input[type="radio"]`)
                .filter((_, input) => input.name === selectItemName)
                .first()
            : $();
        if (!$input.length && selectionColumn?.checkboxEnabled === false) {
            disabled = true;
        }
        const formatter = selectionColumn?.fn?.formatter ?? selectionColumn?.formatter;
        if (!$input.length && typeof formatter === 'function') {
            try {
                const source = selectionColumn?.field
                    ? util.parseStringObject(row, util.replaceDotWithHyphen(selectionColumn.field))
                    : undefined;
                const result = formatter(source, row, index);
                if (result && typeof result === 'object') {
                    disabled = disabled || Boolean(result.disabled);
                    if (result.selectable === false) {
                        selectable = false;
                    }
                }
            } catch (error) {
                util.debug('Table selection formatter failed: ' + this.unique, '#ff4f33');
            }
        }

        // The rendered input is the authoritative result after bootstrap-table
        // has applied every formatter and hook.
        if ($input.length) {
            disabled = disabled || $input.prop('disabled') === true;
        }

        selectable = selectable && !disabled;
        return {
            row: row,
            index: index,
            disabled: !selectable,
            selectable: selectable
        };
    }

    getSelectionState() {
        let rawRows = [];
        const selectionColumn = this.columns.find(column => column?.checkbox === true || column?.radio === true);
        const pageRows = this.getRows();
        const idField = this.options.idField || 'id';
        if (this.isRendered && !this.isDestroyed) {
            try {
                rawRows = this.getSelections();
            } catch (error) {
                rawRows = [];
            }
        }

        const sameRow = (left, right) => {
            if (left === right) {
                return true;
            }
            const leftId = this.#getFieldValue(left, idField);
            const rightId = this.#getFieldValue(right, idField);
            return leftId !== undefined && rightId !== undefined && leftId == rightId;
        };
        const rowStates = selectionColumn
            ? pageRows.map((row, index) => this.#getSelectionRowState(selectionColumn, row, index))
            : [];
        const rows = rawRows.filter(row => {
            const state = rowStates.find(item => sameRow(item.row, row));
            return !state || state.selectable;
        });
        rowStates.forEach(item => {
            item.selected = rows.some(row => sameRow(item.row, row));
            item.id = this.#getFieldValue(item.row, idField);
        });

        return {
            enabled: Boolean(selectionColumn),
            rows: rows,
            ids: this.#idObjToList(rows),
            single: this.singleSelect || selectionColumn?.radio === true,
            type: selectionColumn?.radio === true ? 'radio' : (selectionColumn ? 'checkbox' : null),
            field: selectionColumn?.field ?? idField,
            idField: idField,
            rowStates: rowStates,
            selectableRows: rowStates.filter(item => item.selectable).map(item => item.row),
            disabledRows: rowStates.filter(item => item.disabled).map(item => item.row)
        };
    }

    getLoadState() {
        const status = this.loadState.status;
        const refresh = () => this.refresh(false);
        return {
            status: status,
            loading: status === 'loading',
            success: status === 'success',
            error: status === 'error' ? this.loadState.error : null,
            refresh: refresh,
            retry: refresh
        };
    }

    #renderDetail(item) {
        if (!this.isShowDetail) {
            return '';
        }
        if (typeof this.detail === 'function') {
            return this.detail(item);
        }
        if (Array.isArray(this.detail)) {
            let html = '<table class="open-detail-view"><tbody>';
            this.detail.forEach(det => {
                const title = escapeTableHtml(det.title ? i18n(det.title) : '');
                const field = util.replaceDotWithHyphen(det.field);
                const source = util.parseStringObject(item, field);
                const hasFormatter = typeof det.formatter === 'function';
                const value = hasFormatter ? det.formatter(source, item) : escapeTableHtml(source ?? '-');
                if (value && value !== '-') {
                    html += '<tr><td>' + title + '</td><td>' + value + '</td></tr>';
                }
            });
            return html + '</tbody></table>';
        }
        return '';
    }

    getDetail() {
        return {
            enabled: this.isShowDetail || this.isShowButtonDetail || this.isColumnDetail,
            definition: this.detail,
            button: this.isShowButtonDetail ? this.buttonDetail : null,
            column: this.isColumnDetail ? this.columnDetail : null,
            render: row => this.#renderDetail(row),
            displayValue: (definition, row, index = 0) => {
                if (!definition?.field) {
                    return undefined;
                }
                let value = util.parseStringObject(row, util.replaceDotWithHyphen(definition.field));
                if (typeof definition.formatter === 'function') {
                    try {
                        value = definition.formatter(value, row, index);
                    } catch (error) {
                        util.debug('Table detail formatter failed: ' + this.unique, '#ff4f33');
                    }
                }
                if (definition.hasOwnProperty('dict')) {
                    value = this.#mobileDictValue(definition, value);
                }
                return value;
            },
            open: row => {
                if (!this.isColumnDetail) {
                    return false;
                }
                this.#openColumnDetail(row);
                return true;
            }
        };
    }

    #actionIsDangerous(button) {
        const text = [button.class, button.icon, button.title, button.tips].filter(Boolean).join(' ');
        return /(?:text-danger|btn-danger|trash|circle-exclamation|删除|移除|清空|清理|驳回|拒绝|卸载|解绑|禁用|停用|停止|永久)/i.test(text);
    }

    /** All original button callbacks, flattened into stable action descriptors. */
    getActions() {
        const actions = [];
        this.columns.forEach(column => {
            if (!Array.isArray(column.buttons)) {
                return;
            }
            column.buttons.forEach((button, index) => {
                const id = `${column.field}:${index}`;
                const danger = this.#actionIsDangerous(button);
                actions.push({
                    id: id,
                    field: column.field,
                    index: index,
                    title: i18n(button.title ?? button.tips ?? ''),
                    icon: button.icon ?? '',
                    class: button.class ?? '',
                    danger: danger,
                    category: button.category ?? (danger ? 'danger' : (button.primary === true ? 'primary' : 'more')),
                    definition: Object.assign({}, button),
                    show: row => {
                        if (button.hide === true) {
                            return false;
                        }
                        if (typeof column.show === 'function' && !column.show(row)) {
                            return false;
                        }
                        return typeof button.show !== 'function' || button.show(row);
                    },
                    invoke: (event, row, rowIndex = null) => this.runAction(id, row, event, rowIndex)
                });
            });
        });
        return actions;
    }

    #findRow(rowOrId) {
        const rows = this.getRows();
        const idField = this.options.idField || 'id';
        let index = -1;
        if (rowOrId && typeof rowOrId === 'object') {
            index = rows.indexOf(rowOrId);
            const rowId = this.#getFieldValue(rowOrId, idField);
            if (index < 0 && rowId !== undefined) {
                index = rows.findIndex(row => this.#getFieldValue(row, idField) == rowId);
            }
        } else {
            index = rows.findIndex(row => this.#getFieldValue(row, idField) == rowOrId);
        }
        return {row: index >= 0 ? rows[index] : null, index: index};
    }

    #getActionTarget(column, button, buttonIndex, row, rowIndex) {
        if (this.isRendered && !this.isDestroyed && rowIndex >= 0) {
            const headers = this.$table.find('thead th').toArray();
            const cellIndex = headers.findIndex(header =>
                String($(header).attr('data-field') ?? '') === String(column.field)
            );
            const $row = this.$table.find(`tbody > tr[data-index="${rowIndex}"]`).first();
            const target = cellIndex >= 0
                ? $row.children('td').eq(cellIndex).find(`.index-${buttonIndex}`).first().get(0)
                : null;
            if (target) {
                return target;
            }
        }

        const wrapper = document.createElement('span');
        const rowId = this.#getFieldValue(row, this.options.idField || 'id');
        if (rowId !== undefined && rowId !== null) {
            wrapper.setAttribute('data-id', String(rowId));
        }
        const target = document.createElement('a');
        target.setAttribute('type', 'button');
        target.setAttribute('role', 'button');
        target.setAttribute('tabindex', '0');
        target.className = ['a-badge-glass', button.class, `index-${buttonIndex}`, 'me-1', 'mb-1']
            .filter(Boolean)
            .join(' ');
        if (button.icon) {
            const icon = document.createElement('i');
            icon.className = button.icon;
            target.append(icon, document.createTextNode(' '));
        }
        const title = document.createElement('span');
        title.className = 'btn-title';
        title.textContent = button.title ?? '';
        target.append(title);
        wrapper.append(target);
        return target;
    }

    runAction(actionId, rowOrId, event = null, rowIndex = null) {
        const [field, rawIndex] = String(actionId).split(':');
        const column = this.columns.find(item => String(item.field) === field);
        const button = column?.buttons?.[Number(rawIndex)];
        const found = this.#findRow(rowOrId);
        const row = found.row || (rowOrId && typeof rowOrId === 'object' ? rowOrId : null);
        const index = rowIndex ?? found.index;
        if (!button || !row || (typeof column.show === 'function' && !column.show(row)) ||
            button.hide === true || (typeof button.show === 'function' && !button.show(row))) {
            return false;
        }
        if (typeof button.click !== 'function') {
            return false;
        }
        const actionTarget = this.#getActionTarget(column, button, Number(rawIndex), row, index);
        const actionEvent = event || $.Event('click', {
            target: actionTarget,
            currentTarget: actionTarget,
            delegateTarget: actionTarget
        });
        const value = this.#getFieldValue(row, field);
        return button.click(actionEvent, value, row, index);
    }

    /** Run the same persistence/change path used by desktop inline fields. */
    updateField(rowOrId, field, value, options = {}) {
        const found = this.#findRow(rowOrId);
        if (!found.row) {
            return false;
        }
        const reload = typeof options === 'boolean' ? options : options.reload === true;
        if (!this.#setFieldValue(found.row, field, value)) {
            return false;
        }
        const idField = this.options.idField || 'id';
        const rowId = this.#getFieldValue(found.row, idField);
        if (rowId !== undefined) {
            this.handleData[rowId] = found.row;
        }
        this.#updateDatabase(value, field, rowId, reload);
        if (this.isRendered && !this.isDestroyed && found.index >= 0) {
            this.$table.bootstrapTable('updateCell', {
                index: found.index,
                field: field,
                value: value,
                reinit: true
            });
        }
        this.#scheduleLifecycleUpdate('inline-update');
        return true;
    }

    updateRow(rowOrId, values, options = {}) {
        if (!values || typeof values !== 'object') {
            return false;
        }
        return Object.entries(values).every(([field, value]) =>
            this.updateField(rowOrId, field, value, options)
        );
    }

    setRowSelected(rowOrId, selected = true) {
        const found = this.#findRow(rowOrId);
        if (!found.row || !this.isRendered || this.isDestroyed) {
            return false;
        }
        const selectionColumn = this.columns.find(column => column?.checkbox === true || column?.radio === true);
        if (!selectionColumn || (selected && !this.#getSelectionRowState(selectionColumn, found.row, found.index).selectable)) {
            return false;
        }
        const idField = this.options.idField || 'id';
        this.$table.bootstrapTable(selected ? 'checkBy' : 'uncheckBy', {
            field: idField,
            values: [this.#getFieldValue(found.row, idField)]
        });
        return true;
    }

    getMobileSnapshot(reason = '') {
        return {
            id: this.unique,
            queryUrl: this.queryUrl,
            element: this.$table.get(0),
            columns: this.getColumns(),
            rows: this.getRows(),
            pagination: this.getPagination(),
            selection: this.getSelectionState(),
            detail: this.getDetail(),
            actions: this.getActions(),
            search: this.search ? {
                definitions: this.search.definitions(),
                value: this.search.value(),
                instance: this.search
            } : null,
            state: this.stateField ? this.getState() : null,
            status: this.getLoadState(),
            refresh: () => this.refresh(false),
            displayValue: (columnOrField, row, index = 0) =>
                this.getMobileDisplayValue(columnOrField, row, index),
            reason: reason
        };
    }

    #emitLifecycle(type, reason) {
        const snapshot = this.getMobileSnapshot(reason);
        const payload = {table: this, snapshot: snapshot, reason: reason};
        const event = $.Event(`admin:table:${type}`);
        event.detail = payload;
        this.$table.trigger(event, [payload]);
    }

    #scheduleLifecycleUpdate(reason) {
        if (this.isDestroyed) {
            return;
        }
        clearTimeout(this.lifecycleTimer);
        this.lifecycleTimer = setTimeout(() => {
            this.lifecycleTimer = null;
            this.hasEmittedReady && this.#emitLifecycle('update', reason);
        }, 0);
    }

    #setLoadState(status, error = null, reason = 'load-state') {
        this.loadState = {status: status, error: error};
        if (this.hasEmittedReady) {
            this.#scheduleLifecycleUpdate(reason);
        } else if (status === 'error' && this.isRendered && !this.isDestroyed) {
            this.hasEmittedReady = true;
            this.#emitLifecycle('ready', reason);
        }
    }


    /**
     * 设置删除选中数据
     * @param selector
     * @param urlOrCallback
     */
    setDeleteSelector(selector, urlOrCallback) {
        this.$deleteSelector = $(selector);
        this.$deleteSelector.off(this.eventNamespace).on(`click${this.eventNamespace}`, () => {
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
        const buttons = this.$state?.find('button').toArray() ?? [];
        const options = buttons.map(button => {
            const $button = $(button);
            return {
                value: $button.attr('data-value') ?? '',
                label: $button.text().trim(),
                active: $button.hasClass('active'),
                button: button
            };
        });
        const active = options.find(option => option.active);
        return {
            field: this.stateField,
            value: active?.value ?? '',
            options: options,
            buttons: buttons,
            select: value => this.selectState(value)
        };
    }

    selectState(value = '') {
        const normalized = String(value ?? '');
        const button = this.$state?.find('button').toArray().find(item =>
            String($(item).attr('data-value') ?? '') === normalized
        );
        if (!button) {
            return false;
        }
        this.#handleStateButtonClick($(button), this);
        return true;
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
            this.#scheduleLifecycleUpdate('state-options');
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
                                    renderTableDictionaryValue($(`.${uuid}`).parent("td"), v.name);
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
                            return escapeTableHtml(i18n(content));
                        }

                        if (content === "") {
                            return "-";
                        }

                        return content == null ? content : escapeTableHtml(content);
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
                this.#setLoadState('loading', null, 'loading');
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
                        } else {
                            // Bootstrap Table merges refresh queries into the
                            // previous request object. Explicitly remove an
                            // emptied search field so mobile reset + state
                            // switching cannot silently retain the old filter.
                            delete this.queryParams[dataKey];
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
                return this.#renderDetail(item);
            },
            onPostBody: () => {


                this.fn.complete.forEach(call => {
                    typeof call == "function" && call(this.$table, this.unique, this.response);
                });

                const _this = this;
                let isCtrlPressed = false;

                if (this.isFloatMessage) {


                    // 监听键盘事件，检测Ctrl键是否按下
                    $(document).off(this.floatEventNamespace).on(`keydown${this.floatEventNamespace}`, function (event) {
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


                    $(document).on(`click${this.floatEventNamespace}`, '.lock-hotkeys-cancel', function () {
                        isCtrlPressed = false;
                        for (const tipsId in _this.floatMessageMap) {
                            layer.close(_this.floatMessageMap[tipsId]);
                            delete _this.floatMessageMap[tipsId];
                        }
                    });


                    this.$table.find('tbody tr')
                        .off(this.floatEventNamespace)
                        .on(`mouseenter${this.floatEventNamespace}`, function () {
                            if (isCtrlPressed) {
                                return;
                            }
                            const index = $(this).data('index');
                            const item = _this.$table.bootstrapTable('getData')[index];

                            let html = `<b style="color: #ff2e2e;" class="lock-hotkeys">按Ctrl锁住窗口</b><br>`;
                            _this.floatMessage.forEach(det => {
                                const title = escapeTableHtml(det.title ? i18n(det.title) : '');
                                const source = util.parseStringObject(item, util.replaceDotWithHyphen(det.field));
                                const hasFormatter = typeof det.formatter === 'function';
                                let val = hasFormatter ? det.formatter(source, item) : (source ?? "-");

                                if (det.dict) {
                                    const uuid = util.generateRandStr(10);
                                    html += title + "：" + `<span class="${uuid}">${util.icon("fa-duotone fa-regular fa-spinner icon-spin")}</span><br>`;
                                    _Dict.advanced(det.dict, res => {
                                        res.forEach(v => {
                                            if (v.id == val) {
                                                util.timer(() => {
                                                    return new Promise(resolve => {
                                                        if ($(`.${uuid}`).length > 0) {
                                                            renderTableDictionaryValue($(`.${uuid}`), v.name);
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
                                    if (!hasFormatter) {
                                        val = escapeTableHtml(val);
                                    }
                                    html += title + "：" + val + "\n";
                                }
                            });

                            item?.id && (_this.floatMessageMap[item?.id] = layer.tips(html.replaceAll("\n", "<br>"), this, {
                                tips: 1,
                                time: 0,
                                maxWidth: 920
                            }));
                        })
                        .on(`mouseleave${this.floatEventNamespace}`, function () {
                            if (isCtrlPressed) {
                                return;
                            }
                            const index = $(this).data('index');
                            const item = _this.$table.bootstrapTable('getData')[index];
                            item?.id && layer.close(_this.floatMessageMap[item?.id]);
                            item?.id && (delete _this.floatMessageMap[item?.id]);
                        });
                }

                this.#loadTableSuccess();
                if (this.hasEmittedReady) {
                    this.#scheduleLifecycleUpdate('data');
                } else {
                    this.hasEmittedReady = true;
                    this.#emitLifecycle('ready', 'render');
                }
            },
            rowAttributes: function (row, index) {
                return {
                    'data-id': row.id
                };
            },
            onLoadSuccess: data => {
                const idField = this.options.idField || 'id';
                data.rows.forEach(row => {
                    if (row.checked === true) {
                        this.$table.bootstrapTable('checkBy', {
                            field: idField,
                            values: [this.#getFieldValue(row, idField)]
                        });
                    }
                });
                this.#setLoadState('success', null, 'load-success');
            },
            onLoadError: (status, request) => {
                this.#setLoadState('error', {
                    status: Number(status) || request?.status || 0,
                    message: request?.statusText || i18n('数据加载失败')
                }, 'load-error');
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
        if (this.isDestroyed || !this.isRendered) {
            return;
        }
        let expandedRows = this.getExpandedRows();
        this.$table.bootstrapTable('refresh', {silent: silent});

        if (this.isTreeTable) {
            this.$table.one(`post-body.bs.table${this.eventNamespace}`, () => {
                this.restoreExpandedRows(expandedRows);
            });
        }
    }

    reload(options) {
        if (this.isDestroyed || !this.isRendered) {
            return;
        }
        this.$table.bootstrapTable('refresh', Object.assign({silent: true}, options));
    }

    #loadTableSuccess() {
        this.$table.addClass(this.unique);
        const $this = this;

        //监听文本框
        $(`.${this.unique} .metadata-text`)
            .off(this.eventNamespace)
            .on(`change${this.eventNamespace}`, function () {
                $this.updateField($(this).attr("data-id"), $(this).attr("data-field"), this.value, {
                    reload: $(this).attr("reload") === 'true'
                });
            });

        //监听下拉框
        $(`.${this.unique} .metadata-select`)
            .off(this.eventNamespace)
            .on(`change${this.eventNamespace}`, function () {
                $this.updateField($(this).attr("data-id"), $(this).attr("data-field"), this.value, {
                    reload: $(this).attr("reload") === 'true'
                });
            });

        //监听开关
        if (!this.switchEventRegistered) {
            const switchEvent = `switch(${this.unique}-switch)`;
            if (typeof layui !== 'undefined' && typeof layui.off === 'function') layui.off(switchEvent, 'form');
            this.layuiForm.on(switchEvent, function (data) {
                if ($this.isDestroyed) return;
                $this.updateField($(data.elem).attr("data-id"), $(data.elem).attr("data-field"), data.elem.checked ? 1 : 0, {
                    reload: $(data.elem).attr("reload") === 'true'
                });
            });
            this.switchEventRegistered = true;
        }

        $(`.${this.unique} .render-image`)
            .off(this.eventNamespace)
            .on(`click${this.eventNamespace} keydown${this.eventNamespace}`, function (event) {
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
        this.$table.off(this.eventNamespace);
        this.$table.on(
            `check.bs.table${this.eventNamespace} uncheck.bs.table${this.eventNamespace} ` +
            `check-all.bs.table${this.eventNamespace} uncheck-all.bs.table${this.eventNamespace}`,
            (e, row) => {
                if (this.isTreeTable && ['check', 'uncheck'].includes(e.type)) {
                    const isChecked = e.type === 'check';
                    const idField = this.options.idField || 'id';
                    const parentIdField = this.options.parentIdField || 'pid';
                    const rowId = this.#getFieldValue(row, idField);
                    const allData = this.$table.bootstrapTable('getData', {useCurrentPage: false});
                    const children = allData.filter(item => this.#getFieldValue(item, parentIdField) === rowId);
                    children.forEach(child => {
                        this.$table.bootstrapTable(isChecked ? 'checkBy' : 'uncheckBy', {
                            field: idField,
                            values: [this.#getFieldValue(child, idField)]
                        });
                    });
                }
                this.#scheduleLifecycleUpdate('selection');
            }
        );
    }

    /**
     * 渲染表格
     */
    render() {
        if (this.isRendered || this.isDestroyed) {
            return this;
        }
        this.isRendered = true;
        if (typeof this.queryUrl === 'string') {
            this.loadState = {status: 'loading', error: null};
        }
        this.$table.data('adminTable', this);
        Table.getInstances().add(this);
        //表单构造参数
        this.#createOptions();
        this.#createRequest();
        this.$table.bootstrapTable(this.options);
        this.#registerGlobalEvent();
        return this;
    }

    destroy() {
        if (this.isDestroyed || this.isDestroying) {
            return;
        }
        this.isDestroying = true;
        clearTimeout(this.lifecycleTimer);
        this.lifecycleTimer = null;
        if (this.hasEmittedReady) {
            this.#emitLifecycle('destroy', 'destroy');
        }
        this.isDestroyed = true;
        $(document).off(this.eventNamespace).off(this.floatEventNamespace);
        this.$deleteSelector?.off(this.eventNamespace);
        this.$table.off(this.eventNamespace).off(this.floatEventNamespace);
        Object.values(this.floatMessageMap).forEach(index => layer.close(index));
        this.floatMessageMap = {};
        this.mobileDictCache.clear();
        this.mobileDictRequests.clear();
        if (this.switchEventRegistered && typeof layui !== 'undefined' && typeof layui.off === 'function') {
            try {
                layui.off(`switch(${this.unique}-switch)`, 'form');
            } catch (error) {
                util.debug('Table switch event destroy skipped: ' + this.unique, '#ff4f33');
            }
        }
        this.switchEventRegistered = false;
        this.search?.destroy();
        if (this.isRendered) {
            try {
                this.$table.bootstrapTable('destroy');
            } catch (error) {
                util.debug('Table destroy skipped: ' + this.unique, '#ff4f33');
            }
        }
        this.isRendered = false;
        this.isDestroying = false;
        this.$table.removeData('adminTable');
        Table.getInstances().delete(this);
    }
}
