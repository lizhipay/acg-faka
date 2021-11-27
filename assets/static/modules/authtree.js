/*
* @Author: Jeffrey Wang
* @Date:   2018-03-16 18:24:47
* @Version: v1.2.4
* @Last Modified by:   Jeffrey Wang
* @Last Modified time: 2019-04-29 14:33:00
*/
// 节点树
layui.define(['jquery', 'form'], function (exports) {
    var $ = layui.jquery;
    var form = layui.form;
    var MOD_NAME = 'authtree';

    var obj = {
        // 渲染 + 绑定事件
        openIconContent: '',
        closeIconContent: '',
        // 表单类型 checkbox: 多选，radio：单选
        checkType: 'checkbox',
        // 选中、半选中、未选中
        checkedIconContent: '',
        halfCheckedIconContent: '',
        notCheckedIconContent: '',
        // 保存节点数据
        checkedNode: {},
        notCheckedNode: {},
        // 临时保存最新操作影响的节点
        lastCheckedNode: {},
        lastNotCheckedNode: {},
        // 已经渲染过的树，可用来获取配置，{ dst: {trees: '树的节点数据', opt: '配置'} }
        renderedTrees: {},
        // 使用 layui 的监听事件
        on: function (events, callback) {
            return layui.onevent.call(this, MOD_NAME, events, callback);
        },
        /**
         * 渲染DOM并绑定事件
         * @param  {[type]} dst       [目标ID，如：#test1]
         * @param  {[type]} trees     [数据，格式：{}]
         * @param  {[type]} inputname [上传表单名]
         * @param  {[type]} layfilter [lay-filter的值]
         * @param  {[type]} openall [是否展开全部]
         * @return {[type]}           [description]
         */
        render: function (dst, trees, opt) {
            // 表单名称配置
            var inputname = opt.inputname ? opt.inputname : 'menuids[]';
            opt.inputname = inputname;
            // lay-filter 配置
            var layfilter = opt.layfilter ? opt.layfilter : 'checkauth';
            opt.layfilter = layfilter;
            // 默认展开全部 配置
            var openall = opt.openall ? opt.openall : false;
            opt.openall = openall;
            // 双击展开此层配置
            var dblshow = opt.dblshow ? opt.dblshow : false;
            opt.dblshow = dblshow;
            // 双击时间差 - 不能设置过长，否则单击延迟很感人
            var dbltimeout = opt.dbltimeout ? opt.dbltimeout : 120;
            opt.dbltimeout = dbltimeout;
            // 默认展开有选中数据的层
            var openchecked = typeof opt.openchecked !== 'undefined' ? opt.openchecked : true;
            opt.openchecked = openchecked;
            // 自动取消选中
            var autoclose = typeof opt.autoclose !== 'undefined' ? opt.autoclose : true;
            opt.autoclose = autoclose;
            // 自动选择直属父级节点
            var autochecked = typeof opt.autochecked !== 'undefined' ? opt.autochecked : true;
            opt.autochecked = autochecked;
            // 是否隐藏左侧 单选/多选的选框 -- 特殊需求，一般用于单选树并且不用
            var hidechoose = typeof opt.hidechoose !== 'undefined' ? opt.hidechoose : false;
            opt.hidechoose = hidechoose;
            // 是否开启半选
            var halfchoose = typeof opt.halfchoose !== 'undefined' ? opt.halfchoose : false;
            opt.halfchoose = halfchoose;
            // 收起叶子节点（排列于一行）
            var collapseLeafNode = typeof opt.collapseLeafNode !== 'undefined' ? opt.collapseLeafNode : false;
            opt.collapseLeafNode = collapseLeafNode;
            // 有子节点的前显字符配置
            opt.prefixChildStr = opt.prefixChildStr ? opt.prefixChildStr : '├─';
            // 单选、多选配置
            opt.checkType = opt.checkType ? opt.checkType : 'checkbox';
            this.checkType = opt.checkType;
            // 皮肤可选择
            opt.checkSkin = opt.checkSkin ? opt.checkSkin : 'primary';
            // 主题定制
            opt.theme = opt.theme ? opt.theme : '';
            opt.themePath = opt.themePath ? opt.themePath : 'layui_exts/tree_themes/';
            // 展开、折叠节点的前显字符配置
            opt.openIconContent = opt.openIconContent ? opt.openIconContent : '&#xe625;';
            this.openIconContent = opt.openIconContent;
            opt.closeIconContent = opt.closeIconContent ? opt.closeIconContent : '&#xe623;';
            this.closeIconContent = opt.closeIconContent;
            // 选中、半选中、未选中节点的图标配置
            opt.checkedIconContent = opt.checkedIconContent ? opt.checkedIconContent : '\e605';
            this.checkedIconContent = opt.checkedIconContent;
            opt.halfCheckedIconContent = opt.halfCheckedIconContent ? opt.halfCheckedIconContent : '\e605';
            this.halfCheckedIconContent = opt.halfCheckedIconContent;
            opt.notCheckedIconContent = opt.notCheckedIconContent ? opt.notCheckedIconContent : '&#xe605;';
            this.notCheckedIconContent = opt.notCheckedIconContent;
            // 渲染配置参数
            opt.checkedKey = opt.checkedKey ? opt.checkedKey : 'checked';
            opt.childKey = opt.childKey ? opt.childKey : 'list';
            opt.disabledKey = opt.disabledKey ? opt.disabledKey : 'disabled';
            opt.nameKey = opt.nameKey ? opt.nameKey : 'name';
            opt.valueKey = opt.valueKey ? opt.valueKey : 'value';

            // 不启用双击展开，单击不用延迟
            var dblisten = true;
            if (dblshow) {
                // 开启双击展开，双击事件默认为120s
            } else {
                // 未开启双击展开且 dbltimeout <= 0，则说明不用监听双击事件
                if (opt.dbltimeout <= 0) {
                    dblisten = false;
                }
                dbltimeout = 0;
                // opt.dbltimeout = dbltimeout;
            }

            // 记录渲染过的树
            obj.renderedTrees[dst] = {trees: trees, opt: opt};

            // 主题定制
            if (typeof opt.theme === 'string' && opt.theme !== '') {
                $(dst).addClass(opt.theme)
                layui.link("/assets/static/modules/src/style/authtree/css/" + opt.theme + '.css')
            }
            if (opt.hidechoose) {
                $(dst).addClass('auth-tree-hidechoose');
            }
            $(dst).html(obj.renderAuth(trees, 0, {
                inputname: inputname,
                layfilter: layfilter,
                openall: openall,
                openchecked: openchecked,
                checkType: this.checkType,
                prefixChildStr: opt.prefixChildStr,
                // 配置参数
                checkedKey: opt.checkedKey,
                childKey: opt.childKey,
                disabledKey: opt.disabledKey,
                nameKey: opt.nameKey,
                valueKey: opt.valueKey,
                collapseLeafNode: opt.collapseLeafNode,
            }));
            if (openchecked) {
                obj.showChecked(dst);
            }
            form.render();
            // 变动则存一下临时状态
            obj._saveNodeStatus(dst);

            // 开启自动宽度优化
            obj.autoWidthAll();
            // 备注：如果使用form.on('checkbox()')，外部就无法使用form.on()监听同样的元素了（LAYUI不支持重复监听了）。
            // form.on('checkbox('+layfilter+')', function(data){
            // 	/*属下所有权限状态跟随，如果选中，往上走全部选中*/
            // 	var childs = $(data.elem).parent().next().find('input[type="checkbox"]').prop('checked', data.elem.checked);
            // 	if(data.elem.checked){
            // 		/*查找child的前边一个元素，并将里边的checkbox选中状态改为true。*/
            // 		$(data.elem).parents('.auth-child').prev().find('input[type="checkbox"]').prop('checked', true);
            // 	}
            // 	/*console.log(childs);*/
            // 	form.render('checkbox');
            // });

            // 解决单击和双击冲突问题的 timer 变量
            var timer = 0;
            $(dst).find('.auth-single:first').unbind('click').on('click', '.layui-form-checkbox,.layui-form-radio', function (event) {
                // window.event? window.event.cancelBubble = true : event.stopPropagation();
                var that = this;
                clearTimeout(timer);
                // 双击判断需要的延迟处理
                timer = setTimeout(function () {
                    var elem = $(that).prev();
                    var checked = elem.is(':checked');

                    if (autochecked) {
                        if (checked) {
                            /*查找child的前边一个元素，并将里边的checkbox选中状态改为true。*/
                            elem.parents('.auth-child').prev().find('.authtree-checkitem:not(:disabled)[type="checkbox"]').prop('checked', true);
                        }
                        elem.parent().next().find('.authtree-checkitem:not(:disabled)[type="checkbox"]').prop('checked', checked);
                    }
                    if (autoclose) {
                        if (checked) {
                            // pass
                        } else {
                            // 自动关闭父级选中节点
                            obj._autoclose($(that).parent());
                        }
                    }
                    form.render('checkbox');
                    form.render('radio');
                    // 变动则存一下临时状态
                    obj._saveNodeStatus(dst);
                    // 触发 change 事件
                    obj._triggerEvent(dst, 'change', {
                        othis: $(that),
                        oinput: elem,
                        value: elem.val(),
                    });
                    obj.autoWidthAll();
                }, dbltimeout);
                return false;
            });
            /*动态绑定展开事件*/
            $(dst).unbind('click').on('click', '.auth-icon', function () {
                obj.iconToggle(dst, this);
            });
            /*双击展开*/
            $(dst).find('.auth-single:first').unbind('dblclick').on('dblclick', '.layui-form-checkbox,.layui-form-radio', function (e) {
                // 触发时间 > 0，才触发双击事件
                // opt.dbltimeout 是用户真实设定的超时时间，与 dbltimeout 不一样
                // if (opt.dbltimeout > 0) {
                obj._triggerEvent(dst, 'dblclick', {
                    othis: $(this),
                    elem: $(this).prev(),
                    value: $(this).prev().val(),
                });
                // }
                if (dblshow) {
                    clearTimeout(timer);
                    obj.iconToggle(dst, $(this).prevAll('.auth-icon:first'));
                }
            }).on('selectstart', function () {
                // 屏蔽双击选中文字
                return false;
            });
        },
        // 自动关闭 - 如果兄弟节点均没选中，递归取消上级元素选中状态，传入的是 .auth-status 节点，递归 .auth-status 上级节点
        _autoclose: function (obj) {
            var single = $(obj).parent().parent();
            var authStatus = single.parent().prev();

            if (!authStatus.hasClass('auth-status')) {
                return false;
            }
            // 仅一层
            if (single.find('div>.auth-status>input.authtree-checkitem:not(:disabled)[type="checkbox"]:checked').length === 0) {
                authStatus.find('.authtree-checkitem:not(:disabled)[type="checkbox"]').prop('checked', false);
                this._autoclose(authStatus);
            }
        },
        // 以 icon 的维度，切换显示下级空间
        iconToggle: function (dst, iconobj) {
            var origin = $(iconobj);
            var child = origin.parent().parent().find('.auth-child:first');
            if (origin.is('.active')) {
                /*收起*/
                origin.removeClass('active').html(obj.closeIconContent);
                child.slideUp('fast');
            } else {
                /*展开*/
                origin.addClass('active').html(obj.openIconContent);
                child.slideDown('fast');
            }
            obj._triggerEvent(dst, 'deptChange');
            return false;
        },
        // 递归创建格式
        renderAuth: function (tree, dept, opt) {
            var inputname = opt.inputname;
            var layfilter = opt.layfilter;
            var openall = opt.openall;
            var str = '<div class="auth-single">';

            // 参数配置
            var childKey = opt.childKey;
            var nameKey = opt.nameKey;
            var valueKey = opt.valueKey;

            var _this = this;
            layui.each(tree, function (index, item) {
                var hasChild = (item[childKey] && (item[childKey].length || !$.isEmptyObject(item[childKey].length))) ? 1 : 0;
                // 注意：递归调用时，this的环境会改变！
                var append = hasChild ? obj.renderAuth(item[childKey], dept + 1, opt) : '';
                var openstatus = openall || (opt.openchecked && item.checked);
                var isChecked = _this._getStatusByDynamicKey(item, opt.checkedKey, opt.valueKey);
                var isDisabled = _this._getStatusByDynamicKey(item, opt.disabledKey, opt.valueKey);

                var rowFlag = !hasChild && opt.collapseLeafNode;
                if (rowFlag) {
                    str += '<div class="auth-row auth-skin"><div class="auth-row-item auth-status" style="display: flex;flex-direction: row;align-items: flex-end;">' +
                        (hasChild ? '' : '<i class="layui-icon auth-leaf" style="opacity:0;color: transparent;">&#xe626;</i>');
                } else {
                    // '+new Array(dept * 4).join('&nbsp;')+'
                    str += '<div class="auth-skin"><div class="auth-status" style="display: flex;flex-direction: row;align-items: flex-end;"> ' +
                        (hasChild ? '<i class="layui-icon auth-icon ' + (openstatus ? 'active' : '') + '" style="cursor:pointer;">' + (openstatus ? obj.openIconContent : obj.closeIconContent) + '</i>' : '<i class="layui-icon auth-leaf" style="opacity:0;color: transparent;">&#xe626;</i>') +
                        (dept > 0 ? ('<span class="auth-prefix">' + opt.prefixChildStr + ' </span>') : '');
                }
                str +=
                    '<input class="authtree-checkitem" type="' + opt.checkType + '" name="' + inputname + '" title="' + item[nameKey] + '" value="' + item[valueKey] + '" lay-skin="primary" lay-filter="' + layfilter + '" ' +
                    (isChecked ? ' checked="checked"' : '') +
                    (isDisabled ? ' disabled' : '') +
                    '> </div>' +
                    ' <div class="auth-child" style="' + (openstatus ? '' : 'display:none;') + '"> ' + append + '</div></div>'
            });
            str += '</div>';
            return str;
        },
        // 通过动态key，获取状态信息，dynamicKey支持：数字/字符时直接取属性，对象时查看是否在数组中
        _getStatusByDynamicKey: function (item, dynamicKey, valueKey) {
            var isChecked = false;
            if (typeof dynamicKey === "string" || typeof dynamicKey === 'number') {
                isChecked = item[dynamicKey];
            } else if (typeof dynamicKey === 'object') {
                isChecked = $.inArray(item[valueKey], dynamicKey) !== -1;
            } else {
                isChecked = false;
            }
            return isChecked;
        },
        /**
         * 显示到已选中的最高层级
         * @param  {[type]} dst [description]
         * @return {[type]}     [description]
         */
        showChecked: function (dst) {
            $(dst).find('.authtree-checkitem:checked').parents('.auth-child').show();
        },
        /**
         * 将普通列表无限递归转换为树
         * @param  {[type]} list       [普通的列表，必须包括 opt.primaryKey 指定的键和 opt.parentKey 指定的键]
         * @param {[type]} opt [配置参数，支持 primaryKey(主键 默认id) parentKey(父级id对应键 默认pid) nameKey(节点标题对应的key 默认name) valueKey(节点值对应的key 默认id) checkedKey、disabledKey(节点是否选中的字段 默认checked，传入数组则判断主键是否在此数组中) startPid(第一层扫描的PID 默认0) currentDept(当前层 默认0) maxDept(最大递归层 默认100) childKey(递归完成后子节点对应键 默认list) deptPrefix(根据层级重复的前缀 默认'')]
         * @return {[type]}            [description]
         */
        listConvert: function (list, opt) {
            opt.primaryKey = opt.primaryKey ? opt.primaryKey : 'id';
            opt.parentKey = opt.parentKey ? opt.parentKey : 'pid';
            opt.startPid = opt.startPid ? opt.startPid : 0;
            opt.authType = opt.authType ? opt.authType : 'type';
            opt.currentDept = opt.currentDept ? parseInt(opt.currentDept) : 0;
            opt.maxDept = opt.maxDept ? opt.maxDept : 100;
            opt.childKey = opt.childKey ? opt.childKey : 'list';
            opt.checkedKey = opt.checkedKey ? opt.checkedKey : 'checked';
            opt.disabledKey = opt.disabledKey ? opt.disabledKey : 'disabled';
            opt.nameKey = opt.nameKey ? opt.nameKey : 'name';
            opt.valueKey = opt.valueKey ? opt.valueKey : 'id';
            return this._listToTree(list, opt.startPid, opt.currentDept, opt);
        },
        // 实际的递归函数，将会变化的参数抽取出来
        _listToTree: function (list, startPid, currentDept, opt) {
            if (opt.maxDept < currentDept) {
                return [];
            }
            var child = [];
            for (var index in list) {
                if (list.hasOwnProperty(index)) {
                    // 筛查符合条件的数据（主键 = startPid）
                    var item = list[index];
                    if (typeof item[opt.parentKey] !== 'undefined' && item[opt.parentKey] === startPid) {
                        // 满足条件则递归
                        var nextChild = this._listToTree(list, item[opt.primaryKey], currentDept + 1, opt);
                        // 节点信息保存
                        var node = {};
                        if (nextChild.length > 0) {
                            node[opt.childKey] = nextChild;
                        }
                        node['name'] = item[opt.nameKey];
                        node['value'] = item[opt.valueKey];
                        node['type'] = item[opt.authType] || 0;
                        // 禁用/选中节点的两种渲染方式
                        node['checked'] = this._getStatusByDynamicKey(item, opt.checkedKey, opt.valueKey);
                        node['disabled'] = this._getStatusByDynamicKey(item, opt.disabledKey, opt.valueKey);
                        child.push(node);
                    }
                }
            }
            return child;
        },
        /**
         * 将树转为单选可用的 select，如果后台返回列表数据，可以先转换为 tree
         * @param  {[type]} tree [description]
         * @param  {[type]} opt  [description]
         * @return {[type]}      [description]
         */
        treeConvertSelect: function (tree, opt) {
            if (typeof tree.length !== 'number' || tree.length <= 0) {
                return [];
            }
            // 初始化层级
            opt.currentDept = opt.currentDept ? parseInt(opt.currentDept) : 0;
            // 子节点列表的Key
            opt.childKey = opt.childKey ? opt.childKey : 'list';
            // 名称的key
            opt.nameKey = opt.valueKey ? opt.valueKey : 'name';
            // 值的key
            opt.valueKey = opt.valueKey ? opt.valueKey : 'value';
            // 选中的key - 仅支持字符串
            opt.checkedKey = opt.checkedKey ? opt.checkedKey : 'checked';
            // 禁用的key
            opt.disabledKey = opt.disabledKey ? opt.disabledKey : 'disabled';
            // 有子节点的前缀
            opt.prefixChildStr = opt.prefixChildStr ? opt.prefixChildStr : '├─ ';
            // 没有子节点的前缀
            opt.prefixNoChildStr = opt.prefixNoChildStr ? opt.prefixNoChildStr : '● ';
            // 树的深度影响的子节点数据
            opt.prefixDeptStr = opt.prefixDeptStr ? opt.prefixDeptStr : '　';
            // 如果第一列就存在没有子节点的情况，加的特殊前缀
            opt.prefixFirstEmpty = opt.prefixFirstEmpty ? opt.prefixFirstEmpty : '　　'

            return this._treeToSelect(tree, opt.currentDept, opt);
        },
        // 实际处理递归的函数
        _treeToSelect: function (tree, currentDept, opt) {
            var ansList = [];
            var prefix = '';

            for (var i = 0; i < currentDept; i++) {
                prefix += opt.prefixDeptStr;
            }

            for (var index in tree) {
                if (!tree.hasOwnProperty(index)) {
                    continue;
                }
                var child_flag = 0;
                var item = tree[index];
                if (opt.childKey in item && item[opt.childKey] && item[opt.childKey].length > 0) {
                    child_flag = 1;
                }
                var name = item[opt.nameKey];
                if (child_flag) {
                    name = opt.prefixChildStr + name;
                } else {
                    if (currentDept > 1) {
                        name = opt.prefixNoChildStr + name;
                    } else {
                        name = opt.prefixFirstEmpty + name;
                    }
                }
                ansList.push({
                    name: prefix + name,
                    value: item[opt.valueKey],
                    checked: this._getStatusByDynamicKey(item, opt.checkedKey, opt.valueKey),
                    disabled: this._getStatusByDynamicKey(item, opt.disabledKey, opt.valueKey),
                });
                // 添加子节点
                if (child_flag) {
                    var child = this._treeToSelect(item[opt.childKey], currentDept + 1, opt);
                    // apply 的骚操作，使用第二个参数可以用于合并两个数组
                    ansList.push.apply(ansList, child);
                }
            }
            return ansList;
        },
        autoWidthAll: function () {
            for (var dst in this.renderedTrees) {
                if (this.renderedTrees.hasOwnProperty(dst)) {
                    this.autoWidth(dst)
                }
            }
        },
        // 自动调整宽度以解决 form.render()生成元素兼容性问题，如果用户手动调用 form.render() 之后也需要调用此方法
        autoWidth: function (dst) {
            var tree = this.getRenderedInfo(dst);
            var opt = tree.opt;
            $(dst).css({
                'whiteSpace': 'nowrap',
                'maxWidth': '100%',
            });
            // 自动刷新多选框半选状态
            // this.autoNodeRender(dst)
            // 自动宽度调整的逻辑
            $(dst).find('.layui-form-checkbox,.layui-form-radio,.layui-form-audio').each(function (index, item) {
                var width = $(this).find('span').width() + $(this).find('i').width() + 25;
                if ($(this).is(':hidden')) {
                    // 比较奇葩的获取隐藏元素宽度的手法，请见谅
                    $('body').append('<div id="layui-authtree-get-width">' + $(this).html() + '</div>');
                    width = $('#layui-authtree-get-width').find('span').width() + $('#layui-authtree-get-width').find('i').width() + 29;
                    $('#layui-authtree-get-width').remove();
                } else {
                }

                //$(this).width(width);
                // 隐藏 单选/多选的左侧选框隐藏
                if (opt.hidechoose) {
                    $(this).prevAll('i').css({
                        zIndex: 2,
                    });
                    $(this).css({
                        position: 'relative'
                        , left: function () {
                            return '-' + $(this).css('padding-left');// 避免点击抖动的骚操作
                        }
                    }).find('i').hide();
                }
            });
        },
        // 自动刷新多选框半选状态
        autoNodeRender: function (dst) {
            var tree = this.getRenderedInfo(dst);
            var opt = tree.opt;
            if (opt.halfchoose) {
                this._nodeRenderByParent($(dst).find('.auth-single'))
            }
            document.styleSheets[0].addRule(dst + ' .layui-icon-ok:before', 'content: ' + this.checkedIconContent)
        },
        _nodeRenderByParent: function (leaf) {
        },
        // 触发自定义事件
        _triggerEvent: function (dst, events, other) {
            var tree = this.getRenderedInfo(dst);
            var origin = $(dst);
            if (tree) {
                var opt = tree.opt;
                var data = {
                    opt: opt,
                    tree: tree.trees,
                    dst: dst,
                    othis: origin,
                };
                if (other && typeof other === 'object') {
                    data = $.extend(data, other);
                }
                // 支持 dst 和 用户的配置的 layfilter 监听
                layui.event.call(origin, MOD_NAME, events + '(' + dst + ')', data);
                layui.event.call(origin, MOD_NAME, events + '(' + opt.layfilter + ')', data);
            } else {
                return false;
            }
        },
        // 获取渲染过的信息
        getRenderedInfo: function (dst) {
            return this.renderedTrees[dst];
        },
        // 动态获取最大深度
        getMaxDept: function (dst) {
            var next = $(dst);
            var dept = 0;
            while (next.length && dept < 100000) {
                next = this._getNext(next);
                if (next.length) {
                    dept++;
                } else {
                    break;
                }
            }
            return dept;
        },
        // 全选
        checkAll: function (dst) {
            var origin = $(dst);

            origin.find('.authtree-checkitem:not(:disabled):not(:checked)').prop('checked', true);
            form.render('checkbox');
            form.render('radio');
            obj.autoWidthAll();
            // 变动则存一下临时状态
            obj._saveNodeStatus(dst);
            obj._triggerEvent(dst, 'change');
            obj._triggerEvent(dst, 'checkAll');
        },
        // 全不选
        uncheckAll: function (dst) {
            var origin = $(dst);
            origin.find('.authtree-checkitem:not(:disabled):checked').prop('checked', false);
            form.render('checkbox');
            form.render('radio');
            obj.autoWidthAll();
            // 变动则存一下临时状态
            obj._saveNodeStatus(dst);
            obj._triggerEvent(dst, 'change');
            obj._triggerEvent(dst, 'uncheckAll');
        },
        // 显示整个树
        showAll: function (dst) {
            this.showDept(dst, this.getMaxDept(dst));
        },
        // 关闭整颗树
        closeAll: function (dst) {
            this.closeDept(dst, 1);
        },
        // 切换整颗树的显示/关闭
        toggleAll: function (dst) {
            if (this._shownDept(2)) {
                this.closeDept(dst);
            } else {
                this.showAll(dst);
            }
        },
        // 显示到第 dept 层
        showDept: function (dst, dept) {
            var next = $(dst);
            for (var i = 1; i < dept; i++) {
                next = this._getNext(next);
                if (next.length) {
                    this._showSingle(next);
                } else {
                    break;
                }
            }
            obj._triggerEvent(dst, 'deptChange', {dept: dept});
        },
        // 第 dept 层之后全部关闭
        closeDept: function (dst, dept) {
            var next = $(dst);
            for (var i = 0; i < dept; i++) {
                next = this._getNext(next);
            }
            while (next.length) {
                this._closeSingle(next);
                next = this._getNext(next);
            }
            obj._triggerEvent(dst, 'deptChange', {dept: dept});
        },
        // 临时保存所有节点信息状态
        _saveNodeStatus: function (dst) {
            var currentChecked = this.getChecked(dst);
            var currentNotChecked = this.getNotChecked(dst);
            // 保存新信息前，最新选择的信息
            this.lastCheckedNode[dst] = this._getLastChecked(dst, currentChecked, currentNotChecked);
            this.lastNotCheckedNode[dst] = this._getLastNotChecked(dst, currentChecked, currentNotChecked);
            this.checkedNode[dst] = currentChecked;
            this.notCheckedNode[dst] = currentNotChecked;

            // console.log('保存节点信息', this.checkedNode[dst], this.notCheckedNode[dst], this.lastCheckedNode[dst], this.lastNotCheckedNode[dst]);
        },
        // 判断某一层是否显示
        _shownDept: function (dst, dept) {
            var next = $(dst);
            for (var i = 0; i < dept; i++) {
                next = this._getNext(next);
            }
            return !next.is(':hidden');
        },
        // 获取
        _getNext: function (dst) {
            return $(dst).find('.auth-single:first>div>.auth-child');
        },
        // 显示某层 single
        _showSingle: function (dst) {
            layui.each(dst, function (index, item) {
                var origin = $(item).find('.auth-single:first');
                var parentChild = origin.parent();
                var parentStatus = parentChild.prev();
                if (!parentStatus.find('.auth-icon').hasClass('active')) {
                    parentChild.show();
                    // 显示上级的 .auth-child节点，并修改.auth-status的折叠状态
                    parentStatus.find('.auth-icon').addClass('active').html(obj.openIconContent);
                }
            });
        },
        // 关闭某层 single
        _closeSingle: function (dst) {
            var origin = $(dst).find('.auth-single:first');
            var parentChild = origin.parent();
            var parentStatus = parentChild.prev();
            if (parentStatus.find('.auth-icon').hasClass('active')) {
                parentChild.hide();
                // 显示上级的 .auth-child节点，并修改.auth-status的折叠状态
                parentStatus.find('.auth-icon').removeClass('active').html(obj.closeIconContent);
            }
        },
        // 获取选中叶子结点
        getLeaf: function (dst) {
            var leafs = $(dst).find('.auth-leaf').parent().find('.authtree-checkitem:checked');
            var data = [];
            leafs.each(function (index, item) {
                // console.log(item);
                data.push(item.value);
            });
            // console.log(data);
            return data;
        },
        // 获取所有节点数据
        getAll: function (dst) {
            var inputs = $(dst).find('.authtree-checkitem');
            var data = [];
            inputs.each(function (index, item) {
                data.push(item.value);
            });
            // console.log(data);
            return data;
        },
        // 获取最新选中（之前取消-现在选中）
        getLastChecked: function (dst) {
            return this.lastCheckedNode[dst] || [];
        },
        // (逻辑)最新选中（之前取消-现在选中）
        _getLastChecked: function (dst, currentChecked, currentNotChecked) {
            var lastCheckedNode = currentChecked;

            var data = [];
            for (var i in lastCheckedNode) {
                if ($.inArray(lastCheckedNode[i], this.notCheckedNode[dst]) !== -1) {
                    data.push(lastCheckedNode[i]);
                }
            }
            return data;
        },
        // 获取所有选中的数据
        getChecked: function (dst) {
            var inputs = $(dst).find('.authtree-checkitem:checked');
            var data = [];
            inputs.each(function (index, item) {
                data.push(item.value);
            });
            return data;
        },
        // 获取最新取消（之前取消-现在选中）
        getLastNotChecked: function (dst) {
            return this.lastNotCheckedNode[dst] || [];
        },
        // (逻辑)最新取消（之前选中-现在取消）
        _getLastNotChecked: function (dst, currentChecked, currentNotChecked) {
            var lastNotCheckedNode = currentNotChecked;

            var data = [];
            for (var i in lastNotCheckedNode) {
                if ($.inArray(lastNotCheckedNode[i], this.checkedNode[dst]) !== -1) {
                    data.push(lastNotCheckedNode[i]);
                }
            }
            return data;
        },
        // 获取未选中数据
        getNotChecked: function (dst) {
            var inputs = $(dst).find('.authtree-checkitem:not(:checked)');
            var data = [];
            inputs.each(function (index, item) {
                data.push(item.value);
            });
            // console.log(data);
            return data;
        }
    };
    exports('authtree', obj);
});
