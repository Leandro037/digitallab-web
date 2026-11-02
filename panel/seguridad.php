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
 *
 * Tarea 54 · cambiar la contraseña desde el panel (/panel/contrasena.php). El hash nuevo NO se escribe en
 * dl-auditoria-config.php (carpeta personal, 0644, con otras claves de API): va a <panel_dir>/credencial.json
 * (0600, escritura atómica con flock). Qué hash manda:
 *   - credencial.json, si existe, es válido y su «base» es el sha256 del admin_password_hash ACTUAL del config;
 *   - si no, admin_password_hash del config. Así, poner una contraseña nueva con scripts/hash-contrasena.py
 *     (cambia el hash del config) deja sin efecto la cambiada en el panel sin tocar credencial.json: es el
 *     camino de recuperación si se olvida o si otra persona la cambió.
 * Cada sesión guarda la «huella» (HMAC con panel_sal) del hash vigente: si el hash cambia, por el panel o por el
 * script, todas las sesiones anteriores dejan de valer.
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
const PS_MAX_CUERPO_CAMBIO = 8192;     // bytes del JSON del cambio de contraseña (3 × 1024 + token)
const PS_CREDENCIAL = 'credencial.json';
const PS_ACCIONES = ['login', 'bloqueo_global', 'lista', 'ficha', 'atendido', 'nuevo', 'csv', 'salir', 'contrasena'];
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
 * hash = hash vigente (credencial.json o config, ver cabecera); huella = HMAC del hash vigente que guarda cada
 * sesión (si el hash cambia, las sesiones anteriores dejan de valer). Ambos vacíos si el login está cerrado.
 * @return array{login:bool, leads:bool, dir:?string, cola:?string, motivo:string, hash:string, origen_hash:string, huella:string}
 */
function ps_estado(array $c, array $server): array
{
    $sal = strlen($c['sal']) >= PS_SAL_MIN;
    $dir = $sal ? ps_carpeta_segura($c['dir'], $c['web'], $server, true) : null;
    $hash = $c['hash'];
    $origen = 'config';
    if ($dir !== null && ($h = ps_credencial_leer($dir, $c['hash'])) !== null) { $hash = $h; $origen = 'panel'; }
    $cred = $c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) && ps_hash_valido($hash);
    $cola = ps_carpeta_segura($c['cola'], $c['web'], $server, false);
    $login = $cred && $sal && $dir !== null;
    $motivo = !$cred ? 'credenciales' : (!$sal ? 'sal' : ($dir === null ? 'carpeta' : ($cola === null ? 'cola' : '')));
    if ($motivo !== '') error_log('Digital Lab panel: configuración incompleta (' . $motivo . ')');
    return ['login' => $login, 'leads' => $login && $cola !== null, 'dir' => $dir, 'cola' => $cola, 'motivo' => $motivo,
        'hash' => $login ? $hash : '', 'origen_hash' => $origen, 'huella' => $login ? ps_huella($hash, $c['sal']) : ''];
}

// Huella del hash vigente para las sesiones: HMAC con la sal secreta (no deja ver ni el hash)
function ps_huella(string $hash, string $sal): string
{
    return substr(hash_hmac('sha256', 'sesion|' . $hash, $sal), 0, 32);
}

/* ------------------------------------------------------------------ */
/* Contraseña cambiada en el panel: <panel_dir>/credencial.json (t. 54) */
/* ------------------------------------------------------------------ */

/**
 * Hash guardado por el panel, o null si no hay, no es válido o el config cambió después (entonces manda el config).
 * Formato: {"formato":1, "hash":"$argon2id$…", "base":"<sha256 del admin_password_hash del config al cambiarla>", "cambiado":"…Z"}
 */
