// E2E de la sección Leads del panel y del login endurecido (tarea 50). Sin dependencias nuevas: Playwright de DL Studio + PHP.
//   node pruebas/panel/e2e-panel.mjs                       (desde la raíz del repositorio)
//   DL_PHP=ruta/php.exe  DL_CAPTURAS=carpeta  node pruebas/panel/e2e-panel.mjs
// - Copia la web a una carpeta temporal con la MISMA estructura que el hosting (public_html, /dl-leads,
//   dl-auditoria-config.php al lado) con configuración y leads FICTICIOS (correos .example, contraseña de prueba).
//   Así nunca lee la configuración real que pueda haber junto al repositorio.
// - Sirve public_html con `php -S 127.0.0.1:<puerto>` (el correo de aviso apunta a un puerto cerrado: prueba que si
//   falla, el login sigue).
// - Cubre: redirección sin sesión, login malo/bueno, cookie __Host-, cabeceras R5 en HTML/JSON/CSV/401, lista, KPIs,
//   búsqueda, filtros, «sin resultados», ficha, atendido y «volver a nuevo» (persisten), CSV (fórmulas neutralizadas),
//   CSRF/Origin, XSS en datos del lead, móvil (ficha a pantalla completa y «Volver»), estado vacío, salir y bloqueo.
//   Tarea 51: inactividad de 30 min con el sondeo de las estadísticas activo (tiempo simulado: reloj del navegador
//   con clock.runFor y sesión del servidor envejecida 60 s por minuto), lead de más de 30 días oculto y purga por Cron.
// - Si DL_CAPTURAS está definido, guarda allí panel-leads-*.png (escritorio 1440 y móvil 390, sin scroll horizontal).
// Sale con 0 si todo pasa y 1 si algo falla.
import fs from 'node:fs';
import os from 'node:os';
import net from 'node:net';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const NAV = process.env.DL_NAVEGADOR || path.join(os.homedir(), '.dl-studio', 'navegador');
const { chromium } = createRequire(path.join(NAV, 'package.json'))('playwright');
const PHP_LOCAL = 'C:/Users/Win10/herramientas/php83/php.exe';
const PHP = process.env.DL_PHP || (fs.existsSync(PHP_LOCAL) ? PHP_LOCAL : 'php');
const CAPTURAS = process.env.DL_CAPTURAS || '';

let fallos = 0, total = 0;
const ok = (cond, nombre, detalle = '') => { total++; if (cond) console.log(`  ok   ${nombre}`); else { fallos++; console.log(`  FALLA ${nombre}${detalle ? ' · ' + detalle : ''}`); } };

/* ---------- servidor de prueba ---------- */
const BASE = fs.mkdtempSync(path.join(os.tmpdir(), 'dl-panel-e2e-'));
const WEB = path.join(BASE, 'public_html');
const COLA = path.join(BASE, 'dl-leads');
fs.mkdirSync(WEB); fs.mkdirSync(COLA);
const copiar = (rel, filtro) => fs.cpSync(path.join(RAIZ, rel), path.join(WEB, rel), { recursive: true, filter: filtro });
copiar('panel', (s) => !/[\\/]datos[\\/].+\.(bin|sqlite|json|txt)$/.test(s));
for (const r of ['login', 'inc', 'fonts', 'favicon.svg', 'favicon.ico', 'styles.css', 'auditoria/api/lib.php', 'demo/demo.php']) copiar(r);

