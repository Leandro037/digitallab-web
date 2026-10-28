<?php
/*
 * Digital Lab · configuración de la cola de /demo (EJEMPLO, sin secretos)
 *
 * Dónde va: en el servidor, como /dl-leads/config.php (carpeta hermana de public_html, permisos
 * 0700; este archivo con 0600). NUNCA dentro de public_html ni en Git.
 * La carpeta «privado/» del repositorio refleja lo que vive fuera de la web: no se sube a public_html.
 */
return [
    // Texto aleatorio de al menos 32 caracteres, distinto del de la auditoría. Sin él, /demo responde 503.
    // Se usa para no guardar la IP en claro (HMAC del límite por hora). Es FIJA: no hay que cambiarla
    // cada día (demo.php ya mezcla el día en el código). Generarla una vez, por ejemplo, con:
    //   python -c "import secrets; print(secrets.token_urlsafe(48))"
    'sal' => '',

    // Carpeta de la cola. Vacío = /dl-leads (junto a public_html). Si se cambia, debe existir,
    // tener permisos 0700 y estar fuera de public_html: si está dentro, /demo responde 503.
    'cola_dir' => '',

    // Orígenes desde los que se acepta el formulario
    'origenes' => ['https://dl-dev.com', 'https://www.dl-dev.com'],

    // Versiones del consentimiento publicadas (valor del campo oculto consent_version de demo/index.html).
    // Si cambia el texto de la casilla, se añade la versión nueva aquí ANTES de publicar la página.
    'consent_versiones' => ['34-2026-10-28'],

    // Límites (docs/stack.md §1): envíos por hora y visitante (en IPv6, por prefijo /64), solicitudes
    // guardadas por minuto en total (frena a quien reparte envíos entre muchas IP) y por día en total
    'limite_hora' => 5,
    'limite_minuto' => 10,
    'limite_dia' => 100,

    // IP del visitante detrás de un CDN (hallazgo H2 del 25, tarea 10). Vacío = REMOTE_ADDR, que es lo
    // correcto si el hosting ya entrega la IP real del visitante en REMOTE_ADDR.
    //
    // Cómo elegirla DESPUÉS de publicar (no antes: depende de lo que mida el servidor):
    //   1. Enviar el formulario desde dos redes distintas (p. ej. wifi y datos del móvil) y mirar en
    //      /dl-leads/limites cuántos archivos rl-* hay. Si hay 2, REMOTE_ADDR ya es la IP real: dejar
    //      'ip_cabecera' vacío y 'ip_proxies' vacío. No hace falta nada más.
    //   2. Si hay 1, REMOTE_ADDR es la del CDN (todos los visitantes cuentan como uno y el 6.º envío
    //      por hora de cualquiera recibe 429). Ver qué variables llegan con una prueba temporal fuera
    //      de la web pública (la hace dl-26 con aprobación) y anotar:
    //        - qué variable trae la IP real: la propia del CDN si existe (una sola IP, la escribe el CDN)
    //          o, si no, 'HTTP_X_FORWARDED_FOR' (lista «cliente, proxy…»: demo.php toma el ÚLTIMO
    //          valor válido, el que añadió el CDN; los primeros los puede inventar el cliente);
    //        - desde qué redes llega REMOTE_ADDR (rangos publicados por el CDN o los medidos).
    //   3. Poner esa variable en 'ip_cabecera' (nombre como en $_SERVER, con HTTP_ y en mayúsculas)
    //      y las redes del CDN en 'ip_proxies' (formato CIDR).
    //
    // Seguridad: la cabecera SOLO se usa si REMOTE_ADDR está en 'ip_proxies'. Con 'ip_proxies' vacío,
    // 'ip_cabecera' se ignora: una petición directa al servidor (sin pasar por el CDN) podría traer una
    // cabecera falsa con otra IP en cada envío y saltarse el límite por visitante.
    'ip_cabecera' => '',
    'ip_proxies' => [],                     // p. ej. ['203.0.113.0/24', '2001:db8::/32'] (de documentación)
];
