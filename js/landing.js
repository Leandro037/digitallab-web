/*!
 * Digital Lab · landing
 * Hilos de luz que salen del hero y recorren la página, animaciones de cada servicio,
 * carrusel de proyectos, textos que se iluminan con el scroll y formulario.
 * Depende de hero-animation.js y logo-animations.js (cargados antes).
 */
(function () {
  'use strict';

  document.documentElement.classList.add('js');

  const TAU = Math.PI * 2;
  const COLORS = ['#22d3ee', '#5ad41c', '#e6f23a'];
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const q = (s, r = document) => r.querySelector(s);
  const qa = (s, r = document) => [...r.querySelectorAll(s)];
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const lerp = (a, b, t) => a + (b - a) * t;
  const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

  /* ------------------------------------------------------------------ */
  /* Hero y logos animados                                              */
  /* ------------------------------------------------------------------ */

  // En móvil (≤ 860 px) la animación va debajo del texto: sin integración y los hilos
  // bajan por el margen izquierdo en vez de cruzar las secciones
  const narrow = window.matchMedia('(max-width: 860px)');
  const lockup = q('[data-lockup]'), brandblock = q('[data-brandblock]');
  let stackEye = null, hero = null;

  const heroCanvas = q('.hero__canvas');
  if (heroCanvas && window.DigitalLabHero) {
    hero = DigitalLabHero.mount(heroCanvas, {
      anchor: q('.hero__visual'),
      flowOut: true,
      beams: !narrow.matches,
      onLineEnd: () => { if (stackEye) stackEye.blink(); }   // el dato llega al logo y el ojo parpadea
    });
  }
  if (window.DigitalLabLogo) {
    const brandEye = DigitalLabLogo.mount(q('.brand__eye'), { variant: 'parpadeo', look: 'hilo' });
    q('.nav .brand').addEventListener('mouseenter', () => brandEye.blink());
    const footerEye = DigitalLabLogo.mount(q('.footer__eye'), { variant: 'parpadeo', look: 'hilo' });
    q('.footer .brand').addEventListener('mouseenter', () => footerEye.blink());
    // El ojo grande del hero sigue al cursor (y parpadea de vez en cuando)
    stackEye = DigitalLabLogo.mount(q('.lockup__eye'), { variant: 'mirada', look: 'hilo', center: 'eye' });
    lockup.addEventListener('mouseenter', () => stackEye.blink());  }

  // Escritorio: la matriz se alinea con el logo y su fila de puntos hace de línea bajo el nombre
  function applyHeroMode() {
    if (!hero) return;
    const wide = !narrow.matches;
    hero.setOptions({
      band: wide ? lockup : null,
      lineStart: wide ? brandblock : null,
      ragged: wide ? 0.22 : 0.62,
      beams: wide
    });
  }
  applyHeroMode();
  narrow.addEventListener('change', () => { applyHeroMode(); relayout(); });

  /* ------------------------------------------------------------------ */
  /* Navegación                                                          */
  /* ------------------------------------------------------------------ */

  const nav = q('[data-nav]');
  const toggle = q('.nav__toggle', nav);
  function setMenu(open) {
    nav.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
  }
  toggle.addEventListener('click', () => setMenu(!nav.classList.contains('is-open')));
  qa('.nav__menu a', nav).forEach((a) => a.addEventListener('click', () => setMenu(false)));

  // Cuando el logo grande sale de la vista, la marca pequeña sustituye a la cápsula del menú
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => nav.classList.toggle('show-brand', !e.isIntersecting), {
      rootMargin: '-90px 0px 0px 0px'
    }).observe(lockup);
  } else {
    nav.classList.add('show-brand');
  }

  /* ------------------------------------------------------------------ */
  /* Aparición al hacer scroll                                           */
  /* ------------------------------------------------------------------ */

  const revealEls = qa('[data-reveal]');
  if ('IntersectionObserver' in window && !reduce) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { rootMargin: '0px 0px -12% 0px' });
    revealEls.forEach((el) => io.observe(el));
  } else {
    revealEls.forEach((el) => el.classList.add('in'));
  }

  // Luz que sigue al cursor dentro de cada tarjeta de servicio
  qa('.svc').forEach((card) => {
    card.addEventListener('pointermove', (e) => {
      const r = card.getBoundingClientRect();
      card.style.setProperty('--mx', `${e.clientX - r.left}px`);
      card.style.setProperty('--my', `${e.clientY - r.top}px`);
    });
  });

  /* ------------------------------------------------------------------ */
  /* Manifiesto: palabras destacadas (el texto se arma con la lluvia de datos) */
  /* ------------------------------------------------------------------ */

  const wordsEl = q('[data-words]');
  const HIGHLIGHT = ['moda.', 'negocio,', 'resultados.'];
  if (wordsEl) {
    const text = wordsEl.textContent.trim().split(/\s+/);
    wordsEl.textContent = '';
    text.forEach((w, i) => {
      const s = document.createElement('span');
      s.className = 'w on' + (HIGHLIGHT.includes(w) ? ' hl' : '');
      s.textContent = w;
      wordsEl.append(s, i < text.length - 1 ? ' ' : '');
    });
  }

  /* ------------------------------------------------------------------ */
  /* Títulos que se construyen con cuadraditos: una sola vez, al aparecer */
  /* ------------------------------------------------------------------ */

  const builds = qa('[data-build]');
  const showText = (el) => el.classList.add('tb-ready');
  if ('IntersectionObserver' in window && !reduce && window.DigitalLabTextBuild) {
    // se miden las letras con la tipografía ya cargada
    const fontsReady = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    fontsReady.then(() => {
      const bio = new IntersectionObserver((entries) => entries.forEach((e) => {
        if (!e.isIntersecting) return;
        bio.unobserve(e.target);
        showText(e.target);
        DigitalLabTextBuild.play(e.target);
      }), { rootMargin: '0px 0px -12% 0px', threshold: 0.3 });
      builds.forEach((el) => bio.observe(el));
    });
  } else {
    builds.forEach(showText);
  }

  function onScroll() {
    nav.classList.toggle('is-scrolled', scrollY > 20);
  }
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ------------------------------------------------------------------ */
  /* Hilos de luz por toda la página                                     */
  /* ------------------------------------------------------------------ */

  const svg = q('.flow');
  const NS = 'http://www.w3.org/2000/svg';
  const svgEl = (tag, attrs) => {
    const el = document.createElementNS(NS, tag);
    for (const k in attrs) el.setAttribute(k, attrs[k]);
    return el;
  };
  // Destellos como los del hero: cola fina con degradado continuo que se desvanece y cabeza de luz
  // con un halo suave. El degradado va de la cola a la cabeza y se recoloca en cada fotograma.
  const COMET = 120;          // largo de la cola
  const COMET_SPEED = 280;    // px por segundo
  const TINTS = ['#8ff3ff', '#b8ff7a', '#f8ff9c'];   // tono claro de cada hilo para la cola
  const COMET_LAYERS = [
    { len: COMET, w: 6, op: 0.22, tone: 'grad' },   // resplandor de la cola
    { len: COMET, w: 1.9, op: 1, tone: 'grad' },    // cola
    { len: 0, w: 9, op: 0.18, tone: 'line' },       // halo
    { len: 0, w: 5.5, op: 0.35, tone: 'tint' },
    { len: 0, w: 3, op: 1, tone: 'white' }          // cabeza
  ];
  const defs = svgEl('defs', {});
  svg.append(defs);
  const lines = COLORS.map((color, k) => {
    const base = { fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' };
    const glow = svgEl('path', { ...base, stroke: color, 'stroke-width': 7, 'stroke-opacity': 0.13 });
    const core = svgEl('path', { ...base, stroke: color, 'stroke-width': 1.5, 'stroke-opacity': 0.6 });
    const comets = [0, 1, 2].map((i) => {
      const grad = svgEl('linearGradient', { id: `comet-${k}-${i}`, gradientUnits: 'userSpaceOnUse' });
      [[0, TINTS[k], 0], [0.6, TINTS[k], 0.45], [0.9, TINTS[k], 0.85], [1, '#f6ffe8', 1]].forEach(([o, c, a]) => {
        grad.append(svgEl('stop', { offset: o, 'stop-color': c, 'stop-opacity': a }));
      });
      defs.append(grad);
      const tone = { grad: `url(#comet-${k}-${i})`, line: color, tint: TINTS[k], white: '#f6ffe8' };
      return {
        grad,
        active: false,
        pos: 0,           // distancia recorrida por la cabeza: solo aumenta mientras el destello vive
        layers: COMET_LAYERS.map((l) => ({
          ...l,
          el: svgEl('path', { ...base, stroke: tone[l.tone], 'stroke-width': l.w, 'stroke-opacity': l.op })
        }))
      };
    });
    svg.append(glow, core, ...comets.flatMap((c) => c.layers.map((l) => l.el)));
    return { k, glow, core, comets, total: 0, L: [], Y: [], drawn: 0, spawnIn: 0.4 + k * 0.5 };
  });
  let flowNodes = [];
  let panelLive = false;

  function docRect(el) {
    const r = el.getBoundingClientRect();
    return {
      l: r.left + scrollX, t: r.top + scrollY, r: r.right + scrollX, b: r.bottom + scrollY,
      w: r.width, h: r.height, cx: r.left + scrollX + r.width / 2
    };
  }

  // Curvas en S, siempre hacia abajo, entre puntos consecutivos
  function buildPath(pts) {
    for (let i = 1; i < pts.length; i++) pts[i][1] = Math.max(pts[i][1], pts[i - 1][1] + 1);
    let d = `M${pts[0][0].toFixed(1)} ${pts[0][1].toFixed(1)}`;
    for (let i = 1; i < pts.length; i++) {
      const [ax, ay] = pts[i - 1], [bx, by] = pts[i];
      if (Math.abs(bx - ax) < 0.5) {
        d += ` L${bx.toFixed(1)} ${by.toFixed(1)}`;
      } else {
        const k = clamp((by - ay) * 0.5, 20, 240);
        d += ` C${ax.toFixed(1)} ${(ay + k).toFixed(1)} ${bx.toFixed(1)} ${(by - k).toFixed(1)} ${bx.toFixed(1)} ${by.toFixed(1)}`;
      }
    }
    return d;
  }

  function layoutFlow() {
    const W = document.documentElement.clientWidth;
    svg.setAttribute('width', W);
    svg.setAttribute('height', 10);
    const H = document.documentElement.scrollHeight;
    svg.setAttribute('height', H);
    svg.style.width = `${W}px`;
    svg.style.height = `${H}px`;

    const mobile = W <= 860;
    // Carril del margen izquierdo en móvil: centrado en el margen de la página
    const padL = parseFloat(getComputedStyle(q('.services .wrap')).paddingLeft) || 16;
    const hero = docRect(q('.hero')), vis = docRect(q('.hero__visual'));
    const head = docRect(q('[data-flow-head]'));
    const cards = qa('[data-flow-card]').map(docRect);
    const grid = docRect(q('.services__grid'));
    const steps = docRect(q('[data-flow-steps]'));
    const projHead = docRect(q('.projects .section__head'));
    const car = docRect(q('[data-flow-carousel]'));
    const mani = docRect(q('[data-flow-manifesto]'));
    const panel = docRect(q('[data-flow-panel]'));

    // Puertos del panel de datos de la visita, donde termina cada hilo
    const ports = qa('[data-port]').map(docRect).map((p) => [p.cx, p.t + p.h / 2]);

    lines.forEach((ln, k) => {
      const off = k - 1;
      const x0 = vis.l + vis.w / 2 + off * vis.w * 0.165;   // mismos nodos que el hero
      const [ex, ey] = ports[k];
      let pts;
      if (mobile) {
        // Salen de los nodos (debajo del texto del hero) y se van al margen antes de la siguiente sección
        const lane = padL / 2 + off * 4;
        const nodeR = clamp(vis.w * 0.0105, 3.5, 6.5);
        const y0 = vis.t + vis.h * 0.8 + nodeR * 2.8;
        pts = [[x0, y0], [lane, hero.b - 4], [lane, panel.t - 90], [ex, ey - 50], [ex, ey]];
      } else {
        const cA = cards[k], cB = cards[k + 3];
        const bus = steps.l + 10 + off * 7;
        const laneR = Math.min(grid.r + 18, W - 12) + off * 6;
        const laneL = Math.max(grid.l - 36, 14) + off * 7;
        pts = [
          [x0, hero.b], [x0, head.b + 8],
          [cA.cx, cA.t], [cB.cx, cB.t], [cB.cx, grid.b + 40],
          [bus, steps.t - 40], [bus, steps.b + 30],
          [laneR, projHead.t], [laneR, car.b + 30],
          [laneL, mani.t + 60], [laneL, panel.t - 120],
          [ex, ey - 60], [ex, ey]
        ];
      }
      const d = buildPath(pts);
      for (const p of [ln.glow, ln.core]) p.setAttribute('d', d);
      for (const c of ln.comets) for (const l of c.layers) l.el.setAttribute('d', d);
      ln.total = ln.core.getTotalLength();
      ln.L = []; ln.Y = [];
      let maxY = -Infinity;
      for (let l = 0; l <= ln.total; l += 10) {
        maxY = Math.max(maxY, ln.core.getPointAtLength(l).y);
        ln.L.push(l); ln.Y.push(maxY);
      }
      for (const p of [ln.glow, ln.core]) p.style.strokeDasharray = `${ln.total} ${ln.total}`;
      for (const c of ln.comets) for (const l of c.layers) {
        l.el.style.strokeDasharray = `0 ${ln.total + 1000}`;
        l.el.style.strokeDashoffset = -(ln.total + 50);
      }
      if (reduce) ln.drawn = ln.total;
    });

    flowNodes = qa('[data-flow-node]').map((el) => {
      const r = docRect(el);
      return { el, line: Number(el.dataset.flowNode), y: r.t + r.h / 2 };
    });
  }

  // Longitud de hilo dibujada hasta una altura de la página
  function lengthAtY(ln, y) {
    const Y = ln.Y;
    if (!Y.length || y <= Y[0]) return 0;
    if (y >= Y[Y.length - 1]) return ln.total;
    let lo = 0, hi = Y.length - 1;
    while (hi - lo > 1) { const m = (lo + hi) >> 1; if (Y[m] < y) lo = m; else hi = m; }
    return ln.L[hi];
  }
  function yAtLength(ln, l) {
    const i = clamp(Math.round(l / 10), 0, ln.Y.length - 1);
    return ln.Y[i] ?? 0;
  }

  function updateFlow(dt, t) {
    const tip = scrollY + innerHeight * 0.75;
    for (const ln of lines) {
      if (!ln.total) continue;
      const target = reduce ? ln.total : lengthAtY(ln, tip);
      ln.drawn += (target - ln.drawn) * (1 - Math.exp(-dt * 5));
      const off = ln.total - ln.drawn;
      ln.glow.style.strokeDashoffset = off;
      ln.core.style.strokeDashoffset = off;
      // Destellos: nacen justo encima de la pantalla y siempre avanzan hacia la punta del hilo,
      // aunque el hilo se acorte o alargue con el scroll. Se reciclan al salir por abajo o apagarse.
      const show = !reduce && ln.drawn > 80;
      const viewEnd = lengthAtY(ln, scrollY + innerHeight + 200);
      ln.spawnIn -= dt;
      if (show && ln.spawnIn <= 0) {
        ln.spawnIn = 1.3 + Math.random() * 1.6;
        const start = lengthAtY(ln, scrollY - 60);
        const free = ln.comets.find((c) => !c.active);
        const crowded = ln.comets.some((c) => c.active && Math.abs(c.pos - start) < 260);
        if (free && !crowded && start < ln.drawn - 60) { free.active = true; free.pos = start; }
      }
      ln.comets.forEach((c) => {
        if (c.active) {
          c.pos += dt * COMET_SPEED;
          if (!show || c.pos - COMET > ln.drawn || c.pos - COMET > viewEnd) c.active = false;
        }
        const head = c.pos;
        // La cola va de (cabeza - largo) a la cabeza, recortada en la punta del hilo:
        // al llegar al final el destello se apaga dentro del hilo en vez de salirse
        const s = Math.max(0, head - COMET), e = Math.min(head, ln.drawn);
        const vis = show && c.active;
        if (vis && e - s > 0.5) {
          const a = ln.core.getPointAtLength(s), b = ln.core.getPointAtLength(Math.min(head, ln.total));
          c.grad.setAttribute('x1', a.x.toFixed(1)); c.grad.setAttribute('y1', a.y.toFixed(1));
          c.grad.setAttribute('x2', b.x.toFixed(1)); c.grad.setAttribute('y2', b.y.toFixed(1));
        }
        for (const l of c.layers) {
          const on = vis && (l.len ? e - s > 0.5 : head <= ln.drawn);
          l.el.style.strokeDasharray = on ? `${l.len ? e - s : 0.01} ${ln.total + COMET * 2}` : `0 ${ln.total + 1000}`;
          l.el.style.strokeDashoffset = on ? -(l.len ? s : head) : -(ln.total + 50);
        }
      });
      ln.tipY = yAtLength(ln, ln.drawn);
    }
    for (const n of flowNodes) {
      const ln = lines[n.line];
      const lit = !!ln && (ln.tipY >= n.y - 4 || (ln.total > 0 && ln.drawn > ln.total - 4));
      if (lit !== n.lit) { n.lit = lit; n.el.classList.toggle('is-lit', lit); }
    }
    // Cuando los tres hilos llegan al panel, los datos de la visita pasan a «en vivo»
    if (!panelLive && lines.every((ln) => ln.total && ln.drawn > ln.total - 4)) {
      panelLive = true;
      Visit.goLive();
    }
  }

  /* ------------------------------------------------------------------ */
  /* Animaciones de cada servicio                                        */
  /* ------------------------------------------------------------------ */

  const mono = (px, weight = 500) => `${weight} ${px}px "JetBrains Mono", ui-monospace, monospace`;
  function glowDot(c, x, y, r, color, a) {
    const g = c.createRadialGradient(x, y, 0, x, y, r * 4);
    g.addColorStop(0, color); g.addColorStop(1, 'rgba(0,0,0,0)');
    c.globalAlpha = a * 0.5; c.fillStyle = g; c.fillRect(x - r * 4, y - r * 4, r * 8, r * 8);
    c.globalAlpha = a; c.fillStyle = color; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill();
    c.globalAlpha = 1;
  }
  function pill(c, x, y, w, h, r) {
    c.beginPath();
    c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath();
  }
  function check(c, x, y, r, bg) {
    c.fillStyle = bg; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill();
    c.strokeStyle = '#061204'; c.lineWidth = 1.6; c.lineCap = 'round';
    c.beginPath(); c.moveTo(x - r * 0.45, y); c.lineTo(x - r * 0.1, y + r * 0.35); c.lineTo(x + r * 0.5, y - r * 0.35); c.stroke();
  }

  const MINI = {
    // Radar de oportunidades: el barrido descubre casos de uso
    consultoria(c, w, h, t, s) {
      if (!s.blips) {
        s.blips = ['Atención al cliente', 'Previsión de demanda', 'Documentos', 'Control de calidad', 'Ventas', 'Mantenimiento']
          .map((label, i) => ({ label, a: (i / 6) * TAU + 0.35, r: [0.55, 0.85, 0.4, 0.75, 0.62, 0.9][i] }));
      }
      const cx = w * 0.5, cy = h * 0.53, R = Math.min(w * 0.4, h * 0.44);
      c.strokeStyle = 'rgba(34,211,238,0.14)'; c.lineWidth = 1;
      for (let i = 1; i <= 4; i++) { c.beginPath(); c.arc(cx, cy, (R * i) / 4, 0, TAU); c.stroke(); }
      c.beginPath(); c.moveTo(cx - R, cy); c.lineTo(cx + R, cy); c.moveTo(cx, cy - R); c.lineTo(cx, cy + R); c.stroke();
      const a = (t * 1.2) % TAU;
      if (c.createConicGradient) {
        const g = c.createConicGradient(a - 1.2, cx, cy);
        g.addColorStop(0, 'rgba(34,211,238,0)');
        g.addColorStop(1.2 / TAU, 'rgba(34,211,238,0.3)');
        g.addColorStop(1.2 / TAU + 0.002, 'rgba(34,211,238,0)');
        g.addColorStop(1, 'rgba(34,211,238,0)');
        c.fillStyle = g; c.beginPath(); c.arc(cx, cy, R, 0, TAU); c.fill();
      }
      c.strokeStyle = 'rgba(34,211,238,0.9)'; c.lineWidth = 1.5;
      c.beginPath(); c.moveTo(cx, cy); c.lineTo(cx + Math.cos(a) * R, cy + Math.sin(a) * R); c.stroke();
      c.font = mono(10);
      for (const b of s.blips) {
        const since = (((a - b.a) % TAU) + TAU) % TAU;
        const e = Math.exp(-since * 0.8);
        const x = cx + Math.cos(b.a) * R * b.r, y = cy + Math.sin(b.a) * R * b.r;
        glowDot(c, x, y, 2.4 + e * 1.8, '#22d3ee', 0.35 + e * 0.65);
        if (e > 0.12) {
          const tw = c.measureText(b.label).width, tx = clamp(x + 9, 6, w - tw - 6), ty = clamp(y - 7, 14, h - 6);
          c.globalAlpha = Math.min(1, e * 1.5); c.fillStyle = '#d6f7ff'; c.fillText(b.label, tx, ty); c.globalAlpha = 1;
        }
      }
    },

    // El agente (un ojo, como el logo) consulta herramientas por turnos y las mira
    agentes(c, w, h, t) {
      const cx = w * 0.5, cy = h * 0.54, R = Math.min(w * 0.34, h * 0.36);
      const tools = ['CRM', 'Correo', 'ERP', 'Web', 'Docs'];
      const pos = tools.map((_, i) => { const a = -Math.PI / 2 + (i / 5) * TAU; return [cx + Math.cos(a) * R * 1.3, cy + Math.sin(a) * R]; });
      const period = 1.6, k = Math.floor(t / period) % 5, p = (t % period) / period;
      pos.forEach(([x, y], i) => {
        c.strokeStyle = i === k ? 'rgba(90,212,28,0.55)' : 'rgba(255,255,255,0.09)';
        c.setLineDash(i === k ? [] : [3, 4]); c.lineWidth = 1;
        c.beginPath(); c.moveTo(cx, cy); c.lineTo(x, y); c.stroke();
      });
      c.setLineDash([]);
      const [tx, ty] = pos[k];
      if (p < 0.42) { const e = ease(p / 0.42); glowDot(c, lerp(cx, tx, e), lerp(cy, ty, e), 3, '#5ad41c', 1); }
      else if (p > 0.58) { const e = ease((p - 0.58) / 0.42); glowDot(c, lerp(tx, cx, e), lerp(ty, cy, e), 3, '#e6f23a', 1); }
      c.font = mono(10);
      pos.forEach(([x, y], i) => {
        const on = i === k && p > 0.36 && p < 0.7, tw = c.measureText(tools[i]).width, bw = tw + 18, bh = 22;
        pill(c, x - bw / 2, y - bh / 2, bw, bh, 11);
        c.fillStyle = on ? 'rgba(90,212,28,0.2)' : '#0b1316'; c.fill();
        c.strokeStyle = on ? '#5ad41c' : 'rgba(255,255,255,0.14)'; c.lineWidth = 1; c.stroke();
        c.fillStyle = on ? '#eaffd6' : 'rgba(238,244,240,0.62)'; c.fillText(tools[i], x - tw / 2, y + 3.5);
      });
      // Ojo del agente: mira hacia la herramienta activa
      const dx = tx - cx, dy = ty - cy, dl = Math.hypot(dx, dy) || 1;
      glowDot(c, cx, cy, 6, '#5ad41c', 0.25);
      c.fillStyle = '#121a1d'; c.beginPath(); c.arc(cx, cy, 17, 0, TAU); c.fill();
      c.strokeStyle = '#5ad41c'; c.lineWidth = 2; c.beginPath(); c.arc(cx, cy, 22, 0, TAU); c.stroke();
      const ix = cx + (dx / dl) * 5, iy = cy + (dy / dl) * 5;
      c.fillStyle = '#7dff3a'; c.beginPath(); c.arc(ix, iy, 6.5, 0, TAU); c.fill();
      c.fillStyle = '#ffffff'; c.beginPath(); c.arc(ix + 2.2, iy - 2.2, 2.2, 0, TAU); c.fill();
    },

    // Un documento recorre el flujo y cada paso se marca como hecho
    automatizacion(c, w, h, t) {
      const labels = ['Entrada', 'Extraer', 'Validar', 'Enviar'];
      const pad = w * 0.06, gap = w * 0.045, bw = (w - pad * 2 - gap * 3) / 4, bh = Math.min(h * 0.3, 54), y = h * 0.44 - bh / 2;
      const cycle = 4.4, tt = t % cycle, prog = Math.min(1, tt / 3.2) * 4;
      const files = ['factura_0384.pdf', 'albaran_112.pdf', 'pedido_2291.csv'];
      for (let i = 0; i < 3; i++) {
        const x1 = pad + (i + 1) * bw + i * gap;
        c.strokeStyle = prog > i + 1 ? 'rgba(90,212,28,0.8)' : 'rgba(255,255,255,0.12)'; c.lineWidth = 1.5;
        c.beginPath(); c.moveTo(x1, y + bh / 2); c.lineTo(x1 + gap, y + bh / 2); c.stroke();
      }
      c.font = mono(Math.max(9, Math.min(11, bw / 7)));
      labels.forEach((label, i) => {
        const x = pad + i * (bw + gap), done = prog > i + 0.7, active = prog > i && prog <= i + 1;
        pill(c, x, y, bw, bh, 10);
        c.fillStyle = done ? 'rgba(90,212,28,0.12)' : '#0b1316'; c.fill();
        c.strokeStyle = active ? '#e6f23a' : done ? 'rgba(90,212,28,0.6)' : 'rgba(255,255,255,0.12)'; c.lineWidth = 1.2; c.stroke();
        const tw = c.measureText(label).width;
        c.fillStyle = done || active ? '#eaffd6' : 'rgba(238,244,240,0.55)'; c.fillText(label, x + bw / 2 - tw / 2, y + bh / 2 + 4);
        if (done) check(c, x + bw - 4, y + 4, 7, '#b8f53c');
      });
      if (tt < 3.3) glowDot(c, pad + bw / 2 + (prog / 4) * (w - pad * 2 - bw), y + bh / 2, 3.4, '#e6f23a', 1);
      const name = files[Math.floor(t / cycle) % files.length], allDone = tt > 3.3;
      c.font = mono(11);
      const msg = allDone ? `${name}  →  ERP  ✓` : `${name}  →  procesando…`;
      const mw = c.measureText(msg).width;
      c.fillStyle = allDone ? '#b8f53c' : 'rgba(238,244,240,0.5)'; c.fillText(msg, w / 2 - mw / 2, h * 0.82);
    },

    // Código que se escribe solo
    software(c, w, h, t, s) {
      const K = '#22d3ee', F = '#e6f23a', S = '#5ad41c', I = 'rgba(238,244,240,0.85)', P = 'rgba(238,244,240,0.45)';
      if (!s.code) {
        s.code = [
          [['const ', K], ['agente', I], [' = ', P], ['crearAgente', F], ['({', P]],
          [['  modelo: ', P], ["'llm'", S], [',', P]],
          [['  herramientas: ', P], ['[crm, correo]', I], [',', P]],
          [['});', P]],
          [['await ', K], ['agente', I], ['.', P], ['resolver', F], ['(ticket);', P]]
        ];
        s.total = s.code.flat().reduce((n, [txt]) => n + txt.length, 0);
      }
      c.fillStyle = 'rgba(255,255,255,0.04)'; c.fillRect(0, 0, w, 26);
      COLORS.forEach((col, i) => { c.fillStyle = col; c.beginPath(); c.arc(16 + i * 13, 13, 3.5, 0, TAU); c.fill(); });
      c.font = mono(10); c.fillStyle = 'rgba(238,244,240,0.5)'; c.fillText('agente.ts', 64, 17);
      const fs = clamp(w / 30, 10, 12.5), lh = fs * 1.75, x0 = 16 + fs * 2.2, y0 = 26 + lh;
      const tt = t % 9, shown = Math.floor(Math.min(1, tt / 5.5) * s.total);
      c.font = mono(fs);
      let left = shown, cx = x0, cyy = y0;
      s.code.forEach((line, r) => {
        const y = y0 + r * lh;
        c.fillStyle = 'rgba(238,244,240,0.2)'; c.fillText(String(r + 1), 14, y);
        let x = x0;
        for (const [txt, col] of line) {
          if (left <= 0) break;
          const part = txt.slice(0, left);
          c.fillStyle = col; c.fillText(part, x, y);
          x += c.measureText(part).width; left -= part.length;
          cx = x; cyy = y;
        }
      });
      if (Math.floor(t * 2) % 2 === 0 || shown < s.total) { c.fillStyle = '#e6f23a'; c.fillRect(cx + 1, cyy - fs * 0.85, 2, fs * 1.05); }
      if (tt > 6) {
        c.globalAlpha = Math.min(1, (tt - 6) * 3);
        c.fillStyle = '#b8f53c'; c.fillText('✓ ticket resuelto', x0, y0 + s.code.length * lh + lh * 0.4);
        c.globalAlpha = 1;
      }
    },

    // Objetos que pasan por la cámara con sus cajas de detección
    vision(c, w, h, t) {
      c.strokeStyle = 'rgba(255,255,255,0.04)'; c.lineWidth = 1;
      for (let x = 0; x < w; x += 22) { c.beginPath(); c.moveTo(x, 0); c.lineTo(x, h); c.stroke(); }
      for (let y = 0; y < h; y += 22) { c.beginPath(); c.moveTo(0, y); c.lineTo(w, y); c.stroke(); }
      c.fillStyle = 'rgba(34,211,238,0.06)'; c.fillRect(0, h * 0.78, w, h * 0.22);
      const objs = [
        { kind: 'circle', label: 'pieza', conf: '0.98', col: '#b8f53c', sp: 0.32, yy: 0.52, sz: 0.15, ph: 0 },
        { kind: 'box', label: 'caja', conf: '0.95', col: '#b8f53c', sp: 0.32, yy: 0.5, sz: 0.19, ph: 1.15 },
        { kind: 'circle', label: 'defecto', conf: '0.88', col: '#ffb03c', sp: 0.32, yy: 0.56, sz: 0.11, ph: 2.3 }
      ];
      const span = w + 160;
      c.font = mono(9.5, 700);
      for (const o of objs) {
        const x = ((t * o.sp * w * 0.35 + (o.ph / 3.45) * span) % span) - 80, y = h * o.yy + Math.sin(t * 1.4 + o.ph) * 4;
        const r = Math.min(w, h) * o.sz;
        const g = c.createLinearGradient(x - r, y - r, x + r, y + r);
        g.addColorStop(0, '#3b5b63'); g.addColorStop(1, '#0d171a');
        c.fillStyle = g;
        if (o.kind === 'circle') { c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); }
        else { pill(c, x - r, y - r, r * 2, r * 2, 6); c.fill(); }
        const p = r + 7, L = 9;
        c.strokeStyle = o.col; c.lineWidth = 2;
        [[-1, -1], [1, -1], [1, 1], [-1, 1]].forEach(([sx, sy]) => {
          const bx = x + sx * p, by = y + sy * p;
          c.beginPath(); c.moveTo(bx, by - sy * L); c.lineTo(bx, by); c.lineTo(bx - sx * L, by); c.stroke();
        });
        const label = `${o.label} ${o.conf}`, tw = c.measureText(label).width;
        c.fillStyle = o.col; pill(c, x - p, y - p - 19, tw + 12, 16, 4); c.fill();
        c.fillStyle = '#061204'; c.fillText(label, x - p + 6, y - p - 7.5);
      }
      const sy = ((t * 0.45) % 1) * h;
      const sg = c.createLinearGradient(0, sy - 18, 0, sy + 2);
      sg.addColorStop(0, 'rgba(125,255,58,0)'); sg.addColorStop(1, 'rgba(125,255,58,0.22)');
      c.fillStyle = sg; c.fillRect(0, sy - 18, w, 20);
      c.fillStyle = 'rgba(200,255,150,0.7)'; c.fillRect(0, sy, w, 1.2);
    },

    // Suelo en malla que avanza y un cubo en 3D que gira
    xr(c, w, h, t) {
      const hz = h * 0.56, vpx = w / 2;
      const sky = c.createLinearGradient(0, 0, 0, hz); sky.addColorStop(0, 'rgba(0,0,0,0)'); sky.addColorStop(1, 'rgba(90,212,28,0.14)');
      c.fillStyle = sky; c.fillRect(0, 0, w, hz);
      for (let i = 0; i < 40; i++) { const x = (i * 97.3) % w, y = (i * 53.7) % (hz * 0.9); c.fillStyle = `rgba(220,255,240,${0.2 + ((i * 7) % 5) / 10})`; c.fillRect(x, y, 1.2, 1.2); }
      c.strokeStyle = 'rgba(90,212,28,0.9)'; c.lineWidth = 1.2;
      c.beginPath(); c.moveTo(0, hz); c.lineTo(w, hz); c.stroke();
      const off = (t * 0.6) % 1;
      for (let i = 0; i < 12; i++) {
        const z = (i + off) / 12, y = hz + (h - hz) * Math.pow(z, 2.2);
        c.strokeStyle = `rgba(90,212,28,${0.12 + z * 0.55})`; c.lineWidth = 0.6 + z * 1.2;
        c.beginPath(); c.moveTo(0, y); c.lineTo(w, y); c.stroke();
      }
      c.strokeStyle = 'rgba(20,196,164,0.4)'; c.lineWidth = 1;
      for (let i = -12; i <= 12; i++) { c.beginPath(); c.moveTo(vpx + i * 5, hz); c.lineTo(vpx + i * w * 0.11, h); c.stroke(); }
      // Cubo
      const sz = Math.min(w, h) * 0.17, cx = vpx, cy = hz - h * 0.2, ay = t * 0.7, ax = 0.5 + Math.sin(t * 0.4) * 0.2;
      const V = [];
      for (const X of [-1, 1]) for (const Y of [-1, 1]) for (const Z of [-1, 1]) {
        const x1 = X * Math.cos(ay) + Z * Math.sin(ay), z1 = -X * Math.sin(ay) + Z * Math.cos(ay);
        const y1 = Y * Math.cos(ax) - z1 * Math.sin(ax), z2 = Y * Math.sin(ax) + z1 * Math.cos(ax);
        const p = 4 / (4 + z2);
        V.push([cx + x1 * sz * p, cy + y1 * sz * p]);
      }
      glowDot(c, cx, cy, sz * 0.5, '#5ad41c', 0.25);
      c.strokeStyle = 'rgba(220,255,180,0.95)'; c.lineWidth = 1.4;
      for (let i = 0; i < 8; i++) for (let j = i + 1; j < 8; j++) {
        const diff = (i ^ j);
        if (diff === 1 || diff === 2 || diff === 4) { c.beginPath(); c.moveTo(...V[i]); c.lineTo(...V[j]); c.stroke(); }
      }
      V.forEach(([x, y]) => { c.fillStyle = '#ffffff'; c.beginPath(); c.arc(x, y, 1.8, 0, TAU); c.fill(); });
    }
  };

  const minis = qa('canvas[data-mini]').map((cv) => ({ cv, ctx: cv.getContext('2d'), fn: MINI[cv.dataset.mini], st: {}, w: 0, h: 0, dpr: 1, vis: false }));
  function sizeMinis() {
    for (const m of minis) {
      const r = m.cv.getBoundingClientRect();
      m.dpr = Math.min(2, window.devicePixelRatio || 1);
      m.w = r.width; m.h = r.height;
      m.cv.width = Math.max(1, Math.round(r.width * m.dpr));
      m.cv.height = Math.max(1, Math.round(r.height * m.dpr));
    }
  }
  function drawMini(m, t) {
    if (!m.fn || !m.w) return;
    const c = m.ctx;
    c.setTransform(m.dpr, 0, 0, m.dpr, 0, 0);
    c.clearRect(0, 0, m.w, m.h);
    m.fn(c, m.w, m.h, t, m.st);
  }
  if ('IntersectionObserver' in window) {
    const mio = new IntersectionObserver((entries) => entries.forEach((e) => {
      const m = minis.find((x) => x.cv === e.target);
      if (m) m.vis = e.isIntersecting;
    }));
    minis.forEach((m) => mio.observe(m.cv));
  } else {
    minis.forEach((m) => { m.vis = true; });
  }

  /* ------------------------------------------------------------------ */
  /* Datos de la visita: se calculan en el navegador y no se envían      */
  /* ------------------------------------------------------------------ */

  const Visit = (() => {
    const panelEl = q('[data-flow-panel]');
    const $ = (k) => q(`[data-stat="${k}"]`, panelEl);
    const pad = (n) => String(n).padStart(2, '0');
    const start = performance.now();
    const BUCKETS = 30;                          // 30 barras de 2 s = último minuto
    const activity = new Array(BUCKETS).fill(0);
    const sections = qa('main > section');
    const seen = new Set();
    const coarse = window.matchMedia('(pointer: coarse)').matches;
    let clicks = 0, maxScroll = 0, travel = 0, lastX = null, lastY = null, lastScroll = scrollY;

    // Visitas: contador guardado en el propio navegador, una por sesión
    let visits = 1, first = Date.now();
    try {
      const saved = JSON.parse(localStorage.getItem('dl-visitas') || 'null') || { count: 0, first: Date.now() };
      if (!sessionStorage.getItem('dl-sesion')) {
        saved.count += 1;
        sessionStorage.setItem('dl-sesion', '1');
        localStorage.setItem('dl-visitas', JSON.stringify(saved));
      }
      visits = Math.max(1, saved.count);
      first = saved.first;
    } catch (e) { /* sin almacenamiento disponible: se muestra como primera visita */ }

    // Ubicación aproximada: ciudad y país de la zona horaria; si no se reconoce, país del idioma del navegador
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const city = tz.includes('/') ? tz.split('/').pop().replace(/_/g, ' ') : '';
    const TZ_COUNTRY = {
      'America/Argentina': 'AR', 'America/Buenos_Aires': 'AR', 'America/Cordoba': 'AR', 'America/Mendoza': 'AR',
      'America/Mexico_City': 'MX', 'America/Monterrey': 'MX', 'America/Merida': 'MX', 'America/Cancun': 'MX',
      'America/Chihuahua': 'MX', 'America/Hermosillo': 'MX', 'America/Mazatlan': 'MX', 'America/Tijuana': 'MX',
      'America/Bogota': 'CO', 'America/Santiago': 'CL', 'America/Punta_Arenas': 'CL', 'America/Lima': 'PE',
      'America/Montevideo': 'UY', 'America/Asuncion': 'PY', 'America/La_Paz': 'BO', 'America/Guayaquil': 'EC',
      'America/Caracas': 'VE', 'America/Panama': 'PA', 'America/Costa_Rica': 'CR', 'America/Guatemala': 'GT',
      'America/El_Salvador': 'SV', 'America/Tegucigalpa': 'HN', 'America/Managua': 'NI', 'America/Havana': 'CU',
      'America/Santo_Domingo': 'DO', 'America/Puerto_Rico': 'PR', 'America/Sao_Paulo': 'BR', 'America/Toronto': 'CA',
      'America/New_York': 'US', 'America/Chicago': 'US', 'America/Denver': 'US', 'America/Los_Angeles': 'US', 'America/Phoenix': 'US',
      'Europe/Madrid': 'ES', 'Atlantic/Canary': 'ES', 'Africa/Ceuta': 'ES', 'Europe/Lisbon': 'PT', 'Europe/London': 'GB',
      'Europe/Paris': 'FR', 'Europe/Berlin': 'DE', 'Europe/Rome': 'IT', 'Europe/Amsterdam': 'NL', 'Europe/Zurich': 'CH'
    };
    const lang = navigator.language || 'es';
    const langRegion = (lang.split('-')[1] || '').toUpperCase();
    const region = TZ_COUNTRY[tz] || TZ_COUNTRY[tz.split('/').slice(0, 2).join('/')]
      || (/^[A-Z]{2}$/.test(langRegion) ? langRegion : '');
    let country = '', langName = lang;
    try {
      if (region) country = new Intl.DisplayNames(['es'], { type: 'region' }).of(region);
      langName = new Intl.DisplayNames(['es'], { type: 'language' }).of(lang);
    } catch (e) { /* navegador sin Intl.DisplayNames */ }
    const offset = -new Date().getTimezoneOffset() / 60;
    const gmt = `GMT${offset >= 0 ? '+' : ''}${offset}`;

    // Dispositivo y navegador
    const ua = navigator.userAgent;
    const os = /Windows/.test(ua) ? 'Windows' : /iPhone|iPad|iPod/.test(ua) ? 'iOS' : /Mac OS X/.test(ua) ? 'macOS'
      : /Android/.test(ua) ? 'Android' : /Linux/.test(ua) ? 'Linux' : 'Otro sistema';
    const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox'
      : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Otro navegador';
    const type = coarse ? (Math.min(screen.width, screen.height) >= 700 ? 'Tablet' : 'Móvil') : 'Computadora';

    // De dónde llegó
    let ref = 'Acceso directo';
    try { if (document.referrer) ref = new URL(document.referrer).hostname.replace(/^www\./, ''); } catch (e) { /* sin origen */ }

    $('place').textContent = city || 'Desconocida';
    $('country').textContent = [country, gmt].filter(Boolean).join(' · ');
    $('lang').textContent = langName.charAt(0).toUpperCase() + langName.slice(1);
    $('device').textContent = type;
    $('screen').textContent = [os, browser, screen.width ? `${screen.width}×${screen.height}` : ''].filter(Boolean).join(' · ');
    $('ref').textContent = ref;
    $('visits').textContent = `${visits}ª`;
    $('first').textContent = visits > 1
      ? `Primera: ${new Date(first).toLocaleDateString('es', { day: 'numeric', month: 'short' })}`
      : 'Primera vez aquí';

    const barsEl = q('[data-bars]', panelEl);
    const bars = activity.map(() => barsEl.appendChild(document.createElement('i')));
    const bump = (el) => { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); };

    document.addEventListener('click', () => {
      clicks++;
      activity[BUCKETS - 1] += 6;
      const el = $('clicks');
      el.textContent = clicks;
      bump(el);
    }, true);
    addEventListener('scroll', () => {
      const max = document.documentElement.scrollHeight - innerHeight;
      maxScroll = Math.max(maxScroll, max > 0 ? scrollY / max : 1);
      if (coarse) travel += Math.abs(scrollY - lastScroll);
      lastScroll = scrollY;
      activity[BUCKETS - 1] += 0.4;
    }, { passive: true });
    addEventListener('pointermove', (e) => {
      if (e.pointerType !== 'mouse') return;
      if (lastX != null) travel += Math.hypot(e.clientX - lastX, e.clientY - lastY);
      lastX = e.clientX; lastY = e.clientY;
      activity[BUCKETS - 1] += 0.05;
    }, { passive: true });
    addEventListener('keydown', () => { activity[BUCKETS - 1] += 1; });
    if ('IntersectionObserver' in window) {
      // Cuenta como vista cuando la sección cruza la franja central de la pantalla
      const sio = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) seen.add(en.target); }), { rootMargin: '-35% 0px -35% 0px' });
      sections.forEach((s) => sio.observe(s));
    }

    function tick() {
      const sec = Math.floor((performance.now() - start) / 1000);
      const h = Math.floor(sec / 3600), m = Math.floor(sec / 60) % 60, s = sec % 60;
      $('time').textContent = h ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
      const pct = Math.round(maxScroll * 100);
      $('scroll').textContent = `${pct}%`;
      $('scrollbar').style.width = `${pct}%`;
      $('sections').textContent = `${seen.size} de ${sections.length} secciones vistas`;
      const now = new Date();
      $('clock').textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
      // 3780 px ≈ 1 metro en una pantalla estándar
      $('cursor').textContent = `${coarse ? 'Desplazado' : 'Cursor'}: ${(travel / 3780).toFixed(1).replace('.', ',')} m`;
      const peak = Math.max(4, ...activity);
      bars.forEach((b, i) => { b.style.height = `${Math.max(3, (activity[i] / peak) * 100)}%`; });
    }
    tick();
    setInterval(tick, 500);
    setInterval(() => { activity.shift(); activity.push(0); }, 2000);

    return {
      goLive() {
        panelEl.classList.add('is-live');
        q('[data-live]', panelEl).textContent = 'En vivo';
      }
    };
  })();

  /* ------------------------------------------------------------------ */
  /* Carrusel                                                            */
  /* ------------------------------------------------------------------ */

  function initCarousel(root) {
    const slides = qa('.slide', root), tabs = qa('.carousel__tab', root);
    const count = q('.carousel__count b', root), scan = q('.carousel__scan', root), view = q('.carousel__viewport', root);
    const N = slides.length, DUR = 6500;
    let i = 0, elapsed = 0, last = performance.now(), hover = false, visible = true, x0 = null;

    function go(n) {
      n = (n + N) % N;
      if (n === i) return;
      root.classList.add('is-ready');
      slides.forEach((s) => s.classList.remove('is-prev'));
      slides[i].classList.remove('is-active');
      slides[i].classList.add('is-prev');
      const s = slides[n];
      void s.offsetWidth;
      s.classList.add('is-active');
      tabs[i].classList.remove('is-active');
      tabs[i].setAttribute('aria-selected', 'false');
      tabs[i].style.setProperty('--p', 0);
      tabs[n].classList.add('is-active');
      tabs[n].setAttribute('aria-selected', 'true');
      i = n; elapsed = 0;
      count.textContent = String(i + 1).padStart(2, '0');
      if (!reduce) { scan.classList.remove('is-running'); void scan.offsetWidth; scan.classList.add('is-running'); }
    }

    tabs.forEach((tab, k) => tab.addEventListener('click', () => go(k)));
    qa('.carousel__arrow', root).forEach((b) => b.addEventListener('click', () => go(i + Number(b.dataset.dir))));
    root.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { go(i + 1); e.preventDefault(); }
      if (e.key === 'ArrowLeft') { go(i - 1); e.preventDefault(); }
    });
    root.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') hover = true; });
    root.addEventListener('pointerleave', () => { hover = false; });
    root.addEventListener('focusin', () => { hover = true; });
    root.addEventListener('focusout', () => { hover = false; });
    view.addEventListener('pointerdown', (e) => { x0 = e.clientX; });
    view.addEventListener('pointerup', (e) => {
      if (x0 == null) return;
      const dx = e.clientX - x0;
      if (Math.abs(dx) > 50) go(i + (dx < 0 ? 1 : -1));
      x0 = null;
    });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([e]) => { visible = e.isIntersecting; }).observe(root);
    }

    return function tick(now) {
      const dt = now - last;
      last = now;
      if (reduce || hover || !visible) return;
      elapsed += dt;
      tabs[i].style.setProperty('--p', Math.min(1, elapsed / DUR));
      if (elapsed >= DUR) go(i + 1);
    };
  }
  const carouselEl = q('[data-carousel]');
  const carouselTick = carouselEl ? initCarousel(carouselEl) : () => {};

  /* ------------------------------------------------------------------ */
  /* Formulario (maqueta: todavía no envía)                              */
  /* ------------------------------------------------------------------ */

  const form = q('[data-form]');
  if (form) {
    const note = q('[data-form-note]', form);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      // Campo trampa relleno: lo envió un robot, se descarta sin avisar
      if (form.elements.web && form.elements.web.value) return;
      let ok = true;
      qa('[required]', form).forEach((f) => {
        const bad = f.type === 'checkbox'
          ? !f.checked
          : !f.value.trim() || (f.type === 'email' && !/^\S+@\S+\.\S+$/.test(f.value.trim()));
        f.closest('.field').classList.toggle('is-invalid', bad);
        if (bad) ok = false;
      });
      note.hidden = false;
      note.textContent = ok
        ? 'Este formulario es una maqueta y todavía no envía mensajes. Cuando lo conectes a tu correo o CRM, aquí aparecerá la confirmación.'
        : 'Revisa los campos marcados: nombre, un email válido, tu mensaje y la aceptación de la política de privacidad.';
    });
  }

  /* ------------------------------------------------------------------ */
  /* WhatsApp                                                            */
  /* ------------------------------------------------------------------ */

  // Pon aquí tu número con prefijo de país y sin espacios ni «+», p. ej. '5491123456789'
  const WHATSAPP_NUMBER = '5493496532749';   // +54 9 3496 532749 (en Argentina los móviles llevan el 9 tras el 54)
  const WHATSAPP_MESSAGE = 'Hola Digital Lab, me gustaría hablar sobre un proyecto.';
  const waLink = `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(WHATSAPP_MESSAGE)}`;
  qa('[data-wa-link]').forEach((a) => { a.href = waLink; });

  // El texto asoma solo de vez en cuando para no distraer
  const wa = q('[data-wa]');
  if (wa && !reduce) {
    setInterval(() => {
      if (document.hidden) return;
      wa.classList.add('is-peek');
      setTimeout(() => wa.classList.remove('is-peek'), 3200);
    }, 9000);
  }

  /* ------------------------------------------------------------------ */
  /* Bucle y maquetación                                                 */
  /* ------------------------------------------------------------------ */

  let layoutRaf = 0;
  function relayout() {
    cancelAnimationFrame(layoutRaf);
    layoutRaf = requestAnimationFrame(() => {
      sizeMinis();
      layoutFlow();
      if (reduce) { updateFlow(1, 0); minis.forEach((m) => drawMini(m, 3.5)); }
    });
  }
  sizeMinis();
  layoutFlow();
  new ResizeObserver(relayout).observe(document.body);
  addEventListener('load', relayout);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(relayout);

  const T0 = performance.now();
  let lastNow = T0;
  if (reduce) {
    updateFlow(1, 0);
    minis.forEach((m) => drawMini(m, 3.5));
  }
  function frame(now) {
    requestAnimationFrame(frame);
    const dt = Math.min(0.05, (now - lastNow) / 1000);
    lastNow = now;
    const t = (now - T0) / 1000;
    updateFlow(dt, t);
    if (!reduce) for (const m of minis) if (m.vis) drawMini(m, t);
    carouselTick(now);
  }
  requestAnimationFrame(frame);
})();