function ps_credencial_leer(string $dir, string $hashConfig): ?string
{
    $f = $dir . '/' . PS_CREDENCIAL;
    if ($hashConfig === '' || !is_file($f)) return null;
    if (is_link($f)) { error_log('Digital Lab panel: credencial.json es un enlace; se ignora'); return null; }
    $t = @file_get_contents($f, false, null, 0, 4097);
    $d = is_string($t) && strlen($t) <= 4096 ? json_decode($t, true, 3) : null;
    if (!is_array($d) || ($d['formato'] ?? null) !== 1 || !is_string($d['hash'] ?? null) || !is_string($d['base'] ?? null)) {
        error_log('Digital Lab panel: credencial.json no es válido; manda admin_password_hash');
        return null;
    }
    if (!hash_equals(hash('sha256', $hashConfig), $d['base'])) return null;   // el config se cambió después: manda el config
    if (!ps_hash_valido($d['hash'])) { error_log('Digital Lab panel: credencial.json con un hash no admitido; manda admin_password_hash'); return null; }
    return $d['hash'];
}

/** Bloqueo exclusivo para leer-comprobar-escribir la credencial (dos cambios a la vez no se pisan). @return resource|null */
function ps_credencial_bloquear(string $dir)
{
    return ps_abrir_contador($dir . '/credencial.lock');
}

// Escritura atómica: temporal 0600 en la misma carpeta + rename. Llamar con el bloqueo puesto.
function ps_credencial_guardar(string $dir, string $hash, string $hashConfig, int $ahora): bool
{
    $json = json_encode(['formato' => 1, 'hash' => $hash, 'base' => hash('sha256', $hashConfig), 'cambiado' => gmdate('Y-m-d\TH:i:s\Z', $ahora)]);
    $tmp = $dir . '/.tmp-credencial-' . bin2hex(random_bytes(8));
    $viejo = umask(0077);
    try {
        $fh = @fopen($tmp, 'x');
        if (!$fh) return false;
        $ok = fwrite($fh, (string) $json) === strlen((string) $json) && fflush($fh);
        fclose($fh);
        @chmod($tmp, 0600);
        if (!$ok || !@rename($tmp, $dir . '/' . PS_CREDENCIAL)) { @unlink($tmp); return false; }
        return true;
    } finally {
        umask($viejo);
    }
}

