<?php
/*
 * Digital Lab · seguridad del panel (/login/ y /panel/): requisitos R1-R12 del 25
 * (empresa/proyectos/leads/docs/seguridad/panel-leads-requisitos.md).
 *
 * Funciones sin efectos globales salvo las marcadas «(HTTP)»: las usan entrar.php, leads*.php,
 * api.php y salir.php, y las prueba pruebas/panel/prueba-panel.php con datos ficticios.
 *
 * Configuración (dl-auditoria-config.php, fuera de public_html y de Git):
 *   'admin_email'         => 'correo de acceso',
 *   'admin_password_hash' => hash argon2id (o bcrypt coste ≥ 12) de una contraseña de 14+ caracteres:
 *                            se genera en local con scripts/hash-contrasena.py (la contraseña no sale del PC),
 *   'panel_sal'           => texto aleatorio de 32+ caracteres (HMAC del visitante; lo genera el mismo script),
 *   'panel_dir'           => opcional: carpeta privada del panel; por defecto /dl-leads/panel (0700, fuera de la web),
 *   'panel_aviso_para'    => opcional: buzón del aviso de cada inicio de sesión; por defecto digital-lab@dl-dev.com
 *                            ('' = sin aviso),
 *   'panel_aviso_de'      => opcional: remitente del aviso; por defecto digital-lab@dl-dev.com.
 * La IP real (ip_cabecera, ip_proxies) y la cola de leads (cola_dir) se leen de /dl-leads/config.php,
 * la misma configuración que usa /demo, para que login y formulario midan la IP igual.
 */
declare(strict_types=1);

if (!defined('DL_PANEL')) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/ip.php';

const PS_COOKIE = '__Host-dlpanel';
const PS_INACTIVIDAD = 1800;           // R3: 30 min sin actividad
const PS_MAXIMO = 28800;               // R3: 8 h como máximo desde el inicio de sesión
const PS_MAX_CUERPO = 4096;            // bytes del JSON del login
const PS_PASS_MIN = 14;                // R1
const PS_PASS_MAX = 1024;              // R1: bytes
const PS_SAL_MIN = 32;                 // R12
const PS_FALLOS_VISITANTE = 5;         // R2: 5 fallos…
const PS_VENTANA_VISITANTE = 900;      // …en 15 min…
const PS_BLOQUEO_BASE = 900;           // …bloquean 15 min, duplicando…
const PS_BLOQUEO_MAX = 86400;          // …hasta 24 h
const PS_FALLOS_GLOBAL = 30;           // R2: 30 fallos de todos…
const PS_VENTANA_GLOBAL = 3600;        // …en una hora…
const PS_BLOQUEO_GLOBAL = 900;         // …bloquean el login 15 min
const PS_REGISTRO_DIAS = 90;           // R11
const PS_ID_LEAD = '/^L-\d{8}-\d{6}-[a-f0-9]{16}\z/D';   // R9
const PS_ACCIONES = ['login', 'bloqueo_global', 'lista', 'ficha', 'atendido', 'nuevo', 'csv', 'salir'];
const PS_RESULTADOS = ['ok', 'fallo', 'bloqueado', 'csrf', 'no_existe', 'error'];

/* ------------------------------------------------------------------ */
/* Configuración y fallo cerrado (R12)                                  */
/* ------------------------------------------------------------------ */

// (HTTP) Configuración real: dl-auditoria-config.php (cfg) + /dl-leads/config.php
function ps_config(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $web = dirname(__DIR__);
    $a = [];
    foreach (['admin_email', 'admin_password_hash', 'panel_sal', 'panel_dir', 'origenes', 'panel_aviso_para', 'panel_aviso_de'] as $k) {
        $a[$k] = function_exists('cfg') ? cfg($k) : null;
    }
    $rutaLeads = dirname($web) . '/dl-leads/config.php';
    $l = is_file($rutaLeads) ? require $rutaLeads : [];
    $c = ps_config_desde($a, is_array($l) ? $l : [], $web);
    return $c;
}

