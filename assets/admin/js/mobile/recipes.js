(function (window) {
    'use strict';

    var recipes = [];

    function field(name, label, tone) {
        var descriptor = { field: name, label: label };
        if (tone) {
            descriptor.tone = tone;
        }
        return descriptor;
    }

    function fullField(name, label, tone) {
        var descriptor = field(name, label, tone);
        descriptor.fullWidth = true;
        return descriptor;
    }

    function media(fields, shape, fallbackIcon) {
        return {
            fields: Array.isArray(fields) ? fields : [fields],
            type: 'image',
            shape: shape || 'rounded',
            fallbackIcon: fallbackIcon || 'image'
        };
    }

    function paymentMedia(fieldName, shape, fallbackIcon) {
        var descriptor = media(fieldName, shape || 'circle', fallbackIcon || 'account_balance_wallet');
        descriptor.type = 'payment';
        descriptor.preview = false;
        return descriptor;
    }

    function payPluginVersionField() {
        var descriptor = field('version', '版本');
        descriptor.tone = 'neutral';
        descriptor.format = function (value, row) {
            var version = row && row.info && row.info.version;
            return version ? 'v' + version : value;
        };
        return descriptor;
    }

    function payPluginUpdateVersionField() {
        var descriptor = field('__adminMobilePayUpdateVersion', '发现新版本', 'success');
        descriptor.compactLabel = false;
        descriptor.format = function (value) {
            var version = String(value == null ? '' : value).trim().replace(/^v/i, '');
            return version ? '发现新版本：v' + version : '';
        };
        return descriptor;
    }

    function genericPluginVersionField() {
        var descriptor = field('version', '版本');
        descriptor.tone = 'neutral';
        descriptor.format = function (value, row) {
            var version = row && row.VERSION;
            return version ? 'v' + version : value;
        };
        return descriptor;
    }

    function genericPluginUpdateVersionField() {
        var descriptor = field('__adminMobilePluginUpdateVersion', '发现新版本', 'success');
        descriptor.compactLabel = false;
        descriptor.format = function (value) {
            var version = String(value == null ? '' : value).trim().replace(/^v/i, '');
            return version ? '发现新版本：v' + version : '';
        };
        return descriptor;
    }

    function paymentPluginSubtitleField() {
        var descriptor = field('plugin', '所属插件');
        descriptor.format = function (value) {
            var plugin = String(value == null ? '' : value).trim();
            return '所属插件 · ' + (plugin && plugin !== '-' ? plugin : '系统内置');
        };
        return descriptor;
    }

    function enabledPaymentScopeField(name, label) {
        var descriptor = field(name, label, 'success');
        descriptor.compactLabel = false;
        descriptor.format = function (value, row) {
            return Number(row && row[name]) === 1 ? label : '';
        };
        return descriptor;
    }

    function paymentCodeField() {
        var descriptor = field('type', '支付方式', 'primary');
        descriptor.compactLabel = false;
        descriptor.dot = false;
        descriptor.format = function (value, row) {
            if (Number(row && row.id) === 1) return '';
            var code = String(value == null ? '' : value).trim();
            return code && code !== '-' ? code : '';
        };
        return descriptor;
    }

    function fileSizeField() {
        var descriptor = field('size', '文件大小');
        descriptor.prominentLabel = false;
        return descriptor;
    }

    function switchField(name, label, onLabel, offLabel) {
        return {
            field: name,
            label: label,
            format: function (value) {
                var normalized = String(value == null ? '' : value).trim().toUpperCase();
                if (/^(?:1|ON|TRUE|YES)$/.test(normalized)) return onLabel || '已开启';
                if (/^(?:0|OFF|FALSE|NO)$/.test(normalized)) return offLabel || '未开启';
                return value;
            }
        };
    }

    function businessLevelFlagEnabled(value) {
        return /^(?:1|ON|TRUE|YES)$/.test(String(value == null ? '' : value).trim().toUpperCase());
    }

    function businessLevelPermissionSubtitle() {
        var descriptor = field('supplier', '商户权限');
        descriptor.format = function (value, row) {
            var supplier = businessLevelFlagEnabled(row && row.supplier !== undefined ? row.supplier : value);
            var substation = businessLevelFlagEnabled(row && row.substation);
            if (supplier && substation) return '已开启供货和分站权限';
            if (supplier) return '已开启供货权限';
            if (substation) return '已开启分站权限';
            return '未开启供货或分站权限';
        };
        return descriptor;
    }

    function businessLevelDomainStatus() {
        var descriptor = switchField('top_domain', '独立域名', '可绑定独立域名', '不可绑定独立域名');
        descriptor.tone = function (value, row) {
            return businessLevelFlagEnabled(row && row.top_domain !== undefined ? row.top_domain : value)
                ? 'success'
                : 'neutral';
        };
        return descriptor;
    }

    function sharedStoreConnectionStatusField() {
        var descriptor = field('status', '连接状态');
        descriptor.format = function (_, row) {
            var state = row && row.__mobileConnectStatus;
            if (!state || typeof state !== 'object') return '-';
            return state.success ? '正常' : '错误';
        };
        descriptor.tone = function (_, row) {
            var state = row && row.__mobileConnectStatus;
            return state && state.success ? 'success' : 'danger';
        };
        return descriptor;
    }

    function sharedStoreConnectionErrorField() {
        var descriptor = fullField('__mobileConnectStatus.message', '错误原因', 'danger');
        descriptor.show = function (row) {
            var state = row && row.__mobileConnectStatus;
            return Boolean(state && !state.success && state.message);
        };
        descriptor.format = function (_, row) {
            var state = row && row.__mobileConnectStatus;
            return state && state.message ? String(state.message).trim() : '-';
        };
        return descriptor;
    }

    function rowHasValue(fieldName) {
        return function (row) {
            return row && row[fieldName] !== undefined && row[fieldName] !== null && row[fieldName] !== '';
        };
    }

    function optionalField(name, label, tone) {
        var descriptor = field(name, label, tone);
        descriptor.show = rowHasValue(name);
        return descriptor;
    }

    function optionalSwitchField(name, label, onLabel, offLabel) {
        var descriptor = switchField(name, label, onLabel, offLabel);
        descriptor.show = rowHasValue(name);
        return descriptor;
    }

    function orderOwnerLabel(row) {
        var member = row && row.owner;
        if (!member || typeof member !== 'object') return '访客订单';
        var name = String(member.username || '未命名会员').trim() || '未命名会员';
        var id = member.id == null ? '' : String(member.id).trim();
        return name + (id ? '#' + id : '');
    }

    function orderListOwnerLabel(row) {
        var member = row && row.owner;
        if (!member || typeof member !== 'object') return '访客订单';
        return String(member.username || member.name || '未命名会员').trim() || '未命名会员';
    }

    function orderListOwnerMeta(row) {
        var owner = orderListOwnerLabel(row);
        var createdAt = row && row.create_time;
        if (!Number.isFinite(ticketTimeTimestamp(createdAt))) return owner;
        return owner + ' · ' + ticketRelativeTime(createdAt);
    }

    function orderMemberField() {
        var descriptor = field('owner', '会员');
        descriptor.format = function (value, row) { return orderOwnerLabel(row); };
        return descriptor;
    }

    function parentMemberField() {
        var descriptor = field('parent', '上级');
        descriptor.format = function (value, row) {
            var parent = row && row.parent;
            if (!parent || typeof parent !== 'object') return '-';
            var name = String(parent.username || '未知会员').trim() || '未知会员';
            var id = parent.id == null ? '' : String(parent.id).trim();
            return name + (id ? ' #' + id : '');
        };
        return descriptor;
    }

    function userLoginSubtitleField() {
        var descriptor = field('login_time', '登录时间');
        descriptor.format = function (value, row) {
            var loginTime = ticketRelativeTime(row && row.login_time !== undefined ? row.login_time : value);
            return '登录时间 · ' + loginTime;
        };
        return descriptor;
    }

    function cashApplyTimeSubtitleField() {
        var descriptor = field('create_time', '申请时间');
        descriptor.format = function (value, row) {
            var applyTime = ticketRelativeTime(row && row.create_time !== undefined ? row.create_time : value);
            return '申请时间 · ' + applyTime;
        };
        return descriptor;
    }

    function couponCommoditySubtitleField() {
        var descriptor = field('commodity', '所属商品');
        descriptor.format = function (value, row) {
            var commodity = row && row.commodity;
            if (commodity && typeof commodity === 'object' && commodity.name) {
                return '所属商品 · ' + String(commodity.name).trim();
            }
            if (Number(row && row.commodity_id) > 0) {
                return '商品已删除或不可用';
            }
            var category = row && row.category;
            if (category && typeof category === 'object' && category.name) {
                return '商品分类 · ' + String(category.name).trim();
            }
            if (Number(row && row.category_id) > 0) {
                return '商品分类已删除或不可用';
            }
            return '全场通用';
        };
        return descriptor;
    }

    function userStatusField() {
        return field('status', '状态');
    }

    function userAssetField(name, label, tone) {
        var descriptor = field(name, label, tone);
        descriptor.dot = false;
        descriptor.prominent = name === 'balance';
        descriptor.format = function (value, row) {
            if (value && value !== '-') return value;
            var source = row && row[name];
            if (source === undefined || source === null || source === '') return value;
            var amount = Number(source);
            if (!Number.isFinite(amount)) return String(source);
            var display = amount.toLocaleString('zh-CN', {maximumFractionDigits: 2});
            return name === 'recharge' ? display : '¥' + display;
        };
        return descriptor;
    }

    function billOwnerLabel(row) {
        var owner = row && row.owner;
        if (!owner || typeof owner !== 'object') return '未知会员';
        return String(owner.username || owner.name || '未知会员').trim() || '未知会员';
    }

    function billSubtitleText(row, value) {
        var timeSource = row && row.create_time !== undefined ? row.create_time : value;
        var time = ticketRelativeTime(timeSource);
        return [billOwnerLabel(row), time && time !== '-' ? time : ''].filter(Boolean).join(' · ');
    }

    function billSubtitleField() {
        var descriptor = field('create_time', '会员与发生时间');
        descriptor.format = function (value, row) { return billSubtitleText(row, value); };
        descriptor.liveText = function (row) { return billSubtitleText(row, row && row.create_time); };
        return descriptor;
    }

    function billAmountField() {
        var descriptor = field('amount', '变动金额', function (value, row) {
            return Number(row && row.type) === 0 ? 'danger' : 'success';
        });
        descriptor.prominentLabel = false;
        descriptor.format = function (value, row) {
            var source = row && row.amount !== undefined ? row.amount : value;
            var amount = Number(source);
            var sign = Number(row && row.type) === 0 ? '-' : '+';
            if (!Number.isFinite(amount)) {
                var fallback = String(source == null ? '' : source)
                    .replace(/[￥¥]/g, '')
                    .replace(/^[+\-]\s*/, '')
                    .trim();
                return sign + (fallback || '0');
            }
            return sign + Math.abs(amount).toLocaleString('zh-CN', {
                maximumFractionDigits: 2
            });
        };
        return descriptor;
    }

    function billTypeField() {
        return field('type', '收支类型', function (value, row) {
            return Number(row && row.type) === 0 ? 'danger' : 'success';
        });
    }

    function rechargeOrderMemberLabel(row) {
        var member = row && row.user;
        if (!member || typeof member !== 'object') return '未知会员';
        return String(member.username || member.name || '未知会员').trim() || '未知会员';
    }

    function rechargeOrderMemberField() {
        var descriptor = field('user', '会员');
        descriptor.format = function (value, row) { return rechargeOrderMemberLabel(row); };
        return descriptor;
    }

    function rechargeOrderAmountField() {
        var descriptor = field('amount', '充值金额', 'success');
        descriptor.dot = false;
        descriptor.compactLabel = false;
        descriptor.format = function (value, row) {
            var source = row && row.amount !== undefined ? row.amount : value;
            var amount = Number(source);
            if (!Number.isFinite(amount)) return value || '¥0.00';
            return '¥' + amount.toLocaleString('zh-CN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        };
        return descriptor;
    }

    function rechargeOrderStatusField() {
        return field('status', '支付状态');
    }

    function rechargeOrderTimeLabel(value) {
        return ticketRelativeTime(value);
    }

    function rechargeOrderPaymentLabel(value) {
        if (value && typeof value === 'object') value = value.name || value.title || value.code || '';
        return String(value || '').trim().toUpperCase();
    }

    function rechargeOrderSubtitleValues(paymentValue, timeValue) {
        var payment = rechargeOrderPaymentLabel(paymentValue);
        var time = rechargeOrderTimeLabel(timeValue);
        return [
            payment && payment !== '-' ? payment : '',
            time && time !== '-' ? time : ''
        ].filter(Boolean);
    }

    function rechargeOrderSubtitleField() {
        var descriptor = field('trade_no', '充值单号与支付信息');
        descriptor.format = function (value, row) {
            var tradeNo = String(row && row.trade_no || value || '-').trim() || '-';
            var parts = rechargeOrderSubtitleValues(row && row.pay, row && row.create_time);
            return [tradeNo].concat(parts).join(' · ');
        };
        descriptor.parts = function (value, row) {
            var tradeNo = String(row && row.trade_no || '-').trim() || '-';
            var parts = rechargeOrderSubtitleValues(row && row.pay, row && row.create_time);
            return {
                emphasis: tradeNo,
                text: parts.join(' · ')
            };
        };
        descriptor.liveParts = function (row) {
            var tradeNo = String(row && row.trade_no || '-').trim() || '-';
            var parts = rechargeOrderSubtitleValues(row && row.pay, row && row.create_time);
            return {
                emphasis: tradeNo,
                text: parts.join(' · ')
            };
        };
        descriptor.copyField = 'trade_no';
        descriptor.copyLabel = '复制订单号';
        return descriptor;
    }

    function orderSubtitleField() {
        var descriptor = field('trade_no', '订单身份');
        descriptor.format = function (value, row) {
            var tradeNo = String(row && row.trade_no || value || '-').trim() || '-';
            return tradeNo + ' ' + orderListOwnerMeta(row);
        };
        descriptor.parts = function (value, row) {
            return {
                emphasis: String(row && row.trade_no || '-').trim() || '-',
                text: orderListOwnerMeta(row)
            };
        };
        descriptor.liveParts = function (row) {
            return {
                emphasis: String(row && row.trade_no || '-').trim() || '-',
                text: orderListOwnerMeta(row)
            };
        };
        descriptor.copyField = 'trade_no';
        descriptor.copyLabel = '复制订单号';
        return descriptor;
    }

    function orderProductField() {
        var descriptor = field('commodity', '商品');
        descriptor.format = function (value, row) {
            var commodity = row && row.commodity;
            return commodity && commodity.name ? commodity.name : '商品已删除或不可用';
        };
        return descriptor;
    }

    function cardCommodityName(value, row) {
        var commodity = row && row.commodity;
        var name = commodity && typeof commodity === 'object'
            ? (commodity.name || commodity.title || '')
            : (typeof value === 'string' ? value : '');
        name = String(name || '').trim();
        return name && name !== '[object Object]' ? name : '商品信息未提供';
    }

    function cardStatusLabel(row) {
        var status = Number(row && row.status);
        if (status === 0) return '未出售';
        if (status === 1) return '已出售';
        if (status === 2) return '已锁定';
        return '状态未知';
    }

    function cardCommoditySubtitleField() {
        var descriptor = field('commodity', '所属商品');
        descriptor.format = function (value, row) {
            return '所属商品 · ' + cardCommodityName(value, row);
        };
        return descriptor;
    }

    function cardRecordTitleField() {
        var descriptor = field('id', '卡密');
        descriptor.format = function (value, row) {
            var id = row && row.id !== undefined ? row.id : value;
            return '卡密 #' + String(id == null || id === '' ? '-' : id);
        };
        return descriptor;
    }

    function cardRecordSubtitleField() {
        var descriptor = field('commodity', '卡密摘要');
        descriptor.format = function (value, row) {
            return cardCommodityName(value, row) + ' · ' + cardStatusLabel(row);
        };
        return descriptor;
    }

    function cardCommoditySummaryField() {
        var descriptor = field('commodity', '商品');
        descriptor.format = function (value, row) {
            return cardCommodityName(value, row);
        };
        return descriptor;
    }

    function cardSummaryStatusField() {
        var descriptor = field('status', '状态');
        descriptor.prominentLabel = false;
        return descriptor;
    }

    function cardSecretDetailField() {
        var descriptor = field('__card_secret', '卡密内容');
        descriptor.format = function (_, row) {
            return String(row && row.secret || '').trim() || '-';
        };
        return descriptor;
    }

    function cardRaceSkuDetailField() {
        var descriptor = field('__card_race_sku', '类别 / SKU');
        descriptor.format = function (_, row) {
            var values = [];
            var race = String(row && row.race || '').trim();
            if (race && race !== '-') values.push(race);
            var sku = row && row.sku;
            if (sku && typeof sku === 'object') {
                Object.keys(sku).forEach(function (key) {
                    var value = String(sku[key] == null ? '' : sku[key]).trim();
                    if (value) values.push(String(key).trim() + '：' + value);
                });
            }
            return values.join(' · ') || '-';
        };
        return descriptor;
    }

    function cardMoneyDetailField(name, label) {
        var descriptor = field(name, label);
        descriptor.format = function (value, row) {
            var source = row && row[name] !== undefined ? row[name] : value;
            if (source === undefined || source === null || source === '') return '-';
            var amount = Number(source);
            if (!Number.isFinite(amount)) {
                var display = String(source).replace(/^[￥¥]\s*/, '').trim();
                return display ? '￥' + display : '-';
            }
            return '￥' + amount.toLocaleString('zh-CN', {maximumFractionDigits: 2});
        };
        return descriptor;
    }

    function cardPurchaseTimeDetailField() {
        var descriptor = field('purchase_time', '出售时间');
        descriptor.format = function (value, row) {
            return row && row.purchase_time ? row.purchase_time : (value || '未出售');
        };
        return descriptor;
    }

    function cardCreateTimeDetailField() {
        var descriptor = field('create_time', '创建时间');
        descriptor.format = function (value, row) {
            return row && row.create_time ? row.create_time : (value || '-');
        };
        return descriptor;
    }

    function cardPremiumField() {
        var descriptor = field('draft_premium', '加价与成本');
        descriptor.compactLabel = false;
        descriptor.dot = false;
        descriptor.prominent = false;
        return descriptor;
    }

    function ticketMemberLabel(row) {
        var member = row && row.user;
        if (!member || typeof member !== 'object') return '未知会员';
        return String(member.username || member.name || '未知会员').trim() || '未知会员';
    }

    function ticketTimeTimestamp(value) {
        var parser = window.AdminMobile && window.AdminMobile.relativeTimeTimestamp;
        return typeof parser === 'function' ? parser(value) : null;
    }

    function ticketRelativeTime(value) {
        var normalized = String(value || '').trim();
        var formatter = window.AdminMobile && window.AdminMobile.formatRelativeTime;
        return typeof formatter === 'function' ? formatter(value) : (normalized || '-');
    }

    function ticketSubtitleLabel(row, fallback) {
        var time = row && row.create_time !== undefined ? row.create_time : fallback;
        var relativeTime = ticketRelativeTime(time);
        return ticketMemberLabel(row) + (relativeTime && relativeTime !== '-' ? ' · ' + relativeTime : '');
    }

    function ticketSubtitleField() {
        var descriptor = field('create_time', '提交信息');
        descriptor.format = function (value, row) {
            return ticketSubtitleLabel(row, value);
        };
        descriptor.liveText = function (row) { return ticketSubtitleLabel(row, '-'); };
        return descriptor;
    }

    function messageSendTimeField() {
        var descriptor = field('create_time', '发送时间');
        descriptor.format = function (value, row) {
            var relativeTime = ticketRelativeTime(row && row.create_time || value);
            var parts = relativeTime && relativeTime !== '-' ? [relativeTime] : [];
            if (row && row.manage_name) parts.push(row.manage_name);
            return parts.join(' · ') || '-';
        };
        return descriptor;
    }

    function orderAmountField() {
        var descriptor = field('amount', '实付金额', 'success');
        descriptor.dot = false;
        descriptor.format = function (value) {
            var amount = Number(value);
            if (!Number.isFinite(amount)) return value || '￥0';
            return '￥' + amount.toLocaleString('zh-CN', {maximumFractionDigits: 2});
        };
        return descriptor;
    }

    function orderQuantityField() {
        var descriptor = field('card_num', '购买数量');
        descriptor.format = function (value, row) {
            var quantity = row && row.card_num !== undefined ? row.card_num : value;
            return String(quantity == null || quantity === '' ? 0 : quantity) + ' 件';
        };
        return descriptor;
    }

    function commodityPriceField(name, label, tone) {
        var descriptor = field(name, label, tone);
        descriptor.format = function (value, row) {
            var source = row && row[name] !== undefined ? row[name] : value;
            var display = String(source == null ? '' : source).trim();
            if (!display || display === '-') return '-';
            return '￥' + display.replace(/^[￥¥]\s*/, '');
        };
        return descriptor;
    }

    function commodityRecommendField() {
        var descriptor = field('recommend', '推荐状态');
        descriptor.tone = function (value, row) {
            var source = row && row.recommend !== undefined ? row.recommend : value;
            return /^(?:1|ON|TRUE|YES|已推荐)$/i.test(String(source == null ? '' : source).trim())
                ? 'success'
                : 'neutral';
        };
        return descriptor;
    }

    function action(id, label, options) {
        var descriptor = { id: id, label: label };
        if (options) {
            Object.keys(options).forEach(function (key) {
                descriptor[key] = options[key];
            });
        }
        return descriptor;
    }

    function selector(selectorValue, label, options) {
        var descriptor = { selector: selectorValue, label: label };
        if (options) {
            Object.keys(options).forEach(function (key) {
                descriptor[key] = options[key];
            });
        }
        return descriptor;
    }

    function add(recipe) {
        recipe.status = recipe.status || [];
        recipe.metrics = recipe.metrics || [];
        recipe.details = recipe.details || [];
        recipe.actions = recipe.actions || {};
        recipe.actions.primary = recipe.actions.primary || [];
        recipe.actions.inline = recipe.actions.inline || [];
        recipe.actions.more = recipe.actions.more || [];
        recipe.actions.batch = recipe.actions.batch || [];
        recipe.actions.toolbar = recipe.actions.toolbar || [];
        recipes.push(recipe);
    }

    add({
        id: 'admin-dashboard',
        title: '控制台',
        pageType: 'dashboard',
        match: { routes: ['/admin/dashboard/index'] },
        primary: field('profit', '盈利'),
        metrics: [
            field('profit', '盈利', 'success'),
            field('turnover', '交易金额', 'primary'),
            field('order_num', '订单'),
            field('business', '子站')
        ],
        details: [
            field('cash_status_0', '未处理提现'),
            field('cash_money_status_1', '成功提现'),
            field('user_register_num', '注册用户'),
            field('recharge_amount', '充值金额'),
            field('online_amout', '第三方支付'),
            field('divide_amount', '推广返利'),
            field('rebate', '子站佣金'),
            field('cost', '供货手续费')
        ],
        workflow: 'dashboard'
    });

    add({
        id: 'admin-user',
        title: '会员管理',
        pageType: 'list',
        match: { routes: ['/admin/user/index'], queryUrls: ['/admin/api/user/data'] },
        primary: field('username', '会员'),
        primaryCopy: {copyField: 'username', label: '复制会员名'},
        subtitle: userLoginSubtitleField(),
        media: media('avatar', 'circle', 'person'),
        status: [
            userStatusField(),
            field('group', '会员等级'),
            optionalField('business_level', '商户等级')
        ],
        metrics: [
            userAssetField('balance', '余额', 'success'),
            userAssetField('coin', '硬币', 'warning'),
            userAssetField('recharge', '元气')
        ],
        compactMetricLimit: 3,
        compactLimit: 5,
        details: [field('id', 'ID'), field('email', '邮箱'), field('phone', '手机号'), field('create_time', '注册时间'), parentMemberField()],
        actions: {
            showPrimary: false,
            primary: [],
            more: [
                action('operation:0', '余额操作'),
                action('operation:1', '硬币操作'),
                action('operation:2', '编辑会员'),
                action('operation:3', '启用会员'),
                action('operation:6', '查看商户资料'),
                action('operation:4', '禁用会员', {
                    danger: true,
                    confirm: {title: '确认禁用会员', message: '禁用后该会员将无法继续登录或使用相关功能。确认禁用此会员吗？', confirmText: '确认禁用'}
                }),
                action('operation:5', '删除会员', { danger: true })
            ],
            batch: [
                selector('.handle', '批量修改会员等级', { role: 'batch' }),
                selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })
            ]
        },
        workflow: 'user-management'
    });

    add({
        id: 'admin-ticket',
        title: '工单管理',
        pageType: 'list',
        match: { routes: ['/admin/ticket/index'], queryUrls: ['/admin/api/ticket/data'] },
        primary: field('title', '工单标题'),
        subtitle: ticketSubtitleField(),
        openAction: 'operation:0',
        media: media('user.avatar', 'circle', 'support_agent'),
        status: [field('status', '处理状态'), field('type', '类型与优先级')],
        details: [field('user', '提交会员'), field('relation', '关联信息'), field('last_message_time', '最后动态')],
        actions: {
            primary: [action('operation:0', '查看工单')]
        },
        workflow: 'ticket-management'
    });

    add({
        id: 'admin-message',
        title: '消息管理',
        pageType: 'list',
        match: { routes: ['/admin/message/index'], queryUrls: ['/admin/api/message/data'] },
        primary: field('title', '消息标题'),
        subtitle: messageSendTimeField(),
        status: [],
        metrics: [field('recipient_count', '接收人数')],
        details: [
            field('jump_url', '跳转地址'),
            messageSendTimeField()
        ],
        actions: {
            primary: [action('operation:0', '查看消息')],
            more: [action('operation:1', '编辑消息'), action('operation:2', '永久删除', { danger: true })],
            toolbar: [selector('.btn-message-create', '发送消息', { role: 'primary' })]
        },
        workflow: 'message-management'
    });

    add({
        id: 'admin-recharge-order',
        title: '充值订单',
        pageType: 'list',
        match: { routes: ['/admin/recharge/order'], queryUrls: ['/admin/api/rechargeOrder/data'] },
        primary: rechargeOrderMemberField(),
        recordTitle: field('trade_no', '充值单号'),
        subtitle: rechargeOrderSubtitleField(),
        prominent: rechargeOrderAmountField(),
        media: paymentMedia('pay', 'circle', 'account_balance_wallet'),
        status: [rechargeOrderStatusField()],
        referenceProminentStatus: false,
        compactMetricLimit: 0,
        details: [
            field('trade_no', '充值单号'),
            rechargeOrderMemberField(),
            rechargeOrderAmountField(),
            field('status', '支付状态'),
            field('pay', '支付方式'),
            field('create_time', '创建时间'),
            field('pay_time', '支付时间')
        ],
        selection: false,
        actions: {
            primary: [action('operation:0', '补单', { danger: true })],
            more: [action('copy:trade_no', '复制订单号', {copyField: 'trade_no', icon: 'content_copy'})],
            toolbar: [selector('.btn-app-export', '导出充值订单'), selector('.clear', '清理记录', {
                danger: true,
                confirm: {title: '确认清理充值订单', message: '将永久删除 30 分钟前仍未支付的充值订单，删除后无法恢复。确认继续吗？', confirmText: '确认清理'}
            })]
        }
    });

    add({
        id: 'admin-user-bill',
        title: '账单管理',
        pageType: 'list',
        match: { routes: ['/admin/user/bill'], queryUrls: ['/admin/api/bill/data'] },
        primary: field('log', '交易信息'),
        subtitle: billSubtitleField(),
        prominent: billAmountField(),
        media: media('owner.avatar', 'circle', 'person'),
        status: [billTypeField(), field('currency', '货币类型')],
        metrics: [field('balance', '账户余额')],
        details: [field('id', 'ID'), field('owner', '会员'), field('create_time', '发生时间')],
        selection: false,
        actions: {}
    });

    add({
        id: 'admin-user-group',
        title: '等级与分组',
        pageType: 'segmented-list',
        match: {
            routes: ['/admin/user/group'],
            queryUrls: ['/admin/api/group/data']
        },
        primary: field('name', '等级名称'),
        subtitle: {
            field: 'recharge',
            label: '需累计元气',
            format: function (value) {
                var amount = Number(value);
                var display = Number.isFinite(amount)
                    ? amount.toLocaleString('zh-CN', {maximumFractionDigits: 2})
                    : String(value == null || value === '' ? '-' : value);
                return '需累计元气 · ' + display;
            }
        },
        media: media('icon', 'rounded', 'workspace_premium'),
        details: [field('recharge', '累计元气')],
        actions: {
            primary: [action('operation:0', '修改或设置')],
            more: [action('operation:1', '删除分组', { danger: true })],
            toolbar: [selector('.btn-group-create', '新增会员等级', { role: 'primary' })]
        },
        workflow: 'group-management'
    });

    add({
        id: 'admin-commodity-group',
        title: '商品分组',
        pageType: 'segmented-list',
        match: { queryUrls: ['/admin/api/commodityGroup/data'] },
        primary: field('name', '分组名称'),
        metrics: [field('count', '商品数')],
        actions: {
            primary: [action('operation:0', '设置商品分组')],
            more: [action('operation:1', '删除商品分组', { danger: true })],
            toolbar: [selector('.btn-commodity-group-create', '新增商品分组', { role: 'primary' })]
        },
        workflow: 'group-management'
    });

    add({
        id: 'admin-category-group-visibility',
        title: '会员等级显示',
        pageType: 'list',
        match: { tableIds: ['category-group-table'] },
        primary: field('name', '会员等级'),
        subtitle: field('name', '会员等级'),
        media: {
            fields: ['icon'],
            type: 'image',
            shape: 'rounded',
            fallbackIcon: 'workspace_premium',
            preview: false
        },
        status: [],
        inlineSwitch: field('show', '显示此会员等级'),
        referenceProminentStatus: false,
        referenceCard: false,
        staticCard: true,
        autoMetrics: false,
        details: [],
        actions: {}
    });

    add({
        id: 'admin-commodity-group-pricing',
        title: '会员等级价格',
        pageType: 'list',
        match: { tableIds: ['commodity-group-table'] },
        primary: field('name', '会员等级'),
        media: media('icon', 'rounded', 'workspace_premium'),
        status: [switchField('show', '显示状态', '绝对显示', '跟随默认')],
        metrics: [field('amount', '自定义单价', 'success')],
        referenceProminentStatus: false,
        details: [field('id', '等级 ID')],
        actions: {}
    });

    add({
        id: 'admin-group-discount-config',
        title: '商品分组折扣',
        pageType: 'list',
        match: { tableIds: ['discount-config-table'] },
        primary: field('name', '商品分组'),
        media: media([], 'rounded', 'sell'),
        prominent: {
            field: 'value',
            label: '折扣',
            format: function (value) {
                var normalized = String(value == null ? '' : value).trim();
                return normalized && normalized !== '-' ? normalized + '%' : '-';
            }
        },
        referenceProminentStatus: false,
        metrics: [],
        autoMetrics: false,
        openInlineField: 'value',
        actions: {}
    });

    add({
        id: 'admin-commodity-group-choice',
        title: '分组商品',
        pageType: 'catalog-list',
        match: {
            queryUrlPrefixes: [/^\/admin\/api\/commodityGroup\/list(?:\?|$)/],
            tableIds: ['commodity-table']
        },
        primary: field('name', '商品名称'),
        subtitle: {
            field: 'node_type',
            label: '条目类型',
            format: function (value, row) {
                if (String(value || '') === 'commodity') return '商品';
                var depth = Number(row && row.tree_depth);
                return ['一级分类', '二级分类', '三级分类', '四级分类', '五级分类'][depth] || '多级分类';
            }
        },
        media: media(['cover', 'image', 'icon'], 'rounded', 'inventory_2'),
        status: [],
        metrics: [],
        autoMetrics: false,
        tree: true,
        treeCollapsible: true,
        treeContext: false,
        treeIndentMax: 44,
        selectionPersistent: true,
        referenceCard: false,
        actions: {}
    });

    add({
        id: 'admin-business-level',
        title: '商户等级',
        pageType: 'list',
        match: { routes: ['/admin/user/businessLevel'], queryUrls: ['/admin/api/businessLevel/data'] },
        primary: field('name', '等级名称'),
        subtitle: businessLevelPermissionSubtitle(),
        media: media('icon', 'rounded', 'storefront'),
        status: [businessLevelDomainStatus()],
        metrics: [field('price', '购买价格', 'success'), field('cost', '供货手续费')],
        actions: {
            primary: [action('operation:0', '编辑等级')],
            more: [action('operation:1', '删除等级', { danger: true })],
            toolbar: [selector('.btn-app-create, .btn-app-add', '新增等级', { role: 'primary' })]
        }
    });

    add({
        id: 'admin-cash',
        title: '提现管理',
        pageType: 'list',
        match: { routes: ['/admin/cash/index'], queryUrls: ['/admin/api/cash/data'] },
        primary: field('user', '申请会员'),
        subtitle: cashApplyTimeSubtitleField(),
        media: media('user.avatar', 'circle', 'person'),
        status: [field('status', '审核状态'), field('type', '结算类型'), field('card', '收款方式')],
        metrics: [field('amount', '申请金额', 'success'), field('cost', '手续费')],
        details: [field('message', '处理信息'), field('create_time', '申请时间'), field('arrive_time', '处理时间')],
        actions: {
            primary: [action('operation:0', '确认打款')],
            more: [action('operation:1', '驳回申请', { danger: true })],
            toolbar: [selector('.settlement', '一键自动结算', { danger: true })]
        },
        workflow: 'cash-review'
    });

    add({
        id: 'admin-category',
        title: '分类管理',
        pageType: 'tree-list',
        match: { routes: ['/admin/category/index'], queryUrls: ['/admin/api/category/data'] },
        primary: field('name', '分类名称'),
        subtitle: field('create_time', '创建时间'),
        media: media('icon', 'rounded', 'category'),
        status: [{field: 'status', label: '启用状态', dot: false}],
        statusLimit: 1,
        metrics: [],
        treeContext: false,
        treeCollapsible: true,
        treeLayout: 'grouped',
        treeIndentStep: 0,
        treeIndentMax: 0,
        treeTogglePlacement: 'leading',
        treeToggleExpandedIcon: 'keyboard_arrow_down',
        treeToggleCollapsedIcon: 'keyboard_arrow_right',
        referenceProminentStatus: true,
        details: [field('hide', '隐藏状态'), field('owner', '创建者')],
        actions: {
            primary: [action('operation:0', '编辑分类')],
            more: [action('share_url:0', '复制推广链接'), action('operation:1', '删除分类', { danger: true })],
            batch: [
                selector('.start', '启用分类', { role: 'batch' }),
                selector('.stop', '停用分类', { role: 'batch', danger: true }),
                selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })
            ],
            toolbar: [selector('.btn-app-create, .btn-app-add', '新增分类', { role: 'primary' })]
        },
        workflow: 'category-management'
    });

    add({
        id: 'admin-commodity',
        title: '商品管理',
        pageType: 'catalog-list',
        match: { routes: ['/admin/commodity/index'], queryUrls: ['/admin/api/commodity/data'] },
        primary: field('name', '商品名称'),
        subtitle: field('name', '商品名称'),
        media: media('cover', 'rounded', 'inventory_2'),
        status: [field('status', '上架状态'), commodityRecommendField()],
        compactMetricLimit: 3,
        metrics: [
            {
                field: 'card_count',
                label: '库存',
                format: function (_, row) {
                    if (Number(row && row.shared_id || 0) > 0) return '-';
                    var stock = Number(row && row.delivery_way) === 0 ? row && row.card_count : row && row.stock;
                    var numericStock = Number(stock);
                    return stock !== '' && stock !== null && stock !== undefined && Number.isFinite(numericStock)
                        ? numericStock
                        : '-';
                }
            },
            commodityPriceField('price', '零售价', 'success'),
            commodityPriceField('user_price', '会员价'),
            field('order_today_amount', '今日销量')
        ],
        details: [field('owner', '商家'), field('shared', '对接平台')],
        actions: {
            primary: [action('operation:0', '编辑商品')],
            more: [action('share_url:0', '复制推广链接'), action('operation:1', '克隆商品'), action('operation:3', '添加卡密'), action('operation:2', '删除商品', { danger: true })],
            batch: [
                selector('.listed', '上架商品', { role: 'batch' }),
                selector('.delist', '下架商品', { role: 'batch' }),
                selector('.handle', '批量设置', { role: 'batch' }),
                selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })
            ],
            toolbar: [selector('.btn-app-create, .btn-app-add', '新增商品', { role: 'primary' })]
        },
        workflow: 'commodity-management'
    });

    add({
        id: 'admin-card',
        title: '卡密管理',
        pageType: 'inventory-list',
        match: { routes: ['/admin/card/index'], queryUrls: ['/admin/api/card/data'] },
        primary: field('secret', '卡密信息'),
        subtitle: cardCommoditySubtitleField(),
        recordTitle: cardRecordTitleField(),
        recordSubtitle: cardRecordSubtitleField(),
        media: media('commodity.cover', 'rounded', 'key'),
        status: [field('status', '使用状态')],
        metrics: [cardPremiumField()],
        summary: [cardSummaryStatusField(), cardCommoditySummaryField(), field('id', '卡密 ID')],
        recordDetailExclude: ['race'],
        referenceProminentStatus: false,
        details: [
            cardSecretDetailField(),
            cardRaceSkuDetailField(),
            field('draft', '预选信息'),
            cardMoneyDetailField('draft_premium', '独立加价'),
            cardMoneyDetailField('cost', '预选成本'),
            cardCreateTimeDetailField(),
            cardPurchaseTimeDetailField(),
            field('note', '备注'),
            field('order.trade_no', '订单号')
        ],
        actions: {
            primary: [
                action('copy:secret', '复制卡密', {copyField: 'secret', icon: 'content_copy'}),
                action('operation:0', '编辑卡密', {icon: 'edit'})
            ],
            more: [
                action('operation:1', '锁定卡密', {icon: 'lock', confirm: {title: '确认锁定卡密', message: '锁定后此卡密将不能正常使用。确认继续吗？', confirmText: '确认锁定'}}),
                action('operation:2', '解锁卡密', {icon: 'lock_open', confirm: {title: '确认解锁卡密', message: '解锁后此卡密将恢复可用。确认继续吗？', confirmText: '确认解锁'}}),
                action('operation:3', '删除卡密', {icon: 'delete_forever', danger: true})
            ],
            batch: [
                selector('.btn-app-lock', '锁定卡密', { role: 'batch' }),
                selector('.btn-app-unlock', '解锁卡密', { role: 'batch' }),
                selector('.btn-app-sell', '标记已出售', { role: 'batch', danger: true }),
                selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })
            ],
            toolbar: [selector('.btn-app-create, .btn-app-add', '导入卡密', { role: 'primary' }), selector('.btn-app-export', '导出卡密')]
        },
        workflow: 'card-inventory'
    });

    add({
        id: 'admin-coupon',
        title: '优惠券',
        pageType: 'list',
        match: { routes: ['/admin/coupon/index'], queryUrls: ['/admin/api/coupon/data'] },
        primary: field('code', '优惠码'),
        subtitle: couponCommoditySubtitleField(),
        media: media('commodity.cover', 'rounded', 'confirmation_number'),
        status: [field('status', '使用状态'), field('mode', '抵扣模式')],
        metrics: [field('money', '面值', 'success'), field('life', '剩余次数'), field('use_life', '已使用次数')],
        details: [field('id', 'ID'), field('commodity', '抵扣范围'), field('owner', '所属者'), field('note', '备注'), field('create_time', '创建时间'), field('expire_time', '到期时间'), field('service_time', '最后使用时间'), field('trade_no', '最后使用订单号')],
        actions: {
            primary: [action('operation:0', '锁定优惠券', {confirm: {title: '确认锁定优惠券', message: '锁定后此优惠券将不能继续使用。确认继续吗？', confirmText: '确认锁定'}})],
            more: [action('operation:1', '解锁优惠券', {confirm: {title: '确认解锁优惠券', message: '解锁后此优惠券将恢复可用。确认继续吗？', confirmText: '确认解锁'}}), action('operation:2', '删除优惠券', { danger: true })],
            batch: [
                selector('.btn-app-lock', '锁定优惠券', { role: 'batch' }),
                selector('.btn-app-unlock', '解锁优惠券', { role: 'batch' }),
                selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })
            ],
            toolbar: [selector('.btn-app-create, .btn-app-add', '生成优惠券', { role: 'primary' }), selector('.btn-app-export', '导出优惠券', {
                confirm: {
                    title: '预览敏感券码导出',
                    message: '系统将先核对当前筛选精确命中数量，此步骤不会立即下载。继续吗？',
                    confirmText: '查看导出范围'
                }
            })]
        }
    });

    add({
        id: 'admin-order',
        title: '商品订单',
        pageType: 'order-list',
        match: { routes: ['/admin/order/index'], queryUrls: ['/admin/api/order/data'] },
        primary: orderProductField(),
        subtitle: orderSubtitleField(),
        media: media('commodity.cover', 'rounded', 'inventory_2'),
        status: [orderAmountField(), field('status', '支付状态'), field('delivery_status', '发货状态'), field('commodity.delivery_way', '发货方式')],
        statusLimit: 4,
        referenceProminentStatus: false,
        metrics: [],
        autoMetrics: false,
        details: [orderMemberField(), orderProductField(), field('sku', '类别与 SKU'), field('pay', '支付方式'), orderAmountField(), orderQuantityField(), field('cost', '手续费'), field('rebate', '佣金'), field('rent', '消耗成本'), field('promote', '推广人与分成')],
        selection: false,
        actions: {
            showPrimary: false,
            primary: [action('secret:0', '查看卡密')],
            more: [action('copy:trade_no', '复制订单号', {copyField: 'trade_no', icon: 'content_copy'}), action('secret:1', '手动发货'), action('widget:0', '查看控件信息')],
            toolbar: [selector('.btn-app-export', '导出订单'), selector('.clear', '清理订单', {
                danger: true,
                confirm: {title: '确认清理商品订单', message: '将永久删除 30 分钟前仍未支付的商品订单，删除后无法恢复。确认继续吗？', confirmText: '确认清理'}
            })]
        },
        workflow: 'order-management'
    });

    add({
        id: 'admin-config-other',
        title: '其他设置',
        pageType: 'settings',
        match: { routes: ['/admin/config/other'], queryUrls: ['/admin/api/config/getBusiness'] },
        primary: field('shop_name', '店铺名称'),
        media: media('user.avatar', 'circle', 'storefront'),
        status: [field('status', '主站显示')],
        details: [field('user', '商家'), field('subdomain', '子域名'), field('topdomain', '独立域名'), field('business_level', '店铺等级')],
        actions: {
            primary: [selector('.save-data', '保存设置')],
            more: [action('operation:0', '从主站隐藏', {
                danger: true,
                confirm: {title: '确认隐藏商家', message: '隐藏后该商家及其相关内容将不再出现在主站。确认继续吗？', confirmText: '确认隐藏'}
            }), action('operation:1', '在主站显示')]
        },
        workflow: 'settings-form'
    });

    add({
        id: 'admin-config-website',
        title: '网站设置',
        pageType: 'settings',
        match: { routes: ['/admin/config/index'] },
        primary: field('title', '网站信息'),
        actions: {
            primary: [selector('.save-data', '保存设置')],
            inline: [
                selector('#data-form .image-input label[data-kt-image-input-action="change"]', '更换网站 LOGO', {
                    description: '选择后台和网站使用的 LOGO 图片，上传后需要保存设置',
                    icon: 'image'
                }),
                selector('.theme-setting', '配置 PC 网站模板', {
                    description: '调整电脑端网站当前模板的专属选项',
                    icon: 'desktop_windows'
                }),
                selector('.theme-mobile-setting', '配置手机网站模板', {
                    description: '调整手机端网站当前模板的专属选项',
                    icon: 'smartphone'
                }),
                selector('.theme-user-setting', '配置 PC 会员中心模板', {
                    description: '调整电脑端会员中心当前模板的专属选项',
                    icon: 'manage_accounts'
                }),
                selector('.theme-user-mobile-setting', '配置手机会员中心模板', {
                    description: '调整手机端会员中心当前模板的专属选项',
                    icon: 'phone_iphone'
                }),
                selector('button.background-upload', '上传 PC 背景图片', {
                    description: '选择电脑端网站使用的背景图片，上传后需要保存设置',
                    icon: 'wallpaper'
                }),
                selector('button.background-mb-upload', '上传手机背景图片', {
                    description: '选择手机端网站使用的背景图片，上传后需要保存设置',
                    icon: 'add_photo_alternate'
                })
            ]
        },
        workflow: 'settings-form'
    });

    add({
        id: 'admin-config-sms',
        title: '短信设置',
        pageType: 'settings',
        match: { routes: ['/admin/config/sms'] },
        primary: field('title', '短信服务'),
        actions: {
            primary: [selector('.save-data', '保存设置')],
            more: [selector('.send-test-message', '发送测试短信')]
        },
        workflow: 'settings-form'
    });

    add({
        id: 'admin-config-email',
        title: '邮箱设置',
        pageType: 'settings',
        match: { routes: ['/admin/config/email'] },
        primary: field('title', '邮件服务'),
        actions: {
            primary: [selector('.save-data', '保存设置')],
            more: [selector('.send-test-message', '发送测试邮件')]
        },
        workflow: 'settings-form'
    });

    add({
        id: 'admin-wiki',
        title: '使用帮助',
        pageType: 'document',
        match: { routes: ['/admin/plugin/wiki'] },
        primary: field('title', '帮助主题'),
        workflow: 'document-navigation'
    });

    add({
        id: 'admin-personal',
        title: '个人设置',
        pageType: 'settings',
        match: { routes: ['/admin/manage/set'] },
        primary: field('nickname', '管理员昵称'),
        details: [field('avatar', '头像')],
        actions: {
            primary: [selector('.save-data', '保存设置')],
            more: [
                selector('#g2fa-bind-btn', '绑定谷歌口令', {icon: 'qr_code_2'}),
                selector('#g2fa-unbind-btn', '解绑谷歌口令', {icon: 'link_off', danger: true})
            ]
        },
        workflow: 'personal-settings'
    });

    add({
        id: 'admin-plugin',
        title: '通用插件',
        pageType: 'catalog-list',
        match: { routes: ['/admin/plugin/index'], queryUrls: ['/admin/api/plugin/getPlugins'] },
        primary: field('plugin_name', '插件名称'),
        subtitle: field('DESCRIPTION', '插件简介'),
        media: media('icon', 'rounded', 'extension'),
        recordSheet: true,
        status: [field('status', '启用状态')],
        metrics: [genericPluginVersionField(), genericPluginUpdateVersionField()],
        metricLimit: 2,
        summary: [field('status', '启用状态'), genericPluginVersionField(), field('author', '开发者')],
        recordDetails: false,
        recordDetailExclude: ['PLUGIN_CONFIG.top'],
        details: [field('DESCRIPTION', '插件说明'), field('version', '版本'), field('author', '开发者'), field('wiki', 'Wiki')],
        actions: {
            primary: [action('operation:2', '配置插件')],
            more: [
                action('operation:1', '启用插件', {icon: 'play_circle'}),
                action('operation:4', '更新插件'),
                action('operation:5', '查看文档'),
                action('operation:3', '查看日志'),
                action('operation:0', '停用插件', { danger: true }),
                action('uninstall:0', '卸载插件', { danger: true })
            ],
            batch: [
                selector('.plugin-start', '启动插件', { role: 'batch' }),
                selector('.plugin-stop', '停止插件', { role: 'batch', danger: true })
            ],
            toolbar: [selector('.btn-app-create', '安装插件', { role: 'primary' }), selector('.plugin-update-all', '更新全部插件')]
        },
        workflow: 'plugin-management'
    });

    add({
        id: 'admin-pay',
        title: '支付设置',
        pageType: 'list',
        match: { routes: ['/admin/pay/index'], queryUrls: ['/admin/api/pay/data'] },
        primary: field('name', '支付方式'),
        subtitle: paymentPluginSubtitleField(),
        media: media('icon', 'rounded', 'account_balance_wallet'),
        status: [paymentCodeField(), enabledPaymentScopeField('commodity', '商品下单'), enabledPaymentScopeField('recharge', '余额充值')],
        metrics: [],
        autoMetrics: false,
        referenceProminent: false,
        details: [field('plugin', '所属插件'), field('equipment', '设备范围'), field('create_time', '创建时间')],
        actions: {
            primary: [action('operation:0', '编辑支付方式')],
            more: [action('operation:1', '删除支付方式', { danger: true })],
            batch: [selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })],
            toolbar: [selector('.btn-app-create, .btn-app-add', '新增支付方式', { role: 'primary' })]
        },
        workflow: 'payment-management'
    });

    add({
        id: 'admin-pay-plugin',
        title: '支付插件',
        pageType: 'catalog-list',
        match: { routes: ['/admin/pay/plugin'], queryUrls: ['/admin/api/pay/getPlugins'] },
        primary: field('plugin_name', '插件名称'),
        subtitle: field('info.description', '插件简介'),
        media: media('icon', 'rounded', 'extension'),
        recordSheet: true,
        metrics: [payPluginVersionField(), payPluginUpdateVersionField()],
        summary: [payPluginVersionField(), field('author', '开发者')],
        recordBadges: [{
            field: 'info.options',
            title: '支持的支付方式',
            emptyText: '暂无支持的支付方式',
            tone: 'success'
        }],
        recordDetails: false,
        recordDetailExclude: ['config.top'],
        details: [field('info.description', '插件说明'), field('options', '功能'), field('author', '开发者')],
        actions: {
            primary: [action('operation:0', '配置插件')],
            more: [action('operation:2', '更新插件'), action('operation:1', '查看日志'), action('uninstall:0', '卸载插件', { danger: true })],
            toolbar: [selector('.btn-app-create', '安装更多插件', { role: 'primary' })]
        },
        workflow: 'payment-plugin-management'
    });

    add({
        id: 'admin-file',
        title: '文件管理',
        pageType: 'media-list',
        match: { routes: ['/admin/file/index'], queryUrls: ['/admin/api/file/data'] },
        primary: field('name', '文件名'),
        subtitle: field('create_time', '上传时间'),
        media: media(['thumb_url', 'url'], 'rounded', 'insert_drive_file'),
        status: [field('type', '文件类型')],
        metrics: [fileSizeField()],
        details: [field('note', '备注'), field('path', '文件路径'), field('user_id', '归属'), field('create_time', '上传时间')],
        actions: {
            primary: [action('operation:0', '下载文件')],
            more: [action('operation:1', '复制链接'), action('operation:2', '编辑备注'), action('operation:4', '预览图片'), action('operation:3', '删除文件', { danger: true })],
            batch: [selector('.file-batch-del', '批量删除', { role: 'batch', danger: true })],
            toolbar: [selector('.file-upload-btn', '上传文件', { role: 'primary' })]
        },
        workflow: 'file-management'
    });

    add({
        id: 'admin-photo-album-picker',
        title: '图片选择',
        pageType: 'media-picker',
        match: {
            queryUrlPrefixes: [/^\/admin\/api\/upload\/get(?:\?|$)/],
            tableIds: ['photo-album-table']
        },
        nativeTable: true,
        referenceCard: false,
        primary: field('name', '图片'),
        media: media(['thumb_url', 'url'], 'rounded', 'image'),
        status: [],
        metrics: [],
        autoMetrics: false,
        actions: {}
    });

    add({
        id: 'admin-log',
        title: '操作日志',
        pageType: 'audit-list',
        match: { routes: ['/admin/log/index'], queryUrls: ['/admin/api/log/data'] },
        primary: field('content', '操作内容'),
        subtitle: field('email', '管理员邮箱'),
        media: media([], 'rounded', 'history'),
        status: [{
            field: 'risk',
            label: '风险评估',
            format: function (_, row) {
                var value = Number(row && row.risk);
                return value === 1 ? '风险较高' : (value === 0 ? '无风险' : '未知');
            },
            tone: function (_, row) {
                var value = Number(row && row.risk);
                return value === 1 ? 'danger' : (value === 0 ? 'success' : 'neutral');
            }
        }],
        details: [
            field('id', '日志 ID'),
            field('nickname', '管理员昵称'),
            field('email', '管理员邮箱'),
            {
                field: 'content_full',
                label: '完整内容',
                format: function (_, row) { return row && row.content || '-'; }
            },
            field('create_ip', 'IP 地址'),
            field('create_time', '记录时间'),
            field('ua', '浏览器')
        ],
        actions: {}
    });

    add({
        id: 'admin-manage',
        title: '管理员',
        pageType: 'list',
        match: { routes: ['/admin/manage/index'], queryUrls: ['/admin/api/manage/data'] },
        primary: {
            field: 'nickname',
            label: '管理员',
            format: function (value, row) {
                var nickname = String(value || '').trim();
                if (nickname) return nickname;
                var email = String(row && row.email || '').trim();
                return email || ('管理员 #' + String(row && row.id || '-'));
            }
        },
        subtitle: field('email', ''),
        media: media('avatar', 'circle', 'admin_panel_settings'),
        status: [field('status', '状态'), field('type', '类型')],
        details: [
            field('id', '管理员 ID'),
            field('email', '邮箱'),
            field('note', '备注'),
            field('login_time', '最近登录'),
            field('login_ip', '登录 IP'),
            field('last_login_time', '上次登录'),
            field('last_login_ip', '上次登录 IP'),
            field('create_time', '创建时间')
        ],
        stateOptions: [
            {value: '', label: '全部'},
            {value: 1, label: '正常'},
            {value: 0, label: '禁用'}
        ],
        actions: {
            primary: [action('operation:0', '编辑管理员')],
            more: [action('operation:1', '删除管理员', { danger: true })],
            toolbar: [selector('.btn-app-create, .btn-app-add', '新增管理员', { role: 'primary' })]
        },
        workflow: 'manager-management'
    });

    add({
        id: 'admin-store',
        title: '店铺共享',
        pageType: 'store-list',
        match: { routes: ['/admin/store/index'], queryUrls: ['/admin/api/store/data'] },
        primary: field('name', '店铺名称'),
        subtitle: field('type', '协议'),
        media: media([], 'rounded', 'storefront'),
        status: [sharedStoreConnectionStatusField()],
        metrics: [fullField('domain', '店铺地址'), field('balance', '余额', 'success')],
        summary: [
            Object.assign(sharedStoreConnectionStatusField(), {compact: false}),
            Object.assign(fullField('domain', '店铺地址'), {compact: false})
        ],
        details: [field('domain', '店铺地址'), sharedStoreConnectionErrorField()],
        recordDetailExclude: ['type'],
        selection: false,
        actions: {
            primary: [action('operation:1', '接入货源')],
            more: [action('operation:0', '同步商品'), action('operation:2', '编辑店铺'), action('operation:4', '访问店铺'), action('operation:3', '删除店铺', { danger: true })],
            toolbar: [selector('.btn-app-create', '新增店铺', { role: 'primary' })]
        },
        workflow: 'application-store'
    });

    add({
        id: 'admin-license-transfer',
        title: '授权',
        pageType: 'list',
        match: { queryUrls: ['/admin/api/app/levels'] },
        primary: [
            field('server_ip', '服务器 IP'),
            {
                field: 'level',
                label: '产品名称',
                format: function (value, row) {
                    var product = String(value || '').trim();
                    if (!product || product === '-') product = Number(row && row.level) === 0 ? '专业版' : '企业版';
                    var expire = String(row && row.expire_date || '').trim() || '未提供';
                    return product + ' · 到期 ' + expire;
                }
            }
        ],
        status: [],
        metrics: [],
        autoMetrics: false,
        details: [],
        actions: {}
    });

    add({
        id: 'admin-store-home',
        title: '应用商店',
        pageType: 'store-list',
        match: { routes: ['/admin/store/home'], queryUrls: ['/admin/api/app/plugins'] },
        primary: [{field: 'plugin_name', label: '软件名称', inlineHtml: true}, field('description', '简介')],
        media: media('icon', 'rounded', 'apps'),
        status: [],
        metrics: [],
        autoMetrics: false,
        cardCta: {
            label: '获取',
            title: '应用详情',
            subtitle: '',
            show: function (row) {
                var installed = Number(row && row.install) === 1;
                var unpurchasedPaidApp = Number(row && row.price) > 0
                    && !(row && row.has && row.has.has === true)
                    && !(row && row.owned === true);
                return !installed || unpurchasedPaidApp;
            },
            offer: {
                field: 'price',
                label: '应用价格',
                format: function (value, row) {
                    var price = Number(row && row.price);
                    if (!Number.isFinite(price)) return '-';
                    return price === 0 ? '免费' : '￥' + price.toFixed(2);
                },
                benefits: [
                    {
                        label: '企业版免费',
                        tone: 'success',
                        show: function (row) {
                            return Number(row && row.price) > 0 && [1, 2].indexOf(Number(row && row.group)) >= 0;
                        }
                    },
                    {
                        label: '专业版免费',
                        tone: 'neutral',
                        show: function (row) {
                            return Number(row && row.price) > 0 && Number(row && row.group) === 1;
                        }
                    }
                ]
            }
        },
        details: [
            field('user', '开发商'),
            field('type', '类型'),
            {
                field: 'has.expire',
                label: '授权',
                format: function (value, row) {
                    if (Number(row && row.price) === 0) return '免费使用';
                    if (!row || !row.has || row.has.has !== true) return row && row.owned === true ? '已购买，可转移授权' : '尚未购买';
                    if (/^(?:creator|official)$/i.test(String(value || ''))) return '永久授权';
                    return value || '已授权';
                }
            },
            {
                field: 'install',
                label: '当前状态',
                format: function (value, row) {
                    var installed = Number(row && row.install) === 1 ? '已安装' : '未安装';
                    if (Number(row && row.price) === 0) return installed + ' · 免费使用';
                    if (row && row.has && row.has.has === true) return installed + ' · 已授权';
                    return installed + (row && row.owned === true ? ' · 已购买' : ' · 未购买');
                }
            },
            {
                field: 'version',
                label: '版本',
                format: function (value, row) {
                    var latest = String(row && row.version || '').trim();
                    var local = String(row && row.local_version || '').trim();
                    if (!latest && !local) return '-';
                    if (!local) return '最新 v' + latest;
                    if (!latest || latest === local) return 'v' + local + ' · 已是最新';
                    return '本地 v' + local + ' · 最新 v' + latest;
                }
            },
            {
                field: 'web_site',
                label: '官网',
                show: function (row) {
                    var website = String(row && row.web_site || '').trim();
                    return Boolean(website && website !== '#');
                }
            }
        ],
        selection: false,
        actions: {
            showPrimary: false,
            primary: [action('operation:0', '安装')],
            more: [
                action('operation:1', '更新'),
                action('operation:2', '解绑', { danger: true }),
                action('operation:4', '购买'),
                action('operation:5', '访问官网'),
                action('operation:3', '卸载', { danger: true })
            ],
            toolbar: [
                selector('.update-pro', '开通企业版', {
                    role: 'primary',
                    icon: 'workspace_premium',
                    trailingIcon: 'arrow_forward',
                    className: 'admin-mobile-store-enterprise-cta admin-mobile-store-enterprise-cta--primary',
                    description: '全部插件免费 · 专属技术支持'
                }),
                selector('.bind-pro', '绑定专业版/企业版', {
                    icon: 'link',
                    trailingIcon: 'arrow_forward',
                    className: 'admin-mobile-store-enterprise-cta admin-mobile-store-enterprise-cta--secondary',
                    description: '转移已有授权 · 原设备将解除绑定'
                })
            ]
        },
        workflow: 'owned-applications'
    });

    add({
        id: 'admin-store-developer',
        title: '开发者中心',
        pageType: 'store-list',
        match: { routes: ['/admin/store/developer'], queryUrls: ['/admin/api/app/developerPlugins'] },
        primary: [{field: 'plugin_name', label: '应用名称', inlineHtml: true}, field('description', '简介')],
        media: media('icon', 'rounded', 'developer_mode'),
        status: [],
        metrics: [],
        autoMetrics: false,
        cardCta: {
            label: '管理',
            title: '应用管理',
            subtitle: '',
            offer: {
                field: 'price',
                label: '市场售价',
                format: function (value, row) {
                    var price = Number(row && row.price);
                    if (!Number.isFinite(price)) return '-';
                    return price === 0 ? '免费' : '￥' + price.toFixed(2);
                },
                benefits: [
                    {
                        label: '企业版免费',
                        tone: 'success',
                        show: function (row) {
                            return Number(row && row.price) > 0 && [1, 2].indexOf(Number(row && row.group)) >= 0;
                        }
                    },
                    {
                        label: '专业版免费',
                        tone: 'neutral',
                        show: function (row) {
                            return Number(row && row.price) > 0 && Number(row && row.group) === 1;
                        }
                    }
                ]
            }
        },
        details: [
            field('description', '应用简介'),
            field('status', '发布状态'),
            field('type', '应用类型'),
            {
                field: 'version',
                label: '当前版本',
                format: function (value, row) {
                    var version = String(row && row.version || value || '').trim().replace(/^v/i, '');
                    return version && version !== '-' ? 'v' + version : '-';
                }
            },
            field('plugin_key', '应用标识'),
            {
                field: 'web_site',
                label: '官网',
                show: function (row) {
                    var website = String(row && row.web_site || '').trim();
                    return Boolean(website && website !== '#');
                }
            }
        ],
        selection: false,
        actions: {
            primary: [action('operation:1', '上传安装包'), action('operation:2', '上传更新包')],
            more: [action('operation:0', '设置定价'), action('operation:3', '访问官网')],
            toolbar: [selector('.developerCreatePlugin', '发布应用', { role: 'primary' })]
        },
        workflow: 'developer-applications'
    });

    add({
        id: 'supply-market-index',
        title: '供货市场',
        pageType: 'catalog-list',
        match: { routes: ['/plugin/SupplyMarket/market/index'], queryUrls: ['/plugin/SupplyMarket/api/market/data'] },
        primary: {
            fields: [
                {
                    field: 'name',
                    label: '店铺',
                    format: function (value, row) {
                        return row && row.name ? row.name : value;
                    }
                },
                field('domain', '店铺域名')
            ]
        },
        media: media('logo_url', 'rounded', 'storefront'),
        status: [field('docked', '对接状态')],
        metrics: [field('product_count', '商品数'), field('probe_count', '探针数')],
        details: [field('domain', '店铺域名'), field('description', '简介')],
        actions: {
            primary: [action('operation:0', '对接店铺')],
            more: [action('operation:1', '取消对接', { danger: true })],
            toolbar: [selector('.btn-supply-refresh', '刷新市场')]
        },
        workflow: 'supply-market'
    });

    add({
        id: 'supply-market-create',
        title: '创建供货店铺',
        pageType: 'form',
        match: { routes: ['/plugin/SupplyMarket/market/create'] },
        primary: field('name', '店铺名称'),
        actions: {
            primary: [selector('button[type="submit"]', '提交审核')]
        },
        workflow: 'supply-market-form'
    });

    add({
        id: 'third-dock-site',
        title: '第三方站点',
        pageType: 'list',
        match: { routes: ['/plugin/ThirdDockManage/site/index'], queryUrls: ['/plugin/ThirdDockManage/api/site/data'] },
        primary: field('name', '站点名称'),
        media: media([], 'rounded', 'dns'),
        status: [field('connect_status', '实时连接'), field('type_name', '类型')],
        metrics: [field('balance', '缓存余额', 'success')],
        details: [field('domain', '站点地址'), field('account', '账号'), field('remark', '备注')],
        actions: {
            primary: [action('operation:0', '编辑站点')],
            more: [action('operation:2', '拉取分类'), action('operation:3', '访问站点'), action('operation:1', '删除站点', { danger: true })],
            batch: [selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })],
            toolbar: [selector('.btn-app-create, .btn-app-add', '新增站点', { role: 'primary' })]
        },
        workflow: 'third-dock-site'
    });

    add({
        id: 'third-dock-class',
        title: '分类对接',
        pageType: 'mapping-list',
        match: {
            routes: ['/plugin/ThirdDockManage/site/class'],
            queryUrlPrefixes: [/^\/plugin\/ThirdDockManage\/api\/site\/class(?:\?|$)/]
        },
        primary: field('name', '远端分类'),
        media: media(['img_url', 'image', 'icon'], 'rounded', 'category'),
        details: [field('id', '分类 ID')],
        actions: {
            primary: [action('operate:0', '新增分类')],
            batch: [selector('.btn-app-sync', '新增选中分类', { role: 'batch' })]
        },
        workflow: 'third-dock-category'
    });

    add({
        id: 'third-dock-good',
        title: '商品采集',
        pageType: 'mapping-list',
        match: { routes: ['/plugin/ThirdDockManage/good/index'], queryUrls: ['/plugin/ThirdDockManage/api/good/data'] },
        primary: field('name', '远端商品'),
        media: media('img', 'rounded', 'inventory_2'),
        status: [field('status', '远端状态')],
        metrics: [field('price', '远端零售价', 'success'), field('stock', '库存')],
        details: [field('site_name', '对接站点'), field('c_id', '远端商品 ID'), field('category', '商品分类'), field('updated_at', '最后更新')],
        actions: {
            primary: [action('operate:0', '生成商品')],
            batch: [selector('.btn-app-batch', '批量生成商品', { role: 'batch' })],
            toolbar: [
                selector('.btn-app-sync', '同步远端商品', {
                    confirm: {
                        title: '确认同步远端商品',
                        message: '同步会连接全部已启用的第三方站点，并持续更新本地商品缓存。确认现在开始吗？',
                        confirmText: '开始同步'
                    }
                }),
                selector('.btn-app-all', '生成当前全部商品')
            ]
        },
        workflow: 'third-dock-product'
    });

    add({
        id: 'third-dock-rule',
        title: '克隆规则',
        pageType: 'list',
        match: { routes: ['/plugin/ThirdDockManage/rule/index'], queryUrls: ['/plugin/ThirdDockManage/api/rule/data'] },
        primary: {
            fields: [
                {
                    field: 'site_ids_string',
                    label: '适用站点',
                    format: function (value) {
                        return value || '全部站点';
                    }
                },
                {
                    field: 'categories_string',
                    label: '匹配条件',
                    format: function (value, row) {
                        return value || (row && row.good_names_string) || '全部商品';
                    }
                }
            ]
        },
        media: media([], 'rounded', 'rule'),
        status: [
            {
                field: 'status',
                label: '规则状态',
                format: function (value, row) {
                    return String(row && row.status) === '1' ? '规则开启' : '规则关闭';
                }
            },
            {
                field: 'auto_class',
                label: '自动对应分类',
                format: function (value, row) {
                    return String(row && row.auto_class) === '1' ? '自动分类开启' : '自动分类关闭';
                }
            }
        ],
        metrics: [field('sort', '序号')],
        details: [
            field('c_site_ids', '站点 ID'),
            field('c_categories', '系统分类'),
            field('categories_ext', '额外分类'),
            field('good_names_string', '商品名条件'),
            optionalField('settings_exclude_good_names', '排除商品名'),
            optionalSwitchField('settings_cover', '覆盖配置', '已开启', '未开启'),
            optionalField('settings_category_id', '指定分类 ID'),
            {
                field: 'settings_mode',
                label: '加价模式',
                show: rowHasValue('settings_mode'),
                format: function (value) {
                    return ({0: '普通金额加价', 1: '百分比加价', 2: '阶梯复杂加价'})[String(value)] || value;
                }
            },
            optionalField('settings_mode_value', '加价数量'),
            optionalField('settings_lucky_decimal', '吉利小数'),
            optionalSwitchField('settings_sync_now', '实时同步', '已开启', '未开启'),
            optionalSwitchField('settings_sync_price', '同步价格', '已开启', '未开启'),
            optionalSwitchField('settings_sync_content', '同步详情及参数', '已开启', '未开启'),
            optionalSwitchField('settings_sync_title', '同步标题及封面图', '已开启', '未开启'),
            optionalSwitchField('settings_only_user', '强制登录', '已开启', '未开启'),
            {
                field: 'settings_use_upload',
                label: '使用图床',
                show: rowHasValue('settings_use_upload'),
                format: function (value) {
                    return ({0: '关闭', 1: '开启', 2: '全局设置', 3: '保存本地'})[String(value)] || value;
                }
            },
            optionalField('settings_content_replace', '详情文本替换'),
            {
                field: 'settings_show_stock',
                label: '展示库存',
                show: rowHasValue('settings_show_stock'),
                format: function (value) {
                    return ({0: '全局设置', 1: '关闭', 2: '开启'})[String(value)] || value;
                }
            },
            optionalSwitchField('settings_api_status', 'API 对接', '已开启', '未开启'),
            // These desktop columns repeat the concise mobile fields above.
            // Keep the underlying settings complete without showing the same
            // station/category/status values twice in the detail task page.
            {field: 'site_ids', show: function () { return false; }},
            {field: 'categories', show: function () { return false; }},
            {field: 'good_names', show: function () { return false; }},
            {field: 'status', show: function () { return false; }},
            {field: 'auto_class', show: function () { return false; }},
            {field: 'sort', show: function () { return false; }}
        ],
        actions: {
            primary: [action('operate:0', '编辑规则')],
            batch: [
                selector('.btn-app-exchange', '切换状态', { role: 'batch' }),
                selector('.btn-app-del', '批量删除', { role: 'batch', danger: true })
            ],
            toolbar: [selector('.btn-app-create, .btn-app-add', '新增规则', { role: 'primary' })]
        },
        workflow: 'third-dock-rule'
    });

    add({
        id: 'third-dock-log',
        title: '对接日志',
        pageType: 'audit-list',
        match: { routes: ['/plugin/ThirdDockManage/log/index'], queryUrls: ['/plugin/ThirdDockManage/api/log/data'] },
        primary: {
            fields: [
                {
                    field: 'trade_no',
                    label: '订单号',
                    format: function (value, row) {
                        return value || (row && row.uri) || (row && row.created_at) || '请求日志';
                    }
                },
                field('created_at', '请求时间')
            ]
        },
        media: media([], 'rounded', 'receipt_long'),
        status: [field('method', '请求方式')],
        details: [field('site_name', '站点名称'), field('uri', '请求地址'), field('parameter', '请求参数'), field('result', '结果'), field('remark', '备注'), field('created_at', '请求时间')],
        selection: false,
        actions: {}
    });

    function register(recipe) {
        if (window.AdminMobile && typeof window.AdminMobile.registerRecipe === 'function') {
            window.AdminMobile.registerRecipe(recipe);
            return;
        }

        window.AdminMobileRecipeQueue = window.AdminMobileRecipeQueue || [];
        window.AdminMobileRecipeQueue.push(recipe);
    }

    recipes.forEach(register);
}(window));
