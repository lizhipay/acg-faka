;(function ($) {
    $.fn.extend({
        "sliderBar": function (options) {
            // 使用jQuery.extend 覆盖插件默认参数
            var opts = $.extend(
                {},
                $.fn.sliderBar.defalutPublic,
                options
            );

            // 这里的this 就是 jQuery对象，遍历页面元素对象
            // 加个return可以链式调用
            return this.each(function () {
                //获取当前元素 的this对象 
                var $this = $(this);

                $this.data('open', opts.open);

                privateMethods.initSliderBarCss($this, opts);

                switch (opts.position) {
                    case 'right' :
                        privateMethods.showAtRight($this, opts);
                        break;
                    case 'left'  :
                        privateMethods.showAtLeft($this, opts);
                        break;
                }

            });
        }
    });

    // 默认公有参数
    $.fn.sliderBar.defalutPublic = {
        open: false,           // 默认是否打开，true打开，false关闭
        top: 200,             // 距离顶部多高
        width: 260,           // body内容宽度
        height: 200,          // body内容高度
        theme: 'green',       // 主题颜色
        position: 'left'      // 显示位置，有left和right两种
    }

    var privateMethods = {
        initSliderBarCss: function (obj, opts) {
            obj.css({
                'width': opts.width + 0 + 'px',
                'height': opts.height + 0 + 'px',
                'top': '5px',
                'position': 'absolute',
                'font-family': 'Microsoft Yahei',
                'z-index': '9999',
            }).find('.body').css({
                'width': opts.width + 'px',
                'height': opts.height + 'px',
                'position': 'relative',
                'padding': '10px',
                'overflow-x': 'hidden',
                'overflow-y': 'auto',
                'font-family': 'Microsoft Yahei',
                'font-size': '12px',
                'background-color': '#ffffffb8',
                'display': 'none',
                'border-radius': '5px',
                'font-weight': 'bold',
            });

            var titleCss = {
                'width': '22px',
                'height': '135px',
                'position': 'absolute',
                'top': '-1px',
                'display': 'block',
                'background-color': opts.theme,
                'font-size': '13px',
                'padding': '8px 4px 0px 5px',
                'color': '#fff',
                'cursor': 'pointer',
                'border-radius': '5px',
                'font-weight': 'bold',
            }

            obj.find('.title').css(titleCss).find('i').css({
                'font-size': '15px'
            });
        },
        showAtLeft: function (obj, opts) {
            if (!opts.open) {
                obj.css({right: '0px'});
                obj.find('.title').css('right', '-25px').find('i').attr('class', 'fa fa-chevron-circle-left');
            } else {
                obj.css({right: -opts.width - 22 + 'px'});
                obj.find('.title').css('right', '-25px').find('i').attr('class', 'fa fa-chevron-circle-right');
            }

            obj.find('.title').click(function () {
                if (!obj.data('open')) {
                    obj.find(".body").show();
                    obj.animate({right: -opts.width - 2 + 'px'}, 500);
                    $(this).find('i').attr('class', 'fa fa-chevron-circle-right');
                } else {
                    obj.find(".body").hide();
                    obj.animate({right: '0px'}, 500);
                    $(this).find('i').attr('class', 'fa fa-chevron-circle-left');
                }
                obj.data('open', obj.data('open') == true ? false : true);
            });
        },
        showAtRight: function (obj, opts) {
            if (opts.open) {
                obj.css({right: '0px'});
                obj.find('.title').css('right', opts.width + 20 + 'px').find('i').attr('class', 'fa fa-chevron-circle-right');
            } else {
                obj.css({right: '25px'});
                obj.find('.title').css('right', opts.width + 20 + 'px').find('i').attr('class', 'fa fa-chevron-circle-left');
            }

            obj.find('.title').click(function () {
                if (obj.data('open')) {
                    obj.animate({right: -opts.width - 22 + 'px'}, 500);
                    $(this).find('i').attr('class', 'fa fa-chevron-circle-left');
                } else {
                    obj.animate({right: '0px'}, 500);
                    $(this).find('i').attr('class', 'fa fa-chevron-circle-right');
                }
                obj.data('open', obj.data('open') == true ? false : true);
            });
        }
    };
})(jQuery)
