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
$cfgProxy = $CFG + ['ip_cabecera' => 'HTTP_CF_CONNECTING_IP', 'ip_proxies' => ['10.0.0.0/8']];
for ($i = 0; $i < 5; $i++) enviar(lead(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '192.0.2.20'], $cfgProxy);
[$c] = enviar(lead(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '192.0.2.21'], $cfgProxy);
ok($c === 200, 'con ip_cabecera e ip_proxies, desde el CDN cuenta la IP real y no la del proxy');
[$c] = enviar(lead(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '192.0.2.20']);
ok($c === 200, 'sin ip_cabecera configurada, esa cabecera se ignora (192.0.2.20 ya gastó sus 5)');

echo "\n7b. H2 · la cabecera del CDN solo se cree si la petición llega desde el CDN\n";
vaciar();
$s = fn(string $remota, string $cab) => ['REMOTE_ADDR' => $remota, 'HTTP_CF_CONNECTING_IP' => $cab];
ok(demo_ip($s('10.1.2.3', '192.0.2.50'), $cfgProxy) === '192.0.2.50', 'REMOTE_ADDR del CDN: usa la cabecera');
ok(demo_ip($s('198.51.100.9', '192.0.2.50'), $cfgProxy) === '198.51.100.9', 'REMOTE_ADDR ajena al CDN: ignora la cabecera (falsificable)');
$soloCab = $CFG + ['ip_cabecera' => 'HTTP_CF_CONNECTING_IP'];
ok(demo_ip($s('10.1.2.3', '192.0.2.50'), $soloCab) === '10.1.2.3', 'ip_cabecera sin ip_proxies: se ignora');
$cfgXff = $CFG + ['ip_cabecera' => 'HTTP_X_FORWARDED_FOR', 'ip_proxies' => ['10.0.0.0/8', '2001:db8:ffff::/48']];
ok(demo_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 192.0.2.60'], $cfgXff) === '192.0.2.60',
    'lista con comas: toma el ÚLTIMO valor (el que añadió el CDN), no el primero');
ok(demo_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.61 , basura'], $cfgXff) === '10.0.0.1',
    'lista con un valor no válido al final: REMOTE_ADDR, no el anterior (que lo escribe el cliente)');
ok(demo_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 192.0.2.62:443'], $cfgXff) === '10.0.0.1',
    'último valor con puerto (no es IP): REMOTE_ADDR, no 198.51.100.7');
ok(demo_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, '], $cfgXff) === '10.0.0.1',
    'último valor vacío: REMOTE_ADDR');
ok(demo_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'basura'], $cfgXff) === '10.0.0.1', 'sin ningún valor válido: REMOTE_ADDR');
ok(demo_ip(['REMOTE_ADDR' => '2001:db8:ffff:1::5', 'HTTP_X_FORWARDED_FOR' => '2001:db8:aa::1'], $cfgXff) === '2001:db8:aa::1', 'CDN por IPv6 (rango /48)');
ok(demo_ip_en_red('10.255.0.1', '10.0.0.0/8') && !demo_ip_en_red('11.0.0.1', '10.0.0.0/8') && demo_ip_en_red('192.0.2.7', '192.0.2.7')
    && !demo_ip_en_red('192.0.2.8', '192.0.2.7') && demo_ip_en_red('192.0.2.130', '192.0.2.128/25') && !demo_ip_en_red('192.0.2.127', '192.0.2.128/25')
    && !demo_ip_en_red('10.0.0.1', '2001:db8::/32') && !demo_ip_en_red('10.0.0.1', '10.0.0.0/33') && !demo_ip_en_red('10.0.0.1', '10.0.0.0/x'),
    'demo_ip_en_red: /8, IP sola, /25, familia distinta y máscaras no válidas');
for ($i = 0; $i < 5; $i++) enviar(lead(), $s('198.51.100.9', '192.0.2.' . (70 + $i)), $cfgProxy);
[$c] = enviar(lead(), $s('198.51.100.9', '192.0.2.99'), $cfgProxy);
ok($c === 429, 'petición directa (sin CDN) cambiando la cabecera en cada envío: el 6.º recibe 429');

