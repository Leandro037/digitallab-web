<?php
/*
 * Digital Lab · formulario de contacto
 * Recibe {nombre, email, empresa, servicio, mensaje, privacidad} y lo envía por correo
 * al buzón de Digital Lab por SMTP (con «Responder a» el email de quien escribe). No guarda nada.
 * Configuración (en dl-auditoria-config.php, fuera de public_html y de Git):
 *   'smtp_usuario'  => 'digital-lab@dl-dev.com',   // buzón de Hostinger que envía (y recibe, salvo 'contacto_para')
 *   'smtp_password' => '…',
 *   'smtp_host'     => 'smtp.hostinger.com',       // opcional, es el valor por defecto
 *   'smtp_puerto'   => 465,                        // opcional, SSL
 *   'contacto_para' => ['a@dl-dev.com', 'b@gmail.com'],   // opcional: uno o varios destinatarios
 */
declare(strict_types=1);
define('DL_AUDIT', 1);
require dirname(__DIR__) . '/auditoria/api/lib.php';

const CT_SERVICIOS = ['Consultoría en IA', 'Agentes IA', 'Automatizaciones', 'Desarrollo de software',
    'Visión por computadora', 'Desarrollo XR', 'Otro proyecto'];

try {
    $in = read_json_body();
    if (!empty($in['web'])) json_out(['ok' => true]);            // campo trampa relleno: robot (se descarta sin avisar)
    rate_limit('contacto', 5, 3600);                             // 5 mensajes por hora por visitante
    daily_cap('contacto', 200);

    $linea = fn(string $k) => trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($in[$k] ?? '')) ?? '');
    $nombre = $linea('nombre');
    $email = $linea('email');
    $empresa = $linea('empresa');
    $servicio = $linea('servicio');
    $mensaje = trim(str_replace("\r\n", "\n", (string) ($in['mensaje'] ?? '')));
    $mensaje = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $mensaje) ?? '';
    if ($nombre === '' || mb_strlen($nombre) > 100 || mb_strlen($empresa) > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
        || $mensaje === '' || mb_strlen($mensaje) > 3000 || ($in['privacidad'] ?? false) !== true) fail('datos');
    if (!in_array($servicio, CT_SERVICIOS, true)) $servicio = 'Otro proyecto';

    $usuario = (string) cfg('smtp_usuario', '');
    $pass = (string) cfg('smtp_password', '');
    if ($usuario === '' || $pass === '') fail('sin_servicio', 503);
    $para = array_values(array_filter((array) cfg('contacto_para', $usuario), fn($d) => filter_var($d, FILTER_VALIDATE_EMAIL)));
    if (!$para) fail('sin_servicio', 503);

    $cuerpo = "Nuevo mensaje desde el formulario de dl-dev.com\n\n"
        . "Nombre:   $nombre\n"
        . "Email:    $email\n"
        . 'Empresa:  ' . ($empresa !== '' ? $empresa : '—') . "\n"
        . "Interés:  $servicio\n"
        . 'Fecha:    ' . date('d/m/Y H:i') . " (hora de Argentina)\n\n"
        . "Mensaje:\n$mensaje\n\n"
        . "—\nResponde a este correo para contestarle directamente. Aceptó la política de privacidad.\n";

    smtp_send($usuario, $pass, $para, "Web: $nombre · $servicio", $cuerpo, $email, $nombre);
    json_out(['ok' => true]);
} catch (AuditError $e) {
    fail($e->codigo, $e->http);
} catch (Throwable $e) {
    error_log('Digital Lab contacto: ' . $e->getMessage());
    fail('error', 500);
}

/* Envío por SMTP con SSL y autenticación (sin librerías externas) */
function smtp_send(string $user, string $pass, array $to, string $subject, string $body, string $replyTo, string $replyName): void
{
    $host = (string) cfg('smtp_host', 'smtp.hostinger.com');
    $port = (int) cfg('smtp_puerto', 465);
    $ssl = cfg('smtp_ssl', true) !== false;
    $s = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15);
    if (!$s) throw new RuntimeException("SMTP: no conecta ($errstr)");
    stream_set_timeout($s, 20);

    $cmd = function (?string $line, int $expect) use ($s): void {
        if ($line !== null) fwrite($s, $line . "\r\n");
        $resp = '';
        while (($l = fgets($s, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;          // última línea de la respuesta
        }
        if ((int) substr($resp, 0, 3) !== $expect) {
            throw new RuntimeException('SMTP: ' . ($line !== null && str_starts_with($line, 'AUTH') ? 'AUTH' : strtok((string) $line, ' ')) . ' → ' . trim($resp));
        }
    };
    $enc = fn(string $t) => mb_encode_mimeheader($t, 'UTF-8', 'B', "\r\n");
    $dominio = substr(strrchr($user, '@') ?: '@dl-dev.com', 1);

    try {
        $cmd(null, 220);
        $cmd('EHLO ' . $dominio, 250);
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode($user), 334);
        $cmd(base64_encode($pass), 235);
        $cmd("MAIL FROM:<$user>", 250);
        foreach ($to as $d) $cmd("RCPT TO:<$d>", 250);
        $cmd('DATA', 354);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc('Web Digital Lab') . " <$user>",
            'To: ' . implode(', ', array_map(fn($d) => "<$d>", $to)),
            'Reply-To: ' . $enc($replyName) . " <$replyTo>",
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$dominio>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        // El cuerpo va en base64: ninguna línea empieza por «.», así que no hace falta escaparlas
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n.", 250);
        fwrite($s, "QUIT
");                                // el mensaje ya se aceptó: la respuesta a QUIT no importa
    } finally {
        fclose($s);
    }
}
