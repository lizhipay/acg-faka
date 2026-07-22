/* =============================================================================
   ACG-faka 用户中心 · 玻璃皮交互控制器
   - 顶部二级导航:下拉(hover/click)、当前页高亮(load + pjax)、移动抽屉
   - MUI 浮动标签:移植自 admin/material.js(前台 component.popup 表单同款 .layui-form-pane)
   载入于 Common/Footer.html,晚于 jquery/layui/component。前台永远亮色玻璃。
   ============================================================================= */
!function () {
  var doc = document;
  if (doc.documentElement.getAttribute('data-theme') == null) {
    doc.documentElement.setAttribute('data-theme', 'light');
  }

  /* ---------------- 顶部导航 ---------------- */
  function navActive() {
    var path = location.pathname.replace(/\/+$/, '') || '/';
    // 清旧态
    doc.querySelectorAll('.uc-nav__item.active, .uc-nav__link.active, .uc-subitem.active, .uc-drawer__link.active')
      .forEach(function (a) { a.classList.remove('active'); });
    doc.querySelectorAll('.uc-nav__list > li.has-active')
      .forEach(function (li) { li.classList.remove('has-active'); });

    // 以“最长匹配 href”命中(避免 /user/... 前缀误判),取胜出的 data-match 值
    var bestVal = null, bestLen = -1;
    doc.querySelectorAll('.uc-nav a[data-match], .uc-drawer__link[data-match]').forEach(function (a) {
      var m = a.getAttribute('data-match');
      if (!m) return;
      if (path === m || path.indexOf(m + '/') === 0 || path === m.replace(/\/index$/, '')) {
        if (m.length > bestLen) { bestLen = m.length; bestVal = m; }
      }
    });
    if (bestVal == null) return;
    // 顶栏与抽屉里“同一 data-match”的所有链接一并高亮(桌面导航项/二级项 + 移动抽屉项)
    doc.querySelectorAll('[data-match="' + bestVal + '"]').forEach(function (a) {
      a.classList.add('active');
      var sub = a.closest('.uc-submenu');
      if (sub) {
        var li = sub.closest('.uc-nav__list > li');
        if (li) li.classList.add('has-active');
      }
    });
  }

  function initDropdowns() {
    var openTimer = null;
    function closeAll(except) {
      doc.querySelectorAll('.uc-nav__list > li.is-open, .uc-user.is-open').forEach(function (li) {
        if (li !== except) li.classList.remove('is-open');
      });
    }
    // 悬停(桌面)
    doc.querySelectorAll('.uc-nav__list > li.uc-has-sub, .uc-user').forEach(function (li) {
      li.addEventListener('mouseenter', function () {
        if (window.matchMedia('(max-width: 992px)').matches) return;
        clearTimeout(openTimer); closeAll(li); li.classList.add('is-open');
      });
      li.addEventListener('mouseleave', function () {
        if (window.matchMedia('(max-width: 992px)').matches) return;
        openTimer = setTimeout(function () { li.classList.remove('is-open'); }, 140);
      });
      // 点击触发项也可切换(触屏/无 hover)
      var trigger = li.querySelector(':scope > .uc-nav__item, :scope > .uc-user__btn');
      if (trigger) trigger.addEventListener('click', function (e) {
        if (trigger.tagName === 'A' && trigger.getAttribute('href') && trigger.getAttribute('href') !== 'javascript:;') return;
        e.preventDefault(); e.stopPropagation();
        var open = li.classList.contains('is-open'); closeAll(li);
        li.classList.toggle('is-open', !open);
      });
    });
    doc.addEventListener('click', function () { closeAll(null); });
  }

  function initMobile() {
    var body = doc.body;
    function open() { body.classList.add('uc-drawer-open'); }
    function close() { body.classList.remove('uc-drawer-open'); }
    doc.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('.uc-burger') : null;
      if (t) { e.preventDefault(); e.stopPropagation(); body.classList.contains('uc-drawer-open') ? close() : open(); return; }
      if (e.target.closest && e.target.closest('.uc-drawer__shade')) { close(); return; }
      // 抽屉里点导航链接(真实跳转)后关闭
      var link = e.target.closest ? e.target.closest('.uc-drawer__link') : null;
      if (link && link.getAttribute('href') && link.getAttribute('href') !== 'javascript:;') { close(); }
    });
  }

  /* ---------------- 工单未读角标 ---------------- */
  function refreshTicketBadge() {
    var badges = doc.querySelectorAll('.uc-ticket-nav-badge');
    var $ = window.jQuery;
    if (!badges.length || !$) return;
    $.ajax({
      type: 'POST',
      url: '/user/api/ticket/badge',
      data: {},
      global: false,
      success: function (res) {
        if (!res || res.code !== 200) return;
        var count = Math.max(0, parseInt(res.data && res.data.count, 10) || 0);
        badges.forEach(function (badge) {
          badge.textContent = count > 99 ? '99+' : String(count);
          badge.classList.toggle('is-empty', count < 1);
          badge.setAttribute('aria-label', count > 0 ? count + ' 条未读工单消息' : '没有未读工单消息');
        });
      }
    });
  }
  window.ucTicketRefreshBadge = refreshTicketBadge;

  function initTicketBadge() {
    if (!doc.querySelector('.uc-ticket-nav-badge')) return;
    refreshTicketBadge();
    if (window.__ucTicketBadgeTimer) clearInterval(window.__ucTicketBadgeTimer);
    window.__ucTicketBadgeTimer = setInterval(function () {
      if (!doc.hidden) refreshTicketBadge();
    }, 60000);
    doc.addEventListener('visibilitychange', function () {
      if (!doc.hidden) refreshTicketBadge();
    });
  }

  /* ---------------- 消息通知与最近消息 ---------------- */
  var messageRequestVersion = 0;

  function messageEscape(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function messageTime(value) {
    if (!value) return '刚刚';
    var time = new Date(String(value).replace(/-/g, '/')).getTime();
    if (!Number.isFinite(time)) return messageEscape(value);
    var seconds = Math.max(0, Math.floor((Date.now() - time) / 1000));
    if (seconds < 60) return '刚刚';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' 分钟前';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' 小时前';
    if (seconds < 604800) return Math.floor(seconds / 86400) + ' 天前';
    return messageEscape(value);
  }

  function normalizeMessage(row) {
    row = row || {};
    var source = row.message || row.system_message || row;
    return {
      id: row.id || row.user_message_id || source.user_message_id || 0,
      message_id: row.message_id || source.id || 0,
      title: row.title || source.title || '未命名消息',
      summary: row.summary || source.summary || '',
      content: row.content || source.content || '',
      jump_url: row.jump_url || source.jump_url || row.url || source.url || row.link_url || source.link_url || '',
      create_time: row.create_time || source.create_time || '',
      update_time: row.update_time || source.update_time || '',
      read_time: row.read_time || null,
      became_read: row.became_read,
      unread_count: row.unread_count
    };
  }

  function recentSignature(rows) {
    return JSON.stringify((rows || []).map(function (raw) {
      var item = normalizeMessage(raw);
      return [item.id, item.read_time || '', item.title, item.summary, item.create_time];
    }));
  }

  function commitRecentMessages(rows) {
    renderRecentMessages(rows);
    window.__ucMessageRecentSignature = recentSignature(rows);
    window.__ucMessagePendingRecent = null;
  }

  function closeMessageCenter(restoreFocus) {
    var center = doc.querySelector('.uc-message-center');
    if (center) center.classList.remove('is-open');
    var button = doc.querySelector('.uc-message-btn');
    if (button) {
      button.setAttribute('aria-expanded', 'false');
      if (restoreFocus) button.focus();
    }
    if (window.__ucMessagePendingRecent) commitRecentMessages(window.__ucMessagePendingRecent);
  }

  function setMessageCount(count) {
    count = Math.max(0, parseInt(count, 10) || 0);
    doc.querySelectorAll('.uc-message-badge, .uc-message-drawer-badge').forEach(function (badge) {
      badge.classList.remove('is-error');
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.classList.toggle('is-empty', count < 1);
      badge.setAttribute('aria-label', count > 0 ? count + ' 条未读消息' : '没有未读消息');
    });
    var button = doc.querySelector('.uc-message-btn');
    if (button) button.setAttribute('aria-label', count > 0 ? count + ' 条未读消息' : '没有未读消息');
    var label = doc.querySelector('.uc-message-dropdown__count');
    if (label) label.textContent = count > 0 ? count + ' 条未读' : '暂无未读';
  }

  function renderMessageLoadFailure() {
    var container = doc.querySelector('.uc-message-recent');
    if (container) {
      container.innerHTML = '<button type="button" class="uc-message-recent__retry"><span class="material-icons-outlined" aria-hidden="true">cloud_off</span><strong>消息加载失败</strong><span>点击重新加载</span></button>';
    }
    window.__ucMessagePendingRecent = null;
    window.__ucMessageRecentSignature = null;
    doc.querySelectorAll('.uc-message-badge, .uc-message-drawer-badge').forEach(function (badge) {
      badge.textContent = '!';
      badge.classList.remove('is-empty');
      badge.classList.add('is-error');
      badge.setAttribute('aria-label', '消息加载失败');
    });
    var button = doc.querySelector('.uc-message-btn');
    if (button) button.setAttribute('aria-label', '消息加载失败，点击重试');
    var label = doc.querySelector('.uc-message-dropdown__count');
    if (label) label.textContent = '加载失败';
  }

  function renderRecentMessages(rows) {
    var container = doc.querySelector('.uc-message-recent');
    if (!container) return;
    if (!rows.length) {
      container.innerHTML = '<div class="uc-message-recent__empty"><span class="material-icons-outlined" aria-hidden="true">notifications_off</span><strong>暂时没有消息</strong><small>新的通知会出现在这里</small></div>';
      return;
    }
    container.innerHTML = rows.map(function (raw) {
      var item = normalizeMessage(raw);
      return '<button type="button" class="uc-message-recent__item' + (item.read_time ? '' : ' is-unread') + '" data-message-id="' + encodeURIComponent(item.id) + '">' +
        '<span class="uc-message-recent__mark"><i></i><span class="material-icons-outlined" aria-hidden="true">' + (item.read_time ? 'drafts' : 'mark_email_unread') + '</span></span>' +
        '<span class="uc-message-recent__copy"><strong>' + messageEscape(item.title) + '</strong><small>' + messageEscape(item.summary || '点击查看消息详情') + '</small><time>' + messageTime(item.create_time) + '</time></span>' +
        '<span class="material-icons-outlined uc-message-recent__arrow" aria-hidden="true">chevron_right</span>' +
      '</button>';
    }).join('');
  }

  function refreshMessageCenter() {
    var $ = window.jQuery;
    if (!$ || !doc.querySelector('.uc-message-center')) return;
    var version = ++messageRequestVersion;
    $.ajax({
      type: 'POST',
      url: '/user/api/message/recent',
      data: {},
      global: false,
      success: function (res) {
        if (version !== messageRequestVersion) return;
        if (!res || res.code !== 200) {
          renderMessageLoadFailure();
          return;
        }
        var payload = res.data || {};
        var rows = Array.isArray(payload.list) ? payload.list.slice(0, 6) : (Array.isArray(payload.recent) ? payload.recent.slice(0, 6) : []);
        var count = payload.count != null ? payload.count : payload.unread_count;
        setMessageCount(count);
        var signature = recentSignature(rows);
        if (signature !== window.__ucMessageRecentSignature) {
          var recent = doc.querySelector('.uc-message-recent');
          var center = doc.querySelector('.uc-message-center');
          var keepFocus = !!(recent && center && center.classList.contains('is-open') && recent.contains(doc.activeElement));
          if (keepFocus) window.__ucMessagePendingRecent = rows;
          else commitRecentMessages(rows);
        }

        var newestItem = rows.length ? normalizeMessage(rows[0]) : null;
        var newest = newestItem ? String(newestItem.message_id || newestItem.id) : '';
        var newestNumber = /^\d+$/.test(newest) ? Number(newest) : 0;
        var previousNumber = /^\d+$/.test(String(window.__ucMessageNewestId || '')) ? Number(window.__ucMessageNewestId) : 0;
        var newestTime = newestItem ? new Date(String(newestItem.create_time || '').replace(/-/g, '/')).getTime() || 0 : 0;
        var previousTime = Number(window.__ucMessageNewestTime) || 0;
        var hasNewMessage = window.__ucMessageNewestId !== undefined && newest &&
          (newestNumber && previousNumber ? newestNumber > previousNumber : newestTime > previousTime);
        if (hasNewMessage) {
          var button = doc.querySelector('.uc-message-btn');
          if (button) {
            button.classList.remove('is-ringing');
            void button.offsetWidth;
            button.classList.add('is-ringing');
            setTimeout(function () { button.classList.remove('is-ringing'); }, 900);
          }
        }
        if (window.__ucMessageNewestId === undefined || newestNumber > previousNumber || (!newestNumber && newestTime > previousTime)) {
          window.__ucMessageNewestId = newest;
          window.__ucMessageNewestTime = newestTime;
        }
      },
      error: function () {
        if (version !== messageRequestVersion) return;
        renderMessageLoadFailure();
      }
    });
  }

  function notifyMessageChanged(options) {
    refreshMessageCenter();
    if (window.jQuery) window.jQuery(doc).trigger('uc:message-changed', [options || {}]);
  }

  function openRecentMessage(id, trigger) {
    var $ = window.jQuery;
    if (!$ || !id || typeof component === 'undefined' || typeof component.previewMessage !== 'function') return;
    var $trigger = $(trigger);
    if ($trigger.hasClass('is-loading')) return;
    $trigger.addClass('is-loading').prop('disabled', true);
    $.ajax({
      type: 'POST',
      url: '/user/api/message/detail',
      data: {id: id},
      global: false,
      success: function (res) {
        if (!res || res.code !== 200) {
          if (typeof message !== 'undefined') message.error((res && res.msg) || '消息读取失败');
          return;
        }
        var detail = normalizeMessage(res.data && res.data.message ? res.data.message : res.data);
        closeMessageCenter(true);
        refreshMessageCenter();
        detail.onClose = function () {
          if (window.jQuery) window.jQuery(doc).trigger('uc:message-changed');
        };
        component.previewMessage(detail);
      },
      error: function () {
        if (typeof message !== 'undefined') message.error('消息读取失败，请稍后重试');
      },
      complete: function () { $trigger.removeClass('is-loading').prop('disabled', false); }
    });
  }

  function initMessageCenter() {
    if (!doc.querySelector('.uc-message-center')) return;
    window.ucMessageRefresh = refreshMessageCenter;
    window.ucMessageNotifyChanged = notifyMessageChanged;

    if (!window.__ucMessageCenterBound) {
      window.__ucMessageCenterBound = true;
      var $ = window.jQuery;
      if ($) {
        $(doc).off('.ucMessageCenter')
          .on('click.ucMessageCenter', function (event) {
            var $target = $(event.target);
            var $button = $target.closest('.uc-message-btn');
            if ($button.length) {
              event.preventDefault();
              event.stopPropagation();
              var $center = $button.closest('.uc-message-center');
              var opening = !$center.hasClass('is-open');
              $('.uc-nav__list > li.is-open, .uc-user.is-open').removeClass('is-open');
              $('.uc-message-center').removeClass('is-open');
              $center.toggleClass('is-open', opening);
              $button.attr('aria-expanded', opening ? 'true' : 'false');
              if (opening) refreshMessageCenter();
              return;
            }
            var $item = $target.closest('.uc-message-recent__item');
            if ($item.length) {
              event.preventDefault();
              event.stopPropagation();
              openRecentMessage(decodeURIComponent($item.attr('data-message-id') || ''), $item);
              return;
            }
            if ($target.closest('.uc-message-recent__retry').length) {
              event.preventDefault();
              refreshMessageCenter();
              return;
            }
            if ($target.closest('.uc-message-dropdown__all').length) {
              closeMessageCenter();
              return;
            }
            if (!$target.closest('.uc-message-center').length) closeMessageCenter();
          })
          .on('keydown.ucMessageCenter', function (event) {
            if (event.key === 'Escape') closeMessageCenter(true);
          });
      }
    }

    refreshMessageCenter();
    if (window.__ucMessageTimer) clearInterval(window.__ucMessageTimer);
    window.__ucMessageTimer = setInterval(function () {
      if (!doc.hidden) refreshMessageCenter();
    }, 60000);

    if (window.__ucMessageVisibilityHandler) {
      doc.removeEventListener('visibilitychange', window.__ucMessageVisibilityHandler);
    }
    window.__ucMessageVisibilityHandler = function () {
      if (!doc.hidden) refreshMessageCenter();
    };
    doc.addEventListener('visibilitychange', window.__ucMessageVisibilityHandler);
  }

  /* ---------------- MUI 浮动标签(移植自 material.js) ---------------- */
  function valueOf(item) {
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
    var items = (scope || doc).querySelectorAll('.layui-form-pane .layui-form-item');
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      if (item.__muiSeen) continue;
      item.__muiSeen = 1;
      var label = item.querySelector(':scope > .layui-form-label');
      if (!label || !label.textContent.replace(/\s+/g, '')) continue;
      var blocked = item.querySelector('.layui-form-switch, .layui-form-checkbox, .layui-form-radio, .image-render, .file-render, .layui-upload, .editor-wrapper, .w-e-text-container, .ace_editor, .treeCheckbox');
      if (blocked) continue;
      var textish = item.querySelector('.layui-input:not([type=hidden]), .layui-textarea');
      if (!textish) continue;
      item.classList.add('mui-float');
      refreshItem(item);
    }
  }
  function refreshAll(scope) {
    var items = (scope || doc).querySelectorAll('.layui-form-item.mui-float');
    for (var i = 0; i < items.length; i++) refreshItem(items[i]);
  }
  doc.addEventListener('focusin', function (e) {
    var item = e.target && e.target.closest ? e.target.closest('.layui-form-item.mui-float') : null;
    if (item) item.classList.add('mui-focused');
  });
  doc.addEventListener('focusout', function (e) {
    var item = e.target && e.target.closest ? e.target.closest('.layui-form-item.mui-float') : null;
    if (item) { item.classList.remove('mui-focused'); refreshItem(item); }
  });
  doc.addEventListener('input', function (e) {
    var item = e.target && e.target.closest ? e.target.closest('.layui-form-item.mui-float') : null;
    if (item) refreshItem(item);
  });
  /* 给每个文本/下拉字段注入 MUI notchedOutline(真·缺口边框,取代假 notch 白底) */
  function makeOutline(labelText) {
    var fs = doc.createElement('fieldset'); fs.className = 'uc-outline'; fs.setAttribute('aria-hidden', 'true');
    var lg = doc.createElement('legend'); var sp = doc.createElement('span');
    sp.textContent = labelText; lg.appendChild(sp); fs.appendChild(lg);
    return fs;
  }
  function initMuiOutline(scope) {
    var fields = (scope || doc).querySelectorAll('.uc-card .form-body > .layui-input, .uc-card .form-body > select');
    for (var i = 0; i < fields.length; i++) {
      var inp = fields[i], body = inp.parentElement, wrap = body && body.parentElement;
      if (!wrap || wrap.__muiOutlined) continue;
      var label = wrap.querySelector(':scope > .form-header');
      if (!label) continue;
      wrap.__muiOutlined = 1; wrap.classList.add('uc-field');
      wrap.appendChild(makeOutline(label.textContent.trim()));
    }
    // 列表页搜索区字段(.table-search .mui-sf):同款真缺口(标签由 search.js 渲染)
    var sfs = (scope || doc).querySelectorAll('.uc-card .table-search .mui-sf');
    for (var j = 0; j < sfs.length; j++) {
      var sf = sfs[j];
      if (sf.__muiOutlined) continue;
      var lb = sf.querySelector(':scope > .mui-sf__label');
      if (!lb || !lb.textContent.replace(/\s+/g, '')) continue;
      sf.__muiOutlined = 1; sf.classList.add('uc-sfield');
      sf.appendChild(makeOutline(lb.textContent.trim()));
    }
  }
  /* bootstrap-table 中文化 + 玻璃空态(仅前台生效;glass.js 先于 ready() 控制器执行) */
  function initTableCn() {
    var $ = window.jQuery;
    if (!$ || !$.fn || !$.fn.bootstrapTable || $.fn.bootstrapTable.__ucCn) return;
    $.fn.bootstrapTable.__ucCn = 1;
    $.extend($.fn.bootstrapTable.defaults, {
      formatNoMatches: function () {
        return '<div class="uc-empty"><span class="material-icons-outlined">inbox</span><p>暂无数据</p></div>';
      },
      formatLoadingMessage: function () { return '正在加载'; },
      formatShowingRows: function (f, t, total) { return total > 0 ? '第 ' + f + ' - ' + t + ' 条 · 共 ' + total + ' 条' : '共 0 条'; },
      formatRecordsPerPage: function (n) { return '每页 ' + n + ' 条'; }
    });
  }
  /* 卡片内 tab 切换(店铺页 3 合 1);切到某 tab 时让里面的表格/编辑器重新测量 */
  function initTabs(scope) {
    (scope || doc).querySelectorAll('.uc-tabs').forEach(function (bar) {
      if (bar.__tabsInit) return; bar.__tabsInit = 1;
      bar.addEventListener('click', function (e) {
        var tab = e.target.closest ? e.target.closest('.uc-tab') : null;
        if (!tab || tab.classList.contains('active')) return;
        var key = tab.getAttribute('data-tab');
        var card = bar.closest('.uc-card') || bar.parentElement;
        bar.querySelectorAll('.uc-tab').forEach(function (t) { t.classList.toggle('active', t === tab); });
        card.querySelectorAll('.uc-tabpanel').forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-panel') === key); });
        setTimeout(function () {
          try { window.dispatchEvent(new Event('resize')); } catch (er) {}
          var cm = card.querySelector('.uc-tabpanel.active .CodeMirror');
          if (cm && cm.CodeMirror) cm.CodeMirror.refresh();
        }, 12);
      });
    });
  }
  var pending = false;
  function queueScan() {
    if (pending) return; pending = true;
    setTimeout(function () { pending = false; tagFloatables(); refreshAll(); initMuiOutline(); initTabs(); }, 60);
  }

  /* ---------------- 主题:亮 / 暗 / 自动(点击循环) ---------------- */
  function initTheme() {
    var KEY = 'uc-theme';
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function mode() { return localStorage.getItem(KEY) || 'auto'; }
    function resolve(m) { return (m === 'dark' || (m === 'auto' && mq && mq.matches)) ? 'dark' : 'light'; }
    function apply(m) {
      doc.documentElement.setAttribute('data-theme', resolve(m));
      var ico = doc.querySelector('.uc-theme-ico');
      if (ico) ico.textContent = m === 'dark' ? 'dark_mode' : (m === 'light' ? 'light_mode' : 'brightness_auto');
      var btn = doc.querySelector('.uc-theme-btn');
      if (btn) btn.title = '主题：' + (m === 'dark' ? '暗色' : (m === 'light' ? '亮色' : '跟随系统'));
    }
    apply(mode());
    var btn = doc.querySelector('.uc-theme-btn');
    if (btn) btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var order = ['light', 'dark', 'auto'], next = order[(order.indexOf(mode()) + 1) % 3];
      localStorage.setItem(KEY, next); apply(next);
    });
    if (mq && mq.addEventListener) mq.addEventListener('change', function () { if (mode() === 'auto') apply('auto'); });
  }

  /* ---------------- 启动 ---------------- */
  function boot() {
    initTheme(); initTableCn();
    navActive(); initDropdowns(); initMobile(); initTicketBadge(); initMessageCenter();
    tagFloatables(); refreshAll(); initMuiOutline(); initTabs();
    if (doc.body && window.MutationObserver) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes && muts[i].addedNodes.length) { queueScan(); return; }
        }
      }).observe(doc.body, { childList: true, subtree: true });
    }
    // pjax 换页后重算高亮
    if (window.jQuery) jQuery(doc).on('pjax:complete', function () { setTimeout(navActive, 0); refreshTicketBadge(); refreshMessageCenter(); closeMessageCenter(); });
  }
  if (doc.readyState !== 'loading') boot();
  else doc.addEventListener('DOMContentLoaded', boot);
}();
