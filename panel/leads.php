<?php
/*
 * Digital Lab · panel · sección «Leads» (tarea 50). Diseño aprobado: docs/diseno/panel-leads/ del proyecto leads.
 * La página no lleva datos: los pide leads.js a leads-api.php. Aquí solo se comprueba la sesión y se entrega
 * el token CSRF (R6) en el formulario del CSV. Sin sesión → /login/. Sin configuración → 503 (R12).
 */
declare(strict_types=1);
define('DL_PANEL', 1);
require __DIR__ . '/lib.php';

ps_cabeceras();
$c = ps_config();
$e = ps_estado($c, $_SERVER);
if (!$e['leads']) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Leads · Panel · Digital Lab</title><p>La sección Leads no está disponible en este servidor.</p></html>';
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
<title>Leads · Panel · Digital Lab</title>
<meta name="theme-color" content="#03070a">
<meta name="color-scheme" content="dark">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preload" href="../fonts/space-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="leads.css?v=20261029b">
</head>
<body>
<svg class="sprite" width="0" height="0" aria-hidden="true" focusable="false">
  <symbol id="i-bus" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></symbol>
  <symbol id="i-desc" viewBox="0 0 24 24"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></symbol>
  <symbol id="i-ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
  <symbol id="i-atras" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>
</svg>

<header class="top">
  <a class="marca" href="/" aria-label="Digital Lab, inicio"><img src="/favicon.svg" alt="" width="34" height="34"><strong>DIGITAL LAB</strong><span>Panel</span></a>
  <nav class="nav" aria-label="Secciones del panel"><a href="/panel/">Estadísticas</a><a href="/panel/leads.php" aria-current="page">Leads</a><a href="/panel/contrasena.php">Contraseña</a></nav>
  <a class="salir" href="salir.php">Salir</a>
</header>

