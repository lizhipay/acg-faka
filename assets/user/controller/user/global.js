!function () {

    function _Base() {
        const shadeMobile = $('.site-mobile-shade');

        $('.layui-nav-tree a').click(() => {
            $('.net-loading').show();
        });

        shadeMobile.on('click', function () {
            $('body').removeClass('site-mobile');
        });

        $('.logout').click(function () {
            message.ask('您是否要注销登录？', function () {
                window.location.href = "/user/authentication/logout";
            });
        });

        if (util.isMobile()) {
            $(`.fly-logo`).attr("href", "javascript:void(0)").click(() => {
                $('body').addClass('site-mobile');
            });
        }
    }

    function _Pjax() {

        $(document).pjax('a[target!=_blank]', '#pjax-container', {fragment: '#pjax-container', timeout: 8000});
        $(document).on('pjax:send', function () {
            Loading.show();
        });
        $(document).on('pjax:complete', function () {
            Loading.hide();
        });
    }

    _Base();
    _Pjax();
}();