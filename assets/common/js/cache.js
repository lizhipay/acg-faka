cache = {
    caches: {},
    set(key, val) {
        this.caches[key] = val;
    },
    get(key) {
        return this.caches.hasOwnProperty(key) ? this.caches[key] : null;
    },
    has(key) {
        return this.caches.hasOwnProperty(key);
    },
    del(key) {
        delete this.caches[key];
    }
}