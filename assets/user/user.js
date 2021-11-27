var User = {
    property: {
        uploadUrl: "/user/api/upload/handle"
    },
    isPc() {
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
    }
    ,
    isIphone() {
        var ua = navigator.userAgent;
        var ipad = ua.match(/(iPad).*OS\s([\d_]+)/i), ipod = ua.match(/(iPod).*OS\s([\d_]+)/i);
        let result = !ipod && !ipad && ua.match(/(iPhone\sOS)\s([\d_]+)/i);
        return Boolean(result);
    }
    ,
    isIpad() {
        var ua = navigator.userAgent;
        var ipad = ua.match(/(iPad).*OS\s([\d_]+)/i);
        return Boolean(ipad);
    }
    ,
    isAndroid() {
        var ua = navigator.userAgent;
        var android = ua.match(/(Android)\s+([\d.]+)/i);
        return Boolean(android);
    }
    ,
    isMobile() {
        return this.isAndroid() || this.isIphone();
    }
    ,
    isAlipay() {
        var ua = navigator.userAgent;
        var alipay = ua.match(/(AlipayClient)/i);
        return Boolean(alipay);
    }
    ,
    isWx() {
        var ua = navigator.userAgent;
        var wx = ua.match(/(MicroMessenger)/i);
        return Boolean(wx);
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
    },
    $postSync(url, data = null) {
        var respone;
        let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
        $.ajaxSettings.async = false;
        $.post(url, data, res => {
            layer.close(loaderIndex);
            if (res.code !== 200) {
                layer.msg(res.msg);
                return;
            }
            respone = res.data;
        });
        $.ajaxSettings.async = true;
        return respone;
    },
    getPay(done) {
        this.$get("/user/api/recharge/pay", done);
    },
    device() {
        let device = 0;
        if (this.isAndroid()) {
            device = 1;
        } else if (this.isIphone()) {
            device = 2;
        } else if (this.isIpad()) {
            device = 3;
        }
        return device;
    },
    switchElement(obj) {
        $('.checked').removeClass('checked');
        $(obj).addClass('checked');
    },
    trade(option, done) {
        this.$post("/user/api/recharge/trade", option, done);
    },
    upload(obj, done) {
        $(obj).change(function () {
            let formdata = new FormData();
            formdata.append("file", $(obj)[0].files[0]);
            let index = layer.load(1, {
                shade: [0.3, '#fff']
            });
            $.ajax({
                type: "POST",
                url: "/user/api/upload/handle",
                data: formdata,
                contentType: false, // 不设置内容类型
                processData: false, // 不处理数据
                dataType: "json",
                success: function (res) {
                    layer.close(index);
                    if (res.code == 200) {
                        typeof done === 'function' && done(res.data);
                    } else {
                        layer.msg(res.msg);
                    }
                },
                error: function (data) {
                    layer.close(index);
                    layer.msg('网络错误');
                }
            });
        });
    }
}