function ps_config_desde(array $a, array $l, string $web): array
{
    $cola = (string) ($l['cola_dir'] ?? '') !== '' ? (string) $l['cola_dir'] : dirname($web) . '/dl-leads';
    $correo = static fn($v): string => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    return [
        'email' => is_string($a['admin_email'] ?? null) ? trim($a['admin_email']) : '',
        'hash' => is_string($a['admin_password_hash'] ?? null) ? $a['admin_password_hash'] : '',
        'sal' => is_string($a['panel_sal'] ?? null) ? $a['panel_sal'] : '',
        'dir' => is_string($a['panel_dir'] ?? null) && $a['panel_dir'] !== '' ? $a['panel_dir'] : $cola . '/panel',
        'cola' => $cola,
        'web' => $web,
        'origenes' => is_array($a['origenes'] ?? null) ? array_values(array_filter($a['origenes'], 'is_string')) : ['https://dl-dev.com', 'https://www.dl-dev.com'],
        'ip_cabecera' => (string) ($l['ip_cabecera'] ?? ''),
        'ip_proxies' => (array) ($l['ip_proxies'] ?? []),
        // null (sin clave) = buzón de la empresa; '' = sin aviso
        'aviso_para' => array_key_exists('panel_aviso_para', $a) && $a['panel_aviso_para'] !== null ? $correo($a['panel_aviso_para']) : 'digital-lab@dl-dev.com',
        'aviso_de' => $correo($a['panel_aviso_de'] ?? null) ?: 'digital-lab@dl-dev.com',
    ];
}

// R1: solo argon2id (si este PHP lo soporta) o bcrypt con coste 12 o más
function ps_hash_valido(string $hash): bool
{
    if ($hash === '') return false;
    $i = password_get_info($hash);
    if ($i['algo'] === 'argon2id') return defined('PASSWORD_ARGON2ID');
    if ($i['algo'] === '2y') return (int) ($i['options']['cost'] ?? 0) >= 12;
    return false;
}

// Carpeta privada: existe (o se crea con 0700), se puede escribir y está fuera de public_html. Si no, null.
function ps_carpeta_segura(string $dir, string $web, array $server, bool $crear): ?string
{
    if ($dir === '') return null;
    $norm = static fn(string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/') . '/');
    $dentroDeLaWeb = static function (string $ruta) use ($norm, $server, $web): bool {
        foreach ([(string) ($server['DOCUMENT_ROOT'] ?? ''), $web] as $w) {
            if ($w === '' || ($wr = realpath($w)) === false) continue;
            $a = $norm($ruta); $b = $norm($wr);
            if (str_starts_with($a, $b) || str_starts_with($b, $a)) return true;
        }
        return false;
    };
    if ($crear && !is_dir($dir)) {
        // Antes de crear nada: la ruta (con su antepasado real) no puede caer dentro de la web ni llevar «..»
        $partes = explode('/', str_replace('\\', '/', $dir));
        if (in_array('..', $partes, true)) return null;
        $resto = [];
        $base = $dir;
        while (!is_dir($base) && dirname($base) !== $base) { array_unshift($resto, basename($base)); $base = dirname($base); }
        $baseReal = realpath($base);
        if ($baseReal === false || $dentroDeLaWeb($baseReal . '/' . implode('/', $resto))) return null;
        $viejo = umask(0077);
        @mkdir($dir, 0700, true);
        umask($viejo);
    }
    $real = realpath($dir);
    if ($real === false || !is_dir($real) || !is_writable($real) || $dentroDeLaWeb($real)) return null;
    if (DIRECTORY_SEPARATOR === '/' && (fileperms($real) & 0077) !== 0) {
        @chmod($real, 0700);
        clearstatcache(true, $real);
        if ((fileperms($real) & 0077) !== 0) return null;
    }
    return $real;
}

/**
 * R12: qué funciona con esta configuración.
 * @return array{login:bool, leads:bool, dir:?string, cola:?string, motivo:string}
 */
function ps_estado(array $c, array $server): array
{
    $cred = $c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) && ps_hash_valido($c['hash']);
    $sal = strlen($c['sal']) >= PS_SAL_MIN;
    $dir = $sal ? ps_carpeta_segura($c['dir'], $c['web'], $server, true) : null;
    $cola = ps_carpeta_segura($c['cola'], $c['web'], $server, false);
    $login = $cred && $sal && $dir !== null;
    $motivo = !$cred ? 'credenciales' : (!$sal ? 'sal' : ($dir === null ? 'carpeta' : ($cola === null ? 'cola' : '')));
    if ($motivo !== '') error_log('Digital Lab panel: configuración incompleta (' . $motivo . ')');
    return ['login' => $login, 'leads' => $login && $cola !== null, 'dir' => $dir, 'cola' => $cola, 'motivo' => $motivo];
}

