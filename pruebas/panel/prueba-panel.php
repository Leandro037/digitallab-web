<?php
/*
 * Pruebas del panel (tarea 50): requisitos R1-R12 del 25 y lectura de leads.
 * Sin dependencias: PHP 8.1+ en línea de comandos.
 *
 *   php pruebas/panel/prueba-panel.php         (desde la raíz del repositorio)
 *
 * Trabaja solo en una carpeta temporal nueva con datos ficticios (correos .test, IP de documentación
 * RFC 5737 / RFC 3849) y la borra al terminar. No envía correos ni hace peticiones de red.
 * Sale con 0 si todo pasa y 1 si algo falla.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();                                            // las pruebas de sesión necesitan que no haya salida aún
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');   // los error_log esperados no ensucian la salida
define('DL_DEMO_PRUEBAS', 1);
define('DL_PANEL', 1);
$RAIZ = dirname(__DIR__, 2);
require $RAIZ . '/demo/demo.php';
require $RAIZ . '/panel/seguridad.php';
require $RAIZ . '/panel/leads-lib.php';

$fallos = 0;
$total = 0;
$lineas = [];
function ok(bool $cond, string $nombre, string $detalle = ''): void
{
    global $fallos, $total, $lineas;
    $total++;
    if ($cond) { $lineas[] = "  ok   $nombre"; return; }
    $fallos++;
    $lineas[] = "  FALLA $nombre" . ($detalle !== '' ? " · $detalle" : '');
}
function titulo(string $t): void { global $lineas; $lineas[] = "\n$t"; }

function borrar_arbol(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$d/$f";
        is_dir($p) && !is_link($p) ? borrar_arbol($p) : @unlink($p);
    }
    @rmdir($d);
}

$BASE = sys_get_temp_dir() . '/dl-panel-prueba-' . bin2hex(random_bytes(6));
$WEB = "$BASE/public_html";
$COLA = "$BASE/dl-leads";
mkdir($WEB, 0755, true);
mkdir($COLA, 0700, true);
register_shutdown_function(fn() => borrar_arbol($BASE));

const EMAIL = 'admin@ejemplo.test';
const PASS = 'clave-ficticia-de-prueba-Ñ-2026';
const SAL = 's4l-ficticia-de-pruebas-del-panel-0123456789';
$AHORA = 1793000000;                                   // 2026-10-26, fijo
// Para que las ~200 comprobaciones de login no tarden un minuto, argon2id con coste bajo (sigue siendo argon2id);
// el coste real (el de scripts/hash-contrasena.py) se prueba aparte con $HASH_REAL.
const ARGON_RAPIDO = ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1];
$HASH = password_hash(PASS, PASSWORD_ARGON2ID, ARGON_RAPIDO);
$HASH_REAL = password_hash(PASS, PASSWORD_ARGON2ID);

function cfg_prueba(array $extra = []): array
{
    global $HASH, $COLA, $WEB;
    $a = $extra + ['admin_email' => EMAIL, 'admin_password_hash' => $HASH, 'panel_sal' => SAL, 'panel_dir' => null,
        'origenes' => ['https://dl-dev.com', 'https://www.dl-dev.com'], 'panel_aviso_para' => null, 'panel_aviso_de' => null];
    $l = ($extra['_leads'] ?? []) + ['cola_dir' => $COLA];
    return ps_config_desde($a, $l, $WEB);
}
function srv(string $ip = '203.0.113.5', array $extra = []): array
{
    global $WEB;
    return $extra + ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://dl-dev.com', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => $ip, 'DOCUMENT_ROOT' => $WEB, 'CONTENT_TYPE' => 'application/json'];
}
$CORREOS = [];
function enviar_falso(string $para, string $asunto, string $cuerpo, string $de): bool
{
    global $CORREOS;
    $CORREOS[] = compact('para', 'asunto', 'cuerpo', 'de');
    return true;
}
// Un intento de login. Devuelve [código, cuerpo]
function login(array $c, array $server, int $ahora, string $email = EMAIL, string $pass = PASS, array $extra = [], ?callable $enviar = null): array
{
    $e = ps_estado($c, $server);
    $cuerpo = json_encode($extra + ['email' => $email, 'password' => $pass, 'web' => '']);
    return ps_login($server, (string) $cuerpo, $c, $e, $ahora, fn() => true, $enviar ?? 'enviar_falso', fn($ip) => 'ES');
}
function nuevo_panel(): array
{
    global $COLA;
    $d = "$COLA/panel-" . bin2hex(random_bytes(4));
    return cfg_prueba(['panel_dir' => $d]);
}
$CRED = [401, ['ok' => false, 'error' => 'credenciales']];

/* ================================================================== */
titulo('R3 · Sesión (primero: necesita que aún no haya salida)');
$c = nuevo_panel();
$e = ps_estado($c, srv());
ok(ps_sesion_abrir((string) $e['dir']), 'la sesión se abre en la carpeta privada');
$p = session_get_cookie_params();
ok(session_name() === '__Host-dlpanel', 'cookie con prefijo __Host-', session_name());
ok($p['secure'] === true && $p['httponly'] === true && $p['samesite'] === 'Strict' && $p['path'] === '/' && $p['domain'] === '' && $p['lifetime'] === 0,
    'cookie Secure, HttpOnly, SameSite=Strict, path=/, sin dominio, de sesión', json_encode($p));
$guardado = str_replace('\\', '/', session_save_path());
ok($guardado === str_replace('\\', '/', $e['dir']) . '/sesiones' && !str_starts_with(strtolower($guardado), strtolower(str_replace('\\', '/', realpath($WEB)))),
    'sesiones fuera de public_html (en <panel_dir>/sesiones)', $guardado);
ok(ini_get('session.use_strict_mode') === '1' && ini_get('session.use_only_cookies') === '1', 'modo estricto y solo cookies');
$antes = session_id();
session_write_close();
ok(ps_sesion_nueva($e, $AHORA), 'login correcto abre sesión nueva');
$despues = session_id();
ok($despues !== '' && $despues !== $antes, 'el id de sesión se regenera al entrar', "$antes → $despues");
$f = glob($e['dir'] . '/sesiones/sess_*');
$cont = $f ? (string) file_get_contents(end($f)) : '';
ok(str_contains($cont, 'admin') && preg_match('/csrf\|s:64:"[a-f0-9]{64}"/', $cont) === 1, 'la sesión guarda admin, horas y un token CSRF de 64 hex', $cont);
ok(!str_contains($cont, EMAIL) && !str_contains($cont, PASS), 'la sesión no guarda el correo ni la contraseña');
$s = ['admin' => 1, 'inicio' => $AHORA, 'ultimo' => $AHORA, 'csrf' => str_repeat('a', 64)];
ok(ps_sesion_valida($s, $AHORA + 1799), 'válida con 29 min 59 s de inactividad');
ok(!ps_sesion_valida($s, $AHORA + 1800), 'caduca a los 30 min de inactividad');
ok(ps_sesion_valida(['ultimo' => $AHORA + 28799 - 60] + $s, $AHORA + 28799), 'válida a las 7 h 59 min con actividad reciente');
ok(!ps_sesion_valida(['ultimo' => $AHORA + 28800 - 60] + $s, $AHORA + 28800), 'caduca a las 8 h aunque haya actividad');
ok(!ps_sesion_valida(['admin' => '1'] + $s, $AHORA) && !ps_sesion_valida(array_diff_key($s, ['csrf' => 1]), $AHORA) && !ps_sesion_valida(null, $AHORA),
    'sin admin===1, sin token o vacía: no vale');
ok(!ps_sesion_valida(['inicio' => $AHORA + 3600] + $s, $AHORA), 'inicio en el futuro: no vale');

// H1 (tarea 51): solo las acciones del usuario renuevan los 30 min; el sondeo automático no
$_COOKIE[PS_COOKIE] = $despues;
$archivoSes = $e['dir'] . '/sesiones/sess_' . $despues;
$s1 = ps_sesion_actual($e, $AHORA + 600, false);
ok(is_array($s1) && $s1['ultimo'] === $AHORA && str_contains((string) file_get_contents($archivoSes), 'ultimo|i:' . $AHORA . ';'),
    'H1: una llamada automática (sin X-DL-Accion) no renueva «ultimo» ni reescribe la sesión');
$s2 = ps_sesion_actual($e, $AHORA + 1200, true);
ok(is_array($s2) && $s2['ultimo'] === $AHORA + 1200 && str_contains((string) file_get_contents($archivoSes), 'ultimo|i:' . ($AHORA + 1200) . ';'),
    'H1: una acción del usuario sí renueva «ultimo»');
$vivas = 0;
for ($i = 1; $i <= 31; $i++) {                        // sondeo cada 60 s con la pestaña abierta
    if (ps_sesion_actual($e, $AHORA + 1200 + 60 * $i, false) === null) break;
    $vivas = $i;
}
ok($vivas === 29, 'H1: con el sondeo activo cada 60 s, la sesión caduca a los 30 min de la última acción del usuario', "sondeos con sesión: $vivas");
ok(!is_file($archivoSes), 'H1: la sesión caducada se destruye');
ok(ps_es_accion_usuario(['HTTP_X_DL_ACCION' => '1']) && !ps_es_accion_usuario([]) && !ps_es_accion_usuario(['HTTP_X_DL_ACCION' => 'true']) && !ps_es_accion_usuario(['HTTP_X_DL_ACCION' => ['1']]),
    'H1: solo «X-DL-Accion: 1» cuenta como acción del usuario');
$jsPanel = (string) file_get_contents("$RAIZ/panel/panel.js");
ok(str_contains($jsPanel, "cargar(true); }, 60000)") && str_contains($jsPanel, "auto === true ? {} : { 'X-DL-Accion': '1' }"),
    'H1: el sondeo de panel.js va sin X-DL-Accion; las demás cargas, con ella');
ok(str_contains((string) file_get_contents("$RAIZ/panel/lib.php"), 'ps_sesion_actual($e, time(), ps_es_accion_usuario($_SERVER))')
    && str_contains((string) file_get_contents("$RAIZ/panel/leads-api.php"), 'ps_es_accion_usuario($_SERVER)')
    && str_contains((string) file_get_contents("$RAIZ/panel/contrasena-cambiar.php"), 'ps_sesion_actual($e, $ahora, ps_es_accion_usuario($_SERVER))'),
    'H1: api.php, leads-api.php y contrasena-cambiar.php solo renuevan con X-DL-Accion');
$jsLeads = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents("$RAIZ/panel/leads.js"));
ok(!preg_match('/setInterval|setTimeout\(\s*cargar/', $jsLeads) && substr_count($jsLeads, 'fetch(') === 2,
    'H1: leads.js no sondea (sus dos fetch son acciones del usuario: api() y el CSV)');
