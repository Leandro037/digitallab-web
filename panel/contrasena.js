/* Digital Lab · panel · «Cambiar contraseña» (tarea 54).
 * Envía {actual, nueva, repite, csrf} a contrasena-cambiar.php. Las contraseñas no se guardan, no se registran
 * ni se muestran fuera de su campo (solo si el usuario pulsa «Mostrar»). Sin innerHTML: todo con textContent.
 * El cambio es una acción del usuario: lleva «X-DL-Accion: 1» (renueva la inactividad, R3). */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var form = $('form'), csrf = $('csrf'), btn = $('enviar'), hecho = $('hecho'), error = $('error');
  var CAMPOS = ['actual', 'nueva', 'repite'];
  var MIN = 14, MAX_BYTES = 1024;
  var TEXTO_BTN = btn.textContent;
  var MENSAJES = {
    actual_vacia: 'Escribe tu contraseña actual.',
    actual: 'La contraseña actual no es correcta.',
    bloqueado: 'Demasiados intentos fallidos: el acceso queda bloqueado un tiempo (15 minutos la primera vez). Vuelve a probar más tarde.',
    nueva_vacia: 'Escribe la contraseña nueva.',
    corta: 'La contraseña nueva es muy corta: necesita 14 caracteres o más.',
    larga: 'La contraseña nueva es demasiado larga (máximo 1024 bytes).',
    no_coinciden: 'Las dos contraseñas nuevas no coinciden.',
    igual: 'La contraseña nueva tiene que ser distinta de la actual.',
    csrf: 'La página ha caducado. Recárgala y vuelve a probar.',
    sin_servicio: 'Ahora no se puede cambiar la contraseña. Inténtalo de nuevo en unos minutos.',
    error: 'No se pudo cambiar la contraseña. Inténtalo de nuevo.'
  };

  /* Mostrar / ocultar (botón con aria-pressed; la etiqueta accesible dice qué campo) */
  Array.prototype.forEach.call(document.querySelectorAll('.ver'), function (b) {
    b.addEventListener('click', function () {
      var i = $(b.getAttribute('aria-controls'));
      var mostrar = i.type === 'password';
      i.type = mostrar ? 'text' : 'password';
      b.setAttribute('aria-pressed', String(mostrar));
      b.textContent = mostrar ? 'Ocultar' : 'Mostrar';
      b.setAttribute('aria-label', b.getAttribute('aria-label').replace(mostrar ? /^Mostrar/ : /^Ocultar/, mostrar ? 'Ocultar' : 'Mostrar'));
    });
  });
  function ocultarTodas() {
    Array.prototype.forEach.call(document.querySelectorAll('.ver'), function (b) {
      if (b.getAttribute('aria-pressed') === 'true') b.click();
    });
  }

  function marcar(campo, codigo) {
    var i = $(campo), e = $('e-' + campo);
    e.textContent = codigo ? (MENSAJES[codigo] || MENSAJES.error) : '';
    e.hidden = !codigo;
    if (codigo) i.setAttribute('aria-invalid', 'true'); else i.removeAttribute('aria-invalid');
  }
  function general(caja, texto) { caja.textContent = texto || ''; caja.hidden = !texto; }
  function limpiar() { CAMPOS.forEach(function (c) { marcar(c, ''); }); general(error, ''); general(hecho, ''); }

  function caracteres(s) { return Array.from(s).length; }
  function bytes(s) { return new TextEncoder().encode(s).length; }

  // Mismas reglas que el servidor (el servidor decide; esto solo ahorra un viaje)
  function validar(v) {
    var e = {};
    if (!v.actual) e.actual = 'actual_vacia';
    if (!v.nueva) e.nueva = 'nueva_vacia';
    else if (caracteres(v.nueva) < MIN) e.nueva = 'corta';
    else if (bytes(v.nueva) > MAX_BYTES) e.nueva = 'larga';
    else if (v.actual && v.nueva === v.actual) e.nueva = 'igual';
    if (!e.nueva && v.nueva !== v.repite) e.repite = 'no_coinciden';
    return e;
  }

  // Al corregir un campo marcado, se quita su error
  $('nueva').addEventListener('input', function () { if ($('nueva').getAttribute('aria-invalid')) marcar('nueva', ''); });
  $('repite').addEventListener('input', function () { if ($('repite').getAttribute('aria-invalid')) marcar('repite', ''); });
  $('actual').addEventListener('input', function () { if ($('actual').getAttribute('aria-invalid')) marcar('actual', ''); });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    limpiar();
    var v = { actual: $('actual').value, nueva: $('nueva').value, repite: $('repite').value };
    var errores = validar(v);
    var primero = CAMPOS.filter(function (c) { return errores[c]; });
    if (primero.length) {
      primero.forEach(function (c) { marcar(c, errores[c]); });
      $(primero[0]).focus();
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Cambiando…';
    form.setAttribute('aria-busy', 'true');
    fetch('contrasena-cambiar.php', {
      method: 'POST',
      credentials: 'same-origin',
      // Con Referrer-Policy: no-referrer, sin esto el POST podría salir con «Origin: null» y el servidor lo rechaza (R6)
      referrerPolicy: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-DL-Accion': '1' },
      body: JSON.stringify({ actual: v.actual, nueva: v.nueva, repite: v.repite, csrf: csrf.value })
    }).then(function (r) {
      if (r.status === 401) { location.replace('/login/'); throw new Error('sin_sesion'); }
      return r.json().catch(function () { return null; }).then(function (d) { return { st: r.status, d: d }; });
    }).then(function (x) {
      var d = x.d;
      if (!d) { general(error, MENSAJES.error); return; }
      if (d.ok) {
        v = null;
        form.reset();
        ocultarTodas();
        if (d.sesion === false) { location.replace('/login/'); return; }
        if (typeof d.csrf === 'string' && /^[a-f0-9]{64}$/.test(d.csrf)) csrf.value = d.csrf;
        var n = Number(d.cerradas) || 0;
        general(hecho, 'Contraseña cambiada. ' + (n === 0 ? 'No había otras sesiones abiertas.' : n === 1 ? 'Se cerró 1 sesión más.' : 'Se cerraron ' + n + ' sesiones más.')
          + (d.aviso ? ' Te llegará un aviso por correo.' : ''));
        hecho.focus();
        return;
      }
      var campo = CAMPOS.indexOf(d.campo) >= 0 ? d.campo : '';
      if (campo) { marcar(campo, d.error); $(campo).focus(); if (campo === 'actual') $('actual').select(); }
      else general(error, MENSAJES[d.error] || MENSAJES.error);
    }).catch(function (e) {
      if (e.message !== 'sin_sesion') general(error, MENSAJES.error);
    }).then(function () {
      btn.disabled = false;
      btn.textContent = TEXTO_BTN;
      form.removeAttribute('aria-busy');
    });
  });
})();
