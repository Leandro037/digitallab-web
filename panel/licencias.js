/* Digital Lab · panel · Licencias. Habla con licencias-api.php (sesión del panel + token CSRF). Sin estilos ni scripts en línea (CSP). */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var datos = null, ultima = null, timer = null;

  function h(tag, attrs, hijos) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'class') e.className = attrs[k]; else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]); else e.setAttribute(k, attrs[k]); });
    [].concat(hijos == null ? [] : hijos).forEach(function (c) { e.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c); });
    return e;
  }
  function aviso(txt) { var e = $('error'); e.textContent = txt || ''; e.hidden = !txt; }

  // Acción del usuario: renueva la sesión (X-DL-Accion). El sondeo automático (GET sin la cabecera) no la renueva: la pestaña olvidada caduca a los 30 min.
  function pedir(accion, extra, auto) {
    var opt = { credentials: 'same-origin', referrerPolicy: 'same-origin', headers: { Accept: 'application/json' } };
    var url = 'licencias-api.php';
    if (auto) { /* GET: solo lectura */ }
    else {
      opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.headers['X-DL-Accion'] = '1';
      opt.body = JSON.stringify(Object.assign({ accion: accion, csrf: $('csrf').value }, extra || {}));
    }
    return fetch(url, opt).then(function (r) {
      if (r.status === 401) { location.href = '/login/'; return new Promise(function () {}); }
      return r.json().then(function (j) { j._code = r.status; return j; }, function () { return { ok: false, error: 'error', _code: r.status }; });
    });
  }
  var MSG = { csrf: 'La sesión del panel cambió. Recarga la página.', sin_servicio: 'El servidor de licencias no está disponible ahora mismo.', corta_primero: 'Corta la licencia antes de eliminarla.', correo_invalido: 'Ese correo no es válido.', no_existe: 'Esa licencia ya no existe.' };
  function falla(j) { aviso(MSG[j.error] || 'No se pudo completar la acción (' + (j.error || 'error') + ').'); }

  var fecha = function (t) { return t ? new Date(t * 1000).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; };
  var dia = function (t) { return t ? new Date(t * 1000).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'; };
  function hace(t) {
    if (!t) return 'nunca'; var s = Math.max(0, Math.floor(Date.now() / 1000) - t);
    if (s < 3600) return 'hace ' + Math.max(1, Math.floor(s / 60)) + ' min'; if (s < 86400) return 'hace ' + Math.floor(s / 3600) + ' h'; return 'hace ' + Math.floor(s / 86400) + ' d';
  }
  function uso(m) { if (!m) return '0 min'; var hh = Math.floor(m / 60), mm = m % 60; return (hh ? hh + ' h ' : '') + mm + ' min'; }
  function copiar(texto, boton) {
    var ok = function () { var t = boton.textContent; boton.textContent = 'Copiado'; setTimeout(function () { boton.textContent = t; }, 1400); };
    if (navigator.clipboard) navigator.clipboard.writeText(texto).then(ok, function () { window.prompt('Copia esto:', texto); }); else window.prompt('Copia esto:', texto);
  }
  var nombre = function (l) { return l.empresa || l.cliente || l.correo; };
  var estadoVista = function (l) { return l.estado === 'activa' && l.activada === null ? 'sin' : l.estado; };

  function pintarResumen() {
    var r = datos.resumen;
    $('kTotal').textContent = r.total; $('kInst').textContent = r.instaladas; $('kAct').textContent = r.activas; $('kCor').textContent = r.cortadas; $('kVis').textContent = r.vistas_7_dias;
    $('ult').textContent = 'Actualizado ' + new Date().toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  }
  function pintarTabla() {
    var q = $('q').value.trim().toLowerCase(), fe = $('fEstado').value, cuerpo = $('filas');
    var lista = datos.licencias.filter(function (l) {
      if (fe && estadoVista(l) !== fe) return false;
      return !q || [l.empresa, l.cliente, l.correo, l.clave, l.notas].join(' ').toLowerCase().indexOf(q) >= 0;
    });
    $('cuenta').textContent = lista.length === datos.licencias.length ? lista.length + (lista.length === 1 ? ' licencia' : ' licencias') : lista.length + ' de ' + datos.licencias.length + ' licencias';
    cuerpo.replaceChildren();
    if (!lista.length) { cuerpo.appendChild(h('tr', {}, h('td', { colspan: '9', class: 'vacio-t' }, datos.licencias.length ? 'Ninguna licencia coincide con la búsqueda.' : 'Todavía no hay licencias. Crea la primera arriba.'))); return; }
    lista.forEach(function (l) {
      var instalada = l.activada !== null, acc = [];
      acc.push(h('button', { class: 'ghost', type: 'button', onclick: function (e) { copiar(l.clave, e.currentTarget); } }, 'Copiar'));
      if (l.estado === 'cortada') acc.push(h('button', { class: 'ghost', type: 'button', onclick: function () { if (window.confirm('¿Reactivar la licencia de ' + nombre(l) + '?')) accion('reactivar', l.id); } }, 'Reactivar'));
      else acc.push(h('button', { class: 'ghost mal', type: 'button', onclick: function () { if (window.confirm('¿Cortar la licencia de ' + nombre(l) + '?\nDL Studio se bloqueará en su próxima comprobación.')) accion('cortar', l.id); } }, 'Cortar'));
      if (l.equipo) acc.push(h('button', { class: 'ghost', type: 'button', onclick: function () { if (window.confirm('¿Liberar el equipo de esta clave?\nPodrá activarse en otro PC; el actual tendrá que activarla de nuevo.')) accion('liberar', l.id); } }, 'Liberar equipo'));
      if (!instalada || l.estado === 'cortada') acc.push(h('button', { class: 'ghost mal', type: 'button', onclick: function () { if (window.confirm('¿Eliminar esta clave para siempre?')) accion('eliminar', l.id); } }, 'Eliminar'));
      var ev = estadoVista(l);
      cuerpo.appendChild(h('tr', {}, [
        h('td', {}, [h('strong', {}, nombre(l)), l.empresa && l.cliente && l.empresa !== l.cliente ? h('small', {}, l.cliente) : '', l.notas ? h('small', {}, l.notas) : '']),
        h('td', {}, l.correo), h('td', { class: 'clave-c' }, l.clave),
        h('td', {}, [h('span', { class: 'est ' + ev }, ev === 'sin' ? 'sin instalar' : ev), l.vence ? h('small', {}, 'vence ' + dia(l.vence)) : '']),
        h('td', {}, fecha(l.activada)), h('td', {}, [hace(l.ultimo_contacto), l.ultimo_contacto ? h('small', {}, fecha(l.ultimo_contacto)) : '']),
        h('td', {}, uso(l.uso_min)), h('td', {}, [l.version || '—', l.equipo ? h('small', {}, 'equipo ' + l.equipo) : '']),
        h('td', {}, h('div', { class: 'acc' }, acc))]));
    });
  }
  function pintarNueva() {
    var n = ultima; $('claveNueva').hidden = !n; if (!n) return;
    $('cnCorreo').textContent = n.correo; $('cnClave').textContent = n.clave;
    $('cnCopiarClave').onclick = function (e) { copiar(n.clave, e.currentTarget); };
    $('cnCopiarMsg').onclick = function (e) { copiar('Tu licencia de DL Studio\nCorreo: ' + n.correo + '\nClave: ' + n.clave + '\nAl abrir DL Studio por primera vez te pedirá el correo y la clave. La clave vale para un solo ordenador.', e.currentTarget); };
  }
  function pintar() { pintarResumen(); pintarTabla(); pintarNueva(); }

  function cargar(auto) {
    return pedir('listar', {}, auto).then(function (j) {
      if (j.ok) { datos = j; aviso(''); pintar(); } else if (!auto) falla(j);
    }, function () { if (!auto) aviso('No se pudo conectar con el servidor.'); });
  }
  function accion(a, id) {
    aviso('');
    pedir(a, { id: id }).then(function (j) { if (j.ok) cargar(false); else falla(j); }, function () { aviso('No se pudo conectar con el servidor.'); });
  }

  $('nueva').addEventListener('submit', function (e) {
    e.preventDefault();
    var correo = $('nCorreo').value.trim(); if (!correo) { $('nCorreo').focus(); return; }
    var b = $('nCrear'); b.disabled = true; aviso('');
    pedir('crear', { correo: correo, cliente: $('nCliente').value, notas: $('nNotas').value, dias: +$('nDias').value }).then(function (j) {
      b.disabled = false;
      if (!j.ok) { falla(j); return; }
      ultima = j.licencia; $('nCorreo').value = ''; $('nCliente').value = ''; $('nNotas').value = ''; $('nDias').value = '0'; cargar(false);
    }, function () { b.disabled = false; aviso('No se pudo conectar con el servidor.'); });
  });
  $('cnCerrar').addEventListener('click', function () { ultima = null; pintarNueva(); });
  $('filtros').addEventListener('submit', function (e) { e.preventDefault(); });
  $('q').addEventListener('input', function () { if (datos) pintarTabla(); });
  $('fEstado').addEventListener('change', function () { if (datos) pintarTabla(); });
  $('actualizar').addEventListener('click', function () { cargar(false); });
  timer = setInterval(function () { if (document.visibilityState === 'visible') cargar(true); }, 60000);
  cargar(false);
})();
