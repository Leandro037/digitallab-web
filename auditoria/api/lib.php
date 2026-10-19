<?php
/*
 * Digital Lab · auditoría web: utilidades comunes
 * Configuración, respuestas JSON, límites de uso, almacenamiento temporal,
 * descarga segura de la web auditada, configuración de los agentes y llamada a la IA (Claude).
 */
declare(strict_types=1);

if (!defined('DL_AUDIT')) { http_response_code(404); exit; }

// Los avisos de PHP van al registro del servidor, nunca a la respuesta (romperían el JSON y mostrarían rutas)
ini_set('display_errors', '0');
date_default_timezone_set('America/Argentina/Buenos_Aires');

const DL_DATA_DIR = __DIR__ . '/data';
const DL_TTL = 86400;                 // los datos de cada auditoría se borran a las 24 h
const DL_UA = 'DigitalLabAudit/1.0 (+https://dl-dev.com/auditoria/)';

final class AuditError extends Exception
{
    public function __construct(public readonly string $codigo, public readonly int $http = 400)
    {
        parent::__construct($codigo);
    }
}

/* ------------------------------------------------------------------ */
/* Configuración                                                        */
/* ------------------------------------------------------------------ */

// Se busca primero fuera de public_html (más seguro) y si no, junto a este archivo
function cfg(string $key, mixed $default = null): mixed
{
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach ([dirname(__DIR__, 3) . '/dl-auditoria-config.php', __DIR__ . '/config.php'] as $p) {
            if (is_file($p)) { $c = require $p; break; }
        }
    }
    return $c[$key] ?? $default;
}

/* ------------------------------------------------------------------ */
/* Peticiones y respuestas                                              */
/* ------------------------------------------------------------------ */

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $error, int $code = 400): never
{
    json_out(['ok' => false, 'error' => $error], $code);
}

// Solo POST con JSON pequeño y desde nuestro propio dominio
function read_json_body(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('error', 405);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = cfg('origenes', ['https://dl-dev.com', 'https://www.dl-dev.com']);
    if ($origin !== '' && !in_array($origin, $allowed, true)) fail('error', 403);
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) fail('error', 400);
    return $data;
}

/* ------------------------------------------------------------------ */
/* Límites de uso (por visitante y diarios, para controlar el gasto)    */
/* ------------------------------------------------------------------ */

// La IP no se guarda: solo un resumen (hash) con sal, y se borra a las 24 h
function client_key(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr(hash('sha256', $ip . '|' . cfg('sal', 'digital-lab')), 0, 32);
}

function ensure_data_dir(): void
{
    if (!is_dir(DL_DATA_DIR)) mkdir(DL_DATA_DIR, 0700, true);
}

function rate_limit(string $bucket, int $max, int $window): void
{
    ensure_data_dir();
    $file = DL_DATA_DIR . "/rl-$bucket-" . client_key() . '.json';
    $fh = fopen($file, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $times = json_decode(stream_get_contents($fh) ?: '[]', true) ?: [];
    $now = time();
    $times = array_values(array_filter($times, fn($t) => is_int($t) && $t > $now - $window));
    if (count($times) >= $max) { flock($fh, LOCK_UN); fclose($fh); fail('limite', 429); }
    $times[] = $now;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($times));
    flock($fh, LOCK_UN); fclose($fh);
}

function daily_cap(string $bucket, int $max): void
{
    ensure_data_dir();
    $file = DL_DATA_DIR . "/cap-$bucket-" . date('Ymd') . '.txt';
    $fh = fopen($file, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $n = (int) stream_get_contents($fh);
    if ($n >= $max) { flock($fh, LOCK_UN); fclose($fh); fail('limite', 429); }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, (string) ($n + 1));
    flock($fh, LOCK_UN); fclose($fh);
}

/* ------------------------------------------------------------------ */
/* Almacenamiento temporal de cada auditoría                            */
/* ------------------------------------------------------------------ */

function new_id(): string { return bin2hex(random_bytes(12)); }

function store_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) throw new AuditError('expirada', 410);
    return DL_DATA_DIR . "/audit-$id.json";
}

