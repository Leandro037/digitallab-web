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

    // Configuración de los agentes: el archivo que genera «Exportar la configuración para la web» en el organigrama.
    // Guárdalo como «dl-auditoria-agentes.json» junto a este archivo de configuración (fuera de public_html)
    // o indica aquí su ruta. Cada agente usa su instrucción, su modelo y su esfuerzo.
    // 'agentes_archivo' => '/ruta/a/dl-auditoria-agentes.json',

    // Dominios desde los que se aceptan peticiones
    'origenes' => ['https://dl-dev.com', 'https://www.dl-dev.com'],

    // Texto aleatorio largo: se usa para no guardar las IP en claro (cámbialo)
    'sal' => 'cambia-esto-por-un-texto-largo-y-aleatorio',

    // Límites de uso: auditorías por visitante y hora, y llamadas a la IA por día en total
    // (cada auditoría hace entre 6 y 12 llamadas: una por agente, más las correcciones si el QA las pide)
    'limite_hora' => 6,
    'limite_ia_dia' => 400,

    // Acceso al panel de administración (login/ de la web). La contraseña se guarda como hash, nunca en claro:
    // php -r "echo password_hash('LA_CONTRASEÑA', PASSWORD_DEFAULT);"
    'admin_email' => '',
    'admin_password_hash' => '',
    'admin_url' => '',   // dirección del Organigrama de Digital Lab en claude.ai

    // Solo para pruebas (déjalos comentados en producción):
    // 'anthropic_url' => 'http://127.0.0.1:5393/v1/messages',   // simulador local de la IA
];
