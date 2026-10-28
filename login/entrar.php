<?php
/*
 * Digital Lab · acceso al panel de administración (requisitos R1-R12, panel/seguridad.php)
 * Recibe {email, password, web} por POST (JSON, con Origin del propio dominio). Si coinciden con los de
 * la configuración (contraseña guardada como hash argon2id, nunca en claro), abre la sesión del panel.
 * Respuestas: 200 {ok, url} · 401 {error:'credenciales'} (respuesta única: datos mal, bloqueo o robot)
 *             403 origen · 405 método · 503 sin_servicio (falta configuración o no hay contador de intentos)
 * Configuración: cabecera de panel/seguridad.php.
 */
declare(strict_types=1);
define('DL_AUDIT', 1);
define('DL_PANEL', 1);
require dirname(__DIR__) . '/panel/lib.php';

ps_cabeceras();
try {
    $c = ps_config();
    $estado = ps_estado($c, $_SERVER);
    $ahora = time();
    $cuerpo = (string) file_get_contents('php://input', false, null, 0, PS_MAX_CUERPO + 1);
    [$codigo, $datos] = ps_login($_SERVER, $cuerpo, $c, $estado, $ahora,
        static fn(): bool => ps_sesion_nueva((string) $estado['dir'], $ahora),
        'ps_enviar_correo',
        'pais_de_ip');
    if ($codigo === 401) sleep(1);                              // frena los intentos a ciegas
    ps_json($codigo, $datos);
} catch (Throwable $e) {
    error_log('Digital Lab acceso: ' . get_class($e));
    ps_json(500, ['ok' => false, 'error' => 'error']);
}