function store_put(string $id, array $data): void
{
    ensure_data_dir();
    file_put_contents(store_path($id), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function store_get(string $id): array
{
    $p = store_path($id);
    if (!is_file($p) || filemtime($p) < time() - DL_TTL) throw new AuditError('expirada', 410);
    $d = json_decode((string) file_get_contents($p), true);
    if (!is_array($d)) throw new AuditError('expirada', 410);
    return $d;
}

// Lee y guarda la auditoría con el archivo bloqueado: dos agentes trabajan a la vez sobre la misma auditoría
function store_update(string $id, callable $fn): array
{
    $p = store_path($id);
    if (!is_file($p) || filemtime($p) < time() - DL_TTL) throw new AuditError('expirada', 410);
    $fh = fopen($p, 'c+');
    if (!$fh) throw new AuditError('error', 500);
    flock($fh, LOCK_EX);
    try {
        $d = json_decode((string) stream_get_contents($fh), true);
        if (!is_array($d)) throw new AuditError('expirada', 410);
        $d = $fn($d);
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($d, JSON_UNESCAPED_UNICODE));
        return $d;
    } finally {
        flock($fh, LOCK_UN); fclose($fh);
    }
}

// De vez en cuando borra auditorías y contadores caducados
function cleanup(): void
{
    if (random_int(1, 20) !== 1 || !is_dir(DL_DATA_DIR)) return;
    $limit = time() - DL_TTL;
    foreach (glob(DL_DATA_DIR . '/{audit,rl,cap}-*', GLOB_BRACE) ?: [] as $f) {
        if (filemtime($f) < $limit) @unlink($f);
    }
}

/* ------------------------------------------------------------------ */
/* Descarga segura de la web auditada (protección SSRF)                 */
/* Solo webs públicas: se resuelve el dominio, se rechazan IP privadas  */
/* o reservadas y la conexión se fija a la IP comprobada.               */
/* ------------------------------------------------------------------ */

function normalize_url(string $raw): string
{
    $v = trim($raw);
    if ($v === '' || strlen($v) > 300) throw new AuditError('url_invalida');
    if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
    $p = parse_url($v);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
        || isset($p['user']) || isset($p['pass'])) throw new AuditError('url_invalida');
    if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) throw new AuditError('no_publica');
    $host = strtolower(rtrim($p['host'], '.'));
    if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
        $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
    }
    if (!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) throw new AuditError('url_invalida');
    $path = $p['path'] ?? '/';
    $query = isset($p['query']) ? '?' . $p['query'] : '';
    return strtolower($p['scheme']) . '://' . $host . ($path === '' ? '/' : $path) . $query;
}

function ip_is_public(string $ip): bool
{
    if (str_starts_with(strtolower($ip), '::ffff:')) {
        $v4 = substr($ip, 7);
        if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return ip_is_public($v4);
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['0.0.0.0', 8]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) return false;
        }
    } else {
        $l = strtolower($ip);
        if (str_starts_with($l, 'fc') || str_starts_with($l, 'fd') || str_starts_with($l, 'fe80') || $l === '::' || $l === '::1') return false;
    }
    return true;
}

// Devuelve la IP pública con la que conectar; rechaza el dominio si alguna de sus IP es interna
function resolve_public(string $host): string
{
    $ips = gethostbynamel($host) ?: [];
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    if (!$ips) throw new AuditError('inaccesible', 422);
    foreach ($ips as $ip) if (!ip_is_public($ip)) throw new AuditError('no_publica', 422);
    foreach ($ips as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
    return $ips[0];
}

/**
 * Una petición HTTP sin seguir redirecciones, con la IP fijada, tiempo y tamaño limitados.
 * @return array{status:int, headers:array<string,string>, body:string, ttfb:float, cert_expira:?int, tls_ok:bool, error:string}
 */
function http_once(string $url, int $maxBytes = 1500000, bool $verify = true): array
{
    $p = parse_url($url);
    $host = $p['host'];
    $port = ($p['scheme'] === 'https') ? 443 : 80;
    $ip = resolve_public($host);
    $headers = [];
    $body = '';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RESOLVE => ["$host:$port:" . (str_contains($ip, ':') ? "[$ip]" : $ip)],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => DL_UA,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: es,en;q=0.8'],
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_CERTINFO => true,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
            $body .= $chunk;
            return strlen($body) > $maxBytes ? -1 : strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
    else $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
    $expira = null;
    foreach ((array) curl_getinfo($ch, CURLINFO_CERTINFO) as $cert) {
        if (!empty($cert['Expire date'])) { $expira = strtotime($cert['Expire date']) ?: null; break; }
    }
    curl_close($ch);
    // 23 = descarga cortada a propósito por superar el tamaño máximo: se usa lo recibido
    $error = ($errno && $errno !== 23) ? (string) $errno : '';
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'ttfb' => $ttfb, 'cert_expira' => $expira,
            'tls_ok' => !in_array($errno, [35, 51, 58, 60], true), 'error' => $error];
}

// Sigue hasta 4 redirecciones, comprobando cada destino antes de conectar
function http_follow(string $url, int $maxBytes = 1500000): array
{
    $chain = [];
    for ($i = 0; $i < 5; $i++) {
        $r = http_once($url, $maxBytes);
        if (!$r['tls_ok']) {                     // certificado roto: se anota y se lee igual el contenido
            $r = http_once($url, $maxBytes, false);
            $r['tls_ok'] = false;
        }
        $r['url'] = $url;
        $chain[] = $url;
        if ($r['error'] !== '' && $r['status'] === 0) throw new AuditError('inaccesible', 422);
        if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['headers']['location'])) {
            $url = normalize_url(absolute_url($r['headers']['location'], $url));
            continue;
        }
        $r['chain'] = $chain;
        return $r;
    }
    throw new AuditError('inaccesible', 422);
}

