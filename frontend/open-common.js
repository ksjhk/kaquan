/* 卡世界开放平台 — 公共脚本：接口调用层 + 导航/页脚 + 工具函数 */
(function (w) {
  'use strict';

  /* ===== 接口配置 ===== */
  // ===== 部署配置：改成你自己的域名 =====
  var API_BASE = 'https://your-domain.com';        // 开放平台后端（本项目 backend 部署地址）
  var OPEN_API = API_BASE + '/open-api/platform';  // 开放平台账号体系
  var GOODS_API = API_BASE + '/open-api/v1';       // 货源数据（本平台 v1 公开接口，无需签名）
  var TOKEN_KEY = 'open_token';

  /* ===== token ===== */
  function getToken() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
  function setToken(t) { try { localStorage.setItem(TOKEN_KEY, t); } catch (e) {} }
  function clearToken() { try { localStorage.removeItem(TOKEN_KEY); } catch (e) {} }

  /* ===== toast ===== */
  function toast(msg, ms) {
    var el = document.getElementById('op-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'op-toast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.remove('show'); }, ms || 2400);
  }

  /* ===== 开放平台账号接口（后端并行开发中，失败时抛出带友好文案的 Error） ===== */
  function openApi(path, opts) {
    opts = opts || {};
    var headers = { 'Content-Type': 'application/json' };
    var token = getToken();
    if (token) headers['token'] = token;
    var fetchOpts = { method: opts.method || 'GET', headers: headers };
    if (opts.data) fetchOpts.body = JSON.stringify(opts.data);
    var url = OPEN_API + path;
    if (opts.query) {
      var qs = Object.keys(opts.query).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]); }).join('&');
      url += (url.indexOf('?') > -1 ? '&' : '?') + qs;
    }
    return fetch(url, fetchOpts).then(function (res) {
      return res.json().catch(function () { throw new Error('服务响应异常，请稍后再试'); });
    }).then(function (json) {
      if (json && json.code === 0) return json.data;
      throw new Error((json && json.message) || '操作失败，请稍后再试');
    }).catch(function (err) {
      if (err && err.message && err.message !== 'Failed to fetch') throw err;
      throw new Error('开放平台服务正在部署中，暂时无法连接，请稍后再试');
    });
  }

  /* ===== 货源接口（本平台 v1 公开接口，匿名可调） ===== */
  function goodsApi(path, query) {
    query = query || {};
    var qs = Object.keys(query).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); }).join('&');
    return fetch(GOODS_API + path + (qs ? '?' + qs : '')).then(function (res) {
      return res.json().catch(function () { throw new Error('货源数据响应异常'); });
    }).then(function (json) {
      if (json && json.code === 0) return json.data;
      throw new Error((json && json.message) || '货源数据加载失败');
    }).catch(function (err) {
      if (err && err.message && err.message !== 'Failed to fetch') throw err;
      throw new Error('货源服务暂时无法连接，请稍后刷新重试');
    });
  }

  /* ===== 工具 ===== */
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fen2yuan(fen) {
    var n = Number(fen);
    if (isNaN(n)) return '0.00';
    return (n / 100).toFixed(2);
  }
  function maskMobile(m) {
    m = String(m || '');
    return m.length >= 7 ? m.substr(0, 3) + '****' + m.substr(m.length - 4) : m;
  }
  function copyText(text, okMsg) {
    function done() { toast(okMsg || '已复制到剪贴板'); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;left:-9999px;';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败，请手动复制'); }
      document.body.removeChild(ta);
    }
  }
  function fmtTime(t) {
    if (!t) return '-';
    var d;
    if (typeof t === 'number' || /^\d+$/.test(String(t))) {
      d = new Date(parseInt(t, 10) * 1000);   // 后端 created_time/paid_time/last_used_time 都是秒级时间戳
    } else {
      d = new Date(String(t).replace(/-/g, '/'));
    }
    if (isNaN(d.getTime())) return String(t);
    function p(n) { return n < 10 ? '0' + n : '' + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  function fmtYuan(v) {   // balance 单位是元（decimal(10,2)，接口实测返回 10 表示10元），格式化为两位小数
    var n = parseFloat(v);
    if (isNaN(n)) n = 0;
    return n.toFixed(2);
  }

  function getQuery(name) {
    var m = location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  /* ===== 渲染公共导航 ===== */
  function renderHeader(active) {
    var menuLinks =
      '<a href="index.html"' + (active === 'index' ? ' class="active"' : '') + '>首页</a>' +
      '<a href="market.html"' + (active === 'market' ? ' class="active"' : '') + '>货源广场</a>' +
      '<a href="docs.html"' + (active === 'docs' ? ' class="active"' : '') + '>API 文档</a>' +
      '<a href="console.html"' + (active === 'console' ? ' class="active"' : '') + '>控制台</a>';
    var html =
      '<header class="op-header"><div class="op-header-inner">' +
      '<a class="op-logo" href="index.html">' +
      '<img src="../assets/logo-icon.png" alt="卡世界" />' +
      '<span class="op-logo-text">卡世界</span>' +
      '<span class="op-logo-badge">开放平台</span>' +
      '</a>' +
      '<nav class="op-menu">' + menuLinks + '</nav>' +
      '<button type="button" class="op-burger" id="op-burger" aria-label="打开导航菜单">' +
      '<svg viewBox="0 0 24 24"><path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>' +
      '</button>' +
      '<div class="op-actions" id="op-header-actions"></div>' +
      '</div>' +
      '<nav class="op-nav-panel" id="op-nav-panel">' + menuLinks + '</nav>' +
      '</header>';
    var mount = document.getElementById('op-header-mount');
    if (mount) mount.innerHTML = html;
    else document.body.insertAdjacentHTML('afterbegin', html);
    renderHeaderActions();

    // 移动端汉堡菜单：点击展开/收起，点任一项后收起
    var burger = document.getElementById('op-burger');
    var panel = document.getElementById('op-nav-panel');
    if (burger && panel) {
      burger.addEventListener('click', function () {
        panel.classList.toggle('show');
      });
      Array.prototype.forEach.call(panel.querySelectorAll('a'), function (a) {
        a.addEventListener('click', function () { panel.classList.remove('show'); });
      });
    }
  }

  function renderHeaderActions() {
    var box = document.getElementById('op-header-actions');
    if (!box) return;
    if (getToken()) {
      box.innerHTML = '<a class="op-btn-primary" href="console.html">进入控制台</a>';
    } else {
      box.innerHTML = '<a class="op-btn-login" href="login.html">登录</a>' +
        '<a class="op-btn-primary" href="register.html">免费注册</a>';
    }
  }

  function renderFooter() {
    var html =
      '<footer class="op-footer"><div class="op-footer-inner">' +
      '<div class="op-footer-top">' +
      '<div class="op-footer-brand"><img src="../assets/logo-icon.png" alt="卡世界" /><span>卡世界开放平台</span></div>' +
      '<div class="op-footer-links">' +
      '<div class="op-footer-col"><div class="op-footer-col-title">产品</div>' +
      '<a href="market.html">货源广场</a><a href="docs.html">API 文档</a><a href="console.html">开发者控制台</a></div>' +
      '<div class="op-footer-col"><div class="op-footer-col-title">账号</div>' +
      '<a href="register.html">免费注册</a><a href="login.html">登录</a></div>' +
      '<div class="op-footer-col"><div class="op-footer-col-title">关于</div>' +
      '<a href="../index.html">卡世界官网</a><a href="javascript:void(0)" class="op-dev-qr-open">联系开发者</a></div>' +
      '</div></div>' +
      '<div class="op-footer-bottom">© 卡世界开放平台 · 会员权益货源 API 一站式接入 ｜ <a href="https://beian.miit.gov.cn/" target="_blank" rel="noreferrer">闽ICP备2023011865号-28</a></div>' +
      '</div></footer>';
    var mount = document.getElementById('op-footer-mount');
    if (mount) mount.innerHTML = html;
    else document.body.insertAdjacentHTML('beforeend', html);

    // 「联系开发者」二维码弹窗（DOM 注入一次，遮罩点击/右上角按钮关闭）
    if (!document.getElementById('op-dev-qr-modal')) {
      document.body.insertAdjacentHTML('beforeend',
        '<div class="op-modal-mask" id="op-dev-qr-modal">' +
        '<div class="op-modal-box">' +
        '<button class="op-modal-close" id="op-dev-qr-close">&times;</button>' +
        '<div class="op-modal-title">联系开发者</div>' +
        '<img src="assets/wecom-qr.png" alt="企业微信二维码" />' +
        '<div class="op-modal-tip">扫码添加企业微信</div>' +
        '</div></div>');
      var modal = document.getElementById('op-dev-qr-modal');
      modal.addEventListener('click', function (e) {
        if (e.target === modal) modal.classList.remove('show');
      });
      document.getElementById('op-dev-qr-close').addEventListener('click', function () {
        modal.classList.remove('show');
      });
    }
    Array.prototype.forEach.call(document.querySelectorAll('.op-dev-qr-open'), function (el) {
      el.addEventListener('click', function () {
        document.getElementById('op-dev-qr-modal').classList.add('show');
      });
    });
  }

  /* ===== 导出 ===== */
  w.OP = {
    OPEN_API: OPEN_API, GOODS_API: GOODS_API,
    getToken: getToken, setToken: setToken, clearToken: clearToken,
    toast: toast, openApi: openApi, goodsApi: goodsApi,
    escapeHtml: escapeHtml, fen2yuan: fen2yuan, maskMobile: maskMobile, fmtTime: fmtTime, fmtYuan: fmtYuan,
    copyText: copyText, getQuery: getQuery,
    renderHeader: renderHeader, renderFooter: renderFooter
  };
})(window);
