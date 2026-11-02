<?php
/*
 * Digital Lab · panel · sección «Licencias» (control de las instalaciones de DL Studio).
 * La página no lleva datos: los pide licencias.js a licencias-api.php. Aquí solo se comprueba la sesión y se entrega el token CSRF.
 * Sin sesión → /login/. Sin configuración → 503.
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
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Licencias · Panel · Digital Lab</title><p>La sección Licencias no está disponible en este servidor.</p></html>';
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
<title>Licencias · Panel · Digital Lab</title>
<meta name="theme-color" content="#03070a">
<meta name="color-scheme" content="dark">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preload" href="../fonts/space-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="leads.css?v=20261029c">
<link rel="stylesheet" href="licencias.css?v=20261102a">
</head>
<body>
<header class="top">
  <a class="marca" href="/" aria-label="Digital Lab, inicio"><img src="/favicon.svg" alt="" width="34" height="34"><strong>DIGITAL LAB</strong><span>Panel</span></a>
  <nav class="nav" aria-label="Secciones del panel"><a href="/panel/">Estadísticas</a><a href="/panel/leads.php">Leads</a><a href="/panel/licencias.php" aria-current="page">Licencias</a><a href="/panel/contrasena.php">Contraseña</a></nav>
  <a class="salir" href="salir.php">Salir</a>
</header>

<main>
  <div class="titulo">
    <div><p>DL Studio</p><h1>Licencias</h1></div>
    <span class="ult" id="ult" aria-live="polite"></span>
    <button class="ghost" type="button" id="actualizar">Actualizar</button>
  </div>
  <input type="hidden" id="csrf" value="<?= $csrf ?>">

  <p class="aviso error" id="error" role="alert" hidden></p>

  <section class="kpis kpis5" id="kpis" aria-label="Resumen">
    <div class="kpi"><small>Licencias</small><b id="kTotal">—</b><em>creadas</em></div>
    <div class="kpi"><small>Instaladas</small><b id="kInst">—</b><em>activadas en un PC</em></div>
    <div class="kpi acento"><small>Activas</small><b id="kAct">—</b><em>pueden usar Studio</em></div>
    <div class="kpi"><small>Cortadas</small><b id="kCor">—</b><em>bloqueadas</em></div>
    <div class="kpi"><small>Vistas en 7 días</small><b id="kVis">—</b><em>con contacto reciente</em></div>
  </section>

  <form class="card nueva" id="nueva" autocomplete="off" novalidate>
    <h2>Crear una clave</h2>
    <div class="nueva-campos">
      <label class="campo"><span>Correo del cliente</span><input type="email" id="nCorreo" maxlength="160" placeholder="correo@empresa.com" required></label>
      <label class="campo"><span>Empresa o persona</span><input type="text" id="nCliente" maxlength="120" placeholder="Opcional"></label>
      <label class="campo"><span>Notas</span><input type="text" id="nNotas" maxlength="300" placeholder="Opcional"></label>
      <label class="campo"><span>Validez</span><select id="nDias"><option value="0">Sin vencimiento</option><option value="30">Prueba de 30 días</option><option value="90">90 días</option><option value="365">1 año</option></select></label>
      <button class="prim" type="submit" id="nCrear">Crear clave</button>
    </div>
  </form>

  <section class="aviso clave-nueva" id="claveNueva" hidden aria-live="polite">
    <p><b>Clave creada para <span id="cnCorreo"></span></b></p>
    <div class="cn-fila"><code id="cnClave"></code>
      <button class="ghost" type="button" id="cnCopiarClave">Copiar clave</button>
      <button class="ghost" type="button" id="cnCopiarMsg">Copiar mensaje para el cliente</button>
      <button class="ghost" type="button" id="cnCerrar">Cerrar</button>
    </div>
  </section>

  <form class="filtros2" id="filtros" role="search" aria-label="Filtrar licencias">
    <label class="campo"><span>Buscar</span><input type="search" id="q" placeholder="Empresa, correo o clave…" autocomplete="off" spellcheck="false" maxlength="100"></label>
    <label class="campo"><span>Estado</span><select id="fEstado"><option value="">Todos</option><option value="activa">Activa</option><option value="sin">Sin instalar</option><option value="cortada">Cortada</option><option value="vencida">Vencida</option></select></label>
  </form>
  <p class="cuenta" id="cuenta" aria-live="polite"></p>

  <section class="card tabla-c" aria-label="Instalaciones">
    <div class="tabla-scroll">
      <table class="lic">
        <thead><tr><th>Empresa / cliente</th><th>Correo</th><th>Clave</th><th>Estado</th><th>Instalada</th><th>Último contacto</th><th>Uso</th><th>Versión · equipo</th><th><span class="sr">Acciones</span></th></tr></thead>
        <tbody id="filas"></tbody>
      </table>
    </div>
  </section>
</main>

<footer class="pie"><p>Herramienta interna de un solo usuario · cada clave vale para un solo PC · tiempo de uso aproximado.</p></footer>
<script src="licencias.js?v=20261102a"></script>
</body>
</html>
