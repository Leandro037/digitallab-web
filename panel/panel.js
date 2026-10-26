/* Panel de estadísticas de Digital Lab. Los datos llegan de api.php (solo con sesión). */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var NOMBRES = null;
  try { NOMBRES = new Intl.DisplayNames(['es'], { type: 'region' }); } catch (e) {}
  var filtros = { p: '', pais: '', disp: '', fuente: '' }, rango = '7', ultimo = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(n) { return Number(n || 0).toLocaleString('es'); }
  function dur(s) { s = Math.round(s || 0); return s >= 60 ? Math.floor(s / 60) + ' min ' + (s % 60) + ' s' : s + ' s'; }
  function pais(cc) { if (!cc || cc === '??') return 'Desconocido'; try { return (NOMBRES && NOMBRES.of(cc)) || cc; } catch (e) { return cc; } }
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

  function fijaRango(r) {
    var h = new Date(), d = new Date();
    if (r === 'ayer') { h.setDate(h.getDate() - 1); d = new Date(h); }
    else if (r !== 'hoy') d.setDate(d.getDate() - (Number(r) - 1));
    $('desde').value = ymd(d); $('hasta').value = ymd(h);
  }

  function cargar() {
    var q = new URLSearchParams({ desde: $('desde').value, hasta: $('hasta').value });
    Object.keys(filtros).forEach(function (k) { if (filtros[k]) q.set(k, filtros[k]); });
    fetch('api.php?' + q, { credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401) { location.replace('/login/'); throw 0; }
      return r.json();
    }).then(function (d) { if (!d.ok) throw 1; ultimo = d; pinta(d); }).catch(function (e) {
      if (e === 0) return;
      aviso('No se pudieron cargar los datos. Reintenta en unos segundos.');
    });
  }

  function aviso(t) { var a = $('aviso'); a.hidden = !t; a.textContent = t || ''; }

  function delta(a, b, inv) {
    if (!b) return a ? '<em class="eq">nuevo</em>' : '<em class="eq">—</em>';
    var p = Math.round(100 * (a - b) / b); if (!p) return '<em class="eq">= igual</em>';
    var bien = inv ? p < 0 : p > 0;
    return '<em class="' + (bien ? 'up' : 'dn') + '">' + (p > 0 ? '▲ ' : '▼ ') + Math.abs(p) + ' %</em>';
  }

  function pinta(d) {
    var r = d.resumen, a = d.anterior;
    $('ahoraN').textContent = d.ahora.visitantes;
    $('ahora').title = 'Personas con actividad en los últimos 5 minutos' + (d.ahora.paginas.length ? ': ' + d.ahora.paginas.map(function (x) { return x.p + ' (' + x.n + ')'; }).join(', ') : '');
    var k = [
      ['Visitantes', num(r.visitantes), delta(r.visitantes, a.visitantes)],
      ['Páginas vistas', num(r.vistas), delta(r.vistas, a.vistas)],
      ['Sesiones', num(r.sesiones), delta(r.sesiones, a.sesiones)],
      ['Páginas por sesión', String(r.paginas_por_sesion).replace('.', ','), delta(r.paginas_por_sesion, a.paginas_por_sesion)],
      ['Tiempo medio por sesión', dur(r.tiempo_medio_s), delta(r.tiempo_medio_s, a.tiempo_medio_s)],
      ['Sesiones con interacción', r.interaccion_pct + ' %', delta(r.interaccion_pct, a.interaccion_pct)],
      ['Rebote', r.rebote_pct + ' %', delta(r.rebote_pct, a.rebote_pct, true)],
      ['Clics', num(r.clics), delta(r.clics, a.clics)],
      ['Formularios enviados', num(r.formularios), delta(r.formularios, a.formularios)],
      ['Conversión', String(r.conversion_pct).replace('.', ',') + ' %', delta(r.conversion_pct, a.conversion_pct)]
    ];
    $('kpis').innerHTML = k.map(function (x) { return '<div class="kpi"><small>' + x[0] + '</small><b>' + x[1] + '</b>' + x[2] + '</div>'; }).join('');
    var ni = d.total_eventos === 0;
    aviso(ni ? 'Aún no hay visitas registradas. Las estadísticas empiezan a contar desde que se publicó el script de medición.' : '');

    serie(d.serie); $('serieSub').textContent = d.serie.por === 'hora' ? 'por hora' : 'por día';
    tablaPaginas(d.paginas);
    barras('paises', d.paises, function (x) { return pais(x.pais); }, 'visitantes', 'pais', function (x) { return x.pais; });
    barras('fuentes', d.fuentes, function (x) { return x.fuente; }, 'visitantes', 'fuente', function (x) { return x.fuente; });
    barras('clics', d.clics, function (x) { return x.obj; }, 'clics');
    barras('secciones', d.secciones, function (x) { return x.sec; }, 'clics');
    barras('eventos', d.eventos, function (x) { return x.obj; }, 'veces');
    barras('dispositivos', d.dispositivos, function (x) { return x.k; }, 'visitantes', 'disp', function (x) { return x.k; });
    ['navegadores', 'sistemas', 'anchos'].forEach(function (c) { barras(c, d[c], function (x) { return x.k; }, 'visitantes'); });
    barras('idiomas', d.idiomas, function (x) { return (x.k || '').toUpperCase(); }, 'visitantes');
    barras('campanas', d.campanas, function (x) { return [x.fuente, x.medio, x.campana].filter(Boolean).join(' / '); }, 'visitantes');
    barras('e404', d.salidas404, function (x) { return x.p; }, 'veces');
    calor(d.horas); velocidad(d.velocidad);
    chips();
    $('pie').textContent = 'Zona horaria: ' + d.rango.zona + ' · Datos de ' + d.rango.desde + ' a ' + d.rango.hasta + ' · ' + num(d.total_eventos) + ' eventos guardados' + (d.geo_mes ? ' · Base de países: ' + d.geo_mes : '');
  }

  function vacio(el) { el.innerHTML = '<p class="vacio">Sin datos en este periodo.</p>'; }

  function barras(id, filas, nombre, campo, filtro, valFiltro) {
    var el = $(id); if (!filas || !filas.length) return vacio(el);
    var max = Math.max.apply(null, filas.map(function (x) { return Number(x[campo]); })) || 1;
    el.innerHTML = filas.map(function (x, i) {
      var n = esc(nombre(x) || '(sin nombre)');
      return '<div class="f' + (filtro ? ' clic' : '') + '"' + (filtro ? ' data-f="' + filtro + '" data-i="' + i + '"' : '') + '><span class="n" title="' + n + '">' + n + '</span><span class="v">' + num(x[campo]) + '</span><span class="b"><i style="width:' + Math.max(2, 100 * x[campo] / max) + '%"></i></span></div>';
    }).join('');
    if (filtro) el.querySelectorAll('.clic').forEach(function (n) { n.onclick = function () { filtros[filtro] = valFiltro(filas[n.dataset.i]); cargar(); }; });
  }

  function tablaPaginas(f) {
    var el = $('paginas'); if (!f || !f.length) return vacio(el);
    el.innerHTML = '<table><thead><tr><th>Página</th><th>Vistas</th><th>Visitantes</th><th>Tiempo</th><th>Lectura</th><th>Clics</th></tr></thead><tbody>' + f.map(function (x, i) {
      return '<tr class="clic" data-i="' + i + '"><td title="' + esc(x.p) + '">' + esc(x.p) + '</td><td>' + num(x.vistas) + '</td><td>' + num(x.visitantes) + '</td><td>' + (x.tiempo_s != null ? dur(x.tiempo_s) : '—') + '</td><td>' + (x.scroll != null ? x.scroll + ' %' : '—') + '</td><td>' + num(x.clics) + '</td></tr>';
    }).join('') + '</tbody></table>';
    el.querySelectorAll('tr.clic').forEach(function (tr) { tr.onclick = function () { filtros.p = f[tr.dataset.i].p; cargar(); }; });
  }

  function serie(s) {
    var el = $('serie'), p = s.puntos; if (!p.length) return vacio(el);
    var W = 900, H = 240, L = 40, B = 26, T = 10, R = 8, n = p.length;
    var max = Math.max.apply(null, p.map(function (x) { return Math.max(+x.vistas, +x.visitantes); })) || 1;
    var paso = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / paso) * paso;
    var x = function (i) { return L + (n === 1 ? (W - L - R) / 2 : i * (W - L - R) / (n - 1)); }, y = function (v) { return T + (H - T - B) * (1 - v / top); };
    var path = function (c) { return p.map(function (q, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(+q[c]).toFixed(1); }).join(' '); };
    var g = '';
    for (var i = 0; i <= 4; i++) { var v = top * i / 4; g += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(v) + '" y2="' + y(v) + '" stroke="var(--bd)"/><text x="' + (L - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end" font-size="11" fill="var(--mut)">' + num(Math.round(v)) + '</text>'; }
    var cada = Math.ceil(n / 8), etq = '';
    p.forEach(function (q, i) { if (i % cada === 0) etq += '<text x="' + x(i) + '" y="' + (H - 8) + '" text-anchor="' + (i === 0 ? 'start' : i >= n - cada ? 'end' : 'middle') + '" font-size="11" fill="var(--mut)">' + esc(s.por === 'hora' ? q.k.slice(11) : q.k.slice(5)) + '</text>'; });
    var pts = p.map(function (q, i) { return '<circle cx="' + x(i) + '" cy="' + y(+q.visitantes) + '" r="3" fill="var(--ac)"><title>' + esc(q.k) + ': ' + q.visitantes + ' visitantes, ' + q.vistas + ' páginas vistas, ' + q.clics + ' clics</title></circle>'; }).join('');
    el.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Visitantes y páginas vistas en el tiempo">' + g + etq +
      '<path d="' + path('vistas') + '" fill="none" stroke="var(--ac2)" stroke-width="2.5" stroke-linejoin="round"/><path d="' + path('visitantes') + '" fill="none" stroke="var(--ac)" stroke-width="2.5" stroke-linejoin="round"/>' + pts + '</svg>';
  }

  function calor(h) {
    var el = $('horas'); if (!h || !h.length) return vacio(el);
    var m = {}, max = 1; h.forEach(function (x) { m[x.dia + '_' + x.hora] = +x.vistas; if (+x.vistas > max) max = +x.vistas; });
    var dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'], html = '<span></span>';
    for (var hr = 0; hr < 24; hr++) html += '<div class="e">' + (hr % 3 === 0 ? hr : '') + '</div>';
    [1, 2, 3, 4, 5, 6, 0].forEach(function (di) {
      html += '<div class="d">' + dias[di] + '</div>';
      for (var hr2 = 0; hr2 < 24; hr2++) { var v = m[di + '_' + hr2] || 0; html += '<div title="' + dias[di] + ' ' + hr2 + ':00 · ' + v + ' vistas" style="background:' + (v ? 'color-mix(in srgb,var(--ac) ' + Math.round(15 + 85 * v / max) + '%,transparent)' : 'var(--bar)') + ';' + (v ? '' : 'opacity:.35') + '"></div>'; }
    });
    el.innerHTML = html;
  }

  function velocidad(v) {
    var el = $('velocidad'); if (!v || !v.muestras) return vacio(el);
    var ms = function (x) { return x == null ? '—' : (x / 1000).toFixed(2).replace('.', ',') + ' s'; };
    var buen = function (x) { return x == null ? '' : x <= 2500 ? ' up' : x <= 4000 ? '' : ' dn'; };
    el.innerHTML = '<div class="g"><span>Carga del contenido principal (mediana)</span><b class="' + buen(v.lcp_mediana) + '">' + ms(v.lcp_mediana) + '</b></div>' +
      '<div class="g"><span>Percentil 75 (el objetivo de Google es ≤ 2,5 s)</span><b class="' + buen(v.lcp_p75) + '">' + ms(v.lcp_p75) + '</b></div>' +
      '<div class="g"><span>Estabilidad visual (mediana)</span><b>' + (v.cls_mediana == null ? '—' : String(v.cls_mediana).replace('.', ',')) + '</b></div>' +
      (v.por_dispositivo || []).map(function (x) { return '<div class="g"><span>' + esc(x.disp || 'otro') + ' (' + x.muestras + ' medidas)</span><b>' + ms(x.lcp_mediana) + '</b></div>'; }).join('');
  }

  function chips() {
    var el = $('filtros'), n = Object.keys(filtros).filter(function (k) { return filtros[k]; });
    el.innerHTML = n.map(function (k) { return '<button class="chip" data-k="' + k + '" title="Quitar filtro">' + esc({ p: 'Página', pais: 'País', disp: 'Dispositivo', fuente: 'Origen' }[k]) + ': ' + esc(k === 'pais' ? pais(filtros[k]) : filtros[k]) + ' ✕</button>'; }).join('');
    el.querySelectorAll('.chip').forEach(function (b) { b.onclick = function () { filtros[b.dataset.k] = ''; cargar(); }; });
  }

  $('rangos').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return; rango = b.dataset.r;
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
    fijaRango(rango); cargar();
  });
  ['desde', 'hasta'].forEach(function (id) { $(id).addEventListener('change', function () { $('rangos').querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); }); cargar(); }); });
  $('actualizar').onclick = cargar;
  fijaRango('7'); cargar();
  setInterval(function () { if (document.visibilityState === 'visible') cargar(); }, 60000);
})();
