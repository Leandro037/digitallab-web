<?php
/*
 * Digital Lab · panel · destino del <form> de «Cambiar contraseña» y del acceso cuando el JavaScript no carga
 * (tarea 55, H1). Esos formularios envían por fetch; sin JS el navegador haría un envío normal y, si fuera GET,
 * pondría datos en la URL, el historial y el registro de accesos. Por eso llevan method="post" action="…/sin-js.php".
 * Aquí NO se lee el cuerpo, no se abre sesión, no se registra nada y no se comprueba nada: POST → 400 con un aviso;
 * cualquier otro método → 405.
 */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

ps_cabeceras();
header('Content-Type: text/html; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Digital Lab</title><p>Método no permitido.</p></html>';
    exit;
}
http_response_code(400);
echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Falta JavaScript · Digital Lab</title>'
   . '<p>No se ha enviado nada. Esta página necesita JavaScript: actívalo, recarga la página e inténtalo de nuevo.</p></html>';
