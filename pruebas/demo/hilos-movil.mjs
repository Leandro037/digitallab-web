// Tarea 6 · ningún hilo de /demo pasa por detrás de un texto en móvil y tablet (carril, ≤ 1100 px).
//   node pruebas/demo/hilos-movil.mjs          (desde la raíz del repositorio)
// Recorre cada trazo de los hilos (cada 3 px, con medio grosor del trazo: 3,5 px el halo y 4,5 px el cometa) y cuenta
// los puntos que caen dentro de una línea de texto visible. También comprueba que se usa la ruta nueva (no la anterior).
// Sale con 0 si hay 0 cruces en todas las anchuras.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const NAV = process.env.DL_NAVEGADOR || path.join(os.homedir(), '.dl-studio', 'navegador');
const { chromium } = createRequire(path.join(NAV, 'package.json'))('playwright');
const TIPOS = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.woff2': 'font/woff2' };
const server = http.createServer((req, res) => {
  let f = path.join(RAIZ, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!f.startsWith(RAIZ)) { res.writeHead(403); return res.end(); }
  if (fs.existsSync(f) && fs.statSync(f).isDirectory()) f = path.join(f, 'index.html');
  if (!fs.existsSync(f) || f.endsWith('.php')) { res.writeHead(404); return res.end(); }
  res.writeHead(200, { 'Content-Type': TIPOS[path.extname(f)] || 'application/octet-stream' });
  res.end(fs.readFileSync(f));
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const nav = await chromium.launch();
let mal = 0;
for (const w of [360, 390, 768, 1024, 1100]) {
  const ctx = await nav.newContext({ viewport: { width: w, height: 844 }, isMobile: w < 800, hasTouch: w < 800, reducedMotion: 'reduce' });
  const pag = await ctx.newPage();
  await pag.route('**/panel/r.php', (r) => r.fulfill({ status: 204, body: '' }));
  await pag.goto(`http://127.0.0.1:${server.address().port}/demo/`, { waitUntil: 'networkidle' });
  await pag.evaluate(() => document.querySelectorAll('.st-rev').forEach((e) => e.classList.add('is-in')));
  await pag.waitForTimeout(3600); // los hilos arrancan 2,5 s después de la carga
  const m = await pag.evaluate(() => {
    const rects = [];
    const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const rg = document.createRange();
    for (let n = tw.nextNode(); n; n = tw.nextNode()) {
      if (!n.data.trim() || !n.parentElement.offsetParent || n.parentElement.closest('.sr, .st-top')) continue;
      rg.selectNodeContents(n);
      for (const r of rg.getClientRects()) if (r.width > 0) rects.push({ t: n.data.trim().slice(0, 30), x0: r.left, x1: r.right, y0: r.top + scrollY, y1: r.bottom + scrollY });
    }
    const svg = document.querySelector('.flow'); const sr = svg.getBoundingClientRect();
    const paths = [...svg.querySelectorAll('path')].filter((p) => (p.getAttribute('d') || '').length > 10);
    const cruces = {};
    for (const p of paths) {
      const L = p.getTotalLength(); const h = parseFloat(p.getAttribute('stroke-width')) / 2;
      for (let s = 0; s <= L; s += 3) {
        const q = p.getPointAtLength(s); const x = q.x + sr.left, y = q.y + sr.top + scrollY;
        for (const r of rects) if (x + h > r.x0 && x - h < r.x1 && y + h > r.y0 && y - h < r.y1) { cruces[r.t] = (cruces[r.t] || 0) + 1; break; }
      }
    }
    const d = paths[0] ? paths[0].getAttribute('d') : '';
    return { trazos: paths.length, rutaNueva: /^M[\d.]+ [\d.]+ L/.test(d), cruces };
  });
  const n = Object.values(m.cruces).reduce((a, b) => a + b, 0);
  const bien = n === 0 && m.rutaNueva && m.trazos > 0;
  if (!bien) mal++;
  console.log(`${bien ? 'ok   ' : 'FALLA'} ${w} px · trazos ${m.trazos} · ruta nueva ${m.rutaNueva} · cruces con texto ${n}${n ? ' ' + JSON.stringify(m.cruces) : ''}`);
  await ctx.close();
}
await nav.close();
server.close();
process.exit(mal ? 1 : 0);
