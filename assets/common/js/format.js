const format = new class Format {

    constructor() {
        this.property = {
            success: 'badge-light-success',
            danger: 'badge-light-danger',
            warning: 'badge-light-warning',
            primary: 'badge-light-primary',
            dark: 'badge-light-dark',
            cambridgeBlue: 'badge-light-cambridgeBlue',
            info: 'badge-light-info',
            italic: 'acg-italic',
            bold: 'acg-bold',
            underline: 'acg-underline',
        }
    }

    browser(ua = navigator.userAgent) {
        let list = {
            UC: /ucweb/i.test(ua), // UC浏览器
            Firefox: /firefox/i.test(ua), // 火狐浏览器
            Opera: /opera/i.test(ua), // Opera浏览器
            Safari: /safari/i.test(ua) && !/chrome/i.test(ua), // safari浏览器
            360: /360se/i.test(ua), // 360浏览器
            '百度': /bidubrowser/i.test(ua), // 百度浏览器
            '搜狗': /metasr/i.test(ua), // 搜狗浏览器
            IE6: /msie 6.0/i.test(ua), // IE6
            IE7: /msie 7.0/i.test(ua), // IE7
            IE8: /msie 8.0/i.test(ua), // IE8
            IE9: /msie 9.0/i.test(ua), // IE9
            IE10: /msie 10.0/i.test(ua), // IE10
            IE11: /msie 11.0/i.test(ua), // IE11
            Edge: /edg/i.test(ua), // 微软EDGE
            '猎豹': /lbbrowser/i.test(ua), // 猎豹浏览器
            '微信': /micromessenger/i.test(ua), // 微信内置浏览器
            QQ: /qqbrowser/i.test(ua), // QQ浏览器
            Chrome: /safari/i.test(ua) && /chrome/i.test(ua), // Chrome浏览器
        };
        for (const key in list) {
            if (list[key]) {
                return key;
            }
        }
        return '未知';
    }

    success(text, ...arg) {
        return '<span class="acg-badge ' + format.property.success + ' ' + format.join(" ", ...arg) + '">' + text + '</span>';
    }

    danger(text, ...arg) {
        return '<span class="acg-badge ' + format.property.danger + ' ' + format.join(" ", ...arg) + '">' + text + '</span>';
    }

    warning(text, ...arg) {
        return '<span class="acg-badge ' + format.property.warning + ' ' + format.join(" ", ...arg) + '">' + text + '</span>';
    }

    info(text, ...arg) {
        return '<span class="acg-badge ' + format.property.info + ' ' + format.join(" ", ...arg) + '">' + text + '</span>';
    }

    primary(text, ...arg) {
        return '<span class="acg-badge ' + format.property.primary + ' ' + format.join(" ", ...arg) + '">' + text + '</span>';
    }

    dark(text, ...arg) {
        return '<span class="acg-badge ' + format.property.dark + ' ' + format.join(" ", ...arg) + '">' + text + '</span>';
    }

    cambridgeBlue(text, ...arg) {
        return '<span class="acg-badge ' + format.property.cambridgeBlue + ' ' + format.join(" ", ...arg) + '">' + text + '</span>';
    }

    italic(text, ...arg) {
        return '<span class="' + format.property.italic + ' ' + format.join(" ", ...arg) + '">' + text + '</span>'
    }

    bold(text, ...arg) {
        return '<span class="' + format.property.bold + ' ' + format.join(" ", ...arg) + '">' + text + '</span>'
    }

    underline(text, ...arg) {
        return '<span class="' + format.property.underline + ' ' + format.join("", ...arg) + '">' + text + '</span>'
    }

    avatar(img) {
        return '<img style="border-radius:25%;width: 18px;height: 18px;" class="render-image" src="' + img + '"">';
    }

    join(symbol, ...arg) {
        let list = [];
        arg.forEach(item => {
            if (typeof item === "string") {
                list.push(item);
            }
        });
        return list.join(symbol);
    }

    money(amount, color = "green", bold = true) {
        if (!amount || amount == "-") {
            return '-';
        }
        return `<span style="color: ${color};${bold ? 'font-weight: bolder;' : ''}">¥${format.amountRemoveTrailingZeros(amount)}</span>`;
    }

    amount(amount) {
        if (isNaN(amount)) {
            return "0.00";
        }
        return Number(amount).toFixed(2);
    }

    amountRemoveTrailingZeros(number) {
        if (number == "" || number == 0 || isNaN(number)) {
            return "0.00";
        }
        number = number.toString();
        return number.replace(/(\.\d*?[1-9])0+|\.0*$/, '$1');
    }

    amounts(amount) {
        if (isNaN(amount)) {
            return '0.00';
        }
        let amountStr = Math.floor(amount * 100) / 100;
        amountStr = amountStr.toFixed(2);
        return amountStr.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    color(text, color) {
        return '<span style="color: ' + color + ';">' + text + '</span>';
    }

    badge(text, ...classes) {
        return '<span class="a-badge ' + format.join(" ", ...classes) + '">' + i18n(text) + '</span>';
    }

    badgeGroup(text, ...classes) {
        return `<span class="a-badge-group ${format.join(" ", ...classes)}">${text}</span>`;
    }

    item(item) {
        return `<span class="table-item"><img src="${item?.cover}" class="table-item-icon"><span class="table-item-name">${item?.name}</span></span>`;
    }

    category(item, obj = null) {
        if (obj?.hasOwnProperty("icon")) {
            item = obj;
        }

        if (!item?.icon) {
            return "-";
        }

        return `<span class="table-item table-item-cate"><img src="${item?.icon}" class="table-item-icon"><span class="table-item-name">${item?.name}</span></span>`;
    }

    group(group) {
        if (!group) {
            return '-';
        }
        return `<span class="table-item table-item-cate"><img src="${group.icon}" class="table-item-icon"><span class="table-item-name">${group.name}</span></span>`;
    }


    user(item) {
        if (!item) {
            return '-';
        }
        return `<span class="table-item table-item-user"><img src="${item.avatar}" class="table-item-icon"><span class="table-item-name">${item.username}</span></span>`;
    }

    owner(item) {
        if (!item) {
            return `<span class="table-item table-item-user"><span class="table-item-name">${util.icon("fa-duotone fa-regular fa-shop")}</span></span>`;
        }
        return `<span class="table-item table-item-user"><img src="${item.avatar}" class="table-item-icon"><span class="table-item-name">${item.username}</span></span>`;
    }

    shared(item) {
        if (!item) {
            return '-';
        }
        return `<span class="table-item table-item-user"><img src="${item.domain}/favicon.ico" class="table-item-icon"><span class="table-item-name">${item.name}</span></span>`;
    }

    bank(item) {
        if (!item) {
            return '-';
        }
        return `<span class="table-item"><img src="${item.icon}" class="table-item-icon"><span class="table-item-name">${item.name}</span></span>`;
    }

    link(link) {
        if (link == "#" || !link) {
            return '-';
        }
        const uuid = util.generateRandStr(16);

        $(document).on("click", `.${uuid}`, function () {
            util.copyTextToClipboard(link, () => {
                message.success("复制成功");
            });
        });

        return `<a href="${link}" target="_blank" class="text-primary">${link}</a> <span style="cursor:pointer;" class="${uuid}"><i class="fa-duotone fa-regular fa-copy"></i></span>`
    }

    invite(item) {
        if (!item) {
            return '-';
        }
        return `<span class="table-item table-item-user"><img src="${item.avatar}" class="table-item-icon"><span class="table-item-name">${item.username}</span></span>`;
    }

    site(item) {
        if (!item) {
            return '-';
        }
        return `<span class="table-item"><img src="${item.logo}" class="table-item-icon"><span class="table-item-name">${item.title}</span></span>`;
    }

    pay(item) {
        if (!item) {
            return '-';
        }
        return `<span class="pay-item"><img src="${item.icon}" class="item-icon"><span class="item-name">${item.name}</span></span>`;
    }

    customer(item, all) {
        if (!item) {
            return `<svg style="height: 21px;width: 40px;" aria-hidden="true"><use xlink:href="#icon-youke"></use></svg>`;
        }
        return `<span class="table-item table-item-user"><img src="${item.avatar}" class="table-item-icon"><span class="table-item-name">${item.username}</span></span>`;
    }

    bankCard(item) {
        if (!item) {
            return '-';
        }
        return `<span class="table-item"><img src="${item?.bank?.icon}" class="table-item-icon"><span class="table-item-name">${item?.bank?.name}(${item.card_no})</span></span>`;
    }

    client(item) {
        return `<span class="table-item table-item-user"><img src="${item.avatar}" class="table-item-icon"><span class="table-item-name">${item.username}</span></span>`;
    }

    blockItems(items) {
        const html = items.map(item => `<div class="b-item"><span class="b-title">${item.title}</span><span class="b-content">${item.content}</span></div>`).join('');
        return `<div class="table-block-items">${html}</div>`;
    }

    select2BankCard(state) {
        if (!state.id) {
            return state.text;
        }
        var $state = $(
            `<span class="select2-item"><img src="${state.element.getAttribute('data-icon')}" class="table-item-icon"><span class="table-item-name">${state.text}</span></span>`
        );
        return $state;
    }

    plugin(item) {
        return `<span class="table-item"><img src="${item.icon}" class="table-item-icon"><span class="table-item-name">${item.name}</span></span>`;
    }

    pastTime(date) {
        const intervals = [
            {label: '年', seconds: 31536000},
            {label: '月', seconds: 2592000},
            {label: '天', seconds: 86400},
            {label: '小时', seconds: 3600},
            {label: '分钟', seconds: 60},
            {label: '秒', seconds: 1}
        ];

        const past = new Date(date).getTime();
        const now = new Date().getTime();
        const secondsElapsed = Math.floor((now - past) / 1000);

        const interval = intervals.find(i => i.seconds < secondsElapsed || i.label === '秒');
        const count = Math.floor(secondsElapsed / interval.seconds);
        return `${count < 0 ? 0 : count} ${i18n(interval.label + "前")}`;
    }

    expireTime(date) {
        const intervals = [
            {label: '年', seconds: 31536000},
            {label: '个月', seconds: 2592000},
            {label: '天', seconds: 86400},
            {label: '小时', seconds: 3600},
            {label: '分钟', seconds: 60},
            {label: '秒', seconds: 1}
        ];

        const expire = new Date(date).getTime();
        const now = new Date().getTime();
        let secondsElapsed = Math.floor((expire - now) / 1000);

        if (secondsElapsed <= 0) {
            return false;
        }

        let result = '';

        for (let i = 0; i < intervals.length; i++) {
            const count = Math.floor(secondsElapsed / intervals[i].seconds);
            if (count > 0) {
                result += `<b>${count}</b>${intervals[i].label}`;
                secondsElapsed -= count * intervals[i].seconds;

                if (i < intervals.length - 1) {
                    const nextCount = Math.floor(secondsElapsed / intervals[i + 1].seconds);
                    if (nextCount > 0) {
                        result += `<b>${nextCount}</b>${intervals[i + 1].label}`;
                    }
                }
                break;
            }
        }

        return result.trim();
    }
}

