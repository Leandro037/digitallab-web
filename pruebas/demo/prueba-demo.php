<?php
/*
 * Pruebas de demo/demo.php (tarea 5). Sin dependencias: PHP 8.1+ en línea de comandos.
 *
 *   php pruebas/demo/prueba-demo.php                      (desde la raíz del repositorio)
 *   php prueba-demo.php /ruta/a/public_html/demo/demo.php (en el servidor, p. ej. por Cron Jobs)
 *
 * Trabaja solo en una carpeta temporal nueva con datos ficticios y la borra al terminar.
 * No toca la cola real ni hace peticiones de red. Sale con 0 si todo pasa y 1 si algo falla.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DL_DEMO_PRUEBAS', 1);
require $argv[1] ?? dirname(__DIR__, 2) . '/demo/demo.php';

$fallos = 0;
$total = 0;
function ok(bool $cond, string $nombre, string $detalle = ''): void
{
    global $fallos, $total;
    $total++;
    if ($cond) { echo "  ok   $nombre\n"; return; }
    $fallos++;
    echo "  FALLA $nombre" . ($detalle !== '' ? " · $detalle" : '') . "\n";
}

function borrar_arbol(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$d/$f";
        is_dir($p) ? borrar_arbol($p) : @unlink($p);
    }
    @rmdir($d);
}

$BASE = sys_get_temp_dir() . '/dl-demo-prueba-' . bin2hex(random_bytes(6));
$WEB = "$BASE/public_html";
$COLA = "$BASE/dl-leads";
mkdir($WEB, 0755, true);
mkdir($COLA, 0700, true);
register_shutdown_function(fn() => borrar_arbol($BASE));

$CFG = [
    'sal' => str_repeat('s4l-de-prueba-', 4),
    'cola_dir' => $COLA,
    'web_dir' => $WEB,
    'origenes' => ['https://dl-dev.com', 'https://www.dl-dev.com'],
    'consent_versiones' => ['34-2026-10-28'],
];
$AHORA = 1793000000;                                   // fijo: 2026-10-26, las pruebas no dependen del reloj
$IP = '203.0.113.77';                                  // IP de documentación (RFC 5737)

function servidor(array $extra = []): array
{
    global $WEB, $IP;
    return $extra + [
        'REQUEST_METHOD' => 'POST',
        'HTTP_ORIGIN' => 'https://dl-dev.com',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => $IP,
        'DOCUMENT_ROOT' => $WEB,
    ];
}

function lead(array $cambios = []): array
{
    return array_merge([
        'nombre' => 'Ana Prueba',
        'email' => 'ana@ejemplo.test',
        'empresa' => 'Empresa Ficticia SL',
        'pais' => 'España',
        'tamano_equipo' => '2-10',
        'usa_claude_code' => 'si',
        'necesidad' => "Automatizar informes.\nY responder correos.",
        'consent_respuesta' => true,
        'consent_novedades' => false,
        'web' => '',
        't' => 8000,
        'cta_origen' => 'hero',
        'consent_version' => '34-2026-10-28',
        'utm_source' => 'instagram',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'demo-ar-2026-11',
        'utm_content' => 'anuncio_1',
    ], $cambios);
}

function enviar(array|string $datos, array $srv = [], ?array $cfg = null, ?int $ahora = null): array
{
    global $CFG, $AHORA;
    $cuerpo = is_string($datos) ? $datos : json_encode($datos, JSON_UNESCAPED_UNICODE);
    return demo_procesar(servidor($srv), $cuerpo, $cfg ?? $CFG, $ahora ?? $AHORA);
}

function leads(): array
{
    global $COLA;
    return glob("$COLA/lead-*.json") ?: [];
}

function vaciar(): void
{
    global $COLA;
    foreach (leads() as $f) unlink($f);
    foreach (glob("$COLA/limites/*") ?: [] as $f) unlink($f);
}

// Cada prueba usa una IP distinta para no chocar con el límite por hora
$n = 0;
function otra_ip(): array { global $n; $n++; return ['REMOTE_ADDR' => '198.51.100.' . ($n % 250 + 1)]; }

$posix = DIRECTORY_SEPARATOR === '/';

echo "\n1. Envío válido: un JSON en la cola, 0600, con consentimiento y UTM, sin IP\n";
vaciar();
[$c, $r] = enviar(lead());
ok($c === 200 && $r === ['ok' => true], 'responde 200 {ok:true}', "$c " . json_encode($r));
$fs = leads();
ok(count($fs) === 1, 'crea exactamente un archivo lead-*.json', (string) count($fs));
$j = $fs ? json_decode((string) file_get_contents($fs[0]), true) : [];
ok(($j['consent_respuesta'] ?? null) === true && ($j['consent_version'] ?? '') === '34-2026-10-28'
    && ($j['consent_fecha'] ?? '') === gmdate('Y-m-d\TH:i:s\Z', $AHORA), 'guarda consentimiento, versión y fecha');
ok(($j['consent_novedades'] ?? null) === false, 'casilla de novedades sin marcar = false');
ok(($j['utm_source'] ?? '') === 'instagram' && ($j['utm_medium'] ?? '') === 'paid_social'
    && ($j['utm_campaign'] ?? '') === 'demo-ar-2026-11' && ($j['utm_content'] ?? '') === 'anuncio_1', 'guarda las 4 utm_*');
ok(($j['nombre'] ?? '') === 'Ana Prueba' && ($j['pais'] ?? '') === 'España' && ($j['tamano_equipo'] ?? '') === '2-10'
    && ($j['usa_claude_code'] ?? '') === 'si' && ($j['cta_origen'] ?? '') === 'hero'
    && ($j['necesidad'] ?? '') === "Automatizar informes.\nY responder correos.", 'guarda los campos del formulario');
ok(!array_key_exists('web', $j) && !array_key_exists('t', $j), 'no guarda el campo trampa ni t');
$todo = '';
foreach (array_merge($fs, glob("$COLA/limites/*") ?: []) as $f) $todo .= basename($f) . file_get_contents($f);
ok(!str_contains($todo, $IP) && !str_contains($todo, hash('sha256', $IP)), 'la IP no aparece en la cola ni en los límites (ni en claro ni como sha256 sin sal)');
if ($posix) {
    ok((fileperms($fs[0]) & 0777) === 0600, 'el archivo tiene permisos 0600', sprintf('%o', fileperms($fs[0]) & 0777));
    ok((fileperms("$COLA/limites") & 0777) === 0700, 'la carpeta de límites tiene 0700');
} else {
    echo "  --   permisos 0600/0700: no se comprueban en Windows (ejecutar en Linux o en el servidor)\n";
}
ok(!glob("$COLA/.tmp-*"), 'no quedan temporales');

echo "\n2. Sin UTM y con UTM no válidas: vacías\n";
vaciar();
[$c] = enviar(lead(['utm_source' => '', 'utm_medium' => null, 'utm_campaign' => '<script>alert(1)</script>', 'utm_content' => str_repeat('a', 61)]) + [], otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok($c === 200, 'el envío se acepta igual');
ok(($j['utm_source'] ?? 'x') === '' && ($j['utm_medium'] ?? 'x') === '' && ($j['utm_campaign'] ?? 'x') === '' && ($j['utm_content'] ?? 'x') === '',
    'vacía, null, <script> y 61 caracteres → vacías', json_encode(array_intersect_key($j, array_flip(DEMO_UTM))));
vaciar();
$d = lead(); unset($d['utm_source'], $d['utm_medium'], $d['utm_campaign'], $d['utm_content']);
enviar($d, otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok(array_key_exists('utm_source', $j) && $j['utm_source'] === '' && $j['utm_content'] === '', 'sin UTM en la petición: las 4 claves existen y vacías');
ok(demo_utm(str_repeat('b', 60)) === str_repeat('b', 60) && demo_utm('a b') === '' && demo_utm('ñu') === ''
    && demo_utm("abc\n") === '' && demo_utm(['x']) === '' && demo_utm('A-z_9') === 'A-z_9', 'regla UTM: 60 sí, espacio, ñ, salto final y lista no');

echo "\n3. Campo trampa y tiempo t\n";
vaciar();
[$c, $r] = enviar(lead(['web' => 'https://spam.test']), otra_ip());
ok($c === 200 && $r === ['ok' => true] && !leads(), 'trampa rellena: 200 sin guardar');
[$c, $r] = enviar(lead(['t' => 2999]), otra_ip());
ok($c === 200 && !leads(), 't < 3 s: 200 sin guardar');
[$c] = enviar(lead(['t' => 3000]), otra_ip());
ok($c === 200 && count(leads()) === 1, 't = 3 s: se guarda');
vaciar();
[$c, $r] = enviar(lead(['t' => 7200001]), otra_ip());
ok($c === 400 && ($r['error'] ?? '') === 'tiempo' && !leads(), 't > 2 h: 400 tiempo');
[$c, $r] = enviar(lead(['t' => 'abc']), otra_ip());
ok($c === 400 && ($r['error'] ?? '') === 'datos', 't no numérico: 400 datos');
$d = lead(); unset($d['t']);
[$c, $r] = enviar($d, otra_ip());
ok($c === 400 && ($r['error'] ?? '') === 'datos', 'sin t: 400 datos');
[$c] = enviar(lead(['t' => '8000']), otra_ip());
ok($c === 200, 't como texto numérico: se acepta');
[$c] = enviar(lead(['t' => 8000.7]), otra_ip());
ok($c === 200, 't con decimales: se acepta');

echo "\n4. Consentimiento: solo true\n";
vaciar();
foreach (['false' => false, '"true"' => 'true', '1' => 1, 'ausente' => null] as $etq => $v) {
    $d = lead(['consent_respuesta' => $v]);
    if ($v === null) unset($d['consent_respuesta']);
    [$c, $r] = enviar($d, otra_ip());
    ok($c === 400 && ($r['campos']['consent_respuesta'] ?? '') === 'falta', "consent_respuesta = $etq → 400 campos");
}
ok(!leads(), 'ninguno se guarda');
[$c] = enviar(lead(['consent_novedades' => true]), otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok(($j['consent_novedades'] ?? null) === true, 'novedades marcada = true');
vaciar();
[$c] = enviar(lead(['consent_novedades' => 'true']), otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok(($j['consent_novedades'] ?? null) === false, 'novedades "true" (texto) = false');
[$c, $r] = enviar(lead(['consent_version' => '34-2025-01-01']), otra_ip());
ok($c === 409 && ($r['error'] ?? '') === 'version', 'versión de consentimiento desconocida: 409 version');

echo "\n5. Validación y longitudes\n";
vaciar();
$casos = [
    ['nombre', '', 'falta'], ['nombre', '   ', 'falta'], ['nombre', str_repeat('n', 101), 'largo'], ['nombre', ['x'], 'falta'],
    ['email', '', 'falta'], ['email', 'ana@', 'invalido'], ['email', 'ana @ejemplo.test', 'invalido'],
    ['email', str_repeat('a', 64) . '@' . str_repeat('b', 186) . '.test', 'largo'],
    ['empresa', '', 'falta'], ['empresa', str_repeat('e', 121), 'largo'],
    ['usa_claude_code', 'quizas', 'falta'], ['usa_claude_code', '', 'falta'],
    ['necesidad', str_repeat('x', 1001), 'largo'], ['necesidad', 5, 'invalido'],
];
foreach ($casos as [$campo, $valor, $esperado]) {
    [$c, $r] = enviar(lead([$campo => $valor]), otra_ip());
    $txt = is_string($valor) ? (strlen($valor) > 20 ? strlen($valor) . ' car.' : "\"$valor\"") : gettype($valor);
    ok($c === 400 && ($r['error'] ?? '') === 'campos' && ($r['campos'][$campo] ?? '') === $esperado,
        "$campo = $txt → $esperado", "$c " . json_encode($r));
}
ok(!leads(), 'ningún envío inválido se guarda');
[$c, $r] = enviar(lead(['nombre' => '', 'email' => 'x', 'consent_respuesta' => false]), otra_ip());
ok(count($r['campos'] ?? []) === 3, 'varios errores a la vez: devuelve todos los campos');
[$c] = enviar(lead(['nombre' => str_repeat('ñ', 100), 'empresa' => str_repeat('é', 120), 'necesidad' => str_repeat('á', 1000)]), otra_ip());
ok($c === 200, 'en el límite exacto (100/120/1000 caracteres con acentos): se acepta');
vaciar();
[$c] = enviar(lead(['pais' => 'Narnia', 'tamano_equipo' => '999', 'cta_origen' => '<b>', 'necesidad' => '']), otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok($c === 200 && $j['pais'] === '' && $j['tamano_equipo'] === '' && $j['cta_origen'] === 'final' && $j['necesidad'] === '',
    'listas cerradas: valor ajeno → vacío (cta → final); opcional vacío se acepta');
vaciar();
[$c] = enviar(lead(['nombre' => "Ana\x00\x1b[31m\u{202E}Prueba\u{200B}  ", 'empresa' => "Ficticia\r\nBcc: x@y", 'necesidad' => "uno\r\n\r\n\r\n\r\ndos\x07"]), otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok(($j['nombre'] ?? '') === 'Ana [31mPrueba', 'nombre: sin controles ni invisibles', json_encode($j['nombre'] ?? null));
ok(($j['empresa'] ?? '') === 'Ficticia Bcc: x@y', 'empresa: saltos de línea → espacio', json_encode($j['empresa'] ?? null));
ok(($j['necesidad'] ?? '') === "uno\n\ndos", 'necesidad: conserva saltos (máx. una línea en blanco), sin controles', json_encode($j['necesidad'] ?? null));

echo "\n6. Petición: método, origen, tipo, tamaño y JSON\n";
vaciar();
[$c, $r, $h] = enviar(lead(), ['REQUEST_METHOD' => 'GET']);
ok($c === 405 && ($h['Allow'] ?? '') === 'POST', 'GET: 405 con Allow: POST');
[$c, $r] = enviar(lead(), ['HTTP_ORIGIN' => 'https://evil.test']);
ok($c === 403 && $r['error'] === 'origen', 'origen ajeno: 403');
[$c] = enviar(lead(), ['HTTP_ORIGIN' => 'https://dl-dev.com.evil.test']);
ok($c === 403, 'origen que empieza igual: 403');
$s = servidor(); unset($s['HTTP_ORIGIN']);
[$c] = demo_procesar($s, json_encode(lead()), $CFG, $AHORA);
ok($c === 403, 'sin Origin: 403');
[$c] = enviar(lead(), ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
ok($c === 403, 'Sec-Fetch-Site cross-site: 403');
[$c, $r] = enviar(lead(), ['CONTENT_TYPE' => 'text/plain']);
ok($c === 415 && $r['error'] === 'tipo', 'text/plain: 415');
[$c] = enviar(lead(), ['CONTENT_TYPE' => 'application/json; charset=utf-8'] + otra_ip());
ok($c === 200, 'application/json; charset=utf-8: se acepta');
vaciar();
[$c] = enviar(json_encode(lead(['necesidad' => str_repeat('x', 17000)])), otra_ip());
ok($c === 413, 'cuerpo > 16 KB: 413');
[$c, $r] = enviar('{no es json', otra_ip());
ok($c === 400 && $r['error'] === 'datos', 'JSON roto: 400 datos');
[$c] = enviar('[1,2,3]', otra_ip());
ok($c === 400, 'JSON lista: 400');
[$c] = enviar(json_encode(['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]), otra_ip());
ok($c === 400, 'JSON demasiado anidado: 400');
ok(!leads(), 'nada se guarda');

echo "\n7. Límite: 5 por hora y visitante\n";
vaciar();
$ipFija = ['REMOTE_ADDR' => '192.0.2.10'];
$codigos = [];
for ($i = 0; $i < 6; $i++) $codigos[] = enviar(lead(), $ipFija, null, $AHORA + $i)[0];
ok($codigos === [200, 200, 200, 200, 200, 429], '6.º envío en la hora: 429', implode(',', $codigos));
[$c, $r, $h] = enviar(lead(), $ipFija, null, $AHORA + 10);
ok($c === 429 && $r['error'] === 'limite' && ($h['Retry-After'] ?? '') === '3600', '429 {error:limite} con Retry-After: 3600');
[$c] = enviar(lead(), ['REMOTE_ADDR' => '192.0.2.11'], null, $AHORA + 10);
ok($c === 200, 'otro visitante sí puede');
[$c] = enviar(lead(), $ipFija, null, $AHORA + 3601);
ok($c === 200, 'pasada la hora, vuelve a poder');
ok(count(leads()) === 7, 'solo se guardaron los aceptados', (string) count(leads()));
[$c] = enviar(lead(['web' => 'robot']), ['REMOTE_ADDR' => '192.0.2.12']);
for ($i = 0; $i < 4; $i++) enviar(lead(['web' => 'robot']), ['REMOTE_ADDR' => '192.0.2.12']);
[$c] = enviar(lead(), ['REMOTE_ADDR' => '192.0.2.12']);
ok($c === 429, 'los intentos de robots también cuentan para el límite por visitante');
vaciar();
$cfgProxy = $CFG + ['ip_cabecera' => 'HTTP_CF_CONNECTING_IP'];
for ($i = 0; $i < 5; $i++) enviar(lead(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '192.0.2.20'], $cfgProxy);
[$c] = enviar(lead(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '192.0.2.21'], $cfgProxy);
ok($c === 200, 'con ip_cabecera configurada, cuenta la IP real y no la del proxy');
[$c] = enviar(lead(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '192.0.2.20']);
ok($c === 200, 'sin ip_cabecera configurada, esa cabecera se ignora (192.0.2.20 ya gastó sus 5)');

echo "\n8. Tope diario: 100 solicitudes guardadas\n";
vaciar();
$ultimo = 0;
for ($i = 0; $i < 100; $i++) $ultimo = enviar(lead(), ['REMOTE_ADDR' => '100.64.' . intdiv($i, 4) . '.' . ($i % 4 + 1)])[0];
ok($ultimo === 200 && count(leads()) === 100, '100 solicitudes de visitantes distintos: guardadas', (string) count(leads()));
[$c, $r, $h] = enviar(lead(), ['REMOTE_ADDR' => '100.65.0.1']);
ok($c === 429 && $r['error'] === 'limite' && (int) ($h['Retry-After'] ?? 0) > 0, 'la 101.ª: 429 limite con Retry-After');
ok(count(leads()) === 100, 'no se guarda la 101.ª');
[$c] = enviar(lead(['web' => 'robot']), ['REMOTE_ADDR' => '100.65.0.2']);
ok($c === 200, 'el campo trampa no consume el tope diario (responde como siempre)');
[$c] = enviar(lead(), ['REMOTE_ADDR' => '100.65.0.3'], null, $AHORA + 86400);
ok($c === 200, 'al día siguiente vuelve a aceptar');

echo "\n9. Cola segura y configuración\n";
vaciar();
$dentro = "$WEB/dl-leads";
mkdir($dentro, 0700);
[$c, $r] = enviar(lead(), otra_ip(), ['cola_dir' => $dentro] + $CFG);
ok($c === 503 && $r['error'] === 'sin_servicio' && !glob("$dentro/*"), 'cola dentro de public_html (web_dir): 503 y no escribe');
[$c] = enviar(lead(), otra_ip() + ['DOCUMENT_ROOT' => $WEB], ['cola_dir' => $dentro, 'web_dir' => ''] + $CFG);
ok($c === 503, 'cola dentro de DOCUMENT_ROOT: 503');
[$c] = enviar(lead(), otra_ip(), ['cola_dir' => "$WEB/../public_html/dl-leads"] + $CFG);
ok($c === 503, 'cola dentro de la web con ../ en la ruta: 503');
[$c] = enviar(lead(), otra_ip(), ['cola_dir' => $BASE] + $CFG);
ok($c === 503, 'cola que contiene la web: 503');
[$c] = enviar(lead(), otra_ip(), ['cola_dir' => "$BASE/no-existe"] + $CFG);
ok($c === 503, 'cola inexistente: 503');
[$c] = enviar(lead(), otra_ip(), ['sal' => 'corta'] + $CFG);
ok($c === 503, 'sal de menos de 32 caracteres: 503');
$sinSal = $CFG; unset($sinSal['sal']);
[$c] = enviar(lead(), otra_ip(), $sinSal);
ok($c === 503, 'sin sal: 503');
ok(!leads(), 'nada se guarda sin configuración segura');

echo "\n10. Caducidad: códigos de visitante, topes y solicitudes\n";
vaciar();
enviar(lead(), ['REMOTE_ADDR' => '192.0.2.30']);
$rl = glob("$COLA/limites/rl-*.json") ?: [];
ok(count($rl) === 1 && str_contains($rl[0], gmdate('Ymd', $AHORA)), 'el código del visitante lleva el día');
enviar(lead(), ['REMOTE_ADDR' => '192.0.2.31'], null, $AHORA + 3700);
ok(!file_exists($rl[0]), 'el código de un visitante se borra a la hora del último intento');
$otroDia = glob("$COLA/limites/rl-*.json") ?: [];
enviar(lead(), ['REMOTE_ADDR' => '192.0.2.31'], null, $AHORA + 86400 + 10);
$nuevos = glob("$COLA/limites/rl-*.json") ?: [];
ok(count($nuevos) === 1 && !in_array($nuevos[0], $otroDia, true), 'la misma IP al día siguiente da otro código y el anterior se borra (nunca más de 24 h)');
file_put_contents("$COLA/limites/cap-20200101.txt", '5');
$viejo = "$COLA/lead-20200101T000000Z-0000000000000000.json";
file_put_contents($viejo, '{}');
touch($viejo, $AHORA - 31 * 86400);
$reciente = "$COLA/lead-20200101T000000Z-1111111111111111.json";
file_put_contents($reciente, '{}');
touch($reciente, $AHORA - 29 * 86400);
file_put_contents("$COLA/.tmp-abandonado", 'x');
touch("$COLA/.tmp-abandonado", $AHORA - 7200);
demo_purgar($COLA, $AHORA);
ok(!file_exists($viejo) && file_exists($reciente), 'solicitudes: se borra la de 31 días, queda la de 29');
ok(!file_exists("$COLA/limites/cap-20200101.txt"), 'topes de días anteriores: se borran');
ok(!file_exists("$COLA/.tmp-abandonado"), 'temporales abandonados: se borran');

echo "\n" . ($fallos === 0 ? "TODO BIEN: $total pruebas" : "FALLAN $fallos de $total pruebas") . "\n";
exit($fallos === 0 ? 0 : 1);