const libre = () => new Promise((r) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const PUERTO = await libre();
const ORIGEN = `http://127.0.0.1:${PUERTO}`;
const EMAIL = 'admin@ejemplo.test';
const PASS = 'clave-ficticia-e2e-' + crypto.randomBytes(6).toString('hex');
const HASH = execFileSync(PHP, ['-r', "echo password_hash(stream_get_contents(STDIN), PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1]);"], { input: PASS }).toString();
const lit = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
fs.writeFileSync(path.join(BASE, 'dl-auditoria-config.php'), `<?php\nreturn [\n  'admin_email' => ${lit(EMAIL)},\n  'admin_password_hash' => ${lit(HASH)},\n  'panel_sal' => ${lit(crypto.randomBytes(32).toString('hex'))},\n  'origenes' => [${lit(ORIGEN)}],\n];\n`);
fs.writeFileSync(path.join(COLA, 'config.php'), `<?php\nreturn ['sal' => ${lit(crypto.randomBytes(32).toString('hex'))}];\n`);

// Leads ficticios (los de la maqueta del 29), en el formato que escribe demo.php
const hoy = new Date(); hoy.setUTCHours(0, 0, 0, 0);
const LEADS = [
  { d: 0, h: '09:41', nombre: 'Marta Quiroga', email: 'marta@brisa.example', empresa: 'Estudio Brisa', pais: 'España', tamano_equipo: '2-10', usa_claude_code: 'si', cta_origen: 'hero', utm_source: 'instagram', utm_medium: 'social', utm_campaign: 'lanzamiento-oct', utm_content: 'reel-ig', necesidad: 'Queremos automatizar las propuestas a clientes.' },
  { d: 1, h: '18:12', nombre: 'Tomás Aguirre', email: 'tomas@lumbre.example', empresa: 'Lumbre Software', pais: 'Argentina', tamano_equipo: '11-50', usa_claude_code: 'si', cta_origen: 'precio', necesidad: 'Somos 14 personas y ya usamos Claude Code a diario para desarrollo. Queremos ver cómo se reparte el trabajo entre agentes en revisión de código y documentación sin perder el contexto de cada cliente.\n\n¿Podríamos probarlo con un repositorio nuestro durante la demo?' },
  { d: 2, h: '12:03', nombre: 'Camila Rojas', email: 'camila@rojas.example', empresa: 'Rojas & Asociados', pais: 'Chile', tamano_equipo: '1', usa_claude_code: 'no', cta_origen: 'final', utm_source: 'instagram', utm_campaign: 'lanzamiento-oct', utm_content: 'carrusel-1', necesidad: 'Llevo un despacho pequeño.', atendido: true },
  { d: 4, h: '20:30', nombre: 'Andrés Molina', email: 'andres@molina.example', empresa: 'Molina Logística', pais: '', tamano_equipo: '51+', usa_claude_code: 'nose', cta_origen: 'nav', necesidad: '' },
  { d: 5, h: '08:55', nombre: 'Lucía Ferrer', email: 'lucia@ferrer.example', empresa: 'Ferrer Digital', pais: 'México', tamano_equipo: '2-10', usa_claude_code: 'si', cta_origen: 'hero', utm_source: 'correo', utm_campaign: 'alianzas', utm_content: 'correo-1', necesidad: 'Agencia de marketing.', atendido: true },
  { d: 6, h: '15:20', nombre: 'Iñaki Etxebarria', email: 'inaki@etxe.example', empresa: 'Etxe Datos', pais: 'España', tamano_equipo: '11-50', usa_claude_code: 'si', cta_origen: 'final', necesidad: 'Análisis de datos.', consent_novedades: true },
];
const atendidos = {};
const escribirLead = (l) => {
  const t = new Date(hoy.getTime() - l.d * 86400000);
  const [hh, mm] = l.h.split(':'); t.setUTCHours(+hh, +mm, 0, 0);
  const iso = t.toISOString().replace(/\.\d{3}Z$/, 'Z');
  const ymd = iso.slice(0, 10).replace(/-/g, ''), hms = iso.slice(11, 19).replace(/:/g, '');
  const hex = crypto.randomBytes(8).toString('hex');
  const id = `L-${ymd}-${hms}-${hex}`;
  const lead = { formato: 1, id, recibido: iso, nombre: l.nombre, email: l.email, empresa: l.empresa, pais: l.pais, tamano_equipo: l.tamano_equipo, usa_claude_code: l.usa_claude_code,
    necesidad: l.necesidad, cta_origen: l.cta_origen, utm_source: l.utm_source || '', utm_medium: l.utm_medium || '', utm_campaign: l.utm_campaign || '', utm_content: l.utm_content || '',
    consent_respuesta: true, consent_novedades: !!l.consent_novedades, consent_version: '34-2026-10-28', consent_fecha: iso };
  const f = path.join(COLA, `lead-${ymd}T${hms}Z-${hex}.json`);
  fs.writeFileSync(f, JSON.stringify(lead, null, 4) + '\n');
  if (l.atendido) atendidos[id] = new Date(t.getTime() + 3600000).toISOString().replace(/\.\d{3}Z$/, 'Z');
  return { id, f };
};
const ESCRITOS = LEADS.map(escribirLead);
// Detalle 2 del 16: un lead de hace 31 días que la purga aún no ha borrado (con la fecha de archivo que pone demo.php)
const CADUCADO = escribirLead({ d: 31, h: '10:00', nombre: 'Lead Caducado', email: 'viejo@caducado.example', empresa: 'Caducada SA', pais: 'Chile', tamano_equipo: '1', usa_claude_code: 'no', cta_origen: 'final', necesidad: 'Viejo.' });
{ const t = new Date(hoy.getTime() - 31 * 86400000 + 36000000); fs.utimesSync(CADUCADO.f, t, t); }
fs.writeFileSync(path.join(COLA, 'atendidos.json'), JSON.stringify(atendidos, null, 4));
const ID_TOMAS = ESCRITOS[1].id;

const LOG_PHP = path.join(BASE, 'php-errores.log');
const php = spawn(PHP, ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', `error_log=${LOG_PHP}`, '-d', 'SMTP=127.0.0.1', '-d', 'smtp_port=9',
  '-S', `127.0.0.1:${PUERTO}`, '-t', WEB], { stdio: 'ignore' });
for (let i = 0; i < 50; i++) { try { await fetch(ORIGEN + '/favicon.svg'); break; } catch { await new Promise((r) => setTimeout(r, 100)); } }

