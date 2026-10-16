<?php
/*
 * Digital Lab · auditoría web, paso 1: revisión desde el servidor
 * Recibe {url}, descarga la web de forma segura y devuelve las comprobaciones
 * de seguridad, SEO técnico y funcionamiento, más un id para los pasos siguientes.
 */
declare(strict_types=1);
define('DL_AUDIT', 1);
require __DIR__ . '/lib.php';

try {
    $in = read_json_body();
    if (!empty($in['web'])) fail('error');                       // campo trampa relleno: robot
    rate_limit('revisar', (int) cfg('limite_hora', 6), 3600);
    cleanup();

    $url = normalize_url((string) ($in['url'] ?? ''));
    $res = audit_site($url);
    $id = new_id();
    store_put($id, ['creado' => time(), 'url' => $res['url'], 'host' => $res['host'], 'checks' => $res['checks'], 'datos' => $res['datos']]);
    json_out([
        'ok' => true,
        'id' => $id,
        'url' => $res['url'],
        'host' => $res['host'],
        'checks' => $res['checks'],
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
    if ($r['status'] >= 400) throw new AuditError('inaccesible', 422);

    /* ---------- Seguridad ---------- */
    $https = $p['scheme'] === 'https';
    $checks[] = $https
        ? check('seguridad', 'ok', 'La web usa HTTPS', 'La conexión con los visitantes va cifrada.')
        : check('seguridad', 'bad', 'La web no usa HTTPS', 'Los navegadores la marcan como «no segura» y Google la penaliza.');

    if ($https) {
        try {
            $plain = http_once('http://' . $host . '/', 20000);
            $loc = $plain['headers']['location'] ?? '';
            $checks[] = ($plain['status'] >= 300 && $plain['status'] < 400 && str_starts_with(strtolower($loc), 'https://'))
                ? check('seguridad', 'ok', 'Redirige siempre a HTTPS')
                : check('seguridad', 'warn', 'La versión sin HTTPS no redirige', 'Quien escriba la dirección sin «https» entra por una conexión sin cifrar.');
        } catch (AuditError $e) { /* el puerto 80 puede estar cerrado: no es un problema */ }

        if (!$r['tls_ok']) {
            $checks[] = check('seguridad', 'bad', 'Certificado de seguridad no válido', 'Los navegadores muestran un aviso de peligro al entrar.');
        } elseif ($r['cert_expira']) {
            $days = (int) floor(($r['cert_expira'] - time()) / 86400);
            $checks[] = $days > 21
                ? check('seguridad', 'ok', 'Certificado de seguridad válido', "Caduca dentro de $days días.")
                : check('seguridad', 'warn', 'El certificado caduca pronto', "Quedan $days días: revisa que se renueve solo.");
        }
    }

    $hsts = isset($h['strict-transport-security']);
    $csp = $h['content-security-policy'] ?? '';
    $checks[] = $hsts
        ? check('seguridad', 'ok', 'HSTS activo', 'El navegador usa siempre HTTPS con esta web.')
        : check('seguridad', $https ? 'bad' : 'warn', 'Falta HSTS', 'Obliga al navegador a usar siempre HTTPS y evita ataques en redes públicas.');
    $checks[] = $csp !== ''
        ? check('seguridad', 'ok', 'Tiene política de seguridad de contenido (CSP)')
        : check('seguridad', 'warn', 'Sin política de seguridad de contenido (CSP)', 'Ayuda a bloquear código inyectado por terceros.');
    $checks[] = (isset($h['x-frame-options']) || str_contains($csp, 'frame-ancestors'))
        ? check('seguridad', 'ok', 'Protegida contra el «clickjacking»')
        : check('seguridad', 'warn', 'Puede cargarse dentro de otras webs', 'Falta X-Frame-Options o frame-ancestors: facilita engaños de «clickjacking».');
    $checks[] = (strtolower($h['x-content-type-options'] ?? '') === 'nosniff')
        ? check('seguridad', 'ok', 'Cabecera X-Content-Type-Options correcta')
        : check('seguridad', 'warn', 'Falta X-Content-Type-Options', 'Evita que el navegador interprete archivos con el tipo equivocado.');
    $leak = trim(($h['server'] ?? '') . ' ' . ($h['x-powered-by'] ?? ''));
    if ($leak !== '' && preg_match('/\d+\.\d+/', $leak)) {
        $checks[] = check('seguridad', 'warn', 'El servidor muestra su versión', 'Revela «' . clean_text($leak, 60) . '», una pista útil para atacantes.');
    }

    /* ---------- SEO técnico (contenido de la página) ---------- */
    $html = $r['body'];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $first = fn(string $q) => ($n = $xp->query($q)) && $n->length ? $n->item(0) : null;
    $meta = function (string $name) use ($xp): string {
        $n = $xp->query("//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$name' or @property='$name']/@content");
        return $n && $n->length ? trim($n->item(0)->nodeValue) : '';
    };

    $title = clean_text($first('//title')?->textContent, 200);
    $tl = mb_strlen($title);
    $checks[] = $title === ''
        ? check('seo', 'bad', 'La página no tiene título', 'Es lo primero que muestra Google en los resultados.')
        : ($tl >= 10 && $tl <= 65
            ? check('seo', 'ok', 'Título de la página correcto', "«{$title}» ($tl caracteres).")
            : check('seo', 'warn', $tl < 10 ? 'Título demasiado corto' : 'Título demasiado largo', "«{$title}» ($tl caracteres; lo ideal es entre 30 y 60)."));

    $desc = clean_text($meta('description'), 300);
    $dl = mb_strlen($desc);
    $checks[] = $desc === ''
        ? check('seo', 'bad', 'Sin meta descripción', 'Google mostrará un fragmento elegido al azar en los resultados.')
        : ($dl >= 50 && $dl <= 170
            ? check('seo', 'ok', 'Meta descripción correcta', "$dl caracteres.")
            : check('seo', 'warn', $dl < 50 ? 'Meta descripción demasiado corta' : 'Meta descripción demasiado larga', "$dl caracteres; lo ideal es entre 120 y 160."));

    $h1s = $xp->query('//h1');
    $n1 = $h1s ? $h1s->length : 0;
    $h1text = $n1 ? clean_text($h1s->item(0)->textContent, 120) : '';
    $checks[] = $n1 === 1
        ? check('seo', 'ok', 'Un único encabezado principal (H1)', "«{$h1text}».")
        : ($n1 === 0
            ? check('seo', 'bad', 'Falta el encabezado principal (H1)', 'Le dice a Google de qué trata la página.')
            : check('seo', 'warn', "Hay $n1 encabezados H1", 'Lo recomendable es uno solo por página.'));

    $lang = clean_text($first('//html/@lang')?->nodeValue, 20);
    $checks[] = $lang !== ''
        ? check('seo', 'ok', 'Idioma declarado', "lang=\"$lang\".")
        : check('seo', 'warn', 'No declara el idioma', 'Falta el atributo lang: ayuda a buscadores y lectores de pantalla.');

    $checks[] = $meta('viewport') !== ''
        ? check('seo', 'ok', 'Adaptada a móviles (viewport)')
        : check('seo', 'bad', 'No está adaptada a móviles', 'Falta la etiqueta viewport; Google indexa primero la versión móvil.');

    $checks[] = $first("//link[translate(@rel,'CANONICAL','canonical')='canonical']/@href")
        ? check('seo', 'ok', 'Tiene dirección canónica')
        : check('seo', 'warn', 'Sin dirección canónica', 'Ayuda a evitar contenido duplicado en Google.');

    $checks[] = ($meta('og:title') !== '' && $meta('og:image') !== '')
        ? check('seo', 'ok', 'Vista previa para redes sociales (Open Graph)')
        : check('seo', 'warn', 'Sin vista previa para redes sociales', 'Al compartir el enlace en WhatsApp o LinkedIn no aparece una imagen ni un título cuidados.');

    $checks[] = $first("//script[@type='application/ld+json']")
        ? check('seo', 'ok', 'Tiene datos estructurados (schema.org)')
        : check('seo', 'warn', 'Sin datos estructurados', 'Ayudan a Google a entender la empresa y a mostrar resultados enriquecidos.');

    $imgs = $xp->query('//img');
    $noAlt = 0;
    foreach ($imgs ?: [] as $img) if (!$img->hasAttribute('alt')) $noAlt++;
    if ($imgs && $imgs->length) {
        $checks[] = $noAlt === 0
            ? check('seo', 'ok', 'Todas las imágenes tienen texto alternativo')
            : check('seo', 'warn', "$noAlt imágenes sin texto alternativo", 'Google no puede «leerlas» y las personas ciegas no saben qué muestran.');
    }

    if (preg_match('/noindex/i', $meta('robots') . ' ' . ($h['x-robots-tag'] ?? ''))) {
        $checks[] = check('seo', 'bad', 'La página pide no aparecer en Google', 'Tiene «noindex»: si es la web principal, no se mostrará en los resultados.');
    }

    // robots.txt y sitemap
    $sitemapUrl = '';
    try {
        $rb = http_once($root . '/robots.txt', 100000);
        if ($rb['status'] === 200 && trim($rb['body']) !== '' && !preg_match('/<html/i', $rb['body'])) {
            $checks[] = check('seo', 'ok', 'Tiene robots.txt');
            if (preg_match('/^\s*sitemap:\s*(\S+)/im', $rb['body'], $m)) $sitemapUrl = $m[1];
        } else {
            $checks[] = check('seo', 'warn', 'Sin robots.txt', 'Indica a los buscadores qué pueden rastrear y dónde está el sitemap.');
        }
    } catch (AuditError $e) { /* sin robots.txt accesible */ }
    $hasSitemap = $sitemapUrl !== '';
    if (!$hasSitemap) {
        try {
            $sm = http_once($root . '/sitemap.xml', 100000);
            $hasSitemap = $sm['status'] === 200 && (str_contains($sm['body'], '<urlset') || str_contains($sm['body'], '<sitemapindex'));
        } catch (AuditError $e) { /* sin sitemap accesible */ }
    }
    $checks[] = $hasSitemap
        ? check('seo', 'ok', 'Tiene sitemap')
        : check('seo', 'warn', 'Sin sitemap.xml', 'Facilita que Google encuentre todas las páginas.');

    /* ---------- Funcionamiento ---------- */
    $ttfb = $r['ttfb'];
    $t = number_format($ttfb, 2, ',', '');
    $checks[] = $ttfb < 0.6
        ? check('tecnico', 'ok', 'El servidor responde rápido', "$t s hasta el primer byte.")
        : check('tecnico', $ttfb < 1.5 ? 'warn' : 'bad', 'El servidor tarda en responder', "$t s hasta el primer byte; lo ideal es menos de 0,6 s.");

    $enc = strtolower($h['content-encoding'] ?? '');
    $checks[] = ($enc !== '' && $enc !== 'identity')
        ? check('tecnico', 'ok', 'Envía el contenido comprimido', strtoupper($enc) . '.')
        : check('tecnico', 'warn', 'No comprime el contenido', 'Activar GZIP o Brotli reduce el peso de la página.');

    if ($https && preg_match('/\s(?:src|href)=["\']http:\/\/(?!www\.w3\.org)/i', $html)) {
        $checks[] = check('tecnico', 'warn', 'Carga recursos sin HTTPS', 'Algunas imágenes o scripts se piden por http://; el navegador puede bloquearlos.');
    }

    $kb = (int) round(strlen($html) / 1024);
    if ($kb > 300) $checks[] = check('tecnico', 'warn', 'El HTML pesa mucho', "$kb KB; conviene aligerarlo.");

    $datos = [
        'titulo' => $title,
        'descripcion' => clean_text($desc, 200),
        'h1' => $h1text,
        'idioma' => $lang,
        'redirecciones' => count($r['chain']) - 1,
    ];
    return ['url' => $final, 'host' => $host, 'checks' => $checks, 'datos' => $datos];
}
