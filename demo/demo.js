/*!
 * Digital Lab · /demo · reel y formulario «Pide tu demo» (tarea 4: diseño · tarea 6: funcionalidad)
 * - Reel: póster con botón propio y capítulos que saltan al segundo.
 * - Formulario: valida cada campo, envía JSON a demo.php (tarea 5) y enseña los estados del diseño:
 *   enviando · éxito · error por campo · límite · fallo.
 * - UTM: se leen de location.search a los 4 campos ocultos (solo [A-Za-z0-9_-], hasta 60). Sin cookies ni
 *   localStorage/sessionStorage.
 * Estados forzados para revisión y capturas (solo en localhost / 127.0.0.1):
 *   ?estado=vacio | error | enviando | exito | fallo | limite
 */
(function () {
  'use strict';
  const q = (s, r = document) => r.querySelector(s);
  const qa = (s, r = document) => [...r.querySelectorAll(s)];

  /* ---------- Reel ---------- */
  const reel = q('[data-reel]');
  if (reel) {
    const video = q('video', reel);
    const play = q('[data-reel-play]', reel);
    const caps = qa('[data-cap]');
    const arrancar = (t) => {
      if (typeof t === 'number') video.currentTime = t;
      reel.classList.add('is-playing');
      video.setAttribute('controls', '');
      const p = video.play(); if (p && p.catch) p.catch(() => {});
    };
    if (play) play.addEventListener('click', () => { arrancar(); video.focus(); });
    caps.forEach((b) => b.addEventListener('click', () => arrancar(Number(b.dataset.cap))));
    video.addEventListener('timeupdate', () => {
      const t = video.currentTime;
      let activo = -1;
      caps.forEach((b, i) => { if (t >= Number(b.dataset.cap) - 0.05) activo = i; });
      caps.forEach((b, i) => { b.classList.toggle('is-on', i === activo); b.setAttribute('aria-current', i === activo ? 'true' : 'false'); });
    });
  }

  /* ---------- Formulario ---------- */
  const form = q('[data-demo-form]');
  if (!form) return;
  const panel = q('[data-demo-panel]');
  const resumen = q('[data-form-resumen]', form);
  const exito = q('[data-form-exito]');
  const fallo = q('[data-form-fallo]', form);
  const falloMsg = q('[data-fallo-msg]', form);
  const reintentar = q('[data-reintentar]', form);
  const boton = q('[data-enviar]', form);
  const botonTxt = q('[data-enviar-txt]', form);
  const el = form.elements;

  const ENDPOINT = 'demo.php';
  const ESPERA_MAX = 15000;   // ms sin respuesta → fallo
  const T_MIN = 3000;         // demo.php descarta sin guardar lo que llega antes de 3 s (robots)
  // Textos literales de docs/textos/demo.md
  const MSG = { nombre: 'Escribe tu nombre', email: 'Revisa el correo: parece incompleto', empresa: 'Escribe el nombre de tu empresa', usa_claude_code: 'Elige una opción', consent_respuesta: 'Marca esta casilla para poder responderte' };
  const FALLO = { red: 'No pudimos enviarlo. Tus datos siguen aquí; inténtalo otra vez o escríbenos por WhatsApp.', limite: 'Demasiados intentos; prueba en una hora.' };
  const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];
  const UTM_OK = /^[A-Za-z0-9_-]{1,60}$/;
  const EMAIL_OK = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  // Tiempo desde que se abrió el formulario (ms). Se reinicia si demo.php responde «tiempo».
  let inicio = performance.now();
  let enviando = false;

  // UTM de la URL a los campos ocultos (lo que no cumple el patrón se queda vacío). Nada se guarda en el navegador.
  const params = new URLSearchParams(location.search);
  UTM.forEach((n) => { const v = params.get(n); if (el[n]) el[n].value = v !== null && UTM_OK.test(v) ? v : ''; });

  // Botón que trajo al visitante hasta el formulario: nav · hero · precio (final por defecto)
  qa('a[data-cta]').forEach((a) => a.addEventListener('click', () => { if (el.cta_origen) el.cta_origen.value = a.dataset.cta; }));

  const campo = (name) => { const c = el[name]; return c && (c.length && !c.tagName ? c[0] : c); };
  const caja = (name) => { const c = campo(name); return c && c.closest('[data-campo]'); };
  const linea = (name) => (el[name] ? String(el[name].value).trim() : '');
  const radio = (name) => { const r = qa('input[type="radio"]', form).find((i) => i.name === name && i.checked); return r ? r.value : ''; };

  function valido(name) {
    if (name === 'usa_claude_code') return radio(name) !== '';
    if (name === 'consent_respuesta') return el.consent_respuesta.checked;
    if (name === 'email') return EMAIL_OK.test(linea('email'));
    return linea(name).length > 0;
  }
  function marcar(name, mal) {
    const c = caja(name); if (!c) return;
    c.classList.toggle('is-invalid', mal);
    const err = q('.field__error', c);
    if (err) err.textContent = mal ? MSG[name] : '';
    qa('input, textarea, select', c).forEach((i) => { if (mal) i.setAttribute('aria-invalid', 'true'); else i.removeAttribute('aria-invalid'); });
  }
  // Pinta los errores (del navegador o de demo.php) y lleva el foco al primero
  function mostrarErrores(malos, enfocar) {
    Object.keys(MSG).forEach((n) => marcar(n, malos.includes(n)));
    if (resumen) resumen.textContent = malos.map((n) => MSG[n]).join('. ');
    form.classList.toggle('is-error', malos.length > 0);
    if (malos.length && enfocar) { const f = campo(malos[0]); if (f) f.focus(); }
  }
  const validar = () => Object.keys(MSG).filter((n) => !valido(n));

  // tipoFallo: 'red' (con «Reintentar») · 'version' (página desactualizada: reintentar daría lo mismo)
  function estado(e, tipoFallo) {
    if (panel) panel.dataset.estado = e;
    const env = e === 'enviando';
    form.classList.toggle('is-sending', env);
    if (env) boton.setAttribute('aria-disabled', 'true'); else boton.removeAttribute('aria-disabled');
    botonTxt.textContent = env ? 'Enviando…' : 'Pedir demo';
    const f = e === 'fallo' ? (tipoFallo || 'red') : e === 'limite' ? 'limite' : '';
    fallo.hidden = !f;
    if (f) falloMsg.textContent = f === 'limite' ? FALLO.limite : FALLO.red;
    reintentar.hidden = f !== 'red';
    if (e === 'exito') { form.hidden = true; exito.hidden = false; exito.focus(); if (panel) panel.classList.add('is-live'); }
  }

  function datos() {
    const t = Math.max(0, Math.round(performance.now() - inicio));
    if (el.t) el.t.value = String(t);
    const d = {
      nombre: linea('nombre'),
      email: linea('email'),
      empresa: linea('empresa'),
      pais: el.pais ? el.pais.value : '',
      tamano_equipo: el.tamano_equipo ? el.tamano_equipo.value : '',
      usa_claude_code: radio('usa_claude_code'),
      necesidad: el.necesidad ? el.necesidad.value.trim() : '',
      consent_respuesta: el.consent_respuesta.checked === true,
      consent_novedades: !!(el.consent_novedades && el.consent_novedades.checked),
      consent_version: el.consent_version ? el.consent_version.value : '',
      cta_origen: el.cta_origen ? el.cta_origen.value : 'final',
      web: el.web ? el.web.value : '',
      t,
    };
    UTM.forEach((n) => { d[n] = el[n] ? el[n].value : ''; });
    return d;
  }

  const pausa = (ms) => new Promise((r) => setTimeout(r, ms));

  async function enviar() {
    if (enviando) return;
    const malos = validar();
    mostrarErrores(malos, true);
    if (malos.length) return;
    enviando = true;
    estado('enviando');
    // Una persona con autorrelleno puede enviar antes de 3 s: esperamos para que su solicitud no se descarte como robot
    const falta = T_MIN + 100 - (performance.now() - inicio);
    if (falta > 0) await pausa(falta);

    let codigo = 0, cuerpo = null;
    const ctrl = 'AbortController' in window ? new AbortController() : null;
    const reloj = ctrl ? setTimeout(() => ctrl.abort(), ESPERA_MAX) : 0;
    try {
      const r = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(datos()),
        cache: 'no-store',
        credentials: 'same-origin',
        signal: ctrl ? ctrl.signal : undefined,
      });
      codigo = r.status;
      try { cuerpo = await r.json(); } catch (_) { cuerpo = null; }
    } catch (_) {
      codigo = 0; // sin red, tiempo agotado o respuesta cortada
    } finally {
      clearTimeout(reloj);
      enviando = false;
    }
    resultado(codigo, cuerpo);
  }

  // Códigos de demo.php (lista en su cabecera) → estados del diseño
  function resultado(codigo, cuerpo) {
    const error = cuerpo && typeof cuerpo.error === 'string' ? cuerpo.error : '';
    if (codigo === 200 && cuerpo && cuerpo.ok === true) {
      estado('exito');
      if (typeof window.dlForm === 'function') window.dlForm('demo'); // analítica propia: solo el nombre del formulario
      return;
    }
    if (codigo === 429 || error === 'limite') { estado('limite'); return; }
    if (codigo === 400 && error === 'campos' && cuerpo.campos && typeof cuerpo.campos === 'object') {
      const malos = Object.keys(MSG).filter((n) => Object.prototype.hasOwnProperty.call(cuerpo.campos, n));
      if (malos.length) { estado('vacio'); mostrarErrores(malos, true); return; }
      // Error en un campo sin mensaje propio (p. ej. «necesidad»): se trata como fallo
    }
    if (codigo === 400 && error === 'tiempo') inicio = performance.now();
    if (codigo === 409 || error === 'version') { estado('fallo', 'version'); return; }
    estado('fallo', 'red');
  }

  form.addEventListener('input', (e) => { if (form.classList.contains('is-error') && e.target.name in MSG) marcar(e.target.name, !valido(e.target.name)); });
  form.addEventListener('change', (e) => { if (form.classList.contains('is-error') && e.target.name in MSG) marcar(e.target.name, !valido(e.target.name)); });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (boton.getAttribute('aria-disabled') === 'true') return;
    enviar();
  });
  if (reintentar) reintentar.addEventListener('click', () => { boton.focus(); enviar(); });

  /* ---------- Estados forzados para revisión (solo en local) ---------- */
  const local = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
  const forzado = local ? params.get('estado') : null;
  const rellenar = () => {
    el.nombre.value = 'Laura Gómez';
    el.email.value = 'laura@empresa.com';
    el.empresa.value = 'Estudio Norte';
    el.pais.value = 'España';
    el.usa_claude_code[0].checked = true;
    el.consent_respuesta.checked = true;
  };
  if (forzado === 'error') {
    el.nombre.value = '';
    el.email.value = 'laura@';
    mostrarErrores(validar(), false);
  } else if (['enviando', 'fallo', 'limite', 'exito'].includes(forzado)) {
    rellenar();
    estado(forzado);
  }
})();
