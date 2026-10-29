<?php
/*
 * Cambio de contraseña del panel (tarea 54). Solo con sesión válida; POST JSON {actual, nueva, repite, csrf} con
 * Origin del propio dominio y «X-DL-Accion: 1» (acción del usuario: renueva la inactividad). Lógica y orden de
 * comprobaciones en ps_cambiar_contrasena() (panel/seguridad.php).
 * Respuestas: 200 {ok, sesion, csrf, cerradas, aviso} · 400 {error: actual_vacia|corta|larga|no_coinciden|actual|igual, campo}
 *             401 sin_sesion · 403 csrf · 405 metodo · 429 bloqueado · 503 sin_servicio
 */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

ps_cabeceras();
try {
    $c = ps_config();
    $e = ps_estado($c, $_SERVER);
    if (!$e['login']) ps_json(503, ['ok' => false, 'error' => 'sin_servicio']);        // R12
    $ahora = time();
    $sesion = ps_sesion_actual($e, $ahora, ps_es_accion_usuario($_SERVER));
    if ($sesion === null) ps_json(401, ['ok' => false, 'error' => 'sin_sesion']);
    $cuerpo = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (string) file_get_contents('php://input', false, null, 0, PS_MAX_CUERPO_CAMBIO + 1) : '';
    [$codigo, $datos] = ps_cambiar_contrasena($_SERVER, $cuerpo, $c, $e, $sesion, $ahora,
        static fn(string $huella): ?array => ps_sesion_rotar($e, $huella, $ahora),
        'ps_enviar_correo',
        'pais_de_ip');
    if (in_array($datos['error'] ?? '', ['actual', 'bloqueado'], true)) sleep(1);   // frena los intentos a ciegas, como el login
    ps_json($codigo, $datos);
} catch (Throwable $ex) {
    error_log('Digital Lab contraseña: ' . get_class($ex));
    ps_json(500, ['ok' => false, 'error' => 'error']);
}
