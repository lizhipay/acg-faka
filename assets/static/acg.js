//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

//这套 acg.js 被 10 套旧版 PHP 引擎主题共用，其中只有部分主题会加载 ready.js。
//所以不能直接调 i18n()——没加载的主题会 ReferenceError 整个挂掉。
//acgT() 在 i18n 不存在时原样返回，等于零影响。
function acgT(text) {
    return typeof i18n === 'function' ? i18n(text) : text;
}

let acg = {
    setCache(key, value, expire = 0) {
        localStorage.setItem("cache_" + key, JSON.stringify({
            data: value, expire: expire, time: Math.round(new Date().getTime() / 1000)
        }));
    }, getCache(key) {
        key = "cache_" + key;
        let item = localStorage.getItem(key);
        if (!item) {
            return null;
        }
        item = JSON.parse(item);
        if (item.expire != 0 && Math.round(new Date().getTime() / 1000) > item.time + item.expire) {
            localStorage.removeItem(key);
            return null;
        }
        return item.data;
    }, property: {
        Browser: {
            ie: /msie/.test(window.navigator.userAgent.toLowerCase()),
            moz: /gecko/.test(window.navigator.userAgent.toLowerCase()),
            opera: /opera/.test(window.navigator.userAgent.toLowerCase()),
            safari: /safari/.test(window.navigator.userAgent.toLowerCase())
        }, cache: {
            raceId: "", payHtml: "", inventoryHidden: 0, order: []
        }, setting: {
            cache: 0, cache_expire: 0
        },
    }, loadScript(url, callback = null) {
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
    }, fillTime(template, langTime) {
        //倒计时原来是「还剩」+天+「天」+时+「时」… 这样拼的,
        //每个字都成了单独词条,翻出来是一堆碎片且语序改不了。
        //改成整句带 {d}{h}{m}{s} 占位符,各语言自己决定顺序。
        return String(template)
            .replace("{d}", langTime.days)
            .replace("{h}", langTime.hours)
            .replace("{m}", langTime.minutes)
            .replace("{s}", langTime.seconds);
    }, getLangTime(start, end) {
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
            days: diffDays, hours: diffHours, minutes: diffMinutes, seconds: diffSeconds,
        }
    }, ready(fromId, callback) {
        let from = parseInt(fromId);
        if (from !== 0) {
            localStorage.setItem("from_id", from);
        }

        if (typeof cache_status != "undefined") {
            acg.property.setting.cache = cache_status;
        }

        if (typeof cache_expire != "undefined") {
            acg.property.setting.cache_expire = cache_expire;
        }

        // acg.loadScript("/assets/static/jquery.min.js", () => {
        acg.loadScript("/assets/static/layer/layer.js", () => {
            acg.loadScript("/assets/static/clipboard.js", callback);
        });
        // });
    }, $post(url, data, done, error = null, cache = 0, cache_expire = 0) {
        if (cache == 1) {
            let cacheRes = acg.getCache(url + encodeURIComponent(JSON.stringify(data)));
            if (cacheRes) {
                typeof done === 'function' && done(cacheRes);
                return;
            }
        }

        let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
        $.post(url, data, res => {
            layer.close(loaderIndex);
            if (res.code !== 200) {
                layer.msg(res.msg);
                typeof error === 'function' ? error(res) : layer.msg(res.msg);
                return;
            }

            if (cache == 1) {
                acg.setCache(url + encodeURIComponent(JSON.stringify(data)), res.data, cache_expire);
            }

            typeof done === 'function' && done(res.data, res);
        });
    }, $get(url, done, error = null, cache = 0, cache_expire = 0) {
        if (cache == 1) {
            let cacheRes = acg.getCache(url);
            if (cacheRes) {
                typeof done === 'function' && done(cacheRes);
                return;
            }
        }

        let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
        $.get(url, res => {
            layer.close(loaderIndex);
            if (res.code !== 200) {
                typeof error === 'function' ? error(res) : layer.msg(res.msg);
                return;
            }

            if (cache == 1) {
                acg.setCache(url, res.data, cache_expire);
            }

            typeof done === 'function' && done(res.data, res);
        });
    }, Util: {
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
                    //不再预编码 + 和 &（#852）：旧清洗管线的补偿，#833 后服务端只解一层，
                    //预编码会把 %2B/%26 原样落库
                    paramsToJSONObject[item.name] = item.value;
                }
            });
            return paramsToJSONObject;
        }, isPc() {
            var userAgentInfo = navigator.userAgent;
            var Agents = ["Android", "iPhone", "SymbianOS", "Windows Phone", "iPad", "iPod"];
            var flag = true;
            for (var v = 0; v < Agents.length; v++) {
                if (userAgentInfo.indexOf(Agents[v]) > 0) {
                    flag = false;
                    break;
                }
            }
            return flag;
        }, isIphone() {
            var ua = navigator.userAgent;
            var ipad = ua.match(/(iPad).*OS\s([\d_]+)/i), ipod = ua.match(/(iPod).*OS\s([\d_]+)/i);
            let result = !ipod && !ipad && ua.match(/(iPhone\sOS)\s([\d_]+)/i);
            return Boolean(result);
        }, isIpad() {
            var ua = navigator.userAgent;
            var ipad = ua.match(/(iPad).*OS\s([\d_]+)/i);
            return Boolean(ipad);
        }, isAndroid() {
            var ua = navigator.userAgent;
            var android = ua.match(/(Android)\s+([\d.]+)/i);
            return Boolean(android);
        }, isMobile() {
            return this.isAndroid() || this.isIphone();
        }, isAlipay() {
            var ua = navigator.userAgent;
            var alipay = ua.match(/(AlipayClient)/i);
            return Boolean(alipay);
        }, isWx() {
            var ua = navigator.userAgent;
            var wx = ua.match(/(MicroMessenger)/i);
            return Boolean(wx);
        }, device() {
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
    }, API: {
        secret(opt) {
            acg.$post("/user/api/index/secret", {
                tradeNo: acg.property.cache.order[opt.orderId].trade_no, password: opt.password
            }, res => {
                typeof opt.begin === 'function' && opt.begin(res);
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                typeof opt.success === 'function' && opt.success(res);
                typeof opt.yes === 'function' && opt.yes(res);
            }, opt.error);
        }, query(opt) {
            acg.$post("/user/api/index/query", {
                keywords: opt.keywords
            }, res => {
                typeof opt.begin === 'function' && opt.begin(res);

                if (res.total == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                res?.list?.forEach(item => {
                    acg.property.cache.order[item.id] = item;
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes(res);
            }, opt.error);
        }, pay(opt) {
            acg.$get("/user/api/index/pay", res => {
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                res.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes(res);
            }, opt.error, acg.property.setting.cache, acg.property.setting.cache_expire);
        }, trade(opt) {
            acg.$post("/user/api/order/trade", opt.data, opt.success, opt.error);
        }, tradePerform(payId) {
            let arrayToObject = this.getPostData();

            arrayToObject.pay_id = payId;
            arrayToObject.device = acg.Util.device();

            acg.API.trade({
                data: arrayToObject, success: res => {
                    if (res.secret == null) {
                        window.location.href = res.url;
                    } else {
                        acgSecretPopup(res);
                    }
                    acg.API.captcha(".captcha");
                }, error: () => {
                    acg.API.captcha(".captcha");
                }
            });
        },
        getFormData(element) {
            const formData = new FormData(
                element instanceof HTMLFormElement ? element : document.querySelector(element)
            );
            return Object.fromEntries(formData.entries());
        },
        getPostData() {
            const _item = acg.property.cache.item;
            let post = this.getFormData('.commodity-form');
            post["item_id"] = _item?.id;

            if (!this.isEmptyOrNotJson(_item?.config?.category)) {
                //商品分类
                post["race"] = $(`.sku-race.checked`).data("id");
            }

            //获取SKU
            if (!this.isEmptyOrNotJson(_item?.config?.sku)) {
                for (const name in _item?.config?.sku) {
                    const $sku = $(`.sku[data-sku="${name}"].checked`);
                    post["sku"] = post["sku"] || {};
                    post["sku"][name] = $sku.data("value");
                }
            }

            //自选卡密
            let cardId = $('input[name=card_id]:checked').val();
            if (cardId > 0) {
                post['card_id'] = cardId;
            }

            return post;
        },
        tradeAmount(opt) {
            acg.$post("/user/api/index/valuation", this.getPostData(), res => {
                typeof opt.success === 'function' && opt.success(res);
            }, opt.error);

        }, tradeAmountPerform(instance) {
            let num = $("input[name=num]").val();
            if (num <= 0) {
                $("input[name=num]").val(1);
                num = 1;
            }
            let cardId = $('input[name=card_id]:checked').val();

            if (cardId > 0) {
                $("input[name=num]").val(1);
            }

            acg.API.tradeAmount({
                success: res => {
                    $(instance).html(acgCurrencySymbol() + (res.price * $("input[name=num]").val()));
                    $('.price').html(acgCurrencySymbol() + res.price);
                    if (res.hasOwnProperty("card_count")) {
                        let instance = $('.card_count');
                        if (acg.property.cache.inventoryHidden == 1) {
                            if (res.card_count <= 0) {
                                instance.addClass("card_count_empty").html(acgT("已售罄"));
                            } else if (res.card_count <= 5) {
                                instance.addClass("card_count_immediately").html(acgT("即将售罄"));
                            } else if (res.card_count <= 20) {
                                instance.addClass("card_count_general").html(acgT("一般"));
                            } else if (res.card_count > 20) {
                                instance.html(acgT("充足"));
                            }
                        } else {
                            instance.html(res.card_count);
                        }
                    }
                }
            });
        }, //获取分类
        category(opt) {
            acg.$get("/user/api/index/data", res => {
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }
                //接口返回的本来就是带 children 的整棵树，但 success 是逐个顶层节点回调的，
                //子级会被丢掉。想做多级分类的主题传 tree 拿整棵树自己渲染；
                //不传就还是老行为，其余主题一行都不用改。
                if (typeof opt.tree === 'function') {
                    opt.tree(res);
                } else {
                    res.forEach(item => {
                        typeof opt.success === 'function' && opt.success(item);
                    });
                }
                typeof opt.yes === 'function' && opt.yes();
            }, opt.error, acg.property.setting.cache, acg.property.setting.cache_expire);
        }, draftCard(opt) {
            let data = this.getPostData();
            data.page = opt.page;
            data.limit = opt.limit;
            acg.property.cache.cardPage = data.page;
            acg.$post("/user/api/index/card", data, res => {
                typeof opt.begin === 'function' && opt.begin(res);

                if (res?.total == 0) {
                    typeof opt.empty === 'function' && opt.empty(res);
                    return;
                }

                res?.list?.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes();
            }, opt.error);
        }, draftCardPerform(instance, commodityId, page, draft_premium) {
            acg.API.draftCard({
                commodityId: commodityId, page: page, limit: 5, begin: res => {
                    let next = acg.property.cache.cardPage + 1;
                    let prev = acg.property.cache.cardPage - 1;

                    if (prev <= 1) {
                        prev = 1;
                    }
                    $(instance).html('<table><tbody class="draftCard"></tbody></table> <div style="margin-top: 5px;" class="page-button"><button ' + (res.current_page <= 1 ? 'disabled' : '') + ' type="button" data-acg-action="acg.API.draftCardPerform" data-acg-args=\'["' + instance + '",' + commodityId + ',' + prev + ',"' + draft_premium + '"]\'>' + acgT("上一组") + '</button> <button ' + (res.current_page >= res.last_page ? 'disabled' : '') + ' type="button" data-acg-action="acg.API.draftCardPerform" data-acg-args=\'["' + instance + '",' + commodityId + ',' + next + ',"' + draft_premium + '"]\'>' + acgT("下一组") + '</button></div>');
                }, success: item => {
                    let premium = 0;

                    if (draft_premium > 0) {
                        premium = draft_premium;
                    }

                    if (item?.draft_premium > 0) {
                        premium = item.draft_premium;
                    }

                    $(instance).find(".draftCard").append('<tr><td><label><input type="checkbox" data-acg-change="acg.API.draftCardCheckbox" name="card_id" value="' + item.id + '"> ' + item.draft + (premium > 0 ? `<span class="card-premium">+${acgCurrencySymbol()}${premium}</span>` : '') + '</label></td></tr>');
                }
            });
        }, draftCardCheckbox(obj) {
            let state = $(obj).prop("checked");
            $('input[name=card_id]:checked').prop("checked", false);
            if (state === true) {
                $(obj).prop("checked", true);
            } else {
                $(obj).prop("checked", false);
            }
            acg.API.tradeAmountPerform('.trade_amount');
        }, //获取商品列表
        commoditys(opt) {
            if (opt.categoryId === "") {
                return;
            }
            acg.$get("/user/api/index/commodity?categoryId=" + opt.categoryId + (opt.keywords ? "&keywords=" + opt.keywords : "") + (opt.limit ? "&limit=" + opt.limit : "") + (opt.page ? "&page=" + opt.page : ""), (res, row) => {
                if (res.length == 0) {
                    typeof opt.empty === 'function' && opt.empty();
                    return;
                }
                res.forEach(item => {
                    typeof opt.success === 'function' && opt.success(item);
                });
                typeof opt.yes === 'function' && opt.yes();

                if (opt.limit) {
                    let totalPage = Math.ceil(row.total / opt.limit);
                    //上一页
                    typeof opt.prev === 'function' && opt.prev(totalPage, opt.page, opt.page <= 1 ? 1 : opt.page - 1);
                    //分页
                    typeof opt.pageRender === 'function' && this.getPage(opt.page, totalPage, opt.pageRender);
                    //下一页
                    typeof opt.next === 'function' && opt.next(totalPage, opt.page, opt.page >= totalPage ? totalPage : opt.page + 1);
                }

            }, opt.error, acg.property.setting.cache, acg.property.setting.cache_expire);
        },
        getPage(page, totalPage, done = null) {
            for (let i = 1; i <= totalPage; i++) {
                if (i == 2 && page - 6 > 1) {
                    i = page - 6;
                } else if (i == page + 6 && page + 6 < totalPage) {
                    i = totalPage - 1;
                } else {
                    typeof done === 'function' && done(totalPage, page, i);
                }
            }
        },
        //获取商品信息
        commodity(opt) {
            acg.property.cache.raceId = "";
            acg.property.cache.cardPage = 1;
            acg.$get("/user/api/index/commodityDetail?commodityId=" + opt.commodityId, res => {
                typeof opt.begin === 'function' && opt.begin(res);
                acg.property.cache.item = res;
                localStorage.setItem("_item_id", res.id);
                acg.property.cache.currentCommodityId = opt.commodityId;
                opt.pay && $(opt.pay).show();
                if (opt.auto) {
                    for (const autoKey in opt.auto) {
                        let instance = $(opt.auto[autoKey]);
                        let value = res[autoKey];
                        if (autoKey == "share_url") {
                            instance.attr("data-clipboard-text", value + "#buy" );
                            instance.click(function () {
                                let clipboard = new ClipboardJS(opt.auto[autoKey]);
                                clipboard.on('success', function (e) {
                                    layer.msg(acgT("分享链接已经复制成功了，赶快发给好友吧！"));
                                });
                            });
                            continue;
                        } else if (autoKey == "delivery_way") {
                            if (value == 0) {
                                instance.html(acgT("自动发货")).addClass("delivery_way_auto");
                            } else {
                                instance.html(acgT("在线发货")).addClass("delivery_way_hand");
                            }
                            continue
                        } else if (autoKey == "lot_status") {
                            continue;
                        } else if (autoKey == "race") {
                            let lotHtml = $(opt.auto['lot_status']);

                            if (!this.isEmptyOrNotJson(res?.config?.category)) {
                                let content = instance.find("span");
                                let raceIndex = 0;
                                content.html("");
                                acg.property.cache.raceId = "";
                                for (let key in res?.config?.category) {
                                    if (raceIndex == 0) {
                                        acg.property.cache.raceId = key;
                                    }
                                    const price = res?.config?.category[key];
                                    content.append(`<span data-id="${key}" class="race-click button-click sku-race ${raceIndex == 0 ? 'checked' : ''}">${acgT(key)}${price > 0 ? `<span class="badge-money">${acgCurrencySymbol()}${price}</span>` : ''}</span>`);
                                    raceIndex++;
                                }
                                let categoryWholesale = function () {
                                    //批发渲染
                                    let categoryWholesale = res.category_wholesale;
                                    if (categoryWholesale && categoryWholesale.hasOwnProperty(acg.property.cache.raceId)) {
                                        let rules = categoryWholesale[acg.property.cache.raceId];
                                        let ws = [];
                                        for (const ruleKey in rules) {
                                            ws[ruleKey] = rules[ruleKey];
                                        }
                                        let x = '';
                                        ws.forEach((money, num) => {
                                            x += '<div class="lot_string">' + acgT("一次性购买 {num} 张，单价自动调整为：{price}")
                                                .replace("{num}", num).replace("{price}", '<b>' + acgCurrencySymbol() + money + '</b>') + '</div>';
                                        });
                                        if (ws.length > 0) {
                                            lotHtml.html(x);
                                            lotHtml.show();
                                        } else {
                                            lotHtml.hide();
                                        }
                                    } else {
                                        lotHtml.hide();
                                    }
                                }
                                categoryWholesale();
                                const _this = this;
                                $('.sku-race').click(function () {
                                    acg.property.cache.raceId = $(this).attr("data-id");
                                    $('.sku-race').removeClass("checked");
                                    $(this).addClass("checked");
                                    acg.API.tradeAmountPerform('.trade_amount');
                                    categoryWholesale();
                                    _this.stock();
                                });

                                instance.show();
                            } else {
                                let wholesale = res.wholesale;
                                if (wholesale && Object.keys(wholesale).length > 0) {
                                    let ws = [];
                                    for (const ruleKey in wholesale) {
                                        ws[ruleKey] = wholesale[ruleKey];
                                    }
                                    let x = '';
                                    ws.forEach((money, num) => {
                                        x += '<div class="lot_string">' + acgT("一次性购买 {num} 张，单价自动调整为：{price}")
                                                .replace("{num}", num).replace("{price}", '<b>' + acgCurrencySymbol() + money + '</b>') + '</div>';
                                    });
                                    if (ws.length > 0) {
                                        lotHtml.show();
                                        lotHtml.html(x);
                                    } else {
                                        lotHtml.hide();
                                    }
                                } else {
                                    lotHtml.hide();
                                }
                                instance.hide();
                            }

                            continue;
                        } else if (autoKey == "sku") {

                            if (!this.isEmptyOrNotJson(res?.config?.sku)) {
                                for (const skuKey in res?.config?.sku) {
                                    let skuHtml = ``, i = 0;
                                    for (const typeKey in res?.config?.sku[skuKey]) {
                                        const price = res?.config?.sku[skuKey][typeKey];
                                        skuHtml += `<span data-sku="${skuKey}" data-value="${typeKey}" data-price="${price}" class="race-click button-click sku ${i == 0 ? 'checked' : ''}">${acgT(typeKey)}${price > 0 ? `<span class="badge-money">+${acgCurrencySymbol()}${price}</span>` : ''}</span>`;
                                        i++;
                                    }
                                    instance.append(`<p class="general">${acgT(skuKey)}：<span>${skuHtml}</span></p>`);
                                }

                                const _this = this;

                                if (!this.isEmptyOrNotJson(res?.config?.sku)) {
                                    for (const name in res?.config?.sku) {
                                        const $sku = $(`.sku[data-sku="${name}"]`);
                                        $sku.click(function () {
                                            $sku.removeClass("checked");
                                            $(this).addClass("checked");
                                            acg.API.tradeAmountPerform('.trade_amount');
                                            _this.stock();
                                        });
                                    }
                                }
                            }

                        } else if (autoKey == "contact_type") {
                            if (res.login) {
                                instance.parent().hide();
                                continue;
                            }
                            let contactType = [acgT("任意联系方式"), acgT("手机号"), acgT("邮箱"), acgT("QQ号")];
                            instance.attr("placeholder", acgT("请输入您的") + contactType[value]);
                            continue;
                        } else if (autoKey == "coupon") {
                            value == 0 ? instance.hide() : instance.show();
                            continue;
                        } else if (autoKey == "purchase_num") {
                            //
                            if (res.minimum > 0) {
                                instance.val(res.minimum).change();
                            }
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
                                    timer.html("<span class='seckill_end_time'>" + acg.fillTime(acgT("还剩 {d} 天 {h} 时 {m} 分 {s} 秒结束"), langTime) + "</span>");
                                    if (langTime.days <= 0 && langTime.hours <= 0 && langTime.minutes <= 0 && langTime.seconds <= 0) {
                                        timer.html("<span class='seckill_end'>" + acgT("已结束") + "</span>");
                                        opt.pay && $(opt.pay).hide();
                                        clearInterval(acg.property.cache.seckill);
                                    }
                                };
                                let fnStart = () => {
                                    let langTime = acg.getLangTime(new Date().getTime(), start);
                                    timer.html("<span class='seckill_start_time'>" + acg.fillTime(acgT("{d} 天 {h} 时 {m} 分 {s} 秒后开始抢购"), langTime) + "</span>");
                                    $(`.pay-content`).hide();
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
                                    timer.html("<span class='seckill_end'>" + acgT("已结束") + "</span>");
                                }
                            } else {
                                instance.hide();
                            }
                            continue;
                        } else if (autoKey == "card") {
                            acg.property.cache.inventoryHidden = res.inventory_hidden;
                            if (res.delivery_way == 1 || res.shared) {
                                instance.addClass("card_count_unknown").html(acgT("未知"));
                                continue;
                            }
                            if (res.inventory_hidden == 1) {
                                if (res.card <= 0) {
                                    instance.addClass("card_count_empty").html(acgT("已售罄"));
                                } else if (res.card <= 5) {
                                    instance.addClass("card_count_immediately").html(acgT("马上卖完!"));
                                } else if (res.card <= 20) {
                                    instance.addClass("card_count_general").html(acgT("一般"));
                                } else if (res.card > 20) {
                                    instance.html(acgT("充足"));
                                }
                            } else {
                                instance.html(res.card);
                            }
                            continue;
                        } else if (autoKey == "purchase_count") {
                            if (res.purchase_count > 0) {
                                instance.html(acgT("该商品每人累计购买最多 {n} 个").replace("{n}", res.purchase_count));
                                instance.show();
                            } else {
                                instance.hide();
                            }
                            continue;
                        } else if (autoKey == "price") {
                            if (res.login) {
                                instance.html(acgCurrencySymbol() + res.user_price);
                            } else {
                                let user = "";
                                if (res.user_price < res.price) {
                                    user = '<span class="price_tips">(' + acgT("会员价") + ':' + acgCurrencySymbol() + res.user_price + ') <a style="color: #6d97d5;" href="/user/authentication/login?goto=' + encodeURIComponent(res.share_url) + '" target="_blank">' + acgT("现在就去登录!") + '</a></span>';
                                }
                                instance.html(acgCurrencySymbol() + res.price + ' ' + user);
                            }
                            continue;
                        } else if (autoKey == "trade_amount") {
                            if (res.login) {
                                instance.html(acgCurrencySymbol() + res.user_price);
                            } else {
                                instance.html(acgCurrencySymbol() + res.price);
                            }
                            continue;
                        } else if (autoKey == "widget") {
                            if (!this.isEmptyOrNotJson(res.widget)) {
                                res.widget.forEach(widget => {
                                    if (widget.type == "text" || widget.type == "password" || widget.type == "number") {
                                        instance.append('<p>' + widget.cn + '：<input class="acg-input" type="' + widget.type + '" name="' + widget.name + '" placeholder="' + widget.placeholder + '"></p>');
                                    } else if (widget.type == "select") {
                                        let html = '<p>' + widget.cn + '：<select name="' + widget.name + '" style="border-radius: 5px;border: 1px dashed #80b9f594;width:auto;height: auto;display: inline-block;padding: 0 0;"><option value="">' + widget.placeholder + '</option>';
                                        let dict = widget.dict.split(",");
                                        for (let i = 0; i < dict.length; i++) {
                                            let sp = dict[i].split("=");
                                            if (sp.length != 2) {
                                                continue;
                                            }
                                            html += '<option value="' + sp[1] + '">' + sp[0] + '</option>'
                                        }
                                        html += "</select></p>"
                                        instance.append(html);
                                    } else if (widget.type == "textarea") {
                                        instance.append('<p><textarea name="' + widget.name + '" placeholder="' + widget.placeholder + '" style="border-radius: 5px;border: 1px dashed #80b9f594;width: 100%;height: 100px;"></textarea></p>');
                                    } else if (widget.type == "checkbox") {
                                        let html = '<p>' + widget.cn + '：';
                                        let dict = widget.dict.split(",");
                                        for (let i = 0; i < dict.length; i++) {
                                            let sp = dict[i].split("=");
                                            if (sp.length != 2) {
                                                continue;
                                            }
                                            html += '<label style="margin-right: 10px;"><input name="' + widget.name + '[]" type="checkbox" value="' + sp[1] + '"> ' + sp[0] + '</label>';
                                        }
                                        html += '</p>';
                                        instance.append(html);
                                    } else if (widget.type == "radio") {
                                        let html = '<p>' + widget.cn + '：';
                                        let dict = widget.dict.split(",");
                                        for (let i = 0; i < dict.length; i++) {
                                            let sp = dict[i].split("=");
                                            if (sp.length != 2) {
                                                continue;
                                            }
                                            html += '<label style="margin-right: 10px;"><input name="' + widget.name + '" type="radio" value="' + sp[1] + '"> ' + sp[0] + '</label>';
                                        }
                                        html += '</p>';
                                        instance.append(html);
                                    } else if (widget.type == "custom") {
                                        //custom：由 JS 接管渲染的自定义组件容器（如插件注入的人机验证），无输入项、不参与下单校验
                                        let customName = String(widget.name || '').replace(/[^a-zA-Z0-9_-]/g, '');
                                        if (customName) {
                                            instance.append('<div class="acg-widget-custom acg-widget-custom-' + customName + '" data-widget-custom="' + customName + '"></div>');
                                        }
                                    }
                                });
                            } else {
                                instance.hide();
                            }
                            continue;
                        } else if (autoKey == "description") {
                            instance.html(value);
                            instance.find("img").click(function () {
                                let imageUrl = $(this).attr("src");
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
                                        type: 1, title: false, closeBtn: 0, //不显示关闭按钮
                                        anim: 5, area: [img.width + "px", img.height + "px"], shadeClose: true, //开启遮罩关闭
                                        content: '<img  src="' + imageUrl + '" style="border-radius: 20px;width:' + img.width + 'px;height:' + img.height + 'px">'
                                    });
                                }
                            });
                            continue;
                        }
                        instance.html(value);
                    }
                }
                if (!res.login && opt.pay) {
                    $('.need-login').remove();
                    if (res.only_user == 1 || res.purchase_count > 0) {
                        $(opt.pay).hide();
                        $(opt.pay).after('<div class="need-login">' + acgT("该商品需要登录才能购买，{link}")
                            .replace("{link}", '<a href="/user/authentication/login?goto=' + res.share_url + '">' + acgT("现在登录") + '</a>') + '</div>');
                    } else {
                        $(opt.pay).show();
                    }
                }
                this.tradeAmountPerform(`.trade_amount`);
                this.stock();
                typeof opt.success === 'function' && opt.success(res);
            }, opt.error, acg.property.setting.cache, 10);
        }, captcha(obj) {
            $(obj).attr("src", "/user/captcha/image?action=trade&rand=" + Math.ceil(Math.random() * 10000000));
        },
        isEmptyOrNotJson(val) {
            if (val === null || val === undefined) return true;

            if (typeof val === 'object') {
                if (Array.isArray(val) || Object.prototype.toString.call(val) === '[object Object]') {
                    return Object.keys(val).length === 0;
                }
            }

            // 不是对象类型，统统算 true
            return true;
        },
        stock() {
            const _item = acg.property.cache.item;
            const $itemStock = $(`.stock`), $payContent = $(`.pay-content`), $draftStatus = $(`.draft_status`);
            $.post("/user/api/index/stock", this.getPostData(), res => {
                if (res.data.stock_state <= 0) {
                    $itemStock.css('background', '#ff8383').html(acgT("无库存")).show();
                    //记一笔"这次是因为缺货才藏起来的"，切回有货的SKU时才知道该由谁负责恢复
                    $payContent.data("acgStockHidden", true).fadeOut(100);
                    $draftStatus.fadeOut(100);
                    return;
                }

                $itemStock.css('background', '#9259f378').html(acgT("库存") + ": " + res.data.stock).show();

                if (_item.draft_status == 1) {
                    $('input[name=card_id]:checked').prop("checked", false);
                    acg.API.draftCardPerform('.draft_status', null, 1, _item.draft_premium);
                    //预选卡密区在主题CSS里默认 display:none，只能靠这里显示出来
                    $draftStatus.fadeIn(150);
                }

                //付款区还会因为"需要登录才能购买"和"秒杀未开始/已结束"被隐藏，那两种不归这里管，
                //恢复前必须确认它们当前都不成立，否则切一下SKU就能把登录门槛和秒杀限制绕过去
                const payBlockedByOthers = $('.need-login').length > 0
                    || $('.seckill_start_time').length > 0
                    || $('.seckill_end').length > 0;

                //这行原来写在上面的 draft_status 分支里，于是非预选卡密的商品（绝大多数）
                //从无货SKU切回有货SKU后付款区再也回不来，只能刷新页面（#799）
                if ($payContent.data("acgStockHidden") && !payBlockedByOthers) {
                    $payContent.removeData("acgStockHidden");
                    $payContent.fadeIn(150);
                }
            });
        }
    },
}

