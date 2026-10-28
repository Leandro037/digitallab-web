<?php
/*
 * Digital Lab · /demo · recepción del formulario «Pide tu demo» (tarea 5)
 *
 * Recibe un POST con JSON desde dl-dev.com/demo/, lo valida y guarda UN archivo JSON por solicitud
 * en la cola privada (/dl-leads, junto a public_html, nunca dentro de la web), con permisos 0600.
 * La recoge el ordenador de Digital Lab (pendientes.php / confirmar.php, tarea 14).
 *
 * Defensas (docs/stack.md §1): solo POST con JSON y desde nuestro origen; 5 envíos por hora por
 * visitante y 100 al día en total; campo trampa «web»; tiempo mínimo «t» (3 s) y máximo (2 h);
 * consentimiento «consent_respuesta» === true; listas cerradas; longitudes limitadas.
 * La IP no se guarda: para el límite por hora se usa un código HMAC con sal secreta que cambia cada
 * día y se borra como mucho una hora después del último intento (y siempre antes de 24 h).
 *
 * Configuración: /dl-leads/config.php (fuera de public_html y de Git). Ejemplo en
 * privado/dl-leads/config.example.php del repositorio. Sin configuración válida responde 503.
 *
 * Respuestas (JSON, siempre con «ok»):
 *   200 {ok:true}                                     éxito (también, sin guardar, para robots)
 *   400 {ok:false, error:'campos', campos:{…}}        error por campo (falta | largo | invalido)
 *   400 {ok:false, error:'datos'}                     petición mal formada
 *   400 {ok:false, error:'tiempo'}                    formulario abierto más de 2 h: reintentar
 *   403 {ok:false, error:'origen'}                    origen ajeno
 *   405 {ok:false, error:'metodo'}                    no es POST
 *   409 {ok:false, error:'version'}                   versión del consentimiento desconocida (página antigua)
 *   413 {ok:false, error:'datos'}                     cuerpo demasiado grande
 *   415 {ok:false, error:'tipo'}                      no es application/json
 *   429 {ok:false, error:'limite'}                    5/hora por visitante o 100/día (con Retry-After)
 *   500 {ok:false, error:'error'}                     fallo al guardar
 *   503 {ok:false, error:'sin_servicio'}              falta configuración o la cola no es segura
 */
declare(strict_types=1);

const DEMO_MAX_CUERPO = 16384;              // bytes
const DEMO_T_MIN = 3000;                    // ms: más rápido que esto es un robot
const DEMO_T_MAX = 7200000;                 // ms: 2 horas
const DEMO_VENTANA = 3600;                  // s: ventana del límite por visitante
const DEMO_CONSERVACION = 30 * 86400;       // s: la solicitud espera como máximo 30 días (privacidad)

const DEMO_PAISES = ['Argentina', 'España', 'Chile', 'Colombia', 'México', 'Perú', 'Uruguay', 'Otro país'];
const DEMO_TAMANOS = ['1', '2-10', '11-50', '51+'];
const DEMO_CLAUDE_CODE = ['si', 'no', 'nose'];
const DEMO_CTA = ['nav', 'hero', 'precio', 'final'];
const DEMO_UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

/* ------------------------------------------------------------------ */
/* Configuración                                                        */
/* ------------------------------------------------------------------ */

function demo_cargar_config(): array
{
    // demo.php vive en public_html/demo/: dos niveles arriba está la carpeta personal, hermana de public_html
    $base = dirname(__DIR__, 2) . '/dl-leads';
    $ruta = $base . '/config.php';
    $c = is_file($ruta) ? require $ruta : [];
    if (!is_array($c)) $c = [];
    $c['cola_dir'] = (string) ($c['cola_dir'] ?? '') !== '' ? (string) $c['cola_dir'] : $base;
    $c['web_dir'] = dirname(__DIR__);       // public_html: la cola nunca puede estar dentro
    return $c;
}

