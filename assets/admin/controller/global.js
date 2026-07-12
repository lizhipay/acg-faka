!function () {
    let _LatestVersion, _LocalVersion, _IsLatestVersion;

    function _LoadStoreUserInfo() {
        util.post({
            url: "/admin/api/app/service",
            loader: false,
            error: false,
            fail: false,
            done: res => {
                const $StoreText = $(`.store-text`);

                if (res?.data?.id <= 0) {
                    return;
                }


                let html = format.badge(`<i class="fa-duotone fa-regular fa-user"></i> ${res.data.username}`, 'a-badge-light edit-store-user');

                if (res.data.level === 0) {
                    html += format.badge(`<i class="fa-duotone fa-regular fa-crown"></i> 專業版`, 'a-badge a-badge-primary hide-mobile');
                }

                if (res.data.level === 1) {
                    html += format.badge(`<i class="fa-duotone fa-regular fa-crown"></i> 企業版`, 'a-badge a-badge-success');
                }

                if (res.data.developer == 1) {
                    html += format.badge(`<span class="material-icons-outlined md-top-pill__icon" aria-hidden="true">terminal</span> 開發者`, 'a-badge a-badge-success hide-mobile');
                    html += format.badge(`<span class="material-icons-outlined md-top-pill__icon" aria-hidden="true">account_balance_wallet</span> ${res.data.balance}`, 'a-badge a-badge-warning hide-mobile');
                }

                $StoreText.html(format.badgeGroup(html));


                $(`.edit-store-user`).click(() => {
                    component.popup({
                        submit: '/admin/api/app/editPassword',
                        tab: [
                            {
                                name: `<i class="fa-duotone fa-regular fa-user-pen"></i> 修改应用商店账户密码`,
                                form: [
                                    {
                                        title: false,
                                        name: "tips_page",
                                        type: "custom",
                                        complete: (form, dom) => {
                                            dom.html(`<div class="">
                  <div class="alert alert-warning d-flex align-items-center" role="alert">
                    <p class="mb-0">
                    <i class="fa-duotone fa-regular fa-circle-exclamation"></i> 旧密码输入错误超过10次，将会永久封禁账户，请慎重操作。
                    </p>
                  </div>`);
                                        }
                                    },
                                    {title: "旧密码", name: "old_password", type: "password", placeholder: "请输入旧密码"},
                                    {
                                        title: "新密码",
                                        name: "new_password",
                                        type: "input",
                                        placeholder: "6位字符以上"
                                    },
                                    {
                                        title: "踢除其他登录",
                                        name: "kick",
                                        tips: "如果开启此功能，当您修改密码时，所有已登录服务器将被强制下线，必须使用新密码重新登录。建议仅在账号可能被他人盗用时使用，平时无需勾选。",
                                        type: "switch",
                                        placeholder: "踢掉所有已登录服务器|保持现状"
                                    },
                                ]
                            }
                        ],
                        autoPosition: true,
                        height: "auto",
                        maxmin: false,
                        confirmText: `${util.icon("fa-duotone fa-regular fa-rotate")} 确认修改`,
                        width: "320px"
                    });
                });
            }
        });
    }

    function _HandleUpdate(isUpdate) {
        component.popup({
            submit: isUpdate ? () => {
                util.post("/admin/api/app/update", () => {
                    message.success("更新已完成");
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                });
            } : false,
            confirmText: `<i class="fa-duotone fa-regular fa-arrows-rotate"></i>立即更新`,
            width: "620px",
            height: "720px",
            tab: [
                {
                    name: `<i class="fa-duotone fa-regular fa-code"></i> 版本列表`,
                    form: [
                        {
                            title: false,
                            name: "custom",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="layui-timeline version-list"></div>`);
                                const $versionList = dom.find(".version-list");
                                util.post({
                                    url: "/admin/api/app/versions", done: res => {
                                        res.data.forEach(item => {
                                            let beta = item?.beta == 1 ? `<b class="text-primary">beta</b>` : "<b class='text-success'>stable</b>";

                                            $versionList.append(`<div class="layui-timeline-item">
                                                                        <i class="layui-icon layui-timeline-axis">&#xe63f;</i>
                                                                        <div class="layui-timeline-content">
                                                                          <h3 class="layui-timeline-title fs-5" style="color: ${item.version == _LocalVersion ? "#2fcf94" : "#f98ee7"};">${item.version} ${beta} ${item.version == _LocalVersion ? "←" : ''}</h3>
                                                                          <p>${item.content}</p>
                                                                          <p style="margin-top: 10px;color: #867d00;font-size: 12px;">source: <a class="text-primary" href="${item.update_url}" target="_blank">${item.version}.zip</a></p>
                                                                          <p class="fw-normal" style="font-size: 12px;color: #009a25;">${item.update_date}</p>
                                                                        </div>
                                                                      </div>`);
                                        });
                                    }
                                });

                            }
                        }
                    ]
                }
            ],
            maxmin: false,
            shadeClose: true
        });
    }

    function _LodLatest() {
        util.post({
            url: "/admin/api/app/latest",
            loader: false,
            done: res => {
                _LatestVersion = res.data.version;
                _LocalVersion = res.data.local;
                _IsLatestVersion = res.data.latest;

                $('.local-version').html(res.data.local);

                if (_IsLatestVersion) {
                    $('.latest-version').css("color", "green").html("[ Latest ]");
                } else {
                    $('.latest-version').css("color", "red").html(`[ 更新 v${res.data.version} ]`);
                    let cache = localStorage.getItem(res.data.version);
                    //第一次检测到版本，主动打开更新窗口
                    if (!cache) {
                        _HandleUpdate(true);
                        localStorage.setItem(res.data.version, true);
                    }
                }

                $('.latest-update').click(function () {
                    _HandleUpdate(!_IsLatestVersion);
                });
            },
            error: () => {
                $('.latest-update').css("color", "red").html("版本检查失败");
            },
            fail: () => {
                $('.latest-update').css("color", "red").html("版本检查失败");
            }
        });
    }

    function _LoadPluginUpdates() {
        $.get("/admin/api/app/getUpdates", res => {
            if (res.code != 200) {
                return;
            }

            if (res.data && Object.keys(res.data).length > 0) {
                localStorage.setItem("pluginVersions", JSON.stringify(res.data));
            }

            if (res.themePlugin > 0){
                $(`.theme-update`).html(`${res.themePlugin}个更新`).show();
            }

            if (res.generalPlugin > 0){
                $(`.general-update`).html(`${res.generalPlugin}个更新`).show();
            }

            if (res.payPlugin > 0){
                $(`.payPlugin-update`).html(`${res.payPlugin}个更新`).show();
            }
        });
    }

    function _LoadTicketBadge() {
        util.post({
            url: "/admin/api/ticket/badge",
            loader: false,
            error: false,
            fail: false,
            done: res => {
                const count = Math.max(0, Number(res?.data?.count || 0));
                $('.ticket-admin-badge')
                    .text(count > 99 ? '99+' : count)
                    .prop('hidden', count < 1)
                    .attr('aria-label', count > 0 ? `有 ${count} 张工单等待处理` : '没有待处理工单');
            }
        });
    }

    function _AppServerSelect() {
        $('.app-server-select').change(function () {
            util.post("/admin/api/app/setServer", {server: $(this).val()}, res => {
                message.success(res.msg);
            });
        });
    }


    function _Pjax() {
        $(document).pjax('a[target!=_blank]', '#pjax-container', {fragment: '#pjax-container', timeout: 8000});
        $(document).on('pjax:send', function () {
            Loading.show();
            // 手机版:点菜单(pjax 导航)后自动收起侧栏抽屉；桌面态 aside 非 drawer-on,跳过
            var aside = document.querySelector('#kt_aside');
            if (aside && aside.classList.contains('drawer-on') && window.KTDrawer) {
                var drawer = KTDrawer.getInstance(aside);
                drawer && drawer.hide();
            }
        });
        $(document).on('pjax:complete', function () {
            Loading.hide();
        });
        $("a[target!=_blank]").click(function () {
            $('a[target!=_blank]').removeClass("active");
            $(this).addClass("active");
        });
    }

    _LoadStoreUserInfo();
    _LodLatest();
    _LoadPluginUpdates();
    _LoadTicketBadge();
    _AppServerSelect();
    _Pjax();

    $(document).off('ticket:badge-refresh.admin').on('ticket:badge-refresh.admin', _LoadTicketBadge);
    if (window.__adminTicketBadgeTimer) {
        clearInterval(window.__adminTicketBadgeTimer);
    }
    window.__adminTicketBadgeTimer = setInterval(_LoadTicketBadge, 60000);
}();