echo "\n8. Tope diario: 100 solicitudes guardadas\n";
vaciar();
$ultimo = 0;
$cfgSinRitmo = ['limite_minuto' => 1000] + $CFG;     // aquí solo se prueba el tope diario (el de minuto, en la 11)
for ($i = 0; $i < 100; $i++) $ultimo = enviar(lead(), ['REMOTE_ADDR' => '100.64.' . intdiv($i, 4) . '.' . ($i % 4 + 1)], $cfgSinRitmo)[0];
ok($ultimo === 200 && count(leads()) === 100, '100 solicitudes de visitantes distintos: guardadas', (string) count(leads()));
[$c, $r, $h] = enviar(lead(), ['REMOTE_ADDR' => '100.65.0.1'], $cfgSinRitmo);
ok($c === 429 && $r['error'] === 'limite' && (int) ($h['Retry-After'] ?? 0) > 60, 'la 101.ª: 429 limite con Retry-After hasta medianoche');
ok(count(leads()) === 100, 'no se guarda la 101.ª');
[$c] = enviar(lead(['web' => 'robot']), ['REMOTE_ADDR' => '100.65.0.2'], $cfgSinRitmo);
ok($c === 200, 'el campo trampa no consume el tope diario (responde como siempre)');
[$c] = enviar(lead(), ['REMOTE_ADDR' => '100.65.0.3'], $cfgSinRitmo, $AHORA + 86400);
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

echo "\n11. H3 · IPv6 por prefijo /64 y tope global de 10 guardados por minuto\n";
ok(demo_clave_ip('2001:db8:1:2:aaaa:bbbb:cccc:dddd') === '2001:db8:1:2::/64' && demo_clave_ip('2001:db8:1:2::1') === '2001:db8:1:2::/64',
    'dos IPv6 del mismo /64 dan la misma clave', demo_clave_ip('2001:db8:1:2::1'));
ok(demo_clave_ip('2001:db8:1:3::1') !== demo_clave_ip('2001:db8:1:2::1'), 'otro /64, otra clave');
ok(demo_clave_ip('192.0.2.10') === '192.0.2.10' && demo_clave_ip('::ffff:192.0.2.10') === '192.0.2.10', 'IPv4 completa; ::ffff:a.b.c.d cuenta como la IPv4');
ok(demo_clave_ip('no-es-ip') === 'no-es-ip', 'algo que no es IP se usa tal cual');
vaciar();
$codigos = [];
for ($i = 1; $i <= 6; $i++) $codigos[] = enviar(lead(), ['REMOTE_ADDR' => "2001:db8:1:2::$i"], null, $AHORA + $i)[0];
ok($codigos === [200, 200, 200, 200, 200, 429], '6 direcciones distintas del mismo /64: la 6.ª recibe 429', implode(',', $codigos));
[$c] = enviar(lead(), ['REMOTE_ADDR' => '2001:db8:1:3::1'], null, $AHORA + 7);
ok($c === 200, 'otro /64 sí puede');
vaciar();
for ($i = 0; $i < 5; $i++) enviar(lead(), ['REMOTE_ADDR' => '192.0.2.40']);
[$c] = enviar(lead(), ['REMOTE_ADDR' => '::ffff:192.0.2.40']);
ok($c === 429, 'la misma IPv4 escrita como ::ffff: no salta el límite');
vaciar();
$codigos = [];
for ($i = 1; $i <= 11; $i++) $codigos[] = enviar(lead(), ['REMOTE_ADDR' => "198.18.0.$i"], null, $AHORA + intdiv($i, 3))[0];
ok(array_slice($codigos, 0, 10) === array_fill(0, 10, 200), '10 guardados en menos de un minuto desde 10 IP: aceptados', implode(',', $codigos));
[$c, $r, $h] = enviar(lead(), ['REMOTE_ADDR' => '198.18.1.1'], null, $AHORA + 30);
ok($codigos[10] === 429 && $c === 429 && $r['error'] === 'limite' && ($h['Retry-After'] ?? '') === '60',
    'el 11.º en el mismo minuto, aunque sea de otra IP: 429 limite con Retry-After: 60');