const nav = await chromium.launch();
const VISTAS = {
  escritorio: { viewport: { width: 1440, height: 900 } },
  movil: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
};
const sinScrollH = (pag) => pag.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
const captura = async (pag, nombre, completa = false) => {
  ok(await sinScrollH(pag), `${nombre}: sin scroll horizontal`);
  if (!CAPTURAS) return;
  fs.mkdirSync(CAPTURAS, { recursive: true });
  await pag.evaluate(() => document.fonts.ready);
  await pag.screenshot({ path: path.join(CAPTURAS, `panel-leads-${nombre}.png`), fullPage: completa });
};
async function contexto(v) {
  const ctx = await nav.newContext({ ...VISTAS[v], reducedMotion: 'reduce', acceptDownloads: true });
  const pag = await ctx.newPage();
  const errores = [];
  // Los 4xx/5xx esperados (login malo, api.php de estadísticas sin SQLite en local) se anotan en consola: no cuentan
  pag.on('console', (m) => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) errores.push(m.text()); });
  pag.on('pageerror', (e) => errores.push(String(e.message)));
  return { ctx, pag, errores };
}
async function entrar(pag, pass = PASS) {
  await pag.goto(ORIGEN + '/login/');
  await pag.fill('input[name=email]', EMAIL);
  await pag.fill('input[name=password]', pass);
  await pag.click('[data-login-btn]');
}
const R5 = (h) => h['cache-control'] === 'no-store' && h['x-content-type-options'] === 'nosniff' && h['referrer-policy'] === 'no-referrer'
  && /frame-ancestors 'none'/.test(h['content-security-policy'] || '') && /script-src 'self'(;|$)/.test(h['content-security-policy'] || '');

