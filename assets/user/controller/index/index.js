!function () {
    const $SwitchCategory = $(`.switch-category`), $ItemList = $(`.item-list`), categoryId = getVar("CAT_ID");


    function _PushCommodityList(data) {
        $ItemList.html("");

        if (data.length == 0) {
            layer.msg("没有商品");
            $ItemList.html(`<div style="margin-right: 10px;margin-top:10px;font-size: 1.1rem;">没有商品</div>`);
            return;
        }

        data.forEach(item => {
            const isSoldOut = item.stock == 0;
            $ItemList.append(`<a href="${!isSoldOut ? `/item/${item.id}` : `javascript:void(0);`}" class="col-12 col-md-6 col-lg-3 mb-3" data-id="${item.id}">
          <div class="acg-card ${isSoldOut ? `soldout` : ``} h-100">
            <div class="acg-thumb" style="background: url('${item.cover}') center/cover no-repeat;"></div>
            <div class="p-3">
              <div class="tags">
              <span class="badge-soft badge-soft-success">${item.delivery_way === 0 ? '自动发货' : '在线发货'}</span>
              ${item.recommend == 1 ? `<span class="badge-soft badge-soft-primary">推荐</span>` : ``}
              </div>
              <p class="goods-title">${item.name}</p>
              <div class="stat-row mb-1">
                <div class="price"><span class="unit">¥</span>${item.price}</div>
              </div>
              <div class="stat-bottom"><span>库存：${item.stock}</span><span>已售：${item.order_sold}</span></div>
            </div>
            ${isSoldOut ? `<div class="soldout-ribbon">售罄</div>` : ``}
          </div>
        </a>`);
        });
    }

    function _SwitchCategory(id, link = false) {
        $SwitchCategory.removeClass("is-primary");
        $(`a[data-id=${id}]`).addClass("is-primary");
        if (link) {
            history.pushState(null, '', `/cat/${id}`);
        }
        trade.getCommodityList({
            categoryId: id,
            done: data => {
                _PushCommodityList(data);
            }
        });
    }


    function _Search(keywords) {
        if (keywords == '') {
            layer.msg("请输入要搜索的商品名称关键词");
            return;
        }

        $SwitchCategory.removeClass("is-primary");

        trade.getCommodityList({
            keywords: keywords,
            done: data => {
                _PushCommodityList(data);
            }
        });
    }


    //初次加载
    _SwitchCategory(categoryId > 0 ? categoryId : $SwitchCategory.first().data("id"));


    $SwitchCategory.click(function () {
        if ($(this).hasClass("is-primary")) {
            return;
        }
        _SwitchCategory($(this).data("id"), true);
    });


    $('.item-search-input').on('keypress', function (e) {
        if (e.which === 13) { // 或者 e.key === "Enter"
            _Search($(this).val());
        }
    });
}();