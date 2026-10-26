/*!
 * Digital Lab · acceso al panel de administración
 * El usuario, la contraseña y la dirección del panel solo los conoce el servidor (entrar.php):
 * si los datos son correctos, devuelve la dirección y el navegador va al panel.
 */
(function () {
  'use strict';

  const form = document.querySelector('[data-login]');
  const btn = document.querySelector('[data-login-btn]');
  const msg = document.querySelector('[data-login-msg]');
  const ERRORS = {
    credenciales: 'El email o la contraseña no son correctos.',
    limite: 'Demasiados intentos. Espera unos minutos y vuelve a probar.',
    sin_servicio: 'El acceso no está disponible en este servidor.',
    error: 'No se pudo comprobar el acceso. Vuelve a intentarlo.'
  };
  const show = (code) => { msg.hidden = false; msg.textContent = ERRORS[code] || ERRORS.error; };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (form.elements.web.value) return;   // campo trampa relleno
    const email = form.elements.email.value.trim(), password = form.elements.password.value;
    if (!email || !password) { show('credenciales'); return; }
    btn.disabled = true;
    msg.hidden = true;
    try {
      const r = await fetch('entrar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password, web: '' })
      });
      let body = null;
      try { body = await r.json(); } catch (err) { /* respuesta no JSON: sin PHP */ }
      if (!body) { show(r.status === 404 || r.status === 405 ? 'sin_servicio' : 'error'); return; }
      if (!body.ok || !/^(\/panel\/|https:\/\/)/.test(body.url || '')) { show(body.error); return; }
      form.elements.password.value = '';
      location.assign(body.url);
    } catch (err) {
      show('error');
    } finally {
      btn.disabled = false;
    }
  });
})();
