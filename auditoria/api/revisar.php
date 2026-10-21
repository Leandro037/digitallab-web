<?php
/*
 * Digital Lab · auditoría web, paso 1: revisión desde el servidor
 * Recibe {url}, descarga la web de forma segura y devuelve las comprobaciones de seguridad,
 * privacidad, SEO, accesibilidad, rendimiento y conversión (las mismas que el flujo «Auditoría web»
 * del organigrama, más las que solo puede hacer un servidor), y un id para los agentes.
 */
declare(strict_types=1);
define('DL_AUDIT', 1);
require __DIR__ . '/lib.php';

try {
    $in = read_json_body();
    if (!empty($in['web'])) fail('error');                       // campo trampa relleno: robot
    rate_limit('revisar', (int) cfg('limite_hora', 6), 3600);   // también cuenta los códigos fallidos
    // Código de acceso: sin él configurado no se audita nada (protege el saldo de la IA)
    $codigo = (string) cfg('codigo_acceso', '');
    if ($codigo === '' || !hash_equals($codigo, trim((string) ($in['codigo'] ?? '')))) fail('codigo', 403);
    cleanup();

    $url = normalize_url((string) ($in['url'] ?? ''));
    $res = audit_site($url);
    $id = new_id();
    store_put($id, ['creado' => time(), 'url' => $res['url'], 'host' => $res['host'], 'checks' => $res['checks'],
        'terceros' => $res['terceros'], 'stats' => $res['stats'], 'out' => [], 'llamadas' => 0]);
    json_out([
        'ok' => true,
        'id' => $id,
        'url' => $res['url'],
        'host' => $res['host'],
        'checks' => $res['checks'],
        'terceros' => $res['terceros'],
        'stats' => $res['stats'],
        'psiKey' => (string) cfg('google_psi_key', ''),
    ]);
} catch (AuditError $e) {
    fail($e->codigo, $e->http);
} catch (Throwable $e) {
    error_log('Digital Lab auditoría (revisar): ' . $e->getMessage());
    fail('error', 500);
}

function check(string $area, string $estado, string $titulo, string $detalle = ''): array
{
    return ['area' => $area, 'estado' => $estado, 'titulo' => $titulo, 'detalle' => $detalle];
}

