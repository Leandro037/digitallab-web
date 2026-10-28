/* Digital Lab · panel · sección Leads (tarea 50). Datos de leads-api.php (solo con sesión).
 * R7: todo dato del lead es no confiable y se pinta con textContent; este archivo no usa innerHTML,
 * insertAdjacentHTML ni document.write. Único enlace con datos: mailto: con correo validado.
 * Fechas y horas en UTC (así se guarda «recibido»). Caducidad = recibido + 30 días naturales. */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  var CC = { si: 'Sí', no: 'No', nose: 'No sé' };
  var CTA = { nav: 'Menú', hero: 'Inicio', precio: 'Precio', final: 'Final de la página' };
  var RE_ID = /^L-\d{8}-\d{6}-[a-f0-9]{16}$/;
  var RE_CORREO = /^[A-Za-z0-9._%+\-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*\.[A-Za-z]{2,63}$/;
  var DIA = 86400000, PLAZO = 30;
  var escritorio = window.matchMedia('(min-width: 901px)');

  var leads = [], ahora = new Date(), sel = null, fichaLead = null, cache = {}, abierta = false, pedido = 0;

  /* ---------- utilidades ---------- */
  function el(tag, cls, texto) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (texto != null) n.textContent = texto;
    return n;
  }
  function fecha(iso) { var d = new Date(iso); return isNaN(d) ? null : d; }
  function dosC(n) { return String(n).padStart(2, '0'); }
  function diaMes(d) { return d ? d.getUTCDate() + ' ' + MESES[d.getUTCMonth()] : '—'; }
  function hora(d) { return d ? dosC(d.getUTCHours()) + ':' + dosC(d.getUTCMinutes()) : ''; }
  function fechaCorta(d) { return d ? diaMes(d) + ' ' + d.getUTCFullYear() : '—'; }
  function fechaLarga(d) { return d ? fechaCorta(d) + ' · ' + hora(d) + ' UTC' : '—'; }
  function diaUTC(d) { return Math.floor(d.getTime() / DIA); }
  function pasados(l) { var r = fecha(l.recibido); return r ? Math.max(0, Math.min(PLAZO, diaUTC(ahora) - diaUTC(r))) : 0; }
  function quedan(l) { return PLAZO - pasados(l); }
  function normal(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function origen(l) { return l.utm_campaign || l.utm_source || l.utm_content ? (l.utm_campaign || l.utm_source || l.utm_content) : 'directo'; }
  function quedanTexto(n) { return n <= 0 ? 'Caduca hoy' : n === 1 ? 'Queda 1 día' : 'Quedan ' + n + ' días'; }
  function dentroDe(n) { return n <= 0 ? 'hoy' : n === 1 ? 'mañana' : 'dentro de ' + n + ' días'; }

  function aviso(t) { var a = $('error'); a.textContent = t || ''; a.hidden = !t; }

  function api(url, opciones) {
    // referrerPolicy same-origin: con no-referrer (R5) un POST podría salir con «Origin: null» y el servidor lo rechaza (R6)
    return fetch(url, Object.assign({ credentials: 'same-origin', referrerPolicy: 'same-origin', headers: { Accept: 'application/json' } }, opciones || {})).then(function (r) {
      if (r.status === 401) { location.replace('/login/'); throw new Error('sin_sesion'); }
      return r.json().then(function (d) { if (!r.ok || !d || !d.ok) throw new Error((d && d.error) || 'error'); return d; });
    });
  }

  /* ---------- carga ---------- */
  function cargar() {
    api('leads-api.php').then(function (d) {
      aviso('');
      ahora = fecha(d.ahora) || new Date();
      leads = (d.leads || []).filter(function (l) { return RE_ID.test(l.id); });
      pintaTodo();
    }).catch(function (e) {
      if (e.message !== 'sin_sesion') aviso('No se pudieron cargar los leads. Reintenta en unos segundos.');
    });
  }

  function pintaTodo() {
    var hay = leads.length > 0;
    $('vacio').hidden = hay;
    $('filtros').hidden = !hay;
    $('cuenta').hidden = !hay;
    $('vista').hidden = !hay;
    $('csv').disabled = !hay;
    kpis();
    if (hay) { opcionesFiltros(); aplicar(); }
  }

  function kpis() {
    var nuevos = leads.filter(function (l) { return !l.atendido; });
    var at = leads.length - nuevos.length;
    $('kTotal').textContent = leads.length;
    var primero = leads.length ? fecha(leads[leads.length - 1].recibido) : null;
    $('kTotalSub').textContent = primero ? 'desde el ' + diaMes(primero) : ' ';
    $('kNuevos').textContent = nuevos.length;
    $('kAtendidos').textContent = at;
    $('kAtendidosSub').textContent = leads.length ? 'de ' + leads.length : ' ';
    var urgente = null;
    nuevos.forEach(function (l) { if (!urgente || l.caduca < urgente.caduca) urgente = l; });
    $('kCad').textContent = urgente ? diaMes(fecha(urgente.caduca)) : '—';
    $('kCadSub').textContent = urgente ? dentroDe(quedan(urgente)) : ' ';
  }

  /* ---------- filtros ---------- */
  function rellenaSelect(s, valores) {
    var antes = s.value;
    while (s.options.length > 1) s.remove(1);
    valores.forEach(function (v) { var o = el('option', null, v[1]); o.value = v[0]; s.add(o); });
    s.value = valores.some(function (v) { return v[0] === antes; }) ? antes : '';
  }

  function opcionesFiltros() {
    var paises = {}, sinPais = false, origenes = {}, directo = false;
    leads.forEach(function (l) {
      if (l.pais) paises[l.pais] = 1; else sinPais = true;
      var o = origen(l); if (o === 'directo') directo = true; else origenes[o] = 1;
    });
    var orden = function (a, b) { return a.localeCompare(b, 'es'); };
    rellenaSelect($('fPaisSel'), Object.keys(paises).sort(orden).map(function (p) { return [p, p]; }).concat(sinPais ? [['-', 'Sin país']] : []));
    rellenaSelect($('fOrigenSel'), (directo ? [['directo', 'Directo']] : []).concat(Object.keys(origenes).sort(orden).map(function (o) { return [o, o]; })));
  }

  function filtrar() {
    var q = normal($('q').value.trim()), est = $('fEstadoSel').value, pais = $('fPaisSel').value, ori = $('fOrigenSel').value;
    return leads.filter(function (l) {
      if (est === 'nuevo' && l.atendido) return false;
      if (est === 'atendido' && !l.atendido) return false;
      if (pais && (pais === '-' ? l.pais !== '' : l.pais !== pais)) return false;
      if (ori && origen(l) !== ori) return false;
      return !q || normal(l.nombre + ' ' + l.empresa + ' ' + l.email).indexOf(q) !== -1;
    });
  }

  function hayFiltros() { return !!($('q').value.trim() || $('fEstadoSel').value || $('fPaisSel').value || $('fOrigenSel').value); }

  function aplicar() {
    var vis = filtrar(), ul = $('filas');
    ul.replaceChildren.apply(ul, vis.map(fila));
    var c = $('cuenta');
    c.replaceChildren('Mostrando ', el('b', null, String(vis.length)), ' de ', el('b', null, String(leads.length)), leads.length === 1 ? ' lead' : ' leads', ' · del más reciente al más antiguo');
    $('limpiar').disabled = !hayFiltros();
    var nada = vis.length === 0;
    $('sinRes').hidden = !nada;
    $('lista').hidden = nada;
    $('ficha').hidden = nada;
    if (nada) return;
    // En escritorio siempre hay una ficha abierta: la elegida (aunque ya no esté en la lista filtrada) o la primera
    if (!sel || !leads.some(function (l) { return l.id === sel; })) seleccionar(vis[0].id, false);
    else marcaFila();
  }

  function limpiar() {
    $('q').value = ''; $('fEstadoSel').value = ''; $('fPaisSel').value = ''; $('fOrigenSel').value = '';
    aplicar();
    $('q').focus();
  }

  /* ---------- lista ---------- */
  function insignia(l) { return el('span', 'est ' + (l.atendido ? 'atendido' : 'nuevo'), l.atendido ? 'Atendido' : 'Nuevo'); }
  function conB(antes, valor) { var s = el('span'); if (antes) s.append(antes); s.append(el('b', null, valor)); return s; }

  function fila(l) {
    var d = fecha(l.recibido), li = el('li'), a = el('a', 'fila');
    a.href = '#ficha';
    a.dataset.id = l.id;
    var f = el('span', 'f-fecha'); f.append(el('b', null, diaMes(d)), hora(d));
    var p = el('span', 'f-persona'); p.append(el('strong', null, l.nombre || '(sin nombre)'), el('span', null, l.empresa));
    var o = origen(l), sOri = el('span', 'f-origen' + (o === 'directo' ? ' directo' : ''));
    sOri.append(el('b', null, o));
    if (o !== 'directo' && l.utm_content && l.utm_content !== o) sOri.append(el('span', null, l.utm_content));
    var est = el('span', 'f-estado'); est.append(insignia(l));
    var datos = el('span', 'f-datos');
    datos.append(conB('', l.pais || 'Sin país'), conB('Equipo ', l.tamano_equipo || '—'), conB('Claude Code ', CC[l.usa_claude_code] || '—'));
    a.append(f, p,
      el('span', 'f-dato f-pais' + (l.pais ? '' : ' nada'), l.pais || '—'),
      el('span', 'f-dato m f-eq', l.tamano_equipo || '—'),
      el('span', 'f-dato f-cc', CC[l.usa_claude_code] || '—'),
      sOri, est, datos);
    if (l.id === sel) a.setAttribute('aria-current', 'true');
    li.append(a);
    return li;
  }

  function marcaFila() {
    $('filas').querySelectorAll('a.fila').forEach(function (a) {
      if (a.dataset.id === sel) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
    });
  }

  /* ---------- ficha ---------- */
  function dato(id, v) { var n = $(id); n.textContent = v || '—'; n.classList.toggle('nada', !v); }

  function estadoFicha(l) {
    var e = $('fEstado'), b = $('fAccion'), at = !!l.atendido;
    e.className = 'est ' + (at ? 'atendido' : 'nuevo');
    e.textContent = at ? 'Atendido' : 'Nuevo';
    $('fAtendido').hidden = !at;
    $('fAtendido').textContent = at ? 'Atendido el ' + fechaLarga(fecha(l.atendido)) : '';
    b.className = at ? 'ghost' : 'prim';
    b.disabled = false;
    b.querySelector('svg').toggleAttribute('hidden', at);
    $('fAccionTxt').textContent = at ? 'Volver a nuevo' : 'Marcar como atendido';
  }

  // Cabecera con lo que ya trae la lista; el resto se vacía hasta que llega la ficha (nunca se ven datos de otro lead)
  function fichaProvisional(l) {
    $('fNombre').textContent = l.nombre || '(sin nombre)';
    $('fEmpresa').textContent = l.empresa;
    $('fId').textContent = l.id;
    estadoFicha(l);
    ['fEmail', 'fPais', 'fEq', 'fCc', 'fCta', 'fUtm_source', 'fUtm_medium', 'fUtm_campaign', 'fUtm_content', 'fNecesidad', 'fC1t', 'fC2t', 'fVersion', 'fConsFecha', 'fRecibido', 'fCaduca', 'fQuedan']
      .forEach(function (id) { $(id).textContent = ''; });
    $('fAccion').disabled = true;
  }

  function pintaFicha(l) {
    fichaLead = l;
    $('fNombre').textContent = l.nombre || '(sin nombre)';
    $('fEmpresa').textContent = l.empresa;
    $('fId').textContent = l.id;
    estadoFicha(l);

    var dd = $('fEmail');
    dd.replaceChildren();
    dd.classList.toggle('nada', !l.email);
    if (l.email && l.email_valido === true && RE_CORREO.test(l.email)) {
      var a = el('a', null, l.email);
      a.href = 'mailto:' + l.email;
      dd.append(a);
    } else {
      dd.textContent = l.email || '—';
    }
    dato('fPais', l.pais);
    dato('fEq', l.tamano_equipo);
    dato('fCc', CC[l.usa_claude_code]);
    dato('fCta', CTA[l.cta_origen]);

    var algunUtm = false;
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'].forEach(function (k) {
      dato('fU' + k.slice(1), l[k]);
      if (l[k]) algunUtm = true;
    });
    $('fUtmNota').hidden = algunUtm;

    var n = $('fNecesidad');
    n.textContent = l.necesidad || 'No escribió nada.';
    n.classList.toggle('nada', !l.necesidad);

    $('fC1').classList.toggle('si', l.consent_respuesta === true);
    $('fC1t').textContent = 'Obligatorio · ' + (l.consent_respuesta === true ? 'aceptado' : 'no aceptado');
    $('fC2').classList.toggle('si', l.consent_novedades === true);
    $('fC2t').textContent = 'Opcional · ' + (l.consent_novedades === true ? 'aceptado' : 'no aceptado');
    dato('fVersion', l.consent_version);
    dato('fConsFecha', l.consent_fecha ? fechaLarga(fecha(l.consent_fecha)) : '');

    var p = pasados(l);
    $('fRecibido').textContent = fechaLarga(fecha(l.recibido));
    $('fCaduca').textContent = fechaCorta(fecha(l.caduca));
    $('fBarI').style.width = (100 * p / PLAZO).toFixed(1) + '%';
    $('fBar').setAttribute('aria-label', 'Han pasado ' + p + ' de ' + PLAZO + ' días');
    $('fQuedan').textContent = quedanTexto(PLAZO - p);
  }

  function seleccionar(id, abrir) {
    if (!RE_ID.test(id)) return;
    var l = leads.filter(function (x) { return x.id === id; })[0];
    if (!l) return;
    sel = id;
    marcaFila();
    var n = ++pedido;
    if (cache[id]) pintaFicha(Object.assign({}, cache[id], { atendido: l.atendido }));
    else fichaProvisional(l);
    if (abrir) abrirFicha();
    if (cache[id]) return;
    api('leads-api.php?id=' + encodeURIComponent(id)).then(function (d) {
      cache[id] = d.lead;
      if (n === pedido) pintaFicha(Object.assign({}, d.lead, { atendido: l.atendido }));
    }).catch(function (e) {
      if (e.message === 'no_existe') { cargar(); return; }
      if (e.message !== 'sin_sesion') aviso('No se pudo abrir la ficha. Reintenta en unos segundos.');
    });
  }

  // Móvil: la ficha es una hoja a pantalla completa (clase .abierta). «Atrás» del navegador la cierra.
  function abrirFicha() {
    if (escritorio.matches) return;
    if (!abierta) history.pushState({ ficha: 1 }, '', '#lead');
    abierta = true;
    $('ficha').classList.add('abierta');
    $('fNombre').focus();
  }
  function cerrarFicha(desdeHistorial) {
    if (!abierta) return;
    abierta = false;
    $('ficha').classList.remove('abierta');
    if (!desdeHistorial && history.state && history.state.ficha) history.back();
    var a = $('filas').querySelector('a.fila[aria-current="true"]');
    if (a) a.focus();
  }

  /* ---------- atendido ---------- */
  function cambiarEstado() {
    var l = fichaLead, b = $('fAccion');
    if (!l || b.disabled) return;
    var quiere = !l.atendido;
    b.disabled = true;
    $('fAccionTxt').textContent = 'Guardando…';
    api('leads-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ accion: 'atendido', id: l.id, atendido: quiere, csrf: $('csrf').value })
    }).then(function (d) {
      aviso('');
      leads.forEach(function (x) { if (x.id === d.id) x.atendido = d.atendido; });
      if (cache[d.id]) cache[d.id].atendido = d.atendido;
      kpis();
      aplicar();
      if (fichaLead && fichaLead.id === d.id) { fichaLead.atendido = d.atendido; estadoFicha(fichaLead); }
      b.focus();
    }).catch(function (e) {
      if (e.message === 'sin_sesion') return;
      estadoFicha(l);
      aviso('No se pudo guardar el estado. Recarga la página y vuelve a intentarlo.');
    });
  }

  /* ---------- CSV ---------- */
  // Con Referrer-Policy: no-referrer (R5) el navegador manda «Origin: null» en un envío de formulario normal y el
  // servidor lo rechaza (R6). fetch sí manda el Origin real en una petición del mismo sitio: se descarga como blob.
  function descargarCsv(e) {
    e.preventDefault();
    var b = $('csv');
    if (b.disabled) return;
    b.disabled = true;
    fetch('leads-csv.php', { method: 'POST', body: new FormData($('csvForm')), credentials: 'same-origin', referrerPolicy: 'same-origin' }).then(function (r) {
      if (r.redirected || r.status === 401) { location.replace('/login/'); throw new Error('sin_sesion'); }
      if (!r.ok) throw new Error('error');
      return r.blob();
    }).then(function (blob) {
      var url = URL.createObjectURL(blob), a = el('a');
      a.href = url;
      a.download = 'leads-dl-dev.csv';
      document.body.append(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
      aviso('');
    }).catch(function (err) {
      if (err.message !== 'sin_sesion') aviso('No se pudo descargar el CSV. Recarga la página y vuelve a intentarlo.');
    }).then(function () { b.disabled = leads.length === 0; });
  }

  /* ---------- eventos ---------- */
  $('csvForm').addEventListener('submit', descargarCsv);
  $('filas').addEventListener('click', function (e) {
    var a = e.target.closest('a.fila');
    if (!a) return;
    e.preventDefault();
    seleccionar(a.dataset.id, !escritorio.matches);
  });
  $('filtros').addEventListener('submit', function (e) { e.preventDefault(); });
  $('q').addEventListener('input', aplicar);
  ['fEstadoSel', 'fPaisSel', 'fOrigenSel'].forEach(function (id) { $(id).addEventListener('change', aplicar); });
  $('limpiar').addEventListener('click', limpiar);
  $('limpiar2').addEventListener('click', limpiar);
  $('fAccion').addEventListener('click', cambiarEstado);
  $('volver').addEventListener('click', function (e) { e.preventDefault(); cerrarFicha(false); });
  window.addEventListener('popstate', function () { if (abierta) cerrarFicha(true); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && abierta) cerrarFicha(false); });
  escritorio.addEventListener('change', function () { if (escritorio.matches && abierta) cerrarFicha(false); });

  // Una URL con #ficha (la de la maqueta) abriría la hoja vacía por :target: se quita el ancla
  if (location.hash) { location.hash = ''; history.replaceState(null, '', location.pathname); }
  cargar();
})();
