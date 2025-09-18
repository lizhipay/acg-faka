class Loading {
    static _overlay = null;
    static _inlineHost = null; // 当前内联宿主
    static _inlineEl = null;   // 当前内联loader
    static _locked = false;

    /**
     * 显示加载动画
     * @param {Object} opts
     *  - inline: 选择器或元素，将 loader 内联插入到该元素中；不传则使用全屏遮罩
     *  - size: 直径（number|css，默认 50 或使用 CSS 变量）
     *  - color: 颜色（默认 #25b09b）
     *  - border: 圈线粗细（number|css，默认 4）
     *  - overlayAlpha: 遮罩透明度（0~1），仅 overlay 模式有效
     */
    static show(opts = {}) {
        const { inline, size, color, border, overlayAlpha } = opts;

        if (inline) {
            const host = (typeof inline === 'string') ? document.querySelector(inline) : inline;
            if (!host) {
                console.warn('[Loading] inline 宿主未找到：', inline);
                return;
            }
            // 清理旧的 inline
            this._cleanupInline();
            this._inlineHost = host;

            // 宿主加内联容器（避免影响原布局）
            const wrap = document.createElement('span');
            wrap.className = 'chahuo-ring-inline';
            const ring = document.createElement('span');
            ring.className = 'chahuo-ring';
            wrap.appendChild(ring);
            host.appendChild(wrap);
            this._inlineEl = wrap;

            // 样式覆写
            if (size != null) ring.style.setProperty('--chahuo-ring-size', typeof size === 'number' ? size + 'px' : String(size));
            if (color) ring.style.setProperty('--chahuo-ring-color', color);
            if (border != null) ring.style.setProperty('--chahuo-ring-border', typeof border === 'number' ? border + 'px' : String(border));

            return;
        }

        // overlay 模式
        this._ensureOverlay();
        const ov = this._overlay;
        ov.setAttribute('aria-hidden', 'false');

        // 样式覆写（作用于 overlay 内的 ring）
        const ring = ov.querySelector('.chahuo-ring');
        if (size != null) ring.style.setProperty('--chahuo-ring-size', typeof size === 'number' ? size + 'px' : String(size));
        if (color) ring.style.setProperty('--chahuo-ring-color', color);
        if (border != null) ring.style.setProperty('--chahuo-ring-border', typeof border === 'number' ? border + 'px' : String(border));
        if (typeof overlayAlpha === 'number') ov.style.setProperty('--chahuo-ring-overlay', `rgba(0,0,0,${overlayAlpha})`);

        this._lockScroll();
    }

    /** 隐藏（同时清理 overlay 与 inline） */
    static hide() {
        if (this._overlay) this._overlay.setAttribute('aria-hidden', 'true');
        this._unlockScroll();
        this._cleanupInline();
    }

    /* ============ 内部 ============ */
    static _ensureOverlay() {
        if (this._overlay) return;
        const ov = document.createElement('div');
        ov.className = 'chahuo-ring-overlay';
        ov.setAttribute('aria-hidden', 'true');

        const ring = document.createElement('span');
        ring.className = 'chahuo-ring';

        ov.appendChild(ring);
        document.body.appendChild(ov);
        this._overlay = ov;
    }

    static _cleanupInline() {
        if (this._inlineEl && this._inlineEl.parentNode) {
            this._inlineEl.parentNode.removeChild(this._inlineEl);
        }
        this._inlineEl = null;
        this._inlineHost = null;
    }

    static _lockScroll() {
        if (this._locked) return;
        this._locked = true;
        const doc = document.documentElement;
        const sbw = window.innerWidth - doc.clientWidth;
        doc.style.overflow = 'hidden';
        if (sbw > 0) doc.style.paddingRight = sbw + 'px';
    }
    static _unlockScroll() {
        if (!this._locked) return;
        this._locked = false;
        const doc = document.documentElement;
        doc.style.overflow = '';
        doc.style.paddingRight = '';
    }
}