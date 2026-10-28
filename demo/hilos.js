/*!
 * Digital Lab · hilos de luz de /demo
 * Igual que studio/hilos.js (y su versión ampliada de la propuesta A), con un cambio: en pantallas anchas cada hilo
 * recorre, de arriba abajo, todos los puertos visibles de su color ([data-flow-node="k"] que no son finales y
 * [data-flow-via="k"]), en lugar de una lista fija de tarjetas de /studio. Así sirve aunque cambie el número de
 * tarjetas de cada sección. En móvil y tablet (≤ 1100 px) baja por el carril izquierdo como en /studio, pero la bajada
 * y la llegada buscan franjas sin texto y el carril queda dentro del margen (tarea 6): ningún hilo pasa por detrás de un
 * texto. Si no encuentra hueco, usa la ruta anterior.
 */
(function () {
  'use strict';

  function arrancar() {
  const q = (s, r = document) => r.querySelector(s);
  const qa = (s, r = document) => [...r.querySelectorAll(s)];
  const svg = q('.flow');
  const canvas = q('.st-canvas');
  if (!svg || !canvas) return;

  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const COLORS = ['#22d3ee', '#5ad41c', '#e6f23a'];
  const TINTS = ['#8ff3ff', '#b8ff7a', '#f8ff9c'];
  const NS = 'http://www.w3.org/2000/svg';
  const svgEl = (tag, attrs) => { const el = document.createElementNS(NS, tag); for (const k in attrs) el.setAttribute(k, attrs[k]); return el; };

  const COMET = 120;
  const COMET_SPEED = 280;
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

  // Móvil y tablet (tarea 6): ningún hilo puede pasar por detrás de un texto.
  // Obstáculos = cada línea de texto visible, ampliada MARGEN px (más que medio trazo del cometa, 4,5 px).
  const MARGEN = 6;
  const caja = (r) => [r.left - MARGEN, r.top + scrollY - MARGEN, r.right + MARGEN, r.bottom + scrollY + MARGEN];
  // Devuelve { texto, todo }: los tramos rectos que bajan del CEO y suben al puerto final solo evitan texto (en el
  // diseño del 29 el hilo verde ya pasa bajo la flecha «Bajar»); las franjas de cruce y el carril evitan además enlaces
  // y botones, que tienen fondo y cortarían el hilo.
  function obstaculos() {
    const out = [];
    const rg = document.createRange();
    const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = tw.nextNode(); n; n = tw.nextNode()) {
      if (!n.data.trim()) continue;
      const p = n.parentElement;
      if (!p || p.closest('.st-top, .sr, svg')) continue;
      rg.selectNodeContents(n);
      for (const r of rg.getClientRects()) {
        if (r.width > 1 && r.height > 1) out.push(caja(r));
      }
    }
    const todo = out.slice();
    for (const b of qa('main a, main button')) {
      if (b.offsetParent === null) continue;
      const r = b.getBoundingClientRect();
      if (r.width > 1 && r.height > 1) todo.push(caja(r));
    }
    return { texto: out, todo };
  }
  // Ruta en tres tramos: baja recta desde el lienzo del CEO, cruza al carril izquierdo por una franja libre,
  // baja por el margen y cruza al puerto final por otra franja libre. Devuelve un «d» por hilo o null.
  function rutaCarril(x0s, y0, ends, lanes, obs) {
    const libreEn = (cajas) => (xa, ya, xb, yb) => !cajas.some((z) => z[0] < xb && z[2] > xa && z[1] < yb && z[3] > ya);
    const sinTexto = libreEn(obs.texto), libre = libreEn(obs.todo);
    const ALTOS = [72, 56, 40, 28, 20];
    const BUENO = 40;   // franja preferida; si no hay ninguna, la más alta encontrada
    const xMin = Math.min(...lanes);
    const xMaxB = Math.max(...x0s), xMaxL = Math.max(...ends.map((e) => e.cx));
    const ey = Math.min(...ends.map((e) => e.cy));
    const alto = (xa, ya, xb, haciaArriba) => ALTOS.find((h) => (haciaArriba ? libre(xa, ya - h, xb, ya) : libre(xa, ya, xb, ya + h))) || 0;
    let bajada = null, llegada = null;
    for (let ya = y0; ya < ey; ya += 4) {
      if (!x0s.every((x) => sinTexto(x, y0, x, ya))) break;
      const h = alto(xMin, ya, xMaxB, false);
      if (h && (!bajada || h > bajada[1] - bajada[0])) bajada = [ya, ya + h];
      if (h >= BUENO) break;
    }
    if (!bajada) return null;
    for (let yd = ey; yd - 20 > bajada[1]; yd -= 4) {
      if (!ends.every((e) => sinTexto(e.cx, yd, e.cx, e.cy))) break;
      const h = alto(xMin, yd, xMaxL, true);
      if (h && yd - h > bajada[1] && (!llegada || h > llegada[1] - llegada[0])) llegada = [yd - h, yd];
      if (h >= BUENO) break;
    }
    if (!llegada || !lanes.every((x) => libre(x, bajada[1], x, llegada[0]))) return null;
    const f = (v) => v.toFixed(1);
    const [ya, yb] = bajada, [yc, yd] = llegada, hb = (yb - ya) / 2, hl = (yd - yc) / 2;
    return lanes.map((lx, k) => {
      const x0 = x0s[k], e = ends[k];
      return `M${f(x0)} ${f(y0)} L${f(x0)} ${f(ya)} C${f(x0)} ${f(ya + hb)} ${f(lx)} ${f(yb - hb)} ${f(lx)} ${f(yb)}` +
        ` L${f(lx)} ${f(yc)} C${f(lx)} ${f(yc + hl)} ${f(e.cx)} ${f(yd - hl)} ${f(e.cx)} ${f(yd)} L${f(e.cx)} ${f(e.cy)}`;
    });
  }

  function layoutFlow() {
    const W = document.documentElement.clientWidth;
    svg.setAttribute('width', W); svg.setAttribute('height', 10);
    const H = document.documentElement.scrollHeight;
    svg.setAttribute('height', H); svg.style.width = `${W}px`; svg.style.height = `${H}px`;

    const wide = W > 1100;
    const cv = docRect(canvas);
    const nodeR = clamp(cv.w * 0.0105, 3.5, 6.5);
    const y0 = cv.t + cv.h * 0.8 + nodeR * 2.8;
    const ends = qa('.st-port--end').map(docRect);
    // Lecturas de medidas primero; las escrituras van después
    const paso = wide ? lines.map((ln, k) => {
      const els = new Set([...qa(`[data-flow-node="${k}"]:not(.st-port--end)`), ...qa(`[data-flow-via="${k}"]`)]);
      return [...els].filter((el) => el.offsetParent !== null).map((el) => { const r = docRect(el); return [r.cx, r.cy]; });
    }) : [];
    const padL = parseFloat(getComputedStyle(q('.st-sec')).paddingLeft) || 16;
    // Carril estrecho: los 3 hilos, a 3 px entre sí, terminan MARGEN + 1 px antes de donde empieza el texto
    const lanes = [0, 1, 2].map((k) => Math.max(2, padL - MARGEN - 1 - (2 - k) * 3));
    const x0s = lines.map((ln, k) => cv.cx + (k - 1) * cv.w * 0.165);
    const carril = !wide && ends.length >= lines.length ? rutaCarril(x0s, y0, ends.slice(0, lines.length), lanes, obstaculos()) : null;

    lines.forEach((ln, k) => {
      const off = k - 1;
      const x0 = x0s[k];
      const e = ends[k]; if (!e) return;
      let p;
      if (wide && paso[k].length) {
        const mid = paso[k].sort((a, b) => a[1] - b[1]);
        p = [[x0, y0], ...mid, [e.cx, e.cy - 60], [e.cx, e.cy]];
      } else {
        const lane = padL / 2 + off * 4;
        p = [[x0, y0], [lane, y0 + 260], [lane, e.cy - 34], [e.cx, e.cy]];
      }
      const d = carril ? carril[k] : buildPath(p);
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

  function lengthAtY(ln, y) {
    const Y = ln.Y;
    if (!Y.length || y <= Y[0]) return 0;
    if (y >= Y[Y.length - 1]) return ln.total;
    let lo = 0, hi = Y.length - 1;
    while (hi - lo > 1) { const m = (lo + hi) >> 1; if (Y[m] < y) lo = m; else hi = m; }
    return ln.L[hi];
  }
  function yAtLength(ln, l) { const i = clamp(Math.round(l / PASO_HILO), 0, ln.Y.length - 1); return ln.Y[i] ?? 0; }

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
  const cap30 = window.matchMedia('(max-width: 860px)').matches;
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