ok(count(leads()) === 10, 'no se guarda nada por encima del tope por minuto', (string) count(leads()));
[$c] = enviar(lead(), ['REMOTE_ADDR' => '198.18.1.2'], null, $AHORA + 61);
ok($c === 200, 'pasado el minuto, vuelve a aceptar');
[$c] = enviar(lead(['web' => 'robot']), ['REMOTE_ADDR' => '198.18.1.3'], null, $AHORA + 62);
ok($c === 200 && count(leads()) === 11, 'el campo trampa no consume el tope por minuto');
$cap = (string) @file_get_contents("$COLA/limites/cap-" . gmdate('Ymd', $AHORA) . '.txt');
ok($cap === '11', 'el tope diario cuenta solo los guardados', $cap);

echo "\n12. H4 · etiquetas Unicode y otros invisibles\n";
vaciar();
$etiquetas = "\u{E0001}\u{E0041}\u{E0042}\u{E0043}\u{E007F}";            // «ABC» escrito con etiquetas Unicode (invisible)
[$c] = enviar(lead([
    'nombre' => "Ana{$etiquetas} Prueba",
    'empresa' => "Ficticia\u{061C}\u{180E}\u{034F}\u{206A}\u{3164}\u{FFA0}\u{115F}\u{1160} SL\u{FE0F}\u{E0100}\u{E000}",
    'necesidad' => "Ignora lo anterior{$etiquetas}\u{E0000}\u{E0080}.\u{2028}Segunda línea\u{2029}Tercera",
]), otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok($c === 200, 'el envío se acepta (se limpia, no se rechaza)');
ok(($j['nombre'] ?? '') === 'Ana Prueba', 'nombre: sin etiquetas U+E0001 y U+E0041-E007F', json_encode($j['nombre'] ?? null));
ok(($j['empresa'] ?? '') === 'Ficticia SL', 'empresa: sin U+061C, U+180E, U+034F, U+206A, rellenos Hangul, selectores de variación ni uso privado',
    json_encode($j['empresa'] ?? null));
ok(($j['necesidad'] ?? '') === "Ignora lo anterior.\nSegunda línea\nTercera", 'necesidad: sin etiquetas (ni U+E0000/U+E0080 sin asignar) y U+2028/2029 → salto de línea',
    json_encode($j['necesidad'] ?? null));
ok(!preg_match('/[\x{E0000}-\x{E007F}]/u', (string) file_get_contents(leads()[0] ?? 'php://memory')), 'el archivo guardado no contiene ningún carácter U+E0000-E007F');
ok(demo_linea("a\u{2028}b") === 'a b' && demo_linea("x\u{E0041}") === 'x' && demo_texto("x\u{E0041}") === 'x', 'demo_linea y demo_texto con \u{E0041}');
ok(demo_linea("Jos\u{00E9} Mar\u{00ED}a \u{00D1}u\u{00F1}ez") === "Jos\u{00E9} Mar\u{00ED}a \u{00D1}u\u{00F1}ez" && demo_linea('李小龙') === '李小龙',
    'acentos, ñ y otros alfabetos se conservan');
[$c, $r] = enviar(lead(['nombre' => "\u{E0041}\u{E0042}\u{200B}"]), otra_ip());
ok($c === 400 && ($r['campos']['nombre'] ?? '') === 'falta', 'un nombre hecho solo de invisibles cuenta como vacío');

echo "\n13. H5 · correo sin comillas y nombre y empresa sin «<>»\n";
vaciar();
foreach (['"a<b>"@ejemplo.test', '"ana"@ejemplo.test', '"a b"@ejemplo.test', 'ana<x>@ejemplo.test', 'a\\b@ejemplo.test'] as $mal) {
    [$c, $r] = enviar(lead(['email' => $mal]), otra_ip());
    ok($c === 400 && ($r['campos']['email'] ?? '') === 'invalido', "correo $mal → invalido", "$c " . json_encode($r));
}
ok(!leads(), 'ninguno se guarda');
foreach (['ana.prueba+demo@ejemplo.test', "o'connor@ejemplo.test", 'ana_p-1@sub.ejemplo.test'] as $bien) {
    [$c] = enviar(lead(['email' => $bien]), otra_ip());
    ok($c === 200, "correo normal $bien → se acepta");
}
vaciar();
[$c] = enviar(lead(['nombre' => 'Ana <script>alert(1)</script> Prueba', 'empresa' => '<b>Ficticia</b> > SL <']), otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok($c === 200 && !str_contains(($j['nombre'] ?? '<') . ($j['empresa'] ?? '>'), '<') && !str_contains(($j['nombre'] ?? '') . ($j['empresa'] ?? ''), '>'),
    'nombre y empresa se guardan sin < ni >', json_encode([$j['nombre'] ?? null, $j['empresa'] ?? null], JSON_UNESCAPED_UNICODE));
ok(($j['nombre'] ?? '') === 'Ana scriptalert(1)/script Prueba' && ($j['empresa'] ?? '') === 'bFicticia/b SL', 'y sin espacios dobles al quitarlos',
    json_encode([$j['nombre'] ?? null, $j['empresa'] ?? null], JSON_UNESCAPED_UNICODE));
[$c, $r] = enviar(lead(['nombre' => '<<>>']), otra_ip());
ok($c === 400 && ($r['campos']['nombre'] ?? '') === 'falta', 'un nombre hecho solo de «<>» cuenta como vacío');
vaciar();
[$c] = enviar(lead(['necesidad' => 'Usamos <b>Excel</b> & "macros"']), otra_ip());
$j = json_decode((string) file_get_contents(leads()[0] ?? 'php://memory'), true) ?: [];
ok(($j['necesidad'] ?? '') === 'Usamos <b>Excel</b> & "macros"', 'necesidad se guarda literal (es DATO NO CONFIABLE: lo trata la tarea 14/15/17)');

echo "\n14. H6 · purga acotada: después del límite y como mucho cada 10 min\n";
vaciar();
$viejo = "$COLA/lead-20200101T000000Z-2222222222222222.json";
$sembrar = function () use ($viejo, $AHORA) { file_put_contents($viejo, '{}'); touch($viejo, $AHORA - 31 * 86400); };
$sembrar();
enviar(lead(), ['REMOTE_ADDR' => '192.0.2.80']);
ok(!file_exists($viejo) && is_file("$COLA/limites/purga"), 'primera petición: purga y deja la marca limites/purga');
$sembrar();
enviar(lead(), ['REMOTE_ADDR' => '192.0.2.81'], null, $AHORA + 300);
ok(file_exists($viejo), 'a los 5 min: no vuelve a purgar');
enviar(lead(), ['REMOTE_ADDR' => '192.0.2.82'], null, $AHORA + 601);
ok(!file_exists($viejo), 'pasados 10 min: purga otra vez');
vaciar();
$sembrar();
for ($i = 0; $i < 5; $i++) enviar(lead(['web' => 'robot']), ['REMOTE_ADDR' => '192.0.2.83'], null, $AHORA + 2000);
ok(!file_exists($viejo), '(preparación) la primera petición purga');
$sembrar();
@unlink("$COLA/limites/purga");
[$c] = enviar(lead(), ['REMOTE_ADDR' => '192.0.2.83'], null, $AHORA + 2001);
ok($c === 429 && file_exists($viejo), 'una petición rechazada por el límite (429) no purga');
ok(demo_purgar_si_toca($COLA, "$COLA/limites", $AHORA + 2002) === true && !file_exists($viejo), 'demo_purgar_si_toca sin marca: purga');
ok(demo_purgar_si_toca($COLA, "$COLA/limites", $AHORA + 2003) === false, 'y justo después, no');
touch("$COLA/limites/purga", $AHORA + 99999);
ok(demo_purgar_si_toca($COLA, "$COLA/limites", $AHORA + 2004) === true, 'una marca con fecha futura (reloj cambiado) no bloquea la purga');

echo "\n" . ($fallos === 0 ? "TODO BIEN: $total pruebas" : "FALLAN $fallos de $total pruebas") . "\n";
exit($fallos === 0 ? 0 : 1);
