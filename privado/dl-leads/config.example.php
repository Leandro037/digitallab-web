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
    // Se usa para no guardar la IP en claro (HMAC del límite por hora). Generarlo, por ejemplo, con:
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

    // Límites (docs/stack.md §1): envíos por hora y visitante, y solicitudes guardadas por día en total
    'limite_hora' => 5,
    'limite_dia' => 100,

    // Solo si el hosting pone un proxy o CDN delante y REMOTE_ADDR es la IP del proxy:
    // nombre de la variable del servidor con la IP real (p. ej. 'HTTP_CF_CONNECTING_IP'). Vacío = REMOTE_ADDR.
    'ip_cabecera' => '',
];
