/**
 * 后台表格「拖动排序」公共实现 —— 分类管理、商品管理共用，两边行为保持一模一样。
 *
 * 电脑版：按住「排序」列旁的手柄移动几像素才算开始（单击不误触）→ 这一行化作一张浮起的卡片，拖动图标压在指针正下方跟着走；
 * 同组其它行实时滑开让位，虚线框标出落点 → 松手后卡片落到目标行的手柄位置淡出，这一行落位高亮，
 * 排序数字原地更新；动画播完再静默刷新一次表格，同步行数据。Esc / 窗口失焦 = 取消。
 *
 * 手机版（后台卡片列表，mobile/fallback.js）：长按卡片任意位置约 0.4 秒 → 卡片浮起、轻振一下，跟着手指上下走，
 * 其它卡片滑开让位；松手后落进空位并保存。等待期间手指移动超过 10px 视为在滑动列表，立刻放弃，不妨碍滚动；
 * 开关、更多、复制、展开这类小按钮照常点。
 *
 * 树形表格（分类）只在同一父级下调整，拖的是「这一行 + 全部下级」，拿起时下级平滑收起、放下后展开。
 *
 * 用法：
 *   列定义里在「排序」列前加：...(window.MdTableDragSort ? [MdTableDragSort.column()] : [])
 *   const dragSort = MdTableDragSort.attach({table, selector, namespace, url, ...});   // 要在 table.render() 之前
 *   table.onComplete(() => dragSort?.sync());   页面销毁时 dragSort?.destroy()
 */