/* ------------------------------------------------------------------ */
/* Cabeceras de toda respuesta (R5)                                     */
/* ------------------------------------------------------------------ */

function ps_cabeceras_lista(): array
{
    return [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
        'X-Frame-Options' => 'DENY',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];
}

// (HTTP)
function ps_cabeceras(): void
{
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    foreach (ps_cabeceras_lista() as $k => $v) header("$k: $v");
}

/* ------------------------------------------------------------------ */
/* Visitante, origen y CSRF                                             */
/* ------------------------------------------------------------------ */

// R2 y R11: código del visitante = HMAC (sal secreta) de la IP real; en IPv6, del prefijo /64. La IP no se guarda.
function ps_visitante(array $server, array $c): string
{
    return substr(hash_hmac('sha256', 'panel|' . demo_clave_ip(demo_ip($server, $c)), $c['sal']), 0, 32);
}

// R6: Origin presente y permitido, y Sec-Fetch-Site distinto de cross-site
function ps_origen_ok(array $server, array $c): bool
{
    $o = $server['HTTP_ORIGIN'] ?? '';
    if (!is_string($o) || $o === '' || !in_array($o, $c['origenes'], true)) return false;
    return strtolower((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')) !== 'cross-site';
}

// R6: POST + origen + token de la sesión comparado con hash_equals
function ps_csrf_ok(array $server, array $c, mixed $token, array $sesion): bool
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!ps_origen_ok($server, $c)) return false;
    $esperado = $sesion['csrf'] ?? '';
    return is_string($token) && is_string($esperado) && strlen($esperado) === 64 && hash_equals($esperado, $token);
}

/* ------------------------------------------------------------------ */
/* Bloqueo progresivo (R2)                                              */
/* ------------------------------------------------------------------ */

/** @return resource|null */
function ps_abrir_contador(string $f)
{
    $viejo = umask(0077);
    $fh = @fopen($f, 'c+');
    umask($viejo);
    if (!$fh) return null;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return null; }
    return $fh;
}

function ps_leer_contador($fh): array
{
    rewind($fh);
    $d = json_decode((string) stream_get_contents($fh), true);
    return is_array($d) ? $d : [];
}

function ps_guardar_contador($fh, array $d): bool
{
    rewind($fh);
    return ftruncate($fh, 0) && fwrite($fh, (string) json_encode($d)) !== false && fflush($fh);
}

