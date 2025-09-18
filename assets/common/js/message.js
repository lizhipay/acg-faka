const message = new class Message {
    log(text, type = 'success') {
        toastr.options = {
           // "closeButton": true,
            "debug": false,
            "newestOnTop": true,
            "progressBar": true,
            //"positionClass": "toast-top-center", // 可以根据需要选择位置
            "preventDuplicates": false,
            "onclick": null,
            "showDuration": "300",
            "hideDuration": "1000",
            "timeOut": "2000",
            "extendedTimeOut": "1000",
            "showEasing": "swing",
            "hideEasing": "linear",
            "showMethod": "fadeIn",
            "hideMethod": "fadeOut"
        };

        switch (type) {
            case "success":
                toastr.success(i18n(text));
                break;
            case "error":
                toastr.error(i18n(text));
                break;
            case "info":
                toastr.info(i18n(text));
                break;
            case "warning":
                toastr.warning(i18n(text));
                break;
        }
    }

    success(text) {
        this.log(text, 'success');
    }

    error(text) {
        this.log(text, 'error');
    }

    warning(text) {
        this.log(text, 'warning');
    }

    info(text) {
        this.log(text, 'info');
    }

    ask(text, done = null, title = "您确定吗？", confirm = "确定") {
        Swal.fire({
            title: i18n(title),
            html: i18n(text),
            icon: "warning",
            showCancelButton: true,
            cancelButtonText: i18n("取消"),
            confirmButtonText: i18n(confirm),
        }).then((t => {
            if (t.value) {
                done && done();
            }
        }));
    }

    /**
     * @param opt
     */
    prompt(opt) {
        let options = {
            input: "text",
            inputAttributes: {
                autocapitalize: 'off'
            },
            showCancelButton: true,
            cancelButtonText: i18n("取消"),
            confirmButtonText: i18n("确定")
        };
        options = {...options, ...opt};
        return Swal.fire(options);
    }

    dangerPrompt(message, input, done) {
        this.prompt({
            title: '<svg class="mcy-icon" aria-hidden="true"><use xlink:href="#icon--zhongdaweixian"></use></svg><space></space>' + i18n("危险操作"),
            width: 680,
            html: "<i style='color: #b98f13;'>" + i18n(message) + "</i><br><br><span style='font-size: 14px;'>" + i18n('为了保证安全性，请再次确认您的操作意图。请在下方输入：') + "“<b style='color: red;'>" + i18n(input) + "</b>”</span>",
            inputAttributes: {
                onpaste: 'return false',
                oncopy: 'return false'
            },
            confirmButtonText: i18n("继续操作"),
            inputValidator: function (value) {
                return (!value || value != i18n(input)) && i18n("请输入：") + '“' + i18n(input) + '”，' + i18n("后再进行操作");
            }
        }).then(res => {
            if (res.isConfirmed === true) {
                done && done();
            }
        });
    }


    alert(text, type = 'success') {
        text = i18n(text);
        switch (type) {
            case 'success':
                Swal.fire(i18n("成功"), text, type);
                break;
            case 'error':
                Swal.fire(i18n("操作被阻断"), text, type);
                break;
            case 'warning':
                Swal.fire(i18n("安全警告"), text, type);
                break;
        }
    }
}