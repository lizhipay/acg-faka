!function () {
    let controllerActive = true;
    const pendingRequests = new Set();
    const pendingLoaders = new Set();
    let dashboardDataGeneration = 0;
    let weekStatisticsGeneration = 0;
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const safeAnnouncementUrl = value => {
        const url = String(value || '').trim();
        if (!url) return '';
        try {
            const parsed = new URL(url, window.location.origin);
            return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '';
        } catch (error) {
            return '';
        }
    };
    const sanitizeAnnouncementHtml = value => {
        const template = document.createElement('template');
        template.innerHTML = String(value ?? '');
        const allowedTags = new Set(['B', 'STRONG', 'SPAN', 'BR', 'EM', 'I', 'U', 'S', 'SMALL', 'MARK', 'CODE', 'FONT']);
        const dangerousTags = new Set([
            'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'TEMPLATE',
            'NOSCRIPT', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'OPTION',
            'META', 'LINK', 'BASE', 'VIDEO', 'AUDIO', 'CANVAS', 'FRAME', 'FRAMESET', 'IMG'
        ]);
        const normalizeColor = value => {
            const probe = document.createElement('span');
            probe.style.color = String(value ?? '').trim();
            return probe.style.color;
        };
        const walk = node => {
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                const tag = String(child.tagName || '').toUpperCase();
                if (!allowedTags.has(tag)) {
                    if (dangerousTags.has(tag)) {
                        child.remove();
                    } else {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                    }
                    return;
                }

                const color = normalizeColor(child.style.color || (tag === 'FONT' ? child.getAttribute('color') : ''));
                Array.from(child.attributes).forEach(attribute => child.removeAttribute(attribute.name));
                if (color) child.style.color = color;
                walk(child);
            });
        };
        walk(template.content);
        return template.innerHTML.trim();
    };
    const trackRequest = request => {
        if (!request || typeof request.always !== 'function') return request;
        pendingRequests.add(request);
        request.always(() => pendingRequests.delete(request));
        return request;
    };
    const openLoader = () => {
        const index = layer.load(2, {shade: ['0.3', '#fff']});
        pendingLoaders.add(index);
        return index;
    };
    const closeLoader = index => {
        if (!pendingLoaders.delete(index)) return;
        layer.close(index);
    };
    const requestStateTarget = target => typeof target === 'string' ? document.querySelector(target) : target;
    const clearRequestState = target => {
        const host = requestStateTarget(target);
        if (!host) return;
        host.replaceChildren();
        host.hidden = true;
    };
    const renderRetryState = (target, text, retry) => {
        const host = requestStateTarget(target);
        if (!host) return;
        const state = document.createElement('div');
        const icon = document.createElement('span');
        const message = document.createElement('span');
        const button = document.createElement('button');
        state.className = 'dashboard-request-state';
        icon.className = 'material-icons-outlined';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = 'cloud_off';
        message.className = 'dashboard-request-state__message';
        message.textContent = String(text || '加载失败，请重试');
        button.type = 'button';
        button.className = 'btn btn-sm btn-light-primary dashboard-request-state__retry';
        button.textContent = '重新加载';
        button.addEventListener('click', () => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = '正在重试…';
            retry();
        }, {once: true});
        state.append(icon, message, button);
        host.replaceChildren(state);
        host.hidden = false;
    };
    const _AD_HTML = `<div class="md-ad-item">
        <a href="[url]" [target] class="md-ad-item__title">[title]</a>
        <div class="md-ad-item__time"><i class="fa-duotone fa-regular fa-clock"></i>[create_time]</div>
    </div>`;

    function loadAd() {
        const $adHandle = $('.ad-html');
        // 加载公告数据
        trackRequest($.get("/admin/api/app/ad", res => {
            if (!controllerActive) return;
            if (res.code != 200) {
                renderRetryState($adHandle[0], res.msg || '公告加载失败，请重试', loadAd);
                return;
            }

            if (!Array.isArray(res.data) || res.data.length === 0) {
                $adHandle.html('<div class="text-center text-muted py-4">暂无公告</div>');
                return;
            }

            let html = "";
            res.data.forEach(item => {
                const url = safeAnnouncementUrl(item.url);
                const title = sanitizeAnnouncementHtml(item.title) || '公告';
                html += _AD_HTML.replace("[title]", () => title)
                    .replace("[create_time]", () => escapeHtml(item.create_date))
                    .replace("[url]", () => escapeHtml(url || '#'))
                    .replace("[target]", () => url ? 'target="_blank" rel="noopener noreferrer"' : 'aria-disabled="true"');
            });
            $adHandle.html(html);
        }).fail((xhr, status) => {
            if (!controllerActive || status === 'abort') return;
            renderRetryState($adHandle[0], '网络异常，公告加载失败', loadAd);
        }));
    }

    function initAnnouncementDisclosure() {
        const card = document.querySelector('.dashboard-announcements__card');
        const button = card?.querySelector('.dashboard-announcements__toggle');
        const icon = button?.querySelector('.material-icons-outlined');
        if (!card || !button || !icon) return;

        const storageKey = 'admin-dashboard-announcements-collapsed';
        let collapsed = false;
        try {
            collapsed = window.localStorage.getItem(storageKey) === '1';
        } catch (error) {}

        const render = () => {
            card.classList.toggle('is-collapsed', collapsed);
            button.setAttribute('aria-expanded', String(!collapsed));
            button.setAttribute('aria-label', collapsed ? '展开官方公告' : '收起官方公告');
            icon.textContent = collapsed ? 'expand_more' : 'expand_less';
        };

        button.addEventListener('click', () => {
            collapsed = !collapsed;
            render();
            try {
                window.localStorage.setItem(storageKey, collapsed ? '1' : '0');
            } catch (error) {}
        });
        render();
    }

    // 获取仪表板数据
    function loadDashboardData(type) {
        const generation = ++dashboardDataGeneration;
        const loaderIndex = openLoader();
        trackRequest($.post("/admin/api/dashboard/data", {type: type}, res => {
            closeLoader(loaderIndex);
            if (!controllerActive || generation !== dashboardDataGeneration) return;
            if (res.code == 200) {
                clearRequestState('.dashboard-data-feedback');
                const n = v => Number(v || 0).toLocaleString('en-US');
                const m = v => '￥' + Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                $('.turnover').text(m(res.data.turnover));
                $('.order_num').text(n(res.data.order_num));
                $('.business').text(n(res.data.business));
                $('.cash_status_0').text(n(res.data.cash_status_0));
                $('.cash_money_status_1').text(m(res.data.cash_money_status_1));
                $('.user_register_num').text(n(res.data.user_register_num));
                $('.order_profit').text(m(res.data.profit));
                $('.recharge_amount').text(m(res.data.recharge_amount));
                $('.divide_amount').text(m(res.data.divide_amount));
                $('.rebate').text(m(res.data.rebate));
                $('.cost').text(m(res.data.cost));
                $('.online_amout').text(m(res.data.online_amout));
                return;
            }
            renderRetryState('.dashboard-data-feedback', res.msg || '经营数据加载失败，请重试', () => loadDashboardData(type));
        }).fail((xhr, status) => {
            closeLoader(loaderIndex);
            if (!controllerActive || status === 'abort' || generation !== dashboardDataGeneration) return;
            renderRetryState('.dashboard-data-feedback', '网络异常，经营数据加载失败', () => loadDashboardData(type));
        }));
    }

    let _chart = null, _chartData = null, _chartObserver = null, _chartResizeObserver = null;
    const _resizeChart = () => {
        if (_chart && ! _chart.isDisposed()) {
            _chart.resize();
        }
    };

    function destroyDashboard() {
        if (!controllerActive) return;
        controllerActive = false;
        dashboardDataGeneration++;
        weekStatisticsGeneration++;
        pendingRequests.forEach(request => {
            try { request.abort(); } catch (error) {}
        });
        pendingRequests.clear();
        pendingLoaders.forEach(index => layer.close(index));
        pendingLoaders.clear();
        $('.dashboard-data-type').off('.mdDashboard');
        window.removeEventListener('resize', _resizeChart);
        _chartObserver?.disconnect();
        _chartObserver = null;
        _chartResizeObserver?.disconnect();
        _chartResizeObserver = null;
        if (_chart && !_chart.isDisposed()) {
            _chart.dispose();
        }
        _chart = null;
        _chartData = null;
    }

    function _chartTheme() {
        const s = getComputedStyle(document.documentElement);
        const g = k => s.getPropertyValue(k).trim();
        return {
            profit: g('--md-success'), trade: g('--md-primary'),
            cash: g('--md-secondary'), recharge: g('--md-warning'),
            text: g('--md-on-surface-med'), line: g('--md-divider')
        };
    }

    function _renderChart() {
        const el = document.getElementById('statistics');
        if (!el || !_chartData) return;
        if (!_chart) _chart = echarts.init(el);
        if (!_chartResizeObserver && typeof ResizeObserver === 'function') {
            _chartResizeObserver = new ResizeObserver(_resizeChart);
            _chartResizeObserver.observe(el);
        }
        const c = _chartTheme();
        const S = (name, data) => ({
            name, type: 'line', smooth: true,
            symbol: 'circle', symbolSize: 5, showSymbol: false,
            lineStyle: {width: 2}, areaStyle: {opacity: 0.14},
            emphasis: {focus: 'series'}, data
        });
        _chart.setOption({
            color: [c.profit, c.trade, c.cash, c.recharge],
            tooltip: {trigger: 'axis', axisPointer: {type: 'cross'}},
            legend: {data: ['盈利', '交易金额', '提现', '充值'], icon: 'roundRect', textStyle: {color: c.text, fontSize: 12}},
            grid: {left: '2%', right: '3%', bottom: '2%', top: 48, containLabel: true},
            xAxis: [{
                type: 'category', boundaryGap: false, data: _chartData.week,
                axisLabel: {color: c.text, fontSize: 10},
                axisLine: {lineStyle: {color: c.line}}, axisTick: {show: false}
            }],
            yAxis: [{
                type: 'value', axisLabel: {color: c.text, fontSize: 10},
                splitLine: {lineStyle: {color: c.line}}, axisLine: {show: false}
            }],
            series: [
                S('盈利', _chartData.series.profit),
                S('交易金额', _chartData.series.trade),
                S('提现', _chartData.series.cash),
                S('充值', _chartData.series.recharge)
            ]
        }, true);
    }

    function loadWeekStatistics() {
        const generation = ++weekStatisticsGeneration;
        const chartElement = document.getElementById('statistics');
        // 加载周统计数据
        trackRequest($.get("/admin/api/dashboard/weekStatistics", res => {
            if (!controllerActive || generation !== weekStatisticsGeneration) return;
            if (res.code != 200) {
                if (chartElement) chartElement.hidden = true;
                renderRetryState('.dashboard-chart-feedback', res.msg || '趋势数据加载失败，请重试', loadWeekStatistics);
                return;
            }
            clearRequestState('.dashboard-chart-feedback');
            if (chartElement) chartElement.hidden = false;
            _chartData = res.data;
            _renderChart();
            // 主题切换时重新着色
            if (!_chartObserver) {
                _chartObserver = new MutationObserver(() => _renderChart());
                _chartObserver.observe(document.documentElement, {attributes: true, attributeFilter: ['data-theme']});
            }
            window.removeEventListener('resize', _resizeChart);
            window.addEventListener('resize', _resizeChart, {passive: true});
        }).fail((xhr, status) => {
            if (!controllerActive || status === 'abort' || generation !== weekStatisticsGeneration) return;
            if (chartElement) chartElement.hidden = true;
            renderRetryState('.dashboard-chart-feedback', '网络异常，趋势数据加载失败', loadWeekStatistics);
        }));
    }

    initAnnouncementDisclosure();
    loadAd();
    loadDashboardData(0);
    loadWeekStatistics();

    $('.dashboard-data-type').off('.mdDashboard').on('change.mdDashboard', function () {
        loadDashboardData(this.value);
    });

    $(document)
        .off('pjax:beforeReplace.mdDashboard')
        .one('pjax:beforeReplace.mdDashboard', destroyDashboard);
}();
