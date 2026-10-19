<?php
/*
 * Digital Lab · acceso al panel de administración
 * Recibe {email, password}. Si coinciden con los de la configuración (la contraseña guardada
 * como hash, nunca en claro), devuelve la dirección del panel, que tampoco está en el código público.
 * Configuración (en dl-auditoria-config.php, fuera de public_html y de Git):
 *   'admin_email'         => 'tu@email.com',
 *   'admin_password_hash' => '…',   // php -r "echo password_hash('LA_CONTRASEÑA', PASSWORD_DEFAULT);"
 *   'admin_url'           => 'https://claude.ai/artifact/…',
 */
declare(strict_types=1);
define('DL_AUDIT', 1);
require dirname(__DIR__) . '/auditoria/api/lib.php';

try {
    $in = read_json_body();
    if (!empty($in['web'])) fail('credenciales', 401);           // campo trampa relleno: robot
    rate_limit('login', 8, 900);                                // 8 intentos cada 15 minutos por visitante

    $email = (string) cfg('admin_email', '');
    $hash = (string) cfg('admin_password_hash', '');
    $url = (string) cfg('admin_url', '');
    if ($email === '' || $hash === '' || !str_starts_with($url, 'https://')) fail('sin_servicio', 503);

    $okEmail = hash_equals(strtolower($email), strtolower(trim((string) ($in['email'] ?? ''))));
    $okPass = password_verify((string) ($in['password'] ?? ''), $hash);
    if (!$okEmail || !$okPass) {
        sleep(1);                                               // frena los intentos a ciegas
        fail('credenciales', 401);
    }
    json_out(['ok' => true, 'url' => $url]);
} catch (AuditError $e) {
    fail($e->codigo, $e->http);
} catch (Throwable $e) {
    error_log('Digital Lab acceso: ' . $e->getMessage());
    fail('error', 500);
}
