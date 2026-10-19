/*!
 * Digital Lab · informe PDF de la auditoría web
 * La misma plantilla que el flujo «Auditoría web» del organigrama: A4 vertical, vectorial y con texto real,
 * con las tipografías de la web incrustadas (Space Grotesk y JetBrains Mono, licencia SIL OFL, en fuentes-pdf/).
 * Se genera en el navegador del visitante con jsPDF; no se guarda en ningún servidor.
 * Uso: DigitalLabInformePDF.build(run, { scores, areas, areaScore }) → Promise<Blob>
 */
(function (global) {
  'use strict';

  const FONT_FILES = { 'SG-400': 'SG-400.ttf', 'SG-500': 'SG-500.ttf', 'SG-700': 'SG-700.ttf', 'JB-500': 'JB-500.ttf', 'JB-700': 'JB-700.ttf' };
  let fontsReady = null;
  function loadFonts() {
    if (!fontsReady) {
      fontsReady = Promise.all(Object.keys(FONT_FILES).map((k) => fetch('fuentes-pdf/' + FONT_FILES[k]).then((r) => {
        if (!r.ok) throw new Error('fuente ' + k);
        return r.arrayBuffer();
      }).then((buf) => {
        const b = new Uint8Array(buf); let s = '';
        for (let i = 0; i < b.length; i += 0x8000) s += String.fromCharCode.apply(null, b.subarray(i, i + 0x8000));
        return [k, btoa(s)];
      }))).then(Object.fromEntries);
      fontsReady.catch(() => { fontsReady = null; });
    }
    return fontsReady;
  }

  const AREAS = { seguridad: 'Seguridad', privacidad: 'Privacidad', seo: 'SEO', rendimiento: 'Rendimiento', accesibilidad: 'Accesibilidad', conversion: 'Conversión' };
  const PT = { W: 595.28, H: 841.89, M: 36, bg: '#060D12', surf: '#0B171C', surf2: '#102128', border: '#25434A', grid: '#0A161C', text: '#F5F9F8', mut: '#A8BDC0', faint: '#6E8589', green: '#5AD41C', cyan: '#4ADBE5', warn: '#FFC04E', bad: '#FE7975' };
  const AREA_SUB = { seguridad: 'Protección de la web y de los datos de tus visitantes.', seo: 'Cómo te encuentra y te muestra Google.', rendimiento: 'Lo rápido que carga en el celular.', accesibilidad: 'Que todas las personas puedan usarla, también con teclado o lector de pantalla.', conversion: 'Qué tan fácil le resulta a un visitante contactarte.' };
  function pdfSafe(s) { return String(s == null ? '' : s).replace(/→/g, '›').replace(/≤/g, '<=').replace(/≥/g, '>=').replace(/[✓✔]/g, '').replace(/[^\u0000-ÿ -⁯€™−]/g, ''); }

  // Logo de Digital Lab dibujado en canvas
  function dlLogo(c, x, y, size, bg) {
    const s = size / 396; c.save(); c.translate(x - 56 * s, y - 72 * s); c.scale(s, s);
    const g = c.createLinearGradient(80, 90, 440, 460); g.addColorStop(0, '#3f700c'); g.addColorStop(1, '#5ad41c');
    c.fillStyle = '#2a2d30'; c.beginPath(); c.arc(265, 278, 108, 0, 7); c.fill();
    c.fillStyle = bg; [[168, 203], [348, 188]].forEach((p) => { c.beginPath(); c.arc(p[0], p[1], 36, 0, 7); c.fill(); });
    c.strokeStyle = g; c.lineWidth = 22; c.stroke(new Path2D('M265 108A172 172 0 1 1 96 248')); c.stroke(new Path2D('M168 203A124 124 0 1 0 348 188'));
    c.fillStyle = g; [[265, 108, 30], [96, 248, 30], [168, 203, 30], [348, 188, 30], [269, 275, 46]].forEach((p) => { c.beginPath(); c.arc(p[0], p[1], p[2], 0, 7); c.fill(); });
    c.fillStyle = '#fff'; [[265, 108, 11], [96, 248, 11], [168, 203, 11], [348, 188, 11], [286, 259, 19]].forEach((p) => { c.beginPath(); c.arc(p[0], p[1], p[2], 0, 7); c.fill(); });
    c.restore();
  }

  function draw(TTF, r, ext) {
    const jsPDF = global.jspdf.jsPDF, doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4', compress: true });
    Object.keys(TTF).forEach((k) => { doc.addFileToVFS(k + '.ttf', TTF[k]); doc.addFont(k + '.ttf', k, 'normal'); });
    const d = r.data, sc = ext.scores, rep = r.out.au15 || {}, off = r.out.au08 || {}, W = PT.W, H = PT.H, M = PT.M, CW = W - M * 2;
    let y = 0, page = 0;
    const demo = d.origen === 'ejemplo', host = d.host || 'tu web';
    const fecha = new Date(r.ts).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }).replace(/\//g, '.');
    const logoCv = document.createElement('canvas'); logoCv.width = logoCv.height = 160;
    const lc = logoCv.getContext('2d'); lc.fillStyle = PT.surf; lc.fillRect(0, 0, 160, 160); dlLogo(lc, 8, 8, 144, PT.surf);
    const logo = logoCv.toDataURL('image/png');
    function font(k, size) { doc.setFont(k, 'normal'); doc.setFontSize(size); }
    function T(t, x, yy, o) { o = o || {}; if (Array.isArray(t)) { const lh = o.lh || doc.getFontSize() * 1.36, oo = Object.assign({}, o); delete oo.lh; t.forEach((l, i) => { doc.text(pdfSafe(l), x, yy + i * lh, oo); }); return; } doc.text(pdfSafe(t), x, yy, o); }
    function wrap(t, w) { return doc.splitTextToSize(pdfSafe(t), w); }
    function kick(t, x, yy, col, size, align) { font('JB-700', size || 7.5); doc.setTextColor(col); T(String(t).toUpperCase(), x, yy, { charSpace: 0.7, align: align || 'left' }); }
    function scoreCol(v) { return v == null ? PT.faint : v >= 90 ? PT.green : v >= 50 ? PT.warn : PT.bad; }
    function impCol(i) { return i === 'alto' ? PT.bad : i === 'medio' ? PT.warn : PT.cyan; }
    function arc(cx, cy, rad, a0, a1) {
      const segs = [], n = Math.max(1, Math.ceil(Math.abs(a1 - a0) / (Math.PI / 2))), step = (a1 - a0) / n;
      let cur = [cx + rad * Math.cos(a0), cy + rad * Math.sin(a0)];
      const start = cur.slice();
      for (let i = 0; i < n; i++) {
        const t0 = a0 + i * step, t1 = t0 + step, k = 4 / 3 * Math.tan((t1 - t0) / 4);
        const p1 = [cx + rad * Math.cos(t0) - k * rad * Math.sin(t0), cy + rad * Math.sin(t0) + k * rad * Math.cos(t0)];
        const p3 = [cx + rad * Math.cos(t1), cy + rad * Math.sin(t1)];
        const p2 = [p3[0] + k * rad * Math.sin(t1), p3[1] - k * rad * Math.cos(t1)];
        segs.push([p1[0] - cur[0], p1[1] - cur[1], p2[0] - cur[0], p2[1] - cur[1], p3[0] - cur[0], p3[1] - cur[1]]); cur = p3;
      }
      doc.lines(segs, start[0], start[1], [1, 1], 'S', false);
    }
    function ring(cx, cy, rad, v, lw, numSize) {
      doc.setLineWidth(lw); doc.setDrawColor(PT.border); doc.circle(cx, cy, rad, 'S');
      font('SG-700', numSize || rad * 0.62); doc.setTextColor(PT.text);
      if (v == null) { font('JB-700', Math.max(6, rad * 0.22)); doc.setTextColor(PT.faint); T('NO EVALUADO', cx, cy + rad * 0.08, { align: 'center' }); return; }
      doc.setLineCap('round'); doc.setDrawColor(scoreCol(v)); arc(cx, cy, rad, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * Math.max(0.005, Math.min(1, v / 100))); doc.setLineCap('butt');
      T(String(v), cx, cy + (numSize || rad * 0.62) * 0.36, { align: 'center' });
    }
    function card(x, yy, w, h, o) { o = o || {}; doc.setFillColor(o.fill || PT.surf); doc.setDrawColor(o.stroke || PT.border); doc.setLineWidth(o.lw || 0.8); doc.roundedRect(x, yy, w, h, o.r || 10, o.r || 10, 'FD'); if (o.accent) { doc.setFillColor(o.accent); doc.rect(x, yy + 6, 3, h - 12, 'F'); } }
    function badge(t, x, yy, col) { font('JB-700', 6.6); const bt = pdfSafe(String(t).toUpperCase()), w = doc.getTextWidth(bt) + 0.5 * bt.length + 16; doc.setFillColor(PT.surf2); doc.setDrawColor(col); doc.setLineWidth(0.8); doc.roundedRect(x, yy - 10.5, w, 15, 7.5, 7.5, 'FD'); doc.setTextColor(col); T(String(t).toUpperCase(), x + 8, yy, { charSpace: 0.5 }); return w; }
    function dot(x, yy, col, rad) { doc.setFillColor(col); doc.circle(x, yy, rad || 3, 'F'); }
    function chrome() {
      doc.setFillColor(PT.bg); doc.rect(0, 0, W, H, 'F');
      doc.setDrawColor(PT.grid); doc.setLineWidth(0.5); for (let gx = M; gx < W; gx += 42) doc.line(gx, 62, gx, H - 40); for (let gy = 104; gy < H - 40; gy += 60) doc.line(M, gy, W - M, gy);
      doc.setFillColor(PT.surf); doc.rect(0, 0, W, 62, 'F'); doc.setDrawColor(PT.border); doc.setLineWidth(0.6); doc.line(0, 62, W, 62);
      doc.addImage(logo, 'PNG', M, 18, 26, 26); font('JB-700', 10); doc.setTextColor(PT.text); T('DIGITAL LAB', M + 34, 35.5, { charSpace: 1.2 });
      kick('Auditoría web / ' + fecha, W - M, 35, PT.cyan, 7, 'right');
    }
    function footer(n, total) {
      doc.setDrawColor(PT.border); doc.setLineWidth(0.6); doc.line(M, H - 34, W - M, H - 34);
      kick((demo ? 'Demo · datos de ejemplo   /   ' : 'Digital Lab · dl-dev.com   /   ') + host, M, H - 20, PT.faint, 6.6);
      kick(String(n).padStart(2, '0') + ' / ' + String(total).padStart(2, '0'), W - M, H - 20, PT.green, 7.5, 'right');
    }
    function newPage() { if (page) doc.addPage('a4', 'portrait'); page++; chrome(); y = 96; }
    function heading(k, t, sub, kcol) {
      kick(k, M, y, kcol || PT.green, 7.5); y += 28; font('SG-700', 26); doc.setTextColor(PT.text); T(t, M, y);
      if (sub) { y += 17; font('SG-400', 9.5); doc.setTextColor(PT.mut); const sl = wrap(sub, CW - 110).slice(0, 2); T(sl, M, y); y += (sl.length - 1) * 13; }
      y += 22;
    }
    function room(h) { return y + h <= H - 54; }
    function callout(title, text, col) {
      font('SG-400', 9.5); const tl = wrap(text, CW - 48); const h = 48 + tl.length * 13.5;
      if (!room(h + 10)) return false;
      card(M, y, CW, h, { fill: PT.surf2, r: 12 }); dot(M + 22, y + 22, col || PT.green, 3.6);
      font('SG-700', 10); doc.setTextColor(PT.text); T(String(title).toUpperCase(), M + 34, y + 25.5);
      font('SG-400', 9.5); doc.setTextColor(PT.mut); T(tl, M + 24, y + 43); y += h + 14; return true;
    }

    // ===== 1. Portada / scorecard =====
    newPage(); y = 108;
    kick('Auditoría web', M, y, PT.green); y += 32;
    font('SG-700', 30); doc.setTextColor(PT.text); T('Tu web, bajo la lupa.', M, y); y += 22;
    font('JB-700', 12); doc.setTextColor(PT.cyan); T(host.toUpperCase(), M, y, { charSpace: 1 }); y += 18;
    font('SG-400', 10); doc.setTextColor(PT.mut); T(wrap(d.psi ? 'Velocidad, SEO y accesibilidad medidas por Google PageSpeed en móvil, más la revisión automática de seguridad, privacidad y conversión de Digital Lab.' : 'Revisión automática de seguridad, privacidad, SEO, accesibilidad, velocidad y conversión hecha por Digital Lab.', CW), M, y); y += 34;
    const sh = 236; card(M, y, CW, sh, { r: 16 });
    ring(M + 100, y + 104, 64, sc.global, 9, 40);
    kick('Nota global', M + 100, y + 192, PT.text, 8, 'center');
    font('SG-400', 7); doc.setTextColor(PT.faint); T('Índice Digital Lab: promedio de las 4 notas', M + 100, y + 206, { align: 'center' });
    const gx0 = M + 210, gw = CW - 222, cw2 = (gw - 10) / 2;
    sc.list.forEach((x, i) => {
      const bx = gx0 + (i % 2) * (cw2 + 10), by = y + 12 + Math.floor(i / 2) * 108;
      card(bx, by, cw2, 98, { fill: PT.surf2, r: 12 });
      ring(bx + 40, by + 49, 26, x.v, 5, 17);
      kick(x.l, bx + 78, by + 44, PT.text, 7.5); kick(x.src === 'Google' ? 'Google PageSpeed' : 'Digital Lab', bx + 78, by + 58, PT.faint, 6.2);
    });
    y += sh + 16;
    callout('El diagnóstico', rep.diagnostico || '', PT.warn);
    if (demo) { badge('Informe de demostración · datos de ejemplo', M, y + 8, PT.warn); y += 24; }

    // ===== 2. Resumen ejecutivo =====
    const plan = (rep.plan || []).slice(), oks = [];
    ext.areas.forEach((x) => { (x[2].hallazgos || []).forEach((f) => { if (f.estado === 'ok') oks.push(f.titulo); }); });
    newPage();
    heading('Resumen ejecutivo', 'Primero, lo importante', (plan.length ? Math.min(3, plan.length) + ' acciones prioritarias' : 'Las prioridades') + (oks.length ? ' y ' + Math.min(5, oks.length) + ' fortalezas detectadas.' : '.'));
    plan.slice(0, 3).forEach((p, i) => {
      font('SG-700', 12); const tl = wrap(p.tarea || '', CW - 110).slice(0, 3), h = 58 + tl.length * 15;
      card(M, y, CW, h, { r: 12 });
      font('JB-700', 26); doc.setTextColor(PT.green); T('0' + (i + 1), M + 22, y + 38);
      font('SG-700', 12); doc.setTextColor(PT.text); T(tl, M + 92, y + 26);
      let bx = M + 92; const by = y + 26 + tl.length * 15 + 12;
      bx += badge('Impacto ' + (p.impacto || '-'), bx, by, impCol(p.impacto)) + 6; bx += badge('Esfuerzo ' + (p.esfuerzo || '-'), bx, by, PT.cyan) + 6; if (p.area) badge(AREAS[p.area] || p.area, bx, by, PT.green);
      y += h + 10;
    });
    if (oks.length) {
      y += 24; kick('Lo que ya está bien', M + 2, y, PT.green); y += 20;
      oks.slice(0, 5).forEach((t) => { font('SG-400', 10); const l = wrap(t, CW - 30).slice(0, 2); dot(M + 6, y - 3.4, PT.green, 3.2); doc.setTextColor(PT.text); T(l, M + 22, y); y += l.length * 13 + 8; });
    }
    if (rep.lo_que_ganarias) { y += 8; callout('Resultado esperado', rep.lo_que_ganarias, PT.green); }

    // ===== 3. Áreas =====
    const areas = ext.areas, AREA_SC = { seguridad: sc.list[3].v, seo: sc.list[1].v, rendimiento: sc.list[0].v, accesibilidad: sc.list[2].v, conversion: ext.areaScore(d.checks, ['conversion']) };
    function findingH(f) {
      font('SG-700', 11); const t1 = wrap(f.titulo || '', CW - 150).slice(0, 2);
      font('SG-400', 9.2); const t2 = wrap(f.por_que || '', CW - 44).slice(0, 4);
      const ev = f.evidencia ? wrap('Evidencia: ' + f.evidencia, CW - 44).slice(0, 2) : [];
      font('SG-500', 9.2); const t3 = f.como ? wrap(f.como, CW - 44).slice(0, 4) : [];
      return { t1, t2, ev, t3, h: 26 + t1.length * 14 + t2.length * 12.5 + (ev.length ? 6 + ev.length * 11 : 0) + (t3.length ? 26 + t3.length * 12.5 : 0) + 4 };
    }
    function drawFinding(f, m) {
      const ok = f.estado === 'ok', col = ok ? PT.green : f.estado === 'warn' ? PT.warn : PT.bad;
      card(M, y, CW, m.h, { r: 12, accent: col });
      let ty = y + 24; font('SG-700', 11); doc.setTextColor(PT.text); T(m.t1, M + 20, ty); ty += m.t1.length * 14 + 2;
      badge(ok ? 'Positivo' : 'Impacto ' + (f.impacto || '-'), W - M - 92, y + 22, ok ? PT.green : impCol(f.impacto));
      font('SG-400', 9.2); doc.setTextColor(PT.mut); T(m.t2, M + 20, ty); ty += m.t2.length * 12.5;
      if (m.ev.length) { ty += 6; font('JB-500', 7.6); doc.setTextColor(PT.cyan); T(m.ev, M + 20, ty); ty += m.ev.length * 11; }
      if (m.t3.length) { ty += 14; kick(ok ? 'Cómo mantenerlo' : 'Cómo se corrige', M + 20, ty, PT.cyan, 6.8); ty += 14; font('SG-500', 9.2); doc.setTextColor(PT.text); T(m.t3, M + 20, ty); }
      y += m.h + 10;
    }
    areas.forEach((x, ai) => {
      const kk = 'Área ' + String(ai + 1).padStart(2, '0') + ' / ' + String(areas.length).padStart(2, '0'), list = (x[2].hallazgos || []).slice();
      const first = list.length ? findingH(list[0]).h : 0;
      if (ai === 0 || !room(150 + first)) newPage(); else { y += 18; doc.setDrawColor(PT.border); doc.setLineWidth(0.6); doc.line(M, y - 14, W - M, y - 14); y += 10; }
      const ringY = y + 28; heading(kk, x[1], AREA_SUB[x[0]] || '');
      const kpi = x[0] === 'rendimiento' && d.psi && d.psi.metrics && (d.psi.metrics.lcp || d.psi.metrics.cls);
      if (!kpi) ring(W - M - 34, ringY, 28, AREA_SC[x[0]], 5.5, 16);
      if (kpi) {
        const ph = 112; if (!room(ph + 20)) { newPage(); heading(kk + ' · continuación', x[1], ''); }
        card(M, y, CW, ph, { r: 14 }); ring(M + 62, y + ph / 2, 38, AREA_SC.rendimiento, 7, 24);
        font('SG-700', 20); doc.setTextColor(PT.text); T((AREA_SC.rendimiento == null ? '—' : AREA_SC.rendimiento) + ' / 100', M + 124, y + 38);
        kick('Rendimiento · Google PageSpeed móvil', M + 124, y + 54, PT.warn, 7);
        font('SG-400', 9.2); doc.setTextColor(PT.mut);
        const mt = []; if (d.psi.metrics.lcp) mt.push('Carga del contenido principal (LCP): ' + d.psi.metrics.lcp + ' · objetivo de Google: 2,5 s o menos.'); if (d.psi.metrics.cls) mt.push('Estabilidad visual (CLS): ' + d.psi.metrics.cls + ' · objetivo de Google: 0,1 o menos.');
        T(wrap(mt.join('\n'), CW - 150), M + 124, y + 74); y += ph + 12;
      }
      list.forEach((f) => { const m = findingH(f); if (!room(m.h)) { newPage(); heading(kk + ' · continuación', x[1], ''); } drawFinding(f, m); });
      if (x[2].resumen) { if (!callout('Lectura para el negocio', x[2].resumen, PT.cyan)) { newPage(); heading(kk + ' · continuación', x[1], ''); callout('Lectura para el negocio', x[2].resumen, PT.cyan); } }
    });

    // ===== 4. Hoja de ruta =====
    if (plan.length) {
      newPage(); heading('Hoja de ruta', 'Plan de acción priorizado', plan.length + ' mejoras concretas, ordenadas por impacto y esfuerzo.');
      const lineX = M + 18;
      plan.forEach((p, i) => {
        font('SG-500', 10.5); const tl = wrap(p.tarea || '', CW - 60).slice(0, 3), h = 38 + tl.length * 14;
        if (!room(h)) { newPage(); heading('Hoja de ruta · continuación', 'Plan de acción priorizado', ''); }
        if (i < plan.length - 1) { doc.setDrawColor(PT.green); doc.setLineWidth(1.4); doc.line(lineX, y + 16, lineX, y + h + 14); }
        doc.setFillColor(i < 3 ? PT.green : PT.surf2); doc.setDrawColor(PT.green); doc.setLineWidth(1); doc.circle(lineX, y + 16, 13, 'FD');
        font('SG-700', 10); doc.setTextColor(i < 3 ? '#061204' : PT.text); T(String(i + 1), lineX, y + 19.6, { align: 'center' });
        let bx = M + 46; bx += badge(AREAS[p.area] || p.area || 'General', bx, y + 12, PT.cyan) + 6; bx += badge('Impacto ' + (p.impacto || '-'), bx, y + 12, impCol(p.impacto)) + 6; badge('Esfuerzo ' + (p.esfuerzo || '-'), bx, y + 12, p.esfuerzo === 'alto' ? PT.warn : PT.green);
        font('SG-500', 10.5); doc.setTextColor(PT.text); T(tl, M + 46, y + 34);
        y += h + 14;
      });
    }

    // ===== 5. Siguientes pasos =====
    newPage(); heading('Siguientes pasos', 'Convertir el diagnóstico en acción', 'Una hoja de ruta para mejorar tu web sin empezar desde cero.');
    if (off.titular) {
      const words = pdfSafe(off.titular).split(' '), cut = Math.ceil(words.length / 2), l1 = words.slice(0, cut).join(' '), l2 = words.slice(cut).join(' ');
      font('SG-400', 10); const tx = wrap(off.texto || '', CW - 52).slice(0, 7); const sv = (off.servicios || []).slice(0, 3);
      const svH = sv.map((s) => { font('SG-400', 8.8); return 18 + wrap(s.por_que || '', CW - 52).slice(0, 3).length * 12; });
      const bh = 122 + tx.length * 14 + (sv.length ? 26 + svH.reduce((a, b) => a + b + 8, 0) : 0) + 10;
      card(M, y, CW, bh, { r: 16, stroke: PT.green, lw: 1.2 });
      let yy = y + 26; badge('Digital Lab · acompañamiento', M + 24, yy + 4, PT.green); yy += 40;
      font('SG-700', 24); doc.setTextColor(PT.text); T(l1, M + 24, yy); yy += 28; doc.setTextColor(PT.green); T(l2, M + 24, yy); yy += 24;
      font('SG-400', 10); doc.setTextColor(PT.text); T(tx, M + 24, yy); yy += tx.length * 14 + 8;
      if (sv.length) {
        doc.setDrawColor(PT.green); doc.setLineWidth(0.6); doc.line(M + 24, yy, W - M - 24, yy); yy += 22;
        sv.forEach((s) => { font('JB-700', 8.6); doc.setTextColor(PT.cyan); T(String(s.servicio || '').toUpperCase(), M + 24, yy, { charSpace: 0.6 }); yy += 14; font('SG-400', 8.8); doc.setTextColor(PT.mut); const l = wrap(s.por_que || '', CW - 52).slice(0, 3); T(l, M + 24, yy); yy += l.length * 12 + 12; });
      }
      y += bh + 14;
    }
    card(M, y, CW, 70, { r: 14 });
    font('SG-700', 12); doc.setTextColor(PT.text); T(String(off.cta || 'Hablemos de tu web').toUpperCase(), M + 24, y + 28);
    font('SG-500', 10); doc.setTextColor(PT.green); T('dl-dev.com   ·   WhatsApp +54 9 3496 53-2749', M + 24, y + 48); y += 104;
    const nr = [].concat.apply([], areas.map((x) => x[2].no_revisado || [])).filter(Boolean).slice(0, 6);
    if (nr.length) {
      if (!room(40 + nr.length * 22)) newPage();
      kick('Límites de esta revisión', M + 2, y, PT.warn); y += 18;
      nr.forEach((t) => { font('SG-400', 9); const l = wrap(t, CW - 30).slice(0, 2); dot(M + 6, y - 3, PT.warn, 2.6); doc.setTextColor(PT.mut); T(l, M + 20, y); y += l.length * 12 + 9; });
    }

    const total = doc.getNumberOfPages();
    for (let i = 1; i <= total; i++) { doc.setPage(i); footer(i, total); }
    doc.setProperties({ title: pdfSafe('Auditoría web · ' + host), author: 'Digital Lab', subject: 'Informe de auditoría web', creator: 'Digital Lab · dl-dev.com' });
    return doc.output('blob');
  }

  global.DigitalLabInformePDF = {
    build(run, ext) { return loadFonts().then((ttf) => draw(ttf, run, ext)); }
  };
})(window);
