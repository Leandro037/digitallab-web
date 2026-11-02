<?php
/*
 * API de la sección Licencias (solo con sesión del panel). La lógica vive en /licencias/api/admin-lib.php (servidor de licencias de DL Studio).
 * GET ?accion=listar: lista (sondeo automático: no renueva la sesión). POST JSON {accion, csrf, …}: crear, cortar, reactivar, liberar, eliminar…
 * Cada POST exige el token CSRF de la sesión, el origen propio y la cabecera X-DL-Accion (R6, H1), como el resto del panel.
 */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

ps_cabeceras();
try {
    $c = ps_config();
    $e = ps_estado($c, $_SERVER);
    if (!$e['login']) ps_json(503, ['ok' => false, 'error' => 'sin_servicio']);     // R12
    $ahora = time();
    $esPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $sesion = ps_sesion_actual($e, $ahora, ps_es_accion_usuario($_SERVER));   // H1: solo acciones del usuario renuevan
    if ($sesion === null) ps_json(401, ['ok' => false, 'error' => 'sin_sesion']);

    $in = [];
    if ($esPost) {
        $cuerpo = (string) file_get_contents('php://input', false, null, 0, 4097);
        $in = strlen($cuerpo) <= 4096 ? json_decode($cuerpo, true, 3) : null;
        $in = is_array($in) ? $in : [];
        if (!ps_csrf_ok($_SERVER, $c, $in['csrf'] ?? null, $sesion) || !ps_es_accion_usuario($_SERVER)) ps_json(403, ['ok' => false, 'error' => 'csrf']);
        $accion = is_string($in['accion'] ?? null) ? $in['accion'] : '';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $accion = 'listar';   // por GET solo se puede leer la lista
    } else {
        ps_json(405, ['ok' => false, 'error' => 'metodo']);
    }

    $lib = dirname(__DIR__) . '/licencias/api/admin-lib.php';
    if (!is_file($lib)) ps_json(503, ['ok' => false, 'error' => 'sin_servicio']);
    require $lib;
    [$codigo, $datos] = lic_accion($accion, $in);
    ps_json($codigo, $datos);
} catch (Throwable $ex) {
    error_log('Digital Lab licencias: ' . get_class($ex) . ' ' . $ex->getMessage());
    ps_json(500, ['ok' => false, 'error' => 'error']);
}