try {
  /* ================= escritorio ================= */
  console.log('\nEscritorio (1440)');
  let { ctx, pag, errores } = await contexto('escritorio');

  let r = await pag.goto(ORIGEN + '/panel/leads.php');
  ok(new URL(pag.url()).pathname === '/login/', 'sin sesión, /panel/leads.php lleva a /login/');
  const anon = await ctx.request.get(ORIGEN + '/panel/leads-api.php');
  ok(anon.status() === 401 && R5(anon.headers()), 'API sin sesión: 401 con cabeceras R5', JSON.stringify(anon.headers()));
  const get405 = await ctx.request.get(ORIGEN + '/login/entrar.php');
  ok(get405.status() === 405 && R5(get405.headers()), 'login por GET: 405 con cabeceras R5');

  await entrar(pag, 'contraseña-equivocada-pero-larga');
  await pag.waitForSelector('[data-login-msg]:not([hidden])');
  ok((await pag.textContent('[data-login-msg]')) === 'El email o la contraseña no son correctos.', 'contraseña mala: «credenciales»');
  await entrar(pag);
  await pag.waitForURL('**/panel/leads.php');
  ok(true, 'login correcto lleva a la sección Leads (aunque el aviso por correo falle)');
  const galleta = (await ctx.cookies()).find((c) => c.name === '__Host-dlpanel');
  ok(!!galleta && galleta.httpOnly && galleta.secure && galleta.sameSite === 'Strict' && galleta.path === '/' && !galleta.domain.startsWith('.') && galleta.expires === -1,
    'cookie __Host-dlpanel: HttpOnly, Secure, SameSite=Strict, path=/, de sesión', JSON.stringify(galleta));
  ok(!(await ctx.cookies()).some((c) => c.name === 'dlpanel'), 'sin la cookie antigua «dlpanel»');

  r = await pag.goto(ORIGEN + '/panel/leads.php');
  ok(r.status() === 200 && R5(r.headers()) && /style-src 'self'(;|$)/.test(r.headers()['content-security-policy']), 'página Leads: cabeceras R5 y CSP sin estilos en línea');
  await pag.waitForSelector('#filas a.fila');
  ok(await pag.locator('#filas a.fila').count() === 6, '6 leads en la lista');
  ok(await pag.locator(`#filas a.fila[data-id="${CADUCADO.id}"]`).count() === 0 && fs.existsSync(CADUCADO.f), 'un lead de más de 30 días no se muestra aunque la purga aún no lo haya borrado');
  ok((await pag.textContent('#kTotal')) === '6' && (await pag.textContent('#kNuevos')) === '4' && (await pag.textContent('#kAtendidos')) === '2', 'contadores 6 / 4 nuevos / 2 atendidos');
  ok((await pag.textContent('#kTotalSub')).startsWith('desde el ') && /^dentro de \d+ días$/.test(await pag.textContent('#kCadSub')), 'subtítulos de los contadores');
  ok((await pag.textContent('#cuenta')) === 'Mostrando 6 de 6 leads · del más reciente al más antiguo', 'cuenta de resultados');
  ok((await pag.textContent('#filas a.fila >> nth=0 >> .f-persona strong')) === 'Marta Quiroga', 'orden: el más reciente primero');
  ok((await pag.textContent('#filas a.fila >> nth=1 >> .f-origen b')) === 'directo' && (await pag.textContent('#filas a.fila >> nth=0 >> .f-origen')) === 'lanzamiento-octreel-ig', 'origen: campaña/contenido o «directo»');
  ok((await pag.textContent('#filas a.fila >> nth=3 >> .f-pais')) === '—', 'sin país: «—»');
  ok((await pag.textContent('#fNombre')) === 'Marta Quiroga', 'en escritorio la ficha muestra el primero al cargar');

  await pag.click(`#filas a.fila[data-id="${ID_TOMAS}"]`);
  await pag.waitForFunction(() => document.getElementById('fNecesidad').textContent.startsWith('Somos 14'));
  ok(new URL(pag.url()).hash === '', 'en escritorio elegir un lead no cambia la URL');
  ok((await pag.getAttribute(`#filas a.fila[data-id="${ID_TOMAS}"]`, 'aria-current')) === 'true', 'fila elegida marcada');
  ok((await pag.getAttribute('#fEmail a', 'href')) === 'mailto:tomas@lumbre.example', 'correo como enlace mailto:');
  ok((await pag.textContent('#fCta')) === 'Precio' && (await pag.textContent('#fCc')) === 'Sí' && (await pag.textContent('#fEq')) === '11-50', 'datos de la ficha');
  ok(!(await pag.isHidden('#fUtmNota')) && (await pag.textContent('#fUtm_source')) === '—', 'sin UTM: guiones y la nota «llegó de forma directa»');
  ok((await pag.textContent('#fC1t')) === 'Obligatorio · aceptado' && (await pag.textContent('#fC2t')) === 'Opcional · no aceptado', 'consentimientos');
  ok((await pag.textContent('#fQuedan')) === 'Quedan 29 días' && (await pag.getAttribute('#fBar', 'aria-label')) === 'Han pasado 1 de 30 días', 'caducidad: recibido + 30 días');
  ok(/ · 18:12 UTC$/.test(await pag.textContent('#fRecibido')), 'hora en UTC');
  await captura(pag, 'lista-escritorio');
  await captura(pag, 'lista-escritorio-completa', true);

  // Búsqueda y filtros
  await pag.fill('#q', 'brisa');
  ok(await pag.locator('#filas a.fila').count() === 1 && !(await pag.isDisabled('#limpiar')), 'búsqueda por empresa');
  await pag.fill('#q', 'LUCIA@');
  ok(await pag.locator('#filas a.fila').count() === 1, 'búsqueda por correo, sin distinguir mayúsculas ni tildes');
  await pag.fill('#q', '');
  await pag.selectOption('#fEstadoSel', 'atendido');
  ok(await pag.locator('#filas a.fila').count() === 2, 'filtro Estado: Atendido');
  await pag.selectOption('#fEstadoSel', '');
  await pag.selectOption('#fPaisSel', 'España');
  ok(await pag.locator('#filas a.fila').count() === 2, 'filtro País');
  await pag.selectOption('#fPaisSel', '-');
  ok(await pag.locator('#filas a.fila').count() === 1, 'filtro «Sin país»');
  await pag.selectOption('#fPaisSel', '');
  await pag.selectOption('#fOrigenSel', 'directo');
  ok(await pag.locator('#filas a.fila').count() === 3, 'filtro Origen: Directo');
  await pag.selectOption('#fOrigenSel', 'lanzamiento-oct');
  ok(await pag.locator('#filas a.fila').count() === 2, 'filtro Origen: campaña');
  await pag.selectOption('#fOrigenSel', '');
  await pag.fill('#q', 'zzzz-nadie');
  ok(await pag.isVisible('#sinRes') && await pag.isHidden('#lista') && await pag.isHidden('#ficha'), '«sin resultados»: tarjeta vacía en lugar de lista y ficha');
  ok((await pag.textContent('#cuenta')).startsWith('Mostrando 0 de 6'), 'cuenta con 0');
  await captura(pag, 'sin-resultados-escritorio');
  await pag.click('#limpiar2');
  ok(await pag.locator('#filas a.fila').count() === 6 && (await pag.inputValue('#q')) === '' && await pag.isDisabled('#limpiar'), '«Limpiar filtros» vuelve a la lista completa');

  // Atendido
  await pag.click(`#filas a.fila[data-id="${ID_TOMAS}"]`);
  await pag.waitForFunction(() => document.getElementById('fNecesidad').textContent.startsWith('Somos 14'));
  await pag.click('#fAccion');
  await pag.waitForFunction(() => document.getElementById('fAccionTxt').textContent === 'Volver a nuevo');
  ok((await pag.textContent('#fEstado')) === 'Atendido' && (await pag.getAttribute('#fAccion', 'class')) === 'ghost' && /^Atendido el /.test(await pag.textContent('#fAtendido')), 'marcar como atendido: insignia, fecha y botón «Volver a nuevo»');
  ok((await pag.textContent('#kAtendidos')) === '3' && (await pag.textContent('#kNuevos')) === '3', 'contadores actualizados');
  ok((await pag.textContent(`#filas a.fila[data-id="${ID_TOMAS}"] .est`)) === 'Atendido', 'la fila cambia a «Atendido»');
  await captura(pag, 'atendido-escritorio');
  await pag.reload();
  await pag.waitForSelector('#filas a.fila');
  ok((await pag.textContent(`#filas a.fila[data-id="${ID_TOMAS}"] .est`)) === 'Atendido', 'el estado persiste al recargar');
  const leadTomas = fs.readFileSync(ESCRITOS[1].f, 'utf8');
  ok(!leadTomas.includes('atendido'), 'el JSON del lead no se toca');
  await pag.click(`#filas a.fila[data-id="${ID_TOMAS}"]`);
  await pag.waitForFunction(() => document.getElementById('fAccionTxt').textContent === 'Volver a nuevo');
  await pag.click('#fAccion');
  await pag.waitForFunction(() => document.getElementById('fAccionTxt').textContent === 'Marcar como atendido');
  ok((await pag.textContent('#fEstado')) === 'Nuevo' && (await pag.textContent('#kAtendidos')) === '2', '«Volver a nuevo» deshace la marca');

  // CSV
  const origenes = [];
  pag.on('request', async (x) => { if (x.method() === 'POST') origenes.push((await x.allHeaders()).origin); });
  const [descarga] = await Promise.all([pag.waitForEvent('download', { timeout: 10000 }), pag.click('#csv')]);
  await new Promise((r) => setTimeout(r, 200));
  ok(origenes.length > 0 && origenes.every((o) => o === ORIGEN), 'los POST del navegador llevan el Origin real (no «null») pese a no-referrer', origenes.join(','));
  const csv = fs.readFileSync(await descarga.path(), 'utf8');
  ok(descarga.suggestedFilename() === 'leads-dl-dev.csv', 'CSV con nombre fijo');
  ok(csv.startsWith('\uFEFF"id";"recibido"') && csv.trim().split('\r\n').length === 7, 'CSV con cabecera y 6 filas');
  ok(csv.includes('"Tomás Aguirre"') && csv.includes('"Somos 14 personas'), 'CSV con los datos y la necesidad completa');

  // CSRF / Origin por HTTP real. El cliente HTTP de Playwright no manda cookies Secure por http://127.0.0.1:
  // se le pasa la de la sesión del navegador a mano (así puede además falsificar Origin y Sec-Fetch-Site).
  const csrf = await pag.inputValue('#csrf');
  const GALLETA = { Cookie: '__Host-dlpanel=' + (await ctx.cookies()).find((c) => c.name === '__Host-dlpanel').value };
  const post = (cuerpo, cab = {}) => ctx.request.post(ORIGEN + '/panel/leads-api.php', { headers: { 'Content-Type': 'application/json', Origin: ORIGEN, ...GALLETA, ...cab }, data: JSON.stringify(cuerpo) });
  ok((await post({ accion: 'atendido', id: ID_TOMAS, atendido: true })).status() === 403, 'atendido sin token CSRF: 403');
  ok((await post({ accion: 'atendido', id: ID_TOMAS, atendido: true, csrf }, { Origin: 'https://evil.example' })).status() === 403, 'atendido con token pero Origin ajeno: 403');
  ok((await post({ accion: 'atendido', id: ID_TOMAS, atendido: true, csrf }, { 'Sec-Fetch-Site': 'cross-site' })).status() === 403, 'atendido con Sec-Fetch-Site cross-site: 403');
  const sinOrigen = await ctx.request.fetch(ORIGEN + '/panel/leads-api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', ...GALLETA }, data: JSON.stringify({ accion: 'atendido', id: ID_TOMAS, atendido: true, csrf }) });
  ok(sinOrigen.status() === 403, 'atendido sin Origin: 403');
  ok((await post({ accion: 'atendido', id: ID_TOMAS, atendido: true, csrf }, { Origin: 'null' })).status() === 403, 'atendido con Origin «null»: 403');
  ok((await ctx.request.get(ORIGEN + '/panel/leads-api.php?id=..%2F..%2Fconfig', { headers: GALLETA })).status() === 400, 'ficha con id-ruta: 400');
  const csvGet = await ctx.request.get(ORIGEN + '/panel/leads-csv.php', { headers: GALLETA });
  ok(csvGet.status() === 405 && R5(csvGet.headers()), 'CSV por GET: 405 con cabeceras R5');
  const csvMalo = await ctx.request.post(ORIGEN + '/panel/leads-csv.php', { headers: { Origin: ORIGEN, ...GALLETA }, form: { csrf: 'x'.repeat(64) } });
  ok(csvMalo.status() === 403, 'CSV con token malo: 403');
  const csvBueno = await ctx.request.post(ORIGEN + '/panel/leads-csv.php', { headers: { Origin: ORIGEN, ...GALLETA }, form: { csrf } });
  ok(csvBueno.status() === 200 && R5(csvBueno.headers()) && csvBueno.headers()['content-disposition'] === 'attachment; filename="leads-dl-dev.csv"', 'CSV: adjunto con cabeceras R5');

  // XSS y fórmulas en datos del lead (manipulados a mano en la cola: el panel no se fía de /demo)
  const xss = escribirLead({ d: 0, h: '23:59', nombre: '<img src=x onerror="window.__xss=1">', email: 'x"onmouseover="alert(1)"@evil.example', empresa: '"><svg onload=window.__xss=2>',
    pais: 'Chile', tamano_equipo: '1', usa_claude_code: 'si', cta_origen: 'final', necesidad: '<script>window.__xss=3</script>\n=HYPERLINK("http://evil.example")' });
  const formula = escribirLead({ d: 0, h: '23:58', nombre: '=1+1', email: 'f@formula.example', empresa: '+SUM(A1)', pais: 'Chile', tamano_equipo: '1', usa_claude_code: 'si', cta_origen: 'final', necesidad: '-2+3' });
  await pag.reload();
  await pag.waitForSelector(`#filas a.fila[data-id="${xss.id}"]`);
  await pag.click(`#filas a.fila[data-id="${xss.id}"]`);
  await pag.waitForFunction(() => document.getElementById('fNecesidad').textContent.includes('<script>'));
  ok(await pag.evaluate(() => window.__xss === undefined), 'datos con HTML no se ejecutan');
  ok((await pag.textContent('#fNombre')) === '<img src=x onerror="window.__xss=1">' && (await pag.textContent('#fEmpresa')) === '"><svg onload=window.__xss=2>', 'se ven como texto literal');
  ok(await pag.locator('#fEmail a').count() === 0 && (await pag.textContent('#fEmail')) === 'x"onmouseover="alert(1)"@evil.example', 'correo no válido: sin enlace');
  ok(await pag.locator('#ficha img, #ficha script:not([src]), #filas img').count() === 0, 'ningún elemento creado a partir de los datos');
  const [d2] = await Promise.all([pag.waitForEvent('download'), pag.click('#csv')]);
  const csv2 = fs.readFileSync(await d2.path(), 'utf8');
  ok(csv2.includes(`"'=1+1"`) && csv2.includes(`"'+SUM(A1)"`) && csv2.includes(`"'-2+3"`) && csv2.includes(`"<script>window.__xss=3</script>\n=HYPERLINK(""http://evil.example"")"`), 'CSV: fórmulas con apóstrofo, comillas dobladas');
  fs.unlinkSync(xss.f); fs.unlinkSync(formula.f);

  // H3 (tarea 51): campañas llamadas como propiedades de Object.prototype siguen en el filtro Origen
  const proto = escribirLead({ d: 0, h: '23:57', nombre: 'Proto Ficticio', email: 'p@proto.example', empresa: 'Proto', pais: 'Chile', tamano_equipo: '1', usa_claude_code: 'si', cta_origen: 'final', utm_source: 'x', utm_campaign: '__proto__' });
  const ctor = escribirLead({ d: 0, h: '23:56', nombre: 'Ctor Ficticio', email: 'c@ctor.example', empresa: 'Ctor', pais: 'Chile', tamano_equipo: '1', usa_claude_code: 'si', cta_origen: 'final', utm_source: 'x', utm_campaign: 'constructor' });
  await pag.reload();
  await pag.waitForSelector(`#filas a.fila[data-id="${proto.id}"]`);
  ok(await pag.locator('#fOrigenSel option[value="__proto__"]').count() === 1 && await pag.locator('#fOrigenSel option[value="constructor"]').count() === 1, 'H3: «__proto__» y «constructor» aparecen en el filtro Origen');
  await pag.selectOption('#fOrigenSel', '__proto__');
  ok(await pag.locator('#filas a.fila').count() === 1 && (await pag.textContent('#filas a.fila >> nth=0 >> .f-persona strong')) === 'Proto Ficticio', 'H3: filtrar por «__proto__» muestra su lead');
  await pag.selectOption('#fOrigenSel', '');
  fs.unlinkSync(proto.f); fs.unlinkSync(ctor.f);

  // Menú Estadísticas / Leads
  await pag.goto(ORIGEN + '/panel/');
  ok((await pag.getAttribute('.nav a[aria-current=page]', 'href')) === '/panel/', 'el panel de estadísticas tiene el menú, con «Estadísticas» activo');
  await pag.click('.nav a[href="/panel/leads.php"]');
  await pag.waitForURL('**/panel/leads.php');
  ok(true, 'del menú se llega a Leads');
  ok(errores.length === 0, 'sin errores de JavaScript ni de consola (escritorio)', errores.join(' | '));
  await ctx.close();

  /* ================= H1 (tarea 51): inactividad con la pestaña abierta y el sondeo activo ================= */
  // Tiempo simulado: el reloj del navegador lo mueve Playwright (clock.runFor dispara el sondeo de 60 s) y el
  // del servidor se simula envejeciendo la sesión 60 s por minuto («inicio» y «ultimo» del archivo de sesión).
  console.log('\nInactividad de 30 min con el sondeo activo (H1)');
  const so = await contexto('escritorio');
  await entrar(so.pag);
  await so.pag.waitForURL('**/panel/leads.php');
  const idSes = (await so.ctx.cookies()).find((c) => c.name === '__Host-dlpanel').value;
  const fSes = path.join(COLA, 'panel', 'sesiones', 'sess_' + idSes);
  const envejecer = (s) => fs.writeFileSync(fSes, fs.readFileSync(fSes, 'utf8').replace(/(inicio|ultimo)\|i:(\d+);/g, (m, k, n) => `${k}|i:${Number(n) - s};`));
  const ultimo = () => Number(/ultimo\|i:(\d+);/.exec(fs.readFileSync(fSes, 'utf8'))[1]);

  // Leads: no sondea (10 min sin tocar nada → ninguna petición a la API)
  await so.pag.clock.install();
  await so.pag.reload();
  await so.pag.waitForSelector('#filas a.fila');
  let llamadasLeads = 0;
  const cuentaLeads = (x) => { if (x.url().includes('/panel/leads-api.php')) llamadasLeads++; };
  so.pag.on('request', cuentaLeads);
  await so.pag.clock.runFor(600000);
  so.pag.off('request', cuentaLeads);
  ok(llamadasLeads === 0, 'Leads abierto 10 min sin tocar nada: ninguna llamada automática a la API', String(llamadasLeads));

  // Estadísticas: sondeo de api.php cada 60 s
  const apiResp = (accion) => so.pag.waitForResponse((x) => x.url().includes('/panel/api.php'), { timeout: 10000 })
    .then(async (x) => ({ st: x.status(), cab: (await x.request().allHeaders())['x-dl-accion'] || '' }));
  const primera = apiResp();
  await so.pag.goto(ORIGEN + '/panel/');
  const p0 = await primera;
  ok(p0.st !== 401 && p0.cab === '1', 'al abrir Estadísticas, la carga inicial es una acción del usuario (X-DL-Accion: 1)', JSON.stringify(p0));
  const sondeo = async () => { envejecer(60); const r = apiResp(); await so.pag.clock.runFor(60000); return r; };
  const fase1 = [];
  for (let i = 0; i < 20; i++) fase1.push(await sondeo());
  ok(fase1.every((x) => x.st !== 401 && x.cab === ''), '20 sondeos automáticos (20 min): sin X-DL-Accion y la sesión sigue', JSON.stringify(fase1.slice(-2)));
  const antesAccion = ultimo();
  const accion = apiResp();
  await so.pag.click('#actualizar');
  const pa = await accion;
  ok(pa.st !== 401 && pa.cab === '1' && ultimo() > antesAccion + 1000, '«Actualizar» (acción del usuario) renueva la inactividad', JSON.stringify(pa));
  const fase2 = [];
  for (let i = 0; i < 29; i++) fase2.push(await sondeo());
  ok(fase2.every((x) => x.st !== 401 && x.cab === ''), '29 sondeos más (29 min desde la última acción): la sesión sigue', JSON.stringify(fase2.slice(-1)));
  const login = so.pag.waitForURL('**/login/', { timeout: 10000 });
  const p30 = await sondeo();
  await login;
  ok(p30.st === 401 && new URL(so.pag.url()).pathname === '/login/', 'con la pestaña abierta y el sondeo activo, la sesión caduca a los 30 min de la última acción y vuelve al login', JSON.stringify(p30));
  ok(!fs.existsSync(fSes), 'la sesión caducada se borra del servidor');
  const trasCaducar = await so.ctx.request.get(ORIGEN + '/panel/leads-api.php', { headers: { 'X-DL-Accion': '1', Cookie: '__Host-dlpanel=' + idSes } });
  ok(trasCaducar.status() === 401, 'tras caducar, ni una acción del usuario la revive (401)');
  await so.ctx.close();

  /* ================= móvil ================= */
  console.log('\nMóvil (390)');
  ({ ctx, pag, errores } = await contexto('movil'));
  await entrar(pag);
  await pag.waitForURL('**/panel/leads.php');
  await pag.waitForSelector('#filas a.fila');
  ok(await pag.isHidden('#ficha') || !(await pag.locator('#ficha').boundingBox()), 'en móvil la ficha empieza cerrada');
  await captura(pag, 'lista-movil');
  await captura(pag, 'lista-movil-completa', true);
  await pag.tap(`#filas a.fila[data-id="${ID_TOMAS}"]`);
  await pag.waitForFunction(() => document.getElementById('fNecesidad').textContent.startsWith('Somos 14'));
  ok(await pag.isVisible('#ficha') && await pag.isVisible('#volver'), 'al tocar una fila se abre la ficha a pantalla completa con «Volver a la lista»');
  ok(await pag.evaluate(() => document.activeElement && document.activeElement.id === 'fNombre'), 'el foco va al nombre del lead');
  const caja = await pag.locator('#fAccion').boundingBox();
  ok(caja && caja.y + caja.height <= 844 && caja.height >= 44, 'botón de atender visible abajo y de 44 px o más', JSON.stringify(caja));
  await pag.waitForTimeout(1000);                 // que se apague el resaltado del toque de Chrome antes de la captura
  await captura(pag, 'ficha-movil');
  await pag.click('#volver');
  await pag.waitForFunction(() => !document.getElementById('ficha').classList.contains('abierta'));
  ok(await pag.evaluate(() => document.activeElement && document.activeElement.dataset.id !== undefined), '«Volver a la lista» cierra la ficha y devuelve el foco a la fila');
  await pag.tap(`#filas a.fila[data-id="${ID_TOMAS}"]`);
  await pag.waitForFunction(() => document.getElementById('ficha').classList.contains('abierta'));
  await pag.goBack();
  await pag.waitForFunction(() => !document.getElementById('ficha').classList.contains('abierta'));
  ok(new URL(pag.url()).pathname === '/panel/leads.php', '«Atrás» del navegador cierra la ficha sin salir de la página');
  await pag.fill('#q', 'zzzz-nadie');
  await captura(pag, 'sin-resultados-movil');
  await pag.fill('#q', '');

  // Estado vacío
  const aparte = path.join(BASE, 'aparte'); fs.mkdirSync(aparte);
  const archivos = fs.readdirSync(COLA).filter((f) => f.startsWith('lead-'));
  for (const f of archivos) fs.renameSync(path.join(COLA, f), path.join(aparte, f));
  await pag.reload();
  await pag.waitForSelector('#vacio:not([hidden])');
  ok(await pag.isHidden('#filtros') && await pag.isHidden('#vista') && await pag.isDisabled('#csv') && (await pag.textContent('#kTotal')) === '0' && (await pag.textContent('#kCad')) === '—', 'sin leads: estado vacío, CSV deshabilitado y contadores a 0');
  await captura(pag, 'vacio-movil');
  const esc = await contexto('escritorio');
  await entrar(esc.pag);
  await esc.pag.waitForURL('**/panel/leads.php');
  await esc.pag.waitForSelector('#vacio:not([hidden])');
  await captura(esc.pag, 'vacio-escritorio');
  for (const f of archivos) fs.renameSync(path.join(aparte, f), path.join(COLA, f));

  // Salir
  await esc.pag.click('.salir');
  await esc.pag.waitForURL('**/login/');
  r = await esc.pag.goto(ORIGEN + '/panel/leads.php');
  ok(new URL(esc.pag.url()).pathname === '/login/', 'tras «Salir», Leads vuelve a pedir login');
  ok(!(await esc.ctx.cookies()).some((c) => c.name === '__Host-dlpanel' && c.value), 'la cookie de sesión se borra');
  ok(errores.length === 0 && esc.errores.length === 0, 'sin errores de JavaScript ni de consola (móvil y vacío)', errores.concat(esc.errores).join(' | '));
  await esc.ctx.close();
  await ctx.close();

  /* ================= registro y bloqueo ================= */
  console.log('\nRegistro y bloqueo');
  const panelDir = path.join(COLA, 'panel');
  const reg = fs.readdirSync(path.join(panelDir, 'registro')).map((f) => fs.readFileSync(path.join(panelDir, 'registro', f), 'utf8')).join('');
  ok(/"accion":"login".*"r":"fallo"/.test(reg) && /"accion":"atendido"/.test(reg) && /"accion":"csv"/.test(reg) && /"accion":"salir"/.test(reg), 'registro de accesos con login, atendido, CSV y salir');
  const prohibido = ['127.0.0.1', EMAIL, PASS, 'Tomás', 'tomas@lumbre', 'Lumbre', 'Somos 14', 'lanzamiento-oct', 'Argentina'].filter((p) => reg.includes(p));
  ok(prohibido.length === 0, 'el registro no tiene IP, credenciales ni datos de leads', prohibido.join(', '));
  ok(!fs.readdirSync(WEB, { recursive: true }).some((f) => /sess_|acceso-|atendidos|bloqueo/.test(String(f))), 'nada de sesiones, registro ni estado dentro de public_html');

  const bl = await contexto('escritorio');
  const intento = (pass) => bl.ctx.request.post(ORIGEN + '/login/entrar.php', { headers: { 'Content-Type': 'application/json', Origin: ORIGEN }, data: JSON.stringify({ email: EMAIL, password: pass, web: '' }) });
  for (let i = 0; i < 5; i++) await intento('otra-contraseña-equivocada-' + i);
  const tras = await intento(PASS);
  ok(tras.status() === 401 && (await tras.json()).error === 'credenciales', 'tras 5 fallos, la contraseña buena recibe «credenciales» (bloqueo de 15 min)');
  await bl.ctx.close();

  // Purga diaria por Cron (detalle 2 del 16): por la web no corre; por línea de comandos borra lo caducado
  const webPurga = await fetch(ORIGEN + '/inc/purgar.php');
  ok(webPurga.status === 404 && fs.existsSync(CADUCADO.f), 'inc/purgar.php por la web: 404 y no purga nada');
  const salidaPurga = execFileSync(PHP, ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', `error_log=${LOG_PHP}`, path.join(WEB, 'inc', 'purgar.php')]).toString();
  ok(!fs.existsSync(CADUCADO.f) && ESCRITOS.every((x) => fs.existsSync(x.f)) && /purga: 1 leads borrados, 6 quedan/.test(salidaPurga) && /panel: purgado/.test(salidaPurga),
    'el Cron (php inc/purgar.php) borra el lead de 31 días sin esperar a una visita y deja los demás', salidaPurga.trim());
  ok(!/Caducado|caducado\.example|Tomás|lumbre/.test(salidaPurga), 'la salida del Cron solo lleva recuentos');

  const log = fs.existsSync(LOG_PHP) ? fs.readFileSync(LOG_PHP, 'utf8') : '';
  const raros = log.split('\n').filter((l) => /PHP (Warning|Notice|Deprecated|Fatal|Parse)/.test(l));
  ok(raros.length === 0, 'PHP sin avisos ni errores', raros.slice(0, 3).join(' | '));
  ok(/no se pudo enviar el aviso de login/.test(log), 'el aviso por correo falló (puerto cerrado) y aun así se entró');
} catch (e) {
  ok(false, 'el E2E terminó sin excepciones', String(e && e.stack || e));
} finally {
  await nav.close();
  php.kill();
  await new Promise((r) => setTimeout(r, 300));
  try { fs.rmSync(BASE, { recursive: true, force: true }); } catch {}
}
console.log(fallos === 0 ? `\nTODO BIEN: ${total} comprobaciones` : `\nFALLAN ${fallos} de ${total} comprobaciones`);
process.exit(fallos === 0 ? 0 : 1);
