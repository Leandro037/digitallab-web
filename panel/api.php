<?php
/* API del panel: solo con sesión iniciada en /login/. GET ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&p=&pais=&disp=&fuente= */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
try {
    panel_exigir();
    $g = static fn (string $k, int $max = 120): string => mb_substr(trim((string) ($_GET[$k] ?? '')), 0, $max);
    $hoy = (new DateTime('now', new DateTimeZone((string) cfg('panel_zona', 'America/Argentina/Buenos_Aires'))))->format('Y-m-d');
    $desde = $g('desde', 10); $hasta = $g('hasta', 10);
    if ($hasta === '') $hasta = $hoy;
    if ($desde === '') $desde = (new DateTime($hasta))->modify('-6 days')->format('Y-m-d');
    $f = ['p' => $g('p'), 'pais' => strtoupper($g('pais', 2)), 'disp' => $g('disp', 20), 'fuente' => $g('fuente', 80)];
    json_out(['ok' => true] + panel_datos($desde, $hasta, $f));
} catch (AuditError $e) {
    fail($e->codigo, $e->http);
} catch (Throwable $e) {
    error_log('Digital Lab panel: ' . $e->getMessage());
    fail('error', 500);
}
