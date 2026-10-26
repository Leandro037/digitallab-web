/*!
 * Digital Lab · títulos que se construyen con datos («lluvia de datos»)
 * Sobre el texto se dibuja una capa de cuadraditos con los colores de la matriz del hero:
 * caen desde arriba con estela, se posan fila a fila formando las letras y al final se
 * funden con el texto real, que sigue siendo HTML normal (seleccionable y legible para buscadores).
 *
 *   DigitalLabTextBuild.play(elemento);
 *
 * La capa va dentro del contenedor del texto, así acompaña sus transformaciones (p. ej. al aparecer).
 */
(function (global) {
  'use strict';

  const MATRIX = [
    ['#0f9b8e', 30], ['#14c4a4', 18], ['#1d74c8', 13], ['#35c759', 14],
    ['#5ad41c', 9], ['#22d3ee', 8], ['#e6f23a', 4], ['#ffffff', 4]
  ];
  const MAT_TOTAL = MATRIX.reduce((n, [, w]) => n + w, 0);
  const GRAD = [[0, '#22d3ee'], [0.55, '#5ad41c'], [1, '#e6f23a']];   // igual que .grad
  const GRAD_KEY = '#ff00ff';   // color clave con el que se marcan las letras en degradado al medir

  const FALL = 420;     // ms que tarda en caer cada cuadradito
  const SETTLE = 380;   // ms hasta tomar el color final de la letra
  const SPREAD = 850;   // ms entre la primera y la última fila
  const PAD = { l: 20, r: 20, t: 170, b: 20 };

  const reduce = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const lerp = (a, b, t) => a + (b - a) * t;
  const easeOut = (t) => 1 - Math.pow(1 - t, 3);
  const hex = (h) => [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)];
  const mix = (a, b, t) => [lerp(a[0], b[0], t), lerp(a[1], b[1], t), lerp(a[2], b[2], t)];
  const css = (c) => `rgb(${c[0] | 0},${c[1] | 0},${c[2] | 0})`;
  const MAT_RGB = MATRIX.map(([c]) => hex(c));
  const WHITE = [255, 255, 255];
  const GRAD_RGB = GRAD.map(([o, c]) => [o, hex(c)]);

  function gradAt(t) {
    t = clamp(t, 0, 1);
    for (let i = 1; i < GRAD_RGB.length; i++) {
      const [o1, c1] = GRAD_RGB[i];
      if (t <= o1) {
        const [o0, c0] = GRAD_RGB[i - 1];
        return mix(c0, c1, (t - o0) / (o1 - o0));
      }
    }
    return GRAD_RGB[GRAD_RGB.length - 1][1];
  }

  function pickMat() {
    let r = Math.random() * MAT_TOTAL;
    for (let k = 0; k < MATRIX.length; k++) { r -= MATRIX[k][1]; if (r <= 0) return MAT_RGB[k]; }
    return MAT_RGB[MAT_RGB.length - 1];
  }

  /* ------------------------------------------------------------------ */
  /* Medición: dibuja cada letra, con su color, donde la colocó el navegador */
  /* ------------------------------------------------------------------ */

  function rasterize(el) {
    const box = el.getBoundingClientRect();
    const S = 2;   // doble resolución para decidir mejor qué cuadradito es letra
    const W = Math.max(1, Math.ceil(box.width)), H = Math.max(1, Math.ceil(box.height));
    const cv = document.createElement('canvas');
    cv.width = W * S;
    cv.height = H * S;
    const c = cv.getContext('2d', { willReadFrequently: true });
    c.scale(S, S);
    c.textBaseline = 'alphabetic';

    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const range = document.createRange();
    let node;
    while ((node = walker.nextNode())) {
      const parent = node.parentElement;
      const cs = getComputedStyle(parent);
      const size = parseFloat(cs.fontSize);
      c.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
      c.fillStyle = parent.closest('.grad') ? GRAD_KEY : cs.color;
      c.globalAlpha = 1;
      const text = node.textContent;
      for (let i = 0; i < text.length; i++) {
        if (/\s/.test(text[i])) continue;
        range.setStart(node, i);
        range.setEnd(node, i + 1);
        const r = range.getClientRects()[0];
        if (!r || !r.width) continue;
        const m = c.measureText(text[i]);
        const fa = m.fontBoundingBoxAscent || size * 0.8;
        const fd = m.fontBoundingBoxDescent || size * 0.2;
        c.fillText(text[i], r.left - box.left, r.top - box.top + (r.height - (fa + fd)) / 2 + fa);
      }
    }

    // Tramos del texto en degradado: el color final de cada cuadradito sale de su posición
    const frags = [];
    let acc = 0;
    el.querySelectorAll('.grad').forEach((g) => {
      for (const r of g.getClientRects()) {
        frags.push({ l: r.left - box.left, r: r.right - box.left, t: r.top - box.top, b: r.bottom - box.top, acc });
        acc += r.width;
      }
    });
    frags.forEach((f) => { f.total = acc; });

    return { W, H, S, data: c.getImageData(0, 0, W * S, H * S).data, frags };
  }

  function gradColor(mask, x, y) {
    for (const f of mask.frags) {
      if (y >= f.t && y <= f.b && x >= f.l - 2 && x <= f.r + 2) return gradAt((f.acc + x - f.l) / f.total);
    }
    return gradAt(0.5);
  }

  // Rejilla de cuadraditos sobre las letras, con el color medio de cada celda
  function grid(mask, pitch, threshold = 0.32) {
    const { W, H, S, data } = mask;
    const cells = [];
    const cols = Math.ceil(W / pitch), rows = Math.ceil(H / pitch);
    const rowW = W * S;
    for (let row = 0; row < rows; row++) {
      for (let col = 0; col < cols; col++) {
        const x0 = Math.floor(col * pitch * S), y0 = Math.floor(row * pitch * S);
        const x1 = Math.min(W * S, Math.floor((col + 1) * pitch * S)), y1 = Math.min(H * S, Math.floor((row + 1) * pitch * S));
        let a = 0, r = 0, g = 0, b = 0, n = 0;
        for (let y = y0; y < y1; y++) {
          for (let x = x0; x < x1; x++) {
            const i = (y * rowW + x) * 4, al = data[i + 3];
            a += al; r += data[i] * al; g += data[i + 1] * al; b += data[i + 2] * al; n++;
          }
        }
        const cov = n ? a / (n * 255) : 0;
        if (cov < threshold) continue;
        const x = col * pitch, y = row * pitch;
        let rgb = [r / a, g / a, b / a];
        if (rgb[0] > 200 && rgb[1] < 90 && rgb[2] > 200) rgb = gradColor(mask, x + pitch / 2, y + pitch / 2);
        cells.push({ x, y, cov, rgb, mat: pickMat(), rnd: Math.random() });
      }
    }
    return cells;
  }

  /* ------------------------------------------------------------------ */
  /* Dibujo                                                              */
  /* ------------------------------------------------------------------ */

  function square(st, x, y, rgb, a) {
    if (a <= 0.01) return;
    const ctx = st.ctx;
    ctx.globalAlpha = Math.min(1, a);
    ctx.fillStyle = css(rgb);
    ctx.fillRect(x + st.inset, y + st.inset, st.size, st.size);
  }

  // Brillo suave al posarse
  function glow(st, x, y, rgb, a) {
    if (a <= 0.01) return;
    const ctx = st.ctx;
    ctx.globalCompositeOperation = 'lighter';
    ctx.globalAlpha = a * 0.22;
    ctx.fillStyle = css(rgb);
    const s = st.pitch * 2.2, o = (st.pitch - s) / 2;
    ctx.fillRect(x + o, y + o, s, s);
    ctx.globalCompositeOperation = 'source-over';
  }

  function frame(st, t) {
    for (const c of st.cells) {
      const lt = t - c.delay;
      if (lt < 0) continue;
      const f = Math.min(1, lt / st.fall);
      const a = Math.min(1, f * 3) * (0.6 + 0.4 * c.cov);
      if (f < 1) {
        // gota con estela, como las de la matriz
        const y = c.y - c.drop * (1 - easeOut(f));
        square(st, c.x, y - st.pitch, c.mat, a * 0.35);
        square(st, c.x, y - st.pitch * 2, c.mat, a * 0.15);
        square(st, c.x, y, mix(c.mat, WHITE, 0.35), a);
      } else {
        const k = Math.min(1, (lt - st.fall) / st.settle);
        const flash = Math.max(0, 1 - (lt - st.fall) / (140 * st.k));
        const rgb = mix(mix(c.mat, c.rgb, easeOut(k)), WHITE, flash * 0.7);
        square(st, c.x, c.y, rgb, a);
        glow(st, c.x, c.y, rgb, flash);
      }
    }
    // Fundido final de los cuadraditos al texto real
    if (t <= st.T) return false;
    const k = clamp((t - st.T) / 450, 0, 1);
    st.cv.style.opacity = String(1 - k);
    st.el.style.opacity = String(easeOut(k));
    return k >= 1;
  }

  /* ------------------------------------------------------------------ */
  /* Reproducción                                                        */
  /* ------------------------------------------------------------------ */

  const running = new Map();

  function stop(el) {
    const st = running.get(el);
    if (!st) return;
    cancelAnimationFrame(st.raf);
    st.cv.remove();
    if (st.restore) st.restore();
    el.style.opacity = '';
    running.delete(el);
  }

  function play(el) {
    stop(el);
    if (reduce || !el || !el.parentElement) return;
    const fs = parseFloat(getComputedStyle(el).fontSize) || 48;
    const pitch = clamp(Math.round(fs / 12), 3, 7);
    const k = el.hasAttribute('data-build-fast') ? 0.55 : 1;   // el título principal se construye en poco más de un segundo
    const st = { el, pitch, size: pitch * 0.72, inset: pitch * 0.14, raf: 0, k, fall: FALL * k, settle: SETTLE * k };
    const mask = rasterize(el);
    st.cells = grid(mask, pitch);
    if (!st.cells.length) return;
    for (const c of st.cells) {
      c.delay = ((c.y / mask.H) * SPREAD + c.rnd * 260) * st.k;   // de arriba abajo, con algo de desorden
      c.drop = 50 + Math.random() * 120;
    }
    st.T = Math.max(...st.cells.map((c) => c.delay)) + st.fall + st.settle;

    // Capa de dibujo dentro del contenedor, sin salirse de la página por los lados.
    // Si el contenedor no está posicionado, se posiciona solo mientras dura la animación y sin
    // desplazarlo (p. ej. un «top» pensado para sticky en escritorio); al terminar se deja como estaba.
    const parent = el.parentElement;
    if (getComputedStyle(parent).position === 'static') {
      const prev = { position: parent.style.position, inset: parent.style.inset };
      parent.style.position = 'relative';
      parent.style.inset = 'auto';
      st.restore = () => { parent.style.position = prev.position; parent.style.inset = prev.inset; };
    }
    const box = el.getBoundingClientRect(), pr = parent.getBoundingClientRect();
    const docW = document.documentElement.clientWidth;
    const pad = { ...PAD, l: Math.max(0, Math.min(PAD.l, box.left)), r: Math.max(0, Math.min(PAD.r, docW - box.right)) };
    const dpr = Math.min(global.matchMedia && global.matchMedia('(max-width: 860px)').matches ? 1 : 2, global.devicePixelRatio || 1);
    const w = box.width + pad.l + pad.r, h = box.height + pad.t + pad.b;
    const cv = document.createElement('canvas');
    cv.className = 'tb-layer';
    cv.setAttribute('aria-hidden', 'true');
    Object.assign(cv.style, {
      position: 'absolute', zIndex: '5', pointerEvents: 'none',
      left: `${box.left - pr.left - parent.clientLeft - pad.l}px`,
      top: `${box.top - pr.top - parent.clientTop - pad.t}px`,
      width: `${w}px`, height: `${h}px`
    });
    cv.width = Math.round(w * dpr);
    cv.height = Math.round(h * dpr);
    parent.appendChild(cv);
    st.cv = cv;
    st.ctx = cv.getContext('2d');
    st.ctx.setTransform(dpr, 0, 0, dpr, pad.l * dpr, pad.t * dpr);
    el.style.opacity = '0';

    running.set(el, st);
    const t0 = performance.now();
    const tick = (now) => {
      st.ctx.clearRect(-pad.l, -pad.t, w, h);
      if (frame(st, now - t0)) { stop(el); return; }
      st.raf = requestAnimationFrame(tick);
    };
    st.raf = requestAnimationFrame(tick);
  }

  global.DigitalLabTextBuild = { play, stop };
})(window);