unset($_COOKIE[PS_COOKIE]);

/* ================================================================== */
titulo('R1 · Contraseña');
ok(ps_hash_valido($HASH_REAL) && password_get_info($HASH_REAL)['algo'] === 'argon2id', 'argon2id (coste por defecto) se acepta');
$cReal = cfg_prueba(['admin_password_hash' => $HASH_REAL, 'panel_dir' => "$COLA/preal"]);
ok(login($cReal, srv('203.0.113.9'), $AHORA)[0] === 200, 'login con el hash argon2id de coste real');
ok(ps_hash_valido(password_hash('x', PASSWORD_BCRYPT, ['cost' => 12])), 'bcrypt coste 12 se acepta');
ok(!ps_hash_valido(password_hash('x', PASSWORD_BCRYPT, ['cost' => 10])), 'bcrypt coste 10 se rechaza (el hash actual del login)');
ok(!ps_hash_valido(md5('x')) && !ps_hash_valido('') && !ps_hash_valido('$1$abc$def'), 'md5, vacío y crypt antiguo se rechazan');
$c = nuevo_panel();
ok(login($c, srv('203.0.113.10'), $AHORA) === [200, ['ok' => true, 'url' => '/panel/leads.php']], 'credenciales correctas → 200');
ok(login($c, srv('203.0.113.11'), $AHORA, strtoupper(EMAIL))[0] === 200, 'el correo no distingue mayúsculas');
$corta = 'trece-letras!';                                         // 13 caracteres
$c13 = cfg_prueba(['admin_password_hash' => password_hash($corta, PASSWORD_ARGON2ID, ARGON_RAPIDO), 'panel_dir' => "$COLA/p13"]);
ok(login($c13, srv('203.0.113.12'), $AHORA, EMAIL, $corta) === $CRED, 'contraseña de 13 caracteres: rechazada aunque el hash coincida');
$larga = str_repeat('ñ', 513);                                   // 1026 bytes
$cL = cfg_prueba(['admin_password_hash' => password_hash($larga, PASSWORD_ARGON2ID, ARGON_RAPIDO), 'panel_dir' => "$COLA/plarga"]);
ok(login($cL, srv('203.0.113.13'), $AHORA, EMAIL, $larga) === $CRED, 'contraseña de más de 1024 bytes: rechazada aunque el hash coincida');
$justa = str_repeat('a', 1023) . 'b';                              // 1024 bytes
$cJ = cfg_prueba(['admin_password_hash' => password_hash($justa, PASSWORD_ARGON2ID, ARGON_RAPIDO), 'panel_dir' => "$COLA/pjusta"]);
ok(login($cJ, srv('203.0.113.14'), $AHORA, EMAIL, $justa)[0] === 200, 'contraseña de 1024 bytes exactos: aceptada');
$e = ps_estado($c, srv());
ok(ps_login(srv('203.0.113.15'), str_repeat(' ', 5000), $c, $e, $AHORA, fn() => true, 'enviar_falso') === $CRED, 'cuerpo de más de 4 KB: credenciales');

/* ================================================================== */
titulo('R2 · Bloqueo progresivo y respuesta única');
$c = nuevo_panel();
$ip = '198.51.100.20';
for ($i = 0; $i < 4; $i++) login($c, srv($ip), $AHORA + $i, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv($ip), $AHORA + 10)[0] === 200, '4 fallos: aún entra');
for ($i = 0; $i < 5; $i++) $r = login($c, srv($ip), $AHORA + 20 + $i, EMAIL, 'mala-pero-larga-123');
ok($r === $CRED, 'el 5.º fallo responde igual («credenciales»)');
$t0 = $AHORA + 24;
ok(login($c, srv($ip), $t0 + 1) === $CRED, 'bloqueado: la contraseña correcta recibe «credenciales»');
ok(login($c, srv($ip), $t0 + 899) === $CRED, 'sigue bloqueado a los 14 min 59 s');
ok(login($c, srv($ip), $t0 + 900)[0] === 200, 'a los 15 min vuelve a entrar');
ok(login($c, srv('198.51.100.21'), $t0 + 1)[0] === 200, 'el bloqueo es por visitante: otra IP entra');

// Duplicación hasta 24 h
$c = nuevo_panel();
$ip = '198.51.100.30';
$t = $AHORA; $duraciones = [];
for ($ciclo = 0; $ciclo < 9; $ciclo++) {
    for ($i = 0; $i < 5; $i++) login($c, srv($ip), $t, EMAIL, 'mala-pero-larga-123');
    $d = json_decode((string) file_get_contents($c['dir'] . '/bloqueo/v-' . ps_visitante(srv($ip), $c) . '.json'), true);
    $duraciones[] = $d['hasta'] - $t;
    $t = $d['hasta'] + 1;
}
ok($duraciones === [900, 1800, 3600, 7200, 14400, 28800, 57600, 86400, 86400], 'bloqueos de 15 min, duplicando, con tope de 24 h', implode(',', $duraciones));
$t += 86400 + 10;                                                  // un día sin bloqueos
for ($i = 0; $i < 5; $i++) login($c, srv($ip), $t, EMAIL, 'mala-pero-larga-123');
$d = json_decode((string) file_get_contents($c['dir'] . '/bloqueo/v-' . ps_visitante(srv($ip), $c) . '.json'), true);
ok($d['hasta'] - $t === 900, 'tras 24 h sin bloqueos vuelve a 15 min', (string) ($d['hasta'] - $t));
ok(login($c, srv($ip), $t + 100, EMAIL, PASS) === $CRED && login($c, srv($ip), $t + 900)[0] === 200, 'el bloqueo nuevo se respeta y termina');

// Fallos fuera de la ventana de 15 min no suman
$c = nuevo_panel();
$ip = '198.51.100.40';
for ($i = 0; $i < 4; $i++) login($c, srv($ip), $AHORA + $i, EMAIL, 'mala-pero-larga-123');
login($c, srv($ip), $AHORA + 901, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv($ip), $AHORA + 902)[0] === 200, '5 fallos repartidos en más de 15 min: no bloquea');

// IPv6 por /64
$c = nuevo_panel();
for ($i = 1; $i <= 5; $i++) login($c, srv("2001:db8:1:2::$i"), $AHORA, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv('2001:db8:1:2:ffff::99'), $AHORA + 1) === $CRED, 'IPv6: 5 direcciones del mismo /64 bloquean el /64 entero');
ok(login($c, srv('2001:db8:1:3::1'), $AHORA + 1)[0] === 200, 'IPv6: otro /64 no queda bloqueado');

