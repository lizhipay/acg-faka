/******/
(() => { // webpackBootstrap
    /******/
    "use strict";
    var __webpack_exports__ = {};
    /*!*******************************************************************************************!*\
      !*** ../../../themes/metronic/html/demo1/src/js/custom/authentication/sign-in/general.js ***!
      \*******************************************************************************************/

    var goto = decodeURIComponent(Util.getQueryVariable("goto"));

    if (goto == 0) {
        goto = "/admin/dashboard/index";
    }

// Class definition
    var KTSigninGeneral = function () {
        // Elements
        var form;
        var submitButton;
        var validator;

        // Handle form
        var handleForm = function (e) {
            // Init form validation rules. For more info check the FormValidation plugin's official documentation:https://formvalidation.io/
            validator = FormValidation.formValidation(
                form,
                {
                    fields: {
                        'email': {
                            validators: {
                                notEmpty: {
                                    message: '邮箱不能为空'
                                },
                                emailAddress: {
                                    message: '请填写正确的邮箱地址'
                                }
                            }
                        },
                        'password': {
                            validators: {
                                notEmpty: {
                                    message: '请输入密码'
                                }
                            }
                        }
                    },
                    plugins: {
                        trigger: new FormValidation.plugins.Trigger(),
                        bootstrap: new FormValidation.plugins.Bootstrap5({
                            rowSelector: '.fv-row'
                        })
                    }
                }
            );

            // Handle form submit
            submitButton.addEventListener('click', function (e) {
                // Prevent button default action
                e.preventDefault();

                // Validate form
                validator.validate().then(function (status) {
                    if (status == 'Valid') {
                        // Show loading indication
                        submitButton.setAttribute('data-kt-indicator', 'on');

                        // Disable button to avoid multiple click
                        submitButton.disabled = true;

                        $.post("/admin/api/authentication/login", {
                            username: form.querySelector('[name="email"]').value,
                            password: form.querySelector('[name="password"]').value
                        }, res => {
                            // Hide loading indication
                            submitButton.removeAttribute('data-kt-indicator');
                            // Enable button
                            submitButton.disabled = false;

                            if (res.code != 200) {
                                Swal.fire({
                                    text: res.msg,
                                    icon: "error",
                                    buttonsStyling: false,
                                    confirmButtonText: "OK,我知道了",
                                    customClass: {
                                        confirmButton: "btn btn-primary"
                                    }
                                });
                                return;
                            }


                            setTimeout(() => {
                                window.location.href = goto;
                            }, 1000);


                            Swal.fire({
                                text: "登录成功，正在跳转..",
                                icon: "success",
                                buttonsStyling: false,
                                confirmButtonText: "OK",
                                customClass: {
                                    confirmButton: "btn btn-primary"
                                }
                            }).then(function (result) {
                                if (result.isConfirmed) {
                                    window.location.href = goto;
                                }
                            });
                        });


                    }
                });
            });
        }

        // Public functions
        return {
            // Initialization
            init: function () {
                form = document.querySelector('#kt_sign_in_form');
                submitButton = document.querySelector('#kt_sign_in_submit');

                handleForm();
            }
        };
    }();

// On document ready
    KTUtil.onDOMContentLoaded(function () {
        KTSigninGeneral.init();
    });

    /******/
})()
;
//# sourceMappingURL=general.js.map