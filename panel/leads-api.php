<?php
/* API de la sección Leads (solo con sesión). GET: lista · GET ?id=L-…: ficha · POST JSON: marcar atendido / volver a nuevo. */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/leads-lib.php';

ps_cabeceras();
try {
    $c = ps_config();
    $e = ps_estado($c, $_SERVER);
    if (!$e['leads']) ps_json(503, ['ok' => false, 'error' => 'sin_servicio']);     // R12
    $ahora = time();
    $sesion = ps_sesion_actual((string) $e['dir'], $ahora);
    if ($sesion === null) ps_json(401, ['ok' => false, 'error' => 'sin_sesion']);
    $cuerpo = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (string) file_get_contents('php://input', false, null, 0, 2049) : '';
    [$codigo, $datos] = leads_api($_SERVER, $_GET, $cuerpo, (string) $e['cola'], (string) $e['dir'], $c, $sesion, ps_visitante($_SERVER, $c), $ahora);
    ps_json($codigo, $datos);
} catch (Throwable $ex) {
    error_log('Digital Lab leads: ' . get_class($ex));
    ps_json(500, ['ok' => false, 'error' => 'error']);
}
