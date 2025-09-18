const trade = new class {
    constructor() {
    }

    //获取分类
    getCategoryList(done = null) {
        util.get("/user/api/index/data", data => {
            typeof done === "function" && done(data);
        });
    }

    //获取商品列表
    getCommodityList(opt = {}) {
        let params = {};

        if (opt.keywords) {
            params.keywords = opt.keywords;
        }

        if (opt.limit && opt.page) {
            params.limit = opt.limit;
            params.page = opt.page;
        }

        if (opt.categoryId) {
            params.categoryId = opt.categoryId;
        }

        util.get("/user/api/index/commodity" + (!util.isEmptyOrNotJson(params) ? "?" + util.objectToQueryString(params) : ""), data => {
            typeof opt.done === "function" && opt.done(data);
        });
    }

}