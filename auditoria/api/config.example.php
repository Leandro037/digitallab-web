<?php
/*
 * Digital Lab · configuración de la auditoría web (EJEMPLO)
 *
 * Copia este archivo con el nombre «dl-auditoria-config.php» en la carpeta que está
 * POR ENCIMA de public_html (así nunca es accesible desde internet) y completa los valores.
 * Si el hosting no lo permite, cópialo como «config.php» en esta misma carpeta api/.
 * Nunca subas el archivo con las claves reales a GitHub.
 */
return [
    // Clave de la API de Anthropic (console.anthropic.com → API Keys). Pon un límite de gasto mensual en la consola.
    'anthropic_api_key' => '',
    'modelo_ia' => 'claude-sonnet-5-5',

    // Clave de Google PageSpeed Insights (console.cloud.google.com → APIs → PageSpeed Insights API).
    // Restríngela a «Sitios web» con https://dl-dev.com/* : el navegador la usa directamente.
    'google_psi_key' => '',

    // Buzón de Hostinger que envía los informes (debe existir) y dónde avisar de cada solicitud
    'email_remitente' => 'auditoria@dl-dev.com',
    'email_avisos' => '',

    // Dominios desde los que se aceptan peticiones
    'origenes' => ['https://dl-dev.com', 'https://www.dl-dev.com'],

    // Texto aleatorio largo: se usa para no guardar las IP en claro (cámbialo)
    'sal' => 'cambia-esto-por-un-texto-largo-y-aleatorio',

    // Límites de uso: auditorías por visitante y hora, emails por visitante y día, y llamadas a la IA por día en total
    'limite_hora' => 6,
    'limite_emails_dia' => 3,
    'limite_ia_dia' => 300,

    // Solo para pruebas (déjalos comentados en producción):
    // 'anthropic_url' => 'http://127.0.0.1:5393/v1/messages',   // simulador local de la IA
    // 'email_a_archivo' => '/ruta/a/una/carpeta',                // guarda los emails en archivos
];