/* 购买成功弹窗（自带样式，不依赖模板 CSS）。
   原来是一个 420x420 的死高度 + 裸 textarea：卡密只有一行时下面空一大片，
   而且长得像个输入框；买家想复制只能自己拖选。这里重做成：
   等宽代码块 + 复制/下载按钮 + 独立的「使用说明」区块，高度自适应。
   配色全部用 currentColor 和中性灰 rgba(127,127,127,x)，明暗两种模板皮肤下都成立。 */
function acgSecretPopup(res) {
    if (!document.getElementById('acg-secret-style')) {
        var st = document.createElement('style');
        st.id = 'acg-secret-style';
        st.textContent =
            '.acg-secret{display:flex;flex-direction:column;gap:14px;padding:18px 20px 20px;box-sizing:border-box;font-size:14px;}' +
            '.acg-secret__code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.75;' +
            'white-space:pre-wrap;word-break:break-all;padding:14px 16px;border-radius:10px;' +
            'background:rgba(127,127,127,.12);border:1px solid rgba(127,127,127,.22);' +
            'max-height:300px;overflow:auto;user-select:text;-webkit-user-select:text;}' +
            '.acg-secret__bar{display:flex;justify-content:flex-end;gap:10px;}' +
            '.acg-secret__btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:9px;cursor:pointer;' +
            'font-size:13px;line-height:1.4;color:inherit;background:transparent;border:1px solid rgba(127,127,127,.38);' +
            'transition:background .15s ease,border-color .15s ease;}' +
            '.acg-secret__btn:hover{background:rgba(127,127,127,.16);border-color:rgba(127,127,127,.6);}' +
            '.acg-secret__btn svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;' +
            'stroke-linecap:round;stroke-linejoin:round;}' +
            '.acg-secret__note{border-radius:10px;padding:12px 14px;background:rgba(127,127,127,.10);' +
            'border-left:3px solid rgba(127,127,127,.45);}' +
            '.acg-secret__note-title{display:flex;align-items:center;gap:6px;font-size:12px;opacity:.7;margin-bottom:6px;}' +
            '.acg-secret__note-title svg{width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2;' +
            'stroke-linecap:round;stroke-linejoin:round;}' +
            '.acg-secret__note-body{font-size:13px;line-height:1.75;word-break:break-word;max-height:180px;overflow:auto;}' +
            '.acg-secret__note-body p:last-child{margin-bottom:0;}';
        document.head.appendChild(st);
    }

    var secret = res.secret == null ? '' : String(res.secret);
    var esc = secret.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    var ICON_COPY = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
    var ICON_DOWN = '<svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>';
    var ICON_INFO = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>';

    //发货留言是商家富文本，与购买记录页/查询页一致原样渲染；没配就整块不出现
    var note = res.leave_message
        ? '<div class="acg-secret__note"><div class="acg-secret__note-title">' + ICON_INFO + '<span>' +
          acgT('使用说明') + '</span></div><div class="acg-secret__note-body">' + res.leave_message + '</div></div>'
        : '';

    layer.open({
        type: 1,
        title: acgT('您购买的卡密如下：'),
        //高度一律交给内容自己撑：卡密只有一行时不再留一大片空白。
        //手机端原本是 100%x100% 铺满全屏，一行卡密配一整屏空白，更难看
        area: [Math.min((window.innerWidth || 460) - 32, 460) + 'px', 'auto'],
        shadeClose: false,
        content: '<div class="acg-secret">' +
            '<div class="acg-secret__code">' + esc + '</div>' +
            '<div class="acg-secret__bar">' +
                '<button type="button" class="acg-secret__btn" data-acg-act="copy">' + ICON_COPY + '<span>' + acgT('复制') + '</span></button>' +
                '<button type="button" class="acg-secret__btn" data-acg-act="download">' + ICON_DOWN + '<span>' + acgT('下载') + '</span></button>' +
            '</div>' + note + '</div>',
        btn: ['<span style="color:white;">' + acgT('查看更多信息/下载') + '</span>'],
        success: function (layero) {
            layero.find('[data-acg-act="copy"]').on('click', function () {
                var done = function () { layer.msg(acgT('卡密已复制')); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(secret).then(done, function () { fallbackCopy(secret, done); });
                } else {
                    fallbackCopy(secret, done);
                }
            });
            layero.find('[data-acg-act="download"]').on('click', function () {
                var blob = new Blob([secret], {type: 'text/plain;charset=utf-8'});
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = (res.tradeNo || 'card') + '.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            });
        },
        yes: function () {
            window.open('/user/personal/purchaseRecord?tradeNo=' + res.tradeNo);
        }
    });
}

//clipboard API 在 http 站点下不可用，退回选中+execCommand
function fallbackCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
    document.body.removeChild(ta);
}

