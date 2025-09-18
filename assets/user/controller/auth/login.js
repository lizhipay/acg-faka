!function () {
    let goto = decodeURIComponent(util.getParam("goto"));

    if (goto == "null") {
        goto = "/";
    }

    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post("/user/api/authentication/login", data, res => {
            window.location.href = goto;
            message.success(res.msg);
        });
    });
}();