window.MdTableDragSort = (() => {
    const STYLE_ID = 'md-drag-sort-style';
    const DRAG_THRESHOLD = 4;
    const LONG_PRESS_MS = 400;
    const PRESS_SLOP = 10;
    const reduceMotion = Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const SETTLE_MS = reduceMotion ? 0 : 240;
    const FLIP_MS = reduceMotion ? 0 : 260;
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const cssEscape = value => (window.CSS && typeof window.CSS.escape === 'function') ? window.CSS.escape(value) : String(value).replace(/["\\\]]/g, '\\$&');
    // 手机版后台（AdminMobile）把表格画成卡片列表；布局可能随屏幕宽度实时切换，所以每次交互时现判断
    const isMobileLayout = () => Boolean(window.AdminMobile && typeof window.AdminMobile.isEnabled === 'function' && window.AdminMobile.isEnabled());

    const STYLE = `
        /* 行要用 transform 平移，collapse 模式下分隔线不会跟着走（实测会留在原地穿过文字）；
           后台表格只有单元格底边线，换成 separate + 0 间距视觉完全一样，行高也不变 */
        table[data-md-drag]{border-collapse:separate;border-spacing:0}
        .md-drag-handle{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;
            font-size:20px;line-height:1;color:var(--md-on-surface-med,rgba(0,0,0,.6));cursor:grab;user-select:none;
            touch-action:none;transition:color .15s,background-color .15s,transform .15s}
        .md-drag-handle:hover{color:var(--md-primary,#1976D2);background:rgba(var(--md-primary-rgb,25,118,210),.10)}
        .md-drag-handle:active{transform:scale(.88)}
        .md-drag-handle.is-disabled{cursor:not-allowed;opacity:.35;background:transparent;color:var(--md-on-surface-med,rgba(0,0,0,.6))}
        .md-drag-handle.is-nudge{animation:mdDragNudge .36s cubic-bezier(.36,.07,.19,.97)}
        @keyframes mdDragNudge{20%,60%{transform:translateX(-3px)}40%,80%{transform:translateX(3px)}}
        table[data-md-drag]>tbody>tr:has(.md-drag-handle:not(.is-disabled):hover)>td{background-color:rgba(var(--md-primary-rgb,25,118,210),.045)}
        body.md-drag-dragging,body.md-drag-dragging *{cursor:grabbing!important;user-select:none!important}
        table[data-md-drag].md-drag-sorting>tbody>tr{transition:transform .26s cubic-bezier(.2,.8,.2,1)}
        table[data-md-drag].md-drag-sorting.md-drag-no-anim>tbody>tr{transition:none}
        table[data-md-drag]>tbody>tr.md-drag-source>td{visibility:hidden}
        /* 外层只管位置：每帧用 translate3d 跟随指针；内层卡片管外观和「拿起 / 放下」的动画，两者互不干扰 */
        .md-drag-ghost{position:fixed;left:0;top:0;z-index:2147483000;pointer-events:none;will-change:transform}
        .md-drag-ghost.is-settling{transition:transform .26s cubic-bezier(.2,.8,.2,1)}
        .md-drag-card{box-sizing:border-box;display:flex;align-items:center;gap:10px;height:40px;padding:0 14px 0 8px;
            border-radius:var(--md-radius-lg,12px);background:var(--md-surface-2,#fff);color:var(--md-on-surface,rgba(0,0,0,.87));
            font-size:14px;line-height:1.2;white-space:nowrap;transform-origin:18px 50%;opacity:0;transform:scale(.94);
            box-shadow:0 0 0 1px rgba(var(--md-primary-rgb,25,118,210),.22),0 2px 6px rgba(0,0,0,.06);
            transition:transform .22s cubic-bezier(.2,.8,.2,1),box-shadow .22s cubic-bezier(.2,.8,.2,1),opacity .16s linear}
        .md-drag-ghost.is-lifted .md-drag-card{opacity:1;transform:scale(1.04) rotate(-1.2deg);
            box-shadow:0 0 0 1px rgba(var(--md-primary-rgb,25,118,210),.35),0 22px 44px -12px rgba(0,0,0,.32),0 6px 14px -6px rgba(0,0,0,.14)}
        :root[data-theme="dark"] .md-drag-ghost.is-lifted .md-drag-card{box-shadow:0 0 0 1px rgba(var(--md-primary-rgb),.45),0 24px 48px -12px rgba(0,0,0,.7)}
        .md-drag-ghost.is-settling .md-drag-card{opacity:1;transform:none}
        .md-drag-ghost.is-leaving .md-drag-card{opacity:0;transform:scale(.97)}
        .md-drag-card-grip{font-size:20px;line-height:1;color:var(--md-primary,#1976D2)}
        .md-drag-card-icon{width:28px;height:28px;border-radius:25%;object-fit:cover;flex:0 0 auto}
        .md-drag-card-name{font-weight:600;min-width:0;max-width:360px;overflow:hidden;text-overflow:ellipsis}
        .md-drag-card-badge{flex:0 0 auto;font-size:12px;line-height:1;padding:6px 10px;border-radius:var(--md-radius-pill,9999px);font-weight:600;
            color:var(--md-primary,#1976D2);background:rgba(var(--md-primary-rgb,25,118,210),.12)}
        .md-drag-card-badge.is-muted{color:var(--md-on-surface-med,rgba(0,0,0,.6));background:rgba(127,127,127,.12);font-weight:500}
        .md-drag-slot{position:fixed;z-index:2147482999;pointer-events:none;box-sizing:border-box;border-radius:var(--md-radius-lg,12px);
            border:1.5px dashed rgba(var(--md-primary-rgb,25,118,210),.55);background:rgba(var(--md-primary-rgb,25,118,210),.05);
            transition:transform .26s cubic-bezier(.2,.8,.2,1),opacity .2s}
        @keyframes mdDragLanded{0%{background-color:rgba(var(--md-primary-rgb,25,118,210),.18)}100%{background-color:rgba(var(--md-primary-rgb,25,118,210),0)}}
        @keyframes mdDragUnfold{0%{opacity:0;transform:translateY(-6px)}100%{opacity:1;transform:none}}
        @keyframes mdDragTick{0%{opacity:.2;transform:translateY(-4px)}100%{opacity:1;transform:none}}
        @keyframes mdDragTickText{0%{opacity:.2}100%{opacity:1}}
        table[data-md-drag]>tbody>tr.md-drag-landed>td{animation:mdDragLanded 1.4s cubic-bezier(0,0,.2,1)}
        table[data-md-drag]>tbody>tr.md-drag-unfold>td{animation:mdDragUnfold .3s cubic-bezier(0,0,.2,1) both}
        table[data-md-drag]>tbody>tr.md-drag-landed.md-drag-unfold>td{animation:mdDragUnfold .3s cubic-bezier(0,0,.2,1) both,mdDragLanded 1.4s cubic-bezier(0,0,.2,1)}
        table[data-md-drag] input.metadata-text.md-drag-tick{animation:mdDragTick .45s cubic-bezier(0,0,.2,1);color:var(--md-primary,#1976D2)}
        table[data-md-drag]>tbody>tr>td.md-drag-tick{animation:mdDragTickText .45s cubic-bezier(0,0,.2,1);color:var(--md-primary,#1976D2)}
        table[data-md-drag]>tbody>tr.md-drag-landed>td.md-drag-tick{animation:mdDragTickText .45s cubic-bezier(0,0,.2,1),mdDragLanded 1.4s cubic-bezier(0,0,.2,1)}

        /* ── 手机版（卡片列表）：长按整张卡片拖动 ──
           卡片列表插在表格外壳旁边、同一个父元素里（挂 md-drag-m-scope）。
           卡片上的文字不给长按选中、iOS 不弹长按菜单，否则会和长按拖动抢手势 */
        .md-drag-m-scope>.admin-mobile-card-list .admin-mobile-data-card{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none}
        .md-drag-m-scope>.admin-mobile-card-list .admin-mobile-data-card img{-webkit-user-drag:none}
        .md-drag-m-scope>.admin-mobile-card-list .admin-mobile-data-card.md-drag-m-pressing{scale:.985;transition:scale .3s cubic-bezier(.2,.8,.2,1)}
        body.md-drag-m-dragging{-webkit-user-select:none;user-select:none}
        /* 分类列表是一整块圆角面板（overflow:hidden），拖动时放开裁切，浮起的卡片和阴影才不会被切掉 */
        .md-drag-m-active .admin-mobile-card-items{overflow:visible!important}
        .admin-mobile-card-items.md-drag-m-sorting>.admin-mobile-data-card{transition:transform .26s cubic-bezier(.2,.8,.2,1)}
        .admin-mobile-card-items.md-drag-m-sorting.md-drag-m-no-anim>.admin-mobile-data-card{transition:none}
        /* 浮起：跟手指走用独立的 translate 属性（不带过渡），放大和阴影用 scale / box-shadow 过渡，互不干扰；
           分类行本身是透明底、无圆角的，浮起时补上底色和圆角 */
        .admin-mobile-data-card.md-drag-m-lifted{position:relative;z-index:6;scale:1.03;will-change:translate;
            background:var(--admin-mobile-surface,#fff)!important;border-radius:18px!important;border-bottom-color:transparent!important;
            box-shadow:0 0 0 1px color-mix(in srgb,var(--admin-mobile-primary,#1976D2) 26%,transparent),0 22px 44px -14px rgba(0,0,0,.38),0 6px 14px -6px rgba(0,0,0,.18)!important;
            transition:scale .22s cubic-bezier(.2,.8,.2,1),box-shadow .22s cubic-bezier(.2,.8,.2,1)!important}
        .admin-mobile-data-card.md-drag-m-lifted.md-drag-m-settling{scale:1;
            box-shadow:0 0 0 1px color-mix(in srgb,var(--admin-mobile-primary,#1976D2) 18%,transparent),0 6px 16px -8px rgba(0,0,0,.2)!important;
            transition:translate .24s cubic-bezier(.2,.8,.2,1),scale .24s cubic-bezier(.2,.8,.2,1),box-shadow .24s cubic-bezier(.2,.8,.2,1)!important}
        .admin-mobile-data-card.md-drag-m-shake{animation:mdDragMShake .36s cubic-bezier(.36,.07,.19,.97)}
        @keyframes mdDragMShake{20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
        /* 落位高亮用描边淡出：不动卡片自身的底色和阴影，动画结束不会闪 */
        @keyframes mdDragMLanded{0%{outline-color:color-mix(in srgb,var(--admin-mobile-primary,#1976D2) 70%,transparent)}100%{outline-color:transparent}}
        .admin-mobile-data-card.md-drag-m-landed{outline:2px solid transparent;outline-offset:-2px;animation:mdDragMLanded 1.2s cubic-bezier(0,0,.2,1)}
        .admin-mobile-data-card.md-drag-unfold{animation:mdDragUnfold .3s cubic-bezier(0,0,.2,1) both}

        @media (prefers-reduced-motion: reduce){
            table[data-md-drag].md-drag-sorting>tbody>tr,.md-drag-ghost,.md-drag-ghost.is-settling,.md-drag-card,.md-drag-slot{transition:none!important}
            .md-drag-ghost.is-lifted .md-drag-card{transform:none}
            table[data-md-drag]>tbody>tr.md-drag-landed>td,table[data-md-drag]>tbody>tr.md-drag-unfold>td,
            table[data-md-drag] input.metadata-text.md-drag-tick,table[data-md-drag]>tbody>tr>td.md-drag-tick,.md-drag-handle.is-nudge{animation:none}
            .md-drag-m-scope>.admin-mobile-card-list .admin-mobile-data-card.md-drag-m-pressing,.admin-mobile-data-card.md-drag-m-lifted{scale:none!important}
            .admin-mobile-card-items.md-drag-m-sorting>.admin-mobile-data-card,.admin-mobile-data-card.md-drag-m-lifted,
            .admin-mobile-data-card.md-drag-m-lifted.md-drag-m-settling{transition:none!important}
            .admin-mobile-data-card.md-drag-m-shake,.admin-mobile-data-card.md-drag-m-landed,.admin-mobile-data-card.md-drag-unfold{animation:none}
        }`;

    const injectStyle = () => {
        if (document.getElementById(STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = STYLE;
        document.head.appendChild(style);
    };

    // 手柄列：放在「排序」列前面。buttons:[] 让手机版卡片的详情里不把它当成一项字段显示（电脑版渲染不受影响）
    const column = () => ({
        field: '_drag', title: '', width: 34, buttons: [],
        formatter: () => `<span class="material-icons-outlined md-drag-handle" role="button" aria-label="${escapeHtml(i18n('拖动排序'))}">drag_indicator</span>`
    });

    /**
     * @param {object} options
     * @param {Table}  options.table          表格组件实例
     * @param {string} options.selector       表格选择器，如 '#category-table'
     * @param {string} options.url            保存接口，提交 {list: [按新顺序排好的 id]}；可在 data.sorts 里返回 {id: 新排序值}
     * @param {string} [options.namespace]    事件命名空间
     * @param {boolean}[options.tree]         树形：按 pid 分组，整块（含下级）拖动，拿起时收起下级
     * @param {string} [options.sortField]    排序数字所在列的字段名，保存后原地更新它
     * @param {string} [options.hint]         手柄提示
     * @param {string} [options.singleText]   同组只有一个时的提示
     * @param {Function}[options.isActive]    页面控制器是否还活着
     * @param {Function}[options.blockedReason] 返回不能拖的原因（空串 = 可以拖）
     * @param {Function}[options.describe]    (tr) => {name, icon}，电脑版浮起卡片上显示的名称和图片
     */
    const attach = options => {
        const table = options.table;
        const selector = options.selector;
        const url = options.url;
        const namespace = options.namespace || '.mdTableDragSort';
        const tree = options.tree === true;
        const sortField = options.sortField || 'sort';
        const hint = options.hint || '按住拖动调整顺序';
        const singleText = options.singleText || '只有这一个，不需要排序';
        const isActive = typeof options.isActive === 'function' ? options.isActive : () => true;
        const blockedReason = typeof options.blockedReason === 'function' ? options.blockedReason : () => '';
        let press = null;      // 电脑版：按下了手柄、还没拖出阈值
        let drag = null;       // 电脑版：拖动中
        let mpress = null;     // 手机版：按住卡片、长按计时中
        let mdrag = null;      // 手机版：卡片拖动中
        let mlatch = null;     // 手机版：长按已触发、手指还没抬起的 pointerId（抬手那一下不能当成点击）
        let suppressClickUntil = 0;
        let settling = false;  // 落位 / 归位动画中
        let saving = false;
        let syncTimer = 0;
        let destroyed = false;

        const tableElement = () => document.querySelector(selector);
        const alive = () => !destroyed && isActive() && table && !table.isDestroyed;
        injectStyle();
        tableElement()?.setAttribute('data-md-drag', '');
        // 手机版卡片列表每次刷新整块重画（有时还不发任何事件，比如切换布局时的整体重建），但总是插在表格外壳（.bootstrap-table）
        // 旁边、同一个父元素里。非被动 touchmove 监听必须在触摸开始前就挂好，浏览器才允许拖动时拦住页面滚动——挂在这个稳定的父元素上
        const scope = (tableElement()?.closest('.bootstrap-table') || tableElement())?.parentElement || null;

        // 表头里某个字段是第几列（插件可能往前插列，按字段名找最稳）
        const columnIndex = field => [...(tableElement()?.querySelectorAll(':scope > thead > tr > th') || [])]
            .findIndex(th => th.getAttribute('data-field') === field);

        const describe = typeof options.describe === 'function' ? options.describe : tr => ({
            name: (tr.children[columnIndex('name')]?.textContent || '').trim(),
            icon: tr.children[columnIndex('icon')]?.querySelector('img')?.getAttribute('src') || ''
        });

        // 不能拖的时候轻轻晃一下手柄，比只弹一句提示更直观
        const nudge = handle => {
            if (!handle || reduceMotion) return;
            handle.classList.remove('is-nudge');
            void handle.offsetWidth;
            handle.classList.add('is-nudge');
            setTimeout(() => handle.classList.remove('is-nudge'), 400);
        };

        const reasonNow = () => (table ? String(blockedReason() || '') : '表格尚未加载完成');

        const syncHandles = () => {
            const reason = reasonNow();
            $(tableElement()).find('.md-drag-handle')
                .toggleClass('is-disabled', reason !== '')
                .attr('title', i18n(reason || hint));
        };

        // 同一组的「块」。平铺：表格里每一行（带上紧跟其后的详情行）各是一块；
        // 树形：同一父级的分类，每块 = 这一行 + 深度优先紧随其后的全部下级（折叠隐藏的也还在 DOM 里，一起走）
        const groupBlocks = handleRow => {
            const rows = [...(tableElement()?.querySelectorAll(':scope > tbody > tr') || [])];
            if (!tree) {
                const blocks = [];
                let block = null;
                rows.forEach(tr => {
                    if (tr.hasAttribute('data-id')) {
                        block = {id: Number(tr.getAttribute('data-id')), rows: [tr]};
                        blocks.push(block);
                    } else if (block) {
                        block.rows.push(tr);
                    }
                });
                return blocks;
            }

            const parents = new Map();
            (table?.getRows?.() || []).forEach(row => {
                const id = Number(row?.id);
                if (Number.isInteger(id) && id > 0) parents.set(id, Number(row?.pid) || 0);
            });
            const handleId = Number(handleRow.getAttribute('data-id'));
            if (!parents.has(handleId)) return [];
            const pid = parents.get(handleId);
            const isDescendantOf = (id, ancestorId) => {
                let current = parents.get(id);
                for (let guard = 0; current && guard < 64; guard++) {
                    if (current === ancestorId) return true;
                    current = parents.get(current);
                }
                return false;
            };
            const blocks = [];
            let block = null;
            rows.filter(tr => tr.hasAttribute('data-id')).forEach(tr => {
                const id = Number(tr.getAttribute('data-id'));
                if (!parents.has(id)) {
                    block = null;
                } else if (parents.get(id) === pid) {
                    block = {id: id, rows: [tr]};
                    blocks.push(block);
                } else if (block && isDescendantOf(id, block.id)) {
                    block.rows.push(tr);
                } else {
                    block = null;
                }
            });
            return blocks;
        };

        // 浮层只铺在表格可见区域内（表格可能在横向滚动容器里）
        const visibleTableBounds = () => {
            const element = tableElement();
            if (!element) return {left: 0, width: 0};
            const rect = element.getBoundingClientRect();
            const scroller = element.closest('.fixed-table-body');
            if (!scroller) return {left: rect.left, width: rect.width};
            const box = scroller.getBoundingClientRect();
            const left = Math.max(rect.left, box.left);
            return {left: left, width: Math.max(0, Math.min(rect.right, box.right) - left)};
        };

        // 一块在页面坐标系里的位置：顶部 + 可见高度（折叠起来的行 / 卡片高度为 0，不计）
        const measureBlock = block => {
            const first = block.rows[0].getBoundingClientRect();
            let bottom = first.bottom;
            for (let i = block.rows.length - 1; i > 0; i--) {
                const rect = block.rows[i].getBoundingClientRect();
                if (rect.height > 0) {
                    bottom = rect.bottom;
                    break;
                }
            }
            return {top: first.top + window.scrollY, height: bottom - first.top, rowHeight: first.height};
        };

        // 拖动期间冻结列宽。下级收起时，最长的那段内容可能恰好在被隐藏的行里，自动表格布局会把列收窄，
        // 右边所有列跟着横向跳一下（实测：拿起带长名称子分类的父级，手柄列和排序列整体左移，松手又弹回去）。
        const freezeColumns = () => {
            const element = tableElement();
            if (!element || element.dataset.mdDragFrozen === '1') return;
            const headers = [...element.querySelectorAll(':scope > thead > tr > th')];
            // 先全部量完再统一写，边量边写会让后面的列在中途重排后量错
            const widths = headers.map(th => th.getBoundingClientRect().width);
            const tableWidth = element.getBoundingClientRect().width;
            headers.forEach((th, index) => {
                th.dataset.mdDragWidth = th.style.width;
                th.style.width = widths[index] + 'px';
            });
            element.dataset.mdDragFrozen = '1';
            element.dataset.mdDragWidth = element.style.width;
            element.dataset.mdDragLayout = element.style.tableLayout;
            element.style.width = tableWidth + 'px';
            element.style.tableLayout = 'fixed';
        };

        const unfreezeColumns = () => {
            const element = tableElement();
            if (!element || element.dataset.mdDragFrozen !== '1') return;
            element.querySelectorAll(':scope > thead > tr > th').forEach(th => {
                th.style.width = th.dataset.mdDragWidth || '';
                delete th.dataset.mdDragWidth;
            });
            element.style.width = element.dataset.mdDragWidth || '';
            element.style.tableLayout = element.dataset.mdDragLayout || '';
            delete element.dataset.mdDragFrozen;
            delete element.dataset.mdDragWidth;
            delete element.dataset.mdDragLayout;
        };

        // 某一行之后的所有行
        const rowsAfter = row => {
            const rows = [];
            for (let next = row?.nextElementSibling; next; next = next.nextElementSibling) {
                if (next.tagName === 'TR') rows.push(next);
            }
            return rows;
        };

        // 让一批行从「偏移 fromOffset 的旧位置」平滑滑到当前真实位置（FLIP：先瞬间摆回旧位置，再过渡回来）
        const slideRows = (rows, fromOffset) => {
            if (!rows.length || !fromOffset || reduceMotion) return;
            const element = tableElement();
            element?.classList.add('md-drag-sorting', 'md-drag-no-anim');
            rows.forEach(row => {
                row.style.transform = `translateY(${fromOffset}px)`;
            });
            void element?.offsetHeight;
            element?.classList.remove('md-drag-no-anim');
            rows.forEach(row => {
                row.style.transform = '';
            });
        };

        // 浮起来的卡片：拖动图标 + 图片 + 名称 + 提示徽标，拖动图标正好压在鼠标指针下面，跟着指针走。
        // 不整行克隆——开关、按钮、图片列一搬到表格外面，表格的作用域样式够不着，排版会整个散掉（实测克隆行被撑到两千多像素高）。
        const buildGhost = block => {
            const info = describe(block.rows[0]) || {};
            const ghost = document.createElement('div');
            ghost.className = 'md-drag-ghost';
            const card = document.createElement('div');
            card.className = 'md-drag-card';
            ghost.appendChild(card);

            const grip = document.createElement('span');
            grip.className = 'material-icons-outlined md-drag-card-grip';
            grip.textContent = 'drag_indicator';
            card.appendChild(grip);

            if (info.icon) {
                const icon = document.createElement('img');
                icon.className = 'md-drag-card-icon';
                icon.alt = '';
                icon.src = info.icon;
                card.appendChild(icon);
            }

            const name = document.createElement('span');
            name.className = 'md-drag-card-name';
            name.textContent = String(info.name || '').trim() || `ID ${block.id}`;
            card.appendChild(name);

            const position = document.createElement('span');
            position.className = 'md-drag-card-badge';
            card.appendChild(position);

            const descendants = tree ? block.rows.length - 1 : 0;
            if (descendants > 0) {
                const tag = document.createElement('span');
                tag.className = 'md-drag-card-badge is-muted';
                tag.textContent = `${i18n('连同')} ${descendants} ${i18n('个下级')}`;
                card.appendChild(tag);
            }
            document.body.appendChild(ghost);
            // 对准点 = 拖动图标的中心（用 offset* 量，不受卡片初始缩放影响）；卡片的缩放、倾斜也以这里为原点，指针下永远是图标
            return {
                ghost, position,
                anchorX: grip.offsetLeft + grip.offsetWidth / 2,
                anchorY: card.offsetHeight / 2,
                width: card.offsetWidth
            };
        };

        // 把卡片摆到指针位置：snap=true 直接到位（刚拿起时不能从别处飞过来），否则带一点阻尼跟随，更顺滑
        const placeGhost = (state, snap = false) => {
            const maxX = Math.max(8, window.innerWidth - state.cardWidth - 8);
            const targetX = Math.max(8, Math.min(maxX, state.pointerX - state.anchorX));
            const targetY = state.pointerY - state.anchorY;
            if (snap || reduceMotion) {
                state.x = targetX;
                state.y = targetY;
            } else {
                state.x += (targetX - state.x) * 0.5;
                state.y += (targetY - state.y) * 0.5;
            }
            state.ghost.style.transform = `translate3d(${state.x.toFixed(2)}px, ${state.y.toFixed(2)}px, 0)`;
        };

        // 虚线框：标出松手后会落到哪里
        const buildSlot = (geometry, bounds) => {
            const slot = document.createElement('div');
            slot.className = 'md-drag-slot';
            Object.assign(slot.style, {
                left: (bounds.left + 4) + 'px', width: Math.max(0, bounds.width - 8) + 'px',
                height: Math.max(0, geometry.height - 4) + 'px', top: (geometry.top - window.scrollY + 2) + 'px'
            });
            document.body.appendChild(slot);
            return slot;
        };

        const updatePositionBadge = state => {
            state.position.textContent = `${i18n('第')} ${state.to + 1} / ${state.blocks.length} ${i18n('位')}`;
        };

        // 目标位置变了：夹在中间的块整体滑动「被拖那一块的高度」让出空位，虚线框滑进空位
        const applyTarget = (state, target) => {
            if (target === state.to) return;
            state.to = target;
            const {from, rest, restGeometry, height} = state;
            rest.forEach((block, index) => {
                const shift = (index < from && index >= target) ? height : ((index >= from && index < target) ? -height : 0);
                block.rows.forEach(row => {
                    row.style.transform = shift ? `translateY(${shift}px)` : '';
                });
            });
            let offset = 0;
            for (let i = from; i < target; i++) offset += restGeometry[i].height;
            for (let i = target; i < from; i++) offset -= restGeometry[i].height;
            state.offset = offset;
            state.slot.style.transform = offset ? `translateY(${offset}px)` : '';
            updatePositionBadge(state);
        };

        const dragFrame = () => {
            const state = drag;
            if (!state) return;
            // 贴近窗口上下边缘自动滚动，越靠边滚得越快
            const edge = 72, viewport = window.innerHeight, y = state.pointerY;
            const step = y < edge ? -Math.ceil((edge - y) / 5) : (y > viewport - edge ? Math.ceil((y - viewport + edge) / 5) : 0);
            if (step) window.scrollBy(0, step);

            placeGhost(state);

            // 按卡片（也就是指针）的竖直位置决定插入位置；朝移动方向多留 6px 迟滞，停在分界线上不会来回抖
            const scrollY = window.scrollY;
            const center = state.y + state.anchorY + scrollY;
            let target = 0;
            state.restMids.forEach((mid, index) => {
                if (center > mid + (index >= state.to ? 6 : -6)) target++;
            });
            applyTarget(state, target);

            state.slot.style.top = (state.geometry[state.from].top - scrollY + 2) + 'px';
            state.frame = requestAnimationFrame(dragFrame);
        };

        // 表格内部的行数据在拖完后是旧的（编辑弹窗里的排序值会过期），等动画都播完、且没有新的拖动时再静默刷新
        const scheduleSync = (delay = 1500) => {
            clearTimeout(syncTimer);
            syncTimer = setTimeout(() => {
                if (!alive()) return;
                if (press || drag || mpress || mdrag || settling || saving) {
                    scheduleSync(600);
                    return;
                }
                table.refresh(true);
            }, delay);
        };

        // 排序数字原地更新并轻轻跳一下；服务端没给新值时按提交顺序记 0,1,2…
        const updateSortNumbers = (order, sorts) => {
            const index = columnIndex(sortField);
            if (index < 0) return;
            order.forEach((id, position) => {
                const value = String(sorts && sorts[id] !== undefined ? sorts[id] : position);
                const cell = tableElement()?.querySelector(`:scope > tbody > tr[data-id="${Number(id)}"]`)?.children[index];
                if (!cell) return;
                const input = cell.querySelector('input.metadata-text');
                const target = input || cell;
                if ((input ? input.value : cell.textContent.trim()) === value) return;
                if (input) {
                    input.value = value;
                } else {
                    cell.textContent = value;
                }
                if (reduceMotion) return;
                target.classList.remove('md-drag-tick');
                void target.offsetWidth;
                target.classList.add('md-drag-tick');
                setTimeout(() => target.classList.remove('md-drag-tick'), 500);
            });
        };

        // message.* 内部会过一遍 i18n，这里一律传原文，别再套 i18n()
        const saveOrder = order => {
            saving = true;
            util.post({
                url: url,
                data: {list: order},
                loader: false,   // 不要全屏加载遮罩，否则落位动画会被它盖住闪一下
                done: res => {
                    saving = false;
                    if (!alive()) return;
                    updateSortNumbers(order, res?.data?.sorts);
                    message.success(res?.msg || '排序已保存');
                    scheduleSync();
                },
                error: res => {
                    saving = false;
                    message.error(res?.msg || '排序保存失败');
                    if (alive()) table.refresh(true);
                },
                fail: () => {
                    saving = false;
                    message.error('网络异常，排序未保存');
                    if (alive()) table.refresh(true);
                }
            });
        };

        // 松手（commit）或取消：卡片先落到最终位置，播完再把 DOM 真正挪过去，然后卡片淡出、收起的行展开
        const finishDrag = commit => {
            const state = drag;
            if (!state) return;
            drag = null;
            cancelAnimationFrame(state.frame);
            try {
                state.handle.releasePointerCapture(state.pointerId);
            } catch (error) {
            }
            document.body.classList.remove('md-drag-dragging');

            const changed = commit && state.to !== state.from;
            if (!changed) applyTarget(state, state.from);   // 取消：其它行滑回原处
            settling = true;
            // 卡片落进目标行的手柄位置，同时收起倾斜和阴影；随后淡出，真实的行在原地露出来
            const rowTop = state.geometry[state.from].top - window.scrollY + (changed ? state.offset : 0);
            state.x = state.handleX - state.anchorX;
            state.y = rowTop + state.rowHeight / 2 - state.anchorY;
            state.ghost.classList.add('is-settling');
            state.ghost.classList.remove('is-lifted');
            state.ghost.style.transform = `translate3d(${state.x.toFixed(2)}px, ${state.y.toFixed(2)}px, 0)`;

            setTimeout(() => {
                const element = tableElement();
                const moved = state.blocks[state.from];
                // 先关过渡、清位移，再挪 DOM：此刻各行的视觉位置和挪完后的真实位置一致，画面不会跳
                element?.classList.add('md-drag-no-anim');
                state.blocks.forEach(block => block.rows.forEach(row => {
                    row.style.transform = '';
                }));
                if (changed) {
                    if (state.to < state.rest.length) {
                        $(moved.rows).insertBefore(state.rest[state.to].rows[0]);
                    } else {
                        const last = state.rest[state.rest.length - 1];
                        $(moved.rows).insertAfter(last.rows[last.rows.length - 1]);
                    }
                }
                moved.rows[0].classList.remove('md-drag-source');
                void element?.offsetHeight;
                element?.classList.remove('md-drag-no-anim');

                state.slot.remove();
                state.ghost.classList.add('is-leaving');
                setTimeout(() => state.ghost.remove(), 220);

                // 收起的行展开：恢复显示，后面的行从收起时的位置平滑下移
                state.hidden.forEach(item => {
                    item.row.style.display = item.display;
                });
                slideRows(rowsAfter(moved.rows[moved.rows.length - 1]).filter(row => row.style.display !== 'none'), -state.foldHeight);
                if (!reduceMotion) {
                    state.hidden.map(item => item.row).filter(row => row.style.display !== 'none')
                        .forEach(row => row.classList.add('md-drag-unfold'));
                    if (changed) {
                        moved.rows.forEach(row => {
                            row.classList.remove('md-drag-landed');
                            void row.offsetWidth;
                            row.classList.add('md-drag-landed');
                        });
                    }
                }
                setTimeout(() => {
                    settling = false;
                    tableElement()?.classList.remove('md-drag-sorting');
                    moved.rows.forEach(row => row.classList.remove('md-drag-unfold'));
                    unfreezeColumns();
                }, FLIP_MS + 60);
                if (!changed) return;

                setTimeout(() => moved.rows.forEach(row => row.classList.remove('md-drag-landed')), 1500);
                const order = state.rest.map(block => block.id);
                order.splice(state.to, 0, moved.id);
                saveOrder(order);
            }, SETTLE_MS);
        };

        // 表格被刷新或页面被切走：不播动画，直接收拾干净（行已被重新渲染，别再动旧 DOM 的顺序）
        const abortDrag = () => {
            press = null;
            const state = drag;
            if (!state) return;
            drag = null;
            cancelAnimationFrame(state.frame);
            try {
                state.handle.releasePointerCapture(state.pointerId);
            } catch (error) {
            }
            state.ghost.remove();
            state.slot.remove();
            document.body.classList.remove('md-drag-dragging');
            tableElement()?.classList.remove('md-drag-sorting', 'md-drag-no-anim');
            state.hidden.forEach(item => {
                item.row.style.display = item.display;
            });
            state.blocks.forEach(block => block.rows.forEach(row => {
                row.style.transform = '';
                row.classList.remove('md-drag-source');
            }));
            unfreezeColumns();
        };

        const beginDrag = event => {
            const {handle, pointerId} = press;
            press = null;
            const tr = handle.closest('tr[data-id]');
            if (!tr) return;
            const blocks = groupBlocks(tr);
            const id = Number(tr.getAttribute('data-id'));
            const from = blocks.findIndex(block => block.id === id);
            if (from < 0) return;
            if (blocks.length < 2) {
                try {
                    handle.releasePointerCapture(pointerId);
                } catch (error) {
                }
                nudge(handle);
                message.info(singleText);
                return;
            }

            const element = tableElement();
            freezeColumns();
            const moved = blocks[from];
            const foldHeight = measureBlock(moved).height - moved.rows[0].getBoundingClientRect().height;
            // 收起附带的行（树形的下级 / 平铺的详情行）：先隐藏，趁还没有任何位移时量好收起后的布局，
            // 再让后面的行从原位置平滑上移。这样卡片、虚线框、让出的空位始终都是一行高
            const hidden = moved.rows.slice(1).map(row => ({row, display: row.style.display}));
            hidden.forEach(item => {
                item.row.style.display = 'none';
            });
            element?.classList.add('md-drag-sorting');
            const geometry = blocks.map(measureBlock);
            slideRows(rowsAfter(moved.rows[0]).filter(row => row.style.display !== 'none'), foldHeight);

            const bounds = visibleTableBounds();
            const {ghost, position, anchorX, anchorY, width} = buildGhost(moved);
            const slot = buildSlot(geometry[from], bounds);
            moved.rows[0].classList.add('md-drag-source');
            document.body.classList.add('md-drag-dragging');

            const handleRect = handle.getBoundingClientRect();
            const restGeometry = geometry.filter((_, index) => index !== from);
            drag = {
                blocks, from, to: from, offset: 0, handle, pointerId, ghost, slot, position, geometry, restGeometry,
                hidden, foldHeight, anchorX, anchorY, cardWidth: width,
                rest: blocks.filter((_, index) => index !== from),
                restMids: restGeometry.map(item => item.top + item.height / 2),
                height: geometry[from].height,
                rowHeight: geometry[from].rowHeight,
                handleX: handleRect.left + handleRect.width / 2,
                pointerX: event.clientX, pointerY: event.clientY,
                x: 0, y: 0,
                frame: 0
            };
            placeGhost(drag, true);
            updatePositionBadge(drag);
            // 下一帧再「抬起」，才有浮起来的过渡
            requestAnimationFrame(() => {
                if (drag && drag.ghost === ghost) ghost.classList.add('is-lifted');
            });
            drag.frame = requestAnimationFrame(dragFrame);
        };

        const onPointerDown = (event, handle) => {
            if (!alive() || isMobileLayout() || event.button !== 0 || press || drag || settling) return;
            if (saving) {
                nudge(handle);
                message.warning('上一次排序还在保存，请稍候');
                return;
            }
            const reason = reasonNow();
            if (reason) {
                nudge(handle);
                message.warning(reason);
                return;
            }
            event.preventDefault();
            try {
                handle.setPointerCapture(event.pointerId);
            } catch (error) {
            }
            press = {handle, pointerId: event.pointerId, x: event.clientX, y: event.clientY};
        };

        const onPointerMove = event => {
            if (press && event.pointerId === press.pointerId) {
                if (Math.hypot(event.clientX - press.x, event.clientY - press.y) < DRAG_THRESHOLD) return;
                beginDrag(event);
            }
            if (drag && event.pointerId === drag.pointerId) {
                event.preventDefault();
                drag.pointerX = event.clientX;
                drag.pointerY = event.clientY;
            }
        };

        const onPointerEnd = event => {
            if (press && event.pointerId === press.pointerId) {
                try {
                    press.handle.releasePointerCapture(press.pointerId);
                } catch (error) {
                }
                press = null;
                return;
            }
            if (drag && event.pointerId === drag.pointerId) finishDrag(event.type === 'pointerup');
        };

        // ─── 手机版：长按整张卡片拖动 ─────────────────────────────────────────
        // 卡片列表由 mobile/fallback.js 按 getRows() 的顺序画出，每次表格刷新整块重画；卡片上没有行 id，按位置对应。
        const mobileHost = () => (table && table.unique != null)
            ? document.querySelector(`[data-admin-mobile-table="${cssEscape(String(table.unique))}"]`)
            : null;
        const mobileItems = host => host ? host.querySelector(':scope > .admin-mobile-card-items') : null;
        const mobileCards = items => items ? [...items.querySelectorAll(':scope > .admin-mobile-data-card')] : [];
        const ownsHost = host => Boolean(host && table && host.getAttribute('data-admin-mobile-table') === String(table.unique));

        const siblingsAfter = element => {
            const list = [];
            for (let next = element?.nextElementSibling; next; next = next.nextElementSibling) list.push(next);
            return list;
        };

        // 卡片版 FLIP：一批卡片从「偏移 fromOffset 的旧位置」平滑滑到当前位置
        const slideCards = (items, cards, fromOffset) => {
            if (!items || !cards.length || !fromOffset || reduceMotion) return;
            items.classList.add('md-drag-m-sorting', 'md-drag-m-no-anim');
            cards.forEach(card => {
                card.style.transform = `translateY(${fromOffset}px)`;
            });
            void items.offsetHeight;
            items.classList.remove('md-drag-m-no-anim');
            cards.forEach(card => {
                card.style.transform = '';
            });
        };

        // 不能拖时整张卡片左右晃一下
        const shake = card => {
            if (!card || reduceMotion) return;
            card.classList.remove('md-drag-m-shake');
            void card.offsetWidth;
            card.classList.add('md-drag-m-shake');
            setTimeout(() => card.classList.remove('md-drag-m-shake'), 400);
        };

        // 同组的块：卡片 i 对应 getRows()[i]。「筛选当前列表」会少画卡片，位置就对不上了——返回 null，不让拖
        const mobileBlocks = (items, card) => {
            const rows = table?.getRows?.() || [];
            const cards = mobileCards(items);
            if (!cards.length || cards.length !== rows.length) return null;
            const ids = rows.map(row => Number(row?.id));
            if (!tree) return cards.map((element, index) => ({id: ids[index], rows: [element]}));
            // 树形：分类接口按深度优先顺序返回（Admin\Api\Category::data 的 treeOrder），卡片也就按这个顺序画——
            // 同父级、同深度的是兄弟，紧跟其后更深的卡片都是它的下级。接口若改回扁平顺序，这里就不成立了
            const depthOf = element => Number(element.getAttribute('data-admin-mobile-tree-depth')) || 0;
            const parentOf = element => element.getAttribute('data-admin-mobile-tree-parent-key') || '';
            const depth = depthOf(card);
            const parent = parentOf(card);
            const blocks = [];
            let block = null;
            cards.forEach((element, index) => {
                const current = depthOf(element);
                if (current === depth && parentOf(element) === parent) {
                    block = {id: ids[index], rows: [element]};
                    blocks.push(block);
                } else if (block && current > depth) {
                    block.rows.push(element);
                } else {
                    block = null;
                }
            });
            return blocks;
        };

        const mobileReason = (host, items) => {
            if (host.classList.contains('is-tree-searching') || mobileCards(items).some(card => card.classList.contains('is-hidden-by-search'))) {
                return '正在筛选当前列表，列表不完整，请先清空筛选再拖动排序';
            }
            return reasonNow();
        };

        const clearPress = () => {
            if (!mpress) return;
            clearTimeout(mpress.timer);
            clearTimeout(mpress.feedback);
            mpress.card.classList.remove('md-drag-m-pressing');
            mpress = null;
        };

        const applyMobileTarget = (state, target) => {
            if (target === state.to) return;
            state.to = target;
            const {from, rest, restGeometry, pitch} = state;
            // 卡片之间有间距：让位的距离用「这张卡片顶部到下一张顶部」的跨度，而不是卡片高度
            rest.forEach((block, index) => {
                const shift = (index < from && index >= target) ? pitch : ((index >= from && index < target) ? -pitch : 0);
                block.rows.forEach(element => {
                    element.style.transform = shift ? `translateY(${shift}px)` : '';
                });
            });
            let offset = 0;
            for (let i = from; i < target; i++) offset += restGeometry[i].pitch;
            for (let i = target; i < from; i++) offset -= restGeometry[i].pitch;
            state.offset = offset;
        };

        const mobileFrame = () => {
            const state = mdrag;
            if (!state) return;
            const currentDy = () => Math.max(state.minDy, Math.min(state.maxDy, state.pointerY - state.startY + window.scrollY - state.startScroll));
            let dy = currentDy();
            // 手指贴近顶栏 / 底栏时自动滚动，越靠边越快；卡片已经到列表头 / 尾就不再滚
            const y = state.pointerY;
            let step = 0;
            if (y < state.topEdge && dy > state.minDy) step = -Math.min(18, Math.ceil((state.topEdge - y) / 4));
            else if (y > state.bottomEdge && dy < state.maxDy) step = Math.min(18, Math.ceil((y - state.bottomEdge) / 4));
            if (step) {
                window.scrollBy(0, step);
                dy = currentDy();
            }
            state.dy = dy;
            state.card.style.translate = `0px ${dy.toFixed(1)}px`;
            // 按卡片中线决定插入位置；朝移动方向多留 6px 迟滞，停在分界线上不会来回抖
            const center = state.geometry[state.from].top + dy + state.geometry[state.from].height / 2;
            let target = 0;
            state.restMids.forEach((mid, index) => {
                if (center > mid + (index >= state.to ? 6 : -6)) target++;
            });
            applyMobileTarget(state, target);
            state.frame = requestAnimationFrame(mobileFrame);
        };

        const beginMobileDrag = (current, items, blocks, from) => {
            const host = current.host;
            const moved = blocks[from];
            const card = moved.rows[0];
            const foldHeight = measureBlock(moved).height - card.getBoundingClientRect().height;
            // 拿起父级分类时下级收起，量好收起后的布局，后面的卡片再平滑上移
            const hidden = moved.rows.slice(1).map(row => ({row, display: row.style.display}));
            hidden.forEach(item => {
                item.row.style.display = 'none';
            });
            host.classList.add('md-drag-m-active');
            items.classList.add('md-drag-m-sorting');
            document.body.classList.add('md-drag-m-dragging');
            const geometry = blocks.map(measureBlock);
            const gap = geometry.length > 1 ? Math.max(0, geometry[1].top - geometry[0].top - geometry[0].height) : 0;
            geometry.forEach((item, index) => {
                item.pitch = index < geometry.length - 1 ? geometry[index + 1].top - item.top : item.height + gap;
            });
            slideCards(items, siblingsAfter(card).filter(element => element.style.display !== 'none'), foldHeight);
            const restGeometry = geometry.filter((_, index) => index !== from);
            const last = geometry[geometry.length - 1];
            const bodyStyle = window.getComputedStyle(document.body);
            mdrag = {
                blocks, from, to: from, offset: 0, host, items, card, hidden, foldHeight, geometry, restGeometry,
                rest: blocks.filter((_, index) => index !== from),
                restMids: restGeometry.map(item => item.top + item.height / 2),
                pitch: geometry[from].pitch,
                pointerId: current.pointerId,
                startY: current.lastY, pointerY: current.lastY,
                startScroll: window.scrollY,
                // 卡片中线最远拖到列表首尾边缘。按中线算：高卡片越过比它矮的首 / 末张卡片也总能落到首位 / 末位
                minDy: geometry[0].top - geometry[from].top - geometry[from].height / 2,
                maxDy: last.top + last.height - geometry[from].top - geometry[from].height / 2,
                // 顶部应用栏、底部导航是固定定位的（body 用内边距给它们让位）：自动滚动的触发区要让开它们
                topEdge: (parseFloat(bodyStyle.paddingTop) || 0) + 64,
                bottomEdge: window.innerHeight - (parseFloat(bodyStyle.paddingBottom) || 0) - 64,
                dy: 0, frame: 0
            };
            card.style.translate = '0px 0px';
            card.classList.add('md-drag-m-lifted');
            try {
                if (navigator.vibrate) navigator.vibrate(12);
            } catch (error) {
            }
            mdrag.frame = requestAnimationFrame(mobileFrame);
        };

        const activateLongPress = () => {
            const current = mpress;
            if (!current) return;
            clearTimeout(current.feedback);
            mpress = null;
            current.card.classList.remove('md-drag-m-pressing');
            mlatch = current.pointerId;   // 这次按压抬手时不当作点击，否则会顺手打开详情
            if (!alive() || !isMobileLayout() || !current.card.isConnected) return;
            const items = mobileItems(current.host);
            if (!items || current.card.parentElement !== items) return;
            if (saving) {
                shake(current.card);
                message.warning('上一次排序还在保存，请稍候');
                return;
            }
            const reason = mobileReason(current.host, items);
            if (reason) {
                shake(current.card);
                message.warning(reason);
                return;
            }
            const blocks = mobileBlocks(items, current.card);
            const from = blocks ? blocks.findIndex(block => block.rows[0] === current.card) : -1;
            if (!blocks || from < 0 || blocks.some(block => !Number.isInteger(block.id) || block.id <= 0)) {
                shake(current.card);
                message.warning('列表正在刷新，请稍后再拖动排序');
                return;
            }
            if (blocks.length < 2) {
                shake(current.card);
                message.info(singleText);
                return;
            }
            beginMobileDrag(current, items, blocks, from);
        };

        // 松手（commit）或取消：卡片滑进空位并缩回，播完再把 DOM 真正挪过去，然后下级展开、落位描边淡出
        const finishMobileDrag = commit => {
            const state = mdrag;
            if (!state) return;
            mdrag = null;
            cancelAnimationFrame(state.frame);
            document.body.classList.remove('md-drag-m-dragging');
            suppressClickUntil = Date.now() + 500;
            const changed = commit && state.to !== state.from;
            if (!changed) applyMobileTarget(state, state.from);   // 取消：其它卡片滑回原处
            settling = true;
            state.card.classList.add('md-drag-m-settling');
            state.card.style.translate = `0px ${changed ? state.offset : 0}px`;

            setTimeout(() => {
                const {items, card} = state;
                const moved = state.blocks[state.from];
                // 先关过渡、清位移，再挪 DOM：此刻卡片的视觉位置和挪完后的真实位置一致，画面不会跳
                items.classList.add('md-drag-m-no-anim');
                state.blocks.forEach(block => block.rows.forEach(element => {
                    element.style.transform = '';
                }));
                card.style.translate = '';
                card.classList.remove('md-drag-m-lifted', 'md-drag-m-settling');
                if (changed) {
                    if (state.to < state.rest.length) {
                        $(moved.rows).insertBefore(state.rest[state.to].rows[0]);
                    } else {
                        const last = state.rest[state.rest.length - 1];
                        $(moved.rows).insertAfter(last.rows[last.rows.length - 1]);
                    }
                }
                void items.offsetHeight;
                items.classList.remove('md-drag-m-no-anim');

                state.hidden.forEach(item => {
                    item.row.style.display = item.display;
                });
                slideCards(items, siblingsAfter(moved.rows[moved.rows.length - 1]).filter(element => element.style.display !== 'none'), -state.foldHeight);
                if (!reduceMotion) {
                    state.hidden.map(item => item.row).filter(element => element.style.display !== 'none')
                        .forEach(element => element.classList.add('md-drag-unfold'));
                    if (changed) {
                        card.classList.remove('md-drag-m-landed');
                        void card.offsetWidth;
                        card.classList.add('md-drag-m-landed');
                    }
                }
                setTimeout(() => {
                    settling = false;
                    items.classList.remove('md-drag-m-sorting');
                    state.host.classList.remove('md-drag-m-active');
                    moved.rows.forEach(element => element.classList.remove('md-drag-unfold'));
                }, FLIP_MS + 60);
                if (!changed) return;

                setTimeout(() => card.classList.remove('md-drag-m-landed'), 1300);
                const order = state.rest.map(block => block.id);
                order.splice(state.to, 0, moved.id);
                saveOrder(order);
            }, SETTLE_MS);
        };

        // 卡片列表要被重画 / 页面被切走：不播动画，直接收拾干净
        const abortMobile = () => {
            clearPress();
            const state = mdrag;
            if (!state) return;
            mdrag = null;
            cancelAnimationFrame(state.frame);
            document.body.classList.remove('md-drag-m-dragging');
            state.items.classList.remove('md-drag-m-sorting', 'md-drag-m-no-anim');
            state.host.classList.remove('md-drag-m-active');
            state.card.classList.remove('md-drag-m-lifted', 'md-drag-m-settling');
            state.card.style.translate = '';
            state.hidden.forEach(item => {
                item.row.style.display = item.display;
            });
            state.blocks.forEach(block => block.rows.forEach(element => {
                element.style.transform = '';
            }));
        };

        const onMobilePointerDown = event => {
            if (!alive() || !isMobileLayout() || mpress || mdrag || settling) return;
            if (event.isPrimary === false || (event.pointerType === 'mouse' && event.button !== 0)) return;
            const card = event.target instanceof Element ? event.target.closest('.admin-mobile-data-card') : null;
            const host = card ? card.closest('[data-admin-mobile-table]') : null;
            if (!ownsHost(host) || host.classList.contains('is-selecting') || card.parentElement !== mobileItems(host)) return;
            // 开关、更多、复制、展开这类小控件照常用；标题（点开详情）和图片（点开预览）这两块大区域可以长按
            const control = event.target.closest('button, [role="button"], [role="switch"], input, select, textarea, a[href], label, [contenteditable="true"]');
            if (control && card.contains(control) && !control.matches('[data-admin-mobile-card-detail], .admin-mobile-card-heading-detail-control, .admin-mobile-card-media')) return;
            mlatch = null;
            mpress = {
                card, host, pointerId: event.pointerId,
                x: event.clientX, y: event.clientY, lastY: event.clientY,
                // 按住一小会儿先轻轻压下去，提示「继续按住就能拖」；普通点击太快，看不到这一下
                feedback: setTimeout(() => {
                    if (mpress && mpress.card === card) card.classList.add('md-drag-m-pressing');
                }, 180),
                timer: setTimeout(activateLongPress, LONG_PRESS_MS)
            };
        };

        const onMobilePointerMove = event => {
            if (mpress && event.pointerId === mpress.pointerId) {
                mpress.lastY = event.clientY;
                // 还没按够时间手指就挪开了：是在滑动列表，放弃长按
                if (Math.hypot(event.clientX - mpress.x, event.clientY - mpress.y) > PRESS_SLOP) clearPress();
                return;
            }
            if (mdrag && event.pointerId === mdrag.pointerId) {
                mdrag.pointerY = event.clientY;
                if (event.cancelable) event.preventDefault();
            }
        };

        const onMobilePointerEnd = event => {
            if (mpress && event.pointerId === mpress.pointerId) {
                clearPress();   // 没按够时间：就是一次普通点击，照常打开详情
                return;
            }
            if (mlatch !== null && event.pointerId === mlatch) {
                mlatch = null;
                suppressClickUntil = Date.now() + 500;
            }
            // 系统抢走了这次触摸（pointercancel）就当取消，不保存
            if (mdrag && event.pointerId === mdrag.pointerId) finishMobileDrag(event.type === 'pointerup');
        };

        // 长按触发后接管这次触摸：阻止页面跟着手指滚动（jQuery 绑不出非被动监听，用原生的）
        function onTouchMove(event) {
            if (mdrag && event.cancelable) event.preventDefault();
        }

        // Android 长按会弹系统菜单 / 选中文字
        const onContextMenu = event => {
            if ((mpress || mdrag || mlatch !== null) && event.target instanceof Element && ownsHost(event.target.closest('[data-admin-mobile-table]'))) {
                event.preventDefault();
            }
        };

        // 拖完抬手的那一下不能被当成点击（会打开详情 / 图片预览）
        const onClickCapture = event => {
            if (!suppressClickUntil || Date.now() > suppressClickUntil) return;
            if (!(event.target instanceof Element) || !ownsHost(event.target.closest('[data-admin-mobile-table]'))) return;
            suppressClickUntil = 0;
            event.preventDefault();
            event.stopImmediatePropagation();
        };

        const onVisibilityChange = () => {
            if (document.visibilityState !== 'hidden') return;
            if (drag) finishDrag(false);
            press = null;
            if (mdrag) finishMobileDrag(false);
            clearPress();
        };

        $(document)
            .off(namespace)
            .on('pointerdown' + namespace, selector + ' .md-drag-handle', function (event) {
                onPointerDown(event.originalEvent || event, this);
            })
            .on('pointerdown' + namespace, event => onMobilePointerDown(event.originalEvent || event))
            .on('pointermove' + namespace, event => {
                const original = event.originalEvent || event;
                onPointerMove(original);
                onMobilePointerMove(original);
            })
            .on('pointerup' + namespace + ' pointercancel' + namespace, event => {
                const original = event.originalEvent || event;
                onPointerEnd(original);
                onMobilePointerEnd(original);
            })
            .on('keydown' + namespace, event => {
                if (event.key !== 'Escape') return;
                if (drag) finishDrag(false);
                press = null;
                if (mdrag) finishMobileDrag(false);
                clearPress();
            });
        // 拖到一半切走窗口：当作取消
        $(window).off(namespace).on('blur' + namespace, () => {
            if (drag) finishDrag(false);
            press = null;
            if (mdrag) finishMobileDrag(false);
            clearPress();
        });
        document.addEventListener('contextmenu', onContextMenu, true);
        document.addEventListener('click', onClickCapture, true);
        document.addEventListener('visibilitychange', onVisibilityChange);
        if (scope) {
            scope.classList.add('md-drag-m-scope');
            scope.addEventListener('touchmove', onTouchMove, {passive: false});
        }
        // 表格每次出新数据都会触发 admin:table:*，手机版卡片列表随后整块重画：先收拾进行中的长按 / 拖动
        $(tableElement()).off(namespace).on(`admin:table:ready${namespace} admin:table:update${namespace}`, () => abortMobile());

        return {
            // 表格每次渲染完成时调用：中止进行中的拖动（行已被重绘），并同步手柄可用状态
            sync() {
                if (destroyed) return;
                abortDrag();
                abortMobile();
                tableElement()?.setAttribute('data-md-drag', '');
                syncHandles();
            },
            abort() {
                abortDrag();
                abortMobile();
            },
            destroy() {
                if (destroyed) return;
                abortDrag();
                abortMobile();
                destroyed = true;
                clearTimeout(syncTimer);
                $(document).off(namespace);
                $(window).off(namespace);
                $(tableElement()).off(namespace);
                document.removeEventListener('contextmenu', onContextMenu, true);
                document.removeEventListener('click', onClickCapture, true);
                document.removeEventListener('visibilitychange', onVisibilityChange);
                if (scope) {
                    scope.removeEventListener('touchmove', onTouchMove);
                    scope.classList.remove('md-drag-m-scope');
                }
                mobileHost()?.classList.remove('md-drag-m-active');
                tableElement()?.removeAttribute('data-md-drag');
                // 样式是公共的：页面上已经没有启用拖动排序的表格时再移除
                if (!document.querySelector('table[data-md-drag]')) document.getElementById(STYLE_ID)?.remove();
            }
        };
    };

    return {column, attach};
})();