function absolute_url(string $loc, string $base): string
{
    if (preg_match('#^https?://#i', $loc)) return $loc;
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'];
    if (str_starts_with($loc, '//')) return $b['scheme'] . ':' . $loc;
    if (str_starts_with($loc, '/')) return $root . $loc;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $root . $dir . $loc;
}

/* ------------------------------------------------------------------ */
/* Agentes: la configuración exportada del organigrama de Digital Lab   */
/* («Exportar la configuración para la web»). Contiene las instrucciones */
/* internas de cada agente: vive fuera de public_html y fuera de Git.    */
/* ------------------------------------------------------------------ */

function agent_cfg(string $n): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        $paths = array_filter([(string) cfg('agentes_archivo', ''), dirname(__DIR__, 3) . '/dl-auditoria-agentes.json', __DIR__ . '/agentes.json']);
        foreach ($paths as $p) {
            if (is_file($p)) { $all = json_decode((string) file_get_contents($p), true)['agentes'] ?? []; break; }
        }
    }
    $a = $all[$n] ?? null;
    if (!is_array($a) || trim((string) ($a['instruccion'] ?? '')) === '') {
        error_log("Digital Lab auditoría: falta la configuración del agente $n");
        throw new AuditError('ia_no_configurada', 503);
    }
    return $a;
}

/* ------------------------------------------------------------------ */
/* IA: Claude (API de Anthropic, llamada HTTP directa)                  */
/* ------------------------------------------------------------------ */

function claude_text(string $system, string $user, int $maxTokens, string $effort, ?string $model = null): string
{
    $key = (string) cfg('anthropic_api_key', '');
    if ($key === '') throw new AuditError('ia_no_configurada', 503);
    $body = json_encode([
        'model' => $model ?: cfg('modelo_ia', 'claude-sonnet-5-5'),
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
        'output_config' => ['effort' => $effort],
        // Si el modelo declina la petición, Anthropic la reintenta en el modelo de respaldo recomendado
        'fallbacks' => 'default',
    ], JSON_UNESCAPED_UNICODE);

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init((string) cfg('anthropic_url', 'https://api.anthropic.com/v1/messages'));
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 170,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: server-side-fallback-2026-07-01',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $netError = curl_errno($ch);
        curl_close($ch);

        // Reintento único ante saturación, límite de uso o error temporal
        if ($attempt === 0 && ($netError || in_array($status, [408, 429, 500, 502, 503, 504, 529], true))) {
            $wait = min(5, max(1, (int) ($respHeaders['retry-after'] ?? 2)));
            sleep($wait);
            continue;
        }
        if ($netError || $status !== 200) {
            // Tipo y mensaje del error de la API (nunca incluyen la clave) + id de la petición para el soporte de Anthropic
            $err = is_string($raw) ? (json_decode($raw, true)['error'] ?? []) : [];
            $detail = trim(($err['type'] ?? '') . ' ' . mb_substr((string) ($err['message'] ?? ''), 0, 200));
            error_log("Digital Lab auditoría: error de la IA ($status" . ($netError ? ", red $netError" : '') . ") $detail"
                . (isset($respHeaders['request-id']) ? ' [' . $respHeaders['request-id'] . ']' : ''));
            throw new AuditError('ia_no_disponible', 502);
        }
        $msg = json_decode((string) $raw, true);
        // Consumo de cada llamada en el registro del servidor, para seguir el gasto
        $u = $msg['usage'] ?? [];
        error_log(sprintf('Digital Lab auditoría: consumo %s · entrada %d · salida %d · %s',
            (string) ($msg['model'] ?? '?'), (int) ($u['input_tokens'] ?? 0), (int) ($u['output_tokens'] ?? 0), (string) ($msg['stop_reason'] ?? '?')));
        if (($msg['stop_reason'] ?? '') === 'refusal') {
            error_log('Digital Lab auditoría: la IA declinó la petición');
            throw new AuditError('ia_no_disponible', 502);
        }
        $text = '';
        foreach ($msg['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $text = trim($text);
        if ($text === '') throw new AuditError('ia_no_disponible', 502);
        return $text;
    }
    throw new AuditError('ia_no_disponible', 502);
}

// El agente responde JSON; se tolera texto alrededor o un bloque de código
function extract_json(string $t): ?array
{
    $t = trim($t);
    $try = function (string $s): ?array { $d = json_decode($s, true); return is_array($d) && !array_is_list($d) ? $d : null; };
    if ($d = $try($t)) return $d;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $t, $m) && ($d = $try($m[1]))) return $d;
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a !== false && $b > $a && ($d = $try(substr($t, $a, $b - $a + 1)))) return $d;
    return null;
}