function ps_cerrar_contador($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

function ps_carpeta_bloqueo(string $dir): ?string
{
    $b = $dir . '/bloqueo';
    if (!is_dir($b)) { $v = umask(0077); @mkdir($b, 0700); umask($v); }
    return is_dir($b) && is_writable($b) ? $b : null;
}

/**
 * Abre los dos contadores (siempre en este orden: global y luego visitante) y llama a $fn con sus datos.
 * $fn devuelve [global, visitante, resultado]. Si no hay contador, devuelve null (y se niega el login).
 */
function ps_con_contadores(string $dir, string $v, callable $fn): mixed
{
    $b = ps_carpeta_bloqueo($dir);
    if ($b === null || !preg_match('/^[a-f0-9]{32}$/D', $v)) return null;
    $fg = ps_abrir_contador("$b/global.json");
    if ($fg === null) return null;
    $fv = ps_abrir_contador("$b/v-$v.json");
    if ($fv === null) { ps_cerrar_contador($fg); return null; }
    try {
        [$g, $vis, $res] = $fn(ps_leer_contador($fg), ps_leer_contador($fv));
        if ($g !== null && !ps_guardar_contador($fg, $g)) return null;
        if ($vis !== null && !ps_guardar_contador($fv, $vis)) return null;
        return $res;
    } finally {
        ps_cerrar_contador($fv);
        ps_cerrar_contador($fg);
    }
}

/** @return 'libre'|'bloqueado'|null  (null = sin contador) */
function ps_bloqueo_estado(string $dir, string $v, int $ahora): ?string
{
    return ps_con_contadores($dir, $v, static function (array $g, array $vis) use ($ahora): array {
        $bloq = (int) ($g['hasta'] ?? 0) > $ahora || (int) ($vis['hasta'] ?? 0) > $ahora;
        return [null, null, $bloq ? 'bloqueado' : 'libre'];
    });
}

/** @return array{visitante:int, global_nuevo:bool}|null  visitante = segundos de bloqueo puestos ahora (0 = ninguno) */
function ps_registrar_fallo(string $dir, string $v, int $ahora): ?array
{
    return ps_con_contadores($dir, $v, static function (array $g, array $vis) use ($ahora): array {
        $reciente = static fn(array $x, int $ventana): array => array_values(array_filter($x, static fn($t) => is_int($t) && $t > $ahora - $ventana && $t <= $ahora));

        $vf = $reciente((array) ($vis['fallos'] ?? []), PS_VENTANA_VISITANTE);
        $vf[] = $ahora;
        $puesto = 0;
        if (count($vf) >= PS_FALLOS_VISITANTE) {
            // Duplica si el bloqueo anterior terminó hace menos de 24 h; si no, vuelve a 15 min
            $finAnterior = (int) ($vis['hasta'] ?? 0);
            $nivel = $finAnterior > 0 && $ahora - $finAnterior < PS_BLOQUEO_MAX ? min(7, (int) ($vis['nivel'] ?? 0) + 1) : 0;
            $puesto = min(PS_BLOQUEO_BASE << $nivel, PS_BLOQUEO_MAX);
            $vis = ['fallos' => [], 'nivel' => $nivel, 'hasta' => $ahora + $puesto];
        } else {
            $vis['fallos'] = $vf;
        }

        $gf = $reciente((array) ($g['fallos'] ?? []), PS_VENTANA_GLOBAL);
        $gf[] = $ahora;
        $nuevo = false;
        if (count($gf) >= PS_FALLOS_GLOBAL) {
            $g = ['fallos' => [], 'hasta' => $ahora + PS_BLOQUEO_GLOBAL];
            $nuevo = true;
        } else {
            $g['fallos'] = $gf;
        }
        return [$g, $vis, ['visitante' => $puesto, 'global_nuevo' => $nuevo]];
    });
}

// Login correcto: se olvidan los fallos sueltos del visitante (el nivel de bloqueo se conserva 24 h)
function ps_fallos_a_cero(string $dir, string $v): void
{
    ps_con_contadores($dir, $v, static function (array $g, array $vis): array {
        $vis['fallos'] = [];
        return [null, $vis, true];
    });
}

/* ------------------------------------------------------------------ */
/* Registro de accesos (R11) y purga                                    */
/* ------------------------------------------------------------------ */

// Fecha UTC, acción, id de lead, HMAC del visitante y resultado. Nunca datos del lead, IP ni lo tecleado.
function ps_registrar(string $dir, string $accion, string $lead, string $v, string $resultado, int $ahora): void
{
    $r = $dir . '/registro';
    $viejo = umask(0077);
    try {
        if (!is_dir($r)) @mkdir($r, 0700);
        $linea = json_encode([
            't' => gmdate('Y-m-d\TH:i:s\Z', $ahora),
            'accion' => in_array($accion, PS_ACCIONES, true) ? $accion : 'error',
            'lead' => preg_match(PS_ID_LEAD, $lead) ? $lead : '',
            'v' => preg_match('/^[a-f0-9]{32}$/D', $v) ? substr($v, 0, 16) : '',
            'r' => in_array($resultado, PS_RESULTADOS, true) ? $resultado : 'error',
        ]) . "\n";
        $f = "$r/acceso-" . gmdate('Ymd', $ahora) . '.log';
        if (@file_put_contents($f, $linea, FILE_APPEND | LOCK_EX) === false) error_log('Digital Lab panel: no se pudo escribir el registro');
        @chmod($f, 0600);
    } finally {
        umask($viejo);
    }
}

// Borra registros de más de 90 días, contadores de visitante de más de 2 días y sesiones caducadas
function ps_purgar(string $dir, int $ahora): void
{
    foreach (glob("$dir/registro/acceso-*.log") ?: [] as $f) {
        $d = DateTime::createFromFormat('!Ymd', substr(basename($f), 7, 8), new DateTimeZone('UTC'));
        if ($d && $d->getTimestamp() < $ahora - PS_REGISTRO_DIAS * 86400) @unlink($f);
    }
    foreach (glob("$dir/bloqueo/v-*.json") ?: [] as $f) {
        if ((int) @filemtime($f) < $ahora - 2 * PS_BLOQUEO_MAX) @unlink($f);
    }
    foreach (glob("$dir/sesiones/sess_*") ?: [] as $f) {
        if ((int) @filemtime($f) < $ahora - PS_INACTIVIDAD) @unlink($f);
    }
}

/* ------------------------------------------------------------------ */
/* Aviso por correo (R4)                                                 */
/* ------------------------------------------------------------------ */

/** @return array{0:string, 1:string} [asunto, cuerpo] sin IP ni datos de leads */
function ps_aviso_texto(string $tipo, string $v, string $pais, int $ahora): array
{
    $que = $tipo === 'login' ? 'Inicio de sesión correcto en el panel' : 'Bloqueo global del acceso al panel (30 fallos en una hora)';
    $cuerpo = $que . "\n\n"
        . 'Fecha (UTC): ' . gmdate('Y-m-d H:i:s', $ahora) . "\n"
        . 'Resultado: ' . ($tipo === 'login' ? 'acceso concedido' : 'acceso cerrado 15 minutos para todos') . "\n"
        . 'País aproximado: ' . (preg_match('/^[A-Z]{2}$/D', $pais) ? $pais : 'desconocido') . "\n"
        . 'Visitante (código HMAC, no es la IP): ' . substr($v, 0, 12) . "\n\n"
        . ($tipo === 'login'
            ? "Si no has sido tú, cambia la contraseña (admin_password_hash) y borra la carpeta de sesiones del panel.\n"
            : "Alguien está probando contraseñas. Revisa el registro de accesos del panel.\n")
        . "\n— Aviso automático de dl-dev.com\n";
    return ['[dl-dev.com] ' . $que, $cuerpo];
}

// Si el correo falla, el login sigue: devuelve false y lo anota en el registro del servidor
function ps_avisar(array $c, string $tipo, string $v, string $pais, int $ahora, callable $enviar): bool
{
    if ($c['aviso_para'] === '') return false;
    [$asunto, $cuerpo] = ps_aviso_texto($tipo, $v, $pais, $ahora);
    try {
        $ok = (bool) $enviar($c['aviso_para'], $asunto, $cuerpo, $c['aviso_de']);
    } catch (Throwable) {
        $ok = false;
    }
    if (!$ok) error_log('Digital Lab panel: no se pudo enviar el aviso de ' . $tipo);
    return $ok;
}

// (HTTP) Envío con mail() del hosting. Destinatario y remitente salen de la configuración, nunca de la petición.
function ps_enviar_correo(string $para, string $asunto, string $cuerpo, string $de): bool
{
    $cab = "From: Digital Lab <$de>\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nMIME-Version: 1.0";
    return @mail($para, mb_encode_mimeheader($asunto, 'UTF-8'), $cuerpo, $cab);
}

/* ------------------------------------------------------------------ */
/* Login (R1, R2, R4, R11, R12)                                          */
/* ------------------------------------------------------------------ */

/**
 * Procesa un intento de login sin efectos globales: la sesión la abre $abrirSesion (devuelve bool),
 * el correo lo manda $enviar y el país lo da $pais (callable ip → código de 2 letras).
 * @return array{0:int, 1:array<string,mixed>} [código HTTP, cuerpo JSON]
 */
function ps_login(array $server, string $cuerpo, array $c, array $estado, int $ahora, callable $abrirSesion, callable $enviar, ?callable $pais = null): array
{
    $mal = [401, ['ok' => false, 'error' => 'credenciales']];   // R2: respuesta única
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return [405, ['ok' => false, 'error' => 'metodo']];
    if (!ps_origen_ok($server, $c)) return [403, ['ok' => false, 'error' => 'origen']];
    if (!$estado['login']) return [503, ['ok' => false, 'error' => 'sin_servicio']];      // R12
    $dir = (string) $estado['dir'];
    $v = ps_visitante($server, $c);
    ps_purgar($dir, $ahora);

    // R2: bloqueado (por visitante o global) o sin contador → no se comprueba nada
    $bloqueo = ps_bloqueo_estado($dir, $v, $ahora);
    if ($bloqueo === null) { error_log('Digital Lab panel: sin contador de intentos'); return [503, ['ok' => false, 'error' => 'sin_servicio']]; }
    if ($bloqueo === 'bloqueado') {
        password_verify(bin2hex(random_bytes(8)), $c['hash']);          // mismo tiempo que un intento normal
        ps_registrar($dir, 'login', '', $v, 'bloqueado', $ahora);
        return $mal;
    }

    $in = strlen($cuerpo) <= PS_MAX_CUERPO ? json_decode($cuerpo, true, 3) : null;
    $email = is_array($in) && is_string($in['email'] ?? null) ? trim($in['email']) : '';
    $pass = is_array($in) && is_string($in['password'] ?? null) ? $in['password'] : '';
    $trampa = is_array($in) ? ($in['web'] ?? '') : '';
    $largo = strlen($pass);

    // R1: 14+ caracteres y como mucho 1024 bytes; se compara siempre (mismo tiempo) aunque falle antes
    $okPass = password_verify($largo <= PS_PASS_MAX ? $pass : '', $c['hash']);
    $ok = is_array($in) && $trampa === '' && $largo <= PS_PASS_MAX && mb_strlen($pass) >= PS_PASS_MIN
        && hash_equals(strtolower($c['email']), strtolower($email)) && $okPass;

    $ip = demo_ip($server, $c);
    if (!$ok) {
        $r = ps_registrar_fallo($dir, $v, $ahora);
        if ($r === null) return [503, ['ok' => false, 'error' => 'sin_servicio']];
        ps_registrar($dir, 'login', '', $v, $r['visitante'] > 0 ? 'bloqueado' : 'fallo', $ahora);
        if ($r['global_nuevo']) {
            ps_registrar($dir, 'bloqueo_global', '', $v, 'bloqueado', $ahora);
            ps_avisar($c, 'bloqueo_global', $v, $pais ? (string) $pais($ip) : '??', $ahora, $enviar);
        }
        return $mal;
    }

    if (!$abrirSesion()) return [503, ['ok' => false, 'error' => 'sin_servicio']];
    ps_fallos_a_cero($dir, $v);
    ps_registrar($dir, 'login', '', $v, 'ok', $ahora);
    ps_avisar($c, 'login', $v, $pais ? (string) $pais($ip) : '??', $ahora, $enviar);
    return [200, ['ok' => true, 'url' => '/panel/leads.php']];
}

/* ------------------------------------------------------------------ */
/* Sesión (R3)                                                           */
/* ------------------------------------------------------------------ */

// 30 min sin actividad y 8 h como máximo
function ps_sesion_valida(mixed $s, int $ahora): bool
{
    if (!is_array($s) || ($s['admin'] ?? 0) !== 1) return false;
    $ini = $s['inicio'] ?? null; $ult = $s['ultimo'] ?? null;
    if (!is_int($ini) || !is_int($ult) || $ini > $ahora + 60) return false;
    return $ahora - $ini < PS_MAXIMO && $ahora - $ult < PS_INACTIVIDAD && is_string($s['csrf'] ?? null);
}

// (HTTP) Sesiones en <panel_dir>/sesiones (fuera de public_html). Cookie __Host-, Secure, HttpOnly, SameSite=Strict.
function ps_sesion_abrir(string $dir): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) return true;
    $s = $dir . '/sesiones';
    if (!is_dir($s)) { $v = umask(0077); @mkdir($s, 0700); umask($v); }
    if (!is_dir($s) || !is_writable($s)) return false;
    session_save_path($s);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) PS_INACTIVIDAD);
    session_cache_limiter('');
    session_name(PS_COOKIE);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    $v = umask(0077);
    $ok = @session_start();
    umask($v);
    return $ok;
}

