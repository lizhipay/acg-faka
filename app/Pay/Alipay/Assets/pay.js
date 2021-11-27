var pay = {
    $_GET(name) {
        var reg = new RegExp("(^|&)" + name + "=([^&]*)(&|$)", "i");
        var r = window.location.search.substr(1).match(reg);
        if (r != null) return unescape(r[2]);
        return null;
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
    }
    , queryTimer(tradeNo, unpaid = null, paid = null, expired = null) {
        let timer = 0;
        let callback = () => {
            $.post('/user/api/order/state', {tradeNo: tradeNo}, res => {
                if (res.code != 200) {
                    typeof expired === 'function' && expired(res);
                    clearInterval(timer);
                    return;
                }
                if (res.data.status == 0) {
                    typeof unpaid === 'function' && unpaid(res.data);
                } else if (res.data.status == 1) {
                    typeof paid === 'function' && paid(res.data);
                    clearInterval(timer);
                }
            });
        }
        callback();
        timer = setInterval(() => {
            callback();
        }, 2000);
    }
}