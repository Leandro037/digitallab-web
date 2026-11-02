<?php
/*
 * Digital Lab · panel · «Cambiar contraseña» (tarea 54). Mismos componentes y estilos que Leads (leads.css) más
 * contrasena.css. La página solo comprueba la sesión y entrega el token CSRF (R6); el cambio lo hace
 * contrasena-cambiar.php (POST JSON desde contrasena.js). Sin sesión → /login/. Sin configuración → 503 (R12).
 * Sin scripts, manejadores ni estilos en línea (CSP).
 */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

ps_cabeceras();
$c = ps_config();
$e = ps_estado($c, $_SERVER);
if (!$e['login']) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Contraseña · Panel · Digital Lab</title><p>El panel no está disponible en este servidor.</p></html>';
    exit;
}
$sesion = ps_sesion_actual($e, time());
if ($sesion === null) { header('Location: /login/', true, 303); exit; }
$csrf = htmlspecialchars((string) $sesion['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Contraseña · Panel · Digital Lab</title>
<meta name="theme-color" content="#03070a">
<meta name="color-scheme" content="dark">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preload" href="../fonts/space-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="leads.css?v=20261029c">
<link rel="stylesheet" href="contrasena.css?v=20261029b">
</head>
<body>
<header class="top">
  <a class="marca" href="/" aria-label="Digital Lab, inicio"><img src="/favicon.svg" alt="" width="34" height="34"><strong>DIGITAL LAB</strong><span>Panel</span></a>
  <nav class="nav" aria-label="Secciones del panel"><a href="/panel/">Estadísticas</a><a href="/panel/leads.php">Leads</a><a href="/panel/licencias.php">Licencias</a><a href="/panel/contrasena.php" aria-current="page">Contraseña</a></nav>
  <a class="salir" href="salir.php">Salir</a>
</header>

<main>
  <div class="titulo">
    <div><p>Acceso al panel</p><h1>Cambiar contraseña</h1></div>
  </div>

  <p class="aviso" id="hecho" role="status" tabindex="-1" hidden></p>
  <p class="aviso error" id="error" role="alert" hidden></p>

  <div class="clave-vista">
    <section class="card clave" aria-labelledby="tClave">
      <h2 id="tClave">Tus datos de acceso</h2>
      <form id="form" method="post" action="sin-js.php" novalidate>
        <input type="hidden" id="csrf" value="<?= $csrf ?>">

        <div class="campo">
          <label for="actual">Contraseña actual</label>
          <div class="pass">
            <input type="password" id="actual" autocomplete="current-password" maxlength="1024" spellcheck="false" autocapitalize="off" required aria-describedby="e-actual">
            <button class="ver" type="button" aria-controls="actual" aria-pressed="false" aria-label="Mostrar la contraseña actual">Mostrar</button>
          </div>
          <p class="err" id="e-actual" hidden></p>
        </div>

        <div class="campo">
          <label for="nueva">Contraseña nueva</label>
          <div class="pass">
            <input type="password" id="nueva" autocomplete="new-password" maxlength="1024" minlength="14" spellcheck="false" autocapitalize="off" required aria-describedby="a-nueva e-nueva">
            <button class="ver" type="button" aria-controls="nueva" aria-pressed="false" aria-label="Mostrar la contraseña nueva">Mostrar</button>
          </div>
          <p class="ayuda" id="a-nueva">14 caracteres o más. Única: no la uses en ningún otro sitio.</p>
          <p class="err" id="e-nueva" hidden></p>
        </div>

        <div class="campo">
          <label for="repite">Repite la contraseña nueva</label>
          <div class="pass">
            <input type="password" id="repite" autocomplete="new-password" maxlength="1024" spellcheck="false" autocapitalize="off" required aria-describedby="e-repite">
            <button class="ver" type="button" aria-controls="repite" aria-pressed="false" aria-label="Mostrar la repetición de la contraseña nueva">Mostrar</button>
          </div>
          <p class="err" id="e-repite" hidden></p>
        </div>

        <div class="botones">
          <button class="prim" type="submit" id="enviar">Cambiar contraseña</button>
          <a class="ghost" href="/panel/leads.php">Cancelar</a>
        </div>
      </form>
    </section>

    <aside class="card clave-info" aria-labelledby="tInfo">
      <h2 id="tInfo">Al cambiarla</h2>
      <ul>
        <li>Esta sesión sigue abierta y <b>se cierran todas las demás</b> (otros navegadores o dispositivos).</li>
        <li>Llega un aviso al correo del panel. Si no fuiste tú, ese correo explica cómo recuperarla.</li>
        <li>Si fallas la contraseña actual, cuenta como un intento de acceso: con 5 fallos se bloquea un tiempo.</li>
        <li>Si la olvidas, se pone una nueva desde tu PC con <code>scripts/hash-contrasena.py --subir</code>.</li>
      </ul>
    </aside>
  </div>
</main>

<footer class="pie"><p>Herramienta interna de un solo usuario · horas en UTC.</p></footer>
<script src="contrasena.js?v=20261029a"></script>
</body>
</html>
