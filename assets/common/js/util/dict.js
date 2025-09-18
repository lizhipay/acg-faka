class _DictUtil {
    get(key) {
        const data = this.data[key];
        for (let i = 0; i < data.length; i++) {
            data[i]['name'] = i18n(data[i]['name']);
        }
        return data;
    }

    result(key, value) {
        let res = undefined;
        let data = [];

        if (typeof key == "object") {
            data = key;
        } else {
            data = this.data[key];
        }

        if (!data) {
            return undefined;
        }

        data.forEach(item => {
            if (value == item.id) {
                res = item.name;
            }
        });

        if (res !== undefined) {
            res = i18n(res);
        }
        return res;
    }

    advanced(key, done = null) {
        if (key == undefined) {
            return;
        }
        if (typeof key == "object") {
            done && done(this.globalization(key));
            return;
        }

        if (this.data.hasOwnProperty(key)) {
            const data = this.globalization(this.data[key]);
            done && done(data);
            return;
        }
        util.post(this.dictUrl + key, {}, res => {
            done && done(this.globalization(res.data));
        });
    }

    globalization(data) {
        let list = [...data];
        for (let i = 0; i < list.length; i++) {
            list[i] = {...list[i]};
            list[i]['name'] = i18n(list[i]['name']);
        }
        return list;
    }
}