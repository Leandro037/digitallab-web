<?php
/*
 * Digital Lab · IP real del visitante (compartido por demo/demo.php y el login del panel)
 *
 * Extraído tal cual de demo/demo.php (tarea 50, requisito del 25): mismo comportamiento y mismos
 * nombres, para que pruebas/demo/prueba-demo.php siga pasando sin cambios. Solo define funciones;
 * la carpeta inc/ está cerrada a la web con .htaccess.
 */
declare(strict_types=1);

// IP del visitante. Por defecto, REMOTE_ADDR. Si hay un CDN delante (config «ip_cabecera»), la cabecera
// solo se cree cuando la petición llega desde una red del CDN («ip_proxies»): si no, cualquiera podría
// inventarse la cabecera y saltarse el límite. Si la cabecera es una lista («a, b, c»), solo vale el
// ÚLTIMO valor, que es el que añadió el CDN; si no es una IP válida (con puerto, vacío, basura) se usa
// REMOTE_ADDR y nunca un valor anterior, porque esos los puede escribir el propio cliente.
function demo_ip(array $server, array $cfg): string
{
    $remota = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
    $cab = (string) ($cfg['ip_cabecera'] ?? '');
    if ($cab === '' || !isset($server[$cab]) || !is_string($server[$cab])) return $remota;
    $proxies = array_values(array_filter((array) ($cfg['ip_proxies'] ?? []), 'is_string'));
    $desdeCdn = false;
    foreach ($proxies as $red) {
        if (demo_ip_en_red($remota, $red)) { $desdeCdn = true; break; }
    }
    if (!$desdeCdn) return $remota;
    $valores = explode(',', $server[$cab]);
    $ultimo = trim((string) end($valores));
    return filter_var($ultimo, FILTER_VALIDATE_IP) ? $ultimo : $remota;
}

// ¿Está $ip dentro de $red («203.0.113.0/24», «2001:db8::/32» o una IP sola)?
function demo_ip_en_red(string $ip, string $red): bool
{
    [$base, $bits] = array_pad(explode('/', trim($red), 2), 2, null);
    $a = @inet_pton($ip);
    $b = @inet_pton((string) $base);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $total = strlen($a) * 8;
    $bits = $bits === null ? $total : (preg_match('/^\d{1,3}$/D', $bits) ? (int) $bits : -1);
    if ($bits < 0 || $bits > $total) return false;
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    $resto = $bits % 8;
    if ($resto === 0) return true;
    $mascara = (0xFF << (8 - $resto)) & 0xFF;
    return (ord($a[$bytes]) & $mascara) === (ord($b[$bytes]) & $mascara);
}

// Lo que cuenta como «un visitante» para el límite: la IPv4 completa; en IPv6, el prefijo /64
// (un proveedor suele dar a cada cliente un /64 entero, así que la dirección completa no frena nada).
// Una IPv4 escrita como IPv6 (::ffff:a.b.c.d) cuenta como la IPv4.
function demo_clave_ip(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) return $ip;
    if (strlen($bin) === 4) return (string) inet_ntop($bin);
    if (str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) return (string) inet_ntop(substr($bin, 12));
    return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}
