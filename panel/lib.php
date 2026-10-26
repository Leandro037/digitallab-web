<?php
/*
 * Digital Lab · estadísticas propias de la web (sin cookies, sin guardar la IP)
 * Base de datos SQLite, país por IP (DB-IP Lite, local), navegador/dispositivo por el User-Agent,
 * sesión del panel y consultas del panel. Lo usan r.php (recolector), api.php (panel) y login/entrar.php.
 */
declare(strict_types=1);

if (!defined('DL_PANEL')) { http_response_code(404); exit; }
if (!defined('DL_AUDIT')) define('DL_AUDIT', 1);   // reutiliza cfg(), json_out(), fail() de la auditoría
require_once dirname(__DIR__) . '/auditoria/api/lib.php';

const PANEL_DATOS = __DIR__ . '/datos';
const PANEL_VERSION_DB = 1;
const PANEL_SESION_HORAS = 12;

/* ------------------------------------------------------------------ */
/* Sesión del panel (cookie de sesión solo para quien entra con el login) */
/* ------------------------------------------------------------------ */

function panel_sesion_iniciar(bool $soloLeer = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = PANEL_DATOS . '/sesiones';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string) (PANEL_SESION_HORAS * 3600));
    ini_set('session.use_strict_mode', '1');
    session_name('dlpanel');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start($soloLeer ? ['read_and_close' => true] : []);
}

function panel_autorizado(): bool
{
    if (!isset($_COOKIE['dlpanel'])) return false;
    panel_sesion_iniciar(true);
    return !empty($_SESSION['admin']) && (int) ($_SESSION['t'] ?? 0) > time() - PANEL_SESION_HORAS * 3600;
}

function panel_exigir(): void
{
    if (!panel_autorizado()) { header('Cache-Control: no-store'); json_out(['ok' => false, 'error' => 'sin_sesion'], 401); }
}

/* ------------------------------------------------------------------ */
/* Base de datos                                                        */
/* ------------------------------------------------------------------ */

function pdb(): PDO
{
    static $db = null;
    if ($db) return $db;
    if (!is_dir(PANEL_DATOS)) mkdir(PANEL_DATOS, 0700, true);
    $db = new PDO('sqlite:' . PANEL_DATOS . '/analitica.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=4000');
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() < PANEL_VERSION_DB) {
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS ev (
            id INTEGER PRIMARY KEY, t INTEGER NOT NULL, tipo TEXT NOT NULL, v TEXT NOT NULL, pid TEXT, p TEXT,
            ref TEXT, fuente TEXT, us TEXT, um TEXT, uc TEXT,
            pais TEXT, disp TEXT, nav TEXT, so TEXT, ancho INTEGER, idioma TEXT,
            dur INTEGER, scroll INTEGER, lcp INTEGER, cls REAL, obj TEXT, sec TEXT, e404 INTEGER NOT NULL DEFAULT 0)');
        $db->exec('CREATE INDEX IF NOT EXISTS ev_t ON ev(t)');
        $db->exec('CREATE INDEX IF NOT EXISTS ev_tipo_t ON ev(tipo, t)');
        $db->exec('PRAGMA user_version=' . PANEL_VERSION_DB);
    }
    return $db;
}

/* ------------------------------------------------------------------ */
/* Visitante anónimo del día: hash con sal que cambia cada día           */
/* ------------------------------------------------------------------ */

function sal_del_dia(): string
{
    $f = PANEL_DATOS . '/sal.json';
    $hoy = gmdate('Ymd');
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($d) || ($d['dia'] ?? '') !== $hoy) {
        $d = ['dia' => $hoy, 'sal' => bin2hex(random_bytes(16))];
        file_put_contents($f, json_encode($d), LOCK_EX);
    }
    return (string) $d['sal'];
}

function visitante_id(string $ip, string $ua): string
{
    return substr(hash('sha256', sal_del_dia() . '|' . $ip . '|' . $ua), 0, 16);
}

/* ------------------------------------------------------------------ */
/* País por IP (DB-IP Lite convertida a binario; búsqueda binaria en disco) */
/* ------------------------------------------------------------------ */

