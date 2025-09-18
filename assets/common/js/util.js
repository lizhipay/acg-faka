const util = new class Util {
    icon(icon) {
        return `<i class="${icon}"></i>`;
    }

    replaceDotWithHyphen(str) {
        return str.replace(/\./g, '-');
    }

    parseStringObject(obj, str) {
        try {
            // 去除字符串两端的空格
            str = str.trim();
            // 拆分字符串为属性路径数组
            let props = str.split('-');
            // 逐级访问属性
            let value = obj;
            for (let i = 0; i < props.length; i++) {
                let prop = props[i];
                value = value[prop];
            }
            return value;
        } catch (error) {
            return undefined;
        }
    }

    isEmptyOrNotJson(val) {
        if (val === null || val === undefined) return true;

        if (typeof val === 'object') {
            if (Array.isArray(val) || Object.prototype.toString.call(val) === '[object Object]') {
                return Object.keys(val).length === 0;
            }
        }

        // 不是对象类型，统统算 true
        return true;
    }

    checkPropertyExistence(obj, str) {
        try {
            str = str.trim();
            let props = str.split('-');
            let value = obj;
            for (let i = 0; i < props.length; i++) {
                let prop = props[i];
                if (value.hasOwnProperty(prop)) {
                    value = value[prop];
                } else {
                    return false;
                }
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    parseNestedKeysFromJSON(jsonData) {
        const parsedObject = {};

        for (const key in jsonData) {
            if (jsonData.hasOwnProperty(key)) {
                const nestedKeys = key.split('-');

                let currentObj = parsedObject;
                for (let i = 0; i < nestedKeys.length; i++) {
                    const nestedKey = nestedKeys[i];

                    if (i === nestedKeys.length - 1) {
                        currentObj[nestedKey] = jsonData[key];
                    } else {
                        currentObj[nestedKey] = currentObj[nestedKey] || {};
                        currentObj = currentObj[nestedKey];
                    }
                }
            }
        }

        return parsedObject;
    }


    getCookie(name) {
        return document.cookie.match(`[;\s+]?${name}=([^;]*)`)?.pop();
    }

    getParam(variable) {
        let query = window.location.search.substring(1);
        let vars = query.split("&");
        for (let i = 0; i < vars.length; i++) {
            let pair = vars[i].split("=");
            if (pair[0] === variable) {
                return pair[1];
            }
        }
        return null;
    }

    /**
     * POST
     * @param url
     * @param data
     * @param done
     * @param error
     * @param fail
     */
    post(url, data, done, error = null, fail = null) {
        let loader = {
            enable: true,
            autoClose: true
        };
        if (typeof url == "object") {
            data = url.hasOwnProperty("data") ? url.data : {};
            done = url.hasOwnProperty("done") ? url.done : null;
            error = url.hasOwnProperty("error") ? url.error : null;
            fail = url.hasOwnProperty("fail") ? url.fail : null;
            loader = url.hasOwnProperty("loader") ? (url.loader !== false ? Object.assign({}, loader, url.loader) : {
                enable: false,
                autoClose: false
            }) : loader;
            url = url.hasOwnProperty("url") ? url.url : {};
        } else if (typeof data === "function") {
            done = data;
        }

        loader.enable ? Loading.show() : 0;
        util.debug("POST(↑):" + url, "#ff4f33", data);
        $.ajax({
            type: 'post',
            url: url,
            data: data,
            success: (res, status, xhr) => {
                Loading.hide();
                try {
                    util.debug("POST(↓):" + url, "#0bbf4a", res);
                    if (res.code !== 200) {
                        if (typeof error === 'function') {
                            error(res);
                        } else if (error !== false) {
                            message.error(res.msg);
                        }
                        return;
                    }
                    typeof done === 'function' && done(res);
                } catch (e) {
                    console.log(e)
                    if (typeof error === 'function') {
                        error(res);
                    } else if (error !== false) {
                        util.stdout(`POST(致命异常): ${url} | 请将下面信息截图反馈给维护人员:\n`, "red", res);
                        message.error("服务器数据返回错误，可通过F12查看浏览器错误并且反馈给维护人员");
                    }
                }
            },
            error: (xhr, status, error) => {
                Loading.hide();
                typeof fail === 'function' && fail(xhr, status, error);
            }
        });
    }

    /**
     * GET
     * @param url
     * @param done
     * @param error
     */
    get(url, done = null, error = null) {
        Loading.show();
        util.debug("GET(↑):" + url, "#ff4f33");
        $.get({
            url: url,
            success: res => {
                util.debug("GET(↓):" + url, "#0bbf4a", res);
                Loading.hide();
                if (res.code !== 200) {
                    if (typeof error === 'function') {
                        typeof error === 'function' && error(res);
                    } else {
                        message.error(res.msg);
                    }
                    return;
                }
                typeof done === 'function' && done(res.data);
            }
        });
    }

    /**
     *
     * @param data
     * @param secret
     * @returns {string}
     */
    encrypt(data, secret) {
        let key = CryptoJS.enc.Utf8.parse(secret);
        let secretData = CryptoJS.enc.Utf8.parse(data);
        let encrypted = CryptoJS.AES.encrypt(secretData, key, {
            iv: CryptoJS.enc.Utf8.parse(secret), mode: CryptoJS.mode.CBC
        });
        return encrypted.toString();
    }

    /**
     *
     * @param data
     * @param secret
     * @returns {string}
     */
    decrypt(data, secret) {
        let key = CryptoJS.enc.Utf8.parse(secret);
        let decrypt = CryptoJS.AES.decrypt(data, key, {
            iv: CryptoJS.enc.Utf8.parse(secret), mode: CryptoJS.mode.CBC
        });
        return CryptoJS.enc.Utf8.stringify(decrypt).toString();
    }

    /**
     * @param serializeArray
     * @returns {{}}
     */
    arrayToObject(serializeArray) {
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
        return paramsToJSONObject;
    }

    /**
     *
     * @param url
     * @returns {{}}
     */
    paramsToJSONObject(url) {
        let hash;
        let json = {};
        let hashes = url.slice(url.indexOf('?') + 1).split('&');
        for (let i = 0; i < hashes.length; i++) {
            hash = hashes[i].split('=');
            if (hash[0].indexOf("[]") !== -1) {
                if (!json.hasOwnProperty(hash[0])) {
                    json[hash[0]] = [];
                }
                json[hash[0]].push(hash[1]);
            } else {
                json[hash[0]] = hash[1];
            }

        }
        return json;
    }

    /**
     * @param length
     * @returns {string}
     */
    generateRandStr(length = 32) {
        let _charStr = 'abacdefghjklmnopqrstuvwxyzABCDEFGHJKLMNOPQRSTUVWXYZ0123456789',
            min = 0,
            max = _charStr.length - 1,
            _str = '';
        for (let i = 0, index; i < length; i++) {
            index = (function (randomIndexFunc, i) {
                return randomIndexFunc(min, max, i, randomIndexFunc);
            })(function (min, max, i, _self) {
                let indexTemp = Math.floor(Math.random() * (max - min + 1) + min),
                    numStart = _charStr.length - 10;
                if (i === 0 && indexTemp >= numStart) {
                    indexTemp = _self(min, max, i, _self);
                }
                return indexTemp;
            }, i);
            _str += _charStr[index];
        }
        return _str;
    }

    /**
     * @param obj
     * @returns {{}}
     */
    ksort(obj) {
        let sortObj = {}, keys = Object.keys(obj);
        keys.sort();
        keys.forEach((key) => {
            sortObj[key] = obj[key];
        });
        return sortObj;
    }

    /**
     * @param data
     * @param secret
     * @returns {*}
     */
    generateSignature(data, secret) {
        delete data.sign;
        data = this.ksort(data);
        let url = "";
        for (const key in data) {
            if (data[key] !== "" && data[key] !== undefined && typeof data[key] != "object" && !Number.isNaN(data[key])) {

                url += key + "=" + data[key] + "&";
            }
        }
        url = url.slice(0, -1);

        return CryptoJS.MD5(url + "&key=" + secret).toString();
    }

    md5(text) {
        return CryptoJS.MD5(text).toString();
    }

    isPc() {
        let userAgentInfo = navigator.userAgent;
        let Agents = ["Android", "iPhone", "SymbianOS", "Windows Phone", "iPad", "iPod"];
        let flag = true;
        for (let v = 0; v < Agents.length; v++) {
            if (userAgentInfo.indexOf(Agents[v]) > 0) {
                flag = false;
                break;
            }
        }
        return flag;
    }

    isIphone() {
        let ua = navigator.userAgent;
        let ipad = ua.match(/(iPad).*OS\s([\d_]+)/i), ipod = ua.match(/(iPod).*OS\s([\d_]+)/i);
        let result = !ipod && !ipad && ua.match(/(iPhone\sOS)\s([\d_]+)/i);
        return Boolean(result);
    }

    isIpad() {
        let ua = navigator.userAgent;
        let ipad = ua.match(/(iPad).*OS\s([\d_]+)/i);
        return Boolean(ipad);
    }

    isAndroid() {
        let ua = navigator.userAgent;
        let android = ua.match(/(Android)\s+([\d.]+)/i);
        return Boolean(android);
    }

    isMobile() {
        return this.isAndroid() || this.isIphone();
    }

    isAlipay() {
        let ua = navigator.userAgent;
        let alipay = ua.match(/(AlipayClient)/i);
        return Boolean(alipay);
    }

    isWx() {
        let ua = navigator.userAgent;
        let wx = ua.match(/(MicroMessenger)/i);
        return Boolean(wx);
    }

    getDate() {
        let date = new Date();
        return date.getFullYear() + "-" + date.getMonth() + "-" + date.getDay() + " " + date.getHours() + ":" + date.getMinutes() + ":" + date.getSeconds();
    }

    debug(message, color, ...val) {
        if (!(typeof getVar("DEBUG") == "boolean" && getVar("DEBUG") == true)) {
            return;
        }
        this.stdout(message, color, ...val);
    }


    stdout(message, color, ...val) {
        const date = new Date();
        const d = date.getHours() + ":" + date.getMinutes() + ":" + date.getSeconds();
        console.log('%c[' + d + ']-> %c' + message, 'color: #519dfb;font-weight: bold;', 'color: ' + color + '; font-weight: bold;', ...val);
    }

    isObjectEmpty(obj) {
        return Object.keys(obj).length === 0;
    }

    countDown(element, second, successMessage = "重新发送", message = "{$second}秒后重试") {
        let instance = $(element), interval;
        instance.html(message.replace("{$second}", second));
        instance.attr("disabled", true);
        interval = setInterval(() => {
            second--;
            instance.html(message.replace("{$second}", second));
            if (second <= 0) {
                instance.html(successMessage);
                instance.attr("disabled", false);
                clearInterval(interval);
            }
        }, 1000);
    }

    bytesToSize(bytes) {
        let sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        if (bytes === 0) return '0 Byte';
        let i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
        return Math.round(bytes / Math.pow(1024, i), 2) + sizes[i];
    }

    formatTime(milliseconds) {
        let seconds = Math.floor(milliseconds / 1000);
        let minutes = Math.floor(seconds / 60);
        let hours = Math.floor(minutes / 60);

        let formattedTime = '';
        if (hours > 0) {
            formattedTime += hours + '小时';
        }
        if (minutes > 0) {
            formattedTime += (minutes % 60) + '分钟';
        }
        if (seconds >= 0) {
            formattedTime += (seconds % 60) + '秒';
        }

        return formattedTime.trim();
    }


    getAbstractTimeout(timeout) {
        let timestamp = new Date(timeout).getTime() / 1000;
        let now_timestamp = parseInt(new Date().getTime() / 1000);
        let expire = parseInt(timestamp) - now_timestamp;
        let day = Math.floor(expire / (24 * 3600)); // Math.floor()向下取整
        let hour = Math.floor((expire - day * 24 * 3600) / 3600);
        let minute = Math.floor((expire - day * 24 * 3600 - hour * 3600) / 60);
        let second = expire - day * 24 * 3600 - hour * 3600 - minute * 60;
        return {
            expire: expire,
            day: day,
            hour: hour,
            minute: minute,
            second: second
        }
    }

    getUploadProgress(fileSize, startTime, percent) {
        let currentTime = new Date().getTime();
        let milliseconds = (currentTime - startTime); // 已经过的时间（单位：秒）
        let uploadedBytes = percent * fileSize; // 已上传的字节数
        let uploadedSize = this.bytesToSize(uploadedBytes); // 已上传的文件大小（格式化后）
        let speedBytes = uploadedBytes / (milliseconds / 1000); // 上传速度（字节/秒）

        return {
            speed: this.bytesToSize(speedBytes) + '/s',
            speedBytes: speedBytes,
            size: uploadedSize + "/" + this.bytesToSize(fileSize),
            bytes: uploadedBytes,
            milliseconds: milliseconds,
            time: this.formatTime(milliseconds)
        };
    }

    updateProgress(element, percentage) {
        element.attr('data-percentage', percentage);
        element.css('background', `linear-gradient(to right,  #68ff73 ${percentage}%, #f3f3f3 ${percentage}%)`);
    }

    objectToQueryString(obj) {
        return Object.keys(obj)
            .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(obj[key]))
            .join('&');
    }

    require(scripts, callback, path = "/assets/common/js/component/") {
        if (typeof scripts === 'string') {
            scripts = [scripts];
        }
        let totalScripts = scripts.length;
        let loadedScriptsCount = 0;


        for (let i = 0; i < totalScripts; i++) {
            scripts[i] = path + scripts[i] + '.js';
        }


        scripts.forEach((scriptPath) => {
            let script = document.createElement('script');
            script.src = scriptPath;
            script.onload = () => {
                loadedScriptsCount++;
                if (loadedScriptsCount === totalScripts) {
                    callback();
                }
            };
            script.onerror = (err) => {
                console.error(`Script load error: ${err}`);
            };
            document.body.appendChild(script);
        });
    }

    loadSound(url, done = null) {
        const req = new XMLHttpRequest();
        req.open('GET', url, true);
        req.responseType = 'arraybuffer';
        req.onload = function () {
            let ac = new AudioContext();
            ac.decodeAudioData(req.response, function (buffer) {
                const source = ac.createBufferSource();
                source.buffer = buffer;
                source.connect(ac.destination);
                source.start(0);
                typeof done == "function" && done();
            }, function (e) {
                console.info('错误');
            });
        }
        req.send();
    }

    appendParamToUrl(url, paramString) {
        if (url.includes('?')) {
            return url + '&' + paramString;
        } else {
            return url + '?' + paramString;
        }
    }

    plainText(text) {
        if (typeof text !== 'string') {
            return text;
        }
        // 去除HTML标签
        const noHtml = text.replace(/<[^>]*>/g, '');
        // 去除前后空格和换行
        return noHtml.trim();
    }

    setCookie(key, value) {
        let expires = new Date();
        expires.setTime(expires.getTime() + (20 * 365 * 24 * 60 * 60 * 1000)); //20年
        document.cookie = `${encodeURIComponent(key)}=${encodeURIComponent(value)};expires=${expires.toUTCString()};path=/`;
    }

    deleteNodeById(data, id) {
        if (!id) {
            return data;
        }
        for (let i = 0; i < data.length; i++) {
            const node = data[i];
            // 检查当前节点是否是要删除的节点
            if (node.id === id) {
                data.splice(i, 1); // 从数组中移除该节点
                return data; // 返回更新后的数据
            }
            // 如果当前节点有子节点，递归搜索子节点
            if (node.children && node.children.length > 0) {
                node.children = this.deleteNodeById(node.children, id);
            }
        }
        return data; // 返回更新后的数据，无论是否进行了删除
    }

    copyTextToClipboard(text, success = null, error = null) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                typeof success === "function" && success();
            }).catch(function (err) {
                typeof error === "function" && error();
            });
        } else {
            var $temp = $("<textarea>");
            $("body").append($temp);
            $temp.val(text).select();
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    typeof success === "function" && success();
                } else {
                    typeof error === "function" && error();
                }
            } catch (err) {
                typeof error === "function" && error();
            }
            $temp.remove();
        }
    }

    /**
     * 动态加载一个或多个 JS 脚本，并在所有脚本加载完成后执行回调函数。
     * @param {string|string[]} urls - 一个或多个要加载的 JS 文件的 URL。
     * @param {Function} callback - 所有脚本加载完成后要执行的回调函数。
     */
    loadScripts(urls, callback = null) {
        if (typeof urls === 'string') {
            urls = [urls];
        }
        let promises = urls.map(url => {
            return new Promise((resolve, reject) => {
                let script = document.createElement('script');
                script.type = 'text/javascript';

                script.onload = () => resolve(url);
                script.onerror = () => reject(`Script load error: ${url}`);

                script.src = url;
                document.getElementsByTagName('body')[0].appendChild(script);
            });
        });
        Promise.all(promises).then(() => {
            typeof callback === 'function' && callback();
        }).catch(error => {
            console.error(error);
        });
    }


    bindButtonUpload(obj, url, done) {
        $(obj).change(function () {
            let formdata = new FormData();
            formdata.append("file", $(obj)[0].files[0]);
            Loading.show();
            $.ajax({
                type: "POST",
                url: url,
                data: formdata,
                contentType: false,
                processData: false,
                dataType: "json",
                success: function (res) {
                    Loading.hide();
                    if (res.code == 200) {
                        typeof done === 'function' && done(res.data);
                    } else {
                        layer.msg(res.msg);
                    }
                },
                error: function (data) {
                    Loading.hide();
                    layer.msg('网络错误');
                }
            });
        });
    }


    /**
     *
     * @param call
     * @param millisecond
     * @param immediately
     * @returns {Promise<void>}
     */
    async timer(call, millisecond, immediately = false) {
        if (immediately) {
            const state = await call();
            if (!state) {
                return;
            }
        }
        setTimeout(async () => {
            const state = await call();
            if (state) {
                await this.timer(call, millisecond, false);
            }
        }, millisecond);
    }

    getDomHeight(dom) {
        if (!dom[0]) {
            return "";
        }
        let styleAttr = dom[0].getAttribute("style");
        let heightMatch = styleAttr && styleAttr.match(/height\s*:\s*([^;]+)(;|$)/);
        if (heightMatch) {
            return heightMatch[1].trim();
        } else {
            return "";
        }
    }

    openCheckoutWindowUrl(url) {
        if (getVar("PAY_CONFIG_CHECKOUT_COUNTER") != 1) {
            window.location.href = url;
            return;
        }

        layer.open({
            type: 2,
            title: util.icon("icon-shouyintai-copy") + " 收银台",
            shadeClose: false,
            maxmin: util.isPc(),
            area: util.isPc() ? ['80%', '80%'] : ['100%', '100%'],
            content: url
        });
    }

    onScrollToBottom(callback) {
        // PC端滚动事件
        window.addEventListener('scroll', function () {
            if (document.documentElement.scrollTop + window.innerHeight >= document.documentElement.scrollHeight) {
                callback();  // 到底部时触发回调
            }
        });

        // 手机端触摸事件
        let lastTouchY = 0;
        window.addEventListener('touchstart', function (event) {
            lastTouchY = event.touches[0].pageY;
        });

        window.addEventListener('touchmove', function (event) {
            if (lastTouchY < event.touches[0].pageY) {
                if (document.documentElement.scrollTop + window.innerHeight >= document.documentElement.scrollHeight) {
                    callback();  // 到底部时触发回调
                }
            }
        });
    }


    syncOrder(url, tradeNo) {
        util.timer(() => {
            return new Promise(resolve => {
                util.post({
                    url: url,
                    loader: false,
                    data: {trade_no: tradeNo},
                    done: res => {
                        if (res.data.status === 2) {
                            if (new Date() > new Date(res.data.timeout)) {
                                //超时
                                message.error("订单支付超时");
                                window.location.reload();
                                resolve(false);
                                return;
                            }
                            message.alert("支付已完成，已经授权成功！", "success");
                            //支付成功
                            window.location.reload();
                            resolve(false);
                        } else if (res.data.status === 3) {
                            window.location.reload();
                            resolve(false);
                            return;
                        }
                        resolve(true);
                    },
                    error: () => {
                        window.location.reload();
                        resolve(false);
                    },
                    fail: () => {
                        window.location.reload();
                        resolve(false);
                    }
                });
            });
        }, 2000);
    }


    getFormData(element) {
        const formData = new FormData(
            element instanceof HTMLFormElement ? element : document.querySelector(element)
        );
        return Object.fromEntries(formData.entries());
    }
}