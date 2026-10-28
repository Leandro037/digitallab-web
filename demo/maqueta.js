/*!
 * Digital Lab · /demo · comportamiento de MAQUETA (diseño, tarea 4)
 * - Reel: póster con botón propio y capítulos que saltan al segundo.
 * - Formulario «Pide tu demo»: valida en el navegador y enseña sus estados. NO ENVÍA NADA.
 *   El envío, el anti-spam, las UTM y el consentimiento los hace el 20 (tarea 6) y sustituye este archivo
 *   sin cambiar el marcado ni el CSS.
 * Estados para revisión y capturas: ?estado=vacio | error | enviando | exito | fallo | limite
 * (también se pueden forzar a mano con data-estado en [data-demo-panel]).
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
  qa('a[data-cta]').forEach((a) => a.addEventListener('click', () => { const o = form.elements.cta_origen; if (o) o.value = a.dataset.cta; }));

  const resumen = q('[data-form-resumen]', form);
  const exito = q('[data-form-exito]');
  const fallo = q('[data-form-fallo]', form);
  const falloMsg = q('[data-fallo-msg]', form);
  const reintentar = q('[data-reintentar]', form);
  const boton = q('[data-enviar]', form);
  const botonTxt = q('[data-enviar-txt]', form);
  const MSG = { nombre: 'Escribe tu nombre', email: 'Revisa el correo: parece incompleto', empresa: 'Escribe el nombre de tu empresa', usa_claude_code: 'Elige una opción', consent_respuesta: 'Marca esta casilla para poder responderte' };
  const FALLO = { red: 'No pudimos enviarlo. Tus datos siguen aquí; inténtalo otra vez o escríbenos por WhatsApp.', limite: 'Demasiados intentos; prueba en una hora.' };

  const campo = (name) => { const el = form.elements[name]; return el && (el.length && !el.tagName ? el[0] : el); };
  const caja = (name) => { const el = campo(name); return el && el.closest('[data-campo]'); };
  function valido(name) {
    const el = form.elements[name];
    if (name === 'usa_claude_code') return [...el].some((r) => r.checked);
    if (name === 'consent_respuesta') return el.checked;
    if (name === 'email') return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(el.value.trim());
    return el.value.trim().length > 0;
  }
  function marcar(name, mal) {
    const c = caja(name); if (!c) return;
    c.classList.toggle('is-invalid', mal);
    const err = q('.field__error', c);
    if (err) err.textContent = mal ? MSG[name] : '';
    qa('input, textarea, select', c).forEach((i) => { if (mal) i.setAttribute('aria-invalid', 'true'); else i.removeAttribute('aria-invalid'); });
  }
  function validar() {
    const malos = Object.keys(MSG).filter((n) => !valido(n));
    Object.keys(MSG).forEach((n) => marcar(n, malos.includes(n)));
    if (resumen) resumen.textContent = malos.map((n) => MSG[n]).join('. ');
    form.classList.toggle('is-error', malos.length > 0);
    return malos;
  }
  function estado(e) {
    if (panel) panel.dataset.estado = e;
    const enviando = e === 'enviando';
    form.classList.toggle('is-sending', enviando);
    if (enviando) boton.setAttribute('aria-disabled', 'true'); else boton.removeAttribute('aria-disabled');
    botonTxt.textContent = enviando ? 'Enviando…' : 'Pedir demo';
    const f = e === 'fallo' ? 'red' : e === 'limite' ? 'limite' : '';
    fallo.hidden = !f;
    if (f) falloMsg.textContent = FALLO[f];
    reintentar.hidden = f !== 'red';
    if (e === 'exito') { form.hidden = true; exito.hidden = false; exito.focus(); if (panel) panel.classList.add('is-live'); }
  }

  form.addEventListener('input', (e) => { if (form.classList.contains('is-error') && e.target.name in MSG) marcar(e.target.name, !valido(e.target.name)); });
  form.addEventListener('change', (e) => { if (form.classList.contains('is-error') && e.target.name in MSG) marcar(e.target.name, !valido(e.target.name)); });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (boton.getAttribute('aria-disabled') === 'true') return;
    const malos = validar();
    if (malos.length) { const f = campo(malos[0]); if (f) f.focus(); return; }
    // Maqueta: aquí el 20 hace el envío real. No se manda nada.
    estado('enviando');
  });
  if (reintentar) reintentar.addEventListener('click', () => estado('vacio'));

  /* ---------- Estados forzados para revisión ---------- */
  const forzado = new URLSearchParams(location.search).get('estado');
  const rellenar = () => {
    form.elements.nombre.value = 'Laura Gómez';
    form.elements.email.value = 'laura@empresa.com';
    form.elements.empresa.value = 'Estudio Norte';
    form.elements.pais.value = 'España';
    form.elements.usa_claude_code[0].checked = true;
    form.elements.consent_respuesta.checked = true;
  };
  if (forzado === 'error') {
    form.elements.nombre.value = '';
    form.elements.email.value = 'laura@';
    validar();
  } else if (['enviando', 'fallo', 'limite', 'exito'].includes(forzado)) {
    rellenar();
    estado(forzado);
  }
})();
