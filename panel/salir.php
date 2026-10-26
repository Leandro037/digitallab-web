<?php
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

if (isset($_COOKIE['dlpanel'])) {
    panel_sesion_iniciar();
    $_SESSION = [];
    session_destroy();
    setcookie('dlpanel', '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
}
header('Cache-Control: no-store');
header('Location: /login/');
