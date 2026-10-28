<?php
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

ps_cabeceras();
$c = ps_config();
$e = ps_estado($c, $_SERVER);
if ($e['dir'] !== null) {
    if (isset($_COOKIE[PS_COOKIE])) ps_registrar((string) $e['dir'], 'salir', '', ps_visitante($_SERVER, $c), 'ok', time());
    ps_sesion_cerrar((string) $e['dir']);
} else {
    ps_borrar_cookie();
}
header('Location: /login/', true, 303);
