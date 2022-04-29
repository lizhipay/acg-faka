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
                                    selectInstance.append(' <option value="' + s.id + '"  ' + (values.hasOwnProperty(item.name) ? (values[item.name] === s.id ? 'selected' : '') : '') + '>' + s.name.replace(/(<([^>]+)>)/ig, "") + '</option>');
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
        randomNum(min, max) {
            let Range = max - min;
            let Rand = Math.random();
            return (min + Math.round(Rand * Range));
        },
        popup(url, fields, done, values = {}, area = '660px', edit = false, title = "添加", success = null) {

            area = this.isPc() ? area : ["100%", "100%"];
            let unqueId = this.randomNum(10000, 99999);

            let d = ' <div class="layui-card-body"><form class="layui-form layui-form-pane hex-modal-' + unqueId + '">';
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

                let required = (item.hasOwnProperty("required") && item.required == true ? "<span class='layui-required' title='必须'>*</span>" : "");


                switch (item.type) {
                    case 'hidden':
                        d += '<input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">';
                        break;
                    case 'input':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + required + required + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
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
                            '            <label class="layui-form-label">' + item.title + required + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
                            '            <div class="layui-input-block">\n' +
                            '                <span style="position: relative;top: 6px;">' + item.placeholder + '</span>' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'custom':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + required + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
                            '            <div class="layui-input-block container-' + item.name + '"> \n' +
                            '            </div> \n' +
                            '        </div>';
                        break;
                    case 'textarea':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '        <div class="layui-form-item" style="' + ((item.hasOwnProperty("hide") && item.hide && !(values.hasOwnProperty(item.name) && values[item.name] != "")) ? 'display:none;' : '') + '">\n' +
                            '            <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '            <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '            <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '            <label class="layui-form-label">' + item.title + required + '</label>\n' +
                            '            <div class="layui-input-block ' + item.name + '">\n' +
                            '            </div>\n' +
                            '        </div>';
                        break;
                    case 'switch':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        d += '<div class="layui-form-item"><input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : 0) + '">\n' +
                            '                <label class="layui-form-label">' + item.title + required + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
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
                            '                <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '      <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '      <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '    <label class="layui-form-label">' + item.title + required + '</label>\n' +
                            '    <div class="layui-input-block ' + item.name + '">\n' +
                            '    </div>\n' +
                            '  </div>';
                        break;
                    case 'image':
                        if (edit === true && item.edit === false) {
                            break;
                        }
                        let imageStorageBtn = window.location.pathname.search("/admin/") === -1 ? '<b style="cursor:pointer;" class="images-' + item.name + '"><i class="layui-icon" style="color: green;">&#xe64a;</i></b>' : '<b style="cursor:pointer;" class="images-' + item.name + '"><i class="fas fa-images text-success"></i></b>';
                        d += '<div class="layui-form-item" ><input type="hidden" name="' + item.name + '" value="' + (values.hasOwnProperty(item.name) ? values[item.name] : '') + '">\n' +
                            '    <label class="layui-form-label">' + item.title + required + ' ' + imageStorageBtn + '</label>\n' +
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
                            '    <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '    <label class="layui-form-label">' + item.title + required + ' ' + (item.hasOwnProperty("tips") ? '<span style="cursor: pointer;" class="tips-' + item.name + '"><i class="layui-icon" style="color:#cd9898;font-size: 14px;">&#xe607;</i></span>' : '') + '</label>\n' +
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
                            '            <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                            '            <label class="layui-form-label">' + item.title + required + '</label>\n' +
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
                skin: "layui-popup",
                title: values.hasOwnProperty('id') && title == "添加" ? '修改' : title,
                btn: ['确认', '取消'],
                //  shadeClose: true,
                area: area,
                maxmin: true,
                yes: (index, layero) => {
                    //let serialize = decodeURIComponent($('.hex-modal').serialize());
                    var serializeArray = $('.hex-modal-' + unqueId).serializeArray();
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
                                let data = authtree.getChecked('.hex-modal-' + unqueId + ' .' + item.name);
                                paramsToJSONObject[item.name] = data;
                                break;
                            case "json":
                                paramsToJSONObject[item.name] = encodeURIComponent(JSON.stringify(objectContainer[item.name].get()));
                                break;
                            case "html":
                                paramsToJSONObject[item.name] = objectContainer[item.name].getValue();
                                break;
                            case "custom":
                                let json = [];
                                paramsToJSONObject["name-" + item.name].forEach((name, index) => {
                                    if (name != "") {
                                        json.push({
                                            cn: paramsToJSONObject["cn-" + item.name][index],
                                            name: name,
                                            placeholder: paramsToJSONObject["placeholder-" + item.name][index],
                                            type: paramsToJSONObject["type-" + item.name][index],
                                            regex: paramsToJSONObject["regex-" + item.name][index],
                                            error: paramsToJSONObject["error-" + item.name][index],
                                            dict: paramsToJSONObject["dict-" + item.name][index]
                                        });
                                    }
                                });
                                delete paramsToJSONObject["cn-" + item.name];
                                delete paramsToJSONObject["placeholder-" + item.name];
                                delete paramsToJSONObject["type-" + item.name];
                                delete paramsToJSONObject["regex-" + item.name];
                                delete paramsToJSONObject["error-" + item.name];
                                delete paramsToJSONObject["name-" + item.name];
                                delete paramsToJSONObject["dict-" + item.name];
                                paramsToJSONObject[item.name] = encodeURIComponent(JSON.stringify(json));
                                break;
                        }
                    });
                    if (typeof url == "function") {
                        url(paramsToJSONObject, index, unqueId);
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
                                    let instance = $('.hex-modal-' + unqueId + ' .' + item.name);
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
                                    let instance = $('.hex-modal-' + unqueId + ' .' + item.name);
                                    this.getDict(item.dict, res => {
                                        res.data.forEach(s => {
                                            instance.append(' <option value="' + s.id + '"  ' + (values.hasOwnProperty(item.name) ? (values[item.name] == s.id ? 'selected' : '') : '') + '>' + s.name.replace(/(<([^>]+)>)/ig, "") + '</option>');
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
                                        elem: '.hex-modal-' + unqueId + ' .' + item.name,
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
                                        authtree.render('.hex-modal-' + unqueId + ' .' + item.name, res.data, {
                                            inputname: 'ids[]'
                                            , layfilter: 'lay-check-auth'
                                            , themePath: 'module/src/style/authtree/css/'
                                            , childKey: 'children'
                                            , valueKey: 'id'
                                            , 'theme': 'auth-skin-universal'
                                            , autowidth: true
                                            , openchecked: false
                                            , autochecked: true
                                            , checkedKey: values.hasOwnProperty(item.name) ? values[item.name] : []
                                        });
                                    });
                                }
                                break;
                            case "checkbox":
                                if (item.hasOwnProperty('dict')) {
                                    let instance = $('.hex-modal-' + unqueId + ' .' + item.name);
                                    let val = [];
                                    if (values.hasOwnProperty(item.name)) {
                                        val = values[item.name];
                                    }
                                    this.getDict(item.dict, res => {
                                        res.data.forEach(s => {
                                            instance.append('<input type="checkbox" ' + (val.indexOf(s.id) !== -1 || val.indexOf(s.id.toString()) !== -1 ? 'checked' : '') + ' value="' + s.id + '" name="' + item.name + '[]" title="' + s.name + '">\n');
                                        });
                                        form.render();
                                    });
                                }
                                break;
                            case "switch":
                                form.on('checkbox(switch-' + item.name + ')', function (res) {
                                    let value = res.elem.checked === true ? '1' : '0'
                                    $('.hex-modal-' + unqueId + ' input[name=' + item.name + ']').val(value);
                                    if (item.hasOwnProperty('change')) {
                                        item.change(value, res.elem.checked);
                                    }
                                });
                                break;
                            case 'image':
                                let opts = {
                                    elem: '.hex-modal-' + unqueId + ' .' + item.name
                                    , url: uploadUrl
                                    , accept: 'images' //只允许上传图片
                                    , acceptMime: 'image/*' //只筛选图片
                                    , done: res => {
                                        if (res.code === 200) {
                                            let imgInstance = $('.hex-modal-' + unqueId + ' .' + item.name + ' img');
                                            $('.hex-modal-' + unqueId + ' input[name=' + item.name + ']').val(res.data[uploadUrlName]);
                                            $('.hex-modal-' + unqueId + ' .' + item.name + ' button').hide();
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


                                let imageStorageUrl = window.location.pathname.search("/admin/") === -1 ? '/user/api/upload/images' : '/admin/api/upload/images';
                                let imageStoragePath = window.location.pathname.search("/admin/") === -1 ? '' : '/assets/cache/images/';
                                $('.hex-modal-' + unqueId + ' .images-' + item.name).click(() => {
                                    layer.open({
                                        offset: 'r',
                                        type: 1,
                                        skin: 'layui-popup',
                                        area: this.isPc() ? ['420px', '720px'] : ["100%", "100%"],
                                        content: '<div style="margin: 10px;padding:0 10px 10px 10px;border-radius:20px;background: #fff;"><table class="image-storage-' + unqueId + item.name + '"></table></div>',
                                        title: "图库",
                                        success: (lay, layIndex) => {
                                            let storageTable = $('.image-storage-' + unqueId + item.name);
                                            storageTable.bootstrapTable({
                                                url: imageStorageUrl,//请求的url地址
                                                method: "post",//请求方式
                                                pageSize: 10,
                                                showRefresh: false,//是否显示刷新按钮
                                                cache: false,//是否使用缓存
                                                showToggle: false,//是否显示详细视图和列表视图的切换按钮
                                                cardView: false,
                                                pagination: true,//是否显示分页
                                                pageNumber: 1,//初始化显示第几页，默认第1页
                                                singleSelect: false,//复选框只能选择一条记录
                                                sidePagination: 'server',//分页显示方式，可以选择客户端和服务端（server|client）
                                                contentType: "application/x-www-form-urlencoded",//使用post请求时必须加上
                                                dataType: "json",//接收的数据类型
                                                queryParamsType: 'limit',//参数格式，发送标准的Restful类型的请求
                                                queryParams: function (params) {
                                                    params.page = (params.offset / params.limit) + 1;
                                                    return params;
                                                },
                                                //回调函数
                                                responseHandler: function (res) {
                                                    return {
                                                        "total": res.count,
                                                        "rows": res.data
                                                    }
                                                },
                                                columns: [
                                                    {
                                                        field: 'image',
                                                        title: '',
                                                        formatter: function (val, img) {
                                                            return '<img class="preview" src="' + imageStoragePath + img + '" alt="预览图" style="height: 100px;max-width:100px;cursor: pointer;border-radius: 10px;">';
                                                        },
                                                        events: {
                                                            'click .preview': function (event, value, imageUrl, index) {
                                                                imageUrl = imageStoragePath + imageUrl;
                                                                let img = new Image()
                                                                img.src = imageUrl;
                                                                img.onload = function () {
                                                                    if (img.width >= window.innerWidth) {
                                                                        img.width = window.innerWidth * 0.9;
                                                                    }
                                                                    if (img.height >= window.innerHeight) {
                                                                        img.height = window.innerHeight * 0.9;
                                                                    }
                                                                    layer.open({
                                                                        type: 1,
                                                                        title: false,
                                                                        closeBtn: 0, //不显示关闭按钮
                                                                        anim: 5,
                                                                        area: [img.width + "px", img.height + "px"],
                                                                        shadeClose: true, //开启遮罩关闭
                                                                        content: '<img  src="' + imageUrl + '" style="border-radius: 20px;width:' + img.width + 'px;height:' + img.height + 'px" alt="图片预览">'
                                                                    });
                                                                }
                                                            }
                                                        }
                                                    },
                                                    {
                                                        field: 'operate',
                                                        title: '',
                                                        formatter: function (val, img) {
                                                            let html = '<a  class="badge badge-light text-success set-image" style="cursor: pointer;"><i class="fa fa-cog text-success"></i> 使用此图像</a>';
                                                            html += ' <a style="cursor: pointer;"  class="badge badge-light text-primary copy-link"><i class="far fa-clipboard text-primary"></i> 复制外链</a>';
                                                            return html;
                                                        },
                                                        events: {
                                                            'click .copy-link': function (event, value, img, index) {
                                                                let clipboard = new ClipboardJS('.copy-link', {
                                                                    text: function () {
                                                                        return window.location.origin + imageStoragePath + img;
                                                                    }
                                                                });
                                                                clipboard.on('success', function (e) {
                                                                    layer.msg("复制成功QAQ~");
                                                                });
                                                            },
                                                            'click .set-image': function (event, value, img, index) {
                                                                img = imageStoragePath + img;
                                                                let imgInstance = $('.hex-modal-' + unqueId + ' .' + item.name + ' img');
                                                                $('.hex-modal-' + unqueId + ' input[name=' + item.name + ']').val(img);
                                                                $('.hex-modal-' + unqueId + ' .' + item.name + ' button').hide();
                                                                imgInstance.attr('src', img);
                                                                imgInstance.show();
                                                                layer.close(layIndex);
                                                            }
                                                        }
                                                    }
                                                ]
                                            });
                                        }
                                    });
                                });

                                upload.render(opts)
                                break;
                            case 'file':
                                let buttonSpanInstance = $('.hex-modal-' + unqueId + ' .' + item.name + ' button span');
                                let exts = item.hasOwnProperty('exts') ? item.exts : 'jpg|png|gif|bmp|jpeg|gz|zip|rar|doc|xlsx';
                                let acceptMime = item.hasOwnProperty('acceptMime') ? item.acceptMime : '/*';
                                let opt = {
                                    elem: '.hex-modal-' + unqueId + ' .' + item.name
                                    , url: uploadUrl
                                    , exts: exts
                                    , acceptMime: acceptMime
                                    , done: res => {
                                        if (res.code === 200) {
                                            $('.hex-modal-' + unqueId + ' input[name=' + item.name + ']').val(res.data[uploadUrlName]);
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
                                objectContainer[item.name] = new JSONEditor(document.getElementsByClassName('hex-modal-' + unqueId)[0].getElementsByClassName(item.name)[0], {});
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
                                    elem: '.hex-modal-' + unqueId + ' .' + item.name,
                                    type: 'datetime'
                                });
                                break;
                            case 'remoteSelect':
                                let initValue = [];
                                if (values.hasOwnProperty(item.name)) {
                                    initValue = [values[item.name]];
                                }
                                xmSelect.render({
                                    el: '.hex-modal-' + unqueId + ' .' + item.name,
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
                                const editor = new editorInstance('.hex-modal-' + unqueId + ' .' + item.name);
                                const $textarea = $(".hex-modal-" + unqueId + " textarea[name='" + item.name + "'")

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

                                $('.hex-modal-' + unqueId + ' div[class=' + item.name + ']').find(".w-e-toolbar").css("border", "none");
                                $('.hex-modal-' + unqueId + ' div[class=' + item.name + ']').find(".w-e-text-container").css("border", "none");

                                $('.button-switch-' + item.name).click(function () {
                                    let type = $(this).attr("data-type");
                                    if (type == 0) {
                                        $('.hex-modal-' + unqueId + ' div[class=' + item.name + ']').hide();
                                        $(this).attr("data-type", 1);
                                        $(this).html('<i class="fas fa-feather" style="color: #c9b8b8;"></i> ' + "写作");

                                        //创建临时HTML编辑器
                                        $('.hex-modal-' + unqueId + ' div[class=' + item.name + ']').parent().append('<textarea class="textarea-temp-' + item.name + '"></textarea>');
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
                                        objectContainer[item.name] = CodeMirror.fromTextArea($('.hex-modal-' + unqueId + ' .textarea-temp-' + item.name).get(0), optd);
                                        objectContainer[item.name].setValue(editor.txt.html());
                                        objectContainer[item.name].on("change", function (aa) {
                                            let html = objectContainer[item.name].getValue();
                                            $textarea.val(html);
                                        });

                                        if (item.hasOwnProperty("height")) {
                                            $('.hex-modal-' + unqueId + ' .textarea-temp-' + item.name).siblings(".CodeMirror").css("height", item.height + "px");
                                        }
                                    } else {
                                        let html = objectContainer[item.name].getValue();
                                        editor.txt.html(html);

                                        $('.hex-modal-' + unqueId + ' div[class=' + item.name + ']').show();
                                        $('.hex-modal-' + unqueId + ' div[class=' + item.name + ']').parent().find(".CodeMirror").remove();
                                        $('.hex-modal-' + unqueId + ' .textarea-temp-' + item.name).remove();
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
                                objectContainer[item.name] = CodeMirror.fromTextArea($('.hex-modal-' + unqueId + ' .' + item.name).get(0), optd);

                                if (item.hasOwnProperty("height")) {
                                    $('.hex-modal-' + unqueId + ' .' + item.name).siblings(".CodeMirror").css("height", item.height == "100%" ? item.height : item.height + "px");
                                }

                                break;
                            case 'custom':
                                let preset = [];

                                if (values.hasOwnProperty(item.name) && values[item.name].length > 0) {
                                    preset = JSON.parse(values[item.name]);
                                }

                                let instance = $('.container-' + item.name);
                                let viewNum = 0;
                                let bindButton = function (id) {
                                    viewNum++;
                                    $('.view-' + id).show(150);
                                    $('.append-' + id).click(function () {
                                        let res = render();
                                        $(this).parent().parent().after(res.html);
                                        form.render();
                                        bindButton(res.id);
                                    });
                                    $('.del-' + id).click(function () {
                                        if (viewNum <= 1) {
                                            layer.msg("(⁎˃ᆺ˂)最后一个不能移除哦，但是已经帮你清空了");
                                            let res = render();
                                            instance.html(res.html);
                                            form.render();
                                            bindButton(res.id);
                                            viewNum--;
                                            return;
                                        }

                                        let dom = $(this).parent().parent();
                                        dom.fadeOut('fast', function () {
                                            dom.remove();
                                            viewNum--;
                                        });
                                    });
                                }

                                let render = function (opt = {}) {
                                    let id = Math.random().toString(36).slice(-10);
                                    let html = '' +
                                        '<div style="border-bottom: 1px dashed #ff7c7c3b;margin-bottom: 5px;padding-bottom: 5px;display: none;" class="view-' + id + '"><input type="text"  name="cn-' + item.name + '[]" placeholder="中文名(CN)" class="layui-input" value="' + (opt.hasOwnProperty("cn") ? opt.cn : "") + '"  style="width:100px;display: inline-block;"> ' +
                                        '<input value="' + (opt.hasOwnProperty("name") ? opt.name : "") + '" name="name-' + item.name + '[]" type="text" placeholder="键名(NAME)" class="layui-input" style="width: 100px;display: inline-block;"> ' +
                                        '<input value="' + (opt.hasOwnProperty("placeholder") ? opt.placeholder : "") + '" name="placeholder-' + item.name + '[]" type="text" placeholder="提示内容" class="layui-input" style="width: 120px;display: inline-block;"> ' +
                                        '<input value="' + (opt.hasOwnProperty("regex") ? opt.regex : "") + '"  name="regex-' + item.name + '[]" type="text" placeholder="正则(regex)" class="layui-input" style="width: 120px;display: inline-block;"> ' +
                                        '<input value="' + (opt.hasOwnProperty("error") ? opt.error : "") + '" name="error-' + item.name + '[]" type="text" placeholder="匹配错误提示" class="layui-input" style="width: 120px;display: inline-block;"> ' +
                                        '<div style="width: 140px;display: inline-block;"><select name="type-' + item.name + '[]">' +
                                        '<option ' + (opt.hasOwnProperty("type") && opt.type == "text" ? "selected" : "") + '  value="text">文本框(text)</option>' +
                                        '<option ' + (opt.hasOwnProperty("type") && opt.type == "password" ? "selected" : "") + ' value="password">密码框(password)</option>' +
                                        '<option ' + (opt.hasOwnProperty("type") && opt.type == "number" ? "selected" : "") + ' value="number">数字框(number)</option>' +
                                        '<option ' + (opt.hasOwnProperty("type") && opt.type == "select" ? "selected" : "") + ' value="select">下拉框(select)</option>' +
                                        '<option ' + (opt.hasOwnProperty("type") && opt.type == "checkbox" ? "selected" : "") + ' value="checkbox">多选框(checkbox)</option>' +
                                        '<option ' + (opt.hasOwnProperty("type") && opt.type == "radio" ? "selected" : "") + ' value="radio">单选框(radio)</option>' +
                                        '<option ' + (opt.hasOwnProperty("type") && opt.type == "textarea" ? "selected" : "") + ' value="textarea">文本域(textarea)</option>' +
                                        '</select></div> ' +
                                        '<input value="' + (opt.hasOwnProperty("dict") ? opt.dict : "") + '" name="dict-' + item.name + '[]" type="text" placeholder="扩充数据" class="layui-input" style="width: 180px;display: inline-block;"> ' +
                                        '<div style="display: inline-block;margin-left: 2px;"><i class="layui-icon append-' + id + '" style="color: #23a148;cursor: pointer;font-size: 16px;font-weight: bold;">&#xe61f;</i> <i class="layui-icon del-' + id + '" style="color: #eb8181;cursor: pointer;font-size: 16px;font-weight: bold;">&#x1006;</i></div></div>';
                                    return {id: id, html: html};
                                }

                                if (preset.length > 0) {
                                    preset.forEach(item => {
                                        let res = render(item);
                                        instance.append(res.html);
                                        bindButton(res.id);
                                    })
                                } else {
                                    let res = render();
                                    instance.html(res.html);
                                    bindButton(res.id);
                                }
                                break;
                        }

                        let hover = null;
                        $(".hex-modal-" + unqueId + " .tips-" + item.name).hover(function () {
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
                        success(unqueId);
                    }
                    //添加动漫人物
                    $('.layui-layer-page').append('<img src="/assets/admin/images/menu/left.png" style="position: absolute;height: 200px;top: 0px;left: -142px;"><img src="/assets/admin/images/menu/right.png" style="position: absolute;height: 200px;top: 0px;right: -142px;">');

                    //处理PC
                    typeof area == "string" && hex.popupAutoWindow();
                }
            });
        },
        popupElement(name, type, uniqueId = 0) {
            let element = null;
            switch (type) {
                case "input" :
                case "date":
                    element = $(".hex-modal-" + uniqueId + " input[name='" + name + "']");
                    break;
                case "textarea":
                    element = $(".hex-modal-" + uniqueId + " textarea[name='" + name + "']");
                    break;
                case "select":
                    element = $(".hex-modal-" + uniqueId + " select[name='" + name + "']");
                    break;
                case "checkbox":
                    element = $(".hex-modal-" + uniqueId + " input[name='" + name + "']");
                    break;
            }
            return element;
        },
        setColumnVisible(table, field, checked) {
            localStorage.setItem(table + "_columnVisible_" + field, checked);
        },
        getColumnVisible(table, field, checked = true) {
            let f = table + "_columnVisible_" + field;
            if (!localStorage.hasOwnProperty(f)) {
                return checked;
            }
            return localStorage.getItem(f) == "true";
        },
        popupAutoHeight() {
            let height = 768;
            let pageHeight = $('.layui-popup').height();
            let top = ($(window).height() - height) / 2;
            if (pageHeight > height) {
                $('.layui-popup').css("top", top + "px");
                $('.layui-popup .layui-layer-content').css("height", (height - 55) + "px");
            } else {
                let top2 = ($(window).height() - pageHeight) / 2;
                $('.layui-popup').css("top", top2 + "px");
            }
        },
        popupAutoWindow() {
            if (hex.isPc()) {
                hex.popupAutoHeight();
                $('.layui-popup').bind('DOMNodeInserted', function (e) {
                    hex.popupAutoHeight();
                });
                $('.layui-popup img').each(function () {
                    this.onload = function () {
                        hex.popupAutoHeight();
                    }
                });
            }
        },
        $get(url, done, error = null) {
            let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
            $.get(url, res => {
                layer.close(loaderIndex);
                if (res.code !== 200) {
                    layer.msg(res.msg);
                    typeof error === 'function' && error(res.data);
                    return;
                }
                typeof done === 'function' && done(res.data);
            });
        },
        $post(url, data, done, error = null) {
            let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
            $.post(url, data, res => {
                layer.close(loaderIndex);
                if (res.code !== 200) {
                    layer.msg(res.msg);
                    typeof error === 'function' && error(res.data);
                    return;
                }
                typeof done === 'function' && done(res.data);
            });
        }
    }

    exports('hex', hex);
});