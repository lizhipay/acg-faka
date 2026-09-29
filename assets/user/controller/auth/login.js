!function () {
    function safeGoto(raw, fallback) {
        if (typeof raw !== "string" || raw === "" || raw === "null") {
            return fallback;
        }
        let target;
        try {
            target = decodeURIComponent(raw);
        } catch (e) {
            return fallback;
        }
        const safe = target.charAt(0) === "/"
            && target.charAt(1) !== "/"
            && target.indexOf("\\") === -1
            && !/[\u0000-\u001f\u007f]/.test(target);
        return safe ? target : fallback;
    }

    let goto = safeGoto(util.getParam("goto"), "/");

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