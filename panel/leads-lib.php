<?php
/*
 * Digital Lab · sección «Leads» del panel: lectura de /dl-leads, estado «atendido» y CSV (tarea 50)
 *
 * - Los leads son los JSON que guarda demo/demo.php en la cola privada (/dl-leads/lead-*.json, 0600).
 *   Todo su texto es DATO NO CONFIABLE: aquí solo se valida y se recorta; el navegador lo pinta con textContent.
 * - R9: un id que llega del navegador solo se compara con los ids del listado; nunca forma parte de una ruta.
 * - R10: «atendido» se guarda en /dl-leads/atendidos.json (0600, con bloqueo y escritura atómica). El JSON
 *   del lead no se toca y no se copia nada a public_html.
 * - R8: CSV con toda celda entre comillas y apóstrofo delante de = + - @ tabulador o retorno.
 * - Caducidad = recibido + 30 días. Lo caducado no se muestra (leads_listar) y lo borra la purga: demo.php al
 *   recibir solicitudes y, cada día aunque no haya visitas, el Cron de inc/purgar.php.
 */
declare(strict_types=1);

if (!defined('DL_PANEL')) { http_response_code(404); exit; }
require_once __DIR__ . '/seguridad.php';

const LEADS_CONSERVACION = 30 * 86400;
const LEADS_MAX_ARCHIVO = 65536;
// Copia de las listas cerradas de demo/demo.php (la prueba comprueba que coinciden)
const LEADS_PAISES = ['Argentina', 'España', 'Chile', 'Colombia', 'México', 'Perú', 'Uruguay', 'Otro país'];
const LEADS_TAMANOS = ['1', '2-10', '11-50', '51+'];
const LEADS_CLAUDE_CODE = ['si', 'no', 'nose'];
const LEADS_CTA = ['nav', 'hero', 'precio', 'final'];
const LEADS_UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];
const LEADS_FECHA = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D';
const LEADS_CSV_NOMBRE = 'leads-dl-dev.csv';

/* ------------------------------------------------------------------ */
/* Lectura                                                              */
/* ------------------------------------------------------------------ */

// Correo apto para un enlace mailto: (R7): solo caracteres corrientes, sin comillas ni espacios
function leads_correo_valido(string $e): bool
{
    return strlen($e) <= 254 && (bool) preg_match('/^[A-Za-z0-9._%+\-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*\.[A-Za-z]{2,63}\z/D', $e)
        && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}

/** Normaliza un lead leído del disco; null si no tiene la forma esperada. */
function leads_normalizar(array $d, string $idArchivo): ?array
{
    $s = static function (string $k, int $max) use ($d): string {
        $v = $d[$k] ?? '';
        if (!is_string($v)) return '';
        $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    };
    $id = $d['id'] ?? '';
    if (!is_string($id) || !preg_match(PS_ID_LEAD, $id) || $id !== $idArchivo) return null;
    $recibido = $d['recibido'] ?? '';
    if (!is_string($recibido) || !preg_match(LEADS_FECHA, $recibido)) return null;
    $ts = (new DateTimeImmutable($recibido, new DateTimeZone('UTC')))->getTimestamp();
    $email = $s('email', 254);
    $l = [
        'id' => $id,
        'recibido' => $recibido,
        'caduca' => gmdate('Y-m-d\TH:i:s\Z', $ts + LEADS_CONSERVACION),
        'nombre' => $s('nombre', 100),
        'email' => $email,
        'email_valido' => leads_correo_valido($email),
        'empresa' => $s('empresa', 120),
        'pais' => in_array($d['pais'] ?? '', LEADS_PAISES, true) ? $d['pais'] : '',
        'tamano_equipo' => in_array($d['tamano_equipo'] ?? '', LEADS_TAMANOS, true) ? $d['tamano_equipo'] : '',
        'usa_claude_code' => in_array($d['usa_claude_code'] ?? '', LEADS_CLAUDE_CODE, true) ? $d['usa_claude_code'] : '',
        'necesidad' => is_string($d['necesidad'] ?? null) ? mb_substr(str_replace("\r", '', $d['necesidad']), 0, 1000) : '',
        'cta_origen' => in_array($d['cta_origen'] ?? '', LEADS_CTA, true) ? $d['cta_origen'] : '',
        'consent_respuesta' => ($d['consent_respuesta'] ?? false) === true,
        'consent_novedades' => ($d['consent_novedades'] ?? false) === true,
        'consent_version' => preg_match('/^[A-Za-z0-9._-]{1,40}$/D', (string) (is_string($d['consent_version'] ?? null) ? $d['consent_version'] : '')) ? $d['consent_version'] : '',
        'consent_fecha' => is_string($d['consent_fecha'] ?? null) && preg_match(LEADS_FECHA, $d['consent_fecha']) ? $d['consent_fecha'] : '',
    ];
    foreach (LEADS_UTM as $k) {
        $v = $d[$k] ?? '';
        $l[$k] = is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $v) ? $v : '';
    }
    return $l;
}

