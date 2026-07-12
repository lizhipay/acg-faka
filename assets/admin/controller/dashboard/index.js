!function () {
    const _AD_HTML = `<div class="md-ad-item">
        <a href="[url]" [target] class="md-ad-item__title">[title]</a>
        <div class="md-ad-item__time"><i class="fa-duotone fa-regular fa-clock"></i>[create_time]</div>
    </div>`;

    function loadAd() {
        const $adHandle = $('.ad-html');
        // 加载公告数据
        $.get("/admin/api/app/ad", res => {
            if (res.code != 200) {
                $adHandle.html('<div class="text-center text-muted py-4">暂无公告</div>');
                return;
            }

            if (res.data.length === 0) {
                $adHandle.html('<div class="text-center text-muted py-4">暂无公告</div>');
                return;
            }

            let html = "";
            res.data.forEach(item => {
                html += _AD_HTML.replace("[title]", item.title)
                    .replace("[create_time]", item.create_date)
                    .replace("[url]", item.url ? item.url : "javascript:void(0)")
                    .replace("[target]", item.url ? 'target="_blank"' : '');
            });
            $adHandle.html(html);
        });
    }

    // 获取仪表板数据
    function loadDashboardData(type) {
        let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
        $.post("/admin/api/dashboard/data", {type: type}, res => {
            layer.close(loaderIndex);
            if (res.code == 200) {
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
            }
        });
    }

    let _chart = null, _chartData = null, _chartObserver = null;

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
        const c = _chartTheme();
        const S = (name, data) => ({
            name, type: 'line', stack: 'Total', smooth: true,
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
        // 加载周统计数据
        $.get("/admin/api/dashboard/weekStatistics", res => {
            if (res.code != 200) {
                layer.msg(res.msg);
                return;
            }
            _chartData = res.data;
            _renderChart();
            // 主题切换时重新着色
            if (!_chartObserver) {
                _chartObserver = new MutationObserver(() => _renderChart());
                _chartObserver.observe(document.documentElement, {attributes: true, attributeFilter: ['data-theme']});
            }
            window.addEventListener('resize', function () {
                if (_chart) _chart.resize();
            });
        });
    }

    loadAd();
    loadDashboardData(0);
    loadWeekStatistics();

    $('.dashboard-data-type').change(function (e) {
        loadDashboardData(this.value);
    });
}();