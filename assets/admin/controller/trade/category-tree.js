/**
 * 后台「商品管理」左侧的分类树：主站分类在上、商家分类按商家分组，数量含全部下级分类；
 * 点分类筛选商品（含全部下级），并可在这里直接管理分类。
 *
 * 管理：标题栏「添加分类」；每个分类的「⋯」（也可右键 / 键盘菜单键 / Shift+F10）里有添加子分类、编辑、上移 / 下移、
 * 启用 / 停用、隐藏、复制推广链接、删除；电脑版按住拖动调整同级顺序（Alt+↑/↓ 也可以）。
 * 新增 / 编辑弹窗、删除预览、启停确认与分类管理页共用 category-actions.js，接口也是分类管理页那几个。
 * 分类名在分类管理页允许带 HTML，这里一律只显示纯文字。
 *
 * 电脑版：常驻在商品列表左侧，可一键收合（记住收合状态），滚动时贴顶。
 * 手机版（AdminMobile 卡片列表）：列表上方一个「分类」按钮，点开后台手机版自带的底部抽屉（AdminMobile.openSheet），
 * 「⋯」用自带的操作列表（openActions），外观、下拉关闭、返回键都与其它手机页面一致；排序用上移 / 下移。
 *
 * 用法：
 *   const tree = MdCategoryTree.attach({layout, panel, bar, url, scope: () => ({display_scope, user_id}),
 *       onSelect: id => {...}, onMutate: kind => {...}});
 *   tree.reload();              // 显示范围变了（选中的分类不在新范围里时回到「全部商品」）
 *   tree.refreshSoon();         // 商品增删改后刷新数量（合并短时间内的多次调用）
 *   tree.destroy();             // 页面销毁时
 */