// (HTTP) Login correcto: id nuevo (R3), sesión limpia y token CSRF (R6)
function ps_sesion_nueva(string $dir, int $ahora): bool
{
    if (!ps_sesion_abrir($dir)) return false;
    session_regenerate_id(true);
    $_SESSION = ['admin' => 1, 'inicio' => $ahora, 'ultimo' => $ahora, 'csrf' => bin2hex(random_bytes(32))];
    session_write_close();
    return true;
}

/** (HTTP) Sesión válida → sus datos (y anota la actividad); si no, null. */
function ps_sesion_actual(string $dir, int $ahora): ?array
{
    if (!isset($_COOKIE[PS_COOKIE]) || !is_string($_COOKIE[PS_COOKIE])) return null;
    if (!ps_sesion_abrir($dir)) return null;
    if (!ps_sesion_valida($_SESSION, $ahora)) {
        $_SESSION = [];
        session_destroy();
        ps_borrar_cookie();
        return null;
    }
    $_SESSION['ultimo'] = $ahora;
    $s = $_SESSION;
    session_write_close();
    return $s;
}

// (HTTP)
function ps_sesion_cerrar(string $dir): void
{
    if (isset($_COOKIE[PS_COOKIE]) && ps_sesion_abrir($dir)) {
        $_SESSION = [];
        session_destroy();
    }
    ps_borrar_cookie();
}

// (HTTP) Borra la cookie actual y la del panel anterior (dlpanel)
function ps_borrar_cookie(): void
{
    if (headers_sent()) return;
    foreach ([PS_COOKIE, 'dlpanel'] as $n) {
        setcookie($n, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    }
}

/* ------------------------------------------------------------------ */
/* Salidas HTTP comunes                                                  */
/* ------------------------------------------------------------------ */

// (HTTP)
function ps_json(int $codigo, array $datos): never
{
    ps_cabeceras();
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
