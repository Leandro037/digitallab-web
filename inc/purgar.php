<?php
/*
 * Digital Lab · purga diaria de la cola privada /dl-leads y de la carpeta del panel (tarea 51)
 *
 * Detalle 2 del 16 (tarea 52): la purga de /demo solo corre cuando alguien envía el formulario. Este script la
 * hace una vez al día aunque no haya visitas. Solo línea de comandos: por la web responde 404 (y además inc/
 * está cerrado con .htaccess).
 *
 * Qué borra (las mismas reglas que ya existían, sin reglas nuevas):
 *   - /dl-leads/lead-*.json con más de 30 días (demo_purgar: privacidad publicada), temporales abandonados,
 *     códigos de límite caducados y topes de días anteriores;
 *   - de /dl-leads/atendidos.json, las marcas de leads que ya no están vigentes;
 *   - en la carpeta del panel: registros de acceso de más de 90 días, contadores de bloqueo viejos y sesiones
 *     caducadas (ps_purgar).
 * Imprime solo recuentos (nunca datos de leads). Sale con 0 si ha podido purgar la cola y 1 si no.
 *
 * Cron Job en hPanel (lo crea el humano): Avanzado → Cron Jobs → tipo «PHP» →
 *   comando:  /home/<usuario>/public_html/inc/purgar.php      (ruta real: hPanel → Archivos → Cuentas FTP)
 *   cuándo:   una vez al día (p. ej. minuto 15, hora 4: «15 4 * * *»)
 * Equivalente en tipo «Personalizado»: php /home/<usuario>/public_html/inc/purgar.php
 * Comprobación a mano por SSH: php ~/public_html/inc/purgar.php  → «purga: … leads borrados …»
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
defined('DL_DEMO_LIBRERIA') || define('DL_DEMO_LIBRERIA', 1);
defined('DL_PANEL') || define('DL_PANEL', 1);
require_once dirname(__DIR__) . '/demo/demo.php';
require_once dirname(__DIR__) . '/panel/seguridad.php';
require_once dirname(__DIR__) . '/panel/leads-lib.php';

/**
 * Purga sin efectos globales (la prueban pruebas/panel/prueba-panel.php y el E2E). null = carpeta no disponible.
 * @return array{cola:bool, panel:bool, leads_borrados:int, leads_quedan:int, atendidos_quitados:int}
 */
function purga_todo(?string $cola, ?string $panelDir, int $ahora): array
{
    $r = ['cola' => $cola !== null, 'panel' => $panelDir !== null, 'leads_borrados' => 0, 'leads_quedan' => 0, 'atendidos_quitados' => 0];
    if ($cola !== null) {
        $antes = count(glob("$cola/lead-*.json") ?: []);
        demo_purgar($cola, $ahora);
        clearstatcache();
        $r['leads_quedan'] = count(glob("$cola/lead-*.json") ?: []);
        $r['leads_borrados'] = $antes - $r['leads_quedan'];
        $q = atendidos_podar($cola, array_column(leads_listar($cola, $ahora), 'id'));
        $r['atendidos_quitados'] = $q ?? -1;
    }
    if ($panelDir !== null) ps_purgar($panelDir, $ahora);
    return $r;
}

function purga_main(): int
{
    ini_set('display_errors', '0');
    $cola = demo_cola_segura(demo_cargar_config(), []);
    // La carpeta del panel sale de dl-auditoria-config.php (panel_dir) o, por defecto, /dl-leads/panel
    defined('DL_AUDIT') || define('DL_AUDIT', 1);
    require_once dirname(__DIR__) . '/panel/lib.php';
    $c = ps_config();
    $panel = ps_carpeta_segura($c['dir'], $c['web'], [], false);
    $r = purga_todo($cola, $panel, time());
    echo gmdate('Y-m-d H:i:s') . ' UTC · purga: '
        . ($r['cola'] ? "{$r['leads_borrados']} leads borrados, {$r['leads_quedan']} quedan, {$r['atendidos_quitados']} marcas de atendido quitadas" : 'SIN COLA (revisa /dl-leads/config.php)')
        . ' · panel: ' . ($r['panel'] ? 'purgado' : 'sin carpeta (aún no se ha entrado nunca)') . "\n";
    return $r['cola'] ? 0 : 1;
}

if (!defined('DL_PURGA_PRUEBAS')) exit(purga_main());