window.MdCategoryTree = (() => {
    const STYLE_ID = 'md-cat-tree-style';
    const KEY_COLLAPSED = 'md.commodityCategory.collapsed';
    const KEY_EXPANDED = 'md.commodityCategory.expanded';
    const DRAG_START = 4;
    const DRAG_HINT = 14;
    const SAVE_DELAY = 450;
    // 浮起卡片里拖动图标的中心（相对卡片左上角），让它压在指针正下方
    const GHOST_GRIP_X = 17;
    const GHOST_GRIP_Y = 17;
    const GHOST_NAME_X = 32;
    const T = s => (typeof i18n === 'function' ? i18n(s) : s);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const icon = (name, cls = '') => `<span class="material-icons-outlined${cls ? ' ' + cls : ''}" aria-hidden="true">${name}</span>`;
    const isMobileLayout = () => Boolean(window.AdminMobile && typeof window.AdminMobile.isEnabled === 'function' && window.AdminMobile.isEnabled());
    const reduceMotion = () => Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const store = {
        get(key, fallback) {
            try {
                const raw = window.localStorage.getItem(key);
                return raw === null ? fallback : JSON.parse(raw);
            } catch (e) {
                return fallback;
            }
        },
        set(key, value) {
            try {
                window.localStorage.setItem(key, JSON.stringify(value));
            } catch (e) {
            }
        }
    };
    // DOMParser 解析出的文档不执行脚本、不加载资源，只拿文字
    const plainCache = new Map();
    const plain = value => {
        const raw = String(value ?? '');
        if (!/[<&]/.test(raw)) return raw;
        if (!plainCache.has(raw)) {
            let text = raw;
            try {
                text = (new DOMParser().parseFromString(raw, 'text/html').body.textContent || '').replace(/\s+/g, ' ').trim() || raw;
            } catch (e) {
            }
            plainCache.set(raw, text);
        }
        return plainCache.get(raw);
    };
    const brokenIcons = new Set();

    const STYLE = `
        .md-commodity-layout{display:flex;align-items:flex-start;gap:20px}
        .md-commodity-layout__main{flex:1 1 auto;min-width:0}
        .md-commodity-layout.is-mobile{display:block}
        .md-commodity-layout.is-mobile .md-cat-panel{display:none}
        .md-cat-bar{display:none}
        .md-commodity-layout.is-mobile .md-cat-bar{display:block;margin-bottom:12px}

        .md-cat-panel{flex:0 0 264px;width:264px;position:sticky;top:var(--md-cat-top,16px);max-height:calc(100vh - var(--md-cat-top,16px) - 20px);
            display:flex;flex-direction:column;overflow:hidden;margin-bottom:1.25rem;
            transition:flex-basis .22s var(--md-ease,ease),width .22s var(--md-ease,ease)}
        .md-cat-panel.is-collapsed{flex-basis:48px;width:48px;cursor:pointer}
        .md-cat__head{display:flex;align-items:center;gap:4px;padding:14px 10px 10px 16px;flex:0 0 auto}
        .md-cat__head-ico{font-size:19px;color:var(--md-primary,#1976D2);margin-right:2px}
        .md-cat__title{flex:1 1 auto;min-width:0;font-weight:600;font-size:14px;color:var(--md-on-surface,#1f2937);white-space:nowrap}
        .md-cat__btn{flex:0 0 auto;width:30px;height:30px;border:0;border-radius:8px;background:transparent;display:inline-flex;align-items:center;justify-content:center;
            color:var(--md-on-surface-med,rgba(0,0,0,.6));cursor:pointer;transition:background-color .15s,color .15s}
        .md-cat__btn:hover{background:var(--md-hover-overlay,rgba(0,0,0,.05));color:var(--md-on-surface,#1f2937)}
        .md-cat__btn:focus-visible{outline:2px solid rgba(var(--md-primary-rgb,25,118,210),.5);outline-offset:1px}
        .md-cat__btn[hidden]{display:none}
        .md-cat__btn .material-icons-outlined{font-size:20px}
        .md-cat__btn--add{color:var(--md-primary,#1976D2)}
        .md-cat__btn--add:hover{background:rgba(var(--md-primary-rgb,25,118,210),.10);color:var(--md-primary,#1976D2)}
        .md-cat__rail{display:none;flex-direction:column;align-items:center;gap:8px;padding:12px 0}
        .md-cat__rail-dot{position:relative}
        .md-cat__rail-dot.is-on::after{content:'';position:absolute;top:2px;right:2px;width:7px;height:7px;border-radius:50%;background:var(--md-primary,#1976D2);
            box-shadow:0 0 0 2px var(--md-surface,#fff)}
        .md-cat-panel.is-collapsed .md-cat__head,.md-cat-panel.is-collapsed .md-cat__search,.md-cat-panel.is-collapsed .md-cat__body{display:none}
        .md-cat-panel.is-collapsed .md-cat__rail{display:flex}

        .md-cat__search{flex:0 0 auto;display:flex;align-items:center;gap:6px;height:34px;margin:0 12px 6px;padding:0 10px;border-radius:10px;
            background:var(--md-surface-2,rgba(0,0,0,.04));border:1px solid transparent;transition:border-color .15s,box-shadow .15s}
        .md-cat__search:focus-within{border-color:rgba(var(--md-primary-rgb,25,118,210),.5);box-shadow:0 0 0 3px rgba(var(--md-primary-rgb,25,118,210),.12)}
        .md-cat__search .material-icons-outlined{font-size:18px;color:var(--md-on-surface-dis,rgba(0,0,0,.4))}
        .md-cat__search input{flex:1 1 auto;min-width:0;height:100%;border:0;outline:0;background:transparent;font-size:13px;color:var(--md-on-surface,#1f2937)}
        .md-cat__search input::placeholder{color:var(--md-on-surface-dis,rgba(0,0,0,.4))}

        .md-cat__body{position:relative;flex:1 1 auto;min-height:0;overflow:auto;padding:2px 8px 12px;overscroll-behavior:contain}
        .md-cat__section{display:flex;align-items:center;gap:6px;padding:12px 8px 4px;font-size:11.5px;font-weight:600;color:var(--md-on-surface-dis,rgba(0,0,0,.45))}
        .md-cat__row{position:relative;display:flex;align-items:center;gap:6px;min-height:34px;padding:0 6px 0 calc(4px + var(--depth,0) * 14px);
            border-radius:8px;cursor:pointer;color:var(--md-on-surface,#1f2937);font-size:13px;user-select:none;-webkit-user-select:none;outline:none;
            transition:background-color .12s,color .12s}
        .md-cat__row:hover{background:var(--md-hover-overlay,rgba(0,0,0,.04))}
        .md-cat__row:focus-visible{box-shadow:inset 0 0 0 2px rgba(var(--md-primary-rgb,25,118,210),.45)}
        .md-cat__row.is-selected{background:rgba(var(--md-primary-rgb,25,118,210),.11);color:var(--md-primary,#1976D2);font-weight:600}
        .md-cat__row.is-selected::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:var(--md-primary,#1976D2)}
        .md-cat__toggle{flex:0 0 22px;height:22px;margin-right:-2px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;color:var(--md-on-surface-med,rgba(0,0,0,.55))}
        .md-cat__toggle .material-icons-outlined{font-size:18px;transition:transform .18s var(--md-ease,ease)}
        .md-cat__toggle:not(:empty):hover{background:rgba(var(--md-primary-rgb,25,118,210),.10);color:var(--md-primary,#1976D2)}
        .md-cat__row[aria-expanded="true"] .md-cat__toggle .material-icons-outlined{transform:rotate(90deg)}
        .md-cat__ico{flex:0 0 auto;font-size:17px;color:var(--md-on-surface-med,rgba(0,0,0,.55))}
        .md-cat__ico--fallback{flex:0 0 18px;width:18px;font-size:17px;color:var(--md-on-surface-dis,rgba(0,0,0,.38))}
        .md-cat__img{flex:0 0 18px;width:18px;height:18px;border-radius:5px;object-fit:cover;background:var(--md-surface-2,rgba(0,0,0,.05))}
        .md-cat__row.is-selected .md-cat__ico{color:inherit}
        .md-cat__name{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .md-cat__name mark{padding:0 1px;border-radius:3px;background:rgba(var(--md-warning-rgb,245,158,11),.30);color:inherit}
        .md-cat__tag{flex:0 0 auto;font-size:10.5px;line-height:16px;padding:0 5px;border-radius:4px;background:var(--md-surface-2,rgba(0,0,0,.05));color:var(--md-on-surface-dis,rgba(0,0,0,.45));font-weight:500}
        .md-cat__row.is-off .md-cat__name,.md-cat__row.is-off .md-cat__img,.md-cat__row.is-off .md-cat__ico--fallback{opacity:.55}
        .md-cat__end{position:relative;flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;min-width:46px;height:26px}
        .md-cat__count{font-size:11.5px;font-weight:500;font-variant-numeric:tabular-nums;color:var(--md-on-surface-dis,rgba(0,0,0,.45));transition:opacity .12s}
        .md-cat__row.is-selected .md-cat__count{color:inherit}
        .md-cat__acts{position:absolute;right:-2px;top:0;display:flex;align-items:center;opacity:0;pointer-events:none;transition:opacity .12s}
        .md-cat__grip{width:20px;height:26px;display:inline-flex;align-items:center;justify-content:center;color:var(--md-on-surface-dis,rgba(0,0,0,.4));cursor:grab}
        .md-cat__grip .material-icons-outlined{font-size:17px}
        .md-cat__more{width:26px;height:26px;padding:0;border:0;border-radius:7px;background:transparent;display:inline-flex;align-items:center;justify-content:center;
            color:var(--md-on-surface-med,rgba(0,0,0,.6));cursor:pointer;transition:background-color .12s,color .12s}
        .md-cat__more .material-icons-outlined{font-size:19px}
        .md-cat__more:hover{background:rgba(var(--md-primary-rgb,25,118,210),.12);color:var(--md-primary,#1976D2)}
        .md-cat__row.has-acts:hover .md-cat__acts,.md-cat__row.has-acts:focus-visible .md-cat__acts,.md-cat__row.is-menu .md-cat__acts{opacity:1;pointer-events:auto}
        .md-cat__row.has-acts:hover .md-cat__count,.md-cat__row.has-acts:focus-visible .md-cat__count,.md-cat__row.is-menu .md-cat__count{opacity:0}
        .md-cat__row.is-menu{background:var(--md-hover-overlay,rgba(0,0,0,.05))}
        .md-cat__row.is-dropped{animation:md-cat-dropped .9s var(--md-ease-out,ease-out)}
        @keyframes md-cat-dropped{from{box-shadow:inset 0 0 0 1.5px rgba(var(--md-primary-rgb,25,118,210),.7)}to{box-shadow:inset 0 0 0 1.5px rgba(var(--md-primary-rgb,25,118,210),0)}}
        .md-cat__group{margin-top:2px}
        .md-cat__row.is-group{font-weight:600}
        .md-cat__empty{padding:22px 10px;text-align:center;font-size:12.5px;color:var(--md-on-surface-dis,rgba(0,0,0,.45))}
        .md-cat__retry{border:0;background:none;padding:0;color:var(--md-primary,#1976D2);font:inherit;cursor:pointer;text-decoration:underline}

        .md-cat__body.is-sorting .md-cat__row,.md-cat__body.is-sorting [role="group"]{transition:transform .18s var(--md-ease,ease),background-color .12s,color .12s}
        .md-cat__body.is-sorting .md-cat__row:hover{background:transparent}
        .md-cat__body.is-sorting .md-cat__row.is-selected{background:rgba(var(--md-primary-rgb,25,118,210),.11)}
        .md-cat__body.is-sorting .md-cat__acts{opacity:0!important;pointer-events:none!important}
        .md-cat__body.is-sorting .md-cat__count{opacity:1!important}
        .md-cat__row.is-placeholder,.md-cat__body.is-sorting .md-cat__row.is-placeholder{background:transparent}
        .md-cat__row.is-placeholder::before{display:none}
        .md-cat__row.is-placeholder>*{visibility:hidden}
        .md-cat__row.is-placeholder::after{content:'';position:absolute;inset:2px 0;border:1.5px dashed rgba(var(--md-primary-rgb,25,118,210),.5);border-radius:8px;
            background:rgba(var(--md-primary-rgb,25,118,210),.05)}
        .md-cat__ghost{position:fixed;left:0;top:0;z-index:12010;pointer-events:none;display:flex;align-items:center;gap:6px;height:34px;max-width:260px;padding:0 14px 0 8px;
            border-radius:9px;background:var(--md-surface,#fff);color:var(--md-on-surface,#1f2937);font-size:13px;font-weight:600;
            box-shadow:var(--md-e8,0 8px 24px rgba(0,0,0,.18));will-change:transform}
        .md-cat__ghost .material-icons-outlined{flex:0 0 auto;font-size:18px;color:var(--md-primary,#1976D2)}
        .md-cat__ghost span:last-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .md-cat__ghost.is-landing{transition:transform .18s var(--md-ease-out,ease-out),box-shadow .18s;box-shadow:0 1px 3px rgba(0,0,0,.08)}
        html.md-cat-dragging,html.md-cat-dragging *{cursor:grabbing!important;user-select:none!important;-webkit-user-select:none!important}

        .md-cat-menu{position:fixed;z-index:12010;min-width:180px;max-width:240px;padding:6px;border-radius:12px;background:var(--md-surface,#fff);
            box-shadow:var(--md-e8,0 10px 30px rgba(0,0,0,.18));border:1px solid var(--md-divider,rgba(0,0,0,.08));
            transform-origin:top right;animation:md-cat-menu-in .12s var(--md-ease-out,ease-out)}
        @keyframes md-cat-menu-in{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:none}}
        .md-cat-menu.is-still{animation:none}
        .md-cat-menu__title{padding:6px 10px;font-size:11.5px;font-weight:600;color:var(--md-on-surface-dis,rgba(0,0,0,.45));overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .md-cat-menu__item{display:flex;align-items:center;gap:10px;width:100%;height:34px;padding:0 10px;border:0;border-radius:8px;background:transparent;
            color:var(--md-on-surface,#1f2937);font-size:13px;text-align:left;cursor:pointer;outline:none;white-space:nowrap}
        .md-cat-menu__item .material-icons-outlined{font-size:18px;color:var(--md-on-surface-med,rgba(0,0,0,.55))}
        .md-cat-menu__item:hover,.md-cat-menu__item:focus-visible{background:var(--md-hover-overlay,rgba(0,0,0,.05))}
        .md-cat-menu__item:disabled{opacity:.38;cursor:default;background:transparent}
        .md-cat-menu__item.is-danger,.md-cat-menu__item.is-danger .material-icons-outlined{color:var(--md-error,#d32f2f)}
        .md-cat-menu__item.is-danger:hover,.md-cat-menu__item.is-danger:focus-visible{background:rgba(var(--md-error-rgb,211,47,47),.08)}
        .md-cat-menu__sep{height:1px;margin:5px 4px;background:var(--md-divider,rgba(0,0,0,.08))}
        .md-cat-menu.is-touch .md-cat-menu__item{height:44px;font-size:14px}

        .md-cat-bar__btn{display:flex;align-items:center;gap:8px;width:100%;min-height:46px;padding:0 14px;border-radius:14px;border:1px solid var(--md-divider,rgba(0,0,0,.1));
            background:var(--md-surface,#fff);color:var(--md-on-surface,#1f2937);font-size:14px;cursor:pointer;text-align:left}
        .md-cat-bar__btn .material-icons-outlined{font-size:20px;color:var(--md-on-surface-med,rgba(0,0,0,.55))}
        .md-cat-bar__label{flex:0 0 auto;color:var(--md-on-surface-med,rgba(0,0,0,.6))}
        .md-cat-bar__value{flex:1 1 auto;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .md-cat-bar__btn.is-on .md-cat-bar__value{color:var(--md-primary,#1976D2)}

        /* 备用抽屉（AdminMobile 抽屉不可用时）：层级要盖过手机版顶栏 / 底部导航（11000） */
        .md-cat-sheet{position:fixed;inset:0;z-index:12000;display:flex;align-items:flex-end;justify-content:center}
        .md-cat-sheet__scrim{position:absolute;inset:0;background:var(--md-scrim,rgba(0,0,0,.42));opacity:0;transition:opacity .22s}
        .md-cat-sheet__panel{position:relative;width:100%;max-width:640px;max-height:82vh;display:flex;flex-direction:column;background:var(--md-surface,#fff);
            border-radius:20px 20px 0 0;box-shadow:var(--md-e16,0 -8px 30px rgba(0,0,0,.18));transform:translateY(100%);
            transition:transform .28s var(--md-ease-out,cubic-bezier(.2,.8,.2,1));padding-bottom:env(safe-area-inset-bottom)}
        .md-cat-sheet.is-open .md-cat-sheet__scrim{opacity:1}
        .md-cat-sheet.is-open .md-cat-sheet__panel{transform:none}
        .md-cat-sheet__grip{width:38px;height:4px;border-radius:2px;background:var(--md-divider,rgba(0,0,0,.15));margin:8px auto 0}
        .md-cat-sheet .md-cat__head{padding:10px 10px 8px 18px}
        .md-cat-sheet .md-cat__search{margin:0 14px 8px}
        .md-cat-sheet .md-cat__body{padding:2px 10px 16px}
        .md-cat__tools{display:flex;align-items:center;gap:8px;margin:0 0 10px}
        .md-cat__tools .md-cat__search{flex:1 1 auto;margin:0}
        .md-cat__tools .md-cat__btn{width:40px;height:40px;border-radius:12px;background:rgba(var(--md-primary-rgb,25,118,210),.10)}
        .md-cat-msheet .md-cat__body{overflow:visible;padding:0 0 4px}
        .md-cat-touch .md-cat__search{height:40px}
        .md-cat-touch .md-cat__search input{font-size:16px}
        .md-cat-touch .md-cat__row{min-height:46px;font-size:14px}
        .md-cat-touch .md-cat__end{min-width:64px}
        .md-cat-touch .md-cat__count,.md-cat-touch .md-cat__row.has-acts .md-cat__count{opacity:1;margin-right:38px}
        .md-cat-touch .md-cat__acts{opacity:1;pointer-events:auto;top:-4px}
        .md-cat-touch .md-cat__grip{display:none}
        .md-cat-touch .md-cat__more{width:34px;height:34px}
        @media(hover:none){
            .md-cat-panel .md-cat__acts{opacity:1;pointer-events:auto}
            .md-cat-panel .md-cat__grip{display:none}
            .md-cat-panel .md-cat__count,.md-cat-panel .md-cat__row.has-acts .md-cat__count{opacity:1;margin-right:28px}
        }
        @media(prefers-reduced-motion:reduce){.md-cat-panel,.md-cat__row,.md-cat__toggle .material-icons-outlined,.md-cat-sheet__scrim,.md-cat-sheet__panel,.md-cat-menu,
            .md-cat__body.is-sorting .md-cat__row,.md-cat__body.is-sorting [role="group"]{transition:none;animation:none}}
    `;

    function ensureStyle() {
        if (document.getElementById(STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = STYLE;
        document.head.appendChild(style);
    }

    function attach(options) {
        ensureStyle();
        const {layout, panel, bar, url} = options;
        const scope = typeof options.scope === 'function' ? options.scope : () => ({});
        const onSelect = typeof options.onSelect === 'function' ? options.onSelect : () => {};
        const onMutate = typeof options.onMutate === 'function' ? options.onMutate : () => {};
        const actions = window.MdCategoryActions || null;
        const ns = '.mdCatTree' + Math.random().toString(36).slice(2, 8);

        let data = null;          // {total, main, merchants}
        let failed = false;
        let selected = 0;
        let query = '';
        let collapsed = Boolean(store.get(KEY_COLLAPSED, false));
        let expanded = new Set(store.get(KEY_EXPANDED, []));
        let destroyed = false;
        let sheet = null;
        let menu = null;
        let drag = null;
        let suppressClick = false;
        let refreshTimer = 0;
        let lastLoad = 0;
        let loadSeq = 0;
        let lastJson = '';
        let pendingSave = null;   // {siblings, ids, timer}
        let saving = 0;
        let staleWhileSaving = false;
        let saveChain = Promise.resolve();
        const index = new Map();  // key -> {node, parentKey, siblings, group}
        const isActive = () => !destroyed;
        const hosts = () => [panel, sheet && sheet.host].filter(Boolean);
        const inSheet = host => Boolean(sheet && sheet.host === host);

        // ---------- data ----------
        function load() {
            const seq = ++loadSeq;
            lastLoad = Date.now();
            util.post({
                url: url, data: scope(), loader: false,
                done: res => {
                    if (destroyed || seq !== loadSeq) return;
                    // 排序还在保存：这次拿到的可能是旧顺序，等保存完再取
                    if (pendingSave || saving) {
                        staleWhileSaving = true;
                        return;
                    }
                    const next = (res && res.data) || {total: 0, main: [], merchants: []};
                    const json = JSON.stringify(next);
                    if (json === lastJson && !failed) return;
                    lastJson = json;
                    data = next;
                    failed = false;
                    buildIndex();
                    //选中的分类不在新范围里了（换了显示范围 / 分类被删）：回到「全部商品」并通知列表
                    if (selected && !index.has('c' + selected)) {
                        select(0);
                    }
                    renderAll();
                },
                error: () => {
                    if (destroyed || seq !== loadSeq) return;
                    failed = true;
                    renderAll();
                },
                fail: () => {
                    if (destroyed || seq !== loadSeq) return;
                    failed = true;
                    renderAll();
                }
            });
        }

        function buildIndex() {
            index.clear();
            const walk = (nodes, parentKey) => (nodes || []).forEach(node => {
                index.set('c' + node.id, {node, parentKey, siblings: nodes});
                walk(node.children, 'c' + node.id);
            });
            walk(data.main, '');
            (data.merchants || []).forEach(group => {
                index.set('m' + group.id, {node: group, parentKey: '', group: true, siblings: data.merchants});
                walk(group.children, 'm' + group.id);
            });
        }

        function selectedName() {
            if (!selected) return T('全部商品');
            const hit = index.get('c' + selected);
            return hit ? plain(hit.node.name) : T('全部商品');
        }

        // 分类有变化：重新取树，并让商品列表刷新（改名后商品行里的分类、删除后连带删掉的商品都会变）
        function changed(kind) {
            lastJson = '';
            load();
            onMutate(kind);
        }

        // ---------- render ----------
        const matches = name => !query || plain(name).toLowerCase().includes(query);
        // 搜索时：节点自身命中，或有下级命中，才显示
        function visible(node) {
            if (!query) return true;
            if (matches(node.name)) return true;
            return (node.children || []).some(visible);
        }

        function highlight(name) {
            const text = plain(name);
            if (!query) return esc(text);
            const at = text.toLowerCase().indexOf(query);
            if (at < 0) return esc(text);
            return esc(text.slice(0, at)) + '<mark>' + esc(text.slice(at, at + query.length)) + '</mark>' + esc(text.slice(at + query.length));
        }

        function isOpen(key) {
            return query ? true : expanded.has(key);
        }

        function row(key, depth, inner, attrs) {
            return `<div class="md-cat__row${attrs.cls || ''}" role="treeitem" tabindex="-1" data-key="${key}" aria-level="${depth + 1}"`
                + (attrs.expandable ? ` aria-expanded="${isOpen(key)}"` : '')
                + (attrs.selectable ? ` aria-selected="${attrs.selected ? 'true' : 'false'}"` : '')
                + ` style="--depth:${depth}">${inner}</div>`;
        }

        function thumb(node) {
            if (node.icon && !brokenIcons.has(node.icon)) {
                return `<img class="md-cat__img" src="${esc(node.icon)}" alt="" loading="lazy" draggable="false">`;
            }
            return icon('folder', 'md-cat__ico--fallback');
        }

        function nodesHtml(nodes, depth) {
            const shown = (nodes || []).filter(visible);
            const sortable = Boolean(actions) && !query && shown.length > 1;
            return shown.map(node => {
                const key = 'c' + node.id;
                const kids = (node.children || []).filter(visible);
                const enabled = Number(node.status) === 1;
                const hidden = Number(node.hide) === 1;
                const tag = !enabled ? T('停用') : (hidden ? T('隐藏') : '');
                const acts = actions
                    ? `<span class="md-cat__acts">`
                    + (sortable ? `<span class="md-cat__grip" title="${esc(T('按住拖动，调整同级分类的顺序'))}">${icon('drag_indicator')}</span>` : '')
                    + `<button type="button" class="md-cat__more" data-more tabindex="-1" aria-haspopup="menu" aria-label="${esc(T('更多操作'))}" title="${esc(T('更多操作'))}">${icon('more_horiz')}</button></span>`
                    : '';
                const inner = `<span class="md-cat__toggle" data-toggle>${kids.length ? icon('chevron_right') : ''}</span>${thumb(node)}`
                    + `<span class="md-cat__name" title="${esc(plain(node.name))}">${highlight(node.name)}</span>`
                    + (tag ? `<span class="md-cat__tag">${esc(tag)}</span>` : '')
                    + `<span class="md-cat__end"><span class="md-cat__count">${Number(node.count) || 0}</span>${acts}</span>`;
                const html = row(key, depth, inner, {
                    cls: (selected === node.id ? ' is-selected' : '') + (!enabled || hidden ? ' is-off' : '') + (actions ? ' has-acts' : ''),
                    expandable: kids.length > 0, selectable: true, selected: selected === node.id
                });
                return html + (kids.length && isOpen(key) ? `<div role="group">${nodesHtml(kids, depth + 1)}</div>` : '');
            }).join('');
        }

        function treeHtml() {
            if (failed) {
                return `<div class="md-cat__empty">${esc(T('分类加载失败'))}，<button type="button" class="md-cat__retry" data-retry>${esc(T('重试'))}</button></div>`;
            }
            if (!data) {
                return `<div class="md-cat__empty">${esc(T('正在读取分类…'))}</div>`;
            }
            const main = (data.main || []).filter(visible);
            const groups = (data.merchants || []).filter(visible);
            let html = '';
            if (!query) {
                html += row('all', 0, `<span class="md-cat__toggle"></span>${icon('inventory_2', 'md-cat__ico')}`
                    + `<span class="md-cat__name">${esc(T('全部商品'))}</span><span class="md-cat__end"><span class="md-cat__count">${Number(data.total) || 0}</span></span>`,
                    {cls: selected ? '' : ' is-selected', selectable: true, selected: !selected});
            }
            if (main.length) {
                if ((data.merchants || []).length) html += `<div class="md-cat__section">${esc(T('主站分类'))}</div>`;
                html += nodesHtml(main, 0);
            }
            if (groups.length) {
                html += `<div class="md-cat__section">${esc(T('商家分类'))}</div>`;
                html += groups.map(group => {
                    const key = 'm' + group.id;
                    const inner = `<span class="md-cat__toggle" data-toggle>${icon('chevron_right')}</span>`
                        + `${icon('storefront', 'md-cat__ico')}<span class="md-cat__name" title="${esc(group.name)}">${highlight(group.name)}</span>`
                        + `<span class="md-cat__end"><span class="md-cat__count">${Number(group.count) || 0}</span></span>`;
                    return `<div class="md-cat__group">${row(key, 0, inner, {cls: ' is-group', expandable: true})}`
                        + (isOpen(key) ? `<div role="group">${nodesHtml(group.children, 1)}</div>` : '') + '</div>';
                }).join('');
            }
            if (!main.length && !groups.length) {
                html += `<div class="md-cat__empty">${esc(query ? T('没有匹配的分类') : T('还没有分类'))}</div>`;
            }
            return html;
        }

        const addHtml = () => actions
            ? `<button type="button" class="md-cat__btn md-cat__btn--add" data-create aria-label="${esc(T('添加分类'))}" title="${esc(T('添加分类'))}">${icon('add')}</button>`
            : '';
        const searchHtml = () => `<label class="md-cat__search">${icon('search')}<input type="search" data-search placeholder="${esc(T('搜索分类'))}" aria-label="${esc(T('搜索分类'))}" autocomplete="off" value="${esc(query)}"></label>`;

        function shellHtml(inSheet) {
            return (inSheet ? '<div class="md-cat-sheet__grip" aria-hidden="true"></div>' : '')
                + `<div class="md-cat__head">${icon('account_tree', 'md-cat__head-ico')}`
                + `<span class="md-cat__title">${esc(T('商品分类'))}</span>${addHtml()}`
                + (inSheet
                    ? `<button type="button" class="md-cat__btn" data-close aria-label="${esc(T('关闭'))}">${icon('close')}</button>`
                    : `<button type="button" class="md-cat__btn" data-collapse aria-label="${esc(T('收起分类栏'))}" title="${esc(T('收起分类栏'))}">${icon('keyboard_double_arrow_left')}</button>`)
                + '</div>'
                + searchHtml()
                + `<div class="md-cat__body" role="tree" aria-label="${esc(T('商品分类'))}" data-body></div>`
                + (inSheet ? '' : `<div class="md-cat__rail"><button type="button" class="md-cat__btn" data-expand aria-label="${esc(T('展开分类栏'))}">${icon('keyboard_double_arrow_right')}</button>`
                    + `<span class="md-cat__btn md-cat__rail-dot" aria-hidden="true">${icon('account_tree')}</span></div>`);
        }

        // 新建的分类都属于主站：只看商家分类时新建了也不在这棵树里，按钮先藏起来
        function syncCreate(host) {
            const btn = host.querySelector('[data-create]');
            if (btn) btn.hidden = String(scope().display_scope ?? '') === '2';
        }

        // 只重画树本体：搜索框保持焦点与光标，滚动位置不跳，键盘焦点留在原来那一行
        function renderBody(host) {
            const body = host && host.querySelector('[data-body]');
            if (!body) return;
            const active = document.activeElement;
            const focusedRow = active && body.contains(active) ? active.closest('.md-cat__row') : null;
            const focusedKey = focusedRow ? focusedRow.dataset.key : '';
            const top = body.scrollTop;
            body.innerHTML = treeHtml();
            body.scrollTop = top;
            body.querySelectorAll('img.md-cat__img').forEach(img => img.addEventListener('error', () => {
                brokenIcons.add(img.getAttribute('src'));
                img.replaceWith(Object.assign(document.createElement('span'), {
                    className: 'material-icons-outlined md-cat__ico--fallback', textContent: 'folder'
                }));
            }, {once: true}));
            const current = (focusedKey && body.querySelector(`.md-cat__row[data-key="${focusedKey}"]`))
                || body.querySelector('.md-cat__row.is-selected') || body.querySelector('.md-cat__row');
            if (current) {
                current.tabIndex = 0;
                if (focusedKey) current.focus({preventScroll: true});
            }
            if (menu && menu.host === host) {
                const opened = body.querySelector(`.md-cat__row[data-key="${menu.key}"]`);
                opened && opened.classList.add('is-menu');
            }
            syncCreate(host);
        }

        function renderPanel() {
            if (!panel) return;
            if (!panel.dataset.mdCatReady) {
                panel.innerHTML = shellHtml(false);
                panel.dataset.mdCatReady = '1';
            }
            panel.classList.toggle('is-collapsed', collapsed);
            panel.setAttribute('aria-label', T('商品分类'));
            const dot = panel.querySelector('.md-cat__rail-dot');
            if (dot) {
                dot.classList.toggle('is-on', Boolean(selected));
                panel.title = collapsed ? `${T('展开分类栏')} · ${selectedName()}` : '';
            }
            renderBody(panel);
        }

        function renderBar() {
            if (!bar) return;
            bar.innerHTML = `<button type="button" class="md-cat-bar__btn${selected ? ' is-on' : ''}" data-open-sheet aria-haspopup="dialog">`
                + `${icon('account_tree')}<span class="md-cat-bar__label">${esc(T('分类'))}</span>`
                + `<span class="md-cat-bar__value">${esc(selectedName())}</span>${icon('expand_more')}</button>`;
        }

        function renderAll() {
            renderPanel();
            renderBar();
            if (sheet) renderBody(sheet.host);
        }

        function flash(key) {
            if (reduceMotion()) return;
            hosts().forEach(host => {
                const el = host.querySelector(`.md-cat__row[data-key="${key}"]`);
                if (!el) return;
                el.classList.remove('is-dropped');
                void el.offsetWidth;
                el.classList.add('is-dropped');
                el.addEventListener('animationend', () => el.classList.remove('is-dropped'), {once: true});
            });
        }

        function applyLayout() {
            if (layout) layout.classList.toggle('is-mobile', isMobileLayout());
            if (panel) {
                // 贴顶位置：避开后台固定顶栏
                const header = document.getElementById('kt_header');
                const fixed = header && getComputedStyle(header).position === 'fixed';
                panel.style.setProperty('--md-cat-top', ((fixed ? header.getBoundingClientRect().height : 0) + 16) + 'px');
            }
            if (sheet && !isMobileLayout()) closeSheet();
        }

        // ---------- selection / expand ----------
        function select(id, silent) {
            selected = Number(id) || 0;
            if (selected) {
                // 展开到选中分类所在位置
                let parent = (index.get('c' + selected) || {}).parentKey;
                while (parent) {
                    expanded.add(parent);
                    parent = (index.get(parent) || {}).parentKey;
                }
                store.set(KEY_EXPANDED, Array.from(expanded));
            }
            if (!silent) onSelect(selected, selected ? (index.get('c' + selected) || {}).node : null);
        }

        function toggle(key) {
            if (query) return;
            expanded.has(key) ? expanded.delete(key) : expanded.add(key);
            store.set(KEY_EXPANDED, Array.from(expanded));
        }

        function setCollapsed(value) {
            collapsed = value;
            store.set(KEY_COLLAPSED, collapsed);
            closeMenu();
            renderPanel();
            // 表格宽度变了：让 bootstrap-table 重新量一次表头
            setTimeout(() => window.dispatchEvent(new Event('resize')), reduceMotion() ? 0 : 240);
        }

        function focusRow(host, key) {
            const el = host.querySelector(`.md-cat__row[data-key="${key}"]`);
            if (!el) return;
            host.querySelectorAll('.md-cat__row[tabindex="0"]').forEach(r => { r.tabIndex = -1; });
            el.tabIndex = 0;
            el.focus({preventScroll: false});
        }

        function activate(host, rowEl, viaToggle) {
            const key = rowEl.dataset.key;
            const entry = index.get(key);
            const expandable = rowEl.hasAttribute('aria-expanded');
            if (key === 'all') {
                select(0);
            } else if (entry && entry.group) {
                toggle(key);
            } else if (viaToggle && expandable) {
                toggle(key);
            } else if (entry) {
                select(entry.node.id);
            }
            renderAll();
            focusRow(host, key);
            if (!viaToggle && inSheet(host) && !(entry && entry.group)) closeSheet();
        }

        // ---------- management ----------
        const nodeOf = key => {
            const entry = index.get(key);
            return entry && !entry.group ? entry : null;
        };
        const named = node => Object.assign({}, node, {name: plain(node.name)});

        function createCategory(parent) {
            if (!actions) return;
            // 标题与分类管理页的「添加分类 / 修改分类」弹窗一致
            actions.openEditor(`<i class="fa-duotone fa-regular fa-circle-plus"></i> ${esc(T(parent ? '添加子分类' : '添加分类'))}`, parent ? {pid: parent.id} : {}, {
                isActive, done: () => {
                    if (parent) {
                        expanded.add('c' + parent.id);
                        store.set(KEY_EXPANDED, Array.from(expanded));
                    }
                    changed('create');
                }
            });
        }

        function editCategory(node) {
            if (!actions) return;
            const {children, count, share_url, ...row} = node;
            actions.openEditor(util.icon('fa-duotone fa-regular fa-pen-to-square me-1') + esc(T('修改分类')), row, {isActive, done: () => changed('edit')});
        }

        // 停用会连带停用全部下级、启用会连带启用停用中的上级：会连带别的分类时才弹确认（说明连带范围），否则直接改
        function setStatus(entry, status) {
            if (!actions) return;
            const node = entry.node;
            let cascades = false;
            if (status === 0) {
                const walk = list => (list || []).some(child => Number(child.status) === 1 || walk(child.children));
                cascades = walk(node.children);
            } else {
                let parent = entry.parentKey;
                while (parent && !cascades) {
                    const up = index.get(parent);
                    if (!up || up.group) break;
                    cascades = Number(up.node.status) === 0;
                    parent = up.parentKey;
                }
            }
            actions.confirmStatus([named(node)], status, () => {
                util.post({
                    url: '/admin/api/category/status', data: {list: [node.id], status: status},
                    done: () => {
                        if (destroyed) return;
                        message.success(status ? '启用成功' : '停用成功');
                        changed('status');
                    }
                });
            }, {detailed: cascades});
        }

        function setHidden(node, hide) {
            util.post({
                url: '/admin/api/category/save', data: {id: node.id, hide: hide},
                done: () => {
                    if (destroyed) return;
                    message.success('已更新 (｡•ᴗ-)');
                    changed('hide');
                }
            });
        }

        function removeCategory(node) {
            if (!actions) return;
            actions.confirmDelete([named(node)], previewToken => {
                util.post('/admin/api/category/del', {list: [node.id], preview_token: previewToken}, () => {
                    if (destroyed) return;
                    message.success('删除成功');
                    changed('delete');
                });
            }, {isActive});
        }

        function copyLink(node) {
            if (!node.share_url) return;
            util.copyTextToClipboard(node.share_url, () => message.success('复制成功'), () => message.error('复制失败'));
        }

        // ---------- ordering ----------
        // 本地先改好立即重画（不闪），保存排队按顺序发；菜单里连续上移 / 下移合并成一次保存
        function reorder(entry, ids, immediate) {
            const before = entry.siblings.map(n => n.id);
            if (ids.join(',') === before.join(',')) return false;
            const byId = new Map(entry.siblings.map(n => [n.id, n]));
            entry.siblings.splice(0, entry.siblings.length, ...ids.map(id => byId.get(id)));
            //服务器按位置写 0..n-1：本地同步，免得随后打开编辑弹窗把旧排序值又存回去
            entry.siblings.forEach((n, i) => { n.sort = i; });
            lastJson = JSON.stringify(data);
            renderAll();
            flash('c' + entry.node.id);
            queueSave(entry.siblings, ids, immediate);
            return true;
        }

        function queueSave(siblings, ids, immediate) {
            if (pendingSave && pendingSave.siblings !== siblings) flushSave();
            if (pendingSave) clearTimeout(pendingSave.timer);
            pendingSave = {siblings, ids, timer: setTimeout(flushSave, immediate ? 0 : SAVE_DELAY)};
        }

        function flushSave() {
            if (!pendingSave) return;
            const {ids, timer} = pendingSave;
            clearTimeout(timer);
            pendingSave = null;
            saving++;
            saveChain = saveChain.then(() => new Promise(resolve => {
                const settle = (ok, text) => {
                    saving--;
                    resolve();
                    if (destroyed) return;
                    ok ? message.success(text) : message.error(text);
                    if (!ok) {
                        lastJson = '';
                        staleWhileSaving = true;
                    }
                    if (!pendingSave && !saving && staleWhileSaving) {
                        staleWhileSaving = false;
                        load();
                    }
                };
                util.post({
                    url: '/admin/api/category/reorder', data: {list: ids}, loader: false,
                    done: res => settle(true, res?.msg || '排序已保存'),
                    error: res => settle(false, res?.msg || '排序保存失败'),
                    fail: () => settle(false, '网络异常，排序未保存')
                });
            }));
        }

        function move(entry, delta) {
            const ids = entry.siblings.map(n => n.id);
            const at = ids.indexOf(entry.node.id);
            const to = at + delta;
            if (query || at < 0 || to < 0 || to >= ids.length) return false;
            ids.splice(at, 1);
            ids.splice(to, 0, entry.node.id);
            return reorder(entry, ids, false);
        }

        // ---------- 「⋯」菜单 ----------
        function closeMenu() {
            if (!menu) return;
            const {el, host, key} = menu;
            menu = null;
            el.remove();
            const rowEl = host && host.querySelector(`.md-cat__row[data-key="${key}"]`);
            rowEl && rowEl.classList.remove('is-menu');
        }

        function anchorOf(host, key) {
            const rowEl = host.querySelector(`.md-cat__row[data-key="${key}"]`);
            if (!rowEl) return null;
            rowEl.scrollIntoView({block: 'nearest'});
            const r = (rowEl.querySelector('[data-more]') || rowEl).getBoundingClientRect();
            return {right: r.right, bottom: r.bottom, top: r.top};
        }

        function openMenu(host, key, at, focusAct) {
            const entry = nodeOf(key);
            if (!entry || !actions) return;
            const reopening = Boolean(menu && menu.key === key && focusAct);
            closeMenu();
            const node = entry.node;
            const ids = entry.siblings.map(n => n.id);
            const pos = ids.indexOf(node.id);
            const item = (act, ico, label, opts = {}) => `<button type="button" class="md-cat-menu__item${opts.danger ? ' is-danger' : ''}" role="menuitem" data-act="${act}"${opts.disabled ? ' disabled' : ''}>`
                + `${icon(ico)}<span>${esc(T(label))}</span></button>`;
            const el = document.createElement('div');
            el.className = 'md-cat-menu' + (reopening ? ' is-still' : '')
                + (inSheet(host) || (window.matchMedia && window.matchMedia('(hover: none)').matches) ? ' is-touch' : '');
            el.setAttribute('role', 'menu');
            el.setAttribute('aria-label', plain(node.name));
            el.innerHTML = `<div class="md-cat-menu__title">${esc(plain(node.name))}</div>`
                + (Number(node.owner) === 0 ? item('child', 'create_new_folder', '添加子分类') : '')
                + item('edit', 'edit', '编辑')
                + '<div class="md-cat-menu__sep"></div>'
                + item('up', 'arrow_upward', '上移', {disabled: query || pos <= 0})
                + item('down', 'arrow_downward', '下移', {disabled: query || pos < 0 || pos >= ids.length - 1})
                + '<div class="md-cat-menu__sep"></div>'
                + (Number(node.status) === 1 ? item('disable', 'toggle_off', '停用') : item('enable', 'toggle_on', '启用'))
                + (Number(node.hide) === 1 ? item('unhide', 'visibility', '取消隐藏') : item('hide', 'visibility_off', '隐藏'))
                + item('copy', 'link', '复制推广链接')
                + '<div class="md-cat-menu__sep"></div>'
                + item('delete', 'delete_outline', '删除', {danger: true});
            document.body.appendChild(el);
            menu = {el, host, key};
            const rowEl = host.querySelector(`.md-cat__row[data-key="${key}"]`);
            rowEl && rowEl.classList.add('is-menu');

            // 定位：锚点下方右对齐，放不下就翻到上方 / 往左挪
            const rect = el.getBoundingClientRect();
            const vw = window.innerWidth, vh = window.innerHeight;
            let left = at.right != null ? at.right - rect.width : at.x;
            let top = at.bottom != null ? at.bottom + 4 : at.y;
            if (left + rect.width > vw - 8) left = vw - rect.width - 8;
            if (left < 8) left = 8;
            if (top + rect.height > vh - 8) top = Math.max(8, (at.top != null ? at.top - 4 : at.y) - rect.height);
            el.style.left = Math.round(left) + 'px';
            el.style.top = Math.round(top) + 'px';

            el.addEventListener('click', event => {
                const btn = event.target.closest('[data-act]');
                if (!btn || btn.disabled) return;
                const act = btn.dataset.act;
                const current = nodeOf(key);
                if (!current) {
                    closeMenu();
                    return;
                }
                //上移 / 下移后菜单留着、跟到新位置，方便连续调整
                if (act === 'up' || act === 'down') {
                    if (move(current, act === 'up' ? -1 : 1)) {
                        const anchor = anchorOf(host, key);
                        anchor ? openMenu(host, key, anchor, act) : closeMenu();
                    }
                    return;
                }
                closeMenu();
                ({
                    child: () => createCategory(current.node),
                    edit: () => editCategory(current.node),
                    enable: () => setStatus(current, 1),
                    disable: () => setStatus(current, 0),
                    hide: () => setHidden(current.node, 1),
                    unhide: () => setHidden(current.node, 0),
                    copy: () => copyLink(current.node),
                    delete: () => removeCategory(current.node)
                })[act]?.();
            });
            el.addEventListener('keydown', event => {
                const items = Array.from(el.querySelectorAll('.md-cat-menu__item:not(:disabled)'));
                const i = items.indexOf(document.activeElement);
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    items[(i + 1) % items.length]?.focus();
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    items[(i - 1 + items.length) % items.length]?.focus();
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
                } else if (event.key === 'Escape' || event.key === 'Tab') {
                    event.preventDefault();
                    event.stopPropagation();
                    closeMenu();
                    focusRow(host, key);
                }
            });
            const target = (focusAct && el.querySelector(`[data-act="${focusAct}"]:not(:disabled)`))
                || (focusAct && el.querySelector('[data-act="up"]:not(:disabled), [data-act="down"]:not(:disabled)'))
                || el.querySelector('.md-cat-menu__item:not(:disabled)');
            target && target.focus({preventScroll: true});
        }

        // ---------- drag (desktop pointer) ----------
        // 手感与分类管理页的拖动一致：按住移动几像素才开始；浮起一张紧凑卡片，拖动图标压在指针正下方、横竖都跟；
        // 拖父级时下级先收起；同级其它分类滑开让位，原位置留虚线框；松手卡片落进新位置再保存。
        function dragStart(event, host) {
            if (event.pointerType === 'touch' || event.button !== 0 || !actions || drag) return;
            const rowEl = event.target.closest('.md-cat__row');
            if (!rowEl || event.target.closest('[data-more], .md-cat__toggle:not(:empty)')) return;
            const entry = nodeOf(rowEl.dataset.key);
            if (!entry) return;
            drag = {host, rowEl, entry, x: event.clientX, y: event.clientY, active: false, blocked: false};
        }

        function dragPointerMove(event) {
            if (!drag || drag.blocked) return;
            if (!drag.active) {
                const dist = Math.max(Math.abs(event.clientX - drag.x), Math.abs(event.clientY - drag.y));
                if (dist < DRAG_START) return;
                const reason = query ? ['warning', '正在按名称搜索，列表不完整，请先清空搜索再拖动排序']
                    : (drag.entry.siblings.length < 2 ? ['info', '这一层级下只有这一个分类，不需要排序'] : null);
                if (reason) {
                    if (dist >= DRAG_HINT) {
                        drag.blocked = true;
                        message[reason[0]](reason[1]);
                    }
                    return;
                }
                if (!beginDrag()) {
                    drag = null;
                    return;
                }
            }
            event.preventDefault();
            drag.lastX = event.clientX;
            drag.lastY = event.clientY;
            dragUpdate();
        }

        function beginDrag() {
            const {host, entry} = drag;
            const body = host.querySelector('[data-body]');
            if (!body) return false;
            const blocks = [];
            for (const node of entry.siblings) {
                const el = body.querySelector(`.md-cat__row[data-key="c${node.id}"]`);
                if (!el) return false;
                const next = el.nextElementSibling;
                blocks.push({id: node.id, el, sub: next && next.getAttribute('role') === 'group' ? next : null});
            }
            const from = blocks.findIndex(b => b.id === entry.node.id);
            if (from < 0) return false;
            closeMenu();
            const self = blocks[from];
            if (self.sub) self.sub.style.display = 'none';
            //位置记成树内容坐标（含 scrollTop），自动滚动后依然有效
            const bodyRect = body.getBoundingClientRect();
            blocks.forEach(b => {
                const r = b.el.getBoundingClientRect();
                const bottom = b !== self && b.sub ? b.sub.getBoundingClientRect().bottom : r.bottom;
                b.top = r.top - bodyRect.top + body.scrollTop;
                b.height = bottom - r.top;
            });
            const ghost = document.createElement('div');
            ghost.className = 'md-cat__ghost';
            ghost.setAttribute('aria-hidden', 'true');
            ghost.innerHTML = `${icon('drag_indicator')}<span>${esc(plain(entry.node.name))}</span>`;
            document.body.appendChild(ghost);
            body.classList.add('is-sorting');
            self.el.classList.add('is-placeholder');
            document.documentElement.classList.add('md-cat-dragging');
            Object.assign(drag, {active: true, body, blocks, from, to: from, self, ghost, speed: 0, raf: 0});
            return true;
        }

        const shiftBlock = (b, dy) => {
            const value = dy ? `translateY(${dy}px)` : '';
            b.el.style.transform = value;
            if (b.sub) b.sub.style.transform = value;
        };

        function layoutBlocks(d) {
            const {blocks, from, to} = d;
            const size = blocks[from].height;
            let selfShift = 0;
            blocks.forEach((b, j) => {
                if (j === from) return;
                let dy = 0;
                if (j > from && j <= to) {
                    dy = -size;
                    selfShift += b.height;
                } else if (j < from && j >= to) {
                    dy = size;
                    selfShift -= b.height;
                }
                shiftBlock(b, dy);
            });
            shiftBlock(blocks[from], selfShift);
        }

        function dragUpdate() {
            const d = drag;
            if (!d || !d.active) return;
            d.ghost.style.transform = `translate3d(${Math.round(d.lastX - GHOST_GRIP_X)}px, ${Math.round(d.lastY - GHOST_GRIP_Y)}px, 0)`;
            const rect = d.body.getBoundingClientRect();
            const y = d.lastY - rect.top + d.body.scrollTop;
            const mid = b => b.top + b.height / 2;
            let to = d.from;
            for (let j = d.from - 1; j >= 0 && y < mid(d.blocks[j]); j--) to = j;
            for (let j = d.from + 1; j < d.blocks.length && y > mid(d.blocks[j]); j++) to = j;
            if (to !== d.to) {
                d.to = to;
                layoutBlocks(d);
            }
            // 指针贴近树的可见上下沿：先滚树，树到头了再滚页面（分类栏下半截在屏幕外时）
            const top = Math.max(rect.top, 0);
            const bottom = Math.min(rect.bottom, window.innerHeight);
            const edge = 40;
            let speed = 0;
            if (d.lastY < top + edge) speed = -Math.ceil((top + edge - d.lastY) / 3);
            else if (d.lastY > bottom - edge) speed = Math.ceil((d.lastY - bottom + edge) / 3);
            d.speed = Math.max(-24, Math.min(24, speed));
            if (d.speed && !d.raf) d.raf = requestAnimationFrame(scrollStep);
        }

        function scrollStep() {
            const d = drag;
            if (!d || !d.active) return;
            d.raf = 0;
            if (!d.speed) return;
            const before = d.body.scrollTop;
            d.body.scrollTop = before + d.speed;
            if (d.body.scrollTop === before) {
                const y0 = window.scrollY;
                window.scrollBy(0, d.speed);
                if (window.scrollY === y0) return;
            }
            dragUpdate();
        }

        function endDrag(commit) {
            const d = drag;
            drag = null;
            if (!d) return;
            if (d.blocked || d.active) {
                suppressClick = true;
                setTimeout(() => { suppressClick = false; }, 0);
            }
            if (!d.active) return;
            if (d.raf) cancelAnimationFrame(d.raf);
            document.documentElement.classList.remove('md-cat-dragging');
            if (!commit && d.to !== d.from) {
                d.to = d.from;
                layoutBlocks(d);
            }
            const {blocks, from, to, body, self} = d;
            const moved = to !== from;
            const finish = () => {
                d.ghost.remove();
                body.classList.remove('is-sorting');
                if (moved && !destroyed) {
                    const ids = blocks.map(b => b.id);
                    ids.splice(from, 1);
                    ids.splice(to, 0, self.id);
                    reorder(d.entry, ids, true);
                    return;
                }
                blocks.forEach(b => shiftBlock(b, 0));
                self.el.classList.remove('is-placeholder');
                if (self.sub) self.sub.style.display = '';
            };
            if (reduceMotion() || destroyed) {
                finish();
                return;
            }
            // 落点按内容坐标算（不读动画中途的位置）：卡片里的名字对齐到行里名字的位置
            let top = blocks[from].top;
            if (to < from) top = blocks[to].top;
            else if (to > from) top = blocks[from].top + blocks.slice(from + 1, to + 1).reduce((sum, b) => sum + b.height, 0);
            const rect = body.getBoundingClientRect();
            const name = self.el.querySelector('.md-cat__name');
            const x = (name ? name.getBoundingClientRect().left : rect.left) - GHOST_NAME_X;
            const y = top - body.scrollTop + rect.top + (self.height - d.ghost.offsetHeight) / 2;
            let settled = false;
            const settle = () => {
                if (settled) return;
                settled = true;
                finish();
            };
            d.ghost.classList.add('is-landing');
            d.ghost.style.transform = `translate3d(${Math.round(x)}px, ${Math.round(y)}px, 0)`;
            d.ghost.addEventListener('transitionend', settle, {once: true});
            setTimeout(settle, 260);
        }

        // ---------- keyboard ----------
        function onKey(host, event) {
            const rowEl = event.target.closest('.md-cat__row');
            if (!rowEl || event.target.closest('[data-more]')) return;
            const rows = Array.from(host.querySelectorAll('.md-cat__row'));
            const i = rows.indexOf(rowEl);
            const key = rowEl.dataset.key;
            const entry = index.get(key);
            const moveFocus = target => {
                if (target) {
                    event.preventDefault();
                    focusRow(host, target.dataset.key);
                }
            };
            if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                const own = nodeOf(key);
                if (own && actions) {
                    event.preventDefault();
                    if (move(own, event.key === 'ArrowUp' ? -1 : 1)) focusRow(host, key);
                }
                return;
            }
            switch (event.key) {
                case 'ArrowDown':
                    moveFocus(rows[i + 1]);
                    break;
                case 'ArrowUp':
                    moveFocus(rows[i - 1]);
                    break;
                case 'Home':
                    moveFocus(rows[0]);
                    break;
                case 'End':
                    moveFocus(rows[rows.length - 1]);
                    break;
                case 'ArrowRight':
                    if (rowEl.getAttribute('aria-expanded') === 'false') {
                        event.preventDefault();
                        toggle(key);
                        renderAll();
                        focusRow(host, key);
                    } else if (rowEl.getAttribute('aria-expanded') === 'true') moveFocus(rows[i + 1]);
                    break;
                case 'ArrowLeft':
                    if (rowEl.getAttribute('aria-expanded') === 'true') {
                        event.preventDefault();
                        toggle(key);
                        renderAll();
                        focusRow(host, key);
                    } else if (entry && entry.parentKey) moveFocus(host.querySelector(`.md-cat__row[data-key="${entry.parentKey}"]`));
                    break;
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    activate(host, rowEl, false);
                    break;
                case 'ContextMenu':
                case 'F10':
                    if (event.key === 'F10' && !event.shiftKey) break;
                    if (nodeOf(key) && actions) {
                        event.preventDefault();
                        const anchor = anchorOf(host, key);
                        anchor && openMenu(host, key, anchor);
                    }
                    break;
            }
        }

        function bindHost(host) {
            const $host = $(host);
            $host.on('click' + ns, '.md-cat__row', function (event) {
                if (suppressClick) return;
                const more = event.target.closest('[data-more]');
                if (more) {
                    if (inSheet(host) && openActions(this.dataset.key)) return;
                    if (menu && menu.key === this.dataset.key) {
                        closeMenu();
                    } else {
                        const r = more.getBoundingClientRect();
                        openMenu(host, this.dataset.key, {right: r.right, bottom: r.bottom, top: r.top});
                    }
                    return;
                }
                activate(host, this, Boolean(event.target.closest('[data-toggle]')) && this.hasAttribute('aria-expanded'));
            });
            $host.on('contextmenu' + ns, '.md-cat__row', function (event) {
                if (!nodeOf(this.dataset.key) || !actions) return;
                event.preventDefault();
                if (inSheet(host) && openActions(this.dataset.key)) return;
                openMenu(host, this.dataset.key, {x: event.clientX, y: event.clientY});
            });
            $host.on('keydown' + ns, '.md-cat__row', event => onKey(host, event));
            $host.on('pointerdown' + ns, event => dragStart(event.originalEvent || event, host));
            $host.on('click' + ns, '[data-retry]', () => {
                failed = false;
                data = null;
                lastJson = '';
                renderAll();
                load();
            });
            $host.on('click' + ns, '[data-create]', () => createCategory(null));
            $host.on('input' + ns, '[data-search]', function () {
                query = this.value.trim().toLowerCase();
                closeMenu();
                renderAll();
                // 另一处（侧栏 / 抽屉）的搜索框同步文字
                hosts().forEach(other => {
                    const input = other.querySelector('[data-search]');
                    if (input && input !== this) input.value = this.value;
                });
            });
            $host.find('[data-body]').on('scroll' + ns, () => {
                if (menu && menu.host === host) closeMenu();
            });
        }

        // ---------- mobile sheet ----------
        // 手机版「⋯」：后台手机版自带的操作列表。复制链接要在点击当下写剪贴板（iOS 要求用户手势内），不等列表关完
        function openActions(key) {
            const mobile = window.AdminMobile;
            const entry = nodeOf(key);
            if (!entry || !actions || !mobile || typeof mobile.openActions !== 'function') return false;
            const node = entry.node;
            const ids = entry.siblings.map(n => n.id);
            const pos = ids.indexOf(node.id);
            const enabled = Number(node.status) === 1;
            const hidden = Number(node.hide) === 1;
            const run = fn => () => {
                const current = nodeOf(key);
                if (current && !destroyed) fn(current);
            };
            const copy = {label: T('复制推广链接'), icon: 'link'};
            const list = [
                Number(node.owner) === 0 ? {label: T('添加子分类'), icon: 'create_new_folder', run: run(e => createCategory(e.node))} : null,
                {label: T('编辑'), icon: 'edit', run: run(e => editCategory(e.node))},
                {label: T('上移'), icon: 'arrow_upward', disabled: Boolean(query) || pos <= 0, run: run(e => move(e, -1))},
                {label: T('下移'), icon: 'arrow_downward', disabled: Boolean(query) || pos < 0 || pos >= ids.length - 1, run: run(e => move(e, 1))},
                {label: T(enabled ? '停用' : '启用'), icon: enabled ? 'toggle_off' : 'toggle_on', run: run(e => setStatus(e, enabled ? 0 : 1))},
                {label: T(hidden ? '取消隐藏' : '隐藏'), icon: hidden ? 'visibility' : 'visibility_off', run: run(e => setHidden(e.node, hidden ? 0 : 1))},
                copy,
                {label: T('删除'), icon: 'delete_outline', danger: true, run: run(e => removeCategory(e.node))}
            ].filter(Boolean);
            const opened = mobile.openActions({id: 'md-commodity-category-actions', title: plain(node.name), actions: list});
            if (!opened || !opened.element) return false;
            const control = opened.element.querySelectorAll('.admin-mobile-action-list > *')[list.indexOf(copy)];
            control && control.addEventListener('click', event => {
                event.preventDefault();
                copyLink(node);
                opened.close();
            });
            return true;
        }

        function openSheet() {
            if (sheet) return;
            const mobile = window.AdminMobile;
            if (mobile && typeof mobile.openSheet === 'function') {
                const content = document.createElement('div');
                content.className = 'md-cat-touch md-cat-msheet';
                content.innerHTML = `<div class="md-cat__tools">${searchHtml()}${addHtml()}</div>`
                    + `<div class="md-cat__body" role="tree" aria-label="${esc(T('商品分类'))}" data-body></div>`;
                const record = {host: content, managed: true, handle: null};
                sheet = record;
                const opened = mobile.openSheet({
                    id: 'md-commodity-category', title: T('商品分类'), content,
                    onClose: () => {
                        if (sheet === record) teardownSheet(record);
                    }
                });
                if (opened && opened.element) {
                    record.handle = opened;
                    bindHost(content);
                    renderBody(content);
                    requestAnimationFrame(() => {
                        const current = content.querySelector('.md-cat__row.is-selected');
                        current && current.scrollIntoView({block: 'nearest'});
                    });
                    return;
                }
                sheet = null;
            }
            // 备用：自己画的抽屉
            const el = document.createElement('div');
            el.className = 'md-cat-sheet';
            el.setAttribute('role', 'dialog');
            el.setAttribute('aria-modal', 'true');
            el.setAttribute('aria-label', T('商品分类'));
            el.innerHTML = `<div class="md-cat-sheet__scrim" data-close></div><div class="md-cat-sheet__panel md-cat-touch">${shellHtml(true)}</div>`;
            document.body.appendChild(el);
            const host = el.querySelector('.md-cat-sheet__panel');
            sheet = {host, el, managed: false};
            bindHost(host);
            $(el).on('click' + ns, '[data-close]', closeSheet);
            renderBody(host);
            document.documentElement.style.overflow = 'hidden';
            requestAnimationFrame(() => {
                el.classList.add('is-open');
                const current = host.querySelector('.md-cat__row.is-selected');
                current && current.scrollIntoView({block: 'nearest'});
            });
        }

        function teardownSheet(record) {
            if (menu && menu.host === record.host) closeMenu();
            if (drag && drag.host === record.host) endDrag(false);
            $(record.host).find('*').addBack().off(ns);
            if (record.el) $(record.el).find('*').addBack().off(ns);
            if (sheet === record) sheet = null;
        }

        function closeSheet() {
            if (!sheet) return;
            const record = sheet;
            teardownSheet(record);
            if (record.managed) {
                record.handle && record.handle.close();
                return;
            }
            record.el.classList.remove('is-open');
            document.documentElement.style.overflow = '';
            setTimeout(() => $(record.el).remove(), reduceMotion() ? 0 : 280);
            const opener = bar && bar.querySelector('[data-open-sheet]');
            opener && opener.focus({preventScroll: true});
        }

        // ---------- wire ----------
        renderAll();
        applyLayout();
        if (panel) {
            bindHost(panel);
            $(panel).on('click' + ns, '[data-collapse]', event => {
                event.stopPropagation();
                setCollapsed(true);
            });
            $(panel).on('click' + ns, '[data-expand]', event => {
                event.stopPropagation();
                setCollapsed(false);
            });
            $(panel).on('click' + ns, function (event) {
                if (collapsed && !event.target.closest('[data-expand]')) setCollapsed(false);
            });
        }
        if (bar) $(bar).on('click' + ns, '[data-open-sheet]', openSheet);
        $(document).on('pointermove' + ns, event => dragPointerMove(event.originalEvent || event));
        $(document).on('pointerup' + ns + ' pointercancel' + ns, event => {
            if (drag) endDrag(event.type === 'pointerup');
        });
        $(document).on('pointerdown' + ns, event => {
            if (menu && !menu.el.contains(event.target) && !event.target.closest('[data-more]')) closeMenu();
        });
        $(document).on('keydown' + ns, event => {
            if (event.key !== 'Escape') return;
            if (drag && drag.active) {
                endDrag(false);
                return;
            }
            if (menu) {
                closeMenu();
                return;
            }
            if (sheet && !sheet.managed) closeSheet();
        });
        $(document).on('admin:mobile:viewportchange' + ns + ' admin:mobile:mount' + ns + ' admin:mobile:unmount' + ns, applyLayout);
        // 只看宽度：手机浏览器滚动时地址栏伸缩也会触发 resize（只变高度），不该把菜单关掉
        let lastWidth = window.innerWidth;
        $(window).on('resize' + ns, () => {
            if (window.innerWidth !== lastWidth) {
                lastWidth = window.innerWidth;
                closeMenu();
            }
            clearTimeout(applyLayout.t);
            applyLayout.t = setTimeout(applyLayout, 120);
        });
        $(window).on('scroll' + ns, () => {
            if (menu && !(drag && drag.active)) closeMenu();
        });
        $(window).on('blur' + ns, () => {
            if (drag) endDrag(false);
        });
        load();

        return {
            reload() {
                load();
            },
            refreshSoon() {
                clearTimeout(refreshTimer);
                const wait = Math.max(400, 2000 - (Date.now() - lastLoad));
                refreshTimer = setTimeout(() => !destroyed && load(), wait);
            },
            selected: () => selected,
            destroy() {
                if (destroyed) return;
                if (pendingSave) flushSave();
                const d = drag;
                drag = null;
                if (d && d.ghost) d.ghost.remove();
                document.documentElement.classList.remove('md-cat-dragging');
                destroyed = true;
                clearTimeout(refreshTimer);
                clearTimeout(applyLayout.t);
                closeMenu();
                closeSheet();
                if (panel) {
                    $(panel).off(ns).empty();
                    delete panel.dataset.mdCatReady;
                }
                if (bar) $(bar).off(ns).empty();
                $(document).off(ns);
                $(window).off(ns);
            }
        };
    }

    return {attach};
})();
