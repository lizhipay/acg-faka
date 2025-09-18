!function () {
    function _QueryOrders(keywords) {
        util.post({
            url: "/user/api/index/query",
            data: {
                keywords: keywords,
                page: 1,
                limit: 10
            },
            loader: false,
            done: res => {
                _HideLoading();

                if (res?.data?.total == 0) {
                    _ShowNoResults();
                    return;
                }

                _ShowResults(res?.data?.list ?? [])
            },
            error: () => {
                _HideLoading();
                _ShowNoResults();
            },
            fail: () => {
                _HideLoading();
                _ShowNoResults();
            }
        });
    }


    // 显示加载状态
    function _ShowLoading() {
        $('.order-results').hide();
        $('.no-results').hide();
        $('.loading-state').show();
    }

    // 隐藏加载状态
    function _HideLoading() {
        $('.loading-state').hide();
    }

    // 显示无结果
    function _ShowNoResults() {
        $('.order-results').hide();
        $('.no-results').show();
    }

    function _GetStatusText(status) {
        const statusMap = {
            0: '<i class="fa-duotone fa-regular fa-clock"></i> 待付款',
            1: '<i class="fa-duotone fa-regular fa-circle-check"></i> 已付款'
        };
        return statusMap[status] || '未知状态';
    }

    // 获取状态样式类
    function _GetStatusClass(status) {
        const classMap = {
            0: 'pending',
            1: 'paid',
            2: 'shipped',
            3: 'waiting-shipment'
        };
        return classMap[status] || 'pending';
    }

    // 获取发货状态文本
    function _GetShipmentStatusText(status) {
        const shipmentMap = {
            0: '<span class="shipment-badge shipment-waiting">等待发货</span>',
            1: '<span class="shipment-badge shipment-paid">已发货</span>'
        };
        return shipmentMap[status] || shipmentMap[0];
    }

    function _CreateOrderItem(order) {
        let sku = ``, cardContent = ``;

        if (order.race) {
            sku += `<span class="goods-sku a-badge a-badge-success">商品类型: ${order.race}</span>`;
        }

        if (!util.isEmptyOrNotJson(order?.sku)) {
            for (const skuKey in order?.sku) {
                sku += `<span class="goods-sku a-badge a-badge-primary">${skuKey}: ${order?.sku[skuKey]}</span>`;
            }
        }

        sku += `<span class="goods-sku a-badge a-badge-warning">数量: ${order.card_num}</span>`;

        if (order.status == 1) {
            if (order.password === true) {
                cardContent = `<div class="card-password-section card-content-${order.trade_no}">
        <div class="password-form">
          <div class="input-group">
            <input type="password" class="form-control card-password-input passin-${order.trade_no}" placeholder="请输入查询密码">
            <button type="button" class="btn btn-primary view-card-btn" data-no="${order.trade_no}">
              <i class="fa-duotone fa-regular fa-eye me-2"></i>查看卡密
            </button>
          </div>
        </div>
      </div>

      <div class="card-loading loading-${order.trade_no}" style="display: none;">
        <div class="loading-content">
          <i class="fa-duotone fa-regular fa-spinner-third icon-spin"></i>
          <span>正在解密数据...</span>
        </div>
      </div>`;
            } else {
                cardContent = `<div class="card-content-no-password"><div class="card-display">${order.secret}</div></div>${order?.commodity?.leave_message ? `<div class="mt-3">${order?.commodity?.leave_message}</div>` : ""}`;
            }

            cardContent = `<div class="card-section">
                <div class="card-header">
                    <div class="card-title shipment-content" style="font-size: 1.2rem">
                         <div class="shipment-title"><i class="fa-duotone fa-regular fa-gift me-1"></i>宝贝内容</div>
                         <div class="shipment-status">${_GetShipmentStatusText(order.delivery_status)} </div>
                    </div>
                </div>
                ${cardContent}
            </div>`;
        }

        const template = `<div class="order-item">
    <div class="order-header">
      <div class="order-left">
        <div class="order-status">
          <span class="status-badge status-${_GetStatusClass(order.status)}">${_GetStatusText(order.status)}</span>
        </div>
        <div class="order-basic">
          <div class="order-no">#<span class="order-no-text">${order.trade_no}</span></div>
          <div class="order-time">下单时间：<span class="order-time-text">${order.create_time}</span></div>
          <div class="payment-time">付款时间：<span class="payment-time-text">${order.pay_time ?? "-"}</span></div>
          <div class="payment-dst">支付方式：<span class="payment-method"><img src="${order?.pay?.icon}" alt="支付方式" class="payment-icon"><span class="payment-name">${order?.pay?.name}</span></span></div>
        </div>
      </div>
      <div class="order-right">
        <div class="order-amount">
          <span class="amount-label">订单金额</span>
          <span class="amount-value">¥<span class="amount-number">${order.amount}</span></span>
        </div>
       
      </div>
    </div>

    <!-- 商品信息 -->
    <div class="goods-section">
      <div class="goods-thumb">
        <img src="${order?.commodity?.cover}" alt="商品图片" class="goods-image">
      </div>
      <div class="goods-details">
        <h6 class="goods-name">${order?.commodity?.name}</h6>
        <div class="goods-meta">
          ${sku}
        </div>
       
      </div>
    </div>

       ${cardContent}
  </div>`;

        return template;
    }

    function _ShowResults(orders) {
        $('.order-results').show();
        $('.no-results').hide();

        const orderList = $('.order-list');
        orderList.empty();

        orders.forEach(function (order) {
            const orderItem = _CreateOrderItem(order);
            orderList.append(orderItem);
        });
    }

    function _ShowPasswordLoading(tradeNo) {
        $(`.loading-${tradeNo}`).show();
    }

    function _ShowPasswordInput(tradeNo) {
        $(`.card-content-${tradeNo}`).show();
    }

    function _HidePasswordInput(tradeNo) {
        $(`.card-content-${tradeNo}`).hide();
    }

    function _HidePasswordLoading(tradeNo) {
        $(`.loading-${tradeNo}`).hide();
    }

    function _ShowCardContent(tradeNo, content, leaveMessage = null) {
        $(`.card-content-${tradeNo}`).html(`<div class="card-content">
          <div class="card-display">${content}</div>
        </div>${leaveMessage ? `<div class="mt-3">${leaveMessage}</div>` : ""}`).show();
    }

    $(document).off('click', '.view-card-btn').on('click', '.view-card-btn', function () {
        const tradeNo = $(this).data("no");
        const pass = $(`.passin-${tradeNo}`).val().trim();

        if (!pass) {
            message.error("请输入密码");
            return;
        }

        _ShowPasswordLoading(tradeNo);
        _HidePasswordInput(tradeNo);

        util.post({
            url: "/user/api/index/secret",
            data: {
                tradeNo: tradeNo,
                password: pass
            },
            loader: false,
            done: res => {
                _HidePasswordLoading(tradeNo);
                _ShowCardContent(tradeNo, res?.data?.secret, res?.data?.leave_message);
            },
            error: res => {
                message.error(res.msg ?? "未知错误");
                _HidePasswordLoading(tradeNo);
                _ShowPasswordInput(tradeNo);
            },
            fail: () => {
                message.error("网络错误");
                _HidePasswordLoading(tradeNo);
                _ShowPasswordInput(tradeNo);
            }
        });
    });


    $('.order-query-form').on('submit', function (e) {
        e.preventDefault();

        const formData = new FormData($('.order-query-form')[0]);
        const data = Object.fromEntries(formData.entries());
        const keywords = data?.keywords?.trim();

        if (!keywords) {
            message.error("请输入联系方式或订单号再查询");
            return;
        }


        _ShowLoading();
        _QueryOrders(keywords);
    });


    if (/^\d{18}$/.test(util.getParam("tradeNo"))) {
        $('.btn-search-query').click();
    }
}();