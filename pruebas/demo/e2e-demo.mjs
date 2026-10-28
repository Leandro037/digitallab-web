// Pruebas E2E del formulario «Pide tu demo» (tarea 6). Sin dependencias nuevas: usa el Playwright de DL Studio.
//   node pruebas/demo/e2e-demo.mjs            (desde la raíz del repositorio)
// - Sirve el repositorio en 127.0.0.1 y simula demo.php con page.route (no hay PHP en local).
// - Cubre: casillas sin marcar, validación con los textos de docs/textos/demo.md, UTM → campos ocultos,
//   carga JSON (booleanos, t en ms), estados enviando/éxito/error por campo/límite/fallo, reintento,
//   0 cookies y 0 localStorage/sessionStorage, estados forzados solo en local.
// - Guarda la carga real enviada por el navegador en pruebas/demo/carga-e2e.json: la usa prueba-carga-e2e.php
//   para comprobar con PHP que esa misma carga crea un JSON en la cola.
// Sale con 0 si todo pasa y 1 si algo falla.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const NAV = process.env.DL_NAVEGADOR || path.join(os.homedir(), '.dl-studio', 'navegador');
const { chromium } = createRequire(path.join(NAV, 'package.json'))('playwright');

const TIPOS = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2', '.mp4': 'video/mp4', '.vtt': 'text/vtt; charset=utf-8', '.ico': 'image/x-icon', '.webmanifest': 'application/manifest+json' };
const archivo = (ruta) => {
  let f = path.join(RAIZ, decodeURIComponent(ruta));
  if (!f.startsWith(RAIZ)) return null;
  if (fs.existsSync(f) && fs.statSync(f).isDirectory()) f = path.join(f, 'index.html');
  return fs.existsSync(f) ? f : null;
};
const server = http.createServer((req, res) => {
  const f = archivo(new URL(req.url, 'http://x').pathname);
  if (!f || f.endsWith('.php')) { res.writeHead(404); return res.end(); } // el PHP nunca se sirve como texto
  res.writeHead(200, { 'Content-Type': TIPOS[path.extname(f)] || 'application/octet-stream' });
  res.end(fs.readFileSync(f));
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const BASE = `http://127.0.0.1:${server.address().port}`;
const URL_DEMO = `${BASE}/demo/`;

const MSG = { nombre: 'Escribe tu nombre', email: 'Revisa el correo: parece incompleto', empresa: 'Escribe el nombre de tu empresa', usa_claude_code: 'Elige una opción', consent_respuesta: 'Marca esta casilla para poder responderte' };
const FALLO_RED = 'No pudimos enviarlo. Tus datos siguen aquí; inténtalo otra vez o escríbenos por WhatsApp.';
const FALLO_LIMITE = 'Demasiados intentos; prueba en una hora.';
const EXITO = 'Recibimos tu solicitud. Te escribimos desde digital-lab@dl-dev.com.';
const ERR_ID = { nombre: '#e-nombre', email: '#e-email', empresa: '#e-empresa', usa_claude_code: '#e-cc', consent_respuesta: '#e-consent' };

let fallos = 0, total = 0;
const ok = (cond, nombre, detalle = '') => { total++; if (cond) console.log(`  ok   ${nombre}`); else { fallos++; console.log(`  FALLA ${nombre}${detalle ? ' · ' + detalle : ''}`); } };

const nav = await chromium.launch();
const VISTAS = [
  { id: '1440', viewport: { width: 1440, height: 900 } },
  { id: '390', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
];

// Abre /demo con demo.php simulado. respuestas: lista de { status, body, retraso, abortar } que se consumen en orden.
async function abrir(v, { url = URL_DEMO, respuestas = [] } = {}) {
  const ctx = await nav.newContext({ viewport: v.viewport, isMobile: !!v.isMobile, hasTouch: !!v.hasTouch, reducedMotion: 'reduce' });
  const pag = await ctx.newPage();
  const errores = [], envios = [];
  // El navegador anota en consola cada 4xx/5xx de red; en los casos de error de demo.php es lo esperado y no cuenta
  pag.on('console', (m) => { if (m.type() === 'error' && !(/^Failed to load resource/.test(m.text()) && /\/demo\/demo\.php$/.test(m.location().url || ''))) errores.push(m.text()); });
  pag.on('pageerror', (e) => errores.push(String(e.message)));
  await pag.route('**/demo/demo.php', async (route) => {
    const req = route.request();
    envios.push({ metodo: req.method(), tipo: req.headers()['content-type'], cuerpo: req.postData() });
    const r = respuestas.shift() || { status: 200, body: { ok: true } };
    if (r.retraso) await new Promise((x) => setTimeout(x, r.retraso));
    if (r.abortar) return route.abort('failed');
    return route.fulfill({ status: r.status, contentType: 'application/json', body: JSON.stringify(r.body) });
  });
  await pag.route('**/panel/r.php', (route) => route.fulfill({ status: 204, body: '' })); // analítica propia
  await pag.goto(url, { waitUntil: 'load' });
  return { ctx, pag, errores, envios };
}
const rellenar = async (pag, { consent = true } = {}) => {
  await pag.fill('#f-nombre', 'Ana Prueba');
  await pag.fill('#f-email', 'ana@ejemplo.test');
  await pag.fill('#f-empresa', 'Empresa Ficticia SL');
  await pag.selectOption('#f-pais', 'España');
  await pag.selectOption('#f-tamano', '2-10');
  await pag.check('input[name=usa_claude_code][value=si]');
  await pag.fill('#f-necesidad', 'Automatizar informes.\nY responder correos.');
  if (consent) await pag.check('input[name=consent_respuesta]');
};
const enviar = (pag) => pag.click('[data-enviar]');
const texto = (pag, s) => pag.$eval(s, (e) => e.textContent.trim());
const visible = (pag, s) => pag.isVisible(s);
const almacen = async (ctx, pag) => ({ cookies: (await ctx.cookies()).length, storage: await pag.evaluate(() => localStorage.length + sessionStorage.length) });

for (const v of VISTAS) {
  console.log(`\n== ${v.id} px`);

  // 1. Estado inicial
  {
    const { ctx, pag, errores } = await abrir(v);
    const c = await pag.$eval('input[name=consent_respuesta]', (i) => ({ checked: i.checked, required: i.required, type: i.type }));
    ok(c.type === 'checkbox' && !c.checked && c.required, 'casilla 1 obligatoria y sin marcar');
    ok(!(await pag.$eval('input[name=consent_novedades]', (i) => i.checked)), 'casilla 2 sin marcar');
    ok((await pag.$eval('input[name=consent_version]', (i) => i.value)) === '34-2026-10-28', 'consent_version 34-2026-10-28');
    const utm = await pag.$$eval('input[name^=utm_]', (l) => l.map((i) => i.type + ':' + i.value));
    ok(utm.length === 4 && utm.every((u) => u === 'hidden:'), '4 utm_* ocultos y vacíos sin parámetros', utm.join(','));
    ok(errores.length === 0, 'sin errores de consola al cargar', errores.join(' | '));
    // Accesibilidad básica (no sustituye a Lighthouse, que no está instalado en este PC)
    const a11y = await pag.evaluate(() => {
      const form = document.querySelector('[data-demo-form]');
      const ctrls = [...form.querySelectorAll('input, select, textarea, button')].filter((i) => i.type !== 'hidden' && !i.closest('[aria-hidden="true"]'));
      const sinNombre = ctrls.filter((i) => !(i.labels && i.labels.length) && !i.getAttribute('aria-label') && !(i.tagName === 'BUTTON' && i.textContent.trim())).map((i) => i.name || i.tagName);
      const refs = [...document.querySelectorAll('[aria-describedby]')].flatMap((e) => e.getAttribute('aria-describedby').split(/\s+/)).filter((id) => !document.getElementById(id));
      const ids = [...document.querySelectorAll('[id]')].map((e) => e.id);
      const repetidos = ids.filter((id, i) => ids.indexOf(id) !== i);
      const trampaTab = form.querySelector('input[name=web]').tabIndex;
      return { sinNombre, refs, repetidos, trampaTab, h1: document.querySelectorAll('h1').length, lang: document.documentElement.lang };
    });
    ok(a11y.sinNombre.length === 0, 'cada control del formulario tiene nombre accesible', a11y.sinNombre.join(','));
    ok(a11y.refs.length === 0, 'aria-describedby apunta a ids que existen', a11y.refs.join(','));
    ok(a11y.repetidos.length === 0, 'sin ids repetidos', a11y.repetidos.join(','));
    ok(a11y.trampaTab === -1, 'campo trampa fuera del orden de tabulación');
    ok(a11y.h1 === 1 && a11y.lang === 'es', 'un solo h1 y lang="es"');
    await ctx.close();
  }

  // 2. Validación en el navegador: no se envía nada y salen los textos de demo.md
  {
    const { ctx, pag, envios, errores } = await abrir(v);
    await enviar(pag);
    await pag.waitForTimeout(200);
    for (const [n, s] of Object.entries(ERR_ID)) ok((await texto(pag, s)) === MSG[n], `error vacío: ${n}`, await texto(pag, s));
    ok(envios.length === 0, 'formulario vacío: 0 envíos');
    ok((await pag.evaluate(() => document.activeElement && document.activeElement.id)) === 'f-nombre', 'foco en el primer error');
    ok((await pag.getAttribute('#f-nombre', 'aria-invalid')) === 'true', 'aria-invalid en el campo con error');
    ok((await texto(pag, '[data-form-resumen]')).startsWith('Escribe tu nombre'), 'resumen para lectores de pantalla');
    await pag.fill('#f-nombre', 'Ana');
    ok((await texto(pag, '#e-nombre')) === '' && (await pag.getAttribute('#f-nombre', 'aria-invalid')) === null, 'el error se quita al corregir');
    await rellenar(pag, { consent: false });
    await pag.fill('#f-email', 'laura@');
    await enviar(pag);
    await pag.waitForTimeout(200);
    ok((await texto(pag, '#e-email')) === MSG.email, 'correo incompleto');
    await pag.fill('#f-email', 'ana@ejemplo.test');
    await enviar(pag);
    await pag.waitForTimeout(200);
    ok((await texto(pag, '#e-consent')) === MSG.consent_respuesta && (await texto(pag, '#e-email')) === '', 'solo falta la casilla 1: solo su error');
    ok(envios.length === 0, 'sin casilla 1: 0 envíos');
    ok(errores.length === 0, 'sin errores de consola', errores.join(' | '));
    await ctx.close();
  }

  // 3. Envío correcto con UTM, origen del CTA y espera mínima de 3 s
  {
    const url = `${URL_DEMO}?utm_source=instagram&utm_medium=social_paid&utm_campaign=lanzamiento-beta&utm_content=reel_01`;
    const { ctx, pag, envios, errores } = await abrir(v, { url, respuestas: [{ status: 200, body: { ok: true }, retraso: 400 }] });
    const utm = await pag.$$eval('input[name^=utm_]', (l) => Object.fromEntries(l.map((i) => [i.name, i.value])));
    ok(utm.utm_source === 'instagram' && utm.utm_medium === 'social_paid' && utm.utm_campaign === 'lanzamiento-beta' && utm.utm_content === 'reel_01', 'UTM de la URL a los campos ocultos', JSON.stringify(utm));
    await pag.click('.hero-cta a[data-cta=hero]');
    await rellenar(pag);
    await pag.check('input[name=consent_novedades]');
    await enviar(pag);
    await pag.waitForTimeout(100);
    ok((await texto(pag, '[data-enviar-txt]')) === 'Enviando…' && (await pag.getAttribute('[data-enviar]', 'aria-disabled')) === 'true', 'estado enviando: «Enviando…» y aria-disabled');
    ok((await pag.$eval('[data-demo-panel]', (p) => p.dataset.estado)) === 'enviando', 'panel en data-estado=enviando');
    await pag.click('[data-enviar]', { force: true }); // doble clic: no debe mandar dos veces
    await pag.waitForSelector('[data-form-exito]:not([hidden])', { timeout: 10000 });
    ok(envios.length === 1, 'un solo envío aunque se pulse dos veces', String(envios.length));
    const e = envios[0];
    ok(e && e.metodo === 'POST' && /^application\/json/.test(e.tipo || ''), 'POST con Content-Type application/json');
    const d = e ? JSON.parse(e.cuerpo) : {};
    ok(d.consent_respuesta === true && d.consent_novedades === true, 'casillas como booleanos true', `${typeof d.consent_respuesta}/${typeof d.consent_novedades}`);
    ok(Number.isInteger(d.t) && d.t >= 3000 && d.t < 7200000, 't en ms, entero y ≥ 3000', String(d.t));
    ok(d.cta_origen === 'hero', 'cta_origen = hero tras pulsar el CTA del hero', d.cta_origen);
    ok(d.utm_source === 'instagram' && d.utm_content === 'reel_01', 'UTM en la carga');
    ok(d.web === '', 'campo trampa vacío');
    ok(d.nombre === 'Ana Prueba' && d.email === 'ana@ejemplo.test' && d.empresa === 'Empresa Ficticia SL' && d.pais === 'España' && d.tamano_equipo === '2-10' && d.usa_claude_code === 'si' && d.necesidad === 'Automatizar informes.\nY responder correos.' && d.consent_version === '34-2026-10-28', 'resto de campos con sus valores');
    const nombres = Object.keys(d).sort().join(',');
    ok(nombres === 'consent_novedades,consent_respuesta,consent_version,cta_origen,email,empresa,necesidad,nombre,pais,t,tamano_equipo,usa_claude_code,utm_campaign,utm_content,utm_medium,utm_source,web', 'solo los campos del contrato', nombres);
    ok((await texto(pag, '[data-form-exito] p')).replace(/\s+/g, ' ') === EXITO, 'texto de éxito');
    ok(await pag.$eval('[data-demo-form]', (f) => f.hidden), 'el formulario se oculta en el éxito');
    ok((await pag.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-form-exito'))), 'foco en la confirmación');
    const a = await almacen(ctx, pag);
    ok(a.cookies === 0 && a.storage === 0, '0 cookies y 0 localStorage/sessionStorage', JSON.stringify(a));
    ok(errores.length === 0, 'sin errores de consola', errores.join(' | '));
    if (v.id === '1440') {
      fs.writeFileSync(path.join(RAIZ, 'pruebas', 'demo', 'carga-e2e.json'), JSON.stringify(d, null, 2) + '\n');
      console.log('  (carga guardada en pruebas/demo/carga-e2e.json)');
    }
    await ctx.close();
  }

  // 4. UTM fuera del patrón se quedan vacías
  {
    const largo = 'a'.repeat(61);
    const url = `${URL_DEMO}?utm_source=${encodeURIComponent('<script>')}&utm_medium=${largo}&utm_campaign=${encodeURIComponent('dos palabras')}&utm_content=${'b'.repeat(60)}`;
    const { ctx, pag } = await abrir(v, { url });
    const utm = await pag.$$eval('input[name^=utm_]', (l) => Object.fromEntries(l.map((i) => [i.name, i.value])));
    ok(utm.utm_source === '' && utm.utm_medium === '' && utm.utm_campaign === '' && utm.utm_content === 'b'.repeat(60), 'UTM con <script>, 61 caracteres o espacios → vacía; 60 → se queda', JSON.stringify(utm));
    await ctx.close();
  }

  // 5. Respuestas de demo.php → estados
  const casos = [
    { nombre: '400 campos (email inválido) → error en el campo', r: [{ status: 400, body: { ok: false, error: 'campos', campos: { email: 'invalido' } } }],
      check: async (pag) => (await texto(pag, '#e-email')) === MSG.email && !(await visible(pag, '[data-form-fallo]')) && (await pag.evaluate(() => document.activeElement.id)) === 'f-email' },
    { nombre: '429 → límite, sin «Reintentar»', r: [{ status: 429, body: { ok: false, error: 'limite' } }],
      check: async (pag) => (await texto(pag, '[data-fallo-msg]')) === FALLO_LIMITE && !(await visible(pag, '[data-reintentar]')) },
    { nombre: '500 → fallo con «Reintentar»', r: [{ status: 500, body: { ok: false, error: 'error' } }],
      check: async (pag) => (await texto(pag, '[data-fallo-msg]')) === FALLO_RED && (await visible(pag, '[data-reintentar]')) },
    { nombre: '503 → fallo', r: [{ status: 503, body: { ok: false, error: 'sin_servicio' } }],
      check: async (pag) => (await texto(pag, '[data-fallo-msg]')) === FALLO_RED },
    { nombre: 'sin red → fallo', r: [{ abortar: true }],
      check: async (pag) => (await texto(pag, '[data-fallo-msg]')) === FALLO_RED && (await visible(pag, '[data-reintentar]')) },
    { nombre: '400 tiempo → fallo con «Reintentar»', r: [{ status: 400, body: { ok: false, error: 'tiempo' } }],
      check: async (pag) => (await texto(pag, '[data-fallo-msg]')) === FALLO_RED && (await visible(pag, '[data-reintentar]')) },
    { nombre: '409 versión → fallo sin «Reintentar»', r: [{ status: 409, body: { ok: false, error: 'version' } }],
      check: async (pag) => (await texto(pag, '[data-fallo-msg]')) === FALLO_RED && !(await visible(pag, '[data-reintentar]')) },
    { nombre: '400 campos solo en «necesidad» → fallo', r: [{ status: 400, body: { ok: false, error: 'campos', campos: { necesidad: 'largo' } } }],
      check: async (pag) => (await texto(pag, '[data-fallo-msg]')) === FALLO_RED },
  ];
  for (const c of casos) {
    const { ctx, pag, errores } = await abrir(v, { respuestas: c.r.slice() });
    await rellenar(pag);
    await enviar(pag);
    await pag.waitForFunction(() => !document.querySelector('[data-enviar]').hasAttribute('aria-disabled'), null, { timeout: 10000 });
    const bien = await c.check(pag);
    const datosQuedan = (await pag.inputValue('#f-nombre')) === 'Ana Prueba' && (await pag.isChecked('input[name=consent_respuesta]'));
    ok(bien && datosQuedan, c.nombre + ' (los datos se quedan)');
    ok(errores.length === 0, `  sin errores de consola (${c.nombre})`, errores.join(' | '));
    await ctx.close();
  }

  // 6. Reintentar tras un fallo vuelve a enviar y puede terminar en éxito
  {
    const { ctx, pag, envios } = await abrir(v, { respuestas: [{ status: 500, body: { ok: false, error: 'error' } }, { status: 200, body: { ok: true } }] });
    await rellenar(pag);
    await enviar(pag);
    await pag.waitForSelector('[data-form-fallo]:not([hidden])', { timeout: 10000 });
    await pag.click('[data-reintentar]');
    await pag.waitForSelector('[data-form-exito]:not([hidden])', { timeout: 10000 });
    ok(envios.length === 2 && !(await visible(pag, '[data-form-fallo]')), '«Reintentar» reenvía y llega al éxito');
    await ctx.close();
  }
}

// 7. Los estados forzados (?estado=) solo funcionan en local: en el dominio real no se puede fingir un éxito
{
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const pag = await ctx.newPage();
  await pag.route('https://dl.prueba/**', (route) => {
    const f = archivo(new URL(route.request().url()).pathname);
    if (!f || f.endsWith('.php')) return route.fulfill({ status: 404, body: '' });
    return route.fulfill({ status: 200, contentType: TIPOS[path.extname(f)] || 'application/octet-stream', body: fs.readFileSync(f) });
  });
  await pag.goto('https://dl.prueba/demo/?estado=exito', { waitUntil: 'load' });
  ok(!(await visible(pag, '[data-form-exito]')) && (await visible(pag, '[data-demo-form]')), '?estado=exito ignorado fuera de localhost');
  await ctx.close();
}

await nav.close();
server.close();
console.log(`\n${total - fallos}/${total} comprobaciones correctas`);
process.exit(fallos ? 1 : 0);
