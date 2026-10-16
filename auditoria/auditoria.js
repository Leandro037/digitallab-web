/*!
 * Digital Lab · auditoría web con IA
 * 1. api/revisar.php revisa la web desde el servidor (HTTPS, cabeceras, SEO técnico, robots, sitemap).
 * 2. El navegador pide a Google PageSpeed la velocidad, el SEO, la accesibilidad y las buenas prácticas.
 * 3. api/informe.php junta los datos y la IA redacta el resumen.
 * 4. api/enviar.php manda por email el informe detallado si el visitante lo pide.
 * Con ?demo en la dirección se simula todo, para ver la animación sin servidor.
 */
(function () {
  'use strict';

  const q = (s, r = document) => r.querySelector(s);
  const qa = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const DEMO = new URLSearchParams(location.search).has('demo');
  const API = 'api/';
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const form = q('[data-audit-form]'), input = q('#au-url'), btn = q('[data-audit-btn]'), btnLabel = q('[data-btn-label]');
  const msg = q('[data-msg]'), agent = q('[data-agent]'), steps = q('[data-steps]'), timer = q('[data-timer]');
  const result = q('[data-result]'), mailForm = q('[data-mail-form]'), mailMsg = q('[data-mail-msg]');
  let auditId = null, busy = false;

  /* ---------- Hilos de datos (la misma animación del hero) ---------- */
  let flow = null;
  const canvas = q('.au-canvas');
  if (canvas && window.DigitalLabHero) {
    flow = DigitalLabHero.mount(canvas, { density: 0.8, activity: 0.7, seed: 23, beams: true });
  }

  function setState(state) {
    agent.dataset.state = state;
    if (flow && flow.setActivity) flow.setActivity(state === 'working' ? 3.4 : state === 'done' ? 1 : 0.7);
  }

  function setStep(name, status) {
    const li = q(`[data-step="${name}"]`, steps);
    if (!li) return;
    li.classList.remove('is-active', 'is-done', 'is-skip');
    if (status) li.classList.add('is-' + status);
  }

  function showMsg(el, text, ok = false) {
    el.hidden = !text;
    el.textContent = text || '';
    el.classList.toggle('is-ok', ok);
  }

  /* ---------- Utilidades ---------- */
  function normalizeUrl(raw) {
    let v = (raw || '').trim();
    if (!v) return null;
    if (!/^https?:\/\//i.test(v)) v = 'https://' + v;
    try {
      const u = new URL(v);
      if (!/^https?:$/.test(u.protocol) || !u.hostname.includes('.') || u.username || u.password) return null;
      return u.href;
    } catch (e) { return null; }
  }

  async function post(path, data, timeoutMs = 45000) {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), timeoutMs);
    try {
      const r = await fetch(API + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
        signal: ctl.signal
      });
      let body = null;
      try { body = await r.json(); } catch (e) { /* respuesta no JSON */ }
      if (!r.ok || !body || body.ok === false) {
        const err = new Error((body && body.error) || (r.status === 404 ? 'sin_servicio' : 'error'));
        err.status = r.status;
        throw err;
      }
      return body;
    } finally { clearTimeout(t); }
  }

  const ERRORS = {
    url_invalida: 'Esa dirección no parece válida. Prueba con algo como https://tuweb.com',
    no_publica: 'Solo podemos auditar webs públicas en internet.',
    inaccesible: 'No pudimos acceder a esa web. Revisa que la dirección sea correcta y que esté en línea.',
    limite: 'Has hecho varias auditorías seguidas. Espera un rato y vuelve a intentarlo.',
    sin_servicio: 'El servicio de auditoría no está disponible en este servidor. Pruébalo en dl-dev.com/auditoria.',
    email_invalido: 'Revisa el email: parece que no es válido.',
    sin_consentimiento: 'Para enviarte el informe necesitamos que aceptes la política de privacidad.',
    expirada: 'Esta auditoría ya caducó. Vuelve a analizar la web para pedir el informe.',
    error: 'Algo falló en la auditoría. Vuelve a intentarlo en unos minutos.'
  };
  const errText = (e) => ERRORS[e && e.message] || (e && e.name === 'AbortError' ? 'La auditoría tardó demasiado. Vuelve a intentarlo.' : ERRORS.error);

  /* ---------- Google PageSpeed (desde el navegador) ---------- */
  async function pageSpeed(url, key) {
    if (!key) return null;
    const params = new URLSearchParams({ url, strategy: 'mobile', locale: 'es', key });
    ['performance', 'seo', 'accessibility', 'best-practices'].forEach((c) => params.append('category', c));
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), 75000);
    try {
      const r = await fetch('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' + params, { signal: ctl.signal });
      if (!r.ok) return null;
      return compactPsi(await r.json());
    } catch (e) { return null; } finally { clearTimeout(t); }
  }

  // Solo lo necesario para el informe: puntuaciones, métricas y los fallos principales
  function compactPsi(data) {
    const lh = data && data.lighthouseResult;
    if (!lh || !lh.categories) return null;
    const cats = { performance: 'rendimiento', seo: 'seo', accessibility: 'accesibilidad', 'best-practices': 'buenas_practicas' };
    const scores = {}, issues = {};
    for (const [id, name] of Object.entries(cats)) {
      const c = lh.categories[id];
      if (!c) continue;
      scores[name] = Math.round((c.score || 0) * 100);
      issues[name] = (c.auditRefs || [])
        .map((ref) => lh.audits[ref.id])
        .filter((a) => a && a.score !== null && a.score < 0.9 && !['manual', 'informative', 'notApplicable'].includes(a.scoreDisplayMode))
        .slice(0, 6)
        .map((a) => String(a.title || '').replace(/`/g, '').slice(0, 140));
    }
    const m = (id) => (lh.audits[id] && lh.audits[id].displayValue) || '';
    return {
      scores,
      metrics: { lcp: m('largest-contentful-paint'), fcp: m('first-contentful-paint'), cls: m('cumulative-layout-shift'), tbt: m('total-blocking-time'), si: m('speed-index') },
      issues
    };
  }

  /* ---------- Pintar el resultado ---------- */
  const SCORE_LABELS = { rendimiento: 'Rendimiento', seo: 'SEO', accesibilidad: 'Accesibilidad', buenas_practicas: 'Buenas prácticas' };

  function renderScores(psi) {
    const box = q('[data-scores]');
    box.textContent = '';
    for (const [key, label] of Object.entries(SCORE_LABELS)) {
      const v = psi && psi.scores ? psi.scores[key] : undefined;
      const el = document.createElement('div');
      el.className = 'au-score' + (v === undefined ? ' is-empty' : '');
      const color = v === undefined ? 'var(--faint)' : v >= 90 ? 'var(--green)' : v >= 50 ? 'var(--lime)' : 'var(--warn)';
      el.style.setProperty('--c', color);
      const C = 2 * Math.PI * 40;
      el.innerHTML = `<svg viewBox="0 0 96 96" aria-hidden="true"><circle class="track" cx="48" cy="48" r="40"/><circle class="val" cx="48" cy="48" r="40" stroke-dasharray="${C.toFixed(1)}" stroke-dashoffset="${C.toFixed(1)}"/></svg><b></b><span></span>`;
      q('b', el).textContent = v === undefined ? '—' : String(v);
      q('span', el).textContent = label;
      box.appendChild(el);
      if (v !== undefined) requestAnimationFrame(() => requestAnimationFrame(() => { q('.val', el).style.strokeDashoffset = (C * (1 - v / 100)).toFixed(1); }));
    }
  }

  // Mini formato seguro para el texto de la IA: párrafos, listas con «- » y **negrita** (sin HTML)
  function renderText(container, text) {
    container.textContent = '';
    let list = null;
    const inline = (parent, line) => {
      line.split(/(\*\*[^*]+\*\*)/g).forEach((part) => {
        if (!part) return;
        if (/^\*\*[^*]+\*\*$/.test(part)) { const b = document.createElement('strong'); b.textContent = part.slice(2, -2); parent.appendChild(b); }
        else parent.appendChild(document.createTextNode(part));
      });
    };
    String(text || '').split('\n').forEach((raw) => {
      const line = raw.trim();
      if (!line) { list = null; return; }
      if (/^[-*•]\s+/.test(line)) {
        if (!list) { list = document.createElement('ul'); container.appendChild(list); }
        const li = document.createElement('li'); inline(li, line.replace(/^[-*•]\s+/, '')); list.appendChild(li);
      } else {
        list = null;
        const p = document.createElement('p'); inline(p, line.replace(/^#+\s*/, '')); container.appendChild(p);
      }
    });
  }

  const GROUPS = { seguridad: 'Seguridad', seo: 'SEO técnico', tecnico: 'Funcionamiento' };
  function renderChecks(checks, psi) {
    const box = q('[data-checks]');
    box.textContent = '';
    const groups = {};
    (checks || []).forEach((c) => { (groups[c.area] = groups[c.area] || []).push(c); });
    const issues = psi && psi.issues ? Object.values(psi.issues).flat() : [];
    if (issues.length) groups.google = issues.slice(0, 8).map((t) => ({ estado: 'warn', titulo: t }));
    const names = { ...GROUPS, google: 'Mejoras que sugiere Google' };
    for (const [area, items] of Object.entries(groups)) {
      const g = document.createElement('section');
      g.className = 'au-group';
      const h = document.createElement('h3'); h.textContent = names[area] || area; g.appendChild(h);
      const ul = document.createElement('ul');
      items.forEach((c) => {
        const li = document.createElement('li');
        li.dataset.s = c.estado;
        const i = document.createElement('i'); i.textContent = c.estado === 'ok' ? '✓' : c.estado === 'warn' ? '!' : '✕';
        const d = document.createElement('div'); d.textContent = c.titulo;
        if (c.detalle) { const sm = document.createElement('small'); sm.textContent = c.detalle; d.appendChild(sm); }
        li.append(i, d); ul.appendChild(li);
      });
      g.appendChild(ul); box.appendChild(g);
    }
  }

  // Resumen de respaldo si la IA no responde
  function localSummary(checks, psi) {
    const bad = (checks || []).filter((c) => c.estado !== 'ok');
    const lines = [];
    if (psi && psi.scores) {
      const s = psi.scores;
      lines.push(`La web obtiene **${s.rendimiento ?? '—'}/100 en rendimiento**, ${s.seo ?? '—'} en SEO, ${s.accesibilidad ?? '—'} en accesibilidad y ${s.buenas_practicas ?? '—'} en buenas prácticas (medición de Google en móvil).`);
    }
    if (bad.length) {
      lines.push('', 'Lo más importante para mejorar:');
      bad.slice(0, 5).forEach((c) => lines.push('- ' + c.titulo + (c.detalle ? ': ' + c.detalle : '')));
    } else lines.push('', 'No encontramos problemas importantes en la revisión técnica.');
    return lines.join('\n');
  }

  /* ---------- Modo demostración ---------- */
  const DEMO_DATA = {
    checks: [
      { area: 'seguridad', estado: 'ok', titulo: 'HTTPS activo con certificado válido', detalle: 'Caduca dentro de 87 días.' },
      { area: 'seguridad', estado: 'bad', titulo: 'Falta HSTS', detalle: 'El navegador no está obligado a usar siempre HTTPS.' },
      { area: 'seguridad', estado: 'warn', titulo: 'Política de contenido (CSP) ausente', detalle: 'Ayuda a bloquear código inyectado.' },
      { area: 'seo', estado: 'ok', titulo: 'Título de la página correcto', detalle: '«Tu empresa | Servicios» (24 caracteres).' },
      { area: 'seo', estado: 'bad', titulo: 'Sin meta descripción', detalle: 'Google mostrará un fragmento elegido al azar.' },
      { area: 'seo', estado: 'warn', titulo: 'Sin sitemap.xml', detalle: 'Facilita que Google encuentre todas las páginas.' },
      { area: 'tecnico', estado: 'ok', titulo: 'Respuesta del servidor rápida', detalle: '0,31 s hasta el primer byte.' }
    ],
    psi: { scores: { rendimiento: 54, seo: 82, accesibilidad: 76, buenas_practicas: 92 }, metrics: {}, issues: { rendimiento: ['Reduce el JavaScript que no se usa', 'Usa formatos de imagen modernos'], seo: ['El documento no tiene una metadescripción'] } },
    summary: 'Tu web funciona y es segura en lo básico, pero **pierde visitas por velocidad y SEO**.\n\n- **Velocidad (54/100):** las imágenes pesan demasiado y hay JavaScript que no se usa. En móvil tarda en mostrarse.\n- **SEO:** falta la meta descripción y el sitemap, dos ajustes rápidos que ayudan a aparecer en Google.\n- **Seguridad:** añadir HSTS y una política de contenido cierra las puertas más comunes a ataques.\n\nCon estos cambios la web cargaría casi el doble de rápido y estaría mejor preparada para captar clientes.'
  };

  /* ---------- Flujo principal ---------- */
  let t0 = 0, tick = 0;
  function startTimer() {
    t0 = performance.now();
    timer.hidden = false;
    clearInterval(tick);
    tick = setInterval(() => { timer.textContent = `Analizando · ${Math.round((performance.now() - t0) / 1000)} s`; }, 500);
  }
  function stopTimer(text) { clearInterval(tick); timer.textContent = text || ''; timer.hidden = !text; }

  async function runAudit(url) {
    busy = true;
    btn.disabled = true;
    btnLabel.textContent = 'Analizando…';
    showMsg(msg, '');
    result.hidden = true;
    steps.hidden = false;
    qa('li', steps).forEach((li) => li.classList.remove('is-active', 'is-done', 'is-skip'));
    setState('working');
    startTimer();
    if (!reduce) q('.au-stage').scrollIntoView({ behavior: 'smooth', block: 'center' });

    try {
      // 1. Revisión desde nuestro servidor
      setStep('conexion', 'active');
      const r1 = DEMO ? (await sleep(1800), { id: 'demo', url, host: new URL(url).hostname, checks: DEMO_DATA.checks, psiKey: '' })
        : await post('revisar.php', { url, web: form.elements.web.value });
      auditId = r1.id;
      setStep('conexion', 'done');
      setStep('seguridad', 'active');
      await sleep(DEMO ? 1400 : 500);
      setStep('seguridad', 'done');

      // 2. Google PageSpeed
      setStep('velocidad', 'active');
      const psi = DEMO ? (await sleep(3200), DEMO_DATA.psi) : await pageSpeed(r1.url, r1.psiKey);
      setStep('velocidad', psi ? 'done' : 'skip');
      setStep('seo', 'active');
      await sleep(DEMO ? 1200 : 400);
      setStep('seo', 'done'); // el SEO lo revisa siempre nuestro servidor; Google solo añade la nota

      // 3. Resumen con IA
      setStep('informe', 'active');
      let summary = '';
      if (DEMO) { await sleep(2600); summary = DEMO_DATA.summary; }
      else {
        try { summary = (await post('informe.php', { id: auditId, psi }, 60000)).summary; } catch (e) { summary = ''; }
      }
      setStep('informe', summary ? 'done' : 'skip');

      // 4. Resultado
      q('[data-site]').textContent = r1.host;
      renderScores(psi);
      renderText(q('[data-summary]'), summary || localSummary(r1.checks, psi));
      renderChecks(r1.checks, psi);
      result.hidden = false;
      setState('done');
      stopTimer(`Listo en ${Math.round((performance.now() - t0) / 1000)} s`);
      showMsg(mailMsg, '');
      if (!reduce) setTimeout(() => result.scrollIntoView({ behavior: 'smooth', block: 'start' }), 700);
    } catch (e) {
      setState('error');
      stopTimer('');
      steps.hidden = true;
      showMsg(msg, errText(e));
    } finally {
      busy = false;
      btn.disabled = false;
      btnLabel.textContent = 'Analizar otra web';
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (busy) return;
    if (form.elements.web.value) return;   // campo trampa relleno
    const url = normalizeUrl(input.value);
    if (!url) { showMsg(msg, ERRORS.url_invalida); input.focus(); return; }
    input.value = url;
    runAudit(url);
  });

  /* ---------- Informe detallado por email ---------- */
  mailForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (mailForm.elements.web.value) return;
    const email = mailForm.elements.email.value.trim();
    const okEmail = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) && email.length <= 254;
    const consent = mailForm.elements.consent.checked;
    mailForm.elements.email.classList.toggle('is-invalid', !okEmail);
    q('.field--check', mailForm).classList.toggle('is-invalid', !consent);
    if (!okEmail) { showMsg(mailMsg, ERRORS.email_invalido); return; }
    if (!consent) { showMsg(mailMsg, ERRORS.sin_consentimiento); return; }
    const b = q('[data-mail-btn]');
    b.disabled = true;
    b.textContent = 'Enviando…';
    setState('working');
    try {
      if (DEMO) await sleep(2200);
      else await post('enviar.php', { id: auditId, email, consent: true, web: '' }, 90000);
      showMsg(mailMsg, `¡Listo! Te enviamos el informe detallado a ${email}. Revisa también la carpeta de spam.`, true);
      mailForm.elements.email.value = '';
      mailForm.elements.consent.checked = false;
    } catch (err) {
      showMsg(mailMsg, errText(err));
    } finally {
      setState('done');
      b.disabled = false;
      b.textContent = 'Enviarme el informe';
    }
  });

  // Rellena la URL si llega como parámetro (?url=…) para compartir enlaces directos
  const pre = new URLSearchParams(location.search).get('url');
  if (pre) input.value = pre.slice(0, 300);
})();
