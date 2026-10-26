/*!
 * Digital Lab · auditoría web con un equipo de agentes de IA
 * Mismo flujo que «Auditoría web» del organigrama de Digital Lab:
 * 1. api/revisar.php revisa la web desde el servidor (seguridad, privacidad, SEO, accesibilidad, rendimiento, conversión).
 * 2. El navegador pide a Google PageSpeed la velocidad, el SEO, la accesibilidad y las buenas prácticas.
 * 3. api/agente.php ejecuta cada agente: 25 ∥ 29 → 28 → 15 → 08 → 16 QA (y correcciones si el QA las pide).
 * 4. Si el 16 QA aprueba el informe, el visitante lo descarga en PDF (informe-pdf.js, generado en su navegador).
 * Con ?demo en la dirección se simula todo con una auditoría de ejemplo, para ver la animación sin servidor.
 */
(function () {
  'use strict';

  const q = (s, r = document) => r.querySelector(s);
  const qa = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Demostración: con ?demo, o en GitHub Pages (PRE), donde no hay PHP
  const DEMO = new URLSearchParams(location.search).has('demo') || location.hostname.endsWith('github.io');
  const DEMO_NOTE = 'Modo demostración: los resultados son de ejemplo y no se analiza tu web de verdad.';
  const API = 'api/';
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  const form = q('[data-audit-form]'), input = q('#au-url'), codeInput = q('#au-codigo'), btn = q('[data-audit-btn]'), btnLabel = q('[data-btn-label]');
  const msg = q('[data-msg]'), agent = q('[data-agent]'), team = q('[data-team]'), steps = q('[data-steps]'), timer = q('[data-timer]');
  const caption = q('[data-caption]'), leadPill = q('[data-lead-pill]'), result = q('[data-result]');
  let busy = false, run = null, resumable = false;
  if (DEMO) showMsg(msg, DEMO_NOTE, true);

  /* ---------- El equipo (números y colores del organigrama) ---------- */
  const LEAD = { n: '15', role: 'AI Auditor', color: '#8b5cff', task: 'Diagnóstico y plan de acción' };
  const TEAM = [
    { n: '25', role: 'Cybersecurity', color: '#4f6bff', task: 'Seguridad y privacidad', side: 'l' },
    { n: '29', role: 'Web Designer', color: '#ff8a3d', task: 'SEO, velocidad y conversión', side: 'l' },
    { n: '28', role: 'UI/UX Designer', color: '#3b9bff', task: 'Accesibilidad y experiencia', side: 'l' },
    { n: '08', role: 'Consultor & Propuestas', color: '#ff4fa3', task: 'Cómo puede ayudarte Digital Lab', side: 'r' },
    { n: '16', role: 'QA & Process Agent', color: '#ffb020', task: 'Revisión de calidad', side: 'r' }
  ];
  const WHO = Object.fromEntries([LEAD, ...TEAM].map((a) => [a.n, a]));

  // Avatares: el mismo agente, recoloreado en canvas con el color de cada puesto
  const src = new Image();
  const srcReady = new Promise((res) => { src.onload = res; src.onerror = res; });
  src.src = 'agente.webp?v=20261016d';
  function tint(canvas, color) {
    srcReady.then(() => {
      if (!src.naturalWidth) return;
      const x = canvas.getContext('2d'), S = canvas.width;
      x.drawImage(src, 0, 0, S, S);
      const d = x.getImageData(0, 0, S, S), p = d.data, t = [1, 3, 5].map((i) => parseInt(color.substr(i, 2), 16));
      for (let i = 0; i < p.length; i += 4) {
        if (!p[i + 3]) continue;
        const v = Math.max(p[i], p[i + 1], p[i + 2]) / 255, w = Math.pow(Math.min(p[i], p[i + 1], p[i + 2]) / 255, 1.15);
        for (let k = 0; k < 3; k++) p[i + k] = Math.min(255, t[k] * v + (255 - t[k]) * w);
      }
      x.putImageData(d, 0, 0);
    });
  }
  const seats = {};
  TEAM.forEach((a, i) => {
    const el = document.createElement('div');
    el.className = 'au-seat';
    el.dataset.st = 'off';
    el.style.setProperty('--c', a.color);
    el.style.setProperty('--i', i);
    el.innerHTML = `<p class="au-tag"><b>${a.n}</b> ${esc(a.role)}</p>`
      + '<div class="av"><span class="av__glow"></span><div class="av__body"><canvas class="av__img" width="320" height="320"></canvas>'
      + '<svg class="av__eyes" viewBox="0 0 1254 1254" aria-hidden="true"><path d="M442 607Q499 535 556 607"/><path d="M701 607Q757.5 535 814 607"/></svg></div></div>'
      + `<p class="au-seat__task">${esc(a.task)}</p><p class="au-pill" data-pill>En espera</p>`;
    tint(q('canvas', el), a.color);
    q(`[data-side="${a.side}"]`, team).appendChild(el);
    seats[a.n] = el;
  });

  const PILL = { wait: 'En espera', run: 'Trabajando', fix: 'Corrigiendo', ok: 'Listo', err: 'Sin terminar' };
  function seat(n, st, pill) {
    if (n === LEAD.n) { leadPill.hidden = false; leadPill.dataset.st = st; leadPill.textContent = pill || PILL[st]; return; }
    const el = seats[n]; if (!el) return;
    el.dataset.st = st;
    q('[data-pill]', el).textContent = pill || PILL[st] || '';
  }
  function say(text) { caption.hidden = !text; caption.textContent = text || ''; }

  /* ---------- Hilos de datos (la misma animación del hero) ---------- */
  let flow = null;
  const canvas = q('.au-canvas');
  if (canvas && window.DigitalLabHero) {
    flow = DigitalLabHero.mount(canvas, { density: 0.8, activity: 3.4, seed: 23, beams: true });
    if (flow.setPaused) flow.setPaused(true);
  }
  // Los hilos solo se ven (y solo se animan) mientras los agentes analizan una web
  const flowBox = q('[data-flow]');
  const FLOW_MS = 850;
  let flowTimer = 0;
  function showFlow(on) {
    if (!flowBox) return;
    clearTimeout(flowTimer);
    flowBox.classList.toggle('is-on', on);
    if (!flow || !flow.setPaused) return;
    if (on) flow.setPaused(false);
    else flowTimer = setTimeout(() => flow.setPaused(true), FLOW_MS);
  }

  function setState(state) { agent.dataset.state = state; }
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
    codigo: 'El código de acceso no es correcto.',
    limite: 'Has hecho varias auditorías seguidas. Espera un rato y vuelve a intentarlo.',
    sin_servicio: 'El servicio de auditoría no está disponible en este servidor. Pruébalo en dl-dev.com/auditoria.',
    expirada: 'Esta auditoría ya caducó. Vuelve a analizar la web.',
    ia_no_disponible: 'Uno de nuestros agentes no pudo terminar su parte.',
    ia_no_configurada: 'Nuestros agentes no están disponibles en este momento. Vuelve a intentarlo más tarde.',
    error: 'Algo falló en la auditoría. Vuelve a intentarlo en unos minutos.'
  };
  const errText = (e) => ERRORS[e && e.message] || (e && e.name === 'AbortError' ? 'Un agente tardó demasiado en responder.' : ERRORS.error);

  /* ---------- Google PageSpeed (desde el navegador) ---------- */
  async function pageSpeed(url, key) {
    if (!key) return null;
    const params = new URLSearchParams({ url, strategy: 'mobile', locale: 'es', key });
    ['performance', 'seo', 'accessibility', 'best-practices'].forEach((c) => params.append('category', c));
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), 75000);
    try {
      const r = await fetch('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' + params, { signal: ctl.signal });
      if (!r.ok) {
        // p. ej. clave restringida a otro dominio o cuota agotada: se sigue sin datos de velocidad
        const err = await r.json().catch(() => ({}));
        console.warn('PageSpeed no disponible:', r.status, (err.error && err.error.message) || '');
        return null;
      }
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

  /* ---------- Notas (igual que en el organigrama) ---------- */
  const AREAS = { seguridad: 'Seguridad', privacidad: 'Privacidad', seo: 'SEO', rendimiento: 'Rendimiento', accesibilidad: 'Accesibilidad', conversion: 'Conversión' };
  function areaScore(checks, areas) {
    const list = (checks || []).filter((c) => areas.includes(c.area) && c.estado !== 'na');
    if (!list.length) return null;
    return Math.round(100 * list.reduce((s, c) => s + (c.estado === 'ok' ? 1 : c.estado === 'warn' ? 0.5 : 0), 0) / list.length);
  }
  function auScores(d) {
    const p = (d.psi && d.psi.scores) || {};
    const s = [
      { k: 'rendimiento', l: 'Rendimiento', v: p.rendimiento != null ? p.rendimiento : areaScore(d.checks, ['rendimiento']), src: p.rendimiento != null ? 'Google' : 'Revisión' },
      { k: 'seo', l: 'SEO', v: p.seo != null ? p.seo : areaScore(d.checks, ['seo']), src: p.seo != null ? 'Google' : 'Revisión' },
      { k: 'accesibilidad', l: 'Accesibilidad', v: p.accesibilidad != null ? p.accesibilidad : areaScore(d.checks, ['accesibilidad']), src: p.accesibilidad != null ? 'Google' : 'Revisión' },
      { k: 'seguridad', l: 'Seguridad', v: areaScore(d.checks, ['seguridad', 'privacidad']), src: 'Revisión' }
    ];
    const vals = s.map((x) => x.v).filter((v) => v != null);
    return { list: s, global: vals.length ? Math.round(vals.reduce((a, b) => a + b, 0) / vals.length) : null };
  }
  function auAreas(r) {
    const o = r.out, s = (o.au25 || {}).seguridad, z = o.au29 || {}, a = (o.au28 || {}).accesibilidad;
    return [['seguridad', 'Seguridad y privacidad', s], ['seo', 'SEO', z.seo], ['rendimiento', 'Velocidad', z.rendimiento], ['accesibilidad', 'Accesibilidad', a], ['conversion', 'Conversión', z.conversion]].filter((x) => x[2]);
  }

  /* ---------- Pintar el resultado ---------- */
  const scoreColor = (v) => v == null ? 'var(--faint)' : v >= 90 ? 'var(--green)' : v >= 50 ? 'var(--lime)' : 'var(--warn)';
  function ring(v, label, sub, big) {
    const C = 2 * Math.PI * 40;
    const el = document.createElement('div');
    el.className = 'au-score' + (big ? ' au-score--big' : '') + (v == null ? ' is-empty' : '');
    el.style.setProperty('--c', scoreColor(v));
    el.innerHTML = `<svg viewBox="0 0 96 96" aria-hidden="true"><circle class="track" cx="48" cy="48" r="40"/><circle class="val" cx="48" cy="48" r="40" stroke-dasharray="${C.toFixed(1)}" stroke-dashoffset="${C.toFixed(1)}"/></svg>`
      + `<b>${v == null ? '—' : v}</b><span>${esc(label)}</span>${sub ? `<small>${esc(sub)}</small>` : ''}`;
    if (v != null) requestAnimationFrame(() => requestAnimationFrame(() => { q('.val', el).style.strokeDashoffset = (C * (1 - v / 100)).toFixed(1); }));
    return el;
  }
  function renderScores(d) {
    const box = q('[data-scores]'), sc = auScores(d);
    box.textContent = '';
    box.appendChild(ring(sc.global, 'Nota global', 'Promedio de las 4 notas', true));
    sc.list.forEach((x) => box.appendChild(ring(x.v, x.l, x.src === 'Google' ? 'Google PageSpeed' : 'Revisión Digital Lab')));
  }

  // Mini formato seguro para el texto de los agentes: párrafos, listas con «- » y **negrita** (sin HTML)
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

  const EST = { ok: '✓', warn: '!', bad: '✕' };
  function renderReport(r) {
    const rep = r.out.au15 || {}, off = r.out.au08 || {};
    renderText(q('[data-summary]'), (rep.diagnostico || '') + '\n\n' + (rep.resumen_corto || ''));
    let h = '';
    auAreas(r).forEach(([, name, a]) => {
      h += `<section class="au-box"><h3>${esc(name)}</h3><p>${esc(a.resumen || '')}</p><ul class="au-finds">`
        + (a.hallazgos || []).map((f) => `<li data-s="${esc(f.estado)}"><i>${EST[f.estado] || '·'}</i><div><b>${esc(f.titulo)}</b>`
          + `<p>${esc(f.por_que || '')}${f.como ? ` <strong>Cómo:</strong> ${esc(f.como)}` : ''}</p></div>`
          + (f.estado === 'ok' ? '' : `<span class="au-chip" data-i="${esc(f.impacto)}">impacto ${esc(f.impacto || '-')}</span>`) + '</li>').join('')
        + '</ul></section>';
    });
    if ((rep.plan || []).length) {
      h += '<section class="au-box au-box--plan"><h3>Plan de acción</h3><ol class="au-plan">'
        + rep.plan.map((p) => `<li><span>${esc(p.tarea)}</span><span class="au-chip" data-i="${esc(p.impacto)}">impacto ${esc(p.impacto)}</span><span class="au-chip">esfuerzo ${esc(p.esfuerzo)}</span></li>`).join('')
        + '</ol>' + (rep.lo_que_ganarias ? `<p class="au-gain">${esc(rep.lo_que_ganarias)}</p>` : '') + '</section>';
    }
    if (off.titular) {
      h += `<section class="au-box au-box--offer"><p class="eyebrow"><i></i>Digital Lab</p><h3>${esc(off.titular)}</h3><p>${esc(off.texto || '')}</p>`
        + '<ul class="au-services">' + (off.servicios || []).map((s) => `<li><b>${esc(s.servicio)}</b><span>${esc(s.por_que)}</span></li>`).join('') + '</ul>'
        + `<a class="btn btn--primary" href="../#contacto">${esc(off.cta || 'Hablemos de tu web')}</a></section>`;
    }
    q('[data-areas]').innerHTML = h;
  }

  // Comprobaciones automáticas: solo se muestran si el informe redactado queda retenido
  function renderChecks(d) {
    const box = q('[data-checks]'), groups = {};
    d.checks.forEach((c) => { (groups[c.area] = groups[c.area] || []).push(c); });
    box.innerHTML = Object.keys(groups).map((k) => `<section class="au-group"><h3>${esc(AREAS[k] || k)}</h3><ul>`
      + groups[k].map((c) => `<li data-s="${esc(c.estado)}"><i>${EST[c.estado] || '–'}</i><div>${esc(c.titulo)}${c.detalle ? `<small>${esc(c.detalle)}</small>` : ''}</div></li>`).join('')
      + '</ul></section>').join('');
  }

  function renderResult(r) {
    const d = r.data, ok = r.aprobado;
    q('[data-site]').textContent = d.host;
    q('[data-demo-tag]').hidden = d.origen !== 'ejemplo';
    renderScores(d);
    q('[data-diagnosis]').hidden = !ok;
    q('[data-download]').hidden = !ok;
    q('[data-held]').hidden = ok;
    q('[data-checks]').hidden = ok;
    q('[data-pdf-note]').textContent = '';
    if (ok) renderReport(r); else { q('[data-areas]').textContent = ''; renderChecks(d); }
    q('[data-held-wa]').href = 'https://wa.me/5493496532749?text=' + encodeURIComponent('Hola Digital Lab, hice la auditoría de ' + d.host + ' y me gustaría recibir el informe revisado.');
    result.hidden = false;
  }

  /* ---------- Modo demostración: una auditoría de ejemplo del organigrama ---------- */
  let demoData = null;
  async function loadDemo() {
    if (!demoData) demoData = await fetch('demo.json?v=20261019a').then((r) => { if (!r.ok) throw new Error('error'); return r.json(); });
    return demoData;
  }

  /* ---------- Un paso del flujo: un agente trabaja ---------- */
  const STEP_OF = { au25: '25', au29: '29', au28: '28', au15: '15', au08: '08', au16: '16', au16b: '16' };
  const CAPTION = {
    au25: 'El 25 · Cybersecurity revisa la seguridad y la privacidad de tu web.',
    au29: 'El 29 · Web Designer analiza el SEO, la velocidad y la conversión.',
    au28: 'El 28 · UI/UX Designer comprueba la accesibilidad y la experiencia de uso.',
    au15: 'El 15 · AI Auditor integra el trabajo del equipo en un diagnóstico y un plan de acción.',
    au08: 'El 08 · Consultor prepara cómo te puede ayudar Digital Lab.',
    au16: 'El 16 · QA revisa la calidad del informe antes de entregártelo.',
    au16b: 'El 16 · QA vuelve a revisar el informe corregido.'
  };
  async function step(r, paso) {
    if (r.done[paso]) return r.done[paso];
    const fix = paso.startsWith('fix'), n = fix ? paso.slice(3) : STEP_OF[paso];
    seat(n, fix ? 'fix' : 'run', paso === 'au16b' ? 'Revisando otra vez' : null);
    say(fix ? `El ${n} · ${WHO[n].role} corrige lo que pidió el agente de calidad.` : CAPTION[paso]);
    let data;
    try {
      if (DEMO) {
        await sleep(paso === 'au16' ? 3200 : 2200 + Math.random() * 1600);
        data = paso.startsWith('au16') ? { aprobado: true, corregir: [] } : demoData.out['au' + n];
      } else {
        data = (await post('agente.php', { id: r.id, paso, psi: r.data.psi }, 330000)).data;
      }
    } catch (e) {
      seat(n, 'err');
      e.agente = n;
      throw e;
    }
    r.done[paso] = data;
    if (!paso.startsWith('au16')) r.out['au' + n] = data;
    seat(n, 'ok', fix ? 'Corregido' : paso.startsWith('au16') ? (data.aprobado ? 'Aprobado' : 'Pide cambios') : null);
    return data;
  }

  async function pipeline(r) {
    setStep('equipo', 'active');
    TEAM.forEach((a) => { if (seats[a.n].dataset.st === 'off') seat(a.n, 'wait'); });
    seat(LEAD.n, 'wait', 'Coordinando');
    await Promise.all([step(r, 'au25'), step(r, 'au29')]);
    await step(r, 'au28');
    setStep('equipo', 'done'); setStep('informe', 'active');
    await step(r, 'au15');
    await step(r, 'au08');
    setStep('informe', 'done'); setStep('calidad', 'active');
    let qa = await step(r, 'au16');
    if (!qa.aprobado && qa.corregir.length) {
      for (const n of ['25', '29', '28', '15', '08'].filter((x) => qa.corregir.includes(x))) await step(r, 'fix' + n);
      qa = await step(r, 'au16b');
    }
    r.aprobado = !!qa.aprobado;
    setStep('calidad', 'done');
  }

  /* ---------- Flujo principal ---------- */
  let t0 = 0, tick = 0;
  function startTimer() {
    t0 = performance.now();
    timer.hidden = false;
    clearInterval(tick);
    tick = setInterval(() => { timer.textContent = `Analizando · ${fmtSecs((performance.now() - t0) / 1000)}`; }, 500);
  }
  function fmtSecs(s) { s = Math.round(s); return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')} s`; }
  function stopTimer(text) { clearInterval(tick); timer.textContent = text || ''; timer.hidden = !text; }

  async function runAudit(url, resume) {
    busy = true;
    btn.disabled = true;
    btnLabel.textContent = 'Analizando…';
    showMsg(msg, DEMO ? DEMO_NOTE : '', true);
    result.hidden = true;
    steps.hidden = false;
    team.dataset.state = 'working';
    setState('working');
    seat(LEAD.n, 'run', 'Revisando la web');
    showFlow(true);
    startTimer();
    if (!resume) {
      qa('li', steps).forEach((li) => li.classList.remove('is-active', 'is-done', 'is-skip'));
      TEAM.forEach((a) => seat(a.n, 'off'));
      if (!reduce) q('.au-team').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    try {
      if (!resume) {
        // 1. Revisión automática desde nuestro servidor
        setStep('conexion', 'active');
        say('El 15 · AI Auditor visita tu web y hace la revisión automática.');
        let r1;
        if (DEMO) { const demo = await loadDemo(); await sleep(1800); r1 = { id: 'demo', ...demo.data, psiKey: '' }; }
        else r1 = await post('revisar.php', { url, codigo: codeInput.value, web: form.elements.web.value });
        setStep('conexion', 'done');

        // 2. Google PageSpeed
        setStep('velocidad', 'active');
        say('Medimos la velocidad de tu web en móvil con Google PageSpeed.');
        const psi = DEMO ? (await sleep(2400), r1.psi) : await pageSpeed(r1.url, r1.psiKey);
        setStep('velocidad', psi ? 'done' : 'skip');
        run = { id: r1.id, ts: Date.now(), data: { url: r1.url, host: r1.host, checks: r1.checks, terceros: r1.terceros || [], stats: r1.stats || {}, psi, origen: DEMO ? 'ejemplo' : 'servidor' }, out: {}, done: {}, aprobado: false };
      }

      // 3. El equipo de agentes
      say('El 15 · AI Auditor reúne al equipo.');
      await sleep(reduce ? 0 : 900);
      await pipeline(run);

      // 4. Resultado
      if (window.dlTrack) window.dlTrack(DEMO ? 'auditoría: ejemplo completado' : 'auditoría: completada');
      seat(LEAD.n, 'ok', run.aprobado ? 'Informe listo' : 'Informe retenido');
      say(run.aprobado ? 'Informe aprobado por el agente de calidad. Ya puedes descargarlo.' : 'El agente de calidad pidió una revisión humana del informe.');
      renderResult(run);
      setState('done');
      team.dataset.state = 'done';
      showFlow(false);
      stopTimer(`Listo en ${fmtSecs((performance.now() - t0) / 1000)}`);
      btnLabel.textContent = 'Analizar otra web';
      // primero se recogen los hilos y luego se baja al resultado, para que la página no salte
      if (!reduce) setTimeout(() => result.scrollIntoView({ behavior: 'smooth', block: 'start' }), FLOW_MS + 150);
    } catch (e) {
      setState('error');
      team.dataset.state = 'error';
      seat(LEAD.n, 'err', 'En pausa');
      showFlow(false);
      stopTimer('');
      const who = e.agente && WHO[e.agente] ? ` (${e.agente} · ${WHO[e.agente].role})` : '';
      const canResume = run && e.agente && !['expirada', 'limite'].includes(e.message);
      showMsg(msg, errText(e).replace(/\.$/, who + '.') + (canResume ? ' Pulsa «Continuar» para seguir desde ahí: lo que ya está hecho no se repite.' : ''));
      say('');
      if (e.message === 'codigo') { codeInput.value = ''; codeInput.focus(); }
      resumable = !!canResume;
      btnLabel.textContent = canResume ? 'Continuar' : 'Analizar mi web';
      if (!canResume) { run = null; steps.hidden = true; team.dataset.state = 'idle'; }
    } finally {
      busy = false;
      btn.disabled = false;
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (busy) return;
    if (form.elements.web.value) return;   // campo trampa relleno
    const url = normalizeUrl(input.value);
    if (!url) { showMsg(msg, ERRORS.url_invalida); input.focus(); return; }
    if (!DEMO && !codeInput.value.trim()) { showMsg(msg, 'Escribe el código de acceso para analizar la web.'); codeInput.focus(); return; }
    // «Continuar» retoma la misma web; si cambió la dirección, empieza una auditoría nueva
    const resume = resumable && !!run && url === input.dataset.last;
    resumable = false;
    input.value = url;
    input.dataset.last = url;
    if (!resume) run = null;
    runAudit(url, resume);
  });

  /* ---------- Informe en PDF (se genera en el navegador) ---------- */
  function loadScript(srcUrl) {
    return new Promise((res, rej) => {
      if (document.querySelector(`script[src="${srcUrl}"]`)) return res();
      const s = document.createElement('script');
      s.src = srcUrl; s.onload = res; s.onerror = () => rej(new Error('script'));
      document.head.appendChild(s);
    });
  }
  const pdfBtn = q('[data-pdf-btn]'), pdfLabel = q('[data-pdf-label]'), pdfNote = q('[data-pdf-note]');
  pdfBtn.addEventListener('click', async () => {
    if (window.dlTrack) window.dlTrack('auditoría: PDF descargado');
    if (!run || !run.aprobado) return;
    pdfBtn.disabled = true;
    pdfLabel.textContent = 'Preparando el PDF…';
    pdfNote.textContent = '';
    try {
      await loadScript('vendor/jspdf.umd.min.js?v=2.5.1');
      await loadScript('informe-pdf.js?v=20261019a');
      const blob = await window.DigitalLabInformePDF.build(run, { scores: auScores(run.data), areas: auAreas(run), areaScore });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'auditoria-' + String(run.data.host || 'web').replace(/[^\w.-]+/g, '-') + '-digital-lab.pdf';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 30000);
      pdfNote.textContent = `PDF descargado (${Math.max(1, Math.round(blob.size / 1024))} KB).`;
    } catch (err) {
      console.error(err);
      pdfNote.textContent = 'No se pudo preparar el PDF. Recarga la página y vuelve a intentarlo.';
    } finally {
      pdfBtn.disabled = false;
      pdfLabel.textContent = 'Descargar informe PDF';
    }
  });

  // Rellena la URL si llega como parámetro (?url=…) para compartir enlaces directos
  const pre = new URLSearchParams(location.search).get('url');
  if (pre) input.value = pre.slice(0, 300);
})();