/**
 * Leads vigentes de la cola, del más reciente al más antiguo. Solo archivos lead-AAAAMMDDTHHMMSSZ-<16 hex>.json.
 * Detalle 2 del 16 (tarea 52): un lead con 30 días o más (recibido + 30 días <= ahora) ya no se muestra, ni en
 * la lista, ni en la ficha, ni en el CSV, aunque la purga aún no lo haya borrado del disco.
 */
function leads_listar(string $cola, int $ahora): array
{
    $out = [];
    foreach (glob($cola . '/lead-*.json', GLOB_NOSORT) ?: [] as $f) {
        if (!preg_match('/^lead-(\d{8})T(\d{6})Z-([a-f0-9]{16})\.json\z/D', basename($f), $m)) continue;
        if (is_link($f) || !is_file($f)) continue;
        $tam = @filesize($f);
        if ($tam === false || $tam > LEADS_MAX_ARCHIVO) continue;
        try {
            $d = json_decode((string) @file_get_contents($f), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            continue;
        }
        if (!is_array($d)) continue;
        $l = leads_normalizar($d, "L-{$m[1]}-{$m[2]}-{$m[3]}");
        if ($l !== null && strtotime($l['caduca']) > $ahora) $out[] = $l;
    }
    usort($out, static fn(array $a, array $b): int => [$b['recibido'], $b['id']] <=> [$a['recibido'], $a['id']]);
    return $out;
}

/** R9: busca por comparación en el listado, nunca construyendo una ruta con el id recibido. */
function leads_buscar(array $lista, mixed $id): ?array
{
    if (!is_string($id) || !preg_match(PS_ID_LEAD, $id)) return null;
    foreach ($lista as $l) {
        if (hash_equals($l['id'], $id)) return $l;
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Estado «atendido» (R10)                                              */
/* ------------------------------------------------------------------ */

/** @return array<string,string> id → fecha UTC en que se marcó */
function atendidos_leer(string $cola): array
{
    $f = $cola . '/atendidos.json';
    if (!is_file($f) || is_link($f)) return [];
    $d = json_decode((string) @file_get_contents($f), true);
    $out = [];
    foreach (is_array($d) ? $d : [] as $id => $t) {
        if (is_string($id) && preg_match(PS_ID_LEAD, $id) && is_string($t) && preg_match(LEADS_FECHA, $t)) $out[$id] = $t;
    }
    return $out;
}

/**
 * Marca (o desmarca) un lead con bloqueo y escritura atómica (temporal + rename, 0600).
 * Quita de paso los ids que ya no están en la cola. Devuelve el índice nuevo o null si falla.
 */
function atendidos_cambiar(string $cola, string $id, bool $atendido, int $ahora, array $idsVigentes): ?array
{
    if (!preg_match(PS_ID_LEAD, $id)) return null;
    return atendidos_escribir($cola, $idsVigentes, static function (array $mapa) use ($id, $atendido, $ahora): array {
        if ($atendido) $mapa[$id] = $mapa[$id] ?? gmdate('Y-m-d\TH:i:s\Z', $ahora);
        else unset($mapa[$id]);
        return $mapa;
    });
}

/** Purga diaria: quita de atendidos.json los ids que ya no están vigentes. Devuelve cuántos quitó (null si falla). */
function atendidos_podar(string $cola, array $idsVigentes): ?int
{
    if (!is_file($cola . '/atendidos.json')) return 0;
    $antes = count(atendidos_leer($cola));
    $mapa = atendidos_escribir($cola, $idsVigentes, static fn(array $m): array => $m);
    return $mapa === null ? null : $antes - count($mapa);
}

/** Lee, aplica $cambio y escribe atendidos.json bajo flock (temporal + rename, 0600), solo con ids vigentes. */
function atendidos_escribir(string $cola, array $idsVigentes, callable $cambio): ?array
{
    $viejo = umask(0077);
    $fh = @fopen($cola . '/atendidos.lock', 'c');
    umask($viejo);
    if (!$fh) return null;
    try {
        if (!flock($fh, LOCK_EX)) return null;
        $mapa = $cambio(array_intersect_key(atendidos_leer($cola), array_flip($idsVigentes)));
        ksort($mapa);
        $tmp = $cola . '/.tmp-atendidos-' . bin2hex(random_bytes(8));
        $viejo = umask(0077);
        $ok = @file_put_contents($tmp, json_encode($mapa, JSON_PRETTY_PRINT) . "\n") !== false;
        umask($viejo);
        if ($ok) { @chmod($tmp, 0600); $ok = @rename($tmp, $cola . '/atendidos.json'); }
        if (!$ok) { @unlink($tmp); error_log('Digital Lab panel: no se pudo guardar atendidos.json'); return null; }
        return $mapa;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Lo que va a la lista (sin la necesidad ni los consentimientos: eso va en la ficha). */
function leads_resumen(array $l, array $atendidos): array
{
    return [
        'id' => $l['id'], 'recibido' => $l['recibido'], 'caduca' => $l['caduca'],
        'nombre' => $l['nombre'], 'empresa' => $l['empresa'], 'email' => $l['email'],
        'pais' => $l['pais'], 'tamano_equipo' => $l['tamano_equipo'], 'usa_claude_code' => $l['usa_claude_code'],
        'utm_source' => $l['utm_source'], 'utm_campaign' => $l['utm_campaign'], 'utm_content' => $l['utm_content'],
        'atendido' => $atendidos[$l['id']] ?? '',
    ];
}

/* ------------------------------------------------------------------ */
/* API (sin efectos globales)                                            */
/* ------------------------------------------------------------------ */

/**
 * GET           → lista de resúmenes
 * GET ?id=L-…   → ficha completa
 * POST JSON {accion:'atendido', id, atendido:bool, csrf} → nuevo estado (R6)
 * @return array{0:int, 1:array<string,mixed>}
 */
function leads_api(array $server, array $get, string $cuerpo, string $cola, string $dir, array $c, array $sesion, string $v, int $ahora): array
{
    $metodo = $server['REQUEST_METHOD'] ?? '';
    if ($metodo === 'GET') {
        $lista = leads_listar($cola, $ahora);
        $atendidos = atendidos_leer($cola);
        if (!array_key_exists('id', $get)) {
            ps_registrar($dir, 'lista', '', $v, 'ok', $ahora);
            return [200, ['ok' => true, 'ahora' => gmdate('Y-m-d\TH:i:s\Z', $ahora), 'leads' => array_map(static fn($l) => leads_resumen($l, $atendidos), $lista)]];
        }
        $id = $get['id'];
        if (!is_string($id) || !preg_match(PS_ID_LEAD, $id)) return [400, ['ok' => false, 'error' => 'id']];
        $l = leads_buscar($lista, $id);
        if ($l === null) { ps_registrar($dir, 'ficha', $id, $v, 'no_existe', $ahora); return [404, ['ok' => false, 'error' => 'no_existe']]; }
        ps_registrar($dir, 'ficha', $id, $v, 'ok', $ahora);
        return [200, ['ok' => true, 'ahora' => gmdate('Y-m-d\TH:i:s\Z', $ahora), 'lead' => $l + ['atendido' => $atendidos[$id] ?? '']]];
    }
    if ($metodo !== 'POST') return [405, ['ok' => false, 'error' => 'metodo']];

    $in = strlen($cuerpo) <= 2048 ? json_decode($cuerpo, true, 3) : null;
    if (!is_array($in)) return [400, ['ok' => false, 'error' => 'datos']];
    if (!ps_csrf_ok($server, $c, $in['csrf'] ?? null, $sesion)) {
        ps_registrar($dir, 'atendido', '', $v, 'csrf', $ahora);
        return [403, ['ok' => false, 'error' => 'csrf']];
    }
    if (($in['accion'] ?? '') !== 'atendido' || !is_bool($in['atendido'] ?? null)) return [400, ['ok' => false, 'error' => 'datos']];
    $id = $in['id'] ?? null;
    if (!is_string($id) || !preg_match(PS_ID_LEAD, $id)) return [400, ['ok' => false, 'error' => 'id']];
    $lista = leads_listar($cola, $ahora);
    $accion = $in['atendido'] ? 'atendido' : 'nuevo';
    if (leads_buscar($lista, $id) === null) { ps_registrar($dir, $accion, $id, $v, 'no_existe', $ahora); return [404, ['ok' => false, 'error' => 'no_existe']]; }
    $mapa = atendidos_cambiar($cola, $id, $in['atendido'], $ahora, array_column($lista, 'id'));
    if ($mapa === null) { ps_registrar($dir, $accion, $id, $v, 'error', $ahora); return [500, ['ok' => false, 'error' => 'error']]; }
    ps_registrar($dir, $accion, $id, $v, 'ok', $ahora);
    return [200, ['ok' => true, 'id' => $id, 'atendido' => $mapa[$id] ?? '']];
}

/* ------------------------------------------------------------------ */
/* CSV (R5, R6, R8)                                                      */
/* ------------------------------------------------------------------ */

function leads_csv_celda(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r\n") !== false) $v = "'" . $v;
    return '"' . str_replace('"', '""', $v) . '"';
}

// Separador «;» y BOM UTF-8 para que Excel en español lo abra por columnas y con tildes
function leads_csv(array $lista, array $atendidos): string
{
    $cols = ['id', 'recibido', 'caduca', 'estado', 'atendido', 'nombre', 'email', 'empresa', 'pais', 'tamano_equipo',
        'usa_claude_code', 'cta_origen', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'necesidad',
        'consent_respuesta', 'consent_novedades', 'consent_version', 'consent_fecha'];
    $out = "\xEF\xBB\xBF" . implode(';', array_map('leads_csv_celda', $cols)) . "\r\n";
    foreach ($lista as $l) {
        $at = $atendidos[$l['id']] ?? '';
        $fila = [];
        foreach ($cols as $k) {
            $v = match ($k) {
                'estado' => $at !== '' ? 'atendido' : 'nuevo',
                'atendido' => $at,
                'consent_respuesta', 'consent_novedades' => $l[$k] ? 'si' : 'no',
                default => (string) ($l[$k] ?? ''),
            };
            $fila[] = leads_csv_celda($v);
        }
        $out .= implode(';', $fila) . "\r\n";
    }
    return $out;
}

/** @return array{0:int, 1:array<string,string>, 2:string} [código, cabeceras, cuerpo] */
function leads_csv_procesar(array $server, array $post, string $cola, string $dir, array $c, array $sesion, string $v, int $ahora): array
{
    $texto = ['Content-Type' => 'text/plain; charset=utf-8'];
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return [405, $texto + ['Allow' => 'POST'], "Solo POST.\n"];
    if (!ps_csrf_ok($server, $c, $post['csrf'] ?? null, $sesion)) {
        ps_registrar($dir, 'csv', '', $v, 'csrf', $ahora);
        return [403, $texto, "Petición rechazada.\n"];
    }
    $lista = leads_listar($cola, $ahora);
    ps_registrar($dir, 'csv', '', $v, 'ok', $ahora);
    return [200, [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . LEADS_CSV_NOMBRE . '"',
    ], leads_csv($lista, atendidos_leer($cola))];
}