function clean_text(?string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

function audit_site(string $url): array
{
    $r = http_follow($url);
    $final = $r['url'];
    $p = parse_url($final);
    $host = $p['host'];
    $root = $p['scheme'] . '://' . $host;
    $h = $r['headers'];
    $checks = [];
    $add = function (string $area, string $estado, string $titulo, string $detalle = '') use (&$checks) { $checks[] = check($area, $estado, $titulo, $detalle); };
    if ($r['status'] >= 400) throw new AuditError('inaccesible', 422);

    $html = $r['body'];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $q = fn(string $query) => iterator_to_array($xp->query($query) ?: []);
    $first = fn(string $query) => ($n = $xp->query($query)) && $n->length ? $n->item(0) : null;
    $lower = "translate(%s,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";
    $meta = function (string $name) use ($xp, $lower): string {
        $n = $xp->query("//meta[" . sprintf($lower, '@name') . "='$name' or @property='$name']/@content");
        return $n && $n->length ? trim($n->item(0)->nodeValue) : '';
    };
    $attr = fn(DOMElement $el, string $a) => strtolower(trim($el->getAttribute($a)));

    /* ---------- Seguridad ---------- */
    $https = $p['scheme'] === 'https';
    $add('seguridad', $https ? 'ok' : 'bad', $https ? 'La web usa HTTPS' : 'La web no usa HTTPS', $https ? 'La conexión con tus visitantes va cifrada.' : 'Los navegadores la marcan como «No segura».');
    if ($https) {
        try {
            $plain = http_once('http://' . $host . '/', 20000);
            $loc = $plain['headers']['location'] ?? '';
            if ($plain['status'] >= 300 && $plain['status'] < 400 && str_starts_with(strtolower($loc), 'https://')) $add('seguridad', 'ok', 'Redirige siempre a HTTPS');
            else $add('seguridad', 'warn', 'La versión sin HTTPS no redirige', 'Quien escriba la dirección sin «https» entra por una conexión sin cifrar.');
        } catch (AuditError $e) { /* el puerto 80 puede estar cerrado: no es un problema */ }
        if (!$r['tls_ok']) $add('seguridad', 'bad', 'Certificado de seguridad no válido', 'Los navegadores muestran un aviso de peligro al entrar.');
        elseif ($r['cert_expira']) {
            $days = (int) floor(($r['cert_expira'] - time()) / 86400);
            if ($days > 21) $add('seguridad', 'ok', 'Certificado de seguridad válido', "Caduca dentro de $days días.");
            else $add('seguridad', 'warn', 'El certificado caduca pronto', "Quedan $days días: revisa que se renueve solo.");
        }
    }
    $hsts = isset($h['strict-transport-security']);
    $add('seguridad', $hsts ? 'ok' : ($https ? 'bad' : 'warn'), $hsts ? 'HSTS activo' : 'Falta HSTS', 'Obliga al navegador a usar siempre HTTPS.');
    $csp = $h['content-security-policy'] ?? '';
    $add('seguridad', $csp !== '' ? 'ok' : 'warn', $csp !== '' ? 'Tiene política de contenido (CSP)' : 'Sin política de contenido (CSP)', 'Ayuda a bloquear código inyectado.');
    $frame = isset($h['x-frame-options']) || stripos($csp, 'frame-ancestors') !== false;
    $add('seguridad', $frame ? 'ok' : 'warn', $frame ? 'Protegida contra incrustación' : 'Se puede incrustar en otras webs', 'Evita que otra web la muestre dentro de la suya para engañar a tus visitantes.');
    $nosniff = stripos($h['x-content-type-options'] ?? '', 'nosniff') !== false;
    $add('seguridad', $nosniff ? 'ok' : 'warn', $nosniff ? 'Tipo de contenido protegido (nosniff)' : 'Falta la cabecera nosniff', 'Impide que el navegador interprete archivos de forma peligrosa.');
    $leak = implode(' · ', array_filter([$h['server'] ?? '', $h['x-powered-by'] ?? ''], fn($v) => $v !== '' && preg_match('/\d/', $v)));
    if ($leak !== '') $add('seguridad', 'warn', 'El servidor muestra su versión', 'Revela «' . clean_text($leak, 60) . '», una pista útil para atacantes.');
    if ($https && preg_match('/\s(?:src|href)=["\']http:\/\/(?!www\.w3\.org)/i', $html)) $add('seguridad', 'warn', 'Carga recursos sin HTTPS', 'Algunas imágenes o scripts se piden por http:// y el navegador puede bloquearlos.');
    if ($q('//form[starts-with(' . sprintf($lower, '@action') . ',"http:")]')) $add('seguridad', 'bad', 'Un formulario envía datos sin cifrar', 'Lo que escriben tus visitantes viaja sin protección.');

    /* ---------- Privacidad ---------- */
    $trackers = [];
    foreach (['Google Analytics' => '/googletagmanager|gtag\(|google-analytics/i', 'Meta Pixel' => '/fbq\(|connect\.facebook\.net/i', 'Hotjar' => '/hotjar/i', 'Microsoft Clarity' => '/clarity\.ms/i', 'TikTok Pixel' => '/analytics\.tiktok/i'] as $name => $re) {
        if (preg_match($re, $html)) $trackers[] = $name;
    }
    $consent = (bool) preg_match('/cookiebot|cookieyes|onetrust|consentmanager|cookie-?consent|cookie-?notice|cookie-?law|aceptar (las )?cookies|usamos cookies|utilizamos cookies/i', $html);
    if ($trackers) $add('privacidad', $consent ? 'ok' : 'bad', $consent ? 'Seguimiento con aviso de cookies' : 'Seguimiento sin aviso de cookies', 'Usa ' . implode(', ', $trackers) . ($consent ? ' y muestra un aviso de cookies.' : ' y no detectamos un aviso de cookies: es un riesgo legal.'));
    else $add('privacidad', 'ok', 'Sin herramientas de seguimiento detectadas', 'No encontramos Google Analytics, Meta Pixel ni similares.');

    /* ---------- SEO ---------- */
    $title = clean_text($first('//title')?->textContent, 200);
    $tl = mb_strlen($title);
    $add('seo', $title === '' ? 'bad' : ($tl < 10 || $tl > 65 ? 'warn' : 'ok'), $title === '' ? 'La página no tiene título' : 'Título de la página' . ($tl > 65 ? ' demasiado largo' : ($tl < 10 ? ' demasiado corto' : ' correcto')), $title !== '' ? '«' . mb_substr($title, 0, 70) . "» ($tl caracteres)." : 'Es lo que aparece en la pestaña y en Google.');
    $desc = clean_text($meta('description'), 300);
    $dl = mb_strlen($desc);
    $add('seo', $desc === '' ? 'bad' : ($dl < 50 || $dl > 160 ? 'warn' : 'ok'), $desc !== '' ? 'Descripción para Google' . ($dl > 160 ? ' larga' : ($dl < 50 ? ' corta' : ' correcta')) : 'Falta la descripción para Google', $desc !== '' ? "$dl caracteres." : 'Google mostrará un fragmento elegido al azar.');
    $n1 = count($q('//h1'));
    $add('seo', $n1 === 1 ? 'ok' : ($n1 === 0 ? 'bad' : 'warn'), $n1 === 1 ? 'Un único título principal (H1)' : ($n1 === 0 ? 'Sin título principal (H1)' : "Hay $n1 títulos principales (H1)"), 'Le dice a Google de qué trata la página.');
    $viewport = $meta('viewport');
    $add('seo', $viewport !== '' ? 'ok' : 'bad', $viewport !== '' ? 'Preparada para móviles (viewport)' : 'No está preparada para móviles', 'Sin esto, en el celular se ve diminuta.');
    $canon = (bool) $first("//link[" . sprintf($lower, '@rel') . "='canonical']");
    $add('seo', $canon ? 'ok' : 'warn', $canon ? 'Tiene dirección canónica' : 'Sin dirección canónica', 'Evita que Google vea páginas duplicadas.');
    $og = $meta('og:title') !== '' && $meta('og:image') !== '';
    $add('seo', $og ? 'ok' : 'warn', $og ? 'Vista previa para redes y WhatsApp' : 'Sin vista previa al compartir', 'Al compartir el enlace se ve título e imagen.');
    $ld = (bool) $first("//script[@type='application/ld+json']");
    $add('seo', $ld ? 'ok' : 'warn', $ld ? 'Tiene datos estructurados' : 'Sin datos estructurados', 'Ayudan a Google a mostrar datos de tu negocio.');
    if (preg_match('/noindex/i', $meta('robots') . ' ' . ($h['x-robots-tag'] ?? ''))) $add('seo', 'bad', 'La página pide no aparecer en Google', 'Tiene «noindex».');
    $sitemapUrl = '';
    try {
        $rb = http_once($root . '/robots.txt', 100000);
        if ($rb['status'] === 200 && trim($rb['body']) !== '' && !preg_match('/<html/i', $rb['body'])) {
            $add('seo', 'ok', 'Tiene robots.txt');
            if (preg_match('/^\s*sitemap:\s*(\S+)/im', $rb['body'], $m)) $sitemapUrl = $m[1];
        } else $add('seo', 'warn', 'Sin robots.txt', 'Indica a los buscadores qué pueden rastrear y dónde está el sitemap.');
    } catch (AuditError $e) { /* sin robots.txt accesible */ }
    $hasSitemap = $sitemapUrl !== '';
    if (!$hasSitemap) {
        try {
            $sm = http_once($root . '/sitemap.xml', 100000);
            $hasSitemap = $sm['status'] === 200 && (str_contains($sm['body'], '<urlset') || str_contains($sm['body'], '<sitemapindex'));
        } catch (AuditError $e) { /* sin sitemap accesible */ }
    }
    $add('seo', $hasSitemap ? 'ok' : 'warn', $hasSitemap ? 'Tiene sitemap' : 'Sin sitemap', 'Facilita que Google encuentre todas las páginas.');

    /* ---------- Accesibilidad ---------- */
    $imgs = $q('//img');
    $noAlt = count(array_filter($imgs, fn($i) => !$i->hasAttribute('alt')));
    if ($imgs) $add('accesibilidad', $noAlt === 0 ? 'ok' : 'warn', $noAlt === 0 ? 'Todas las imágenes tienen texto alternativo' : "$noAlt de " . count($imgs) . ' imágenes sin texto alternativo', 'Lo leen los lectores de pantalla y también Google.');
    $lang = clean_text($first('//html/@lang')?->nodeValue, 20);
    $add('accesibilidad', $lang !== '' ? 'ok' : 'warn', $lang !== '' ? "Idioma declarado ($lang)" : 'Sin idioma declarado', 'Los lectores de pantalla pronuncian mejor.');
    $fields = array_values(array_filter($q('//input|//select|//textarea'), fn($el) => !in_array($attr($el, 'type'), ['hidden', 'submit', 'button', 'reset', 'image'], true)));
    $unl = count(array_filter($fields, function ($el) use ($xp) {
        if ($el->getAttribute('aria-label') !== '' || $el->getAttribute('aria-labelledby') !== '' || $el->getAttribute('title') !== '') return false;
        $id = $el->getAttribute('id');
        if ($id !== '' && !str_contains($id, '"') && $xp->query('//label[@for="' . $id . '"]')->length) return false;
        return !$xp->query('ancestor::label', $el)->length;
    }));
    if ($fields) $add('accesibilidad', $unl ? 'warn' : 'ok', $unl ? "$unl campos de formulario sin etiqueta" : 'Formularios con etiquetas', 'Sin etiqueta, quien usa lector de pantalla no sabe qué escribir.');
    $emptyLinks = count(array_filter($q('//a'), fn($a) => trim($a->textContent) === '' && $a->getAttribute('aria-label') === '' && !$xp->query('.//img[@alt!=""]', $a)->length));
    $emptyBtns = count(array_filter($q('//button'), fn($b) => trim($b->textContent) === '' && $b->getAttribute('aria-label') === ''));
    if ($emptyLinks + $emptyBtns) $add('accesibilidad', 'warn', ($emptyLinks + $emptyBtns) . ' enlaces o botones sin texto', 'Un lector de pantalla los anuncia vacíos.');
    else $add('accesibilidad', 'ok', 'Enlaces y botones con texto');
    $lv = array_map(fn($x) => (int) substr($x->nodeName, 1), $q('//h1|//h2|//h3|//h4|//h5|//h6'));
    $jump = false;
    for ($i = 1; $i < count($lv); $i++) if ($lv[$i] - $lv[$i - 1] > 1) $jump = true;
    if ($lv) $add('accesibilidad', $jump ? 'warn' : 'ok', $jump ? 'Los títulos saltan niveles' : 'Títulos en orden', 'Ayuda a recorrer la página con teclado o lector de pantalla.');
    if (preg_match('/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?(?![\d.])/i', $viewport)) $add('accesibilidad', 'bad', 'La web impide hacer zoom', 'Quien ve poco no puede agrandar el texto en el celular.');
    if ($q('//nav')) {
        $firstA = $first('//body//a');
        $skip = $firstA && str_starts_with($firstA->getAttribute('href'), '#');
        $add('accesibilidad', $skip ? 'ok' : 'warn', $skip ? 'Enlace para saltar al contenido' : 'Sin enlace para saltar al contenido', 'Quien navega con teclado tiene que recorrer todo el menú en cada página.');
    }

    /* ---------- Conversión (formularios) ---------- */
    $noAc = count(array_filter($fields, fn($el) => $el->nodeName === 'input' && $el->getAttribute('autocomplete') === '' && in_array($attr($el, 'type'), ['text', 'email', 'tel', ''], true)));
    if ($noAc) $add('conversion', 'warn', "$noAc campos sin autocompletado", 'El navegador no puede rellenarlos solo y completar el formulario cuesta más.');
    $badType = count(array_filter($fields, function ($el) use ($attr) {
        $nm = $el->getAttribute('name') . ' ' . $el->getAttribute('placeholder');
        $ty = $attr($el, 'type') ?: 'text';
        return (preg_match('/mail|correo/i', $nm) && $ty !== 'email') || (preg_match('/tel|phone|whats|celular|m[oó]vil/iu', $nm) && $ty !== 'tel');
    }));
    if ($badType) $add('conversion', 'warn', 'Campos con el tipo equivocado', 'En el celular no aparece el teclado adecuado para email o teléfono.');

    /* ---------- Rendimiento ---------- */
    $ttfb = $r['ttfb'];
    $t = number_format($ttfb, 2, ',', '');
    if ($ttfb < 0.6) $add('rendimiento', 'ok', 'El servidor responde rápido', "$t s hasta el primer byte.");
    else $add('rendimiento', $ttfb < 1.5 ? 'warn' : 'bad', 'El servidor tarda en responder', "$t s hasta el primer byte; lo ideal es menos de 0,6 s.");
    $enc = strtolower($h['content-encoding'] ?? '');
    $gz = $enc !== '' && $enc !== 'identity';
    $add('rendimiento', $gz ? 'ok' : 'warn', $gz ? 'Envía el contenido comprimido' : 'No comprime el contenido', $gz ? strtoupper($enc) . '.' : 'Activar GZIP o Brotli reduce el peso de la página.');
    if ($imgs) {
        $noDim = count(array_filter($imgs, fn($im) => $im->getAttribute('width') === '' || $im->getAttribute('height') === ''));
        $add('rendimiento', $noDim ? 'warn' : 'ok', $noDim ? "$noDim imágenes sin tamaño definido" : 'Imágenes con tamaño definido', $noDim ? 'La página salta mientras carga (CLS) y se siente inestable.' : '');
    }
    if (count($imgs) > 3) {
        $lazy = count(array_filter($imgs, fn($im) => $attr($im, 'loading') === 'lazy'));
        $add('rendimiento', $lazy ? 'ok' : 'warn', $lazy ? 'Carga diferida de imágenes' : 'Todas las imágenes cargan de entrada', $lazy ? '' : 'Las de más abajo podrían cargarse al hacer scroll.');
    }
    if (preg_match('/fonts\.googleapis|use\.typekit|fonts\.bunny/i', $html) && !$first("//link[" . sprintf($lower, '@rel') . "='preconnect']")) $add('rendimiento', 'warn', 'Fuentes externas sin preconexión', 'Preconectar con el servidor de fuentes hace que el texto aparezca antes.');
    $kb = (int) round(strlen($html) / 1024);
    $scripts = count($q('//script[@src]'));
    $bare = fn(string $d) => preg_replace('/^www\./', '', strtolower($d));
    $third = [];
    foreach ($q('//script[@src]|//link[@href]|//iframe[@src]|//img[@src]') as $el) {
        $src = $el->getAttribute('src') ?: $el->getAttribute('href');
        if (str_starts_with($src, '//')) $src = 'https:' . $src;
        $d = preg_match('#^https?://#i', $src) ? (string) parse_url($src, PHP_URL_HOST) : '';
        if ($d !== '' && $bare($d) !== $bare($host)) $third[strtolower($d)] = 1;
    }
    $thirdList = array_slice(array_keys($third), 0, 30);
    $add('rendimiento', $kb > 300 ? 'warn' : 'ok', "El HTML pesa $kb KB", $kb > 300 ? 'Conviene aligerarlo.' : 'Peso razonable.');
    $add('rendimiento', $scripts > 15 ? 'warn' : 'ok', "$scripts archivos de JavaScript", $scripts > 15 ? 'Muchos scripts hacen más lenta la carga.' : 'Cantidad razonable.');
    if ($thirdList) $add('rendimiento', count($thirdList) > 8 ? 'warn' : 'ok', count($thirdList) . ' servicios externos', implode(', ', array_slice($thirdList, 0, 6)) . (count($thirdList) > 6 ? '…' : ''));

    /* ---------- Conversión (contacto) ---------- */
    $ch = [];
    if (preg_match('/wa\.me|api\.whatsapp|whatsapp\.com\/send/i', $html)) $ch[] = 'WhatsApp';
    if ($q('//a[starts-with(@href,"tel:")]')) $ch[] = 'teléfono';
    if ($q('//a[starts-with(@href,"mailto:")]')) $ch[] = 'email';
    if ($q('//form')) $ch[] = 'formulario';
    $add('conversion', $ch ? 'ok' : 'bad', $ch ? 'Formas de contacto: ' . implode(', ', $ch) : 'Cuesta contactarte', $ch ? 'Tus visitantes tienen cómo escribirte.' : 'No encontramos WhatsApp, teléfono, email ni formulario.');
    if ($ch && !in_array('teléfono', $ch, true) && !in_array('WhatsApp', $ch, true)) $add('conversion', 'warn', 'Sin contacto inmediato', 'Un botón de WhatsApp o teléfono suele traer más consultas desde el celular.');

    return ['url' => $final, 'host' => $host, 'checks' => $checks, 'terceros' => $thirdList,
            'stats' => ['kb' => $kb, 'scripts' => $scripts, 'imagenes' => count($imgs), 'redirecciones' => count($r['chain']) - 1]];
}