// R1: argon2id si este PHP lo tiene; si no, bcrypt coste 12. Se comprueba antes de guardarlo.
function ps_hash_nuevo(string $pass): ?string
{
    $h = defined('PASSWORD_ARGON2ID') ? password_hash($pass, PASSWORD_ARGON2ID) : password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    return is_string($h) && ps_hash_valido($h) && password_verify($pass, $h) ? $h : null;
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

/**
 * Suma un fallo a los contadores (función pura). visitante = segundos de bloqueo puestos ahora (0 = ninguno).
 * @return array{0:array, 1:array, 2:array{visitante:int, global_nuevo:bool}}
 */
function ps_sumar_fallo(array $g, array $vis, int $ahora): array
{
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
}

/**
 * R2 (H2 de la tarea 51): en UNA sola sección bajo flock se mira el bloqueo y, si está libre, se anota el
 * intento como fallo ANTES de comprobar la contraseña. Así N peticiones simultáneas no pueden pasar todas
 * el control: como mucho se comprueban 5 por visitante (y 30 en total) antes de que salte el bloqueo.
 * Si la contraseña resulta correcta, ps_anular_reserva() deshace la anotación.
 * @return array{estado:string, visitante?:int, global_nuevo?:bool, previo?:array}|null  null = sin contador
 */
function ps_reservar_intento(string $dir, string $v, int $ahora): ?array
{
    return ps_con_contadores($dir, $v, static function (array $g, array $vis) use ($ahora): array {
        if ((int) ($g['hasta'] ?? 0) > $ahora || (int) ($vis['hasta'] ?? 0) > $ahora) {
            return [null, null, ['estado' => 'bloqueado']];
        }
        [$g2, $vis2, $r] = ps_sumar_fallo($g, $vis, $ahora);
        return [$g2, $vis2, ['estado' => 'reservado'] + $r + ['previo' => ['g' => $g, 'vis' => $vis]]];
    });
}

/**
 * Login correcto: se olvidan los fallos sueltos del visitante (el nivel de bloqueo se conserva 24 h) y el
 * intento reservado deja de contar. Si fue ESTA reserva la que puso un bloqueo (de visitante o global), se
 * quita y se vuelve al estado anterior; un bloqueo puesto entretanto por otra petición se respeta.
 */
function ps_anular_reserva(string $dir, string $v, int $ahora, array $r): bool
{
    return (bool) ps_con_contadores($dir, $v, static function (array $g, array $vis) use ($ahora, $r): array {
        $vis['fallos'] = [];
        if (($r['visitante'] ?? 0) > 0 && (int) ($vis['hasta'] ?? 0) === $ahora + $r['visitante']) {
            $antes = (array) ($r['previo']['vis'] ?? []);
            $vis['nivel'] = (int) ($antes['nivel'] ?? 0);
            $vis['hasta'] = (int) ($antes['hasta'] ?? 0);
        }
        if (($r['global_nuevo'] ?? false) && (int) ($g['hasta'] ?? 0) === $ahora + PS_BLOQUEO_GLOBAL) {
            $g = (array) ($r['previo']['g'] ?? []);
        } else {
            $f = array_values((array) ($g['fallos'] ?? []));
            $i = array_search($ahora, $f, true);
            if ($i !== false) { array_splice($f, (int) $i, 1); $g['fallos'] = $f; }
        }
        return [$g, $vis, true];
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
    foreach (glob("$dir/.tmp-credencial-*") ?: [] as $f) {          // temporal de un cambio interrumpido
        if ((int) @filemtime($f) < $ahora - 3600) @unlink($f);
    }
}

/* ------------------------------------------------------------------ */
/* Aviso por correo (R4)                                                 */
/* ------------------------------------------------------------------ */

/** @return array{0:string, 1:string} [asunto, cuerpo] sin IP ni datos de leads */
function ps_aviso_texto(string $tipo, string $v, string $pais, int $ahora): array
{
    [$que, $resultado, $consejo] = match ($tipo) {
        'contrasena' => ['Se cambió la contraseña del panel', 'contraseña nueva guardada; las demás sesiones del panel se cerraron',
            "Si no fuiste tú, alguien tiene acceso al panel: pon una contraseña nueva desde tu PC con\n"
            . "scripts/hash-contrasena.py --subir (deja sin efecto la cambiada en el panel y cierra todas las sesiones)\n"
            . "y revisa el registro de accesos.\n"],
        default => ['Bloqueo global del acceso al panel (30 fallos en una hora)', 'acceso cerrado 15 minutos para todos',
            "Alguien está probando contraseñas. Revisa el registro de accesos del panel.\n"],
    };
    $cuerpo = $que . "\n\n"
        . 'Fecha (UTC): ' . gmdate('Y-m-d H:i:s', $ahora) . "\n"
        . 'Resultado: ' . $resultado . "\n"
        . 'País aproximado: ' . (preg_match('/^[A-Z]{2}$/D', $pais) ? $pais : 'desconocido') . "\n"
        . 'Visitante (código HMAC, no es la IP): ' . substr($v, 0, 12) . "\n\n"
        . $consejo
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

    // R2: el intento se reserva (cuenta como fallo) bajo flock ANTES de comprobar nada. Bloqueado (por
    // visitante o global) o sin contador → no se comprueba la contraseña.
    $reserva = ps_reservar_intento($dir, $v, $ahora);
    if ($reserva === null) { error_log('Digital Lab panel: sin contador de intentos'); return [503, ['ok' => false, 'error' => 'sin_servicio']]; }
    if ($reserva['estado'] === 'bloqueado') {
        password_verify(bin2hex(random_bytes(8)), $estado['hash']);     // mismo tiempo que un intento normal
        ps_registrar($dir, 'login', '', $v, 'bloqueado', $ahora);
        return $mal;
    }

    $in = strlen($cuerpo) <= PS_MAX_CUERPO ? json_decode($cuerpo, true, 3) : null;
    $email = is_array($in) && is_string($in['email'] ?? null) ? trim($in['email']) : '';
    $pass = is_array($in) && is_string($in['password'] ?? null) ? $in['password'] : '';
    $trampa = is_array($in) ? ($in['web'] ?? '') : '';
    $largo = strlen($pass);

    // R1: 14+ caracteres y como mucho 1024 bytes; se compara siempre (mismo tiempo) aunque falle antes
    $okPass = password_verify($largo <= PS_PASS_MAX ? $pass : '', $estado['hash']);   // hash vigente (credencial.json o config)
    $ok = is_array($in) && $trampa === '' && $largo <= PS_PASS_MAX && mb_strlen($pass) >= PS_PASS_MIN
        && hash_equals(strtolower($c['email']), strtolower($email)) && $okPass;

    $ip = demo_ip($server, $c);
    if (!$ok) {
        // El fallo ya quedó anotado en la reserva
        ps_registrar($dir, 'login', '', $v, $reserva['visitante'] > 0 ? 'bloqueado' : 'fallo', $ahora);
        if ($reserva['global_nuevo']) {
            ps_registrar($dir, 'bloqueo_global', '', $v, 'bloqueado', $ahora);
            ps_avisar($c, 'bloqueo_global', $v, $pais ? (string) $pais($ip) : '??', $ahora, $enviar);
        }
        return $mal;
    }

    if (!ps_anular_reserva($dir, $v, $ahora, $reserva)) error_log('Digital Lab panel: no se pudo anular la reserva del intento');
    if (!$abrirSesion()) return [503, ['ok' => false, 'error' => 'sin_servicio']];
    // Decisión del propietario: un inicio de sesión correcto ya NO manda correo (queda en el registro de accesos). Siguen los avisos de
    // bloqueo global y de cambio de contraseña, que sí indican algo raro.
    ps_registrar($dir, 'login', '', $v, 'ok', $ahora);
    return [200, ['ok' => true, 'url' => '/panel/leads.php']];
}

/* ------------------------------------------------------------------ */
/* Cambiar la contraseña desde el panel (tarea 54)                       */
/* ------------------------------------------------------------------ */

/**
 * Procesa un cambio de contraseña de una sesión YA válida, sin efectos globales: $rotarSesion(huella nueva)
 * regenera el id, pone la huella y un token nuevos y cierra las demás sesiones (devuelve ['csrf', 'cerradas'] o
 * null); el correo lo manda $enviar y el país lo da $pais.
 * Orden: método → CSRF + Origin + X-DL-Accion (R6) → configuración (R12) → formato de la nueva (no cuenta como
 * intento: no comprueba nada) → reserva del intento en los MISMOS contadores que el login (R2) → bajo flock,
 * comprobar la actual con el hash vigente → «distinta de la actual» (solo tras acertar: si no, sería un
 * oráculo) → hash nuevo → credencial.json → sesión → registro → aviso. Nunca devuelve ni registra contraseñas.
 * @return array{0:int, 1:array<string,mixed>} [código HTTP, cuerpo JSON]
 */
function ps_cambiar_contrasena(array $server, string $cuerpo, array $c, array $estado, array $sesion, int $ahora,
    callable $rotarSesion, callable $enviar, ?callable $pais = null): array
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return [405, ['ok' => false, 'error' => 'metodo']];
    $in = strlen($cuerpo) <= PS_MAX_CUERPO_CAMBIO ? json_decode($cuerpo, true, 3) : null;
    $in = is_array($in) ? $in : [];
    $dir = (string) ($estado['dir'] ?? '');
    $v = $dir !== '' ? ps_visitante($server, $c) : '';
    if (!ps_csrf_ok($server, $c, $in['csrf'] ?? null, $sesion) || !ps_es_accion_usuario($server)) {
        if ($dir !== '') ps_registrar($dir, 'contrasena', '', $v, 'csrf', $ahora);
        return [403, ['ok' => false, 'error' => 'csrf']];
    }
    if (!$estado['login']) return [503, ['ok' => false, 'error' => 'sin_servicio']];   // R12

    $txt = static fn(string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';
    $actual = $txt('actual'); $nueva = $txt('nueva'); $repite = $txt('repite');
    // Formato: no cuenta como intento porque no se compara nada con la contraseña guardada
    if ($actual === '') return [400, ['ok' => false, 'error' => 'actual_vacia', 'campo' => 'actual']];
    if (mb_strlen($nueva) < PS_PASS_MIN) return [400, ['ok' => false, 'error' => 'corta', 'campo' => 'nueva']];
    if (strlen($nueva) > PS_PASS_MAX) return [400, ['ok' => false, 'error' => 'larga', 'campo' => 'nueva']];
    if (!hash_equals($nueva, $repite)) return [400, ['ok' => false, 'error' => 'no_coinciden', 'campo' => 'repite']];

    // R2: la contraseña actual cuenta como un intento de login (mismo visitante, mismos contadores)
    $reserva = ps_reservar_intento($dir, $v, $ahora);
    if ($reserva === null) { error_log('Digital Lab panel: sin contador de intentos'); return [503, ['ok' => false, 'error' => 'sin_servicio']]; }
    if ($reserva['estado'] === 'bloqueado') {
        password_verify(bin2hex(random_bytes(8)), $estado['hash']);
        ps_registrar($dir, 'contrasena', '', $v, 'bloqueado', $ahora);
        return [429, ['ok' => false, 'error' => 'bloqueado', 'campo' => 'actual']];
    }

    $fh = ps_credencial_bloquear($dir);
    if ($fh === null) {
        ps_anular_reserva($dir, $v, $ahora, $reserva);
        error_log('Digital Lab panel: no se pudo bloquear credencial.lock');
        return [503, ['ok' => false, 'error' => 'sin_servicio']];
    }
    try {
        $vigente = ps_credencial_leer($dir, $c['hash']) ?? $c['hash'];          // releído bajo el bloqueo
        $largo = strlen($actual);
        $okPass = password_verify($largo <= PS_PASS_MAX ? $actual : '', $vigente);
        if (!($okPass && $largo <= PS_PASS_MAX && mb_strlen($actual) >= PS_PASS_MIN && ps_hash_valido($vigente))) {
            $bloquea = $reserva['visitante'] > 0;
            ps_registrar($dir, 'contrasena', '', $v, $bloquea ? 'bloqueado' : 'fallo', $ahora);
            if ($reserva['global_nuevo']) {
                ps_registrar($dir, 'bloqueo_global', '', $v, 'bloqueado', $ahora);
                ps_avisar($c, 'bloqueo_global', $v, $pais ? (string) $pais(demo_ip($server, $c)) : '??', $ahora, $enviar);
            }
            return $bloquea ? [429, ['ok' => false, 'error' => 'bloqueado', 'campo' => 'actual']] : [400, ['ok' => false, 'error' => 'actual', 'campo' => 'actual']];
        }
        if (!ps_anular_reserva($dir, $v, $ahora, $reserva)) error_log('Digital Lab panel: no se pudo anular la reserva del intento');
        if (hash_equals($actual, $nueva)) return [400, ['ok' => false, 'error' => 'igual', 'campo' => 'nueva']];
        $hash = ps_hash_nuevo($nueva);
        if ($hash === null || !ps_credencial_guardar($dir, $hash, $c['hash'], $ahora)) {
            error_log('Digital Lab panel: no se pudo guardar la contraseña nueva');
            ps_registrar($dir, 'contrasena', '', $v, 'error', $ahora);
            return [503, ['ok' => false, 'error' => 'sin_servicio']];
        }
    } finally {
        ps_cerrar_contador($fh);
    }

    $r = $rotarSesion(ps_huella($hash, $c['sal']));
    ps_registrar($dir, 'contrasena', '', $v, 'ok', $ahora);
    $aviso = ps_avisar($c, 'contrasena', $v, $pais ? (string) $pais(demo_ip($server, $c)) : '??', $ahora, $enviar);
    if (!is_array($r)) return [200, ['ok' => true, 'sesion' => false, 'aviso' => $aviso]];   // cambiada; hay que volver a entrar
    return [200, ['ok' => true, 'sesion' => true, 'csrf' => (string) $r['csrf'], 'cerradas' => (int) $r['cerradas'], 'aviso' => $aviso]];
}

/* ------------------------------------------------------------------ */
/* Sesión (R3)                                                           */
/* ------------------------------------------------------------------ */

// 30 min sin actividad y 8 h como máximo. Con $huella (tarea 54): además, abierta con el hash vigente.
function ps_sesion_valida(mixed $s, int $ahora, ?string $huella = null): bool
{
    if (!is_array($s) || ($s['admin'] ?? 0) !== 1) return false;
    $ini = $s['inicio'] ?? null; $ult = $s['ultimo'] ?? null;
    if (!is_int($ini) || !is_int($ult) || $ini > $ahora + 60) return false;
    if ($huella !== null && ($huella === '' || !is_string($s['huella'] ?? null) || !hash_equals($huella, $s['huella']))) return false;
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

// (HTTP) Login correcto: id nuevo (R3), sesión limpia, token CSRF (R6) y huella del hash vigente (tarea 54).
// $e = ps_estado().
function ps_sesion_nueva(array $e, int $ahora): bool
{
    if (($e['huella'] ?? '') === '' || !ps_sesion_abrir((string) $e['dir'])) return false;
    session_regenerate_id(true);
    $_SESSION = ['admin' => 1, 'inicio' => $ahora, 'ultimo' => $ahora, 'csrf' => bin2hex(random_bytes(32)), 'huella' => $e['huella']];
    session_write_close();
    return true;
}

/**
 * (HTTP) Sesión válida → sus datos; si no, null. $e = ps_estado() (carpeta y huella del hash vigente).
 * $renovar: anota la actividad (R3, 30 min de inactividad). Solo para acciones del usuario: navegar a una
 * página, filtrar, abrir una ficha, marcar, descargar. Las llamadas automáticas (el sondeo de las
 * estadísticas cada 60 s) NO renuevan (H1 de la tarea 51): con la pestaña olvidada la sesión caduca a los 30 min.
 * Una sesión abierta con otro hash (contraseña cambiada después, por el panel o por el script) no vale.
 */
function ps_sesion_actual(array $e, int $ahora, bool $renovar = true): ?array
{
    $dir = (string) ($e['dir'] ?? '');
    if (!isset($_COOKIE[PS_COOKIE]) || !is_string($_COOKIE[PS_COOKIE])) return null;
    if ($dir === '' || !ps_sesion_abrir($dir)) return null;
    if (!ps_sesion_valida($_SESSION, $ahora, (string) ($e['huella'] ?? ''))) {
        $_SESSION = [];
        session_destroy();
        ps_borrar_cookie();
        return null;
    }
    if (!$renovar) {
        $s = $_SESSION;
        session_abort();                    // sin escribir: ni «ultimo» ni la fecha del archivo de sesión cambian
        return $s;
    }
    $_SESSION['ultimo'] = $ahora;
    $s = $_SESSION;
    session_write_close();
    return $s;
}

/**
 * H1: las peticiones fetch del panel declaran si las pidió el usuario con «X-DL-Accion: 1». Sin la cabecera
 * (sondeo automático, o cualquier llamada futura que no la ponga) la sesión no se renueva: falla hacia el lado
 * seguro. Una cabecera propia no se puede mandar desde otro sitio sin CORS (y además hace falta la cookie).
 */
function ps_es_accion_usuario(array $server): bool
{
    return ($server['HTTP_X_DL_ACCION'] ?? '') === '1';
}

/**
 * (HTTP) Tras cambiar la contraseña (tarea 54): la sesión actual sigue, con id nuevo (el anterior se borra),
 * la huella del hash nuevo y un token CSRF nuevo; todas las demás sesiones del panel se borran.
 * @return array{csrf:string, cerradas:int}|null  null = no se pudo (habrá que volver a entrar)
 */
function ps_sesion_rotar(array $e, string $huella, int $ahora): ?array
{
    $dir = (string) ($e['dir'] ?? '');
    if ($dir === '' || $huella === '' || !isset($_COOKIE[PS_COOKIE]) || !ps_sesion_abrir($dir)) return null;
    if (!ps_sesion_valida($_SESSION, $ahora)) { session_abort(); return null; }
    if (!session_regenerate_id(true)) { session_abort(); return null; }
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['huella'] = $huella;
    $_SESSION['csrf'] = $csrf;
    $_SESSION['ultimo'] = $ahora;
    $id = session_id();
    session_write_close();
    return ['csrf' => $csrf, 'cerradas' => ps_sesiones_cerrar_otras($dir, $id)];
}

// Borra los archivos de sesión del panel salvo el de $idActual. Devuelve cuántos borró.
function ps_sesiones_cerrar_otras(string $dir, string $idActual): int
{
    $n = 0;
    foreach (glob("$dir/sesiones/sess_*") ?: [] as $f) {
        if (basename($f) === 'sess_' . $idActual) continue;
        if (@unlink($f)) $n++;
    }
    return $n;
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
