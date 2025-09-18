!function () {
    let pluginUnbindTable, proUnbindTable, _GroupPrice;
    const table = new Table("/admin/api/app/plugins", "#plugin-table");
    const $StoreContent = $(`.store-content`).parent();

    $StoreContent.hide();

    function _Auth() {
        component.popup({
            submit: false,
            tab: [
                {
                    name: '<i class="fa-duotone fa-regular fa-right-to-bracket"></i> 登录',
                    form: [
                        {
                            title: false,
                            name: "login_page",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="">               
                  <div class="alert alert-warning d-flex align-items-center" role="alert">
                    <p class="mb-0">
                    <i class="fa-duotone fa-regular fa-circle-exclamation"></i> 访问我们的应用商店需要先登录应用商店账号。应用商店内提供大量插件、模板和主题等资源供您安装。
                    </p>
                  </div>
          
                <form class="form-store-login">
                  <div class="form-floating mb-4">
                             <input type="text" class="form-control" id="login-username" name="username" placeholder="${i18n('用户名')}">
                            <label class="form-label" for="login-username">${i18n('用户名')}</label>
                  </div>
                  
                  <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="login-password" name="password" placeholder="${i18n('请输入密码')}">
                    <label class="form-label" for="login-password">${i18n('密码')}</label>
                  </div>
          
                  <div class="row g-sm mb-4">
                      <button type="button" class="btn btn-sm btn-light-primary btn-login">
                        <i class="fa-duotone fa-regular fa-right-to-bracket"></i> ${i18n('确认登入')}
                      </button>
                  </div>
                </form>
              </div>`);

                                $("#login-password").on("keydown", function(e) {
                                    if (e.key === "Enter" || e.keyCode === 13) {
                                        $(".btn-login").click();
                                    }
                                });

                                $(".btn-login").click(() => {
                                    util.post("/admin/api/app/login", {
                                        username: $("#login-username").val(),
                                        password: $("#login-password").val()
                                    }, res => {
                                        message.success("登录成功");
                                        window.location.reload();
                                    }, (res) => {
                                        message.error(res.msg);
                                    });
                                });
                            }
                        }
                    ]
                },
                {
                    name: `<i class="fa-duotone fa-regular fa-user-plus"></i> 注册`,
                    form: [
                        {
                            title: false,
                            name: "register_page",
                            type: "custom",
                            complete: (form, dom) => {
                                let cookie;

                                function _register_captcha(loader = false) {
                                    util.post({
                                        url: '/admin/api/app/captcha?type=captcha_reg',
                                        loader: loader,
                                        done: res => {
                                            cookie = res.data.cookie;
                                            $('.img-captcha-register').attr("src", res.data.base64);
                                        }
                                    });
                                }


                                dom.html(`<div><form class="form-store-register">
                  <div class="form-floating mb-4">
                    <input type="text" class="form-control" id="register-username"  placeholder="${i18n('用户名')}">
                    <label class="form-label" for="register-username">${i18n('用户名')}</label>
                  </div>
           
        
                  <div class="form-floating mb-4">
                    <input type="text" class="form-control" id="register-password"  placeholder="${i18n('请设置登录密码')}">
                    <label class="form-label" for="register-password">${i18n('登录密码')}</label>
                  </div>
                  <div class="row mb-4">
                    <div class="col-sm-6 col-6">
                           <div class="form-floating">
                            <input type="text" class="form-control" id="register-captcha" placeholder="${i18n('请输入验证码')}">
                            <label class="form-label" for="register-captcha">${i18n('图形验证码')}</label>
                          </div>
                    </div>
                    <div class="col-sm-6 col-6">
                           <img style="cursor:pointer;height: 47px;border-radius: 0.475rem;" class="img-captcha-register" alt="${i18n('更换验证码')}">
                    </div>
                  </div>
                  
                  <div class="row g-sm mb-4">
                      <button type="button" class="btn btn-sm btn-light-success btn-register">
                        <i class="fa-duotone fa-regular fa-user-plus"></i> ${i18n('确认注册')}
                      </button>
                  </div>
                </form>
              </div>`);

                                _register_captcha();

                                const $imageCode = $('.img-captcha-register');
                                $imageCode.click(() => {
                                    _register_captcha(false);
                                });

                                $('.btn-register').click(() => {
                                    util.post("/admin/api/app/register", {
                                        username: $("#register-username").val(),
                                        password: $("#register-password").val(),
                                        captcha: $("#register-captcha").val(),
                                        cookie: cookie
                                    }, res => {
                                        message.success("注册成功");
                                        window.location.reload();
                                    }, (res) => {
                                        message.error(res.msg);
                                        $imageCode.click();
                                    });
                                });
                            }
                        }
                    ]
                }
            ],
            closeBtn: false,
            maxmin: false,
            autoPosition: true,
            width: "480px"
        });


    }

    function _Bill(plugin = {}) {
        let billModalIndex = 0;
        let tabs = [];

        if (!util.isEmptyOrNotJson(plugin)) {
            tabs.push({
                name: `<div class="common-item"><img src="${plugin?.icon}" class="item-icon" style="width: 20px;height: 20px;"> <div class="item-name" style="font-size: 1rem;">${plugin?.plugin_name}</div></div>`,
                form: [
                    {
                        title: false,
                        name: "introduce_plugin",
                        type: "custom",
                        complete: (form, dom) => {
                            let payList = '', selectedSubscription, selectAmount;
                            dom.html(`<div>     
<div class="alert alert-success" role="alert">
                    <p class="mb-0">
                      您所购买的插件，将统一归属于您的应用商店账户名下。无论您更换服务器或重新安装程序，只需登录购买时所使用的应用商店账户，即可迅速将产品绑定至新的网站上。
                    </p>
                  </div>          
            
                    <div class="mb-3 store-introduce">
                      ${i18n(plugin.description)}
                    </div>
                    
                    <div class="subscription-container">
                        <div class="layout-box">
                                <div class="title"><i class="fa-duotone fa-regular fa-clock"></i> 订阅类型</div>
                                <div class="subscription-list online-pay"><div class="subscription-item" data-amount="${plugin.price}"><span style="color: #496b93ab;"><span style="color: #D38200;font-size: 18px;font-weight: bold;">¥${plugin.price}</span></span><span style="color: #BDB8B8;font-size: 13px;text-decoration:line-through;">原价:${plugin.price * 2}</span><span style="color: #D38200;font-size:12px;">终身可用</span></div></div>
                        </div>
                        
                    
                     
                        
                        <div class="layout-box">
                                        <div class="title"><i class="fa-duotone fa-regular fa-star-shooting"></i> 付款购买 ${plugin.group > 0 ? `<span style="color: #3fa24a;"> 此插件企业版免费用，开通企业版更省钱更超值！<a href="javascript:void(0);" class="text-primary open-group-enterprise-click">点我开企业版</a></span>` : ""}</div>
                                            <div class="pay-list online-pay">
                                                <div data-id="${plugin.id}"  data-type="0" data-pay="0" class="pay-item online-pay-click"><img class="item-icon" src="/assets/common/images/alipay.png"><span>支付宝</span></div>
                                                <div data-id="${plugin.id}"  data-type="0" data-pay="1" class="pay-item online-pay-click"><img class="item-icon" src="/assets/common/images/wx.png"><span>微信支付</span></div>
                                            </div>
   
                        </div>
                    </div>
              </div>`);

                            $(`.open-group-enterprise-click`).click(() => {
                                $(`.open-group-enterprise`).parent().trigger('mousedown').trigger('click');
                            });

                            const $onlinePay = dom.find(".online-pay-click");
                            $onlinePay.click(function () {
                                const type = $(this).data("type");
                                const pay = $(this).data("pay");
                                let pluginId = $(this).data("id");
                                pluginId = pluginId ? pluginId : 0;
                                util.post("/admin/api/app/purchase", {
                                    type: type,
                                    plugin_id: pluginId,
                                    payType: pay
                                }, res => {
                                    layer.msg(res.msg);
                                    window.location.href = res.data.url;
                                });
                            });
                        }
                    }
                ]
            });
        }

        if (util.isEmptyOrNotJson(plugin) || plugin.group > 0) {
            tabs.push({
                name: `<div class="common-item open-group-enterprise"><i class="fa-duotone fa-regular fa-user me-1"></i> <div class="item-name" style="font-size: 1rem;">开通企业版(推荐)</div></div>`,
                form: [
                    {
                        title: false,
                        name: "introduce_group",
                        type: "custom",
                        complete: (form, dom) => {
                            let payList = '', selectedSubscription, selectAmount;
                            dom.html(`<div>     
<div class="alert alert-success" role="alert">
                    <p class="mb-0">
                      您所购买的企业版，将统一归属于您的应用商店账户名下。无论您更换服务器或重新安装程序，只需登录购买时所使用的应用商店账户，即可迅速将产品绑定至新的网站上。
                    </p>
                  </div>          
            
                    <div class="mb-3 store-introduce" style="color: green;">
                     <p style="color: red;">1.全部官方插件/主题免费使用，包括后期会继续上架数百上千种插件/主题</p>
                     <p>2.技术支持</p>
                     <p>3.企业版专属售后通道</p>
                     <p>4.内侧版、预览版抢先体验</p>
                     <p>5.企业版专用功能建议通道，可有效提交新功能需求</p>
                    </div>
                    
                    <div class="subscription-container">
                        <div class="layout-box">
                                <div class="title">订阅类型</div>
                                <div class="subscription-list online-pay"><div class="subscription-item" data-amount="${_GroupPrice}"><span style="color: #496b93ab;"><span style="color: #D38200;font-size: 18px;font-weight: bold;">¥${_GroupPrice}</span></span><span style="color: #BDB8B8;font-size: 13px;text-decoration:line-through;">原价:${_GroupPrice * 2}</span><span style="color: #D38200;font-size:12px;">终身可用</span></div></div>
                        </div>
                        <div class="layout-box">
                                        <div class="title">付款购买</div>
                                            <div class="pay-list online-pay">
                                                <div data-id="0" data-type="2" data-pay="0" class="pay-item online-pay-click"><img class="item-icon" src="/assets/common/images/alipay.png"><span>支付宝</span></div>
                                                <div data-id="0" data-type="2" data-pay="1" class="pay-item online-pay-click"><img class="item-icon" src="/assets/common/images/wx.png"><span>微信支付</span></div>
                                            </div>
   
                        </div>
                    </div>
              </div>`);
                            const $onlinePay = dom.find(".online-pay-click");
                            $onlinePay.click(function () {
                                const type = $(this).data("type");
                                const pay = $(this).data("pay");
                                let pluginId = $(this).data("id");
                                pluginId = pluginId ? pluginId : 0;
                                util.post("/admin/api/app/purchase", {
                                    type: type,
                                    plugin_id: pluginId,
                                    payType: pay
                                }, res => {
                                    layer.msg(res.msg);
                                    window.location.href = res.data.url;
                                });
                            });
                        }
                    }
                ]
            })
        }


        component.popup({
            submit: false,
            tab: tabs,
            maxmin: false,
            autoPosition: true,
            width: "780px",
            renderComplete: (unique, index) => {
                billModalIndex = index;
            }
        });
    }

    function _BindPro() {
        component.popup({
            submit: (data, _index) => {
                const ids = proUnbindTable.getSelectionIds();
                if (ids.length == 0) {
                    layer.msg("请选择要解绑的授权");
                    return;
                }
                message.ask(`您正在将授权转移至当前机器，转移后，原机器的授权将失效！`, () => {
                    util.post('/admin/api/app/bindLevel', {
                        auth_id: ids[0]
                    }, res => {
                        layer.close(_index);
                        window.location.reload();
                    });
                }, "授权转移至本机", "确认转移");
            },
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-user-shield") + " 检查授权",
                    form: [
                        {
                            title: false,
                            name: "custom",
                            type: "custom",
                            complete: (obj, dom) => {
                                dom.html('<div class="mcy-card"><table id="pro-unbind-table"></table></div>');
                                proUnbindTable = new Table(`/admin/api/app/levels`, "#pro-unbind-table");
                                proUnbindTable.setColumns([
                                    {checkbox: true},
                                    {
                                        field: 'server_ip',
                                        title: '服务器IP'
                                    },
                                    {
                                        field: 'level', title: '产品名称',
                                        formatter: function (val, item) {
                                            if (item.level == 0) {
                                                return '<span class="a-badge a-badge-primary">专业版</span>';
                                            }
                                            return '<span class="a-badge a-badge-success">企业版</span>';
                                        }
                                    },
                                    {
                                        field: 'app_key', title: '授权指纹',
                                        formatter: function (val, item) {
                                            return '<span class="a-badge a-badge-primary">' + item.app_key + '</span>';
                                        }
                                    },
                                    {
                                        field: 'expire_date', title: '到期时间',
                                        formatter: function (val, item) {
                                            return '<span class="a-badge a-badge-success">' + item.expire_date + '</span>';
                                        }
                                    }]);
                                proUnbindTable.enableSingleSelect();
                                proUnbindTable.disablePagination();
                                proUnbindTable.render();
                            }
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "820px",
            maxmin: false,
            shadeClose: true,
            confirmText: `<i class="fa-duotone fa-regular fa-lock-hashtag"></i> 解绑授权至本机器`,
            done: () => {
                table.refresh();
            }
        });
    }

    util.post({
        url: "/admin/api/app/service",
        loader: false,
        done: res => {
            if (res?.data?.id <= 0) {
                _Auth();
                return;
            }

            if (res?.data?.developer == 0) {
                $(`a[href="/admin/store/developer"]`).remove();
                $(`.breadcrumb-item`).remove();
            }

            if (!res?.data?.level) {
                const $UpdatePro = $(`.update-pro`);
                $(`.store-toolbar`).show();
                $UpdatePro.show().click(() => _Bill());

                if (res?.data?.is_have_level) {
                    $UpdatePro.html(`<i class="fa-duotone fa-regular fa-circle-yen"></i>重新在当前机器开通企业版(不影响其他机器企业版使用)`);
                    $(`.bind-pro`).show().click(() => _BindPro());
                }
            }

            $StoreContent.show();
            table.setColumns([
                {
                    field: 'plugin_name', title: '软件名称', formatter: function (val, item) {
                        return `<span class="table-item"><img src="${item?.icon}" class="table-item-icon"><span class="table-item-name">${item?.plugin_name}</span></span>`;

                        return `<span class="a-badge a-badge-dark"><img src="${item.icon}"  style="width: 18px;border-radius: 5px;margin-top: -2px"> ${item.plugin_name}</span>`
                    }
                }
                ,
                {
                    field: 'user', title: '开发商', formatter: function (val, item) {
                        if (item.user.official == 1) {
                            return '<span class="a-badge a-badge-success">官方</span>';
                        }
                        return '<span class="a-badge a-badge-light">' + item.user.username + '</span>';
                    }
                }
                ,
                {
                    field: 'type', title: '类型', dict: '_store_plugin_type'
                }
                ,
                {
                    field: 'description', title: '简介'
                },
                {
                    field: 'web_site', title: '官网', formatter: format.link
                },
                {
                    field: 'version', title: '版本', formatter: function (val, item) {
                        return '<span class="a-badge a-badge-secondary">' + item.version + '</span>';
                    }
                },
                {
                    field: 'price', title: '价格', formatter: function (val, item) {
                        if (item.price == 0) {
                            return format.badge(`免费`, "a-badge-success");
                        }

                        let html = " <span class='a-badge a-badge-danger'>￥" + item.price + "</span> ";
                        if (item.group == 1) {
                            html += format.badge(`专业版免费`, "a-badge-primary");
                            html += format.badge(`企业版免费`, "a-badge-success");
                        }

                        if (item.group == 2) {
                            html += format.badge(`企业版免费`, "a-badge-success");
                        }
                        return `<span class="a-badge-group nowrap">${html}</span>`;
                    }
                },
                {
                    field: 'price', title: '到期时间', formatter: function (val, item) {
                        if (item.price == 0) {
                            return "-";
                        }
                        if (item.has.has == true) {
                            return "<span class='a-badge a-badge-success'>" + item.has.expire + "</span>";
                        }
                        return "<span class='a-badge a-badge-light'>未开通</span>";
                    }
                },
                {
                    field: 'operation', title: '', type: 'button', buttons: [
                        {
                            icon: 'fa-duotone fa-regular fa-plus',
                            title: "安装",
                            show: item => (item.has.has == true && item.install == 0) || (item.price == 0 && item.install == 0),
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                message.ask(`您正在安装插件<b style="color: mediumvioletred;">${row.plugin_name}</b>，是否继续`, () => {
                                    util.post('/admin/api/app/install', {
                                        plugin_key: row.plugin_key,
                                        type: row.type,
                                        plugin_id: row.id
                                    }, res => {

                                        setTimeout(() => {
                                            table.refresh();
                                        }, 500);

                                        if (row.type == 1) {
                                            message.ask("支付插件安装成功，是否立即前往配置？", () => {
                                                window.location.href = "/admin/pay/plugin";
                                            }, `安装成功`, "前往支付扩展");

                                        } else if (row.type == 2) {
                                            message.ask("网站模版安装成功，是否前往网站设置？", () => {
                                                window.location.href = "/admin/config/index";
                                            }, `安装成功`, "前往网站设置");
                                        } else {
                                            message.ask("插件安装成功，是否前往插件管理？", () => {
                                                window.location.href = "/admin/plugin/index";
                                            }, `安装成功`, "前往插件管理");
                                        }
                                    });
                                }, "安装插件", "确认安装");
                            }
                        },
                        {
                            title: "更新",
                            show: item => item.has.has == true && item.install == 1 || (item.price == 0 && item.install == 1),
                            class: "text-primary",
                            formatter: (item) => {
                                if (item.version != item.local_version) {
                                    return `<a type="button" class="a-badge-glass text-primary me-1 mb-1"><i class="fa-duotone fa-regular fa-arrows-rotate-reverse"></i> <span class="btn-title">更新( <span style='color: red;'>${item.local_version}</span> ➩ <b style='color: #28b728;'>${item.version}</b>)</span></a>`;
                                }
                            },
                            click: (event, value, row, index) => {
                                message.ask(row?.update_content?.replace(/\n/, "<br>"), () => {
                                    util.post('/admin/api/app/upgrade', {
                                        plugin_key: row.plugin_key,
                                        type: row.type,
                                        plugin_id: row.id
                                    }, res => {
                                        message.info(res.msg);
                                        table.refresh();
                                    });
                                }, `<b style="color: #1589e4;"><i class="fa-duotone fa-regular fa-sparkles"></i> ${row.plugin_name}</b> <span style="color: #0a84ff;font-size: 14px;">${row.local_version}</span> <i class="fa-duotone fa-regular fa-right-long text-danger"></i> <span style="color: green;font-size: 14px;">${row.version}</span>`, "立即更新")

                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-lock-hashtag',
                            title: "解绑",
                            show: item => item.price > 0 && item.has.has == false && item.owned == true,
                            class: "text-primary",
                            click: (event, value, row, index) => {
                                component.popup({
                                    submit: (data, _index) => {
                                        const ids = pluginUnbindTable.getSelectionIds();
                                        if (ids.length == 0) {
                                            layer.msg("请选择要解绑的授权");
                                            return;
                                        }

                                        message.ask(`您正在将授权转移至当前机器，转移后，原机器的授权将失效！`, () => {
                                            util.post('/admin/api/app/unbind', {
                                                auth_id: ids[0]
                                            }, res => {
                                                layer.close(_index);
                                                table.refresh();
                                            });
                                        }, "授权转移至本机", "确认转移");
                                    },
                                    tab: [
                                        {
                                            name: util.icon("fa-duotone fa-regular fa-lock-hashtag") + " 检查授权",
                                            form: [
                                                {
                                                    title: false,
                                                    name: "custom",
                                                    type: "custom",
                                                    complete: (obj, dom) => {
                                                        dom.html('<div class="mcy-card"><table id="plugin-unbind-table"></table></div>');
                                                        pluginUnbindTable = new Table(`/admin/api/app/purchaseRecords?plugin_id=${row.id}`, "#plugin-unbind-table");
                                                        pluginUnbindTable.setColumns([
                                                            {checkbox: true},
                                                            {
                                                                field: 'server_ip',
                                                                title: '服务器IP'
                                                            },
                                                            {
                                                                field: 'app_key', title: '授权指纹',
                                                                formatter: function (val, item) {
                                                                    return '<span class="a-badge a-badge-primary">' + item.app_key + '</span>';
                                                                }
                                                            },
                                                            {
                                                                field: 'expire_date', title: '到期时间',
                                                                formatter: function (val, item) {
                                                                    return '<span class="a-badge a-badge-success">' + item.expire_date + '</span>';
                                                                }
                                                            }]);
                                                        pluginUnbindTable.enableSingleSelect();
                                                        pluginUnbindTable.disablePagination();
                                                        pluginUnbindTable.render();
                                                    }
                                                },
                                            ]
                                        },
                                    ],
                                    autoPosition: true,
                                    height: "auto",
                                    width: "720px",
                                    maxmin: false,
                                    shadeClose: true,
                                    confirmText: `<i class="fa-duotone fa-regular fa-lock-hashtag"></i> 解绑授权至本机器`,
                                    done: () => {
                                        table.refresh();
                                    }
                                });
                            }
                        },
                        {
                            icon: 'fa-duotone fa-regular fa-trash-can',
                            title: "卸载",
                            show: item => item.has.has == true && item.install == 1 || (item.price == 0 && item.install == 1),
                            class: "text-danger",
                            click: (event, value, row, index) => {
                                message.ask(`您正在卸载插件<b style="color: mediumvioletred;">${row.plugin_name}</b>，是否继续`, () => {
                                    util.post('/admin/api/app/uninstall', {
                                        plugin_key: row.plugin_key,
                                        type: row.type
                                    }, res => {
                                        table.refresh();
                                    });
                                }, "卸载插件", "确认卸载");
                            }
                        }, {
                            icon: 'fa-duotone fa-regular fa-cart-shopping',
                            title: "购买",
                            show: item => item.price > 0 && item.has.has == false,
                            formatter: (item) => {
                                return `<a type="button" class="a-badge-glass text-primary me-1 mb-1"><i class="fa-duotone fa-regular fa-cart-shopping"></i> <span class="btn-title">${item.owned == true ? "重新购买" : "立即购买"}</a>`;
                            },
                            class: "text-success",
                            click: (event, value, row, index) => {
                                _Bill(row);
                            }
                        },
                    ]
                }
            ]);

            table.setPagination(20, [20, 30, 50, 100, 200]);

            table.setSearch([
                {title: "搜索应用..", name: "keywords", type: "input"}
            ]);

            table.onResponse(data => {
                _GroupPrice = data?.purchase?.enterprise;
            });

            table.setState("owner", "_store_plugin_owner");
            table.render();
        },
        error: () => {
            _Auth();
        },
        fail: () => {
            _Auth();
        }
    });
}();