!function () {
    const _AD_HTML = `<div class="d-flex align-items-start position-relative mb-3 p-3 rounded bg-light bg-opacity-50">
        <div class="position-absolute top-0 start-0 rounded h-100 bg-primary" style="width: 3px;"></div>
        <div class="ms-3 flex-grow-1">
            <a href="[url]" [target] class="fw-bold text-decoration-none text-dark d-block mb-1">[title]</a>
            <div class="text-muted small">[create_time]</div>
        </div>
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
                $('.turnover').text("￥" + res.data.turnover);
                $('.order_num').text(res.data.order_num);
                $('.business').text(res.data.business);
                $('.cash_status_0').text(res.data.cash_status_0);
                $('.cash_money_status_1').text("￥" + res.data.cash_money_status_1);
                $('.user_register_num').text(res.data.user_register_num);
                $('.user_login_num').text(res.data.user_login_num);
                $('.recharge_amount').text("￥" + res.data.recharge_amount);
                $('.divide_amount').text("￥" + res.data.divide_amount);
                $('.rebate').text("￥" + res.data.rebate);
                $('.cost').text("￥" + res.data.cost);
                $('.online_amout').text("￥" + res.data.online_amout);
            }
        });
    }

    function loadWeekStatistics() {
        // 加载周统计数据
        $.get("/admin/api/dashboard/weekStatistics", res => {
            if (res.code != 200) {
                layer.msg(res.msg);
                return;
            }

            let statistics = echarts.init(document.getElementById('statistics'));
            let option = {
                tooltip: {
                    trigger: 'axis',
                    axisPointer: {
                        type: 'cross',
                        label: {
                            backgroundColor: '#6a7985'
                        }
                    }
                },
                legend: {
                    data: ['交易金额', '提现', '充值'],
                    textStyle: {
                        fontSize: 12
                    }
                },
                toolbox: {
                    feature: {
                        saveAsImage: {}
                    }
                },
                grid: {
                    left: '3%',
                    right: '4%',
                    bottom: '3%',
                    containLabel: true
                },
                xAxis: [
                    {
                        type: 'category',
                        boundaryGap: false,
                        data: res.data.week,
                        axisLabel: {
                            fontSize: 10
                        }
                    }
                ],
                yAxis: [
                    {
                        type: 'value',
                        axisLabel: {
                            fontSize: 10
                        }
                    }
                ],
                series: [
                    {
                        name: '交易金额',
                        type: 'line',
                        stack: 'Total',
                        label: {
                            show: true,
                            position: 'top',
                            fontSize: 10
                        },
                        areaStyle: {
                            opacity: 0.3
                        },
                        emphasis: {
                            focus: 'series'
                        },
                        data: res.data.series.trade,
                        itemStyle: {
                            color: '#007bff'
                        }
                    },
                    {
                        name: '提现',
                        type: 'line',
                        stack: 'Total',
                        areaStyle: {
                            opacity: 0.3
                        },
                        emphasis: {
                            focus: 'series'
                        },
                        data: res.data.series.cash,
                        itemStyle: {
                            color: '#28a745'
                        }
                    },
                    {
                        name: '充值',
                        type: 'line',
                        stack: 'Total',
                        areaStyle: {
                            opacity: 0.3
                        },
                        emphasis: {
                            focus: 'series'
                        },
                        data: res.data.series.recharge,
                        itemStyle: {
                            color: '#dc3545'
                        }
                    }
                ]
            };
            statistics.setOption(option);

            // 响应式处理
            window.addEventListener('resize', function () {
                statistics.resize();
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