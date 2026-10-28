<?php
/* «Descargar CSV» de la sección Leads: solo POST con token CSRF y Origin del propio dominio (R6). Adjunto con nombre fijo (R5). */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/leads-lib.php';

ps_cabeceras();
try {
    $c = ps_config();
    $e = ps_estado($c, $_SERVER);
    if (!$e['leads']) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); echo "Servicio no disponible.\n"; exit; }
    $ahora = time();
    $sesion = ps_sesion_actual((string) $e['dir'], $ahora);
    if ($sesion === null) { header('Location: /login/', true, 303); exit; }
    [$codigo, $cab, $cuerpo] = leads_csv_procesar($_SERVER, $_POST, (string) $e['cola'], (string) $e['dir'], $c, $sesion, ps_visitante($_SERVER, $c), $ahora);
    http_response_code($codigo);
    foreach ($cab as $k => $v) header("$k: $v");
    echo $cuerpo;
} catch (Throwable $ex) {
    error_log('Digital Lab leads CSV: ' . get_class($ex));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error.\n";
}