/* ------------------------------------------------------------------ */
/* Proceso de una petición (sin efectos globales: lo usan las pruebas)  */
/* ------------------------------------------------------------------ */

/**
 * @return array{0:int, 1:array<string,mixed>, 2:array<string,string>} [código HTTP, cuerpo, cabeceras extra]
 */
function demo_procesar(array $server, string $cuerpo, array $cfg, int $ahora): array
{
    // 1. Método, tipo y origen
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return [405, ['ok' => false, 'error' => 'metodo'], ['Allow' => 'POST']];
    $origenes = (array) ($cfg['origenes'] ?? ['https://dl-dev.com', 'https://www.dl-dev.com']);
    $origen = (string) ($server['HTTP_ORIGIN'] ?? '');
    if ($origen === '' || !in_array($origen, $origenes, true)) return demo_error('origen', 403);
    if (strtolower((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') return demo_error('origen', 403);
    $tipo = strtolower(trim(explode(';', (string) ($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? ''))[0]));
    if ($tipo !== 'application/json') return demo_error('tipo', 415);

    // 2. Configuración y cola segura (si algo falla, no se guarda nada)
    $cola = demo_cola_segura($cfg, $server);
    $sal = (string) ($cfg['sal'] ?? '');
    if ($cola === null || strlen($sal) < 32) {
        error_log('Digital Lab demo: falta configuración o la cola no es segura');
        return demo_error('sin_servicio', 503);
    }
    $limites = $cola . '/limites';
    if (!is_dir($limites) && !@mkdir($limites, 0700) && !is_dir($limites)) {
        error_log('Digital Lab demo: no se puede crear la carpeta de límites');
        return demo_error('sin_servicio', 503);
    }
    demo_purgar($cola, $ahora);

    // 3. Límite por visitante (cuenta todos los intentos, también los de robots)
    $ip = demo_ip($server, $cfg);
    $porHora = max(1, (int) ($cfg['limite_hora'] ?? 5));
    if (!demo_limite_visitante($limites, $ip, $sal, $porHora, $ahora)) {
        return demo_error('limite', 429, ['Retry-After' => (string) DEMO_VENTANA]);
    }

    // 4. Cuerpo JSON
    if (strlen($cuerpo) > DEMO_MAX_CUERPO) return demo_error('datos', 413);
    try {
        $in = json_decode($cuerpo, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return demo_error('datos', 400);
    }
    if (!is_array($in) || array_is_list($in)) return demo_error('datos', 400);

    // 5. Campo trampa: respuesta de éxito, sin guardar nada (no se le enseña al robot qué falló)
    $trampa = $in['web'] ?? '';
    if (!is_string($trampa) || trim($trampa) !== '') return [200, ['ok' => true], []];

    // 6. Tiempo desde que se abrió el formulario (ms medidos en el navegador)
    $t = $in['t'] ?? null;
    if (is_string($t) && preg_match('/^\d{1,10}$/D', $t)) $t = (int) $t;
    elseif (is_float($t) && is_finite($t) && $t >= 0 && $t < 1e10) $t = (int) $t;
    if (!is_int($t) || $t < 0) return demo_error('datos', 400);
    if ($t < DEMO_T_MIN) return [200, ['ok' => true], []];
    if ($t > DEMO_T_MAX) return demo_error('tiempo', 400);

    // 7. Versión del consentimiento: solo las publicadas
    $version = $in['consent_version'] ?? '';
    $versiones = (array) ($cfg['consent_versiones'] ?? ['34-2026-10-28']);
    if (!is_string($version) || !in_array($version, $versiones, true)) return demo_error('version', 409);

    // 8. Campos
    [$lead, $errores] = demo_validar($in);
    if ($errores) return [400, ['ok' => false, 'error' => 'campos', 'campos' => $errores], []];

    // 9. Tope diario y guardado
    $porDia = max(1, (int) ($cfg['limite_dia'] ?? 100));
    $fecha = gmdate('Y-m-d\TH:i:s\Z', $ahora);
    $lead = ['formato' => 1, 'id' => '', 'recibido' => $fecha] + $lead + [
        'consent_respuesta' => true,
        'consent_novedades' => ($in['consent_novedades'] ?? false) === true,
        'consent_version' => $version,
        'consent_fecha' => $fecha,
    ];
    $r = demo_guardar_con_tope($cola, $limites, $lead, $porDia, $ahora);
    if ($r === 'limite') {
        $medianoche = intdiv($ahora, 86400) * 86400 + 86400;
        return demo_error('limite', 429, ['Retry-After' => (string) max(60, $medianoche - $ahora)]);
    }
    if ($r === 'error') return demo_error('error', 500);
    return [200, ['ok' => true], []];
}

function demo_error(string $codigo, int $http, array $cabeceras = []): array
{
    return [$http, ['ok' => false, 'error' => $codigo], $cabeceras];
}

/* ------------------------------------------------------------------ */
/* Validación                                                           */
/* ------------------------------------------------------------------ */

/** @return array{0:array<string,mixed>, 1:array<string,string>} [datos limpios, errores por campo] */
function demo_validar(array $in): array
{
    $e = [];

    $nombre = demo_linea($in['nombre'] ?? null);
    if ($nombre === null || $nombre === '') $e['nombre'] = 'falta';
    elseif (mb_strlen($nombre) > 100) $e['nombre'] = 'largo';

    $email = demo_linea($in['email'] ?? null);
    if ($email === null || $email === '') $e['email'] = 'falta';
    elseif (strlen($email) > 254) $e['email'] = 'largo';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'invalido';

    $empresa = demo_linea($in['empresa'] ?? null);
    if ($empresa === null || $empresa === '') $e['empresa'] = 'falta';
    elseif (mb_strlen($empresa) > 120) $e['empresa'] = 'largo';

    $cc = $in['usa_claude_code'] ?? null;
    if (!is_string($cc) || !in_array($cc, DEMO_CLAUDE_CODE, true)) $e['usa_claude_code'] = 'falta';

    $necesidad = demo_texto($in['necesidad'] ?? '');
    if ($necesidad === null) $e['necesidad'] = 'invalido';
    elseif (mb_strlen($necesidad) > 1000) $e['necesidad'] = 'largo';

    if (($in['consent_respuesta'] ?? null) !== true) $e['consent_respuesta'] = 'falta';

    // Opcionales de lista cerrada: un valor ajeno (manipulado) se guarda vacío
    $pais = $in['pais'] ?? '';
    $tamano = $in['tamano_equipo'] ?? '';
    $cta = $in['cta_origen'] ?? '';

    $lead = [
        'nombre' => (string) $nombre,
        'email' => (string) $email,
        'empresa' => (string) $empresa,
        'pais' => is_string($pais) && in_array($pais, DEMO_PAISES, true) ? $pais : '',
        'tamano_equipo' => is_string($tamano) && in_array($tamano, DEMO_TAMANOS, true) ? $tamano : '',
        'usa_claude_code' => is_string($cc) ? $cc : '',
        'necesidad' => (string) $necesidad,
        'cta_origen' => is_string($cta) && in_array($cta, DEMO_CTA, true) ? $cta : 'final',
    ];
    foreach (DEMO_UTM as $k) $lead[$k] = demo_utm($in[$k] ?? '');
    return [$lead, $e];
}

// UTM: ≤ 60 caracteres y solo [A-Za-z0-9_-]; cualquier otra cosa se guarda vacía
function demo_utm(mixed $v): string
{
    return is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $v) ? $v : '';
}

// Caracteres invisibles que pueden disfrazar un texto (cambio de dirección, ancho cero, BOM)
const DEMO_INVISIBLES = '/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

// Una línea: sin caracteres de control ni invisibles, espacios seguidos reducidos a uno. null si no es texto.
function demo_linea(mixed $v): ?string
{
    if (!is_string($v)) return null;
    $v = preg_replace(DEMO_INVISIBLES, '', $v) ?? '';
    $v = preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', ' ', $v) ?? '';
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    return trim($v);
}

// Texto libre: conserva los saltos de línea (como mucho una línea en blanco seguida)
function demo_texto(mixed $v): ?string
{
    if (!is_string($v)) return null;
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace(DEMO_INVISIBLES, '', $v) ?? '';
    $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{80}-\x{9F}]/u', ' ', $v) ?? '';
    $v = preg_replace('/[ \t]+\n/u', "\n", $v) ?? '';
    $v = preg_replace('/\n{3,}/', "\n\n", $v) ?? '';
    return trim($v);
}

/* ------------------------------------------------------------------ */
/* Cola privada                                                         */
/* ------------------------------------------------------------------ */

// Devuelve la ruta real de la cola si existe, es una carpeta y está fuera de la web; si no, null
function demo_cola_segura(array $cfg, array $server): ?string
{
    $dir = (string) ($cfg['cola_dir'] ?? '');
    if ($dir === '') return null;
    $real = realpath($dir);
    if ($real === false || !is_dir($real) || !is_writable($real)) return null;
    $norm = fn(string $p) => rtrim(str_replace('\\', '/', $p), '/') . '/';
    foreach ([(string) ($server['DOCUMENT_ROOT'] ?? ''), (string) ($cfg['web_dir'] ?? '')] as $web) {
        if ($web === '') continue;
        $webReal = realpath($web);
        if ($webReal === false) continue;
        // Dentro de la web, o la web dentro de la cola: las dos cosas están prohibidas
        $a = $norm($real); $b = $norm($webReal);
        if (str_starts_with(strtolower($a), strtolower($b)) || str_starts_with(strtolower($b), strtolower($a))) return null;
    }
    if (DIRECTORY_SEPARATOR === '/' && (fileperms($real) & 0077) !== 0) {
        error_log('Digital Lab demo: la cola no tenía permisos 0700; se corrigen');
        @chmod($real, 0700);
    }
    return $real;
}

function demo_ip(array $server, array $cfg): string
{
    // Solo si el hosting pone un proxy delante (configurable); por defecto, REMOTE_ADDR
    $cab = (string) ($cfg['ip_cabecera'] ?? '');
    if ($cab !== '' && filter_var($server[$cab] ?? '', FILTER_VALIDATE_IP)) return (string) $server[$cab];
    return (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
}

// 5 intentos por hora y visitante. La IP no se guarda: solo un HMAC con sal que cambia cada día (UTC).
function demo_limite_visitante(string $dir, string $ip, string $sal, int $max, int $ahora): bool
{
    $dia = gmdate('Ymd', $ahora);
    $clave = substr(hash_hmac('sha256', $ip . '|' . $dia, $sal), 0, 32);
    $f = "$dir/rl-$dia-$clave.json";
    $viejo = umask(0077);
    $fh = @fopen($f, 'c+');
    umask($viejo);
    if (!$fh) {
        error_log('Digital Lab demo: no se puede abrir el contador por visitante');
        return true;                         // no bloquear a un visitante legítimo por un fallo nuestro
    }
    $permitido = true;
    try {
        flock($fh, LOCK_EX);
        $marcas = json_decode((string) stream_get_contents($fh), true);
        $marcas = array_values(array_filter(is_array($marcas) ? $marcas : [],
            fn($x) => is_int($x) && $x > $ahora - DEMO_VENTANA && $x <= $ahora));
        if (count($marcas) >= $max) {
            $permitido = false;
        } else {
            $marcas[] = $ahora;
            ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($marcas));
            fflush($fh);
        }
    } finally {
        flock($fh, LOCK_UN); fclose($fh);
    }
    if ($permitido) @touch($f, $ahora);      // la caducidad se mide desde el último intento aceptado
    return $permitido;
}

// Guarda la solicitud respetando el tope diario. Devuelve 'ok', 'limite' o 'error'.
function demo_guardar_con_tope(string $cola, string $limites, array $lead, int $max, int $ahora): string
{
    $viejo = umask(0077);
    try {
        $fh = @fopen("$limites/cap-" . gmdate('Ymd', $ahora) . '.txt', 'c+');
        if (!$fh) { error_log('Digital Lab demo: no se puede abrir el tope diario'); return 'error'; }
        try {
            flock($fh, LOCK_EX);
            $n = (int) stream_get_contents($fh);
            if ($n >= $max) return 'limite';
            if (!demo_escribir_lead($cola, $lead, $ahora)) return 'error';
            ftruncate($fh, 0); rewind($fh); fwrite($fh, (string) ($n + 1));
            fflush($fh);
            return 'ok';
        } finally {
            flock($fh, LOCK_UN); fclose($fh);
        }
    } finally {
        umask($viejo);
    }
}

// Un archivo por solicitud: se escribe con nombre temporal (oculto) y se renombra, así quien
// recoge la cola nunca ve un archivo a medias. Permisos 0600.
function demo_escribir_lead(string $cola, array $lead, int $ahora): bool
{
    $aleatorio = bin2hex(random_bytes(8));
    $lead['id'] = 'L-' . gmdate('Ymd-His', $ahora) . '-' . $aleatorio;
    $final = $cola . '/lead-' . gmdate('Ymd\THis\Z', $ahora) . '-' . $aleatorio . '.json';
    $tmp = $cola . '/.tmp-' . $aleatorio;
    try {
        $json = json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    $fh = @fopen($tmp, 'x');
    if (!$fh) { error_log('Digital Lab demo: no se puede crear la solicitud'); return false; }
    $ok = fwrite($fh, $json . "\n") === strlen($json) + 1 && fflush($fh);
    fclose($fh);
    if ($ok) { @chmod($tmp, 0600); @touch($tmp, $ahora); $ok = @rename($tmp, $final); }
    if (!$ok) {
        @unlink($tmp);
        error_log('Digital Lab demo: fallo al guardar la solicitud');
        return false;
    }
    return true;
}

// Borra lo caducado: códigos de visitante (> 1 h o de otro día), topes de días anteriores,
// temporales abandonados y solicitudes de más de 30 días (máximo publicado en la privacidad).
function demo_purgar(string $cola, int $ahora): void
{
    $hoy = gmdate('Ymd', $ahora);
    $ayer = gmdate('Ymd', $ahora - 86400);
    foreach (glob("$cola/limites/rl-*.json") ?: [] as $f) {
        if (!str_starts_with(basename($f), "rl-$hoy-") || (int) @filemtime($f) < $ahora - DEMO_VENTANA) @unlink($f);
    }
    foreach (glob("$cola/limites/cap-*.txt") ?: [] as $f) {
        $d = substr(basename($f), 4, 8);
        if ($d !== $hoy && $d !== $ayer) @unlink($f);
    }
    foreach (glob("$cola/.tmp-*") ?: [] as $f) {
        if ((int) @filemtime($f) < $ahora - 3600) @unlink($f);
    }
    foreach (glob("$cola/lead-*.json") ?: [] as $f) {
        if ((int) @filemtime($f) < $ahora - DEMO_CONSERVACION) @unlink($f);
    }
}

/* ------------------------------------------------------------------ */
/* Entrada HTTP                                                         */
/* ------------------------------------------------------------------ */

function demo_main(): never
{
    ini_set('display_errors', '0');
    try {
        $cuerpo = (string) file_get_contents('php://input', false, null, 0, DEMO_MAX_CUERPO + 1);
        [$codigo, $datos, $cabeceras] = demo_procesar($_SERVER, $cuerpo, demo_cargar_config(), time());
    } catch (Throwable $e) {
        error_log('Digital Lab demo: ' . get_class($e));
        [$codigo, $datos, $cabeceras] = [500, ['ok' => false, 'error' => 'error'], []];
    }
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    foreach ($cabeceras as $k => $v) header("$k: $v");
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!defined('DL_DEMO_PRUEBAS')) demo_main();