function geo_buscar(string $archivo, int $tam, int $nClave, array $clave): string
{
    if (!is_file($archivo)) return '??';
    $fh = fopen($archivo, 'rb'); if (!$fh) return '??';
    $n = intdiv((int) filesize($archivo), $tam); $lo = 0; $hi = $n - 1; $hallado = -1;
    $cmp = static function (array $a, array $b): int { foreach ($a as $i => $x) { if ($x !== $b[$i]) return $x <=> $b[$i]; } return 0; };
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1; fseek($fh, $mid * $tam); $r = fread($fh, $tam);
        $ini = array_values(unpack($nClave === 1 ? 'N' : 'N2', $r));
        if ($cmp($ini, $clave) <= 0) { $hallado = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
    }
    $cc = '??';
    if ($hallado >= 0) {
        fseek($fh, $hallado * $tam); $r = fread($fh, $tam);
        $f = $nClave === 1 ? array_values(unpack('N', substr($r, 4, 4))) : array_values(unpack('N2', substr($r, 8, 8)));
        if ($cmp($clave, $f) <= 0) $cc = substr($r, $tam - 2, 2);
    }
    fclose($fh);
    return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '??';
}

function pais_de_ip(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) return '??';
    if (strlen($bin) === 4) return geo_buscar(PANEL_DATOS . '/geo4.bin', 10, 1, [unpack('N', $bin)[1]]);
    $k = array_values(unpack('N2', substr($bin, 0, 8)));
    return geo_buscar(PANEL_DATOS . '/geo6.bin', 18, 2, $k);
}

/* ------------------------------------------------------------------ */
/* Navegador, sistema y dispositivo (sin guardar el User-Agent)          */
/* ------------------------------------------------------------------ */

