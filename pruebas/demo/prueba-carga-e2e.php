<?php
/*
 * Tarea 6 · la carga que envía de verdad el navegador (pruebas/demo/carga-e2e.json, generada por e2e-demo.mjs)
 * crea un JSON en la cola al pasar por demo.php. Sin dependencias: PHP 8.1+ en línea de comandos.
 *
 *   php pruebas/demo/prueba-carga-e2e.php                                   (desde la raíz del repositorio)
 *   php prueba-carga-e2e.php /ruta/a/public_html/demo/demo.php /ruta/a/carga-e2e.json   (en el servidor, por Cron Jobs)
 *
 * Trabaja en una carpeta temporal nueva con datos ficticios y la borra al terminar. No toca la cola real ni la red.
 * Sale con 0 si todo pasa y 1 si algo falla.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DL_DEMO_PRUEBAS', 1);
require $argv[1] ?? dirname(__DIR__, 2) . '/demo/demo.php';
$rutaCarga = $argv[2] ?? __DIR__ . '/carga-e2e.json';

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

$carga = (string) @file_get_contents($rutaCarga);
$datos = json_decode($carga, true);
ok(is_array($datos), 'carga-e2e.json existe y es JSON', $rutaCarga);
if (!is_array($datos)) exit(1);
ok($datos['consent_respuesta'] === true && is_bool($datos['consent_novedades']), 'el navegador manda las casillas como booleanos');
ok(is_int($datos['t']) && $datos['t'] >= 3000, 't entero en ms y ≥ 3000');

$BASE = sys_get_temp_dir() . '/dl-demo-carga-' . bin2hex(random_bytes(6));
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
$SERVIDOR = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_ORIGIN' => 'https://dl-dev.com',
    'HTTP_SEC_FETCH_SITE' => 'same-origin',
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => '203.0.113.88',                   // IP de documentación (RFC 5737)
    'DOCUMENT_ROOT' => $WEB,
];
$AHORA = 1793000000;

// 1. La carga tal cual → 200 y un lead-*.json
[$codigo, $cuerpo] = demo_procesar($SERVIDOR, $carga, $CFG, $AHORA);
ok($codigo === 200 && ($cuerpo['ok'] ?? false) === true, 'carga del navegador → 200 {ok:true}', "$codigo " . json_encode($cuerpo));
$leads = glob("$COLA/lead-*.json") ?: [];
ok(count($leads) === 1, 'se crea exactamente un lead-*.json', (string) count($leads));
$lead = $leads ? json_decode((string) file_get_contents($leads[0]), true) : [];
foreach (['nombre', 'email', 'empresa', 'pais', 'tamano_equipo', 'usa_claude_code', 'necesidad', 'cta_origen', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'consent_version'] as $k) {
    ok(($lead[$k] ?? null) === $datos[$k], "el JSON guarda $k como lo envió el navegador", json_encode($lead[$k] ?? null));
}
ok(($lead['consent_respuesta'] ?? null) === true && ($lead['consent_novedades'] ?? null) === $datos['consent_novedades'], 'consentimientos guardados como booleanos');
ok(!array_key_exists('t', $lead) && !array_key_exists('web', $lead), 'no guarda t ni el campo trampa');
if (DIRECTORY_SEPARATOR === '/' && $leads) ok((fileperms($leads[0]) & 0777) === 0600, 'permisos 0600');

// 2. La misma carga con la casilla 1 como texto "1" (lo que mandaría un formulario sin JS) → 400 campos
$sinBool = $datos;
$sinBool['consent_respuesta'] = '1';
[$codigo, $cuerpo] = demo_procesar($SERVIDOR, json_encode($sinBool), $CFG, $AHORA + 10);
ok($codigo === 400 && ($cuerpo['campos']['consent_respuesta'] ?? '') === 'falta', 'consent_respuesta "1" en vez de true → 400 campos', "$codigo " . json_encode($cuerpo));

// 3. Sin marcar la casilla 1 → 400 campos y no se guarda nada nuevo
$sinConsent = $datos;
$sinConsent['consent_respuesta'] = false;
[$codigo] = demo_procesar($SERVIDOR, json_encode($sinConsent), $CFG, $AHORA + 20);
ok($codigo === 400 && count(glob("$COLA/lead-*.json") ?: []) === 1, 'sin casilla 1 → 400 y sigue habiendo un solo JSON');

echo "\n" . ($total - $fallos) . "/$total comprobaciones correctas\n";
exit($fallos ? 1 : 0);
