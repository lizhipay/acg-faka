/*!
 * material.js — Material UI theme controller for the acg-faka admin.
 *
 *  - Applies light / dark / auto theme (localStorage 'admin-theme', default 'auto').
 *  - Persists the desktop aside state (localStorage 'admin-aside-minimize').
 *  - Drives the topbar switcher (icon button + 3-item menu).
 *  - Follows the OS theme live while in 'auto' (matchMedia).
 *  - Faithful MUI floating-label state for Layui form fields.
 *
 * Loaded LAST in the footer; jQuery / layui / layer already exist. The inline
 * <head> FOUC guard has already set data-theme before first paint — this script
 * re-affirms it and wires interactivity. Idempotent (guarded) so being present in
 * both js() arrays / re-run under PJAX is harmless.
 */
(function () {
  if (window.__mdThemeInit) return;
  window.__mdThemeInit = true;

  var KEY = 'admin-theme';
  var ASIDE_KEY = 'admin-aside-minimize';
  var root = document.documentElement;
  var mql = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var asideMql = window.matchMedia ? window.matchMedia('(min-width: 992px)') : null;

  /* ---------- theme state ---------- */
  function getPref() {
    try { return localStorage.getItem(KEY) || 'auto'; } catch (e) { return 'auto'; }
  }
  function resolve(p) {
    if (p === 'auto') return (mql && mql.matches) ? 'dark' : 'light';
    return (p === 'dark') ? 'dark' : 'light';
  }
  function apply(p) {
    root.setAttribute('data-theme', resolve(p));
    root.setAttribute('data-theme-pref', p);
    syncMenu(p);
  }
  function setPref(p) {
    try { localStorage.setItem(KEY, p); } catch (e) {}
    apply(p);
  }

  apply(getPref());

  /* live OS changes while in auto */
  if (mql) {
    var onOS = function () { if (getPref() === 'auto') apply('auto'); };
    if (mql.addEventListener) mql.addEventListener('change', onOS);
    else if (mql.addListener) mql.addListener(onOS);
  }

  /* ---------- desktop aside state ---------- */
  function getAsidePref() {
    try { return localStorage.getItem(ASIDE_KEY) === 'on'; } catch (e) { return false; }
  }
  function applyAsidePref() {
    if (!document.body) return;
    if (asideMql && !asideMql.matches) {
      document.body.removeAttribute('data-kt-aside-minimize');
      return;
    }
    if (getAsidePref()) document.body.setAttribute('data-kt-aside-minimize', 'on');
    else document.body.removeAttribute('data-kt-aside-minimize');
  }
  function saveAsidePref() {
    try {
      localStorage.setItem(ASIDE_KEY, document.body.getAttribute('data-kt-aside-minimize') === 'on' ? 'on' : 'off');
    } catch (e) {}
  }
  function bindAsidePersistence() {
    var element = document.getElementById('kt_aside_toggle');
    if (!element || element.__mdAsidePersist || typeof KTToggle === 'undefined') return;
    var toggle = KTToggle.getInstance(element);
    if (!toggle) return;
    element.__mdAsidePersist = true;
    toggle.on('kt.toggle.changed', saveAsidePref);
  }

  applyAsidePref();
  if (asideMql) {
    if (asideMql.addEventListener) asideMql.addEventListener('change', applyAsidePref);
    else if (asideMql.addListener) asideMql.addListener(applyAsidePref);
  }
  if (document.readyState !== 'loading') bindAsidePersistence();
  else document.addEventListener('DOMContentLoaded', bindAsidePersistence);

  /* ---------- topbar switcher ---------- */
  function syncMenu(p) {
    var menu = document.getElementById('md-theme-menu');
    if (!menu) return;
    var items = menu.querySelectorAll('[data-theme-value]');
    for (var i = 0; i < items.length; i++) {
      items[i].classList.toggle('active', items[i].getAttribute('data-theme-value') === p);
    }
  }
  function closeMenu() {
    var menu = document.getElementById('md-theme-menu');
    if (menu) menu.classList.remove('show');
  }

  /* delegated — topbar persists across PJAX, but delegation is future-proof */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var toggle = t.closest('#md-theme-toggle');
    if (toggle) {
      e.preventDefault(); e.stopPropagation();
      var menu = document.getElementById('md-theme-menu');
      if (menu) { menu.classList.toggle('show'); syncMenu(getPref()); }
      return;
    }
    var opt = t.closest('[data-theme-value]');
    if (opt) {
      e.preventDefault();
      setPref(opt.getAttribute('data-theme-value'));
      closeMenu();
      return;
    }
    closeMenu();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

  /* ---------- faithful MUI floating labels (Layui fields) ---------- *
   * Adds .mui-float (eligible fields) + .mui-focused / .mui-filled to
   * .layui-form-item so material.css can float the label. Only text-like
   * fields in a .layui-form-pane get it; switches/checks/radios and
   * label-less fields fall back to plain outlined inputs.
   */
  function valueOf(item) {
    // layui <select> writes the chosen text into .layui-select-title .layui-input
    var sel = item.querySelector('.layui-select-title .layui-input');
    if (sel) return sel.value;
    var input = item.querySelector('.layui-input, .layui-textarea');
    return input ? input.value : '';
  }
  function refreshItem(item) {
    if (!item) return;
    var v = valueOf(item);
    item.classList.toggle('mui-filled', v != null && String(v).trim() !== '');
  }
  function tagFloatables(scope) {
    var items = (scope || document).querySelectorAll('.layui-form-pane .layui-form-item');
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      if (item.__muiSeen) { continue; }
      item.__muiSeen = 1;
      var label = item.querySelector(':scope > .layui-form-label');
      if (!label || !label.textContent.replace(/\s+/g, '')) continue;        // no title → plain
      // non-text controls (switch/checkbox/radio) and rich fields (image/file/editor) stay put.
      // treeSelect DOES float: its display value lives in .layui-select-title .layui-input (valueOf reads it).
      var blocked = item.querySelector('.layui-form-switch, .layui-form-checkbox, .layui-form-radio, .image-render, .file-render, .layui-upload, .editor-wrapper, .w-e-text-container, .ace_editor, .treeCheckbox');
      if (blocked) continue;
      var textish = item.querySelector('.layui-input:not([type=hidden]), .layui-textarea');
      if (!textish) continue;
      item.classList.add('mui-float');
      refreshItem(item);
    }
  }
  function refreshAll(scope) {
    var items = (scope || document).querySelectorAll('.layui-form-item.mui-float');
    for (var i = 0; i < items.length; i++) refreshItem(items[i]);
  }

  document.addEventListener('focusin', function (e) {
    var item = e.target && e.target.closest ? e.target.closest('.layui-form-item.mui-float') : null;
    if (item) item.classList.add('mui-focused');
  });
  document.addEventListener('focusout', function (e) {
    var item = e.target && e.target.closest ? e.target.closest('.layui-form-item.mui-float') : null;
    if (item) { item.classList.remove('mui-focused'); refreshItem(item); }
  });
  document.addEventListener('input', function (e) {
    var item = e.target && e.target.closest ? e.target.closest('.layui-form-item.mui-float') : null;
    if (item) refreshItem(item);
  });

  /* re-scan after Layui rebuilds fields (dict AJAX, select render, laydate writes) */
  var pending = false;
  function queueScan() {
    if (pending) return;
    pending = true;
    setTimeout(function () { pending = false; tagFloatables(); refreshAll(); initSettingsSelect2(); }, 60);
  }
  /* Replace the native node <select> with a custom dropdown that shows a vendor
   * icon per option (native <option> can't hold icons) and drops the "节点:" prefix.
   * The native <select> is kept (hidden) as the value holder, so the existing
   * change → /admin/api/app/setServer handler still fires. Auto-sizes to content. */
  function nodeMeta(t) {
    t = t || '';
    if (t.indexOf('腾讯') >= 0) return {icon: 'fa-cloud', color: '#0052D9'};
    if (t.indexOf('阿里') >= 0) return {icon: 'fa-cloud', color: '#FF6A00'};
    if (t.indexOf('抖音') >= 0) return {icon: 'fa-music', color: '#FE2C55'};
    if (t.indexOf('海外') >= 0 || t.indexOf('专线') >= 0) return {icon: 'fa-earth-asia', color: '#12B886'};
    return {icon: 'fa-server', color: '#6E6E6E'};
  }
  function nodeLabel(t) { return (t || '').replace(/^\s*节点\s*[:：]\s*/, '').trim(); }
  function nodeInner(meta, label, trailing) {
    return '<i class="fa-duotone fa-regular ' + meta.icon + ' md-nodesel__ico" style="color:' + meta.color + '"></i>' +
           '<span class="md-nodesel__label">' + label + '</span>' + trailing;
  }
  function enhanceOneNodeSelect(sel) {
    if (sel.__mdEnhanced) return;
    sel.__mdEnhanced = true;
    var wrap = document.createElement('div'); wrap.className = 'md-nodesel';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'md-nodesel__btn';
    var menu = document.createElement('div'); menu.className = 'md-nodesel__menu';
    function sync() {
      var opt = sel.options[sel.selectedIndex]; if (!opt) return;
      btn.innerHTML = nodeInner(nodeMeta(opt.textContent), nodeLabel(opt.textContent), '<i class="fa-duotone fa-regular fa-chevron-down md-nodesel__caret"></i>');
      var items = menu.querySelectorAll('.md-nodesel__item');
      for (var k = 0; k < items.length; k++) items[k].classList.toggle('active', items[k].getAttribute('data-value') === sel.value);
    }
    for (var j = 0; j < sel.options.length; j++) {
      var opt = sel.options[j];
      var item = document.createElement('div'); item.className = 'md-nodesel__item'; item.setAttribute('data-value', opt.value);
      item.innerHTML = nodeInner(nodeMeta(opt.textContent), nodeLabel(opt.textContent), '<i class="fa-duotone fa-regular fa-check md-nodesel__check"></i>');
      (function (val) {
        item.addEventListener('click', function (e) {
          e.stopPropagation();
          if (sel.value !== val) { sel.value = val; sel.dispatchEvent(new Event('change', {bubbles: true})); }
          sync(); menu.classList.remove('show');
        });
      })(opt.value);
      menu.appendChild(item);
    }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.classList.contains('show');
      document.querySelectorAll('.md-nodesel__menu.show').forEach(function (m) { m.classList.remove('show'); });
      if (!open) menu.classList.add('show');
    });
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(btn); wrap.appendChild(menu); wrap.appendChild(sel);
    sel.style.display = 'none';
    sync();
  }
  function enhanceNodeSelects() {
    var sels = document.querySelectorAll('.app-server-select');
    for (var i = 0; i < sels.length; i++) enhanceOneNodeSelect(sels[i]);
  }

  /* 网站设置: turn native <select data-control=select2> into a real MUI dropdown component
   * (native selects are ugly). select2 ships + its dropdown is MUI-styled in material.css.
   * Guarded so re-scans (pjax / mutation) don't double-init. */
  function initSettingsSelect2() {
    var $ = window.jQuery;
    if (!$ || !$.fn || !$.fn.select2) return;
    $('.md-settings select[data-control="select2"]').each(function () {
      if (this.classList.contains('select2-hidden-accessible')) return;
      $(this).select2({ width: '100%', minimumResultsForSearch: Infinity });
    });
  }

  /* reusable MUI user cell (avatar-left + name-top / id-below) for table columns.
   * Exposed globally so per-page controllers (loaded individually in prod) can use it
   * without editing the shared format.user (which many other tables rely on). */
  window.mdUserCell = function (item) {
    if (!item) return '-';
    var name = item.username == null ? '' : String(item.username);
    var id = item.id == null ? '' : String(item.id);
    var av = item.avatar
      ? '<img src="' + item.avatar + '" class="md-user-cell__avatar" alt="">'
      : '<span class="md-user-cell__avatar md-user-cell__avatar--ph">' + ((name.charAt(0) || '?').toUpperCase()) + '</span>';
    return '<div class="md-user-cell">' + av +
      '<div class="md-user-cell__text"><span class="md-user-cell__name">' + name +
      '</span><span class="md-user-cell__id">' + id + '</span></div></div>';
  };

  /* owner 变体：无所属者（owner=0）时渲染「主站/系统」单元格，否则复用 mdUserCell。
   * 供 商家/所属者/创建者 等列使用（对应 format.owner 的系统态 → 店铺图标）。 */
  window.mdOwnerCell = function (item) {
    if (!item) {
      return '<div class="md-user-cell"><span class="md-user-cell__avatar md-user-cell__avatar--ph">' +
        '<i class="fa-duotone fa-regular fa-shop"></i></span>' +
        '<div class="md-user-cell__text"><span class="md-user-cell__name">主站</span>' +
        '<span class="md-user-cell__id">系统</span></div></div>';
    }
    return mdUserCell(item);
  };
  document.addEventListener('click', function () {
    document.querySelectorAll('.md-nodesel__menu.show').forEach(function (m) { m.classList.remove('show'); });
  });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(enhanceNodeSelects);

  function startObserver() {
    if (!document.body || !window.MutationObserver) return;
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        if (muts[i].addedNodes && muts[i].addedNodes.length) { queueScan(); return; }
      }
    }).observe(document.body, { childList: true, subtree: true });
    tagFloatables(); refreshAll(); enhanceNodeSelects(); initSettingsSelect2();
  }
  if (document.readyState !== 'loading') startObserver();
  else document.addEventListener('DOMContentLoaded', startObserver);
})();
