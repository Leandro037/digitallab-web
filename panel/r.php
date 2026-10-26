<?php
/* Recolector de visitas: lo llama js/analitica.js con sendBeacon. No guarda IP ni cookies. */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(204); exit; }

// Solo desde las páginas del propio sitio
$permitidos = (array) cfg('origenes', ['https://dl-dev.com', 'https://www.dl-dev.com']);
$origen = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origen !== '' && !in_array($origen, $permitidos, true)) { http_response_code(204); exit; }

$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$dnt = ($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
if ($dnt || es_robot($ua)) { http_response_code(204); exit; }

$raw = file_get_contents('php://input', false, null, 0, 4096);
$d = json_decode((string) $raw, true);
if (!is_array($d)) { http_response_code(204); exit; }

$tipo = (string) ($d['tipo'] ?? '');
if (!in_array($tipo, ['pv', 'fin', 'click', 'form', 'evt'], true)) { http_response_code(204); exit; }

// Límite por visitante para frenar abusos (200 eventos por hora)
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$v = visitante_id($ip, $ua);
$info = ua_info($ua);

try {
    $db = pdb();
    $n = (int) $db->query("SELECT COUNT(*) FROM ev WHERE v = " . $db->quote($v) . " AND t > " . (time() - 3600))->fetchColumn();
    if ($n >= 200) { http_response_code(204); exit; }

    $ref = limpia_texto($d['ref'] ?? '', 200);
    $refHost = $ref !== '' ? (string) (parse_url($ref, PHP_URL_HOST) ?: '') : '';
    $p = limpia_ruta($d['p'] ?? '/');
    $esPv = $tipo === 'pv';

    $st = $db->prepare('INSERT INTO ev (t, tipo, v, pid, p, ref, fuente, us, um, uc, pais, disp, nav, so, ancho, idioma, dur, scroll, lcp, cls, obj, sec, e404)
        VALUES (:t, :tipo, :v, :pid, :p, :ref, :fuente, :us, :um, :uc, :pais, :disp, :nav, :so, :ancho, :idioma, :dur, :scroll, :lcp, :cls, :obj, :sec, :e404)');
    $st->execute([
        ':t' => time(), ':tipo' => $tipo, ':v' => $v, ':pid' => limpia_texto($d['pid'] ?? '', 16) ?: null, ':p' => $p,
        ':ref' => $esPv && $refHost !== '' ? substr($refHost, 0, 80) : null,
        ':fuente' => $esPv ? fuente_de($refHost) : null,
        ':us' => $esPv ? (limpia_texto($d['us'] ?? '', 60) ?: null) : null,
        ':um' => $esPv ? (limpia_texto($d['um'] ?? '', 60) ?: null) : null,
        ':uc' => $esPv ? (limpia_texto($d['uc'] ?? '', 80) ?: null) : null,
        ':pais' => pais_de_ip($ip), ':disp' => $info['disp'], ':nav' => $info['nav'], ':so' => $info['so'],
        ':ancho' => entero($d['w'] ?? null, 0, 10000),
        ':idioma' => $esPv ? (substr(strtolower(limpia_texto($d['lang'] ?? '', 12)), 0, 2) ?: null) : null,
        ':dur' => $tipo === 'fin' ? entero($d['dur'] ?? null, 0, 3600000) : null,
        ':scroll' => $tipo === 'fin' ? entero($d['scroll'] ?? null, 0, 100) : null,
        ':lcp' => $tipo === 'fin' ? entero($d['lcp'] ?? null, 0, 60000) : null,
        ':cls' => $tipo === 'fin' && is_numeric($d['cls'] ?? null) ? round((float) $d['cls'], 4) : null,
        ':obj' => in_array($tipo, ['click', 'form', 'evt'], true) ? (limpia_texto($d['obj'] ?? '', 120) ?: null) : null,
        ':sec' => $tipo === 'click' ? (limpia_texto($d['sec'] ?? '', 60) ?: null) : null,
        ':e404' => $esPv && !empty($d['e404']) ? 1 : 0,
    ]);
    if (random_int(1, 500) === 1) purgar_viejos();
} catch (Throwable $e) {
    // nunca romper la página del visitante
}
http_response_code(204);
