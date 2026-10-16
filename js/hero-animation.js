/*!
 * Digital Lab · Hero «Convergencia de datos»
 * Animación en <canvas> sin dependencias.
 *
 * Una matriz de datos «llueve» hacia una fila de emisores; desde ahí cientos de
 * hilos de luz convergen en tres nodos (inspirados en los nodos del logo), que
 * reflejan la información hacia abajo. El cursor ilumina la matriz e inyecta datos.
 *
 * Uso:
 *   <section class="hero">
 *     <canvas class="hero__canvas"></canvas>
 *     ...contenido...
 *     <div class="hero__visual"></div>   ← zona donde se compone la escena
 *   </section>
 *   <script src="hero-animation.js"></script>
 *   <script>
 *     DigitalLabHero.mount(document.querySelector('.hero__canvas'), {
 *       anchor: document.querySelector('.hero__visual'),
 *       palette: 'brand'            // 'brand' | 'spectrum' | { nodes, matrix, ambient }
 *     });
 *   </script>
 */
(function (global) {
  'use strict';

  const TAU = Math.PI * 2;

  const PALETTES = {
    // Verde Digital Lab con acentos turquesa y lima
    brand: {
      nodes: ['#22d3ee', '#5ad41c', '#e6f23a'],
      matrix: [
        ['#0f9b8e', 30], ['#14c4a4', 18], ['#1d74c8', 13], ['#35c759', 14],
        ['#5ad41c', 9], ['#22d3ee', 8], ['#e6f23a', 4], ['#ffffff', 4]
      ],
      ambient: '#1fbf8f'
    },
    // Cian, magenta y ámbar, más cercana a la imagen de referencia
    spectrum: {
      nodes: ['#22d3ff', '#ff3fb4', '#ffc23c'],
      matrix: [
        ['#2f5bff', 32], ['#4f86ff', 20], ['#6b4dff', 11], ['#22d3ff', 13],
        ['#ff3fb4', 9], ['#ff9a3c', 6], ['#ffffff', 6]
      ],
      ambient: '#3b5bff'
    }
  };

  const DEFAULTS = {
    palette: 'brand',
    anchor: null,      // elemento cuyo rectángulo define dónde se dibuja la escena
    density: 1,        // multiplicador de hilos, gotas y pulsos
    bloom: true,       // resplandor (se desactiva solo si el equipo va lento)
    interactive: true, // el cursor ilumina e inyecta datos en la matriz
    seed: 11,
    flowOut: false,    // landing: nodos fijos y reflejos continuos hasta el borde inferior,
                       // para que los hilos de la página sigan desde ahí
    beams: true,       // dibujar los reflejos bajo los nodos (en móvil los dibuja la página)
    // Modo integrado con el texto (opcional):
    band: null,        // elemento cuya altura ocupa la matriz (p. ej. el logo con el nombre)
    lineStart: null,   // elemento desde cuyo borde izquierdo se alarga la fila de emisores
    ragged: 0.62,      // irregularidad del borde superior de la matriz (0 = recto)
    onLineEnd: null,   // se llama cuando un pulso de luz llega al final de la fila alargada
    activity: 1        // ritmo de los datos (1 normal; más alto = más gotas y pulsos, p. ej. mientras se trabaja)
  };

  const WHITE = [255, 255, 255];
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const smooth = (t) => { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); };
  const easeInOut = (t) => { t = clamp(t, 0, 1); return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
  const hexToRgb = (h) => { const n = parseInt(h.slice(1), 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };
  const mix = (a, b, t) => [0, 1, 2].map((i) => Math.round(a[i] + (b[i] - a[i]) * t));
  const rgba = (c, a) => `rgba(${c[0]},${c[1]},${c[2]},${a})`;

  function mulberry32(seed) {
    return function () {
      seed = (seed + 0x6d2b79f5) | 0;
      let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function noise1D(rand, size) {
    const v = new Float32Array(size);
    for (let i = 0; i < size; i++) v[i] = rand();
    return (x) => {
      const i = Math.floor(x), f = x - i;
      const a = v[((i % size) + size) % size], b = v[(((i + 1) % size) + size) % size];
      return a + (b - a) * f * f * (3 - 2 * f);
    };
  }

  // Punto de una curva cúbica guardada como 8 floats desde j (axis 0 = x, 1 = y)
  function bz(a, j, t, axis) {
    const u = 1 - t;
    return u * u * u * a[j + axis] + 3 * u * u * t * a[j + 2 + axis] +
      3 * u * t * t * a[j + 4 + axis] + t * t * t * a[j + 6 + axis];
  }

  function mount(canvas, options) {
    const o = Object.assign({}, DEFAULTS, options);
    const pal = typeof o.palette === 'object' ? o.palette : (PALETTES[o.palette] || PALETTES.brand);
    const ctx = canvas.getContext('2d');
    const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    const nodeRGB = pal.nodes.map(hexToRgb);
    const nodeCSS = pal.nodes.slice();
    const nodeClear = nodeRGB.map((c) => rgba(c, 0));
    const nodeHot = nodeRGB.map((c) => rgba(mix(c, WHITE, 0.45), 1));
    const matCSS = pal.matrix.map((m) => m[0]);
    const matW = pal.matrix.map((m) => m[1]);
    const matWTotal = matW.reduce((a, b) => a + b, 0);
    const ambientRGB = hexToRgb(pal.ambient);

    const bloomBufs = [0, 1, 2].map(() => {
      const c = document.createElement('canvas');
      return { c, x: c.getContext('2d') };
    });

    let W = 1, H = 1;
    let dpr = Math.min(window.devicePixelRatio || 1, 2);
    let R = { x: 0, y: 0, w: 1, h: 1 };          // zona de la composición (px CSS)
    let box = { x: 0, y: 0, w: 1, h: 1 };        // zona del resplandor (px de dispositivo)

    // Geometría
    let pitch, cell, nCols, nRows, mx0, my0, emitY, ty0, nodeY, nodeR, beamTop, beamLen;
    // Celdas de la matriz (arrays planos por rendimiento)
    let colStart, colOffset, cX, cY, cBase, cPhase, cSpeed, cFade, cRow, cBoost, colorLists;
    let emitters = [], threads = [], colThreads = [], cp = new Float32Array(0);
    let threadGrads = [], beamGlow = [], ambientGrad = null;
    let nodes = [], drops = [], pulses = [], bursts = [];
    let bandR = null, lineX = null, ext = [], linePulses = [], lineAcc = 0;

    let T = 0, last = 0, raf = 0, rzRaf = 0, running = false, inView = true, paused = false;
    let pulseAcc = 0, par = 0, act = o.activity;
    const mouse = { x: -1e4, y: -1e4, on: false, moved: false, lastInject: 0 };
    const perf = { sum: 0, n: 0 };

    /* ------------------------------------------------------------------ */
    /* Medidas y construcción de la escena                                */
    /* ------------------------------------------------------------------ */

    function measure() {
      const rc = canvas.getBoundingClientRect();
      W = Math.max(1, rc.width);
      H = Math.max(1, rc.height);
      canvas.width = Math.round(W * dpr);
      canvas.height = Math.round(H * dpr);
      if (o.anchor) {
        const a = o.anchor.getBoundingClientRect();
        R = { x: a.left - rc.left, y: a.top - rc.top, w: Math.max(1, a.width), h: Math.max(1, a.height) };
      } else {
        R = { x: 0, y: 0, w: W, h: H };
      }
      const b = o.band && o.band.getBoundingClientRect();
      bandR = b && b.height > 0 ? { y: b.top - rc.top, h: b.height } : null;
      const l = o.lineStart && o.lineStart.getBoundingClientRect();
      lineX = l && l.width > 0 ? l.left - rc.left : null;
    }

    function build() {
      const rand = mulberry32(o.seed);
      const d = o.density;
      const pick = () => {
        let r = rand() * matWTotal;
        for (let k = 0; k < matW.length; k++) { r -= matW[k]; if (r <= 0) return k; }
        return matW.length - 1;
      };

      // --- Matriz de datos
      pitch = clamp(R.w / 62, 5, 10);
      cell = Math.max(2, pitch * 0.6);
      nCols = Math.max(16, Math.floor((R.w * 0.94) / pitch));
      let mTop = R.y + R.h * 0.08;
      let mBot = R.y + R.h * 0.46;
      if (bandR) {
        // La matriz ocupa la altura del logo y su fila de emisores queda justo bajo el nombre
        mTop = bandR.y;
        mBot = bandR.y + bandR.h + pitch * 0.4;
      }
      nRows = Math.max(6, Math.floor((mBot - mTop) / pitch));
      mx0 = R.x + (R.w - nCols * pitch) / 2 + (pitch - cell) / 2;
      my0 = mBot - nRows * pitch;

      const nzA = noise1D(rand, 48), nzB = noise1D(rand, 48);
      colStart = new Int16Array(nCols);
      let total = 0;
      for (let c = 0; c < nCols; c++) {
        const n = nzA(c * 0.16) * 0.7 + nzB(c * 0.6) * 0.3;
        colStart[c] = Math.min(nRows - 4, Math.floor(nRows * o.ragged * Math.pow(n, 1.4) + rand() * (bandR ? 1.5 : 3)));
        total += nRows - colStart[c];
      }

      cX = new Float32Array(total); cY = new Float32Array(total);
      cBase = new Float32Array(total); cPhase = new Float32Array(total);
      cSpeed = new Float32Array(total); cFade = new Float32Array(total);
      cRow = new Float32Array(total); cBoost = new Float32Array(total);
      colOffset = new Int32Array(nCols);
      const buckets = matCSS.map(() => []);
      let i = 0;
      for (let c = 0; c < nCols; c++) {
        colOffset[c] = i;
        const edge = 0.35 + 0.65 * smooth(Math.min(c, nCols - 1 - c) / (nCols * 0.14));
        const gain = 0.7 + 0.6 * rand();
        for (let r = colStart[c]; r < nRows; r++, i++) {
          cX[i] = mx0 + c * pitch;
          cY[i] = my0 + r * pitch;
          buckets[pick()].push(i);
          cBase[i] = 0.08 + Math.pow(rand(), 2) * 0.92;
          cPhase[i] = rand() * TAU;
          cSpeed[i] = 0.4 + rand() * 2.4;
          cFade[i] = smooth((r - colStart[c]) / 7) * (0.45 + 0.55 * (r / nRows)) * edge * gain;
          cRow[i] = r / nRows;
        }
      }
      colorLists = buckets.map((b) => Uint32Array.from(b));

      // --- Fila de emisores
      emitY = my0 + nRows * pitch + pitch * 0.9;
      emitters = [];
      for (let c = 0; c < nCols; c++) {
        emitters.push({ k: rand() < 0.18 ? 3 : Math.floor(rand() * 3), base: 0.25 + rand() * 0.45, ph: rand() * TAU, flash: 0 });
      }
      // Fila alargada hacia la izquierda: hace de línea bajo el logo y une texto y animación
      ext = [];
      linePulses = [];
      if (lineX != null && lineX < mx0 - pitch) {
        for (let x = mx0 - pitch; x >= lineX; x -= pitch) {
          ext.push({ x, k: rand() < 0.15 ? 3 : Math.floor(rand() * 3), base: 0.16 + rand() * 0.3, ph: rand() * TAU });
        }
      }

      // --- Nodos de convergencia
      ty0 = emitY + pitch * 1.4;
      nodeY = R.y + R.h * 0.8;
      nodeR = clamp(R.w * 0.0105, 3.5, 6.5);
      const spread = R.w * 0.165;
      nodes = [0, 1, 2].map((k) => {
        const bx = R.x + R.w / 2 + (k - 1) * spread;
        return { k, bx, x: bx, y: nodeY, energy: 0, ph: rand() * TAU, beamSpeed: 14 + rand() * 12, segs: [] };
      });

      // --- Hilos
      const nT = Math.round(clamp(R.w * 0.45, 110, 300) * d);
      threads = [];
      colThreads = Array.from({ length: nCols }, () => []);
      for (let t = 0; t < nT; t++) {
        const c = Math.min(nCols - 1, Math.floor(((t + rand()) / nT) * nCols));
        threads.push({
          c, f: Math.floor(rand() * 3),
          x0: mx0 + c * pitch + cell / 2,
          a: 0.25 + rand() * 0.3, b: 0.2 + rand() * 0.3, k: 0.03 + rand() * 0.2,
          alpha: 0.1 + Math.pow(rand(), 1.6) * 0.4, w: 0.5 + rand() * 0.75,
          sa: 0.4 + rand() * 2.2, sf: 0.2 + rand() * 0.6, sp: rand() * TAU,
          delay: rand(), len: 0
        });
        colThreads[c].push(t);
      }
      cp = new Float32Array(nT * 8);
      for (let t = 0; t < nT; t++) {
        shapeThread(t, 0);
        const j = t * 8;
        let L = 0, px = cp[j], py = cp[j + 1];
        for (let s = 1; s <= 16; s++) {
          const x = bz(cp, j, s / 16, 0), y = bz(cp, j, s / 16, 1);
          L += Math.hypot(x - px, y - py); px = x; py = y;
        }
        threads[t].len = L * 1.02;
      }

      threadGrads = nodeRGB.map((rgb) => {
        const g = ctx.createLinearGradient(0, ty0, 0, nodeY);
        const pale = mix(rgb, WHITE, 0.55);
        g.addColorStop(0, rgba(pale, 0));
        g.addColorStop(0.12, rgba(pale, 0.35));
        g.addColorStop(0.55, rgba(mix(rgb, WHITE, 0.2), 0.65));
        g.addColorStop(1, rgba(rgb, 1));
        return g;
      });

      // --- Reflejos bajo los nodos
      beamTop = nodeY + nodeR * 2.8;
      beamLen = Math.max(30, (o.flowOut ? H : Math.min(H, R.y + R.h * 1.08)) - beamTop);
      for (const n of nodes) {
        let y = rand() * 6;
        while (y < beamLen) {
          const l = 1.5 + Math.pow(rand(), 2) * 14;
          n.segs.push({ p: y, l, a: 0.2 + rand() * 0.7 });
          y += l + 3 + rand() * 12;
        }
      }
      beamGlow = nodeRGB.map((rgb) => {
        const g = ctx.createLinearGradient(0, beamTop, 0, beamTop + beamLen * 0.85);
        g.addColorStop(0, rgba(rgb, 0.22));
        g.addColorStop(1, rgba(rgb, o.flowOut ? 0.16 : 0));
        return g;
      });

      const acx = R.x + R.w / 2, acy = nodeY - R.h * 0.1;
      ambientGrad = ctx.createRadialGradient(acx, acy, 0, acx, acy, R.w * (o.flowOut ? 0.55 : 0.75));
      ambientGrad.addColorStop(0, rgba(ambientRGB, 0.1));
      ambientGrad.addColorStop(1, rgba(ambientRGB, 0));

      // --- Gotas, pulsos y ráfagas
      drops = [];
      const nD = Math.round(nCols * 0.4 * d);
      for (let k = 0; k < nD; k++) drops.push(spawnDrop({}, true));
      pulses = [];
      bursts = [];

      // --- Buffers del resplandor (solo cubren la composición)
      const bx0 = Math.max(0, Math.min(R.x - R.w * 0.12, ext.length ? lineX - 30 : Infinity));
      const by0 = Math.max(0, Math.min(R.y, bandR ? bandR.y - 30 : Infinity));
      const bx1 = Math.min(W, R.x + R.w * 1.12), by1 = o.flowOut ? H : Math.min(H, R.y + R.h * 1.1);
      box = {
        x: Math.floor(bx0 * dpr), y: Math.floor(by0 * dpr),
        w: Math.max(4, Math.ceil((bx1 - bx0) * dpr)), h: Math.max(4, Math.ceil((by1 - by0) * dpr))
      };
      let bw = box.w, bh = box.h;
      bloomBufs.forEach((b, k) => {
        const f = k === 0 ? 4 : 2;
        bw = Math.max(1, Math.round(bw / f)); bh = Math.max(1, Math.round(bh / f));
        b.c.width = bw; b.c.height = bh;
        b.x.imageSmoothingEnabled = true;
        b.x.imageSmoothingQuality = 'high';
      });
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';
    }

    function shapeThread(i, shift) {
      const th = threads[i], n = nodes[th.f], j = i * 8;
      const x0 = th.x0 + shift, dy = nodeY - ty0;
      cp[j] = x0;                            cp[j + 1] = ty0;
      cp[j + 2] = x0;                        cp[j + 3] = ty0 + dy * th.a;
      cp[j + 4] = n.x + (x0 - n.x) * th.k;   cp[j + 5] = nodeY - dy * th.b;
      cp[j + 6] = n.x;                       cp[j + 7] = nodeY - nodeR * 0.5;
    }

    function spawnDrop(dr, initial) {
      const c = Math.floor(Math.random() * nCols);
      dr.c = c;
      dr.len = 3 + Math.random() * 8;
      dr.y = initial ? colStart[c] + Math.random() * (nRows - colStart[c]) : colStart[c] - Math.random() * nRows * 0.6;
      dr.v = 5 + Math.random() * 11;
      dr.fired = false;
      dr.injected = false;
      return dr;
    }

    function firePulse(c) {
      if (pulses.length > 160) return;
      let list = colThreads[c];
      for (let s = 1; !list.length && s < nCols; s++) {
        list = colThreads[Math.max(0, c - s)].length ? colThreads[Math.max(0, c - s)] : colThreads[Math.min(nCols - 1, c + s)];
      }
      if (!list.length) return;
      pulses.push({
        i: list[Math.floor(Math.random() * list.length)],
        t: 0,
        v: 0.45 + Math.random() * 0.4,
        trail: 0.06 + Math.random() * 0.08
      });
    }

    /* ------------------------------------------------------------------ */
    /* Simulación                                                          */
    /* ------------------------------------------------------------------ */

    function update(dt) {
      T += dt;
      act += (o.activity - act) * (1 - Math.exp(-dt * 1.5));   // el ritmo cambia de forma suave
      const target = mouse.on ? clamp(mouse.x / W - 0.5, -0.5, 0.5) : 0;
      par += (target - par) * (1 - Math.exp(-dt * 2.5));
      const shift = ext.length ? 0 : -par * 12;

      for (const n of nodes) {
        n.x = o.flowOut ? n.bx : n.bx + Math.sin(T * 0.3 + n.ph) * R.w * 0.008 + par * R.w * 0.05;
        n.energy *= Math.exp(-dt * 2.4);
      }

      const live = T > 2.3;

      // El cursor inyecta gotas en la columna que toca
      if (mouse.on && mouse.moved && o.interactive && T - mouse.lastInject > 0.07) {
        const c = Math.floor((mouse.x - shift - mx0) / pitch);
        const r = Math.floor((mouse.y - my0) / pitch);
        if (c >= 0 && c < nCols && r >= colStart[c] && r < nRows && drops.length < 260) {
          drops.push({ c, y: r, v: 14 + Math.random() * 10, len: 4 + Math.random() * 5, fired: false, injected: true });
          mouse.lastInject = T;
        }
        mouse.moved = false;
      }

      for (let k = drops.length - 1; k >= 0; k--) {
        const dr = drops[k];
        dr.y += dr.v * dt * Math.sqrt(act);
        if (!dr.fired && dr.y >= nRows) {
          dr.fired = true;
          emitters[dr.c].flash = 1;
          if (live) firePulse(dr.c);
        }
        if (dr.y - dr.len > nRows) {
          if (dr.injected) drops.splice(k, 1);
          else spawnDrop(dr, false);
        }
      }

      if (live) {
        pulseAcc += dt * 2.5 * o.density * act;
        while (pulseAcc > 1) { pulseAcc -= 1; firePulse(Math.floor(Math.random() * nCols)); }
      }

      for (let k = pulses.length - 1; k >= 0; k--) {
        const p = pulses[k];
        p.t += dt * p.v * (0.45 + p.t * 1.1);   // acelera al caer
        if (p.t >= 1) {
          const n = nodes[threads[p.i].f];
          n.energy = Math.min(1.6, n.energy + 0.22);
          if (Math.random() < 0.6) bursts.push({ n: n.k, y: 0, v: 60 + Math.random() * 80, l: 6 + Math.random() * 16 });
          pulses.splice(k, 1);
        }
      }

      for (let k = bursts.length - 1; k >= 0; k--) {
        const b = bursts[k];
        b.v += dt * 120;
        b.y += b.v * dt;
        if (b.y - b.l > beamLen) bursts.splice(k, 1);
      }

      for (const e of emitters) e.flash *= Math.exp(-dt * 4);

      // Pulsos suaves que recorren la fila alargada hasta debajo del logo
      if (ext.length && T > 3) {
        lineAcc -= dt;
        if (lineAcc <= 0) {
          lineAcc = 4.5 + Math.random() * 3;
          linePulses.push({ x: mx0, v: 150 + Math.random() * 60 });
        }
      }
      for (let k = linePulses.length - 1; k >= 0; k--) {
        const lp = linePulses[k];
        lp.x -= lp.v * dt;
        if (lp.x < lineX) {
          linePulses.splice(k, 1);
          if (o.onLineEnd) o.onLineEnd();
        }
      }
    }

    /* ------------------------------------------------------------------ */
    /* Dibujo                                                              */
    /* ------------------------------------------------------------------ */

    function draw() {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = 1;
      ctx.clearRect(0, 0, W, H);
      ctx.globalCompositeOperation = 'lighter';

      const shift = ext.length ? 0 : -par * 12;
      drawMatrix(shift);
      drawEmitters(shift);
      drawThreads(shift);
      drawPulses();
      drawNodes();
      drawBeams();
      if (o.bloom) bloom();

      // Halo ambiental fuera del resplandor para que no marque el borde de su zona
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.globalCompositeOperation = 'lighter';
      ctx.globalAlpha = smooth((T - 0.6) / 2);
      ctx.fillStyle = ambientGrad;
      ctx.fillRect(R.x - R.w * 0.4, R.y, R.w * 1.8, R.h * 1.2);
    }

    function drawMatrix(shift) {
      cBoost.fill(0);
      for (const dr of drops) {
        const s = colStart[dr.c], base = colOffset[dr.c] - s;
        const r0 = Math.max(s, Math.ceil(dr.y - dr.len)), r1 = Math.min(nRows - 1, Math.floor(dr.y));
        for (let r = r0; r <= r1; r++) cBoost[base + r] += (1 - (dr.y - r) / dr.len) * 0.9;
      }

      const revealing = T < 2;
      const front = ((T - 0.1) / 1.3) * 1.25;
      const mOn = mouse.on && o.interactive;
      const mx = mouse.x - shift, my = mouse.y, rad2 = 80 * 80;

      for (let k = 0; k < colorLists.length; k++) {
        const list = colorLists[k];
        ctx.fillStyle = matCSS[k];
        for (let j = 0, L = list.length; j < L; j++) {
          const i = list[j];
          let v = cBase[i] * (0.5 + 0.5 * Math.sin(T * cSpeed[i] + cPhase[i])) * cFade[i] + cBoost[i];
          if (mOn) {
            const dx = cX[i] - mx, dy = cY[i] - my, d2 = dx * dx + dy * dy;
            if (d2 < rad2) v += (1 - d2 / rad2) * 0.7 * (cFade[i] + 0.3);
          }
          if (revealing) v *= smooth((front - cRow[i]) * 4);
          if (v < 0.025) continue;
          ctx.globalAlpha = v > 1 ? 1 : v;
          ctx.fillRect(cX[i] + shift, cY[i], cell, cell);
        }
      }

      // Cabeza blanca de cada gota
      const ia = smooth((T - 0.6) / 1.2);
      if (ia <= 0) return;
      ctx.fillStyle = '#ffffff';
      for (const dr of drops) {
        const r = Math.floor(dr.y);
        if (r < colStart[dr.c] || r >= nRows) continue;
        ctx.globalAlpha = (dr.injected ? 0.95 : 0.7) * ia;
        ctx.fillRect(mx0 + dr.c * pitch + shift, my0 + r * pitch, cell, cell);
      }
    }

    function drawEmitters(shift) {
      const ia = smooth((T - 1.0) / 0.8);
      if (ia <= 0) return;
      const s = cell * 1.1, off = (s - cell) / 2;
      for (let c = 0; c < nCols; c++) {
        const e = emitters[c];
        const v = (e.base * (0.65 + 0.35 * Math.sin(T * 1.7 + e.ph)) + e.flash * 0.9) * ia;
        ctx.globalAlpha = v > 1 ? 1 : v;
        ctx.fillStyle = e.k === 3 ? '#ffffff' : nodeCSS[e.k];
        ctx.fillRect(mx0 + c * pitch + shift - off, emitY - off, s, s);
      }
      if (!ext.length) return;
      // La fila alargada aparece de derecha a izquierda; el pulso solo aviva un poco los puntos
      const span = Math.max(1, mx0 - lineX);
      const grow = smooth((T - 1.2) / 1.2) * 1.2;
      for (const e of ext) {
        const reveal = smooth((grow - (mx0 - e.x) / span) * 5);
        if (reveal <= 0) continue;
        let v = e.base * (0.6 + 0.4 * Math.sin(T * 1.5 + e.ph));
        for (const lp of linePulses) {
          const dd = Math.abs(e.x - lp.x);
          if (dd < 90) v += smooth(1 - dd / 90) * 0.32;
        }
        ctx.globalAlpha = Math.min(0.85, v * reveal);
        ctx.fillStyle = e.k === 3 ? '#ffffff' : nodeCSS[e.k];
        ctx.fillRect(e.x - off, emitY - off, s, s);
      }
    }

    function drawThreads(shift) {
      const tr = T - 1.2;
      if (tr <= 0) return;
      const drawing = tr < 2;
      ctx.lineCap = 'round';
      for (let i = 0; i < threads.length; i++) {
        const th = threads[i];
        shapeThread(i, shift + Math.sin(T * th.sf + th.sp) * th.sa);
        let p = 1;
        if (drawing) {
          p = easeInOut((tr - th.delay * 0.7) / 1.1);
          if (p <= 0) continue;
          ctx.setLineDash([th.len, th.len]);
          ctx.lineDashOffset = th.len * (1 - p);
        }
        const j = i * 8;
        ctx.beginPath();
        ctx.moveTo(cp[j], cp[j + 1]);
        ctx.bezierCurveTo(cp[j + 2], cp[j + 3], cp[j + 4], cp[j + 5], cp[j + 6], cp[j + 7]);
        ctx.strokeStyle = threadGrads[th.f];
        ctx.lineWidth = th.w;
        ctx.globalAlpha = th.alpha * (0.8 + 0.2 * Math.sin(T * 0.8 + th.sp));
        ctx.stroke();
      }
      if (drawing) ctx.setLineDash([]);
    }

    function drawPulses() {
      ctx.lineCap = 'round';
      ctx.lineWidth = 1.5;
      for (const p of pulses) {
        const f = threads[p.i].f, j = p.i * 8;
        const t1 = p.t, t0 = Math.max(0, t1 - p.trail);
        const x0 = bz(cp, j, t0, 0), y0 = bz(cp, j, t0, 1);
        const x1 = bz(cp, j, t1, 0), y1 = bz(cp, j, t1, 1);
        const g = ctx.createLinearGradient(x0, y0, x1, y1);
        g.addColorStop(0, nodeClear[f]);
        g.addColorStop(1, nodeHot[f]);
        ctx.strokeStyle = g;
        ctx.globalAlpha = 0.9 * smooth(p.t * 12);
        ctx.beginPath();
        ctx.moveTo(x0, y0);
        for (let s = 1; s <= 4; s++) {
          const t = t0 + ((t1 - t0) * s) / 4;
          ctx.lineTo(bz(cp, j, t, 0), bz(cp, j, t, 1));
        }
        ctx.stroke();
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(x1, y1, 1.2, 0, TAU);
        ctx.fill();
      }
    }

    function drawNodes() {
      const ia = smooth((T - 2.0) / 0.9);
      if (ia <= 0) return;
      for (const n of nodes) {
        const rgb = nodeRGB[n.k], e = n.energy;

        // Halo
        const hr = nodeR * (7 + e * 5);
        const g = ctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, hr);
        g.addColorStop(0, rgba(rgb, Math.min(1, 0.5 + e * 0.2)));
        g.addColorStop(0.15, rgba(rgb, 0.2 + e * 0.1));
        g.addColorStop(0.45, rgba(rgb, 0.05 + e * 0.03));
        g.addColorStop(1, rgba(rgb, 0));
        ctx.globalAlpha = ia;
        ctx.fillStyle = g;
        ctx.fillRect(n.x - hr, n.y - hr, hr * 2, hr * 2);

        // Anillo punteado que gira
        ctx.strokeStyle = nodeCSS[n.k];
        ctx.lineWidth = 1;
        ctx.globalAlpha = ia * (0.35 + e * 0.25);
        ctx.setLineDash([2, 3.5]);
        ctx.lineDashOffset = T * 10 * (n.k === 1 ? -1 : 1);
        ctx.beginPath();
        ctx.arc(n.x, n.y, nodeR * 2.4, 0, TAU);
        ctx.stroke();
        ctx.setLineDash([]);

        // Nodo: disco de color con centro blanco, como en el logo
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = ia;
        ctx.fillStyle = nodeCSS[n.k];
        ctx.beginPath();
        ctx.arc(n.x, n.y, nodeR, 0, TAU);
        ctx.fill();
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(n.x, n.y, nodeR * 0.45, 0, TAU);
        ctx.fill();
        ctx.globalCompositeOperation = 'lighter';
      }
    }

    function drawBeams() {
      const ia = smooth((T - 2.4) / 1.2);
      if (ia <= 0 || !o.beams) return;
      for (const n of nodes) {
        ctx.globalAlpha = ia * (0.5 + n.energy * 0.3);
        ctx.fillStyle = beamGlow[n.k];
        ctx.fillRect(n.x - 1.5, beamTop, 3, beamLen * (o.flowOut ? 1 : 0.85));

        ctx.fillStyle = nodeCSS[n.k];
        if (o.flowOut) {
          // Hilo continuo que sale del hero y sigue por la página
          ctx.globalAlpha = 0.55 * ia;
          ctx.fillRect(n.x - 0.7, beamTop, 1.4, beamLen);
        }
        const off = (T * n.beamSpeed) % beamLen;
        for (const s of n.segs) {
          let y = s.p + off;
          if (y >= beamLen) y -= beamLen;
          const f = o.flowOut ? 0.8 : 1 - y / beamLen;
          const a = s.a * f * f * smooth(y / 14) * ia;
          if (a < 0.01) continue;
          ctx.globalAlpha = a;
          ctx.fillRect(n.x - 0.75, beamTop + y, 1.5, Math.min(s.l, beamLen - y));
        }
      }
      ctx.fillStyle = '#ffffff';
      for (const b of bursts) {
        const n = nodes[b.n], f = 1 - b.y / beamLen;
        if (f <= 0) continue;
        const y0 = Math.max(0, b.y - b.l);
        ctx.globalAlpha = 0.8 * f * ia;
        ctx.fillRect(n.x - 0.75, beamTop + y0, 1.5, Math.min(b.y, beamLen) - y0);
      }
    }

    // Resplandor barato: reducir la escena y sumarla de nuevo ampliada
    function bloom() {
      const [a, b, c] = bloomBufs;
      a.x.globalCompositeOperation = 'copy';
      a.x.drawImage(canvas, box.x, box.y, box.w, box.h, 0, 0, a.c.width, a.c.height);
      b.x.globalCompositeOperation = 'copy';
      b.x.drawImage(a.c, 0, 0, b.c.width, b.c.height);
      c.x.globalCompositeOperation = 'copy';
      c.x.drawImage(b.c, 0, 0, c.c.width, c.c.height);
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.globalCompositeOperation = 'lighter';
      ctx.globalAlpha = 0.45; ctx.drawImage(a.c, box.x, box.y, box.w, box.h);
      ctx.globalAlpha = 0.55; ctx.drawImage(b.c, box.x, box.y, box.w, box.h);
      ctx.globalAlpha = 0.65; ctx.drawImage(c.c, box.x, box.y, box.w, box.h);
    }

    /* ------------------------------------------------------------------ */
    /* Bucle, pausa y adaptación                                           */
    /* ------------------------------------------------------------------ */

    function frame(now) {
      raf = requestAnimationFrame(frame);
      const raw = (now - last) / 1000;
      last = now;
      if (T > 3 && raw < 0.25) {
        perf.sum += raw;
        if (++perf.n >= 120) {
          degrade(perf.sum / perf.n);
          perf.sum = 0; perf.n = 0;
        }
      }
      update(Math.min(raw, 1 / 20));
      draw();
    }

    // Por debajo de ~40 fps baja la resolución; por debajo de ~24 fps quita el resplandor
    function degrade(avg) {
      if (avg > 1 / 40 && dpr > 1) { dpr = 1; resize(); }
      else if (avg > 1 / 24) o.bloom = false;
    }

    function renderStatic() {
      T = 0;
      for (let k = 0; k < 150; k++) update(1 / 30);
      draw();
    }

    function resize() {
      measure();
      build();
      if (motionQuery.matches) renderStatic();
    }

    function sync() {
      const go = inView && !paused && !document.hidden && !motionQuery.matches;
      if (go && !running) {
        running = true;
        last = performance.now();
        raf = requestAnimationFrame(frame);
      } else if (!go && running) {
        running = false;
        cancelAnimationFrame(raf);
      }
      if (motionQuery.matches) renderStatic();
    }

    function onMove(e) {
      if (e.pointerType && e.pointerType !== 'mouse') return;
      const rc = canvas.getBoundingClientRect();
      mouse.x = e.clientX - rc.left;
      mouse.y = e.clientY - rc.top;
      mouse.on = mouse.x >= 0 && mouse.y >= 0 && mouse.x <= W && mouse.y <= H;
      mouse.moved = true;
    }
    function onOut(e) { if (!e.relatedTarget) mouse.on = false; }

    const ro = new ResizeObserver(() => {
      cancelAnimationFrame(rzRaf);
      rzRaf = requestAnimationFrame(resize);
    });
    ro.observe(canvas);
    if (o.anchor) ro.observe(o.anchor);

    const io = new IntersectionObserver((entries) => {
      inView = entries[entries.length - 1].isIntersecting;
      sync();
    });
    io.observe(canvas);

    window.addEventListener('pointermove', onMove, { passive: true });
    document.addEventListener('mouseout', onOut);
    document.addEventListener('visibilitychange', sync);
    motionQuery.addEventListener('change', sync);

    measure();
    build();
    sync();

    return {
      // Cambia el ritmo de los datos sin recomponer la escena
      setActivity(a) { o.activity = Math.max(0.1, a); },
      // Detiene o reanuda la animación (p. ej. mientras está oculta)
      setPaused(p) { paused = !!p; sync(); },
      // Cambia opciones en caliente (p. ej. activar el modo integrado) y recompone la escena
      setOptions(next) {
        Object.assign(o, next);
        if (o.band) ro.observe(o.band);
        if (o.lineStart) ro.observe(o.lineStart);
        resize();
      },
      destroy() {
        running = false;
        cancelAnimationFrame(raf);
        cancelAnimationFrame(rzRaf);
        ro.disconnect();
        io.disconnect();
        window.removeEventListener('pointermove', onMove);
        document.removeEventListener('mouseout', onOut);
        document.removeEventListener('visibilitychange', sync);
        motionQuery.removeEventListener('change', sync);
      }
    };
  }

  global.DigitalLabHero = { mount, palettes: PALETTES };
})(window);