// IP real tras CDN (misma función que /demo)
$cdn = ['_leads' => ['ip_cabecera' => 'HTTP_X_FORWARDED_FOR', 'ip_proxies' => ['10.0.0.0/8']]];
$c = cfg_prueba($cdn + ['panel_dir' => "$COLA/pcdn"]);
for ($i = 0; $i < 5; $i++) login($c, srv('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '192.0.2.50']), $AHORA, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv('10.0.0.2', ['HTTP_X_FORWARDED_FOR' => '192.0.2.50']), $AHORA + 1) === $CRED, 'tras el CDN cuenta la IP real de la cabecera');
ok(login($c, srv('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '192.0.2.51']), $AHORA + 1)[0] === 200, 'otro visitante tras el mismo CDN entra');
for ($i = 0; $i < 5; $i++) login($c, srv('198.51.100.60', ['HTTP_X_FORWARDED_FOR' => "192.0.2.10$i"]), $AHORA, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv('198.51.100.60', ['HTTP_X_FORWARDED_FOR' => '192.0.2.199']), $AHORA + 1) === $CRED, 'cabecera falsa desde fuera del CDN: cuenta REMOTE_ADDR y bloquea');
ok(ps_visitante(srv('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '192.0.2.50']), $c) === ps_visitante(srv('192.0.2.50'), $c), 'el código del visitante es el de la IP real');

// Global
$c = nuevo_panel();
$CORREOS = [];
for ($i = 0; $i < 29; $i++) login($c, srv("198.18.0.$i"), $AHORA + $i, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv('198.18.1.1'), $AHORA + 40)[0] === 200, '29 fallos globales en una hora: aún se entra');
$CORREOS = [];
login($c, srv('198.18.0.250'), $AHORA + 41, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv('198.18.2.2'), $AHORA + 42) === $CRED, '30 fallos de IP distintas en una hora: bloqueo global');
ok(login($c, srv('198.18.2.3'), $AHORA + 41 + 899) === $CRED, 'el bloqueo global dura 15 min');
ok(login($c, srv('198.18.2.4'), $AHORA + 41 + 900)[0] === 200, 'a los 15 min se vuelve a entrar');
$glob = array_values(array_filter($CORREOS, fn($m) => str_contains($m['asunto'], 'Bloqueo global')));
ok(count($glob) === 1, 'el bloqueo global avisa por correo una vez', (string) count($glob));

// Sin contador → se niega
$c = nuevo_panel();
$e = ps_estado($c, srv());
file_put_contents($e['dir'] . '/bloqueo', 'no soy una carpeta');
ok(login($c, srv('203.0.113.70'), $AHORA) === [503, ['ok' => false, 'error' => 'sin_servicio']], 'sin contador de intentos: login negado (503) aunque la contraseña sea buena');

// Respuesta única
$c = nuevo_panel();
$e = ps_estado($c, srv());
$resp = [
    login($c, srv('203.0.113.80'), $AHORA, 'otro@ejemplo.test'),
    login($c, srv('203.0.113.81'), $AHORA, EMAIL, 'mala-pero-larga-123'),
    login($c, srv('203.0.113.82'), $AHORA, EMAIL, PASS, ['web' => 'robot']),
    login($c, srv('203.0.113.83'), $AHORA, EMAIL, 'corta'),
    ps_login(srv('203.0.113.84'), 'no es json', $c, $e, $AHORA, fn() => true, 'enviar_falso'),
    ps_login(srv('203.0.113.85'), '{"email":["x"],"password":1}', $c, $e, $AHORA, fn() => true, 'enviar_falso'),
];
ok(count(array_unique(array_map('json_encode', $resp))) === 1 && $resp[0] === $CRED,
    'correo malo, contraseña mala, campo trampa, contraseña corta, JSON roto y tipos raros: misma respuesta 401 «credenciales»');

/* ================================================================== */
titulo('H2 (tarea 51) · El intento se reserva bajo flock antes de password_verify');
$c = nuevo_panel();
$ip = '198.51.100.70';
$fv = fn() => json_decode((string) file_get_contents($c['dir'] . '/bloqueo/v-' . ps_visitante(srv($ip), $c) . '.json'), true);
for ($i = 0; $i < 4; $i++) login($c, srv($ip), $AHORA + $i, EMAIL, 'mala-pero-larga-123');
ok(login($c, srv($ip), $AHORA + 5)[0] === 200, 'el 5.º intento con la contraseña buena entra (su reserva no lo bloquea)');
ok((int) ($fv()['hasta'] ?? 0) === 0 && $fv()['fallos'] === [] && (int) ($fv()['nivel'] ?? 0) === 0, 'al acertar se anula la reserva: sin bloqueo, sin fallos y sin subir de nivel', json_encode($fv()));
$g = json_decode((string) file_get_contents($c['dir'] . '/bloqueo/global.json'), true);
ok(count($g['fallos']) === 4 && (int) ($g['hasta'] ?? 0) === 0, 'el acierto no cuenta como fallo global', json_encode($g));
$r = ps_reservar_intento($c['dir'], ps_visitante(srv('198.51.100.71'), $c), $AHORA);
ok(is_array($r) && $r['estado'] === 'reservado' && $r['visitante'] === 0 && $r['global_nuevo'] === false, 'ps_reservar_intento anota el intento antes de comprobar nada');
ok(json_decode((string) file_get_contents($c['dir'] . '/bloqueo/v-' . ps_visitante(srv('198.51.100.71'), $c) . '.json'), true)['fallos'] === [$AHORA], 'la reserva queda escrita en el contador del visitante');

// Concurrencia real: N procesos PHP a la vez contra la misma carpeta (el hallazgo del 25: 20 de 20 verificados)
$HIJO = "$BASE/hijo-login.php";
file_put_contents($HIJO, <<<'PHP'
<?php
declare(strict_types=1);
define('DL_DEMO_PRUEBAS', 1);
define('DL_PANEL', 1);
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
require $argv[1] . '/demo/demo.php';
require $argv[1] . '/panel/seguridad.php';
$d = json_decode((string) file_get_contents($argv[2]), true);
$c = ps_config_desde($d['a'], $d['l'], $d['web']);
$e = ps_estado($c, $d['server']);
$cuerpo = (string) json_encode(['email' => $d['email'], 'password' => $d['pass'], 'web' => '']);
while (!is_file($d['salida'])) usleep(1000);          // todos salen a la vez
[$cod] = ps_login($d['server'], $cuerpo, $c, $e, (int) $d['ahora'], fn() => true, fn() => true, fn($ip) => 'ES');
echo $cod;
PHP);
// Hash más lento que el de las demás pruebas, para que la ventana de carrera sea real
$HASH_LENTO = password_hash(PASS, PASSWORD_ARGON2ID, ['memory_cost' => 16384, 'time_cost' => 6, 'threads' => 1]);
function en_paralelo(array $servidores, array $a, string $pass): array
{
    global $BASE, $RAIZ, $COLA, $WEB, $AHORA, $HIJO;
    $go = "$BASE/go-" . bin2hex(random_bytes(4));
    $procs = [];
    foreach ($servidores as $i => $sv) {
        $f = "$BASE/datos-$i-" . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($f, json_encode(['a' => $a, 'l' => ['cola_dir' => $COLA], 'web' => $WEB, 'server' => $sv, 'email' => EMAIL, 'pass' => $pass, 'ahora' => $AHORA, 'salida' => $go]));
        $p = proc_open([PHP_BINARY, $HIJO, $RAIZ, $f], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        $procs[] = [$p, $tubos];
    }
    usleep(800000);                                    // que todos estén esperando
    touch($go);
    $codigos = [];
    foreach ($procs as [$p, $tubos]) {
        $codigos[] = trim((string) stream_get_contents($tubos[1]));
        fclose($tubos[1]); fclose($tubos[2]);
        proc_close($p);
    }
    return $codigos;
}
function lineas_registro(string $dir): array
{
    $t = implode('', array_map('file_get_contents', glob("$dir/registro/acceso-*.log") ?: []));
    return array_map(fn($x) => json_decode($x, true), array_values(array_filter(explode("\n", $t))));
}
$dirC = "$COLA/pconc-" . bin2hex(random_bytes(4));
$aConc = ['admin_email' => EMAIL, 'admin_password_hash' => $HASH_LENTO, 'panel_sal' => SAL, 'panel_dir' => $dirC, 'origenes' => ['https://dl-dev.com'], 'panel_aviso_para' => '', 'panel_aviso_de' => null];
$cod = en_paralelo(array_fill(0, 20, srv('198.51.100.80')), $aConc, 'mala-pero-larga-123');
$reg = array_filter(lineas_registro($dirC), fn($x) => $x['accion'] === 'login');
$porR = array_count_values(array_column($reg, 'r'));
ok(count($cod) === 20 && array_unique($cod) === ['401'], '20 intentos simultáneos de un visitante: todos 401', implode(',', $cod));
ok(($porR['fallo'] ?? 0) === 4 && ($porR['bloqueado'] ?? 0) === 16, 'de 20 simultáneos solo 5 llegan a comprobar la contraseña (4 fallos + el que bloquea); 15 se cortan antes', json_encode($porR));
$cConc = ps_config_desde($aConc, ['cola_dir' => $COLA], $WEB);
$vC = json_decode((string) file_get_contents("$dirC/bloqueo/v-" . ps_visitante(srv('198.51.100.80'), $cConc) . '.json'), true);
ok((int) $vC['nivel'] === 0 && (int) $vC['hasta'] === $AHORA + 900, 'un solo bloqueo de 15 min (sin subir de nivel por la carrera)', json_encode($vC));

$dirG = "$COLA/pconcg-" . bin2hex(random_bytes(4));
$cod = en_paralelo(array_map(fn($i) => srv("198.18.9.$i"), range(1, 36)), ['panel_dir' => $dirG] + $aConc, 'mala-pero-larga-123');
$regG = lineas_registro($dirG);
$porRG = array_count_values(array_map(fn($x) => $x['accion'] . ':' . $x['r'], $regG));
ok(($porRG['login:fallo'] ?? 0) === 30 && ($porRG['login:bloqueado'] ?? 0) === 6 && ($porRG['bloqueo_global:bloqueado'] ?? 0) === 1,
    '36 intentos simultáneos de IP distintas: 30 se comprueban, 6 se cortan y el bloqueo global salta una vez', json_encode($porRG));

/* ================================================================== */
titulo('R4 · Sin aviso por correo en cada inicio de sesión (decisión del propietario); sí en bloqueo global y cambio de contraseña');
$c = nuevo_panel();
$CORREOS = [];
login($c, srv('203.0.113.90'), $AHORA);
ok($CORREOS === [], 'un login correcto ya NO envía correo');
$CORREOS = [];
login($c, srv('203.0.113.91'), $AHORA, EMAIL, 'mala-pero-larga-123');
ok($CORREOS === [], 'un fallo suelto no envía aviso');
ok(login($c, srv('203.0.113.92'), $AHORA, EMAIL, PASS, [], fn() => throw new RuntimeException('smtp caído'))[0] === 200, 'el login no depende del correo (aunque lance un error)');
ok(login($c, srv('203.0.113.93'), $AHORA, EMAIL, PASS, [], fn() => false)[0] === 200, 'el login no depende del correo (aunque devuelva false)');
$reg90 = array_filter(lineas_registro($c['dir']), fn($x) => $x['accion'] === 'login' && $x['r'] === 'ok');
ok(count($reg90) >= 1, 'el inicio de sesión correcto sigue quedando en el registro de accesos');
$cSin = cfg_prueba(['panel_aviso_para' => '', 'panel_dir' => "$COLA/psin"]);
$CORREOS = [];
ok(login($cSin, srv('203.0.113.94'), $AHORA)[0] === 200 && $CORREOS === [], 'sin buzón configurado: se entra, sin aviso (R12)');
[$as, $cu] = ps_aviso_texto('bloqueo_global', str_repeat('ab', 16), '??', $AHORA);
ok(str_contains($as, 'Bloqueo global') && str_contains($cu, 'País aproximado: desconocido'), 'aviso de bloqueo global con país desconocido');

/* ================================================================== */
titulo('R5 · Cabeceras de toda respuesta');
$cab = ps_cabeceras_lista();
ok($cab['Cache-Control'] === 'no-store' && $cab['X-Content-Type-Options'] === 'nosniff' && $cab['Referrer-Policy'] === 'no-referrer', 'no-store, nosniff y no-referrer');
$csp = $cab['Content-Security-Policy'];
preg_match("/script-src ([^;]+)/", $csp, $sm);
ok(str_contains($csp, "frame-ancestors 'none'") && isset($sm[1]) && trim($sm[1]) === "'self'" && !str_contains($csp, 'unsafe'), 'CSP con frame-ancestors none y scripts solo propios, sin unsafe-*', $csp);
$entradas = ['panel/leads.php', 'panel/leads-api.php', 'panel/leads-csv.php', 'panel/api.php', 'panel/salir.php', 'login/entrar.php', 'panel/contrasena.php', 'panel/contrasena-cambiar.php'];
foreach ($entradas as $f) {
    $src = (string) file_get_contents("$RAIZ/$f");
    $pos = strpos($src, 'ps_cabeceras()');
    ok($pos !== false && !preg_match('/\b(echo|print|header\(\'Location)/', substr($src, 0, $pos)), "$f envía las cabeceras antes de cualquier salida");
}
ok(preg_match("/ps_cabeceras\(\);\s+if \(!panel_autorizado\(\)\) json_out\(\['ok' => false, 'error' => 'sin_sesion'\], 401\);/", (string) file_get_contents("$RAIZ/panel/lib.php")) === 1,
    'el 401 de las estadísticas también lleva las cabeceras');
foreach (['panel/.htaccess', 'login/.htaccess'] as $f) {
    $h = (string) file_get_contents("$RAIZ/$f");
    ok(str_contains($h, 'Cache-Control "no-store"') && str_contains($h, 'Referrer-Policy "no-referrer"') && str_contains($h, "frame-ancestors 'none'") && str_contains($h, "script-src 'self';") && str_contains($h, 'nosniff'),
        "$f: cabeceras R5 también para los archivos estáticos");
}
$s = ['admin' => 1, 'inicio' => $AHORA, 'ultimo' => $AHORA, 'csrf' => str_repeat('c', 64)];
[$cod, $hd, $cuerpoCsv] = leads_csv_procesar(srv(), ['csrf' => $s['csrf']], $COLA, "$COLA/pcsv", cfg_prueba(), $s, str_repeat('a', 32), $AHORA);
ok($cod === 200 && $hd['Content-Disposition'] === 'attachment; filename="leads-dl-dev.csv"' && str_starts_with($hd['Content-Type'], 'text/csv'), 'CSV como adjunto con nombre fijo');

/* ================================================================== */
titulo('R6 · CSRF, Origin y Sec-Fetch-Site');
$c = cfg_prueba();
$tok = str_repeat('d', 64);
$ses = ['csrf' => $tok];
ok(ps_csrf_ok(srv(), $c, $tok, $ses), 'POST + origen propio + token correcto: pasa');
ok(!ps_csrf_ok(srv('203.0.113.5', ['REQUEST_METHOD' => 'GET']), $c, $tok, $ses), 'GET: no');
$sinOrigen = srv(); unset($sinOrigen['HTTP_ORIGIN']);
ok(!ps_csrf_ok($sinOrigen, $c, $tok, $ses), 'sin Origin: no');
ok(!ps_csrf_ok(srv('203.0.113.5', ['HTTP_ORIGIN' => 'https://dl-dev.com.evil.test']), $c, $tok, $ses), 'Origin ajeno: no');
ok(!ps_csrf_ok(srv('203.0.113.5', ['HTTP_ORIGIN' => 'null']), $c, $tok, $ses), 'Origin «null»: no');
ok(!ps_csrf_ok(srv('203.0.113.5', ['HTTP_SEC_FETCH_SITE' => 'cross-site']), $c, $tok, $ses), 'Sec-Fetch-Site: cross-site: no');
ok(!ps_csrf_ok(srv(), $c, str_repeat('e', 64), $ses) && !ps_csrf_ok(srv(), $c, [$tok], $ses) && !ps_csrf_ok(srv(), $c, null, $ses), 'token distinto, en lista o ausente: no');
ok(!ps_csrf_ok(srv(), $c, '', ['csrf' => '']), 'sesión sin token: no');
ok(str_contains((string) file_get_contents("$RAIZ/panel/seguridad.php"), 'hash_equals($esperado, $token)'), 'la comparación es con hash_equals');
$e = ps_estado($c, srv());
ok(ps_login($sinOrigen, '{}', $c, $e, $AHORA, fn() => true, 'enviar_falso')[0] === 403, 'login sin Origin: 403');
ok(ps_login(srv('203.0.113.5', ['HTTP_SEC_FETCH_SITE' => 'cross-site']), '{}', $c, $e, $AHORA, fn() => true, 'enviar_falso')[0] === 403, 'login desde otro sitio: 403');
ok(ps_login(srv('203.0.113.5', ['REQUEST_METHOD' => 'GET']), '', $c, $e, $AHORA, fn() => true, 'enviar_falso')[0] === 405, 'login por GET: 405');

/* ================================================================== */
titulo('Lectura de leads (datos ficticios con el mismo código que /demo)');
$base = ['nombre' => 'Ana Prueba', 'email' => 'ana@ejemplo.test', 'empresa' => 'Empresa Ficticia SL', 'pais' => 'España', 'tamano_equipo' => '2-10',
    'usa_claude_code' => 'si', 'necesidad' => "Automatizar informes.\n<script>alert(1)</script>", 'cta_origen' => 'precio', 'consent_respuesta' => true,
    'consent_novedades' => false, 'consent_version' => '34-2026-10-28', 'web' => '', 't' => 5000];
foreach ([0 => [], 3600 => ['nombre' => 'Bruno Ficticio', 'pais' => '', 'utm_source' => 'instagram', 'utm_campaign' => 'lanzamiento-oct', 'utm_content' => 'reel-1'],
          7200 => ['nombre' => 'Carla Ejemplo', 'email' => 'carla@ejemplo.test', 'consent_novedades' => true]] as $dt => $cambios) {
    [$lead, $err] = demo_validar($cambios + $base);
    $fecha = gmdate('Y-m-d\TH:i:s\Z', $AHORA - 86400 * 2 + $dt);
    $lead = ['formato' => 1, 'id' => '', 'recibido' => $fecha] + $lead + ['consent_respuesta' => true, 'consent_novedades' => ($cambios + $base)['consent_novedades'], 'consent_version' => '34-2026-10-28', 'consent_fecha' => $fecha];
    demo_escribir_lead($COLA, $lead, $AHORA - 86400 * 2 + $dt);
}
$lista = leads_listar($COLA, $AHORA);
ok(count($lista) === 3, 'lee los 3 leads de la cola', (string) count($lista));
ok(array_column($lista, 'nombre') === ['Carla Ejemplo', 'Bruno Ficticio', 'Ana Prueba'], 'del más reciente al más antiguo');
$ana = $lista[2];
ok($ana['caduca'] === gmdate('Y-m-d\TH:i:s\Z', strtotime($ana['recibido']) + 30 * 86400), 'caducidad = recibido + 30 días', $ana['caduca']);
ok($ana['email_valido'] === true && $ana['pais'] === 'España' && $ana['usa_claude_code'] === 'si' && $ana['consent_respuesta'] === true && $ana['consent_novedades'] === false,
    'campos y consentimientos como los guardó /demo');
ok(str_contains($ana['necesidad'], '<script>alert(1)</script>'), 'la necesidad llega como texto tal cual (la pinta textContent)');
ok($lista[1]['utm_campaign'] === 'lanzamiento-oct' && $lista[1]['pais'] === '', 'UTM y país vacío');
ok(LEADS_PAISES === DEMO_PAISES && LEADS_TAMANOS === DEMO_TAMANOS && LEADS_CLAUDE_CODE === DEMO_CLAUDE_CODE && LEADS_CTA === DEMO_CTA && LEADS_UTM === DEMO_UTM,
    'las listas cerradas coinciden con las de demo.php');
$res = leads_resumen($ana, []);
ok(!isset($res['necesidad']) && !isset($res['consent_version']) && $res['atendido'] === '', 'la lista no lleva la necesidad ni los consentimientos');

/* ================================================================== */
titulo('R9 · Ids y rutas');
$ID = $ana['id'];
ok(preg_match(PS_ID_LEAD, $ID) === 1, 'el id de /demo cumple el patrón', $ID);
ok(leads_buscar($lista, $ID)['nombre'] === 'Ana Prueba', 'busca por comparación en el listado');
foreach (['../../etc/passwd', $ID . "\n", strtoupper($ID), 'L-20261026-000000-' . str_repeat('g', 16), $ID . '.json', 'lead-x'] as $malo) {
    ok(leads_buscar($lista, $malo) === null, 'id rechazado: ' . json_encode($malo));
}
ok(leads_buscar($lista, [$ID]) === null && leads_buscar($lista, 123) === null, 'id que no es texto: rechazado');
// Archivos raros en la cola: se ignoran
file_put_contents("$COLA/lead-malicioso.json", json_encode(['id' => 'L-20261026-000000-aaaaaaaaaaaaaaaa', 'recibido' => '2026-10-26T00:00:00Z']));
file_put_contents("$COLA/lead-20261026T000000Z-bbbbbbbbbbbbbbbb.json", json_encode(['id' => 'L-20261026-000000-cccccccccccccccc', 'recibido' => '2026-10-26T00:00:00Z']));
file_put_contents("$COLA/lead-20261026T000001Z-dddddddddddddddd.json", str_repeat('x', 70000));
file_put_contents("$COLA/lead-20261026T000002Z-eeeeeeeeeeeeeeee.json", '{"id":"L-20261026-000002-eeeeeeeeeeeeeeee","recibido":"ayer"}');
ok(count(leads_listar($COLA, $AHORA)) === 3, 'nombre fuera de patrón, id que no coincide con el archivo, archivo enorme y fecha mala: ignorados');
$s = ['admin' => 1, 'inicio' => $AHORA, 'ultimo' => $AHORA, 'csrf' => str_repeat('f', 64)];
$V = str_repeat('a', 32); $DIRP = "$COLA/papi"; mkdir($DIRP, 0700);
$g = fn(array $q) => leads_api(['REQUEST_METHOD' => 'GET'], $q, '', $COLA, $DIRP, cfg_prueba(), $s, $V, $AHORA);
ok($g(['id' => '../../etc/passwd'])[0] === 400 && $g(['id' => ['x']])[0] === 400, 'API: id con ruta o en lista → 400');
ok($g(['id' => 'L-20261026-000000-aaaaaaaaaaaaaaaa'])[0] === 404, 'API: id con buen formato que no está en el listado → 404');
[$cod, $d] = $g(['id' => $ID]);
ok($cod === 200 && $d['lead']['id'] === $ID && $d['lead']['necesidad'] !== '', 'API: ficha completa por id');
[$cod, $d] = $g(['pais' => '<script>', 'estado' => '../x', 'origen' => str_repeat('z', 9999)]);
ok($cod === 200 && count($d['leads']) === 3, 'API: no acepta filtros del navegador (se filtra en la página con listas cerradas); los ignora');

/* ================================================================== */
titulo('R10 · «Atendido» aparte, sin tocar el lead');
$f = glob("$COLA/lead-*" . substr($ID, -16) . '.json')[0];
$antes = hash_file('sha256', $f); $mt = filemtime($f);
$post = fn(array $b, array $sv = []) => leads_api(srv('203.0.113.5', $sv), [], (string) json_encode($b), $COLA, $DIRP, cfg_prueba(), $s, $V, $AHORA);
[$cod, $d] = $post(['accion' => 'atendido', 'id' => $ID, 'atendido' => true, 'csrf' => $s['csrf']]);
ok($cod === 200 && $d['atendido'] === gmdate('Y-m-d\TH:i:s\Z', $AHORA), 'marcar como atendido → 200 con la fecha');
ok(hash_file('sha256', $f) === $antes && filemtime($f) === $mt, 'el JSON del lead no cambia (ni su fecha: la purga de 30 días sigue igual)');
ok(atendidos_leer($COLA) === [$ID => gmdate('Y-m-d\TH:i:s\Z', $AHORA)], 'el estado está en /dl-leads/atendidos.json');
if (DIRECTORY_SEPARATOR === '/') ok((fileperms("$COLA/atendidos.json") & 0777) === 0600, 'atendidos.json con permisos 0600');
ok(glob("$COLA/.tmp-atendidos-*") === [] && is_file("$COLA/atendidos.lock"), 'escritura atómica: sin temporales y con archivo de bloqueo');
[$cod, $d] = $g([]);
ok($d['leads'][2]['atendido'] !== '' && $d['leads'][0]['atendido'] === '', 'la lista refleja el estado');
$otro = $lista[0]['id'];
$post(['accion' => 'atendido', 'id' => $otro, 'atendido' => true, 'csrf' => $s['csrf']]);
ok(count(atendidos_leer($COLA)) === 2, 'dos marcas seguidas se conservan');
[$cod, $d] = $post(['accion' => 'atendido', 'id' => $ID, 'atendido' => false, 'csrf' => $s['csrf']]);
ok($cod === 200 && $d['atendido'] === '' && array_keys(atendidos_leer($COLA)) === [$otro], '«Volver a nuevo» quita la marca');
file_put_contents("$COLA/atendidos.json", json_encode([$otro => '2026-10-26T00:00:00Z', 'L-20200101-000000-0000000000000000' => '2020-01-01T00:00:00Z', '../x' => 'y']));
ok(array_keys(atendidos_leer($COLA)) === [$otro, 'L-20200101-000000-0000000000000000'], 'al leer se descartan entradas con id inválido');
$post(['accion' => 'atendido', 'id' => $ID, 'atendido' => true, 'csrf' => $s['csrf']]);
ok(!isset(atendidos_leer($COLA)['L-20200101-000000-0000000000000000']), 'al escribir se quitan los ids que ya no están en la cola (caducados)');
ok($post(['accion' => 'atendido', 'id' => $ID, 'atendido' => true])[0] === 403, 'sin token CSRF → 403');
ok($post(['accion' => 'atendido', 'id' => $ID, 'atendido' => true, 'csrf' => $s['csrf']], ['HTTP_ORIGIN' => 'https://evil.test'])[0] === 403, 'con token pero desde otro origen → 403');
ok($post(['accion' => 'atendido', 'id' => '../' . $ID, 'atendido' => true, 'csrf' => $s['csrf']])[0] === 400, 'id con ruta → 400');
ok($post(['accion' => 'atendido', 'id' => 'L-20261026-000000-aaaaaaaaaaaaaaaa', 'atendido' => true, 'csrf' => $s['csrf']])[0] === 404, 'id que no existe → 404');
ok($post(['accion' => 'borrar', 'id' => $ID, 'atendido' => true, 'csrf' => $s['csrf']])[0] === 400 && $post(['accion' => 'atendido', 'id' => $ID, 'atendido' => 'si', 'csrf' => $s['csrf']])[0] === 400, 'acción desconocida o valor no booleano → 400');
ok(leads_api(srv('203.0.113.5', ['REQUEST_METHOD' => 'DELETE']), [], '', $COLA, $DIRP, cfg_prueba(), $s, $V, $AHORA)[0] === 405, 'otros métodos → 405');
$enWeb = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($WEB, FilesystemIterator::SKIP_DOTS));
ok(iterator_count($enWeb) === 0, 'nada se escribe en public_html');

/* ================================================================== */
titulo('R8 · CSV sin fórmulas');
ok(leads_csv_celda('=1+1') === '"\'=1+1"' && leads_csv_celda('+34') === '"\'+34"' && leads_csv_celda('-x') === '"\'-x"' && leads_csv_celda('@SUM(A1)') === '"\'@SUM(A1)"',
    '= + - @ al principio llevan apóstrofo');
ok(leads_csv_celda("\tx") === "\"'\tx\"" && leads_csv_celda("\rx") === "\"'\rx\"", 'tabulador y retorno al principio llevan apóstrofo');
ok(leads_csv_celda('Ana "la" Prueba') === '"Ana ""la"" Prueba"' && leads_csv_celda('') === '""' && leads_csv_celda('a=b') === '"a=b"', 'comillas dobladas; vacío y texto normal entre comillas');
$malo = ['id' => 'L-20261025-100000-1111111111111111', 'recibido' => '2026-10-25T10:00:00Z', 'nombre' => '=HYPERLINK("http://evil.test","x")', 'email' => '@evil.test',
    'empresa' => '+SUM(1)', 'necesidad' => "-cmd\n=2", 'utm_source' => '-ig', 'utm_medium' => '+x', 'utm_campaign' => '=c', 'utm_content' => '@c', 'consent_respuesta' => true,
    'consent_version' => '34-2026-10-28', 'consent_fecha' => '2026-10-25T10:00:00Z', 'pais' => 'Chile'];
file_put_contents("$COLA/lead-20261025T100000Z-1111111111111111.json", json_encode($malo));
$csv = leads_csv(leads_listar($COLA, $AHORA), atendidos_leer($COLA));
ok(str_starts_with($csv, "\xEF\xBB\xBF\"id\";\"recibido\""), 'CSV con BOM y cabecera entre comillas');
ok(str_contains($csv, '"\'=HYPERLINK(""http://evil.test"",""x"")"') && str_contains($csv, '"\'+SUM(1)"') && str_contains($csv, "\"'-cmd\n=2\""), 'nombre, empresa y necesidad neutralizados');
$filaMala = '';
foreach (explode("\r\n", $csv) as $fl) if (str_contains($fl, 'L-20261025-100000')) $filaMala = $fl;
ok($filaMala === '' ? false : !str_contains($filaMala, '"-ig"') && !str_contains($filaMala, '"=c"'), 'UTM peligrosos: no entran sin validar (se vacían) ni sin apóstrofo');
$celdas = str_getcsv(substr(explode("\r\n", $csv)[1], 0), ';');
ok(count($celdas) === 21, 'cada fila tiene 21 columnas', (string) count($celdas));
ok(preg_match_all('/(^|;)(?!")/', implode("\r\n", array_filter(explode("\r\n", substr($csv, 3)), fn($x) => $x !== '' && !str_contains($x, "\n")))) === 0, 'toda celda va entre comillas');
foreach (str_getcsv(str_replace("\r\n", ' ', substr($csv, 3)), ';') as $celda) {
    if ($celda !== '' && strpbrk($celda[0], "=+-@\t\r") !== false) { ok(false, 'ninguna celda empieza por un carácter de fórmula', $celda); break; }
}
ok(true, 'ninguna celda empieza por un carácter de fórmula');
$s2 = ['csrf' => str_repeat('9', 64)];
ok(leads_csv_procesar(srv('203.0.113.5', ['REQUEST_METHOD' => 'GET']), [], $COLA, $DIRP, cfg_prueba(), $s2, $V, $AHORA)[0] === 405, 'CSV por GET → 405');
ok(leads_csv_procesar(srv(), ['csrf' => 'x'], $COLA, $DIRP, cfg_prueba(), $s2, $V, $AHORA)[0] === 403, 'CSV con token malo → 403');
$sinO = srv(); unset($sinO['HTTP_ORIGIN']);
ok(leads_csv_procesar($sinO, ['csrf' => $s2['csrf']], $COLA, $DIRP, cfg_prueba(), $s2, $V, $AHORA)[0] === 403, 'CSV sin Origin → 403');

/* ================================================================== */
titulo('Detalle 2 del 16 (tarea 52) · Leads caducados y purga diaria (Cron)');
define('DL_PURGA_PRUEBAS', 1);
require "$RAIZ/inc/purgar.php";
$C2 = "$BASE/cola-purga";
mkdir($C2, 0700);
$escribe = function (int $t, string $nombre) use ($C2, $base): void {
    [$lead] = demo_validar(['nombre' => $nombre] + $base);
    $f = gmdate('Y-m-d\TH:i:s\Z', $t);
    $lead = ['formato' => 1, 'id' => '', 'recibido' => $f] + $lead + ['consent_respuesta' => true, 'consent_novedades' => false, 'consent_version' => '34-2026-10-28', 'consent_fecha' => $f];
    demo_escribir_lead($C2, $lead, $t);
};
$escribe($AHORA - 30 * 86400 - 60, 'Lead Caducado');                  // 30 días y 1 minuto (el caso del 16)
$escribe($AHORA - 30 * 86400, 'Lead Justo');                          // 30 días exactos: ya caducado
$escribe($AHORA - 30 * 86400 + 3600, 'Lead Caduca Hoy');              // le queda 1 hora
$escribe($AHORA - 86400, 'Lead Nuevo');
$l2 = leads_listar($C2, $AHORA);
ok(array_column($l2, 'nombre') === ['Lead Nuevo', 'Lead Caduca Hoy'], 'un lead con 30 días o más no se muestra aunque siga en el disco', implode(', ', array_column($l2, 'nombre')));
$todos = [];
foreach (glob("$C2/lead-*.json") as $fl) { $j = json_decode((string) file_get_contents($fl), true); $todos[$j['nombre']] = $j['id']; }
[$cod] = leads_api(['REQUEST_METHOD' => 'GET'], ['id' => $todos['Lead Caducado']], '', $C2, $DIRP, cfg_prueba(), $s, $V, $AHORA);
ok($cod === 404, 'la ficha de un lead caducado da 404');
$csvC = leads_csv(leads_listar($C2, $AHORA), []);
ok(!str_contains($csvC, 'Lead Caducado') && !str_contains($csvC, 'Lead Justo') && str_contains($csvC, 'Lead Caduca Hoy'), 'el CSV no lleva leads caducados');
file_put_contents("$C2/atendidos.json", json_encode([$todos['Lead Caducado'] => '2026-09-30T00:00:00Z', $todos['Lead Nuevo'] => '2026-10-26T00:00:00Z']));
$P2 = "$BASE/panel-purga";
mkdir("$P2/registro", 0700, true);
touch("$P2/registro/acceso-" . gmdate('Ymd', $AHORA - 91 * 86400) . '.log');
$rp = purga_todo($C2, $P2, $AHORA);
// demo_purgar borra con más de 30 días (el de 30 exactos cae en la purga siguiente; ya no se muestra)
ok($rp['leads_borrados'] === 1 && $rp['leads_quedan'] === 3 && count(glob("$C2/lead-*.json")) === 3, 'la purga diaria borra el lead de 30 días y 1 minuto sin esperar a una visita', json_encode($rp));
ok($rp['atendidos_quitados'] === 1 && array_keys(atendidos_leer($C2)) === [$todos['Lead Nuevo']], 'quita de atendidos.json la marca del lead borrado');
ok(glob("$P2/registro/acceso-*.log") === [], 'también purga el registro de accesos del panel (> 90 días)');
ok(purga_todo(null, null, $AHORA) === ['cola' => false, 'panel' => false, 'leads_borrados' => 0, 'leads_quedan' => 0, 'atendidos_quitados' => 0], 'sin cola ni panel: no toca nada');
$srcP = (string) file_get_contents("$RAIZ/inc/purgar.php");
ok(preg_match("/declare\(strict_types=1\);\s+if \(PHP_SAPI !== 'cli'\) \{ http_response_code\(404\); exit; \}/", $srcP) === 1, 'inc/purgar.php solo corre por línea de comandos (por la web, 404)');
ok(!preg_match('/echo[^;]*\$(l|lead)\[/', $srcP), 'la salida del Cron solo lleva recuentos, nunca datos de leads');

/* ================================================================== */
titulo('R7 · Salida segura en el navegador');
$js = (string) file_get_contents("$RAIZ/panel/leads.js");
$sinComentarios = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);
ok(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function|setAttribute\(\s*[\'"]on/i', $sinComentarios), 'leads.js no usa innerHTML, insertAdjacentHTML, document.write ni eval');
ok(substr_count($sinComentarios, '.href =') === 3 && str_contains($sinComentarios, "a.href = 'mailto:' + l.email") && str_contains($sinComentarios, "a.href = '#ficha'")
    && str_contains($sinComentarios, 'a.href = url;') && str_contains($sinComentarios, 'url = URL.createObjectURL(blob)'), 'único enlace con datos: mailto: (los otros: #ficha y el blob del CSV)');
ok(substr_count($sinComentarios, "referrerPolicy: 'same-origin'") === 2 && str_contains((string) file_get_contents("$RAIZ/login/login.js"), "referrerPolicy: 'same-origin'"),
    'los POST salen con Origin real pese a no-referrer (R5 + R6)');
ok(str_contains($sinComentarios, "l.email_valido === true && RE_CORREO.test(l.email)"), 'el mailto solo se crea con correo validado (servidor y navegador)');
$html = (string) file_get_contents("$RAIZ/panel/leads.php");
ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $html) && !preg_match('/\son[a-z]+=/i', $html) && !preg_match('/\sstyle=/i', $html), 'leads.php sin scripts, manejadores ni estilos en línea (CSP)');
ok(str_contains($html, "htmlspecialchars((string) \$sesion['csrf'], ENT_QUOTES"), 'el token se escapa al escribirlo en la página');
ok(leads_correo_valido('ana@ejemplo.test') && !leads_correo_valido('a"b@x.test') && !leads_correo_valido('a<b>@x.test') && !leads_correo_valido('javascript:alert(1)//@x.test') && !leads_correo_valido('a@b'),
    'correo para mailto: solo con caracteres corrientes');

/* ================================================================== */
titulo('R11 · Registro de accesos');
$c = nuevo_panel();
login($c, srv('203.0.113.120'), $AHORA, 'intruso@ejemplo.test', 'contraseña-que-teclea-otro');
login($c, srv('203.0.113.120'), $AHORA + 1);
$e = ps_estado($c, srv());
$v = ps_visitante(srv('203.0.113.120'), $c);
leads_api(['REQUEST_METHOD' => 'GET'], ['id' => $ID], '', $COLA, (string) $e['dir'], $c, $s, $v, $AHORA + 2);
leads_api(srv('203.0.113.120'), [], (string) json_encode(['accion' => 'atendido', 'id' => $ID, 'atendido' => true, 'csrf' => $s['csrf']]), $COLA, (string) $e['dir'], $c, $s, $v, $AHORA + 3);
leads_csv_procesar(srv('203.0.113.120'), ['csrf' => $s['csrf']], $COLA, (string) $e['dir'], $c, $s, $v, $AHORA + 4);
$reg = glob($e['dir'] . '/registro/acceso-*.log');
ok(count($reg) === 1 && basename($reg[0]) === 'acceso-' . gmdate('Ymd', $AHORA) . '.log', 'un archivo por día (UTC) en <panel_dir>/registro, fuera de public_html');
if (DIRECTORY_SEPARATOR === '/') ok((fileperms($reg[0]) & 0777) === 0600, 'registro con permisos 0600');
$txt = (string) file_get_contents($reg[0]);
$filasReg = array_map(fn($x) => json_decode($x, true), array_filter(explode("\n", $txt)));
ok(array_column($filasReg, 'accion') === ['login', 'login', 'ficha', 'atendido', 'csv'] && array_column($filasReg, 'r') === ['fallo', 'ok', 'ok', 'ok', 'ok'], 'registra login, ficha, atendido y CSV con su resultado');
ok(array_unique(array_map(fn($x) => implode(',', array_keys($x)), $filasReg)) === ['t,accion,lead,v,r'], 'solo fecha UTC, acción, id de lead, visitante (HMAC) y resultado');
ok($filasReg[2]['lead'] === $ID && $filasReg[0]['v'] === substr($v, 0, 16) && $filasReg[0]['t'] === gmdate('Y-m-d\TH:i:s\Z', $AHORA), 'id de lead, HMAC del visitante y hora UTC correctos');
$prohibido = ['203.0.113.120', 'intruso@ejemplo.test', 'contraseña-que-teclea-otro', PASS, EMAIL, 'Ana Prueba', 'ana@ejemplo.test', 'Empresa Ficticia', 'Automatizar', 'lanzamiento-oct', 'España'];
$hall = array_filter($prohibido, fn($p) => str_contains($txt, $p));
ok($hall === [], 'nunca IP, correo tecleado, contraseña, nombre, correo, empresa, necesidad, UTM ni país del lead', implode(', ', $hall));
touch($e['dir'] . '/registro/acceso-' . gmdate('Ymd', $AHORA - 91 * 86400) . '.log');
touch($e['dir'] . '/registro/acceso-' . gmdate('Ymd', $AHORA - 89 * 86400) . '.log');
ps_purgar((string) $e['dir'], $AHORA);
ok(!is_file($e['dir'] . '/registro/acceso-' . gmdate('Ymd', $AHORA - 91 * 86400) . '.log') && is_file($e['dir'] . '/registro/acceso-' . gmdate('Ymd', $AHORA - 89 * 86400) . '.log'),
    'se conservan 90 días: se borra el de hace 91 y queda el de hace 89');
ps_registrar((string) $e['dir'], 'inventada', '../x', 'IP 1.2.3.4', 'raro', $AHORA);
$ult = json_decode(trim((string) array_slice(file($reg[0]), -1)[0]), true);
ok($ult === ['t' => gmdate('Y-m-d\TH:i:s\Z', $AHORA), 'accion' => 'error', 'lead' => '', 'v' => '', 'r' => 'error'], 'valores fuera de lista no entran en el registro', json_encode($ult));

/* ================================================================== */
titulo('R12 · Si falta configuración, se cierra');
$m503 = [503, ['ok' => false, 'error' => 'sin_servicio']];
$cfgs = [
    'sin admin_email' => cfg_prueba(['admin_email' => '', 'panel_dir' => "$COLA/r12a"]),
    'sin hash' => cfg_prueba(['admin_password_hash' => '', 'panel_dir' => "$COLA/r12b"]),
    'hash débil (bcrypt 10)' => cfg_prueba(['admin_password_hash' => password_hash(PASS, PASSWORD_BCRYPT, ['cost' => 10]), 'panel_dir' => "$COLA/r12c"]),
    'sal de 31 caracteres' => cfg_prueba(['panel_sal' => str_repeat('x', 31), 'panel_dir' => "$COLA/r12d"]),
    'carpeta dentro de public_html' => cfg_prueba(['panel_dir' => "$WEB/privado"]),
    'carpeta que es un archivo' => cfg_prueba(['panel_dir' => "$COLA/atendidos.json"]),
];
foreach ($cfgs as $nombre => $cf) {
    $est = ps_estado($cf, srv());
    ok(!$est['login'] && !$est['leads'] && login($cf, srv('203.0.113.130'), $AHORA) === $m503, "$nombre: login y Leads 503");
}
ok(!is_dir("$WEB/privado"), 'no crea la carpeta privada dentro de public_html');
$sinCola = cfg_prueba(['_leads' => ['cola_dir' => "$BASE/no-existe"], 'panel_dir' => "$COLA/r12e"]);
$est = ps_estado($sinCola, srv());
ok($est['login'] && !$est['leads'], 'sin cola de leads: se entra al panel (estadísticas) pero Leads da 503');
$def = ps_config_desde([], [], $WEB);
ok($def['aviso_para'] === 'digital-lab@dl-dev.com' && $def['dir'] === dirname($WEB) . '/dl-leads/panel' && $def['cola'] === dirname($WEB) . '/dl-leads'
    && $def['email'] === '' && $def['hash'] === '' && $def['sal'] === '', 'valores por defecto: buzón de la empresa, /dl-leads/panel, y nada de credenciales');
ok(!ps_estado($def, srv())['login'], 'configuración vacía: login cerrado');
foreach (['panel/leads.php', 'panel/leads-api.php', 'panel/leads-csv.php'] as $f) {
    ok(str_contains((string) file_get_contents("$RAIZ/$f"), "if (!\$e['leads'])"), "$f responde 503 sin configuración de Leads");
}
ok(str_contains((string) file_get_contents("$RAIZ/panel/r.php"), "require __DIR__ . '/lib.php'") && !str_contains((string) file_get_contents("$RAIZ/panel/r.php"), 'ps_estado'),
    'el recolector de estadísticas no depende de la configuración del login (sigue midiendo)');

/* ================================================================== */
titulo('IP compartida (inc/ip.php)');
$demo = (string) file_get_contents("$RAIZ/demo/demo.php");
ok(!str_contains($demo, 'function demo_ip(') && str_contains($demo, "require_once dirname(__DIR__) . '/inc/ip.php'"), 'demo.php ya no define las funciones de IP: las incluye');
ok(str_contains((string) file_get_contents("$RAIZ/panel/seguridad.php"), "require_once dirname(__DIR__) . '/inc/ip.php'"), 'el login usa el mismo archivo');
ok(str_contains((string) file_get_contents("$RAIZ/inc/.htaccess"), 'Require all denied'), 'inc/ cerrado a la web');

/* ================================================================== */
titulo('Tarea 54 · Cambiar la contraseña desde el panel');
const NUEVA = 'otra-clave-ficticia-Ü-2026-larga';
$c = nuevo_panel();
$e = ps_estado($c, srv());
$TOK = str_repeat('7', 64);
$SES = ['admin' => 1, 'inicio' => $AHORA, 'ultimo' => $AHORA, 'csrf' => $TOK, 'huella' => $e['huella']];
$ROTADA = null;
function srv_cambio(string $ip = '203.0.113.150', array $extra = []): array
{
    return srv($ip, $extra + ['HTTP_X_DL_ACCION' => '1']);
}
// Un cambio. $rotar falso: anota la huella nueva y devuelve un token nuevo y 2 sesiones cerradas.
function cambiar(array $c, array $server, array $cuerpo, int $ahora, array $ses, ?callable $rotar = null, ?callable $enviar = null): array
{
    global $ROTADA;
    $e = ps_estado($c, $server);
    $rotar ??= function (string $h) use (&$ROTADA): array { $ROTADA = $h; return ['csrf' => str_repeat('8', 64), 'cerradas' => 2]; };
    return ps_cambiar_contrasena($server, (string) json_encode($cuerpo), $c, $e, $ses, $ahora, $rotar, $enviar ?? 'enviar_falso', fn($ip) => 'ES');
}
$cuerpoOk = ['actual' => PASS, 'nueva' => NUEVA, 'repite' => NUEVA, 'csrf' => $TOK];
$contV = fn(string $ip) => json_decode((string) @file_get_contents($c['dir'] . '/bloqueo/v-' . ps_visitante(srv($ip), $c) . '.json'), true) ?: [];

ok($e['origen_hash'] === 'config' && $e['hash'] === $HASH && strlen($e['huella']) === 32, 'sin credencial.json manda admin_password_hash del config');
// R6: método, CSRF, Origin y cabecera de acción
ok(cambiar($c, srv_cambio('203.0.113.150', ['REQUEST_METHOD' => 'GET']), $cuerpoOk, $AHORA, $SES)[0] === 405, 'por GET → 405');
ok(cambiar($c, srv_cambio(), ['csrf' => null] + $cuerpoOk, $AHORA, $SES) === [403, ['ok' => false, 'error' => 'csrf']], 'sin token CSRF → 403');
ok(cambiar($c, srv_cambio(), ['csrf' => str_repeat('9', 64)] + $cuerpoOk, $AHORA, $SES)[0] === 403, 'token distinto → 403');
ok(cambiar($c, srv_cambio('203.0.113.150', ['HTTP_ORIGIN' => 'https://evil.test']), $cuerpoOk, $AHORA, $SES)[0] === 403, 'Origin ajeno → 403');
$sinO = srv_cambio(); unset($sinO['HTTP_ORIGIN']);
ok(cambiar($c, $sinO, $cuerpoOk, $AHORA, $SES)[0] === 403, 'sin Origin → 403');
ok(cambiar($c, srv_cambio('203.0.113.150', ['HTTP_SEC_FETCH_SITE' => 'cross-site']), $cuerpoOk, $AHORA, $SES)[0] === 403, 'Sec-Fetch-Site cross-site → 403');
ok(cambiar($c, srv('203.0.113.150'), $cuerpoOk, $AHORA, $SES)[0] === 403, 'sin «X-DL-Accion: 1» → 403 (solo una acción del usuario puede cambiarla)');
ok(!is_file($c['dir'] . '/credencial.json') && $contV('203.0.113.150') === [], 'los rechazos por CSRF no cambian nada ni cuentan como intento');

// Formato de la nueva: mensajes claros y sin contar como intento (no se compara nada)
$f400 = fn(array $b) => cambiar($c, srv_cambio(), $b + $cuerpoOk, $AHORA, $SES);
ok($f400(['nueva' => 'trece-letras!', 'repite' => 'trece-letras!'])[1]['error'] === 'corta', 'nueva de 13 caracteres → «corta»');
ok($f400(['nueva' => str_repeat('ñ', 513), 'repite' => str_repeat('ñ', 513)])[1]['error'] === 'larga', 'nueva de 1026 bytes → «larga»');
ok($f400(['repite' => NUEVA . 'x']) === [400, ['ok' => false, 'error' => 'no_coinciden', 'campo' => 'repite']], 'nueva y repetición distintas → «no_coinciden» en el campo de la repetición');
ok($f400(['actual' => ''])[1]['error'] === 'actual_vacia', 'actual vacía → «actual_vacia»');
ok($contV('203.0.113.150') === [] && !is_file($c['dir'] . '/credencial.json'), 'los errores de formato no cuentan como intento ni guardan nada');

// R2: la actual incorrecta cuenta como intento de login (mismos contadores)
$CORREOS = [];
$r = cambiar($c, srv_cambio(), ['actual' => 'no-es-la-actual-123'] + $cuerpoOk, $AHORA, $SES);
ok($r === [400, ['ok' => false, 'error' => 'actual', 'campo' => 'actual']], 'actual incorrecta → 400 «actual»');
ok(count($contV('203.0.113.150')['fallos'] ?? []) === 1, 'la actual incorrecta suma un fallo al contador del visitante (el mismo del login)');
$g = json_decode((string) file_get_contents($c['dir'] . '/bloqueo/global.json'), true);
ok(count($g['fallos'] ?? []) === 1, 'y también al contador global');
ok(!is_file($c['dir'] . '/credencial.json') && $ROTADA === null && $CORREOS === [], 'con la actual incorrecta no se guarda nada, no se toca la sesión ni se avisa');
ok(cambiar($c, srv_cambio(), ['nueva' => PASS, 'repite' => PASS] + $cuerpoOk, $AHORA, $SES) === [400, ['ok' => false, 'error' => 'igual', 'campo' => 'nueva']], 'nueva igual a la actual → «igual» (solo se dice tras acertar la actual)');
ok(count($contV('203.0.113.150')['fallos'] ?? []) === 0, 'acertar la actual anula su intento y olvida los fallos sueltos, como el login');

// Cambio correcto
$CORREOS = [];
$r = cambiar($c, srv_cambio(), $cuerpoOk, $AHORA + 10, $SES);
ok($r === [200, ['ok' => true, 'sesion' => true, 'csrf' => str_repeat('8', 64), 'cerradas' => 2, 'aviso' => true]], 'cambio correcto → 200 con token nuevo y sesiones cerradas', json_encode($r));
$fc = $c['dir'] . '/credencial.json';
$jc = json_decode((string) @file_get_contents($fc), true);
ok(is_array($jc) && array_keys($jc) === ['formato', 'hash', 'base', 'cambiado'] && $jc['formato'] === 1 && $jc['base'] === hash('sha256', $HASH) && $jc['cambiado'] === gmdate('Y-m-d\TH:i:s\Z', $AHORA + 10),
    'credencial.json: formato 1, hash, base = sha256 del hash del config y fecha UTC', json_encode(array_keys((array) $jc)));
ok(password_get_info($jc['hash'])['algo'] === 'argon2id' && ps_hash_valido($jc['hash']) && password_verify(NUEVA, $jc['hash']), 'hash nuevo argon2id que verifica la nueva');
if (DIRECTORY_SEPARATOR === '/') ok((fileperms($fc) & 0777) === 0600, 'credencial.json con permisos 0600');
ok(glob($c['dir'] . '/.tmp-credencial-*') === [] && is_file($c['dir'] . '/credencial.lock'), 'escritura atómica: sin temporales y con archivo de bloqueo');
$e2 = ps_estado($c, srv());
ok($e2['origen_hash'] === 'panel' && $e2['hash'] === $jc['hash'] && $e2['huella'] !== $e['huella'] && $ROTADA === $e2['huella'], 'ahora manda credencial.json; la huella cambia y la sesión actual recibe la nueva');
ok(login($c, srv('203.0.113.151'), $AHORA + 20, EMAIL, NUEVA)[0] === 200, 'login con la contraseña nueva → 200');
ok(login($c, srv('203.0.113.152'), $AHORA + 20) === $CRED, 'la contraseña vieja ya no entra');
ok(!ps_sesion_valida($SES, $AHORA + 20, $e2['huella']) && ps_sesion_valida(['huella' => $e2['huella']] + $SES, $AHORA + 20, $e2['huella']), 'una sesión con la huella anterior deja de valer; con la nueva, vale');
ok(!ps_sesion_valida(array_diff_key($SES, ['huella' => 1]), $AHORA, $e['huella']) && !ps_sesion_valida($SES, $AHORA, ''), 'sesión sin huella (anterior a la tarea 54) o estado sin huella: no vale');
$avisosC = array_values(array_filter($CORREOS, fn($x) => str_contains($x['asunto'], 'contraseña')));   // el login de después manda el suyo
$m = $avisosC[0] ?? ['para' => '', 'asunto' => '', 'cuerpo' => '', 'de' => ''];
ok(count($avisosC) === 1 && $m['asunto'] === '[dl-dev.com] Se cambió la contraseña del panel' && str_contains($m['cuerpo'], 'Si no fuiste tú') && str_contains($m['cuerpo'], 'hash-contrasena.py --subir')
    && str_contains($m['cuerpo'], 'Fecha (UTC): ' . gmdate('Y-m-d H:i:s', $AHORA + 10)) && str_contains($m['cuerpo'], 'País aproximado: ES'), 'aviso por correo «se cambió la contraseña… si no fuiste tú…»', count($CORREOS) . ' ' . json_encode($m, JSON_UNESCAPED_UNICODE));
$todo = json_encode($r) . $m['asunto'] . $m['cuerpo'] . (string) file_get_contents($fc);
ok(!str_contains($todo, PASS) && !str_contains($todo, NUEVA) && !str_contains($todo, '203.0.113.150') && !str_contains($todo, EMAIL), 'ni la respuesta, ni el correo, ni credencial.json llevan contraseñas, IP ni correo');
$regC = implode('', array_map('file_get_contents', glob($c['dir'] . '/registro/acceso-*.log')));
$filasC = array_values(array_filter(array_map(fn($x) => json_decode($x, true), explode("\n", $regC)), fn($x) => is_array($x) && $x['accion'] === 'contrasena'));
ok(array_column($filasC, 'r') === ['csrf', 'csrf', 'csrf', 'csrf', 'csrf', 'csrf', 'fallo', 'ok'], 'registro: cada rechazo CSRF, el fallo y el cambio, sin datos personales', implode(',', array_column($filasC, 'r')));
ok(!str_contains($regC, PASS) && !str_contains($regC, NUEVA) && !str_contains($regC, '203.0.113.150') && !str_contains($regC, 'no-es-la-actual-123'), 'el registro no lleva contraseñas (ni la mala) ni IP');
ok(cambiar($c, srv_cambio(), $cuerpoOk, $AHORA + 30, ['huella' => $e2['huella']] + $SES)[1]['error'] === 'actual', 'tras el cambio, la vieja ya no sirve como «actual»');
$CORREOS = [];
ok(cambiar($c, srv_cambio(), ['actual' => NUEVA, 'nueva' => PASS, 'repite' => PASS] + $cuerpoOk, $AHORA + 40, ['huella' => $e2['huella']] + $SES, null, fn() => false)
    === [200, ['ok' => true, 'sesion' => true, 'csrf' => str_repeat('8', 64), 'cerradas' => 2, 'aviso' => false]], 'se puede volver a cambiar; si el correo falla, el cambio sigue (aviso: false)');
ok(cambiar($c, srv_cambio(), ['actual' => PASS, 'nueva' => NUEVA, 'repite' => NUEVA] + $cuerpoOk, $AHORA + 50, $SES, fn() => null)[1] === ['ok' => true, 'sesion' => false, 'aviso' => true],
    'si no se puede rotar la sesión, la contraseña cambia igual y se pide volver a entrar (sesion: false)');

// Qué manda: si el humano cambia admin_password_hash (hash-contrasena.py --subir), credencial.json queda sin efecto
$HASH_SCRIPT = password_hash('clave-puesta-con-el-script-2026', PASSWORD_ARGON2ID, ARGON_RAPIDO);
$cScript = cfg_prueba(['admin_password_hash' => $HASH_SCRIPT, 'panel_dir' => $c['dir']]);
$e3 = ps_estado($cScript, srv());
ok($e3['origen_hash'] === 'config' && $e3['hash'] === $HASH_SCRIPT && $e3['huella'] !== ps_estado($c, srv())['huella'], 'config cambiado después: manda el config (credencial.json ignorado) y cambia la huella (se cierran todas las sesiones)');
ok(login($cScript, srv('203.0.113.153'), $AHORA + 60, EMAIL, 'clave-puesta-con-el-script-2026')[0] === 200 && login($cScript, srv('203.0.113.154'), $AHORA + 60, EMAIL, NUEVA) === $CRED,
    'entra la del script; la cambiada en el panel ya no');
ok(ps_estado($c, srv())['origen_hash'] === 'panel', 'volver al hash anterior del config (--restaurar-config) vuelve a dar validez a credencial.json');
$guardado = (string) file_get_contents($fc);
file_put_contents($fc, '{roto');
ok(ps_estado($c, srv())['origen_hash'] === 'config', 'credencial.json roto: se ignora (manda el config)');
file_put_contents($fc, json_encode(['formato' => 1, 'hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 10]), 'base' => hash('sha256', $HASH), 'cambiado' => 'x']));
ok(ps_estado($c, srv())['origen_hash'] === 'config', 'credencial.json con bcrypt coste 10: se ignora (R1)');
file_put_contents($fc, $guardado);
ok(ps_credencial_leer($c['dir'], '') === null, 'sin hash en el config, credencial.json no cuenta (R12 sigue cerrando)');
touch($c['dir'] . '/.tmp-credencial-viejo', $AHORA - 7200);
ps_purgar($c['dir'], $AHORA);
ok(!is_file($c['dir'] . '/.tmp-credencial-viejo'), 'la purga borra temporales de cambios interrumpidos');

// R2: 5 actuales incorrectas bloquean; el bloqueo vale también para el login y para seguir cambiando
$c = nuevo_panel();
$e = ps_estado($c, srv());
$S2 = ['huella' => $e['huella']] + $SES;
$ip = '198.51.100.160';
$cods = [];
for ($i = 0; $i < 5; $i++) $cods[] = cambiar($c, srv_cambio($ip), ['actual' => 'mala-pero-larga-' . $i] + $cuerpoOk, $AHORA + $i, $S2)[0];
ok($cods === [400, 400, 400, 400, 429], '5 actuales incorrectas: la 5.ª bloquea (429 «bloqueado»)', implode(',', $cods));
ok(login($c, srv($ip), $AHORA + 10) === $CRED, 'tras el bloqueo, ese visitante tampoco entra por el login con la contraseña buena');
ok(cambiar($c, srv_cambio($ip), $cuerpoOk, $AHORA + 11, $S2) === [429, ['ok' => false, 'error' => 'bloqueado', 'campo' => 'actual']] && !is_file($c['dir'] . '/credencial.json'), 'bloqueado: ni con la actual correcta se cambia');
for ($i = 0; $i < 4; $i++) login($c, srv('198.51.100.161'), $AHORA + $i, EMAIL, 'mala-pero-larga-123');
ok(cambiar($c, srv_cambio('198.51.100.161'), ['actual' => 'otra-mala-larga-1'] + $cuerpoOk, $AHORA + 5, $S2)[0] === 429, '4 logins fallidos + 1 actual incorrecta = bloqueo (un solo contador)');
$c = nuevo_panel();
$e = ps_estado($c, srv());
file_put_contents($e['dir'] . '/bloqueo', 'no soy una carpeta');
ok(cambiar($c, srv_cambio(), $cuerpoOk, $AHORA, ['huella' => $e['huella']] + $SES)[0] === 503, 'sin contador de intentos: no se cambia (503)');

// Sesión real (HTTP): id nuevo, huella y token nuevos, y las demás sesiones cerradas
$c = nuevo_panel();
$e = ps_estado($c, srv());
$ids = [];
for ($i = 0; $i < 3; $i++) { unset($_COOKIE[PS_COOKIE]); session_id(''); ps_sesion_nueva($e, $AHORA); $ids[] = session_id(); }
ok(count(array_unique($ids)) === 3 && count(glob($e['dir'] . '/sesiones/sess_*')) === 3, 'tres sesiones abiertas (tres navegadores)');
$_COOKIE[PS_COOKIE] = $ids[0];
session_id($ids[0]);
$antesS = ps_sesion_actual($e, $AHORA + 5);
$huellaN = ps_huella('$argon2id$ficticio', SAL);
$rot = ps_sesion_rotar($e, $huellaN, $AHORA + 6);
$nuevoId = session_id();
ok(is_array($rot) && $rot['cerradas'] === 2 && preg_match('/^[a-f0-9]{64}$/D', $rot['csrf']) && $rot['csrf'] !== $antesS['csrf'], 'rotar: cierra las otras 2 y da un token CSRF nuevo', json_encode($rot));
ok($nuevoId !== $ids[0] && glob($e['dir'] . '/sesiones/sess_*') === [$e['dir'] . '/sesiones/sess_' . $nuevoId], 'id de sesión regenerado: solo queda el archivo de la sesión nueva');
$cont = (string) file_get_contents($e['dir'] . '/sesiones/sess_' . $nuevoId);
ok(str_contains($cont, 'huella|s:32:"' . $huellaN . '"') && str_contains($cont, 'inicio|i:' . $AHORA . ';') && !str_contains($cont, PASS), 'la sesión nueva guarda la huella nueva y conserva su inicio (el tope de 8 h no se reinicia)');
$eN = ['huella' => $huellaN] + $e;
$_COOKIE[PS_COOKIE] = $nuevoId; session_id($nuevoId);
ok(is_array(ps_sesion_actual($eN, $AHORA + 7)), 'la sesión que cambió la contraseña sigue abierta');
$_COOKIE[PS_COOKIE] = $ids[1]; session_id($ids[1]);
ok(ps_sesion_actual($eN, $AHORA + 7) === null, 'otra sesión anterior queda cerrada');
$_COOKIE[PS_COOKIE] = $nuevoId; session_id($nuevoId);
ok(ps_sesion_actual($e, $AHORA + 8) === null, 'con la huella anterior (otra pestaña con el hash viejo) ya no vale');
unset($_COOKIE[PS_COOKIE]);

// Página, endpoint y JS
$pag = (string) file_get_contents("$RAIZ/panel/contrasena.php");
ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $pag) && !preg_match('/\son[a-z]+=/i', $pag) && !preg_match('/\sstyle=/i', $pag), 'contrasena.php sin scripts, manejadores ni estilos en línea (CSP)');
ok(str_contains($pag, "htmlspecialchars((string) \$sesion['csrf'], ENT_QUOTES") && str_contains($pag, "if (!\$e['login'])") && str_contains($pag, "header('Location: /login/', true, 303)"), 'página: token escapado, 503 sin configuración y sin sesión al login');
ok(substr_count($pag, 'type="password"') === 3 && str_contains($pag, 'autocomplete="current-password"') && substr_count($pag, 'autocomplete="new-password"') === 2 && substr_count($pag, 'aria-pressed="false"') === 3,
    'tres campos de contraseña con autocomplete correcto y su botón «Mostrar» (aria-pressed)');
// Tarea 55 (H1): sin JS el envío normal no puede poner nada en la URL ni en el registro
$loginHtml = (string) file_get_contents("$RAIZ/login/index.html");
foreach (['panel/contrasena.php' => $pag, 'login/index.html' => $loginHtml] as $f => $txt) {
    ok(preg_match('/<form\b[^>]*\bmethod="post"[^>]*\baction="[^"]*sin-js\.php"/', $txt) === 1, "$f: <form method=\"post\"> con action a sin-js.php");
    ok(preg_match('/<input\b[^>]*type="password"[^>]*\bname=/', $txt) === 0 && preg_match('/<input\b[^>]*\bname="[^"]*"[^>]*type="password"/', $txt) === 0, "$f: los campos de contraseña no llevan name");
}
ok(preg_match('/<input\b[^>]*type="hidden"[^>]*\bname=/', $pag) === 0, 'contrasena.php: el token CSRF oculto tampoco lleva name (el JS lo lee por id)');
$sinjsCod = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents("$RAIZ/panel/sin-js.php"));
ok(str_contains($sinjsCod, 'http_response_code(400)') && !preg_match('/php:\/\/input|\$_POST|\$_REQUEST|error_log|file_put_contents|session|ps_config|ps_estado/i', $sinjsCod), 'sin-js.php: responde 400, no lee el cuerpo, no registra ni abre sesión');
$jsL = (string) file_get_contents("$RAIZ/login/login.js");
ok(!str_contains($jsL, 'elements.password') && str_contains($jsL, "getElementById('lg-password')"), 'login.js lee la contraseña por id');
foreach (['panel/leads.php', 'panel/index.html', 'panel/contrasena.php'] as $f) {
    ok(str_contains((string) file_get_contents("$RAIZ/$f"), '<a href="/panel/contrasena.php"'), "$f: «Contraseña» en el menú del panel");
}
$jsC = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents("$RAIZ/panel/contrasena.js"));
ok(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|console\.|localStorage|sessionStorage/i', $jsC), 'contrasena.js: sin innerHTML, eval, consola ni almacenamiento (no guarda ni registra contraseñas)');
ok(str_contains($jsC, "'X-DL-Accion': '1'") && str_contains($jsC, "referrerPolicy: 'same-origin'") && substr_count($jsC, 'fetch(') === 1, 'contrasena.js: un solo fetch, con X-DL-Accion y Origin real');
$api = (string) file_get_contents("$RAIZ/panel/contrasena-cambiar.php");
ok(str_contains($api, "if (!\$e['login']) ps_json(503") && str_contains($api, "if (\$sesion === null) ps_json(401"), 'contrasena-cambiar.php: 503 sin configuración y 401 sin sesión');
ok(str_contains((string) file_get_contents("$RAIZ/login/entrar.php"), 'ps_sesion_nueva($estado, $ahora)'), 'el login guarda la huella del hash vigente en la sesión');

/* ================================================================== */
echo implode("\n", $lineas), "\n\n";
echo $fallos === 0 ? "TODO BIEN: $total pruebas\n" : "FALLAN $fallos de $total pruebas\n";
ob_end_flush();
exit($fallos === 0 ? 0 : 1);