<main>
  <div class="titulo">
    <div><p>Formulario de demo</p><h1>Leads</h1></div>
    <form class="csv" method="post" action="leads-csv.php" id="csvForm">
      <input type="hidden" name="csrf" id="csrf" value="<?= $csrf ?>">
      <button class="ghost" type="submit" id="csv" disabled><svg class="i" aria-hidden="true"><use href="#i-desc"/></svg>Descargar CSV</button>
    </form>
  </div>

  <p class="aviso"><b>Los leads se borran 30 días después de recibirlos.</b> Atiende cada uno antes de su fecha de caducidad.</p>
  <p class="aviso error" id="error" role="alert" hidden></p>

  <section class="kpis" aria-label="Resumen">
    <div class="kpi"><small>Leads recibidos</small><b id="kTotal">—</b><em id="kTotalSub">&nbsp;</em></div>
    <div class="kpi acento"><small>Nuevos</small><b id="kNuevos">—</b><em>sin atender</em></div>
    <div class="kpi"><small>Atendidos</small><b id="kAtendidos">—</b><em id="kAtendidosSub">&nbsp;</em></div>
    <div class="kpi"><small>Primera caducidad</small><b id="kCad">—</b><em id="kCadSub">&nbsp;</em></div>
  </section>

  <form class="filtros" id="filtros" role="search" aria-label="Filtrar leads" hidden>
    <label class="campo"><span>Buscar</span><span class="bus"><svg class="i" aria-hidden="true"><use href="#i-bus"/></svg><input type="search" name="q" id="q" placeholder="Nombre, empresa o correo…" autocomplete="off" spellcheck="false" maxlength="100"></span></label>
    <label class="campo"><span>Estado</span><select name="estado" id="fEstadoSel"><option value="">Todos</option><option value="nuevo">Nuevo</option><option value="atendido">Atendido</option></select></label>
    <label class="campo"><span>País</span><select name="pais" id="fPaisSel"><option value="">Todos</option></select></label>
    <label class="campo"><span>Origen</span><select name="origen" id="fOrigenSel"><option value="">Todos</option></select></label>
    <button class="ghost" type="button" id="limpiar" disabled>Limpiar filtros</button>
  </form>

  <p class="cuenta" id="cuenta" aria-live="polite" hidden></p>

  <div class="vista" id="vista" hidden>
    <section class="card lista" id="lista" aria-label="Lista de leads">
      <div class="cab" aria-hidden="true"><span>Fecha</span><span>Persona</span><span>País</span><span>Equipo</span><span>Claude Code</span><span>Origen</span><span>Estado</span></div>
      <ul class="filas" id="filas"></ul>
    </section>

    <section class="card vacio-c" id="sinRes" aria-label="Sin resultados" hidden>
      <div>
        <div class="num" aria-hidden="true">0</div>
        <h2>Ningún lead coincide</h2>
        <p>Prueba con otra búsqueda o quita los filtros para ver todos los leads.</p>
        <button class="ghost" type="button" id="limpiar2">Limpiar filtros</button>
      </div>
    </section>

    <aside class="card ficha" id="ficha" aria-label="Ficha del lead">
      <div class="ficha-cab">
        <a class="volver" href="#" id="volver"><svg class="i" aria-hidden="true"><use href="#i-atras"/></svg>Volver a la lista</a>
        <h2 id="fNombre" tabindex="-1"></h2>
        <p class="emp" id="fEmpresa"></p>
        <div class="meta"><span class="est nuevo" id="fEstado">Nuevo</span><span class="id" id="fId"></span><span class="id" id="fAtendido" hidden></span></div>
      </div>
      <div class="acciones">
        <button class="prim" type="button" id="fAccion"><svg class="i" aria-hidden="true"><use href="#i-ok"/></svg><span id="fAccionTxt">Marcar como atendido</span></button>
      </div>
      <div class="ficha-cuerpo">
        <div class="bloque">
          <h3>Datos</h3>
          <dl class="datos">
            <dt>Correo</dt><dd id="fEmail"></dd>
            <dt>País</dt><dd id="fPais"></dd>
            <dt>Tamaño de equipo</dt><dd id="fEq"></dd>
            <dt>¿Usa Claude Code?</dt><dd id="fCc"></dd>
            <dt>Botón de origen</dt><dd id="fCta"></dd>
          </dl>
        </div>
        <div class="bloque">
          <h3>Origen</h3>
          <dl class="datos">
            <dt>utm_source</dt><dd class="m" id="fUtm_source"></dd>
            <dt>utm_medium</dt><dd class="m" id="fUtm_medium"></dd>
            <dt>utm_campaign</dt><dd class="m" id="fUtm_campaign"></dd>
            <dt>utm_content</dt><dd class="m" id="fUtm_content"></dd>
          </dl>
          <p class="nada nota-utm" id="fUtmNota" hidden>Sin parámetros UTM: llegó de forma directa.</p>
        </div>
        <div class="bloque">
          <h3>Qué quiere resolver</h3>
          <p class="necesidad" id="fNecesidad"></p>
        </div>
        <div class="bloque">
          <h3>Consentimientos</h3>
          <ul class="cons">
            <li><span class="caja" id="fC1"><svg class="i" aria-hidden="true"><use href="#i-ok"/></svg></span><span>Atender y priorizar su solicitud<small id="fC1t"></small></span></li>
            <li><span class="caja" id="fC2"><svg class="i" aria-hidden="true"><use href="#i-ok"/></svg></span><span>Recibir novedades de DL Studio por correo<small id="fC2t"></small></span></li>
          </ul>
          <dl class="datos sep">
            <dt>Versión</dt><dd class="m" id="fVersion"></dd>
            <dt>Aceptado el</dt><dd class="m" id="fConsFecha"></dd>
          </dl>
        </div>
        <div class="bloque">
          <h3>Conservación</h3>
          <div class="cad">
            <dl class="datos m0">
              <dt>Recibido</dt><dd class="m" id="fRecibido"></dd>
              <dt>Caduca</dt><dd class="m" id="fCaduca"></dd>
            </dl>
            <div class="bar" role="img" id="fBar"><i id="fBarI"></i></div>
            <p><b id="fQuedan"></b> · recibido + 30 días</p>
          </div>
        </div>
      </div>
    </aside>
  </div>

  <section class="card vacio-c" id="vacio" aria-label="Sin leads" hidden>
    <div>
      <div class="num" aria-hidden="true">0</div>
      <h2>Aún no hay leads</h2>
      <p>Cuando alguien pida su demo en dl-dev.com/demo/ aparecerá aquí, con su necesidad y sus consentimientos.</p>
      <a class="ghost" href="/demo/">Ver el formulario</a>
    </div>
  </section>
</main>

<footer class="pie"><p>Herramienta interna de un solo usuario · horas en UTC.</p></footer>
<script src="leads.js?v=20261028b"></script>
</body>
</html>
