/*!
 * Digital Lab · página de Digital Lab Studio
 * El CEO trabaja en el centro, seis especialistas lo rodean (el mismo agente de la auditoría, recoloreado con el color de cada puesto)
 * y los datos fluyen debajo con la misma animación del hero.
 */
(function () {
  'use strict';

  const q = (s, r = document) => r.querySelector(s);
  const qa = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  /* ---------- Los especialistas (números y colores del organigrama) ---------- */
  const TEAM = [
    { n: '25', role: 'Cybersecurity', color: '#6D87FF', side: 'l' },
    { n: '29', role: 'Web Designer', color: '#F04C0C', side: 'l' },
    { n: '28', role: 'UI/UX Designer', color: '#0C7EDC', side: 'l' },
    { n: '20', role: 'Software Developer', color: '#00D771', side: 'r' },
    { n: '08', role: 'Consultor & Propuestas', color: '#FF4FA3', side: 'r' },
    { n: '16', role: 'QA & Process Agent', color: '#FFB020', side: 'r' }
  ];

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

  const seats = [];
  TEAM.forEach((a, i) => {
    const el = document.createElement('div');
    el.className = 'st-seat';
    el.dataset.st = 'idle';
    el.style.setProperty('--c', a.color);
    el.style.setProperty('--i', i);
    el.innerHTML = `<p class="st-tag"><b>${a.n}</b> ${esc(a.role)}</p>`
      + '<div class="av"><span class="av__glow"></span><div class="av__body"><canvas class="av__img" width="320" height="320" aria-hidden="true"></canvas>'
      + '<svg class="av__eyes" viewBox="0 0 1254 1254" aria-hidden="true"><path d="M442 607Q499 535 556 607"/><path d="M701 607Q757.5 535 814 607"/></svg></div></div>';
    // el recoloreado de cada avatar se hace por turnos para no bloquear el navegador al cargar
    ('requestIdleCallback' in window ? (f) => requestIdleCallback(f, { timeout: 1500 }) : (f) => setTimeout(f, 60 * (i + 1)))(() => tint(q('canvas', el), a.color));
    q(`[data-side="${a.side}"]`).appendChild(el);
    seats.push(el);
  });

  /* ---------- Vida: cada cierto tiempo un especialista se pone a trabajar y termina contento ---------- */
  const team = q('[data-team]');
  let visible = true;
  if ('IntersectionObserver' in window && team) new IntersectionObserver((e) => { visible = e[e.length - 1].isIntersecting; }, { threshold: 0.05 }).observe(team);
  if (!reduce) {
    let last = -1;
    setInterval(() => {
      if (document.hidden || !visible) return;
      let k; do { k = Math.floor(Math.random() * seats.length); } while (k === last && seats.length > 1);
      last = k;
      const s = seats[k];
      s.dataset.st = 'run';
      setTimeout(() => { s.dataset.st = 'ok'; setTimeout(() => { s.dataset.st = 'idle'; }, 1400); }, 2600);
    }, 1700);
  } else {
    const ceo = q('[data-ceo]'); if (ceo) ceo.dataset.state = 'idle';
  }

  /* ---------- Datos que fluyen (la misma animación del hero) ---------- */
  const canvas = q('.st-canvas');
  if (canvas && window.DigitalLabHero) {
    const small = window.matchMedia('(max-width: 860px)').matches;
    const flow = DigitalLabHero.mount(canvas, { density: small ? 0.8 : 0.85, activity: 3.2, seed: 23, beams: true });
    if (reduce && flow.setPaused) setTimeout(() => flow.setPaused(true), 600);
  }

  /* ---------- Las secciones aparecen al llegar ---------- */
  const rev = qa('.st-rev');
  if (reduce || !('IntersectionObserver' in window)) rev.forEach((e) => e.classList.add('is-in'));
  else {
    const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } }), { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    rev.forEach((e) => io.observe(e));
  }
})();