function es_robot(string $ua): bool
{
    if ($ua === '' || strlen($ua) < 20) return true;
    return (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl\/|wget|python|java\/|go-http|axios|node-fetch|preview|scanner|httpclient|libwww|okhttp|phantom|selenium/i', $ua);
}

function ua_info(string $ua): array
{
    $l = strtolower($ua);
    $disp = str_contains($l, 'ipad') || str_contains($l, 'tablet') || (str_contains($l, 'android') && !str_contains($l, 'mobile')) ? 'tablet'
        : (preg_match('/mobi|iphone|android/', $l) ? 'móvil' : 'escritorio');
    $nav = match (true) {
        str_contains($l, 'edg/') || str_contains($l, 'edga/') || str_contains($l, 'edgios/') => 'Edge',
        str_contains($l, 'opr/') || str_contains($l, 'opera') => 'Opera',
        str_contains($l, 'samsungbrowser') => 'Samsung Internet',
        str_contains($l, 'firefox') || str_contains($l, 'fxios') => 'Firefox',
        str_contains($l, 'crios') || str_contains($l, 'chrome') => 'Chrome',
        str_contains($l, 'safari') => 'Safari',
        default => 'Otro',
    };
    $so = match (true) {
        str_contains($l, 'windows') => 'Windows',
        str_contains($l, 'iphone') || str_contains($l, 'ipad') || str_contains($l, 'ios') => 'iOS',
        str_contains($l, 'android') => 'Android',
        str_contains($l, 'mac os') || str_contains($l, 'macintosh') => 'macOS',
        str_contains($l, 'cros') => 'ChromeOS',
        str_contains($l, 'linux') => 'Linux',
        default => 'Otro',
    };
    return ['disp' => $disp, 'nav' => $nav, 'so' => $so];
}

// De dónde llega la visita: se agrupan los sitios más comunes; el resto se guarda por su dominio
function fuente_de(string $refHost): string
{
    $h = strtolower(preg_replace('/^www\./', '', $refHost));
    if ($h === '') return 'directo';
    if ($h === 'dl-dev.com') return 'interno';
    $grupos = ['google' => '/(^|\.)google\./', 'bing' => '/(^|\.)bing\.com$/', 'duckduckgo' => '/duckduckgo\.com$/', 'yahoo' => '/(^|\.)yahoo\./', 'ecosia' => '/ecosia\.org$/',
        'instagram' => '/(^|\.)instagram\.com$/', 'facebook' => '/(^|\.)(facebook|fb)\.com$|^l\.facebook\.com$/', 'linkedin' => '/(^|\.)(linkedin\.com|lnkd\.in)$/',
        'x' => '/^(t\.co|x\.com|twitter\.com)$|\.twitter\.com$/', 'whatsapp' => '/whatsapp\.com$|wa\.me$/', 'youtube' => '/(^|\.)(youtube\.com|youtu\.be)$/', 'github' => '/github\.(com|io)$/',
        'claude' => '/(^|\.)claude\.ai$/', 'chatgpt' => '/chatgpt\.com$|openai\.com$/', 'perplexity' => '/perplexity\.ai$/'];
    foreach ($grupos as $nombre => $re) if (preg_match($re, $h)) return $nombre;
    return substr($h, 0, 60);
}

/* ------------------------------------------------------------------ */
/* Limpieza de lo que llega del navegador                                */
/* ------------------------------------------------------------------ */

function limpia_texto(mixed $v, int $max): string
{
    $s = is_scalar($v) ? (string) $v : '';
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function limpia_ruta(mixed $v): string
{
    $s = limpia_texto($v, 160);
    $s = preg_replace('#[?\#].*$#', '', $s) ?? '';
    $s = preg_replace('#/index\.html$#', '/', $s) ?? '';
    if ($s === '' || $s[0] !== '/') $s = '/' . $s;
    return preg_replace('#[^a-zA-Z0-9\-._~/]#', '', $s) ?: '/';
}

function entero(mixed $v, int $min, int $max): ?int
{
    if (!is_numeric($v)) return null;
    return max($min, min($max, (int) $v));
}

/* ------------------------------------------------------------------ */
/* Consultas del panel                                                   */
/* ------------------------------------------------------------------ */

function zona_offset(): int
{
    $tz = new DateTimeZone((string) cfg('panel_zona', 'America/Argentina/Buenos_Aires'));
    return $tz->getOffset(new DateTime('now', $tz));
}

// Rango de fechas (en la zona del negocio) a segundos Unix
function rango_unix(string $desde, string $hasta, int $off): array
{
    $d = DateTime::createFromFormat('!Y-m-d', $desde, new DateTimeZone('UTC')); $h = DateTime::createFromFormat('!Y-m-d', $hasta, new DateTimeZone('UTC'));
    if (!$d || !$h || $h < $d) { $h = new DateTime('today', new DateTimeZone('UTC')); $d = (clone $h)->modify('-6 days'); }
    if ($d->diff($h)->days > 400) $d = (clone $h)->modify('-400 days');
    return [$d->getTimestamp() - $off, $h->getTimestamp() + 86400 - 1 - $off, $d->format('Y-m-d'), $h->format('Y-m-d')];
}

// Filtros opcionales (página, país, dispositivo, fuente) como trozos de WHERE
function filtros_sql(array $f, array &$par): string
{
    $w = '';
    if (($f['p'] ?? '') !== '') { $w .= ' AND p = :fp'; $par[':fp'] = $f['p']; }
    if (($f['pais'] ?? '') !== '') { $w .= ' AND pais = :fpais'; $par[':fpais'] = $f['pais']; }
    if (($f['disp'] ?? '') !== '') { $w .= ' AND disp = :fdisp'; $par[':fdisp'] = $f['disp']; }
    if (($f['fuente'] ?? '') !== '') { $w .= " AND v IN (SELECT v FROM ev WHERE tipo = 'pv' AND fuente = :ffuente AND t BETWEEN :a AND :b)"; $par[':ffuente'] = $f['fuente']; }
    return $w;
}

function consulta(string $sql, array $par = []): array
{
    $st = pdb()->prepare($sql); $st->execute($par); return $st->fetchAll();
}

function mediana(array $v, float $q = 0.5): ?int
{
    if (!$v) return null; sort($v); return (int) $v[(int) floor((count($v) - 1) * $q)];
}

// Sesiones: las páginas vistas del mismo visitante (del día) con menos de 30 minutos entre una y otra
function sesiones(int $a, int $b, string $w, array $par): array
{
    $p = $par + [':a' => $a, ':b' => $b];
    $filas = consulta("WITH e AS (SELECT id, v, t, tipo, p, COALESCE(dur,0) AS dur FROM ev WHERE t BETWEEN :a AND :b $w),
        m AS (SELECT *, CASE WHEN LAG(t) OVER (PARTITION BY v ORDER BY t, id) IS NULL OR t - LAG(t) OVER (PARTITION BY v ORDER BY t, id) > 1800 THEN 1 ELSE 0 END AS nueva FROM e),
        s AS (SELECT *, SUM(nueva) OVER (PARTITION BY v ORDER BY t, id) AS n FROM m)
        SELECT v, n, MIN(t) AS ini, SUM(tipo = 'pv') AS pv, SUM(tipo = 'click') AS clics, SUM(tipo = 'form') AS forms, SUM(dur) AS dur FROM s GROUP BY v, n", $p);
    return $filas;
}

function panel_datos(string $desde, string $hasta, array $f): array
{
    $off = zona_offset();
    [$a, $b, $d1, $d2] = rango_unix($desde, $hasta, $off);
    $dias = (int) round(($b - $a + 1) / 86400);
    $pa = $a - $dias * 86400; $pb = $a - 1;                     // periodo anterior, mismo largo
    $out = ['rango' => ['desde' => $d1, 'hasta' => $d2, 'dias' => $dias, 'zona' => (string) cfg('panel_zona', 'America/Argentina/Buenos_Aires')]];

    $resumen = static function (int $x, int $y) use ($f): array {
        $par = []; $w = filtros_sql($f, $par); $par += [':a' => $x, ':b' => $y];
        $r = consulta("SELECT SUM(tipo='pv') AS vistas, COUNT(DISTINCT CASE WHEN tipo='pv' THEN v END) AS visitantes, SUM(tipo='click') AS clics, SUM(tipo='form') AS formularios,
            SUM(CASE WHEN tipo='fin' THEN dur ELSE 0 END) AS dur, SUM(tipo='fin') AS fines FROM ev WHERE t BETWEEN :a AND :b $w", $par)[0];
        $ss = sesiones($x, $y, $w, array_diff_key($par, [':a' => 1, ':b' => 1]));
        $n = count($ss); $inter = 0; $dur = 0; $conv = 0;
        foreach ($ss as $s) { if ((int) $s['pv'] >= 2 || (int) $s['dur'] >= 10000 || (int) $s['clics'] > 0 || (int) $s['forms'] > 0) $inter++; $dur += (int) $s['dur']; if ((int) $s['forms'] > 0) $conv++; }
        return ['vistas' => (int) $r['vistas'], 'visitantes' => (int) $r['visitantes'], 'sesiones' => $n, 'clics' => (int) $r['clics'], 'formularios' => (int) $r['formularios'],
            'paginas_por_sesion' => $n ? round((int) $r['vistas'] / $n, 2) : 0, 'tiempo_medio_s' => $n ? (int) round($dur / $n / 1000) : 0,
            'interaccion_pct' => $n ? round(100 * $inter / $n) : 0, 'rebote_pct' => $n ? round(100 * ($n - $inter) / $n) : 0, 'conversion_pct' => $n ? round(100 * $conv / $n, 1) : 0];
    };
    $out['resumen'] = $resumen($a, $b);
    $out['anterior'] = $resumen($pa, $pb);

    $par = []; $w = filtros_sql($f, $par); $base = $par + [':a' => $a, ':b' => $b];
    $q = static fn (string $sql, array $extra = []) => consulta($sql, $base + $extra);

    // Serie: por hora si el rango es de uno o dos días; por día si es mayor
    $fmt = $dias <= 2 ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
    $serie = $q("SELECT strftime('$fmt', t + $off, 'unixepoch') AS k, SUM(tipo='pv') AS vistas, COUNT(DISTINCT CASE WHEN tipo='pv' THEN v END) AS visitantes, SUM(tipo='click') AS clics FROM ev WHERE t BETWEEN :a AND :b $w GROUP BY k ORDER BY k");
    $out['serie'] = ['por' => $dias <= 2 ? 'hora' : 'dia', 'puntos' => $serie];

    $out['paginas'] = $q("SELECT p, SUM(tipo='pv') AS vistas, COUNT(DISTINCT CASE WHEN tipo='pv' THEN v END) AS visitantes,
            CAST(AVG(CASE WHEN tipo='fin' AND dur > 0 THEN dur END) / 1000 AS INTEGER) AS tiempo_s, CAST(AVG(CASE WHEN tipo='fin' THEN scroll END) AS INTEGER) AS scroll,
            SUM(tipo='click') AS clics, SUM(e404) AS e404 FROM ev WHERE t BETWEEN :a AND :b $w AND p IS NOT NULL GROUP BY p HAVING vistas > 0 ORDER BY vistas DESC LIMIT 40");
    $out['paises'] = $q("SELECT pais, COUNT(DISTINCT v) AS visitantes, SUM(tipo='pv') AS vistas FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='pv' GROUP BY pais ORDER BY visitantes DESC LIMIT 30");
    $out['fuentes'] = $q("SELECT fuente, COUNT(DISTINCT v) AS visitantes, COUNT(*) AS vistas FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='pv' AND fuente IS NOT NULL AND fuente != 'interno' GROUP BY fuente ORDER BY visitantes DESC LIMIT 20");
    $out['campanas'] = $q("SELECT COALESCE(us,'') AS fuente, COALESCE(um,'') AS medio, COALESCE(uc,'') AS campana, COUNT(DISTINCT v) AS visitantes FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='pv' AND (us IS NOT NULL OR um IS NOT NULL OR uc IS NOT NULL) GROUP BY us, um, uc ORDER BY visitantes DESC LIMIT 20");
    foreach (['disp' => 'dispositivos', 'nav' => 'navegadores', 'so' => 'sistemas', 'idioma' => 'idiomas'] as $col => $clave) {
        $out[$clave] = $q("SELECT $col AS k, COUNT(DISTINCT v) AS visitantes FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='pv' AND $col IS NOT NULL GROUP BY $col ORDER BY visitantes DESC LIMIT 12");
    }
    $out['anchos'] = $q("SELECT CASE WHEN ancho < 480 THEN 'menos de 480 px' WHEN ancho < 768 THEN '480 a 767 px' WHEN ancho < 1024 THEN '768 a 1023 px' WHEN ancho < 1440 THEN '1024 a 1439 px' ELSE '1440 px o más' END AS k, COUNT(DISTINCT v) AS visitantes
            FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='pv' AND ancho IS NOT NULL GROUP BY k ORDER BY MIN(ancho)");
    $out['clics'] = $q("SELECT obj, COUNT(*) AS clics, COUNT(DISTINCT v) AS visitantes FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='click' AND obj IS NOT NULL GROUP BY obj ORDER BY clics DESC LIMIT 40");
    $out['secciones'] = $q("SELECT sec, COUNT(*) AS clics FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='click' AND sec IS NOT NULL AND sec != '' GROUP BY sec ORDER BY clics DESC LIMIT 15");
    $out['eventos'] = $q("SELECT obj, COUNT(*) AS veces, COUNT(DISTINCT v) AS visitantes FROM ev WHERE t BETWEEN :a AND :b $w AND tipo IN ('form','evt') AND obj IS NOT NULL GROUP BY obj ORDER BY veces DESC LIMIT 20");
    $out['horas'] = $q("SELECT CAST(strftime('%w', t + $off, 'unixepoch') AS INTEGER) AS dia, CAST(strftime('%H', t + $off, 'unixepoch') AS INTEGER) AS hora, COUNT(*) AS vistas FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='pv' GROUP BY dia, hora");
    $out['salidas404'] = $q("SELECT p, COUNT(*) AS veces FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='pv' AND e404=1 GROUP BY p ORDER BY veces DESC LIMIT 15");

    // Velocidad real percibida por los visitantes (LCP) y estabilidad (CLS)
    $lcp = $q("SELECT disp, lcp, cls FROM ev WHERE t BETWEEN :a AND :b $w AND tipo='fin' AND lcp IS NOT NULL");
    $porDisp = []; foreach ($lcp as $r) { $porDisp[$r['disp'] ?? 'otro'][] = (int) $r['lcp']; }
    $out['velocidad'] = ['muestras' => count($lcp), 'lcp_mediana' => mediana(array_map('intval', array_column($lcp, 'lcp'))), 'lcp_p75' => mediana(array_map('intval', array_column($lcp, 'lcp')), 0.75),
        'cls_mediana' => $lcp ? round((float) (static function (array $c): float { sort($c); return (float) $c[(int) floor((count($c) - 1) / 2)]; })(array_map('floatval', array_column($lcp, 'cls'))), 3) : null,
        'por_dispositivo' => array_map(static fn ($k, $v) => ['disp' => $k, 'lcp_mediana' => mediana($v), 'muestras' => count($v)], array_keys($porDisp), $porDisp)];

    // Ahora mismo: personas con actividad en los últimos 5 minutos
    // «Ahora» ignora los filtros: es la actividad real de los últimos 5 minutos
    $ahora = time(); $x5 = [':x' => $ahora - 300];
    $out['ahora'] = ['visitantes' => (int) consulta('SELECT COUNT(DISTINCT v) AS n FROM ev WHERE t >= :x', $x5)[0]['n'],
        'paginas' => consulta('SELECT p, COUNT(DISTINCT v) AS n FROM ev WHERE t >= :x AND p IS NOT NULL GROUP BY p ORDER BY n DESC LIMIT 5', $x5),
        'paises' => consulta('SELECT pais, COUNT(DISTINCT v) AS n FROM ev WHERE t >= :x AND pais IS NOT NULL GROUP BY pais ORDER BY n DESC LIMIT 5', $x5)];
    $out['filtros'] = array_filter($f, static fn ($x) => $x !== '');
    $out['generado'] = date('c');
    $out['total_eventos'] = (int) consulta('SELECT COUNT(*) AS n FROM ev')[0]['n'];
    $out['primer_evento'] = consulta('SELECT MIN(t) AS t FROM ev')[0]['t'];
    $out['geo_mes'] = is_file(PANEL_DATOS . '/mes.txt') ? trim((string) file_get_contents(PANEL_DATOS . '/mes.txt')) : null;
    return $out;
}

// Borra los datos más viejos que el plazo (por defecto 14 meses)
function purgar_viejos(): void
{
    $dias = (int) cfg('panel_dias', 425);
    pdb()->prepare('DELETE FROM ev WHERE t < :x')->execute([':x' => time() - $dias * 86400]);
}
