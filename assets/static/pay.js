var Pay = {
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
    getWebSiteInfo(done) {
        this.$get("/user/api/site/info", done);
    },
    getCategory(done) {
        this.$get("/user/api/index/data", done);
    },
    getCommodityAll(categoryId, done) {
        this.$get("/user/api/index/commodity?categoryId=" + categoryId, done);
    },
    getCommodityDetail(commodityId, done) {
        this.$get("/user/api/index/commodityDetail?commodityId=" + commodityId, done);
    },
    getDraftCard(commodityId, page, done) {
        this.$get("/user/api/index/card?commodityId=" + commodityId + "&page=" + page, done);
    },
    getPay(done) {
        this.$get("/user/api/index/pay", done);
    },
    getTradeAmount(commodityId, coupon, cardId, num, done) {
        this.$post("/user/api/index/tradeAmount", {
            num: num,
            cardId: cardId,
            coupon: coupon,
            commodityId: commodityId
        }, done);
    },
    trade(option, done, error) {
        this.$post("/user/api/order/trade", option, done, error);
    },
    getQuery(keywords, done, error) {
        this.$post("/user/api/index/query", {
            keywords: keywords
        }, done, error);
    },
    getSecret(orderId, password, done, error) {
        this.$post("/user/api/index/secret", {
            orderId: orderId,
            password: password
        }, done, error);
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
    $_GET(variable) {
        var query = window.location.search.substring(1);
        var vars = query.split("&");
        for (var i = 0; i < vars.length; i++) {
            var pair = vars[i].split("=");
            if (pair[0] == variable) {
                return pair[1];
            }
        }
        return 0;
    },
    getLangTime(start, end) {
        var seconds = 1000;
        var minutes = seconds * 60;
        var hours = minutes * 60;
        var days = hours * 24;
        var years = days * 365;
        var t1 = start;
        var t2 = end;
        var diff = t2 - t1;
        var diffYears = Math.floor(diff / years);
        var diffDays = Math.floor((diff / days) - diffYears * 365);
        var diffHours = Math.floor((diff - (diffYears * 365 + diffDays) * days) / hours);
        var diffMinutes = Math.floor((diff - (diffYears * 365 + diffDays) * days - diffHours * hours) / minutes);
        var diffSeconds = Math.floor((diff - (diffYears * 365 + diffDays) * days - diffHours * hours - diffMinutes * minutes) / seconds);
        return {
            days: diffDays,
            hours: diffHours,
            minutes: diffMinutes,
            seconds: diffSeconds,
        }
    }
}