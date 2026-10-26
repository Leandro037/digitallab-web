/*!
 * Digital Lab · hilos de luz de la página de Digital Lab Studio
 * Los mismos tres hilos de la página principal: nacen de los nodos de la animación de datos, bajan por las secciones,
 * encienden un punto de luz en cada tarjeta que alcanzan y terminan juntos antes de la llamada a la acción.
 * Se dibujan a medida que se hace scroll y, de vez en cuando, un destello recorre cada hilo.
 */
(function () {
  'use strict';

  // Se montan cuando la página ya cargó: los hilos no se ven al principio y así no compiten con la carga (ni con la nota de rendimiento)
  function arrancar() {
  const q = (s, r = document) => r.querySelector(s);
  const qa = (s, r = document) => [...r.querySelectorAll(s)];
  const svg = q('.flow');
  const canvas = q('.st-canvas');
  if (!svg || !canvas) return;

  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const COLORS = ['#22d3ee', '#5ad41c', '#e6f23a'];
  const TINTS = ['#8ff3ff', '#b8ff7a', '#f8ff9c'];   // tono claro de cada hilo para la cola
  const NS = 'http://www.w3.org/2000/svg';
  const svgEl = (tag, attrs) => { const el = document.createElementNS(NS, tag); for (const k in attrs) el.setAttribute(k, attrs[k]); return el; };

  // Destellos: cola fina con degradado que se desvanece y cabeza de luz con un halo suave
  const COMET = 120;          // largo de la cola
  const COMET_SPEED = 280;    // px por segundo
  const COMET_LAYERS = [
    { len: COMET, w: 6, op: 0.22, tone: 'grad' },
    { len: COMET, w: 1.9, op: 1, tone: 'grad' },
    { len: 0, w: 9, op: 0.18, tone: 'line' },
    { len: 0, w: 5.5, op: 0.35, tone: 'tint' },
    { len: 0, w: 3, op: 1, tone: 'white' }
  ];
  const defs = svgEl('defs', {});
  svg.append(defs);
  const lines = COLORS.map((color, k) => {
    const base = { fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' };
    const glow = svgEl('path', { ...base, stroke: color, 'stroke-width': 7, 'stroke-opacity': 0.13 });
    const core = svgEl('path', { ...base, stroke: color, 'stroke-width': 1.5, 'stroke-opacity': 0.6 });
    const comets = [0, 1, 2].map((i) => {
      const grad = svgEl('linearGradient', { id: `st-comet-${k}-${i}`, gradientUnits: 'userSpaceOnUse' });
      [[0, TINTS[k], 0], [0.6, TINTS[k], 0.45], [0.9, TINTS[k], 0.85], [1, '#f6ffe8', 1]].forEach(([o, c, a]) => grad.append(svgEl('stop', { offset: o, 'stop-color': c, 'stop-opacity': a })));
      defs.append(grad);
      const tone = { grad: `url(#st-comet-${k}-${i})`, line: color, tint: TINTS[k], white: '#f6ffe8' };
      return { grad, active: false, pos: 0, layers: COMET_LAYERS.map((l) => ({ ...l, el: svgEl('path', { ...base, stroke: tone[l.tone], 'stroke-width': l.w, 'stroke-opacity': l.op }) })) };
    });
    svg.append(glow, core, ...comets.flatMap((c) => c.layers.map((l) => l.el)));
    return { k, glow, core, comets, total: 0, L: [], Y: [], drawn: 0, spawnIn: 0.4 + k * 0.5 };
  });
  let flowNodes = [];

  function docRect(el) {
    const r = el.getBoundingClientRect();
    return { l: r.left + scrollX, t: r.top + scrollY, r: r.right + scrollX, b: r.bottom + scrollY, w: r.width, h: r.height, cx: r.left + scrollX + r.width / 2, cy: r.top + scrollY + r.height / 2 };
  }

  // Curvas en S, siempre hacia abajo, entre puntos consecutivos
  function buildPath(pts) {
    for (let i = 1; i < pts.length; i++) pts[i][1] = Math.max(pts[i][1], pts[i - 1][1] + 1);
    let d = `M${pts[0][0].toFixed(1)} ${pts[0][1].toFixed(1)}`;
    for (let i = 1; i < pts.length; i++) {
      const [ax, ay] = pts[i - 1], [bx, by] = pts[i];
      if (Math.abs(bx - ax) < 0.5) d += ` L${bx.toFixed(1)} ${by.toFixed(1)}`;
      else { const k = clamp((by - ay) * 0.5, 20, 240); d += ` C${ax.toFixed(1)} ${(ay + k).toFixed(1)} ${bx.toFixed(1)} ${(by - k).toFixed(1)} ${bx.toFixed(1)} ${by.toFixed(1)}`; }
    }
    return d;
  }

  // Qué altura de la página alcanza cada hilo a cada longitud. Se mide en trozos de ~6 ms cuando el navegador está libre
  const PASO_HILO = 16;
  const cuandoLibre = (fn) => ('requestIdleCallback' in window ? requestIdleCallback(fn, { timeout: 1200 }) : setTimeout(fn, 30));
  function muestrearHilo(ln) {
    const trabajo = (ln.trabajo = (ln.trabajo || 0) + 1), total = ln.total, L = [], Y = [];
    let l = 0, maxY = -Infinity;
    (function trozo() {
      if (ln.trabajo !== trabajo) return;
      const fin = performance.now() + 6;
      while (l <= total && performance.now() < fin) { maxY = Math.max(maxY, ln.core.getPointAtLength(l).y); L.push(l); Y.push(maxY); l += PASO_HILO; }
      if (l <= total) cuandoLibre(trozo); else { ln.L = L; ln.Y = Y; }
    })();
  }

  // Tarjetas que alcanza cada hilo (el orden de arriba abajo): tres de «qué es», tres de «cómo trabaja», dos filas de «qué hace» y una de «control»
  const SVC = [[0, 5], [1, 6], [2, 7]];
  const PUNTO = [0, 3, 4];

  function layoutFlow() {
    const W = document.documentElement.clientWidth;
    svg.setAttribute('width', W); svg.setAttribute('height', 10);
    const H = document.documentElement.scrollHeight;
    svg.setAttribute('height', H); svg.style.width = `${W}px`; svg.style.height = `${H}px`;

    const wide = W > 1100;
    const cv = docRect(canvas);
    const nodeR = clamp(cv.w * 0.0105, 3.5, 6.5);
    const y0 = cv.t + cv.h * 0.8 + nodeR * 2.8;      // justo debajo de los nodos de la animación
    const ends = qa('.st-port--end').map(docRect);
    const stat = qa('.st-stat').map(docRect), steps = qa('.st-step').map(docRect), cards = qa('.st-card').map(docRect), pts = qa('.st-point').map(docRect);
    const padL = parseFloat(getComputedStyle(q('.st-sec')).paddingLeft) || 16;

    lines.forEach((ln, k) => {
      const off = k - 1;
      const x0 = cv.cx + off * cv.w * 0.165;      // mismos nodos que la animación
      const e = ends[k]; if (!e) return;
      let p;
      if (wide && stat[k] && steps[k] && cards[SVC[k][1]] && pts[PUNTO[k]]) {
        p = [[x0, y0], [stat[k].cx, stat[k].t], [steps[k].cx, steps[k].t],
          [cards[SVC[k][0]].cx, cards[SVC[k][0]].t], [cards[SVC[k][1]].cx, cards[SVC[k][1]].t],
          [pts[PUNTO[k]].cx, pts[PUNTO[k]].t], [e.cx, e.cy - 60], [e.cx, e.cy]];
      } else {
        // Pantallas estrechas: bajan por el margen izquierdo y se juntan arriba de la llamada a la acción
        const lane = padL / 2 + off * 4;
        p = [[x0, y0], [lane, y0 + 260], [lane, e.cy - 170], [e.cx, e.cy - 60], [e.cx, e.cy]];
      }
      const d = buildPath(p);
      for (const el of [ln.glow, ln.core]) el.setAttribute('d', d);
      for (const c of ln.comets) for (const l of c.layers) l.el.setAttribute('d', d);
      ln.total = ln.core.getTotalLength();
      muestrearHilo(ln);
      for (const el of [ln.glow, ln.core]) el.style.strokeDasharray = `${ln.total} ${ln.total}`;
      for (const c of ln.comets) for (const l of c.layers) { l.el.style.strokeDasharray = `0 ${ln.total + 1000}`; l.el.style.strokeDashoffset = -(ln.total + 50); }
      if (reduce) ln.drawn = ln.total;
    });

    flowNodes = qa('[data-flow-node]').filter((el) => el.offsetParent !== null).map((el) => { const r = docRect(el); return { el, line: Number(el.dataset.flowNode), y: r.cy, lit: el.classList.contains('is-lit') }; });
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
  function yAtLength(ln, l) { const i = clamp(Math.round(l / PASO_HILO), 0, ln.Y.length - 1); return ln.Y[i] ?? 0; }

  // scrollY e innerHeight se guardan al hacer scroll o cambiar de tamaño: leerlos en cada fotograma obligaría a recalcular la maqueta
  let SY = scrollY, IH = innerHeight;
  addEventListener('scroll', () => { SY = scrollY; }, { passive: true });
  addEventListener('resize', () => { SY = scrollY; IH = innerHeight; }, { passive: true });

  function updateFlow(dt) {
    const tip = SY + IH * 0.75;
    for (const ln of lines) {
      if (!ln.total) continue;
      const target = reduce ? ln.total : lengthAtY(ln, tip);
      ln.drawn += (target - ln.drawn) * (1 - Math.exp(-dt * 5));
      const off = ln.total - ln.drawn;
      ln.glow.style.strokeDashoffset = off;
      ln.core.style.strokeDashoffset = off;
      const show = !reduce && ln.drawn > 80;
      const viewEnd = lengthAtY(ln, SY + IH + 200);
      ln.spawnIn -= dt;
      if (show && ln.spawnIn <= 0) {
        ln.spawnIn = 1.3 + Math.random() * 1.6;
        const start = lengthAtY(ln, SY - 60);
        const free = ln.comets.find((c) => !c.active);
        const crowded = ln.comets.some((c) => c.active && Math.abs(c.pos - start) < 260);
        if (free && !crowded && start < ln.drawn - 60) { free.active = true; free.pos = start; }
      }
      ln.comets.forEach((c) => {
        if (c.active) {
          c.pos += dt * COMET_SPEED;
          if (!show || c.pos - COMET > ln.drawn || c.pos - COMET > viewEnd) c.active = false;
        }
        const head = c.pos, s = Math.max(0, head - COMET), e = Math.min(head, ln.drawn), vis = show && c.active;
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
  }

  // La maquetación mide muchos elementos: se recalcula cuando la página se «asienta» (carga, fuentes, cambio real de tamaño)
  let layoutRaf = 0, layoutTimer = 0, medidaW = 0, medidaH = 0;
  function relayout(espera = 150) {
    clearTimeout(layoutTimer);
    layoutTimer = setTimeout(() => {
      cancelAnimationFrame(layoutRaf);
      layoutRaf = requestAnimationFrame(() => { layoutFlow(); if (reduce) updateFlow(1); });
    }, espera);
  }
  new ResizeObserver((es) => {
    const r = es[es.length - 1].contentRect, w = Math.round(r.width), h = Math.round(r.height);
    if (w === medidaW && Math.abs(h - medidaH) < 4) return;
    medidaW = w; medidaH = h; relayout();
  }).observe(document.body);
  relayout(0);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => relayout());

  let lastNow = performance.now();
  const cap30 = window.matchMedia('(max-width: 860px)').matches;   // móvil: 30 fotogramas por segundo
  if (!reduce) {
    (function frame(now) {
      requestAnimationFrame(frame);
      if (cap30 && now - lastNow < 28) return;
      const dt = Math.min(0.05, (now - lastNow) / 1000);
      lastNow = now;
      updateFlow(dt);
    })(lastNow);
  }
  }

  if (document.readyState === 'complete') setTimeout(arrancar, 2500);
  else addEventListener('load', () => setTimeout(arrancar, 2500));
})();
