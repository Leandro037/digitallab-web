/*!
 * Digital Lab · Animaciones del logo («ojo digital»)
 * Cinco animaciones en <canvas>, en neón, sin dependencias.
 *
 * Uso:
 *   <canvas class="logo"></canvas>   ← dale tamaño con CSS
 *   <script src="logo-animations.js"></script>
 *   <script>
 *     const ojo = DigitalLabLogo.mount(document.querySelector('.logo'), { variant: 'parpadeo', look: 'fino' });
 *     ojo.blink();   // fuerza un parpadeo (variantes con párpados)
 *   </script>
 *
 * Variantes: 'parpadeo' | 'encendido' | 'mirada' | 'energia' | 'escaneo'
 * Estilos (look): 'neon' (por defecto) | 'fino' | 'hilo' | 'ojo' | 'contorno'
 * Centrado (center): 'box' (por defecto, toda la caja del logo) | 'eye' (el ojo queda en el centro del canvas)
 * También se puede ajustar cualquier valor de STYLE_DEFAULTS con { style: { stroke: 9, ... } }
 */
(function (global) {
  'use strict';

  const TAU = Math.PI * 2;

  // Geometría del logo en sus unidades originales (caja 56..450 × 72..468)
  const LOGO = {
    box: { x: 56, y: 72, w: 394, h: 396 },
    center: { x: 265, y: 280 },          // centro de los dos arcos
    eye: { x: 265, y: 278, r: 108 },     // círculo oscuro
    iris: { x: 269, y: 275, r: 42 },
    shine: { dx: 17, dy: -16, r: 18 },   // brillo blanco, relativo al iris
    outer: 172, middle: 124, stroke: 15,
    nodeR: 27, nodeHole: 10, notch: 36,
    nodes: [
      { x: 96, y: 248 },   // 0 · exterior izquierdo
      { x: 168, y: 203 },  // 1 · interior izquierdo
      { x: 265, y: 108 },  // 2 · superior
      { x: 348, y: 188 }   // 3 · interior derecho
    ]
  };
  const nodeAngle = (n) => Math.atan2(n.y - LOGO.center.y, n.x - LOGO.center.x);
  const A_TOP = -Math.PI / 2;
  const A_OUT_L = nodeAngle(LOGO.nodes[0]) + TAU;
  const A_MID_L = nodeAngle(LOGO.nodes[1]) + TAU;
  const A_MID_R = nodeAngle(LOGO.nodes[3]);
  // Recorrido por el circuito según el nodo de entrada
  const ROUTES = [
    { r: LOGO.outer, from: A_OUT_L, to: A_TOP, end: 2 },
    { r: LOGO.middle, from: A_MID_L, to: A_MID_R, end: 3 },
    { r: LOGO.outer, from: A_TOP, to: A_OUT_L, end: 0 },
    { r: LOGO.middle, from: A_MID_R, to: A_MID_L, end: 1 }
  ];

  const NEON = 'rgb(125,255,58)';
  const HOT = 'rgb(236,255,214)';
  const TUBE = '#1c3311';

  // Estilo de las líneas y del ojo (unidades del logo). Los valores por defecto son el neón original.
  const STYLE_DEFAULTS = {
    arc: 'neon',          // 'neon' | 'outline' (solo el borde del tubo)
    stroke: 15,           // grosor de los arcos
    core: 3.5, coreA: 0.7, // núcleo blanco del tubo de neón
    arcGlow: 0.85,        // cuánto resplandecen los arcos
    wideGlow: 0.1,        // halo ancho alrededor de los arcos
    node: 'disc',         // 'disc' | 'ring'
    nodeR: 27, nodeHole: 10, ringW: 0, notch: 36,
    arms: ['#47a514', '#8cff45'],
    eyeRim: 0.22,         // borde de luz del círculo oscuro
    eyeGlow: 0,           // halo exterior del ojo
    eyeGlowW: 6,          // grosor del trazo que genera ese halo
    irisHalo: 1,          // brillo alrededor del iris
    nodeHalo: 1,          // halo alrededor de los nodos
    nodeFlash: 1,         // destello de los nodos al recibir energía
    lid: 5,               // grosor de los párpados
    lidCore: true,        // núcleo blanco en el párpado
    bloom: 1              // intensidad del resplandor difuso
  };

  // Variantes de estilo pensadas para que el ojo destaque más que el circuito
  const LOOKS = {
    neon: {},
    fino: {
      stroke: 7, core: 1.8, coreA: 0.6, arcGlow: 0.6, wideGlow: 0.05,
      nodeR: 17, nodeHole: 6.5, notch: 26,
      eyeRim: 0.45, eyeGlow: 0.3, irisHalo: 1.4
    },
    // Una sola línea fina con un brillo muy leve: sin núcleo blanco, sin bandas y con poco resplandor
    hilo: {
      stroke: 3, core: 0, arcGlow: 0.3, wideGlow: 0, bloom: 0.45,
      arms: ['#5fd41f', '#a6ff57'],
      node: 'ring', nodeR: 12, ringW: 3, nodeHole: 3.5, notch: 20, nodeHalo: 0.15, nodeFlash: 0.2,
      eyeRim: 0.55, eyeGlow: 0.25, eyeGlowW: 3, irisHalo: 1.4,
      lid: 3, lidCore: false
    },
    ojo: {
      arms: ['#3f700c', '#5ad41c'], core: 0, arcGlow: 0.12, wideGlow: 0,
      eyeRim: 0.7, eyeGlow: 0.6, irisHalo: 1.8
    },
    contorno: {
      arc: 'outline', core: 0, arcGlow: 0.45, wideGlow: 0.03,
      node: 'ring', ringW: 2.4, nodeHole: 8,
      eyeRim: 0.5, eyeGlow: 0.35, irisHalo: 1.5
    }
  };

  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const lerp = (a, b, t) => a + (b - a) * t;
  const smooth = (t) => { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); };
  const easeIn = (t) => { t = clamp(t, 0, 1); return t * t; };
  const easeOut = (t) => 1 - Math.pow(1 - clamp(t, 0, 1), 3);
  const easeInOut = (t) => { t = clamp(t, 0, 1); return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
  const easeOutBack = (t) => { t = clamp(t, 0, 1); const c = 1.70158; return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); };
  const neonA = (a) => `rgba(125,255,58,${a})`;

  /* ------------------------------------------------------------------ */
  /* Comportamientos compartidos                                         */
  /* ------------------------------------------------------------------ */

  // Apertura del párpado (1 abierto → 0 cerrado) t segundos después de empezar un parpadeo
  function blinkCurve(t) {
    if (t < 0 || t > 0.4) return 1;
    if (t < 0.12) return 1 - easeIn(t / 0.12);
    if (t < 0.17) return 0;
    return easeOut((t - 0.17) / 0.23);
  }

  // Mirada con movimientos sacádicos: saltos rápidos, fijación y un leve temblor
  function makeGaze(range) { return { range, x: 0, y: 0, tx: 0, ty: 0, next: 0.6 }; }
  function lookAround(g, dt, T, target) {
    if (target) {
      g.tx = target.x; g.ty = target.y;
    } else if (T > g.next) {
      const a = Math.random() * TAU, m = Math.random() < 0.3 ? 0 : (0.45 + Math.random() * 0.55) * g.range;
      g.tx = Math.cos(a) * m;
      g.ty = Math.sin(a) * m * 0.75;
      g.next = T + 0.6 + Math.random() * 1.8;
    }
    const k = 1 - Math.exp(-dt * (target ? 9 : 20));
    g.x += (g.tx - g.x) * k;
    g.y += (g.ty - g.y) * k;
    return [g.x + Math.sin(T * 13.1) * 0.35, g.y + Math.cos(T * 10.7) * 0.35];
  }

  function baseState() {
    return {
      arcs: 1, arcI: 1, tube: 0, eye: 1, lid: 1,
      iris: 1, irisScale: 1, ix: 0, iy: 0, irisGlow: 0,
      nodes: [0, 1, 2, 3].map(() => ({ pop: 1, glow: 0 })),
      pulses: [], ripples: []
    };
  }

  /* ------------------------------------------------------------------ */
  /* Escena: dibuja el logo en neón a partir de un estado                */
  /* ------------------------------------------------------------------ */

  // center: 'box' centra la caja completa del logo; 'eye' centra el ojo (útil sobre un texto centrado)
  function createScene(canvas, fit, S, center) {
    const ctx = canvas.getContext('2d');
    const bufs = [0, 1, 2].map(() => {
      const c = document.createElement('canvas');
      return { c, x: c.getContext('2d') };
    });
    const sc = { W: 1, H: 1, dpr: 1, s: 1, ox: 0, oy: 0 };

    // Degradados en unidades del logo: valen para cualquier tamaño
    const armGrad = ctx.createLinearGradient(80, 90, 440, 460);
    armGrad.addColorStop(0, S.arms[0]);
    armGrad.addColorStop(1, S.arms[1]);
    const eyeGrad = ctx.createRadialGradient(232, 236, 6, LOGO.eye.x, LOGO.eye.y, LOGO.eye.r + 4);
    eyeGrad.addColorStop(0, '#2c3236');
    eyeGrad.addColorStop(1, '#101416');
    const irisGrad = ctx.createRadialGradient(-12, -14, 2, 0, 0, LOGO.iris.r);
    irisGrad.addColorStop(0, '#d2ff94');
    irisGrad.addColorStop(0.45, '#86f040');
    irisGrad.addColorStop(1, '#3fae16');

    // En tamaños pequeños (p. ej. el logo del menú) las líneas finas se quedarían por debajo
    // de un píxel: se engrosan lo justo para seguir siendo legibles.
    const base = Object.assign({}, S);
    function fitStyle() {
      const k = 1 / sc.s;   // unidades del logo por píxel
      S.stroke = Math.max(base.stroke, 1.4 * k);
      S.core = base.core > 0 ? Math.min(S.stroke * 0.5, Math.max(base.core, 0.5 * k)) : 0;
      S.ringW = base.ringW > 0 ? Math.max(base.ringW, 1.2 * k) : 0;
      S.nodeR = Math.max(base.nodeR, 3.4 * k);
      S.nodeHole = Math.max(base.nodeHole, 1.1 * k);
      S.notch = Math.max(base.notch, S.nodeR + 8);
      S.rimW = Math.max(base.eyeRim > 0.4 ? 3 : 2.5, 0.9 * k);
      S.lidW = Math.max(base.lid, 1.2 * k);
      S.eyeGlowW = Math.max(base.eyeGlowW, 1.2 * k);
    }

    sc.maxDpr = 2;
    sc.resize = function () {
      const r = canvas.getBoundingClientRect();
      sc.W = Math.max(1, r.width);
      sc.H = Math.max(1, r.height);
      sc.dpr = Math.min(window.devicePixelRatio || 1, sc.maxDpr);
      canvas.width = Math.round(sc.W * sc.dpr);
      canvas.height = Math.round(sc.H * sc.dpr);
      sc.s = (Math.min(sc.W, sc.H) * fit) / LOGO.box.h;
      fitStyle();
      sc.ox = sc.W / 2 - (center === 'eye' ? LOGO.eye.x : LOGO.box.x + LOGO.box.w / 2) * sc.s;
      sc.oy = sc.H / 2 - (LOGO.box.y + LOGO.box.h / 2) * sc.s;
      let bw = canvas.width, bh = canvas.height;
      bufs.forEach((b, k) => {
        const f = k === 0 ? 4 : 2;
        bw = Math.max(1, Math.round(bw / f));
        bh = Math.max(1, Math.round(bh / f));
        b.c.width = bw;
        b.c.height = bh;
        b.x.imageSmoothingQuality = 'high';
      });
      ctx.imageSmoothingQuality = 'high';
    };

    sc.unit = () => ctx.setTransform(sc.s * sc.dpr, 0, 0, sc.s * sc.dpr, sc.ox * sc.dpr, sc.oy * sc.dpr);
    sc.toPx = (u, v) => ({ x: sc.ox + u * sc.s, y: sc.oy + v * sc.s });

    // Con nodos en anillo, los arcos terminan en el borde del anillo en vez de llegar al centro
    function trim(r) {
      return S.node === 'ring' ? (S.nodeR + S.ringW / 2) / r : 0;
    }

    function strokeCircuit(p) {
      const C = LOGO.center, tO = trim(LOGO.outer), tM = trim(LOGO.middle);
      const o0 = A_TOP + tO, o1 = A_OUT_L - tO, m0 = A_MID_L - tM, m1 = A_MID_R + tM;
      const lenO = LOGO.outer * (o1 - o0), lenM = LOGO.middle * (m0 - m1);
      if (p < 1) { ctx.setLineDash([lenO, lenO]); ctx.lineDashOffset = lenO * (1 - p); }
      ctx.beginPath();
      ctx.arc(C.x, C.y, LOGO.outer, o0, o1, false);
      ctx.stroke();
      if (p < 1) { ctx.setLineDash([lenM, lenM]); ctx.lineDashOffset = lenM * (1 - p); }
      ctx.beginPath();
      ctx.arc(C.x, C.y, LOGO.middle, m0, m1, true);
      ctx.stroke();
      ctx.setLineDash([]);
    }

    // Bandas del tubo, para dibujar solo su borde en el estilo de contorno
    const BANDS = [
      { r: LOGO.outer, a0: A_TOP, a1: A_OUT_L, ccw: false },
      { r: LOGO.middle, a0: A_MID_L, a1: A_MID_R, ccw: true }
    ];
    function bandPath(b) {
      const C = LOGO.center, h = S.stroke / 2, dir = b.ccw ? -1 : 1, t = trim(b.r);
      const a0 = b.a0 + dir * t, a1 = b.a1 - dir * t;
      ctx.beginPath();
      ctx.arc(C.x, C.y, b.r + h, a0, a1, b.ccw);
      ctx.arc(C.x, C.y, b.r - h, a1, a0, !b.ccw);
      ctx.closePath();
    }
    function strokeBands() {
      for (const b of BANDS) { bandPath(b); ctx.stroke(); }
    }

    // Cometas de luz por los arcos: { k: ruta, p: posición 0..1, trail: radianes }
    function strokeComets(st, width, color, alpha, heads) {
      const C = LOGO.center;
      ctx.strokeStyle = color;
      ctx.lineWidth = width;
      for (const ap of st.pulses) {
        const rt = ROUTES[ap.k], dir = Math.sign(rt.to - rt.from);
        const from = rt.from + dir * trim(rt.r), to = rt.to - dir * trim(rt.r);
        const head = from + (to - from) * clamp(ap.p, 0, 1);
        for (let q = 0; q < 6; q++) {
          let a0 = head - dir * ap.trail * (1 - q / 6);
          let a1 = head - dir * ap.trail * (1 - (q + 1) / 6);
          if (dir > 0) { a0 = Math.max(a0, from); a1 = Math.max(a1, from); }
          else { a0 = Math.min(a0, from); a1 = Math.min(a1, from); }
          if (a0 === a1) continue;
          const w = (q + 1) / 6;
          ctx.globalAlpha = w * w * alpha;
          ctx.beginPath();
          ctx.arc(C.x, C.y, rt.r, Math.min(a0, a1), Math.max(a0, a1), false);
          ctx.stroke();
        }
        if (heads) {
          ctx.fillStyle = '#ffffff';
          ctx.globalAlpha = 0.95;
          ctx.beginPath();
          ctx.arc(C.x + Math.cos(head) * rt.r, C.y + Math.sin(head) * rt.r, Math.max(2.5, S.stroke * 0.4), 0, TAU);
          ctx.fill();
        }
      }
    }

    function lidGeom(o) {
      const E = LOGO.eye, r = E.r, y = E.y + r * 0.12;
      return {
        x0: E.x - r * 1.25, x1: E.x + r * 1.25, y,
        up: lerp(y + 0.26 * r, y - 2.5 * r, o),
        lo: lerp(y + 0.26 * r, y + 2.2 * r, o)
      };
    }

    function lensPath(o) {
      const L = lidGeom(o), cx = LOGO.eye.x;
      ctx.beginPath();
      ctx.moveTo(L.x0, L.y);
      ctx.quadraticCurveTo(cx, L.up, L.x1, L.y);
      ctx.quadraticCurveTo(cx, L.lo, L.x0, L.y);
      ctx.closePath();
    }

    // Bordes de los párpados: líneas de neón que se cierran sobre el iris
    function drawLids(o, er) {
      const a = smooth((1 - o) * 5);
      if (a <= 0) return;
      const E = LOGO.eye, L = lidGeom(o);
      const curve = (ctrl) => {
        ctx.beginPath();
        ctx.moveTo(L.x0, L.y);
        ctx.quadraticCurveTo(E.x, ctrl, L.x1, L.y);
      };
      ctx.save();
      ctx.beginPath();
      ctx.arc(E.x, E.y, er, 0, TAU);
      ctx.clip();
      ctx.globalCompositeOperation = 'lighter';
      ctx.lineCap = 'round';
      ctx.strokeStyle = NEON;
      // Brillo con sombra difusa: sin bandas de bordes duros alrededor de la línea
      ctx.shadowColor = NEON;
      ctx.shadowBlur = 14 * sc.dpr;
      ctx.globalAlpha = 0.9 * a; ctx.lineWidth = S.lidW; curve(L.up); ctx.stroke();
      ctx.globalAlpha = 0.5 * a; ctx.lineWidth = S.lidW * 0.7; curve(L.lo); ctx.stroke();
      ctx.shadowBlur = 0;
      if (S.lidCore) {
        ctx.strokeStyle = HOT;
        ctx.globalAlpha = 0.9 * a; ctx.lineWidth = S.lidW * 0.32; curve(L.up); ctx.stroke();
      }
      ctx.restore();
    }

    function drawEye(st, V, T) {
      if (st.eye <= 0) return;
      const E = LOGO.eye, eyeA = clamp(st.eye, 0, 1);
      const er = E.r * (0.82 + 0.18 * eyeA);

      // Círculo oscuro con las muescas de los nodos interiores y un borde de neón suave
      ctx.save();
      ctx.beginPath();
      ctx.rect(0, 0, 512, 512);
      for (const k of [1, 3]) {
        const n = LOGO.nodes[k];
        ctx.moveTo(n.x + S.notch, n.y);
        ctx.arc(n.x, n.y, S.notch, 0, TAU);
      }
      ctx.clip('evenodd');
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = eyeA;
      ctx.fillStyle = eyeGrad;
      ctx.beginPath();
      ctx.arc(E.x, E.y, er, 0, TAU);
      ctx.fill();
      ctx.globalCompositeOperation = 'lighter';
      ctx.strokeStyle = neonA(S.eyeRim * eyeA);
      ctx.lineWidth = S.rimW;
      ctx.stroke();
      ctx.restore();

      // Interior: recortado por el círculo y, si parpadea, por los párpados
      ctx.save();
      ctx.beginPath();
      ctx.arc(E.x, E.y, er - 1.5, 0, TAU);
      ctx.clip();
      if (st.lid < 0.999) { lensPath(st.lid); ctx.clip(); }

      if (st.iris > 0) {
        const ix = LOGO.iris.x + st.ix, iy = LOGO.iris.y + st.iy;
        const ir = LOGO.iris.r * st.irisScale * st.iris;
        const hr = Math.max(ir + 1, LOGO.iris.r * (1.6 + 0.3 * S.irisHalo + st.irisGlow * 0.7));
        const g = ctx.createRadialGradient(ix, iy, ir * 0.8, ix, iy, hr);
        g.addColorStop(0, neonA(Math.min(1, 0.2 * S.irisHalo + st.irisGlow * 0.3) * Math.min(1, st.iris)));
        g.addColorStop(1, neonA(0));
        ctx.globalCompositeOperation = 'lighter';
        ctx.globalAlpha = 1;
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(ix, iy, hr, 0, TAU);
        ctx.fill();

        ctx.save();
        ctx.translate(ix, iy);
        const k = ir / LOGO.iris.r;
        ctx.scale(k, k);
        ctx.globalCompositeOperation = 'source-over';
        ctx.fillStyle = irisGrad;
        ctx.beginPath();
        ctx.arc(0, 0, LOGO.iris.r, 0, TAU);
        ctx.fill();
        if (st.irisGlow > 0.02) {
          ctx.globalCompositeOperation = 'lighter';
          ctx.globalAlpha = Math.min(0.6, st.irisGlow * 0.45);
          ctx.fillStyle = HOT;
          ctx.fill();
          ctx.globalAlpha = 1;
        }
        ctx.globalCompositeOperation = 'source-over';
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(LOGO.shine.dx + st.ix * 0.2, LOGO.shine.dy + st.iy * 0.2, LOGO.shine.r, 0, TAU);
        ctx.fill();
        ctx.restore();
      }

      ctx.globalCompositeOperation = 'lighter';
      if (V.inner) V.inner(ctx, st, T);
      ctx.strokeStyle = NEON;
      for (const rp of st.ripples) {
        ctx.globalAlpha = (1 - rp.t) * 0.55;
        ctx.lineWidth = 3 * (1 - rp.t) + 0.6;
        ctx.beginPath();
        ctx.arc(E.x, E.y, 46 + rp.t * 58, 0, TAU);
        ctx.stroke();
      }
      ctx.restore();

      if (st.lid < 0.999) drawLids(st.lid, er);
    }

    // Resplandor barato: reducir la escena y sumarla de nuevo ampliada
    function bloom() {
      const [a, b, c] = bufs;
      a.x.globalCompositeOperation = 'copy';
      a.x.drawImage(canvas, 0, 0, a.c.width, a.c.height);
      b.x.globalCompositeOperation = 'copy';
      b.x.drawImage(a.c, 0, 0, b.c.width, b.c.height);
      c.x.globalCompositeOperation = 'copy';
      c.x.drawImage(b.c, 0, 0, c.c.width, c.c.height);
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.globalCompositeOperation = 'lighter';
      ctx.globalAlpha = 0.55 * S.bloom; ctx.drawImage(a.c, 0, 0, canvas.width, canvas.height);
      ctx.globalAlpha = 0.65 * S.bloom; ctx.drawImage(b.c, 0, 0, canvas.width, canvas.height);
      ctx.globalAlpha = 0.75 * S.bloom; ctx.drawImage(c.c, 0, 0, canvas.width, canvas.height);
    }

    sc.render = function (st, V, T) {
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = 1;
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      sc.unit();
      ctx.lineCap = 'butt';

      const outline = S.arc === 'outline' && st.arcs >= 1;
      const nodeGlowA = 0.8 * (S.arcGlow / 0.85);

      // A · Capa de brillo: lo que debe resplandecer
      ctx.globalCompositeOperation = 'lighter';
      if (V.glow) V.glow(ctx, st, T);
      if (S.eyeGlow > 0 && st.eye > 0) {
        ctx.strokeStyle = NEON;
        ctx.globalAlpha = S.eyeGlow * clamp(st.eye, 0, 1);
        ctx.lineWidth = S.eyeGlowW;
        ctx.beginPath();
        ctx.arc(LOGO.eye.x, LOGO.eye.y, LOGO.eye.r, 0, TAU);
        ctx.stroke();
      }
      if (st.arcs > 0 && st.arcI > 0) {
        ctx.strokeStyle = NEON;
        if (S.wideGlow > 0) {
          ctx.globalAlpha = S.wideGlow * st.arcI; ctx.lineWidth = S.stroke * 2.6; strokeCircuit(st.arcs);
        }
        ctx.globalAlpha = S.arcGlow * st.arcI;
        if (outline) { ctx.lineWidth = 2.4; strokeBands(); }
        else { ctx.lineWidth = S.stroke; strokeCircuit(st.arcs); }
      }
      strokeComets(st, Math.max(3, S.stroke * 1.4), NEON, 1, false);
      st.nodes.forEach((n, k) => {
        if (n.pop <= 0) return;
        const u = LOGO.nodes[k], hr = S.nodeR * (1.8 + n.glow);
        const g = ctx.createRadialGradient(u.x, u.y, S.nodeR * 0.6, u.x, u.y, hr);
        g.addColorStop(0, neonA(0.3 * (0.5 + n.glow * 0.6) * Math.min(1, S.arcGlow / 0.6) * S.nodeHalo));
        g.addColorStop(1, neonA(0));
        ctx.globalAlpha = Math.min(1, n.pop);
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(u.x, u.y, hr, 0, TAU);
        ctx.fill();
        ctx.globalAlpha = nodeGlowA * Math.min(1, n.pop);
        ctx.beginPath();
        ctx.arc(u.x, u.y, S.nodeR * n.pop, 0, TAU);
        if (S.node === 'ring') { ctx.lineWidth = S.ringW; ctx.stroke(); }
        else { ctx.fillStyle = NEON; ctx.fill(); }
      });

      // B · Resplandor
      bloom();
      sc.unit();

      // C · Ojo nítido encima del resplandor
      drawEye(st, V, T);

      // D · Circuito nítido: tubo apagado, tubo de neón y núcleo caliente
      if (st.tube > 0) {
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = st.tube;
        ctx.strokeStyle = TUBE;
        ctx.lineWidth = S.stroke;
        strokeCircuit(1);
        ctx.fillStyle = TUBE;
        for (const u of LOGO.nodes) { ctx.beginPath(); ctx.arc(u.x, u.y, S.nodeR, 0, TAU); ctx.fill(); }
        ctx.fillStyle = '#33452a';
        for (const u of LOGO.nodes) { ctx.beginPath(); ctx.arc(u.x, u.y, S.nodeHole, 0, TAU); ctx.fill(); }
      }
      if (st.arcs > 0 && st.arcI > 0) {
        if (outline) {
          // Tubo hueco: relleno muy tenue y borde de neón
          ctx.globalCompositeOperation = 'lighter';
          ctx.fillStyle = NEON;
          ctx.strokeStyle = NEON;
          ctx.lineWidth = 2.4;
          for (const b of BANDS) {
            bandPath(b);
            ctx.globalAlpha = 0.08 * st.arcI; ctx.fill();
            ctx.globalAlpha = 0.8 * st.arcI; ctx.stroke();
          }
        } else {
          ctx.globalCompositeOperation = 'source-over';
          ctx.globalAlpha = Math.min(1, st.arcI);
          ctx.strokeStyle = armGrad;
          ctx.lineWidth = S.stroke;
          strokeCircuit(st.arcs);
          if (S.core > 0) {
            ctx.globalCompositeOperation = 'lighter';
            ctx.globalAlpha = S.coreA * st.arcI;
            ctx.strokeStyle = HOT;
            ctx.lineWidth = S.core;
            strokeCircuit(st.arcs);
          }
        }
      }
      ctx.globalCompositeOperation = 'lighter';
      strokeComets(st, Math.max(2, S.stroke * 0.8), HOT, 0.95, true);

      st.nodes.forEach((n, k) => {
        if (n.pop <= 0) return;
        const u = LOGO.nodes[k];
        ctx.beginPath();
        ctx.arc(u.x, u.y, S.nodeR * n.pop, 0, TAU);
        if (S.node === 'ring') {
          // Anillo con punto blanco: con contorno va en neón, si no con el degradado del logo
          ctx.globalCompositeOperation = S.arc === 'outline' ? 'lighter' : 'source-over';
          ctx.globalAlpha = 1;
          ctx.strokeStyle = S.arc === 'outline' ? NEON : armGrad;
          ctx.lineWidth = S.ringW;
          ctx.stroke();
          if (n.glow > 0.03) {
            ctx.globalCompositeOperation = 'lighter';
            ctx.globalAlpha = Math.min(0.5, n.glow * 0.4) * S.nodeFlash;
            ctx.fillStyle = NEON;
            ctx.fill();
          }
        } else {
          ctx.globalCompositeOperation = 'source-over';
          ctx.globalAlpha = 1;
          ctx.fillStyle = armGrad;
          ctx.fill();
          if (n.glow > 0.03) {
            ctx.globalCompositeOperation = 'lighter';
            ctx.globalAlpha = Math.min(0.9, n.glow * 0.8);
            ctx.fillStyle = NEON;
            ctx.fill();
          }
        }
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(u.x, u.y, S.nodeHole * n.pop, 0, TAU);
        ctx.fill();
      });

      ctx.globalCompositeOperation = 'lighter';
      if (V.top) V.top(ctx, st, T);
    };

    return sc;
  }

  /* ------------------------------------------------------------------ */
  /* Variantes                                                           */
  /* ------------------------------------------------------------------ */

  // Brillo del neón al encenderse: parpadea unas veces y se estabiliza
  function ignite(t) {
    const seq = [[1.85, 0.25], [1.92, 1], [2.0, 0.15], [2.06, 0.9], [2.16, 0.35], [2.22, 1]];
    let v = 1;
    for (const [at, val] of seq) if (t >= at) v = val;
    return v;
  }

  // Partículas de datos que viajan hacia un nodo
  function spawnPart(p, initial) {
    const k = Math.floor(Math.random() * 4), n = LOGO.nodes[k];
    const a = nodeAngle(n) + (Math.random() - 0.5) * 1.3;
    const r = 290 + Math.random() * 70;
    p.k = k;
    p.x = LOGO.center.x + Math.cos(a) * r;
    p.y = LOGO.center.y + Math.sin(a) * r;
    if (initial) {
      const f = Math.random() * 0.7;
      p.x = lerp(p.x, n.x, f);
      p.y = lerp(p.y, n.y, f);
    }
    p.dx = 0; p.dy = 0;
    p.v = 30 + Math.random() * 60;
    p.life = initial ? 1 : 0;
    p.size = 1.6 + Math.random() * 2.2;
    return p;
  }

  const VARIANTS = {
    // El ojo mira a su alrededor y parpadea; a veces dos veces seguidas
    parpadeo: {
      fit: 0.74,
      init(st) {
        st.gaze = makeGaze(20);
        st.nextBlink = 1.2; st.blinkAt = -10; st.double = false; st.wasClosed = false;
      },
      trigger(st, T) {
        if (T - st.blinkAt > 0.45) {
          st.blinkAt = T; st.double = false;
          st.nextBlink = T + 2.6 + Math.random() * 1.5;
        }
      },
      update(st, dt, T) {
        if (T >= st.nextBlink) {
          st.blinkAt = T;
          st.double = Math.random() < 0.3;
          st.nextBlink = T + 2.4 + Math.random() * 2;
        }
        let open = blinkCurve(T - st.blinkAt);
        if (st.double) open = Math.min(open, blinkCurve(T - st.blinkAt - 0.42));
        if (open < 0.05) st.wasClosed = true;
        else if (st.wasClosed) { st.wasClosed = false; st.irisGlow = 1; st.gaze.next = T; }
        st.lid = open;
        st.irisGlow *= Math.exp(-dt * 3.5);
        st.arcI = 0.7 + 0.3 * open;
        for (const n of st.nodes) n.glow = st.irisGlow * 0.6;
        [st.ix, st.iy] = lookAround(st.gaze, dt, T, null);
      }
    },

    // Arranque: el tubo de neón se dibuja desde los nodos, parpadea al encender y el ojo se abre
    encendido: {
      fit: 0.74, still: 3.2,
      init(st) { st.gaze = makeGaze(12); st.prevT = 0; },
      update(st, dt, T) {
        const t = T % 7.2;
        if (t < st.prevT) st.ripples.length = 0;
        const off = smooth((t - 5.9) / 0.7);
        st.tube = smooth(t / 0.4) * (1 - smooth((t - 6.6) / 0.5));
        st.eye = smooth((t - 0.15) / 0.5) * (1 - smooth((t - 6.5) / 0.5));
        const draw = easeInOut((t - 0.5) / 1.3);
        st.arcs = draw;
        st.arcI = ignite(t) * (1 - off);
        st.pulses = t > 0.5 && t < 1.85 ? [{ k: 2, p: draw, trail: 0.7 }, { k: 1, p: draw, trail: 0.7 }] : [];
        const at = [1.75, 0.45, 0.45, 1.8];
        st.nodes.forEach((n, k) => {
          const since = t - at[k];
          n.pop = easeOutBack(since / 0.4) * (1 - off);
          n.glow = since > 0 ? Math.exp(-since * 3) * 1.2 * (1 - off) : 0;
        });
        st.iris = easeOutBack((t - 2.3) / 0.5) * (1 - easeInOut((t - 5.6) / 0.4));
        if (st.prevT < 2.35 && t >= 2.35) st.ripples.push({ t: 0 });
        st.irisGlow = t > 2.3 ? Math.exp(-(t - 2.3) * 2.5) * 1.2 : 0;
        st.lid = blinkCurve(t - 4.2);
        [st.ix, st.iy] = lookAround(st.gaze, dt, T, t > 2.8 && t < 5.3 ? null : { x: 0, y: 0 });
        st.prevT = t;
      }
    },

    // Sigue al cursor por toda la página y dilata el iris al acercarse
    mirada: {
      fit: 0.74, pointer: true,
      init(st) { st.gaze = makeGaze(16); st.nextBlink = 3; st.blinkAt = -10; },
      trigger(st, T) { st.blinkAt = T; },
      update(st, dt, T, input, sc) {
        let target = null, near = 0;
        if (input.pointer) {
          const c = sc.toPx(LOGO.iris.x, LOGO.iris.y), reach = LOGO.eye.r * sc.s;
          const dx = input.pointer.x - c.x, dy = input.pointer.y - c.y, d = Math.hypot(dx, dy) || 1;
          const m = Math.min(1, d / (reach * 2.5)) * 24;
          target = { x: (dx / d) * m, y: (dy / d) * m };
          near = 1 - smooth((d - reach * 0.5) / (reach * 2.5));
          st.gaze.next = T + 1.2;
        }
        [st.ix, st.iy] = lookAround(st.gaze, dt, T, target);
        const k = 1 - Math.exp(-dt * 5);
        st.irisScale += (1 + near * 0.25 - st.irisScale) * k;
        st.irisGlow += (near * 0.7 - st.irisGlow) * k;
        // El nodo hacia el que mira se ilumina
        const mag = Math.min(1, Math.hypot(st.ix, st.iy) / 20), ang = Math.atan2(st.iy, st.ix);
        st.nodes.forEach((n, j) => {
          const c = Math.cos(ang - nodeAngle(LOGO.nodes[j]));
          n.glow = mag * Math.pow(Math.max(0, c), 4) * 1.3;
        });
        if (T >= st.nextBlink) { st.blinkAt = T; st.nextBlink = T + 3.5 + Math.random() * 3; }
        st.lid = blinkCurve(T - st.blinkAt);
      }
    },

    // Los datos entran por los nodos, recorren el circuito y cargan el ojo
    energia: {
      fit: 0.7,
      init(st) {
        st.gaze = makeGaze(8);
        st.parts = [];
        for (let i = 0; i < 34; i++) st.parts.push(spawnPart({}, true));
      },
      update(st, dt, T) {
        for (const p of st.parts) {
          const n = LOGO.nodes[p.k];
          const dx = n.x - p.x, dy = n.y - p.y, d = Math.hypot(dx, dy) || 1;
          p.v = Math.min(p.v + dt * 160, 420);
          p.dx = dx / d; p.dy = dy / d;
          p.x += p.dx * p.v * dt;
          p.y += p.dy * p.v * dt;
          p.life = Math.min(1, p.life + dt * 2);
          if (d < LOGO.nodeR) {
            const node = st.nodes[p.k];
            node.glow = Math.min(1.6, node.glow + 0.35);
            if (st.pulses.length < 6 && Math.random() < 0.55) {
              st.pulses.push({ k: p.k, t: 0, p: 0, dur: 0.9 + Math.random() * 0.5, trail: 0.55 + Math.random() * 0.3 });
            }
            spawnPart(p, false);
          }
        }
        for (let i = st.pulses.length - 1; i >= 0; i--) {
          const ap = st.pulses[i];
          ap.t += dt / ap.dur;
          ap.p = easeInOut(ap.t);
          if (ap.t >= 1) {
            const end = st.nodes[ROUTES[ap.k].end];
            end.glow = Math.min(1.6, end.glow + 0.6);
            st.irisGlow = Math.min(1.4, st.irisGlow + 0.4);
            if (st.ripples.length < 4) st.ripples.push({ t: 0 });
            st.pulses.splice(i, 1);
          }
        }
        for (const n of st.nodes) n.glow *= Math.exp(-dt * 3);
        st.irisGlow *= Math.exp(-dt * 1.8);
        [st.ix, st.iy] = lookAround(st.gaze, dt, T, null);
      },
      glow(ctx, st) {
        ctx.lineCap = 'round';
        ctx.strokeStyle = NEON;
        for (const p of st.parts) {
          const len = clamp(p.v * 0.045, 3, 18);   // estela según la velocidad, no los fps
          ctx.globalAlpha = 0.9 * p.life;
          ctx.lineWidth = p.size;
          ctx.beginPath();
          ctx.moveTo(p.x - p.dx * len, p.y - p.dy * len);
          ctx.lineTo(p.x, p.y);
          ctx.stroke();
        }
        ctx.lineCap = 'butt';
      }
    },

    // Un láser recorre la retina, el visor gira y los nodos confirman la lectura
    escaneo: {
      fit: 0.6, still: 3.8,
      init(st) { st.gaze = makeGaze(10); st.prevT = 0; st.scanY = null; st.scanA = 0; st.hud = 0; st.match = 0; st.bracket = 0; },
      update(st, dt, T) {
        const t = T % 5.6, E = LOGO.eye, m0 = 3.0;
        st.hud = smooth(t / 0.5) * (1 - smooth((t - 5.0) / 0.5));
        if (t > 0.5 && t < 2.9) {
          st.scanY = t < 2.3
            ? lerp(E.y - E.r, E.y + E.r, easeInOut((t - 0.5) / 1.8))
            : lerp(E.y + E.r, E.y - E.r, easeInOut((t - 2.3) / 0.6));
          st.scanA = smooth((t - 0.5) / 0.15) * (1 - smooth((t - 2.75) / 0.15));
        } else {
          st.scanY = null;
        }
        st.nodes.forEach((n, k) => {
          const since = t - (m0 + k * 0.12);
          n.glow = since > 0 ? Math.exp(-since * 2.2) * 1.3 : 0;
        });
        if (st.prevT < m0 + 0.4 && t >= m0 + 0.4) st.ripples.push({ t: 0 });
        st.match = t > m0 + 0.4 ? Math.exp(-(t - m0 - 0.4) * 2) : 0;
        st.irisGlow = st.match * 0.9;
        st.irisScale = 1 - 0.12 * Math.sin(clamp((t - m0 - 0.3) / 0.6, 0, 1) * Math.PI);
        st.bracket = t > m0 ? easeOutBack((t - m0 - 0.35) / 0.35) : 0;
        [st.ix, st.iy] = lookAround(st.gaze, dt, T, t > 3.6 && t < 5.2 ? null : { x: 0, y: 0 });
        st.prevT = t;
      },
      glow(ctx, st, T) {
        const C = LOGO.center, R = 205;
        // Visor exterior: marcas que giran, más brillantes bajo el barrido
        ctx.strokeStyle = NEON;
        ctx.lineWidth = 2;
        const sweep = T * 1.6;
        for (let i = 0; i < 72; i++) {
          const a = (i / 72) * TAU + T * 0.15;
          const len = i % 6 === 0 ? 14 : 7;
          const near = Math.pow(Math.max(0, Math.cos(a - sweep)), 12);
          ctx.globalAlpha = st.hud * (0.28 + near * 0.7);
          ctx.beginPath();
          ctx.moveTo(C.x + Math.cos(a) * R, C.y + Math.sin(a) * R);
          ctx.lineTo(C.x + Math.cos(a) * (R + len), C.y + Math.sin(a) * (R + len));
          ctx.stroke();
        }
        // Esquinas del encuadre, que se cierran al confirmar
        const h = lerp(240, 214, st.bracket), arm = 34;
        ctx.lineWidth = 4;
        ctx.globalAlpha = st.hud * (0.45 + 0.55 * st.match);
        for (const [sx, sy] of [[-1, -1], [1, -1], [1, 1], [-1, 1]]) {
          const x = C.x + sx * h, y = C.y + sy * h;
          ctx.beginPath();
          ctx.moveTo(x, y - sy * arm);
          ctx.lineTo(x, y);
          ctx.lineTo(x - sx * arm, y);
          ctx.stroke();
        }
        // El láser también cruza el visor, más tenue
        if (st.scanY != null) {
          ctx.globalAlpha = 0.25 * st.scanA;
          ctx.fillStyle = NEON;
          ctx.fillRect(C.x - R, st.scanY - 0.8, R * 2, 1.6);
        }
      },
      inner(ctx, st, T) {
        const E = LOGO.eye;
        ctx.save();
        ctx.translate(E.x, E.y);
        ctx.rotate(-T * 0.6);
        ctx.setLineDash([3, 9]);
        ctx.strokeStyle = NEON;
        ctx.lineWidth = 2;
        ctx.globalAlpha = 0.25 * st.hud;
        ctx.beginPath();
        ctx.arc(0, 0, 86, 0, TAU);
        ctx.stroke();
        ctx.restore();

        if (st.scanY == null) return;
        const y = st.scanY, a = st.scanA;
        // Retícula de puntos que el láser va revelando
        ctx.fillStyle = NEON;
        for (let gy = E.y - E.r; gy <= E.y + E.r; gy += 12) {
          const dy = Math.abs(gy - y);
          if (dy > 34) continue;
          ctx.globalAlpha = (1 - dy / 34) * 0.7 * a;
          for (let gx = E.x - E.r; gx <= E.x + E.r; gx += 12) ctx.fillRect(gx - 1.2, gy - 1.2, 2.4, 2.4);
        }
        // Franja del iris iluminada por el láser
        ctx.save();
        ctx.beginPath();
        ctx.rect(E.x - E.r, y - 10, E.r * 2, 20);
        ctx.clip();
        ctx.globalAlpha = 0.55 * a;
        ctx.fillStyle = HOT;
        ctx.beginPath();
        ctx.arc(LOGO.iris.x + st.ix, LOGO.iris.y + st.iy, LOGO.iris.r * st.irisScale, 0, TAU);
        ctx.fill();
        ctx.restore();
        // Línea láser
        const g = ctx.createLinearGradient(0, y - 22, 0, y + 22);
        g.addColorStop(0, neonA(0));
        g.addColorStop(0.5, neonA(0.35));
        g.addColorStop(1, neonA(0));
        ctx.globalAlpha = a;
        ctx.fillStyle = g;
        ctx.fillRect(E.x - E.r, y - 22, E.r * 2, 44);
        ctx.fillStyle = HOT;
        ctx.globalAlpha = 0.95 * a;
        ctx.fillRect(E.x - E.r, y - 1.2, E.r * 2, 2.4);
      }
    }
  };

  /* ------------------------------------------------------------------ */
  /* Montaje                                                             */
  /* ------------------------------------------------------------------ */

  function mount(canvas, options) {
    const o = Object.assign({ variant: 'parpadeo' }, options);
    const V = VARIANTS[o.variant] || VARIANTS.parpadeo;
    const S = Object.assign({}, STYLE_DEFAULTS, LOOKS[o.look] || {}, o.style || {});
    const sc = createScene(canvas, V.fit, S, o.center);
    const st = baseState();
    if (V.init) V.init(st);
    const input = { pointer: null, until: Infinity };
    const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    let T = 0, last = 0, raf = 0, rzRaf = 0, running = false, inView = true;
    const perf = { sum: 0, n: 0 };

    function step(dt) {
      T += dt;
      if (input.pointer && T > input.until) input.pointer = null;
      V.update(st, dt, T, input, sc);
      for (let i = st.ripples.length - 1; i >= 0; i--) {
        st.ripples[i].t += dt / 1.3;
        if (st.ripples[i].t >= 1) st.ripples.splice(i, 1);
      }
    }

    function frame(now) {
      raf = requestAnimationFrame(frame);
      const raw = (now - last) / 1000;
      last = now;
      // Si el equipo no llega a ~35 fps, se dibuja a resolución normal en lugar de retina
      if (T > 2 && raw < 0.25 && sc.maxDpr > 1) {
        perf.sum += raw;
        if (++perf.n >= 90) {
          if (perf.sum / perf.n > 1 / 35) { sc.maxDpr = 1; sc.resize(); }
          perf.sum = 0; perf.n = 0;
        }
      }
      step(Math.min(0.05, raw));
      sc.render(st, V, T);
    }

    // Con «reducir movimiento»: un único fotograma representativo
    function renderStill() {
      while (T < (V.still || 2.5)) step(1 / 30);
      st.lid = 1;
      sc.render(st, V, T);
    }

    function sync() {
      const go = inView && !document.hidden && !motionQuery.matches;
      if (go && !running) {
        running = true;
        last = performance.now();
        raf = requestAnimationFrame(frame);
      } else if (!go && running) {
        running = false;
        cancelAnimationFrame(raf);
      }
      if (motionQuery.matches) renderStill();
    }

    function resize() {
      sc.resize();
      if (motionQuery.matches) renderStill();
      else sc.render(st, V, T);
    }

    function onPointer(e) {
      const r = canvas.getBoundingClientRect();
      input.pointer = { x: e.clientX - r.left, y: e.clientY - r.top };
      input.until = e.pointerType === 'mouse' ? Infinity : T + 2.5;
    }
    function onOut(e) { if (!e.relatedTarget) input.pointer = null; }

    const ro = new ResizeObserver(() => {
      cancelAnimationFrame(rzRaf);
      rzRaf = requestAnimationFrame(resize);
    });
    ro.observe(canvas);
    const io = new IntersectionObserver((entries) => {
      inView = entries[entries.length - 1].isIntersecting;
      sync();
    });
    io.observe(canvas);
    document.addEventListener('visibilitychange', sync);
    motionQuery.addEventListener('change', sync);
    if (V.pointer) {
      window.addEventListener('pointermove', onPointer, { passive: true });
      window.addEventListener('pointerdown', onPointer, { passive: true });
      document.addEventListener('mouseout', onOut);
    }

    sc.resize();
    sync();

    return {
      blink() { if (V.trigger) V.trigger(st, T); },
      destroy() {
        running = false;
        cancelAnimationFrame(raf);
        cancelAnimationFrame(rzRaf);
        ro.disconnect();
        io.disconnect();
        document.removeEventListener('visibilitychange', sync);
        motionQuery.removeEventListener('change', sync);
        window.removeEventListener('pointermove', onPointer);
        window.removeEventListener('pointerdown', onPointer);
        document.removeEventListener('mouseout', onOut);
      }
    };
  }

  global.DigitalLabLogo = { mount, variants: Object.keys(VARIANTS), looks: Object.keys(LOOKS) };
})(window);
