const treasure = new class Treasure {
    show(tradeNo, secret) {
        layer.open({
            type: 1,
            title: `${util.icon("fa-duotone fa-regular fa-baby-carriage")} 您购买的宝贝信息:`,
            area: util.isMobile() ? ["100%", "100%"] : ['420px', '420px'],
            content: '<textarea class="layui-input" style="padding: 15px;height: 98%;width: 100%;border: none;overflow-x: hidden;">' + secret + '</textarea>',
            btn: ['<span style="color:#aaaaaa;">查看更多信息/下载</span>'],
            yes: function () {
                window.open('/user/personal/purchaseRecord?tradeNo=' + tradeNo);
            }
        });
    }
}