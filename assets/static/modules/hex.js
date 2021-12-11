layui.define(['layer', 'jquery', 'form', 'table', 'upload', 'laydate', 'authtree'], function (exports) {

    let layer = layui.layer, $ = layui.jquery, form = layui.form,
        table = layui.table, upload = layui.upload, authtree = layui.authtree,
        laydate = layui.laydate;


    let hex = {
        property: {
            mapId: {}
        }, percentageHtml(rate) {
            if (rate == null || rate == undefined || rate == '' || isNaN(rate)) {
                rate = 0;
            }

            if (typeof rate == 'number') {
                rate = rate.toFixed(2);
            }


            let color;
            if (rate <= 50) {
                color = "red";
            } else if (rate <= 70) {
                color = '#ffb020';
            } else if (rate <= 100) {
                color = 'green';
            }

            return '<span style="color: ' + color + '">' + rate + '%</span>';
        },
        setIdMap(array) {
            if (array === undefined) {
                return;
            }
            array.forEach(item => {
                this.property.mapId[item.id] = item;
            });
        },
        getIdMap(id) {
            return this.property.mapId[id];
        },
        getMapItem(obj) {
            return this.getIdMap(hex.getObjectId(obj))
        },
        getObjectId(obj) {
            return $(obj).attr('data-id');
        },
        listObjectToArray(list) {
            let ids = [];

            list.forEach(function (item, index) {
                ids.push(item.id);
            });

            return ids;
        },
        copy(element) {
            var clipboard = new Clipboard(element); //实例化对象
            clipboard.on('success', function (e) {
                layer.msg('复制成功');
            });
            clipboard.on('error', function (e) {
                layer.msg('复制失败，请手动复制');
            });
        },
        getDict(dict, done, keywords = '') {
            if (typeof dict === "string") {
                $.post('/admin/api/dict/get', {dict: dict, keywords: keywords}, res => {
                    if (keywords == '') {
                        localStorage.setItem('user_' + dict, JSON.stringify(res));
                    }
                    done(res);
                });
            } else {
                let data = {data: dict};
                done(data);
            }
        },
        getDictSync(dict, keywords = '') {
            $.ajaxSettings.async = false;
            $.post('/admin/api/dict/get', {dict: dict, keywords: keywords}, res => {
                data = res;
            });
            $.ajaxSettings.async = true;
            return data;
        },
        getDictNameSync(dict, value) {
            let dictSync = this.getDictSync(dict);
            let name = "无";
            dictSync.data.forEach(item => {
                if (item.id == value) {
                    name = item.name;
                }
            });
            return name;
        },
        paramsToJSONObject(url) {
            var hash;
            var myJson = {};
            var hashes = url.slice(url.indexOf('?') + 1).split('&');
            for (var i = 0; i < hashes.length; i++) {
                hash = hashes[i].split('=');
                if (hash[0].indexOf("[]") !== -1) {
                    if (!myJson.hasOwnProperty(hash[0])) {
                        myJson[hash[0]] = [];
                    }
                    myJson[hash[0]].push(hash[1]);
                } else {
                    myJson[hash[0]] = hash[1];
                }

            }
            return myJson;
        },
        remoteViewOpen(url, title = '查看', area = ['700px', '450px']) {
            $.get(url, res => {
                layer.open({
                    title: title,
                    type: 1,
                    area: area,
                    anim: 5,
                    maxmin: true, //开启最大化最小化按钮
                    shadeClose: true,
                    content: res
                })
                ;
            });
        },
        set(key, val) {
            return localStorage.setItem(key, val);
        },
        get(key) {
            return localStorage.getItem(key);
        },
        query(elem, table, fields, show = false, values = {}) {
            let instance = $(elem);
            instance.append("<div class='hex-query-form' style='" + (show === false ? 'display: none;' : '') + "'></div>");
            instance.addClass('layui-form-item layui-form');

            instance.append('<style>\n' +
                '    ' + elem + ' .layui-input, ' + elem + ' .layui-form-select dl dd {\n' +
                '        height: 30px;\n' +
                '    }\n' +
                '</style>');

            let formHtml = $(elem + ' .hex-query-form'), boxesObject = {};


            fields.forEach(item => {
                //设置默认值
                if (!values.hasOwnProperty(item.name) && item.hasOwnProperty('default')) {
                    values[item.name] = item.default;
                }

                let width = item.hasOwnProperty('width') ? 'style="width:' + (this.isPc() ? item.width + "px" : "100%") + ';padding-top:10px;"' : 'style="padding-top:10px;"';

                switch (item.type) {
                    case "input":
                        formHtml.append('<div class="layui-input-inline" ' + width + '>\n' +
                            '                            <input type="text" style="border-radius: 5px !important;" class="layui-input" placeholder="' + item.title + '" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">\n' +
                            '                        </div>');
                        break;
                    case "date":
                        formHtml.append(' <div class="layui-input-inline" ' + width + '>\n' +
                            '        <input type="text" style="border-radius: 5px !important;" class="layui-input" name="' + item.name + '" placeholder="' + item.title + '"  value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">\n' +
                            '      </div>');

                        //渲染组件
                        laydate.render({
                            elem: elem + ' input[name=' + item.name + ']',
                            type: 'datetime'
                        });
                        break;
                    case "select":
                        formHtml.append('<div class="layui-input-inline" ' + width + '>\n' +
                            '                    <select ' + (item.search === true ? 'lay-search=""' : '') + ' style="border-radius: 5px !important;" name="' + item.name + '"><option value="">' + item.title + '</option></select>\n' +
                            '                        </div>');

                        //渲染组件
                        if (item.hasOwnProperty('dict')) {
                            let selectInstance = $(elem + ' select[name=' + item.name + ']');
                            this.getDict(item.dict, res => {
                                res.data.forEach(s => {
                                    selectInstance.append(' <option value="' + s.id + '"  ' + (values.hasOwnProperty(item.name) ? (values[item.name] === s.id ? 'selected' : '') : '') + '>' + s.name + '</option>');
                                });
                                form.render();
                            });
                        }
                        break;
                    case "boxes":
                        formHtml.append('<div class="layui-input-inline" ' + width + '>\n' +
                            '                        <span class="' + item.name + '"></span>' +
                            '                        </div>');

                        if (item.hasOwnProperty('dict')) {
                            this.getDict(item.dict, res => {
                                var boxesData = [];
                                res.data.forEach(s => {
                                    boxesData.push({name: s.name, value: s.id});
                                });
                                boxesObject[item.name] = xmSelect.render({
                                    name: item.name,
                                    el: elem + ' .' + item.name,
                                    size: 'mini',
                                    style: {
                                        height: '28px'
                                    },
                                    language: 'zn',
                                    data: boxesData
                                });
                            });
                        }
                        break;
                    case "remoteSelect":
                        formHtml.append('<div class="layui-input-inline" ' + width + '>\n' +
                            '                        <span class="' + item.name + '"></span>' +
                            '                        </div>');

                        xmSelect.render({
                            el: elem + ' .' + item.name,
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
                                this.getDict(item.dict, res => {
                                    let boxesData = [];
                                    if (!res.hasOwnProperty('data')) {
                                        return cb([]);
                                    }
                                    res.data.forEach(s => {
                                        boxesData.push({name: s.name, value: s.id});
                                    });
                                    cb(boxesData);
                                }, val);
                            }
                        });
                        break;
                }
            });

            formHtml.append('<div class="layui-input-inline" style="padding-top:10px;width: 65px !important;"><button type="button" class="layui-btn layui-btn-primary layui-btn-sm queryBtn">' +
                '<i class="layui-icon layui-icon-search"></i>查询</button></div>');

            if (show === false) {
                instance.append('<div style="text-align: center;width: 100%;margin-top:10px;cursor: pointer;display: none;" class="hide"><i class="layui-icon layui-icon-up" title="关闭查询"></i></div>');
                instance.append('<div style="text-align: center;width: 100%;cursor: pointer;" class="show"><i class="layui-icon layui-icon-down" title="查询"></i></div>');
            }

            form.render();

            //监视回车事件
            /*            document.onkeydown = function (e) {
                            var ev = document.all ? window.event : e;
                            if (ev.keyCode == 13) {// 如（ev.ctrlKey && ev.keyCode==13）为ctrl+Center 触发
                                $(elem + ' .queryBtn').click();
                            }
                        }*/

            //监听查询按钮
            $(elem + ' .queryBtn').click(res => {
                let serialize = this.paramsToJSONObject(instance.serialize());
                /*                fields.forEach(item => {
                                    switch (item.type) {
                                        case "boxes":
                                            let list = [];
                                            let val = boxesObject[item.name].getValue();
                                            val.forEach(x => {
                                                list.push(x.value);
                                            });
                                            if (list.length > 0) {
                                                serialize[item.name] = list;
                                            } else {
                                                serialize[item.name] = "";
                                            }
                                            break;
                                    }
                                });
                                console.log(serialize);*/
                table.bootstrapTable('refresh', {
                    silent: false,
                    pageNumber: 1,
                    query: serialize
                });
            });

            //监听查询按钮
            $(elem + ' .show').click(function () {
                formHtml.slideDown(100);
                $(this).hide();
                $(elem + ' .hide').show();
            });

            $(elem + ' .hide').click(function () {
                formHtml.slideUp(100);
                $(this).hide();
                $(elem + ' .show').show();
            });
        },
        isPc() {
            if (document.documentElement.clientWidth < 768) {
                return false;
            }
            var userAgentInfo = navigator.userAgent;
            var Agents = ["Android", "iPhone",
                "SymbianOS", "Windows Phone",
                "iPad", "iPod"];
            var flag = true;
            for (var v = 0; v < Agents.length; v++) {
                if (userAgentInfo.indexOf(Agents[v]) > 0) {
                    flag = false;
                    break;
                }
            }
            return flag;
        },
        popup(url, fields, done, values = {}, area = '660px', edit = false, title = "添加", success = null) {

            area = this.isPc() ? area : ["100%", "100%"];

            let d = ' <div class="layui-card-body"><form class="layui-form layui-form-pane hex-modal">';
            let objectContainer = {}
            //初步渲染界面
            fields.forEach(item => {
                //设置默认值
                if (!values.hasOwnProperty(item.name) && item.hasOwnProperty('default')) {
                    values[item.name] = item.default;
                }

                if (values[item.name] == null && values.hasOwnProperty(item.name)) {
                    values[item.name] = '';
                }


                switch (item.type) {
                    case 'hidden':
                        d += '<input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">';
                        break;
                    case 'input':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
                            '            <div class="layui-input-block">\n' +
                            '                <input name="' + item.name + '" placeholder="' + item.placeholder + '" type="text" class="layui-input ' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '"/>' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'explain':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
                            '            <div class="layui-input-block">\n' +
                            '                <span style="position: relative;top: 6px;">' + item.placeholder + '</span>' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'textarea':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + '</label>\n' +
                            '            <div class="layui-input-block">\n' +
                            '                <textarea ' + (item.hasOwnProperty('height') ? 'style="height:' + item.height + 'px"' : '') + ' name="' + item.name + '" placeholder="' + item.placeholder + '" class="layui-textarea ' + item.name + '">' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '</textarea>' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'html':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + '</label>\n' +
                            '            <div class="layui-input-block">\n' +
                            '                <textarea ' + (item.hasOwnProperty('height') ? 'style="height:' + item.height + 'px"' : '') + ' name="' + item.name + '" placeholder="' + item.placeholder + '" class="layui-textarea ' + item.name + '">' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '</textarea>' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'editor':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + '</label>\n' +
                            '            <div class="layui-input-block"><textarea name="' + item.name + '" style="display: none;"></textarea>\n' +
                            '                <div style=""><button data-type="0" class="button-switch-' + item.name + '" type="button" style="width: 100%;border: none;background: white;border-radius: 5px 5px 0 0;color: #c9b8b8;"><i class="fas fa-code" style="color: #c9b8b8;"></i> HTML</button></div><div ' + (item.hasOwnProperty('height') ? 'style="height:' + item.height + 'px"' : '') + ' class="' + item.name + '">' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '</div>' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'radio':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item">\n' +
                            '            <label class="layui-form-label">' + item.title + '</label>\n' +
                            '            <div class="layui-input-block ' + item.name + '">\n' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'switch':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item"><input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : 0) + '">\n' +
                            '                <label class="layui-form-label">' + item.title + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
                            '                <div class="layui-input-block">\n' +
                            '                    <input class="' + item.name + '" type="checkbox" lay-filter="switch-' + item.name + '" value="1" title="' + item.text + '" ' + (values.hasOwnProperty(item.name) ? (values[item.name] == 1 ? 'checked' : '') : '') + '>\n' +
                            '                </div>\n' +
                            '            </div>';
                        break;
                    case 'select':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item">\n' +
                            '                <label class="layui-form-label">' + item.title + '</label>\n' +
                            '                <div class="layui-input-block">\n' +
                            '                    <select lay-filter="hex-' + item.name + '"  class="' + item.name + '" name="' + item.name + '" ' + (item.search == true ? 'lay-search=""' : "") + '><option value="">' + item.placeholder + '</option></select>\n' +
                            '                </div>\n' +
                            '            </div>';
                        break;
                    case 'icon':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item">\n' +
                            '      <label class="layui-form-label">' + item.title + '</label>\n' +
                            '           <div class="layui-input-block">\n' +
                            '               <input type="text" name="' + item.name + '" class="layui-input ' + item.name + '" lay-filter="' + item.name + '">\n' +
                            '           </div>\n' +
                            '  </div>';
                        break;
                    case 'treeCheckbox':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item">\n' +
                            '      <label class="layui-form-label">' + item.title + '</label>\n' +
                            '           <div class="layui-input-block">\n' +
                            '               <div class="' + item.name + '"></div>\n' +
                            '           </div>\n' +
                            '  </div>';

                        break;
                    case 'checkbox':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item" >\n' +
                            '    <label class="layui-form-label">' + item.title + '</label>\n' +
                            '    <div class="layui-input-block ' + item.name + '">\n' +
                            '    </div>\n' +
                            '  </div>';
                        break;
                    case 'image':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item" ><input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">\n' +
                            '    <label class="layui-form-label">' + item.title + '</label>\n' +
                            '    <div class="layui-input-block ' + item.name + '"><img src="' + (item.hasOwnProperty('viewUrl') ? item.viewUrl : '') + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '" style="margin:3px;border-radius:5px;max-width: ' + (item.hasOwnProperty('width') ? item.width : '300') + 'px;' + (values.hasOwnProperty(item.name) && values[item.name] != '' ? '' : 'display:none;') + '">\n' +
                            '    <button type="button" class="layui-btn layui-btn-primary" style="' + (values.hasOwnProperty(item.name) && values[item.name] != '' ? 'display:none;' : '') + '"><i class="layui-icon layui-icon-picture"></i>' + item.placeholder + '</button >\n' +
                            '    </div>\n' +
                            '  </div>';
                        break;
                    case 'file':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item" ><input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">\n' +
                            '    <label class="layui-form-label">' + item.title + '</label>\n' +
                            '    <div class="layui-input-block ' + item.name + '">\n' +
                            '    <button type="button" class="layui-btn layui-btn-primary"><i class="layui-icon ' + (item.hasOwnProperty('icon') ? item.icon : 'layui-icon-file-b') + '"></i><span>' + item.placeholder + '</span></button >\n' +
                            '    </div>\n' +
                            '  </div>';
                        break;
                    case 'json':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item" ><input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">\n' +
                            '    <label class="layui-form-label">' + item.title + '</label>\n' +
                            '    <div class="layui-input-block ' + item.name + '">\n' +
                            '       <div class="' + item.name + '"></div>' +
                            '    </div>\n' +
                            '  </div>';
                        break;
                    case 'date':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + '</label>\n' +
                            '            <div class="layui-input-block">\n' +
                            '                <input name="' + item.name + '" placeholder="' + item.placeholder + '" type="text" class="layui-input ' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '"/>' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'remoteSelect':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item">\n' +
                            '            <label class="layui-form-label">' + item.title + '</label>\n' +
                            '            <div class="layui-input-block">\n' +
                            '                <span class="' + item.name + '"></span>' +
                            '            </div>\n' +
                            '        </div>';
                        /*                d += '<div class="layui-input-inline" ' + width + '>\n' +
                                            '                        <span class="' + item.name + '"></span>' +
                                            '                        </div>';*/
                        break;
                }
            });
            if (values.hasOwnProperty('id')) {
                d += '<input type="hidden" name="id" value="' + values.id + '">';
            }
            d += '</form></div>';

            layer.open({
                type: 1,
                shade: 0.3,
                content: d,
                title: values.hasOwnProperty('id') && title == "添加" ? '修改' : title,
                btn: ['确认', '取消'],
                //  shadeClose: true,
                area: area,
                maxmin: true,
                yes: (index, layero) => {
                    //let serialize = decodeURIComponent($('.hex-modal').serialize());
                    var serializeArray = $('.hex-modal').serializeArray();
                    let paramsToJSONObject = {};
                    serializeArray.forEach(item => {
                        if (item.name.match(RegExp(/\[\]/))) {
                            let name = item.name.replace("[]", "");
                            if (!paramsToJSONObject.hasOwnProperty(name)) {
                                paramsToJSONObject[name] = [];
                            }
                            paramsToJSONObject[name].push(item.value);
                        } else {
                            paramsToJSONObject[item.name] = item.value.replace(/\+/g, "%2B").replace(/\&/g, "%26");
                        }

                    });

                    //let paramsToJSONObject = this.paramsToJSONObject(serialize);
                    fields.forEach(item => {
                        switch (item.type) {
                            case "treeCheckbox":
                                delete paramsToJSONObject['ids'];
                                let data = authtree.getChecked('.hex-modal .' + item.name);
                                paramsToJSONObject[item.name] = data;
                                break;
                            case "json":
                                paramsToJSONObject[item.name] = encodeURIComponent(JSON.stringify(objectContainer[item.name].get()));
                                break;
                            case "html":
                                paramsToJSONObject[item.name] = objectContainer[item.name].getValue();
                                break;
                        }
                    });
                    if (typeof url == "function") {
                        url(paramsToJSONObject);
                        return;
                    }

                    let loaderIndex = layer.load(0, {shade: ['0.3', '#fff']}); //0代表加载的风格，支持0-2
                    $.post(url, paramsToJSONObject, ret => {
                        layer.close(loaderIndex);
                        layer.msg(ret.msg);
                        if (ret.code != 200) {
                            return;
                        }
                        layer.close(index);
                        done(ret);
                    });
                },
                success: (layero, index) => {
                    fields.forEach(item => {
                        //上传url
                        let uploadUrl = item.hasOwnProperty('uploadUrl') ? item.uploadUrl : '/admin/api/upload/handle';
                        //上传的url字段名称
                        let uploadUrlName = item.hasOwnProperty('uploadUrlName') ? item.uploadUrlName : 'path';
                        switch (item.type) {
                            case "radio":
                                if (item.hasOwnProperty('dict')) {
                                    let instance = $('.hex-modal .' + item.name);
                                    this.getDict(item.dict, res => {
                                        res.data.forEach(s => {
                                            instance.append('<input name="' + item.name + '" type="radio" value="' + s.id + '" title="' + s.name + '" ' + (values.hasOwnProperty(item.name) ? (values[item.name] == s.id ? 'checked' : '') : '') + ' />');
                                        });
                                        form.render();
                                    });
                                }
                                break;
                            case "select":
                                if (item.hasOwnProperty('dict')) {
                                    let instance = $('.hex-modal .' + item.name);
                                    this.getDict(item.dict, res => {
                                        res.data.forEach(s => {
                                            instance.append(' <option value="' + s.id + '"  ' + (values.hasOwnProperty(item.name) ? (values[item.name] == s.id ? 'selected' : '') : '') + '>' + s.name + '</option>');
                                        });
                                        form.render();
                                    });
                                    if (item.hasOwnProperty('change')) {
                                        form.on('select(hex-' + item.name + ')', function (data) {
                                            item.change(data.value, data);
                                        });
                                    }
                                }
                                break;
                            case "icon":
                                layui.use(['iconPicker'], function () {
                                    var iconPicker = layui.iconPicker;
                                    //图标选择器
                                    iconPicker.render({
                                        // 选择器，推荐使用input
                                        elem: '.hex-modal .' + item.name,
                                        // 数据类型：fontClass/unicode，推荐使用fontClass
                                        type: 'fontClass',
                                        // 是否开启搜索：true/false，默认true
                                        search: true,
                                        // 是否开启分页：true/false，默认true
                                        page: true,
                                        // 每页显示数量，默认12
                                        limit: 16,
                                        // 每个图标格子的宽度：'43px'或'20%'
                                        cellWidth: 'calc(25% - 10px)',
                                        // 点击回调
                                        click: function (data) {
                                        },
                                        // 渲染成功后的回调
                                        success: function (d) {
                                        }
                                    });

                                    if (values.hasOwnProperty(item.name)) {
                                        try {
                                            iconPicker.checkIcon(item.name, values[item.name]);
                                        } catch (e) {
                                            iconPicker.checkIcon(item.name, 'layui-icon-water');
                                        }
                                    }
                                });
                                break;
                            case "treeCheckbox":
                                if (item.hasOwnProperty('dict')) {
                                    this.getDict(item.dict, res => {
                                        authtree.render('.hex-modal .' + item.name, res.data, {
                                            inputname: 'ids[]'
                                            , layfilter: 'lay-check-auth'
                                            , themePath: 'module/src/style/authtree/css/'
                                            , childKey: 'children'
                                            , valueKey: 'id'
                                            , 'theme': 'auth-skin-universal'
                                            , autowidth: true
                                            , openchecked: false
                                            , autochecked: false
                                            , checkedKey: values.hasOwnProperty(item.name) ? values[item.name] : []
                                        });
                                    });
                                }
                                break;
                            case "checkbox":
                                if (item.hasOwnProperty('dict')) {
                                    let instance = $('.hex-modal .' + item.name);
                                    let val = [];
                                    if (values.hasOwnProperty(item.name)) {
                                        val = values[item.name];
                                    }
                                    this.getDict(item.dict, res => {
                                        res.data.forEach(s => {
                                            instance.append('<input type="checkbox" ' + (val.indexOf(s.id) !== -1 ? 'checked' : '') + ' value="' + s.id + '" name="' + item.name + '[]" title="' + s.name + '">\n');
                                        });
                                        form.render();
                                    });
                                }
                                break;
                            case "switch":
                                form.on('checkbox(switch-' + item.name + ')', function (res) {
                                    let value = res.elem.checked === true ? '1' : '0'
                                    $('.hex-modal input[name=' + item.name + ']').val(value);
                                    if (item.hasOwnProperty('change')) {
                                        item.change(value, res.elem.checked);
                                    }
                                });
                                break;
                            case 'image':
                                let opts = {
                                    elem: '.hex-modal .' + item.name
                                    , url: uploadUrl
                                    , accept: 'images' //只允许上传图片
                                    , acceptMime: 'image/*' //只筛选图片
                                    , done: res => {
                                        if (res.code === 200) {
                                            let imgInstance = $('.hex-modal .' + item.name + ' img');
                                            $('.hex-modal input[name=' + item.name + ']').val(res.data[uploadUrlName]);
                                            $('.hex-modal .' + item.name + ' button').hide();
                                            imgInstance.attr('src', (item.hasOwnProperty('viewUrl') ? item.viewUrl : '') + res.data[uploadUrlName]);
                                            imgInstance.show();
                                        }
                                        layer.msg(res.msg);
                                    }
                                    , progress: function (n) {
                                        var percent = n + '%';
                                        layer.msg(percent);
                                    }
                                };
                                upload.render(opts)
                                break;
                            case 'file':
                                let buttonSpanInstance = $('.hex-modal .' + item.name + ' button span');
                                let exts = item.hasOwnProperty('exts') ? item.exts : 'jpg|png|gif|bmp|jpeg|gz|zip|rar|doc|xlsx';
                                let acceptMime = item.hasOwnProperty('acceptMime') ? item.acceptMime : '/*';
                                let opt = {
                                    elem: '.hex-modal .' + item.name
                                    , url: uploadUrl
                                    , exts: exts
                                    , acceptMime: acceptMime
                                    , done: res => {
                                        if (res.code === 200) {
                                            $('.hex-modal input[name=' + item.name + ']').val(res.data[uploadUrlName]);
                                            buttonSpanInstance.html('上传成功');
                                        }
                                        layer.msg(res.msg);
                                    }
                                    , progress: function (n) {
                                        var percent = n + '%';
                                        buttonSpanInstance.html("请稍后,已上传:" + percent);
                                    }
                                };
                                upload.render(opt)
                                break;
                            case 'json':
                                objectContainer[item.name] = new JSONEditor(document.getElementsByClassName('hex-modal')[0].getElementsByClassName(item.name)[0], {});
                                if (values.hasOwnProperty(item.name)) {
                                    if (typeof (values[item.name]) === "object") {
                                        objectContainer[item.name].set(values[item.name]);
                                    } else {
                                        if (values[item.name] != '' && values[item.name] != null) {
                                            objectContainer[item.name].set(JSON.parse(values[item.name]));
                                        }
                                    }
                                }
                                break;
                            case 'date':
                                laydate.render({
                                    elem: '.hex-modal .' + item.name,
                                    type: 'datetime'
                                });
                                break;
                            case 'remoteSelect':
                                let initValue = [];
                                if (values.hasOwnProperty(item.name)) {
                                    initValue = [values[item.name]];
                                }
                                xmSelect.render({
                                    el: '.hex-modal .' + item.name,
                                    radio: true,
                                    autoRow: true,
                                    name: item.name,
                                    data: initValue,
                                    tips: item.placeholder,
                                    searchTips: item.placeholder,
                                    //  toolbar: {show: true},
                                    filterable: true,
                                    clickClose: true,
                                    remoteSearch: true,
                                    language: 'zn',
                                    remoteMethod: (val, cb, show) => {
                                        //这里如果val为空, 则不触发搜索
                                        if (!val) {
                                            return cb([]);
                                        }
                                        this.getDict(item.dict, res => {
                                            let boxesData = [];
                                            res.data.forEach(s => {
                                                boxesData.push({name: s.name, value: s.id});
                                            });
                                            cb(boxesData);
                                        }, val);
                                    }
                                });
                                break;
                            case 'editor':
                                let editorInstance = window.wangEditor;
                                const editor = new editorInstance('.hex-modal .' + item.name);
                                const $textarea = $(".hex-modal textarea[name='" + item.name + "'")

                                editor.config.onchange = function (html) {
                                    $textarea.val(html);
                                }
                                editor.config.zIndex = 0;
                                editor.config.uploadFileName = 'file';
                                editor.config.uploadImgServer = uploadUrl;
                                editor.config.uploadImgMaxLength = 1;
                                editor.config.uploadImgHooks = {
                                    customInsert: function (insertImgFn, result) {
                                        insertImgFn(result.data.path);
                                    }
                                }
                                editor.config.uploadVideoServer = uploadUrl;
                                editor.config.uploadVideoName = 'file'
                                editor.config.uploadVideoHooks = {
                                    customInsert: function (insertVideoFn, result) {
                                        insertVideoFn(result.data.path)
                                    }
                                }

                                if (item.hasOwnProperty("height")) {
                                    editor.config.height = item.height - 120;
                                }

                                editor.create();
                                $textarea.val(editor.txt.html())

                                $('.hex-modal div[class=' + item.name + ']').find(".w-e-toolbar").css("border", "none");
                                $('.hex-modal div[class=' + item.name + ']').find(".w-e-text-container").css("border", "none");

                                $('.button-switch-' + item.name).click(function () {
                                    let type = $(this).attr("data-type");
                                    if (type == 0) {
                                        $('.hex-modal div[class=' + item.name + ']').hide();
                                        $(this).attr("data-type", 1);
                                        $(this).html('<i class="fas fa-feather" style="color: #c9b8b8;"></i> ' + "写作");

                                        //创建临时HTML编辑器
                                        $('.hex-modal div[class=' + item.name + ']').parent().append('<textarea class="textarea-temp-' + item.name + '"></textarea>');
                                        let optd = {
                                            mode: "text/html",
                                            lineNumbers: true,
                                            lineWrapping: true,
                                            extraKeys: {
                                                "Ctrl-Q": function (cm) {
                                                    cm.foldCode(cm.getCursor());
                                                }
                                            },
                                            foldGutter: true,
                                            gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"]
                                        };
                                        objectContainer[item.name] = CodeMirror.fromTextArea($('.hex-modal .textarea-temp-' + item.name).get(0), optd);
                                        objectContainer[item.name].setValue(editor.txt.html());
                                        objectContainer[item.name].on("change", function (aa) {
                                            let html = objectContainer[item.name].getValue();
                                            $textarea.val(html);
                                        });

                                        if (item.hasOwnProperty("height")) {
                                            $('.hex-modal .textarea-temp-' + item.name).siblings(".CodeMirror").css("height", item.height + "px");
                                        }
                                    } else {
                                        let html = objectContainer[item.name].getValue();
                                        editor.txt.html(html);

                                        $('.hex-modal div[class=' + item.name + ']').show();
                                        $('.hex-modal div[class=' + item.name + ']').parent().find(".CodeMirror").remove();
                                        $('.hex-modal .textarea-temp-' + item.name).remove();
                                        $(this).attr("data-type", 0);
                                        $(this).html('<i class="fas fa-code" style="color: #c9b8b8;"></i> ' + "HTML");
                                    }
                                });
                                break;
                            case 'html':
                                let optd = {
                                    mode: "text/html",
                                    lineNumbers: true,
                                    lineWrapping: true,
                                    extraKeys: {
                                        "Ctrl-Q": function (cm) {
                                            cm.foldCode(cm.getCursor());
                                        }
                                    },
                                    foldGutter: true,
                                    gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"]
                                };
                                objectContainer[item.name] = CodeMirror.fromTextArea($('.hex-modal .' + item.name).get(0), optd);

                                if (item.hasOwnProperty("height")) {
                                    $('.hex-modal .' + item.name).siblings(".CodeMirror").css("height", item.height == "100%" ? item.height : item.height + "px");
                                }

                                break;
                        }

                        let hover = null;
                        $(".hex-modal .tips-" + item.name).hover(function () {
                            hover = layer.tips(item.tips, this, {
                                tips: [1, '#f7b4d9f7'],
                                time: 0
                            });
                        }, function () {
                            layer.close(hover);
                        });
                    });
                    form.render();

                    if (success) {
                        success();
                    }

                    //添加动漫人物
                    $('.layui-layer-page').append('<img src="/assets/admin/images/menu/left.png" style="position: absolute;height: 200px;top: 0px;left: -142px;"><img src="/assets/admin/images/menu/right.png" style="position: absolute;height: 200px;top: 0px;right: -142px;">');
                }
            });
        },
        popupElement(name, type) {
            let element = null;
            switch (type) {
                case "input" :
                case "date":
                    element = $(".hex-modal input[name='" + name + "']");
                    break;
                case "textarea":
                    element = $(".hex-modal textarea[name='" + name + "']");
                    break;
                case "select":
                    element = $(".hex-modal select[name='" + name + "']");
                    break;
            }
            return element;
        }
    }

    exports('hex', hex);
});