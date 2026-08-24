!function () {
    const $SwitchCategory = $(`.switch-category`), $ItemList = $(`.item-list`), categoryId = getVar("CAT_ID");


    function _PushCommodityList(data) {
        $ItemList.html("");

        if (data.length == 0) {
            layer.msg(i18n("没有商品"));
            $ItemList.html(`<div style="margin-right: 10px;margin-top:10px;font-size: 1.1rem;">${i18n('没有商品')}</div>`);
            return;
        }

        data.forEach(item => {
            const isSoldOut = item.stock == 0;
            $ItemList.append(`<a href="${!isSoldOut ? `/item/${item.id}` : `javascript:void(0);`}" class="col-12 col-md-6 col-lg-3 mb-3" data-id="${item.id}">
          <div class="acg-card ${isSoldOut ? `soldout` : ``} h-100">
            <div class="acg-thumb" style="background: url('${item.cover}') center/cover no-repeat;"></div>
            <div class="p-3">
              <div class="tags">
              ${_CommodityTags(item)}
              <span class="badge-soft badge-soft-success">${item.delivery_way === 0 ? i18n('自动发货') : i18n('在线发货')}</span>
              ${item.recommend == 1 ? `<span class="badge-soft badge-soft-primary">${i18n('推荐')}</span>` : ``}
              </div>
              <p class="goods-title">${i18n(item.name)}</p>
              <div class="stat-row mb-1">
                <div class="price"><span class="unit">${format.currencySymbol()}</span>${item.price}</div>
              </div>
              <div class="stat-bottom"><span>${i18n('库存：')}${item.stock}</span><span>${i18n('已售：')}${item.order_sold}</span></div>
            </div>
            ${isSoldOut ? `<div class="soldout-ribbon">${i18n('售罄')}</div>` : ``}
          </div>
        </a>`);
        });
    }

    //商品标签（#807）：后台配置的彩色标签，排在系统徽章之前
    function _CommodityTags(item) {
        const tags = Array.isArray(item && item.tags) ? item.tags : [];
        if (!tags.length) return '';
        const esc = v => $('<i>').text(String(v == null ? '' : v)).html();
        return tags.map(t => {
            const text = String((t && t.text) || '').trim();
            if (!text) return '';
            const color = String((t && t.color) || 'red');
            return `<span class="acg-tag acg-tag--${esc(color)}">${esc(text)}</span>`;
        }).join('');
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
            layer.msg(i18n("请输入要搜索的商品名称关键词"));
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