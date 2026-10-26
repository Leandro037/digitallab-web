/* Estadísticas propias de Digital Lab: sin cookies, sin guardar la IP, respeta «No rastrear». Envía a /panel/r.php */
(function () {
  'use strict';
  var nav = navigator;
  if (nav.doNotTrack === '1' || window.doNotTrack === '1' || nav.globalPrivacyControl) return;
  if (/^\/panel\//.test(location.pathname) || /^\/login\//.test(location.pathname)) return;
  var URL_R = '/panel/r.php', pid = Math.random().toString(36).slice(2, 10), t0 = Date.now(), visible = 0, desde = Date.now();
  var maxScroll = 0, lcp = null, cls = 0, enviado = false;

  function enviar(o) {
    o.pid = pid; o.p = location.pathname; o.w = window.innerWidth;
    var s = JSON.stringify(o);
    try { if (nav.sendBeacon && nav.sendBeacon(URL_R, new Blob([s], { type: 'text/plain' }))) return; } catch (e) {}
    try { fetch(URL_R, { method: 'POST', body: s, keepalive: true, headers: { 'Content-Type': 'text/plain' } }); } catch (e) {}
  }

  // Visita
  var q = new URLSearchParams(location.search), ref = '';
  try { ref = document.referrer && new URL(document.referrer).origin; } catch (e) {}
  enviar({ tipo: 'pv', ref: ref || '', lang: nav.language || '', us: q.get('utm_source') || '', um: q.get('utm_medium') || '', uc: q.get('utm_campaign') || '',
    e404: document.title && /404|no encontrad/i.test(document.title) ? 1 : 0 });

  // Tiempo realmente visible, profundidad de lectura y velocidad percibida
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { visible += Date.now() - desde; fin(); } else { desde = Date.now(); enviado = false; }
  });
  function pos() {
    var h = document.documentElement, alto = h.scrollHeight - window.innerHeight;
    var p = alto > 0 ? Math.round(100 * (window.scrollY || h.scrollTop) / alto) : 100;
    if (p > maxScroll) maxScroll = Math.min(100, p);
  }
  addEventListener('scroll', pos, { passive: true }); pos();
  try {
    new PerformanceObserver(function (l) { var e = l.getEntries(); lcp = Math.round(e[e.length - 1].startTime); }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver(function (l) { l.getEntries().forEach(function (e) { if (!e.hadRecentInput) cls += e.value; }); }).observe({ type: 'layout-shift', buffered: true });
  } catch (e) {}
  function fin() {
    if (enviado) return; enviado = true; pos();
    enviar({ tipo: 'fin', dur: visible, scroll: maxScroll, lcp: lcp, cls: Math.round(cls * 10000) / 10000 });
  }
  addEventListener('pagehide', function () { if (document.visibilityState !== 'hidden') visible += Date.now() - desde; fin(); });

  // Clics en enlaces, botones y elementos marcados con data-track
  document.addEventListener('click', function (ev) {
    var el = ev.target && ev.target.closest ? ev.target.closest('a,button,[data-track]') : null;
    if (!el) return;
    var obj = el.getAttribute('data-track') || '';
    if (!obj) {
      var href = el.getAttribute('href') || '', txt = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 50);
      if (/^mailto:/i.test(href)) obj = 'correo';
      else if (/^tel:/i.test(href)) obj = 'teléfono';
      else if (/wa\.me|whatsapp/i.test(href)) obj = 'whatsapp';
      else if (/^https?:\/\//i.test(href) && href.indexOf(location.host) < 0) { try { obj = 'salida: ' + new URL(href).hostname.replace(/^www\./, ''); } catch (e) { obj = 'salida'; } }
      else obj = (href ? href.replace(/^https?:\/\/[^/]+/, '').split('?')[0] + ' · ' : '') + txt;
    }
    var s = el.closest('section,header,footer,nav,main'), sec = s ? (s.id || s.getAttribute('data-sec') || s.tagName.toLowerCase()) : '';
    enviar({ tipo: 'click', obj: obj, sec: sec });
  }, true);

  // Para eventos propios: window.dlTrack('nombre')
  window.dlTrack = function (nombre) { if (nombre) enviar({ tipo: 'evt', obj: String(nombre).slice(0, 120) }); };
  window.dlForm = function (nombre) { enviar({ tipo: 'form', obj: String(nombre || 'formulario').slice(0, 120) }); };
})();
