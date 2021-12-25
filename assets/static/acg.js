let acg = {
    property: {
        Browser: {
            ie: /msie/.test(window.navigator.userAgent.toLowerCase()),
            moz: /gecko/.test(window.navigator.userAgent.toLowerCase()),
            opera: /opera/.test(window.navigator.userAgent.toLowerCase()),
            safari: /safari/.test(window.navigator.userAgent.toLowerCase())
        },
        cache: {}
    },
    loadScript(url, callback = null) {
        let _script = document.createElement('script');
        _script.setAttribute('type', 'text/javascript');
        _script.setAttribute('src', url);
        document.getElementsByTagName('head')[0].appendChild(_script);
        if (this.property.Browser.ie) {
            _script.onreadystatechange = function () {
                if (this.readyState == 'loaded') {
                    typeof callback === 'function' && callback();
                }
            };
        } else if (this.property.Browser.moz) {
            _script.onload = function () {
                typeof callback === 'function' && callback();
            };
        } else {
            typeof callback === 'function' && callback();
        }
    },
    getLangTime(start, end) {
        let seconds = 1000;
        let minutes = seconds * 60;
        let hours = minutes * 60;
        let days = hours * 24;
        let years = days * 365;
        let t1 = start;
        let t2 = end;
        let diff = t2 - t1;
        let diffYears = Math.floor(diff / years);
        let diffDays = Math.floor((diff / days) - diffYears * 365);
        let diffHours = Math.floor((diff - (diffYears * 365 + diffDays) * days) / hours);
        let diffMinutes = Math.floor((diff - (diffYears * 365 + diffDays) * days - diffHours * hours) / minutes);
        let diffSeconds = Math.floor((diff - (diffYears * 365 + diffDays) * days - diffHours * hours - diffMinutes * minutes) / seconds);
        return {
            days: diffDays,
            hours: diffHours,
            minutes: diffMinutes,
            seconds: diffSeconds,
        }
    },
    ready(fromId, callback) {
        let from = parseInt(fromId);
        if (from !== 0) {
            localStorage.setItem("from_id", from);
        }

        // acg.loadScript("/assets/static/jquery.min.js", () => {
        acg.loadScript("/assets/static/layer/layer.js", () => {
            acg.loadScript("/assets/static/clipboard.js", callback);
        });
        // });
    },
    $post(url, data, done, error = null) {
        let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
        $.post(url, data, res => {
            layer.close(loaderIndex);
            if (res.code !== 200) {
                layer.msg(res.msg);
                typeof error === 'function' ? error(res) : layer.msg(res.msg);
                return;
            }
            typeof done === 'function' && done(res.data);
        });
    },
    $get(url, done, error = null) {
        let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
        $.get(url, res => {
            layer.close(loaderIndex);
            if (res.code !== 200) {
                typeof error === 'function' ? error(res) : layer.msg(res.msg);
                return;
            }
            typeof done === 'function' && done(res.data);
        });
    },
    Util: {
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
        }
    },
    API: {
        secret(opt) {
            acg.$post("/user/api/index/secret", {
                orderId: opt.orderId,
                password: opt.password
            }, res => {
                typeof opt.begin === 'function' && opt.begin(res);
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                typeof opt.success === 'function' && opt.success(res);
                typeof opt.yes === 'function' && opt.yes(res);
            }, opt.error);
        },
        query(opt) {
            acg.$post("/user/api/index/query", {
                keywords: opt.keywords
            }, res => {
                typeof opt.begin === 'function' && opt.begin(res);

                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                res.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes(res);
            }, opt.error);
        },
        pay(opt) {
            acg.$get("/user/api/index/pay", res => {
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                res.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes(res);
            }, opt.error);
        },
        trade(opt) {
            acg.$post("/user/api/order/trade", opt.data, opt.success, opt.error);
        },
        tradePerform(payId) {
            let cardId = $('input[name=card_id]:checked').val();
            if (cardId === undefined) {
                cardId = 0;
            }

            let arrayToObject = acg.Util.arrayToObject($('.commodity-form').serializeArray());
            arrayToObject.commodity_id = acg.property.cache.currentCommodityId;
            arrayToObject.card_id = cardId;
            arrayToObject.pay_id = payId;
            arrayToObject.device = acg.Util.device();
            arrayToObject.from = localStorage.hasOwnProperty("from_id") ? localStorage.getItem("from_id") : 0;

            acg.API.trade({
                data: arrayToObject,
                success: res => {
                    if (res.secret == null) {
                        window.location.href = res.url;
                    } else {
                        layer.open({
                            type: 1,
                            title: "您购买的卡密如下：",
                            area: ['420px', '420px'],
                            content: '<textarea class="layui-input" style="padding: 15px;height: 98%;width: 100%;border: none;overflow-x: hidden;">' + res.secret + '</textarea>'
                        });
                    }
                    acg.API.captcha(".captcha");
                },
                error: () => {
                    acg.API.captcha(".captcha");
                }
            });
        },
        tradeAmount(opt) {
            acg.$post("/user/api/index/tradeAmount", {
                num: opt.num,
                cardId: opt.cardId,
                coupon: opt.coupon,
                commodityId: opt.commodityId
            }, res => {
                typeof opt.success === 'function' && opt.success(res);
            }, opt.error);

        },
        tradeAmountPerform(instance) {
            let num = $("input[name=num]").val();
            if (num <= 0) {
                $("input[name=num]").val(1);
                num = 1;
            }
            let cardId = $('input[name=card_id]:checked').val();
            let coupon = $('input[name=coupon]').val();
            if (cardId === undefined) {
                cardId = 0;
            }
            acg.API.tradeAmount({
                num: num,
                cardId: cardId,
                coupon: coupon,
                commodityId: acg.property.cache.currentCommodityId,
                success: res => {
                    $(instance).html("¥" + res.amount);
                }
            });
        },
        //获取分类
        category(opt) {
            acg.$get("/user/api/index/data", res => {
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                res.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes();
            }, opt.error);
        },
        draftCard(opt) {
            acg.$get("/user/api/index/card?commodityId=" + opt.commodityId + "&page=" + opt.page + "&limit=" + opt.limit, res => {
                typeof opt.begin === 'function' && opt.begin(res);

                if (res.data.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }

                res.data.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes();
            }, opt.error);
        },
        draftCardPerform(instance, commodityId, page, draft_premium) {
            acg.API.draftCard({
                commodityId: commodityId,
                page: page,
                limit: 5,
                begin: res => {
                    let next = res.current_page + 1;
                    let prev = res.current_page - 1;
                    if (next >= res.last_page) {
                        next = res.last_page;
                    }
                    if (prev <= 1) {
                        prev = 1;
                    }
                    $(instance).html('自选加价：<span class="draft_premium">￥' + draft_premium + '元</span><table><tbody class="draftCard"></tbody></table> <div style="margin-top: 5px;" class="page-button"><button ' + (res.current_page <= 1 ? 'disabled' : '') + ' type="button" onclick="acg.API.draftCardPerform(\'' + instance + '\',' + commodityId + ',' + prev + ',\'' + draft_premium + '\')">上一组</button> <button ' + (res.current_page >= res.last_page ? 'disabled' : '') + ' type="button" onclick="acg.API.draftCardPerform(\'' + instance + '\',' + commodityId + ',' + next + ',\'' + draft_premium + '\')">下一组</button></div>');
                },
                success: item => {
                    $(instance).find(".draftCard").append('<tr><td><label><input type="checkbox" onclick="acg.API.tradeAmountPerform(\'.trade_amount\')" onchange="acg.API.draftCardCheckbox(this)" name="card_id" value="' + item.id + '"> ' + item.draft + '</label></td></tr>');
                }
            });
        },
        draftCardCheckbox(obj) {
            let state = $(obj).prop("checked");
            $('input[name=card_id]:checked').prop("checked", false);
            if (state === true) {
                $(obj).prop("checked", true);
            } else {
                $(obj).prop("checked", false);
            }
        },
        //获取商品列表
        commoditys(opt) {
            if (opt.categoryId === "") {
                return;
            }
            acg.$get("/user/api/index/commodity?categoryId=" + opt.categoryId + (opt.keywords ? "&keywords=" + opt.keywords : ""), res => {
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty();
                    return;
                }
                res.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes();
            }, opt.error);
        },
        //获取商品信息
        commodity(opt) {
            acg.$get("/user/api/index/commodityDetail?commodityId=" + opt.commodityId, res => {
                typeof opt.begin === 'function' && opt.begin(res);
                acg.property.cache.currentCommodityId = opt.commodityId;
                opt.pay && $(opt.pay).show();
                if (opt.auto) {
                    for (const autoKey in opt.auto) {
                        let instance = $(opt.auto[autoKey]);
                        let value = res[autoKey];
                        if (autoKey == "share_url") {
                            instance.attr("data-clipboard-text", value);
                            instance.click(function () {
                                let clipboard = new ClipboardJS(opt.auto[autoKey]);
                                clipboard.on('success', function (e) {
                                    layer.msg("分享链接已经复制成功了，赶快发给好友吧！");
                                });
                            });
                            continue;
                        } else if (autoKey == "delivery_way") {
                            if (value == 0) {
                                instance.html("自动发货").addClass("delivery_way_auto");
                            } else {
                                instance.html("手动发货").addClass("delivery_way_hand");
                            }
                            continue
                        } else if (autoKey == "contact_type") {
                            let contactType = ["任意联系方式", "手机号", "邮箱", "QQ号"];
                            instance.attr("placeholder", "请输入您的" + contactType[value]);
                            continue;
                        } else if (autoKey == "coupon") {
                            value == 0 ? instance.hide() : instance.show();
                            continue;
                        } else if (autoKey == "purchase_num") {
                            //
                            continue;
                        } else if (autoKey == "captcha") {
                            if (res.trade_captcha == 1) {
                                instance.parents(".captcha_status").show();
                                acg.API.captcha(opt.auto[autoKey]);
                                instance.click(function () {
                                    acg.API.captcha(opt.auto[autoKey]);
                                });
                            } else {
                                instance.parents(".captcha_status").hide();
                            }
                            continue;
                        } else if (autoKey == "password_status") {
                            //查询密码
                            value == 0 ? instance.hide() : instance.show();
                            continue;
                        } else if (autoKey == "lot_status") {
                            if (value == 1) {
                                let list = res.lot_config.trim().split("\n");
                                let ws = [];
                                list.forEach(item => {
                                    let s = item.split('-');
                                    if (s.length === 2) {
                                        ws[s[0]] = s[1].trim("\r");
                                    }
                                });
                                let x = '';
                                ws.forEach((money, num) => {
                                    x += '<li>一次性购买' + num + '张，单价自动调整为：<b>¥' + money + '</b></li>';
                                });
                                if (ws.length > 0) {
                                    instance.html(x);
                                }
                                instance.show();
                            } else {
                                instance.hide();
                            }
                            continue;
                        } else if (autoKey == "seckill_status") {
                            clearInterval(acg.property.cache.seckill);
                            if (value == 1) {
                                let timer = instance.find(".seckill_timer");
                                instance.show();
                                let start = new Date(res.seckill_start_time).getTime();
                                let end = new Date(res.seckill_end_time).getTime();
                                let now = new Date().getTime();
                                let fnEnd = () => {
                                    let langTime = acg.getLangTime(new Date().getTime(), end);
                                    timer.html("<span class='seckill_end_time'>还剩" + langTime.days + "天" + langTime.hours + "时" + langTime.minutes + "分" + langTime.seconds + "秒结束</span>");
                                    if (langTime.days <= 0 && langTime.hours <= 0 && langTime.minutes <= 0 && langTime.seconds <= 0) {
                                        timer.html("<span class='seckill_end'>已结束</span>");
                                        opt.pay && $(opt.pay).hide();
                                        clearInterval(acg.property.cache.seckill);
                                    }
                                };
                                let fnStart = () => {
                                    let langTime = acg.getLangTime(new Date().getTime(), start);
                                    timer.html("<span class='seckill_start_time'>" + langTime.days + "天" + langTime.hours + "时" + langTime.minutes + "分" + langTime.seconds + "秒后开始抢购</span>");
                                    if (langTime.days <= 0 && langTime.hours <= 0 && langTime.minutes <= 0 && langTime.seconds <= 0) {
                                        clearInterval(acg.property.cache.seckill);
                                        opt.pay && $(opt.pay).show();
                                        fnEnd();
                                        acg.property.cache.seckill = setInterval(fnEnd, 1000);
                                    }
                                };
                                if (now >= start && now <= end) {
                                    opt.pay && $(opt.pay).show();
                                    fnEnd();
                                    //秒杀正在进行当中
                                    acg.property.cache.seckill = setInterval(fnEnd, 1000);
                                } else if (now < start) {
                                    opt.pay && $(opt.pay).hide();
                                    fnStart();
                                    acg.property.cache.seckill = setInterval(fnStart, 1000);
                                } else if (now > end) {
                                    opt.pay && $(opt.pay).hide();
                                    timer.html("<span class='seckill_end'>已结束</span>");
                                }
                            } else {
                                instance.hide();
                            }
                            continue;
                        } else if (autoKey == "card") {
                            if (res.delivery_way == 1 || res.shared) {
                                instance.addClass("card_count_unknown").html("未知");
                                continue;
                            }
                            if (res.inventory_hidden == 1) {
                                if (res.card <= 0) {
                                    instance.addClass("card_count_empty").html("已售罄");
                                } else if (res.card <= 5) {
                                    instance.addClass("card_count_immediately").html("马上卖完!");
                                } else if (res.card <= 20) {
                                    instance.addClass("card_count_general").html("一般");
                                } else if (res.card > 20) {
                                    instance.html("充足");
                                }
                            } else {
                                instance.html(res.card);
                            }
                            continue;
                        } else if (autoKey == "purchase_count") {
                            if (res.purchase_count > 0) {
                                instance.html("该商品每人累计购买最多" + res.purchase_count + "个");
                                instance.show();
                            } else {
                                instance.hide();
                            }
                            continue;
                        } else if (autoKey == "price") {
                            if (res.login) {
                                instance.html("¥" + res.user_price);
                            } else {
                                let user = "";
                                if (res.user_price < res.price) {
                                    user = '<span class="price_tips">(会员价:¥' + res.user_price + ') <a style="color: #6d97d5;" href="/user/authentication/login?goto=' + encodeURIComponent(res.share_url) + '" target="_blank">现在就去登录!</a></span>';
                                }
                                instance.html('¥' + res.price + ' ' + user);
                            }
                            continue;
                        } else if (autoKey == "trade_amount") {
                            if (res.login) {
                                instance.html("¥" + res.user_price);
                            } else {
                                instance.html('¥' + res.price);
                            }
                            continue;
                        } else if (autoKey == "draft_status") {
                            if (res.draft_status == 1) {
                                instance.show();
                                acg.API.draftCardPerform(opt.auto[autoKey], res.id, 1, res.draft_premium);
                            } else {
                                instance.hide();
                            }
                            continue;
                        } else if (autoKey == "widget") {
                            if (res.widget) {
                                let parse = JSON.parse(res.widget);
                                if (parse != null) {
                                    parse.forEach(widget => {
                                        instance.append('<p>' + widget.cn + '：<input class="acg-input" type="' + widget.type + '" name="' + widget.name + '" placeholder="' + widget.placeholder + '"></p>');
                                    });
                                } else {
                                    instance.hide();
                                }
                            } else {
                                instance.hide();
                            }
                            continue;
                        }
                        instance.html(value);
                    }
                }
                if (!res.login) {
                    if (res.only_user == 1 || res.purchase_count > 0) {
                        opt.pay && $(opt.pay).html('<div class="need-login">该商品需要登录才能购买，<a href="/user/authentication/login?goto=' + res.share_url + '">现在登录</a></div>');
                    }
                }
                typeof opt.success === 'function' && opt.success(res);
            }, opt.error);
        },
        captcha(obj) {
            $(obj).attr("src", "/user/captcha/image?action=trade&rand=" + Math.ceil(Math.random() * 10000000));
        }
    },


}