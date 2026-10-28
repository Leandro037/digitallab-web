# Digital Lab · Landing

Landing de **Digital Lab**, laboratorio de inteligencia artificial para empresas.
Sitio estático (HTML, CSS y JavaScript sin dependencias ni paso de compilación).

## Estructura

```
index.html            Página completa
aviso-legal.html      Aviso legal (titular y condiciones de uso)
privacidad.html       Política de privacidad (Ley 25.326 y RGPD)
cookies.html          Política de cookies (no hay cookies; almacenamiento local del panel)
404.html              Página de error
styles.css            Estilos
fonts/                Tipografías alojadas en el dominio (licencia OFL en fonts/OFL.txt)
js/hero-animation.js  Animación del hero: matriz de datos, hilos de luz y nodos
js/logo-animations.js Ojo digital animado del logo («Hilo de luz»)
js/text-build.js      Títulos que se construyen con cuadraditos («lluvia de datos»)
js/landing.js         Hilos por toda la página, servicios, carrusel, panel de la visita, WhatsApp y formulario
img/                  Imágenes del carrusel (WebP)
auditoria/            Auditoría web gratuita con IA (página + servidor PHP en auditoria/api/)
studio/               Página de Digital Lab Studio: el CEO y su equipo de agentes animados (usa auditoria/agente.webp y js/hero-animation.js)
login/                Acceso al panel de administración (página + entrar.php)
contacto/enviar.php   Envía por correo los mensajes del formulario de contacto
```

## Verla en local

Abre `index.html` en el navegador, o sirve la carpeta para evitar restricciones del navegador:

```bash
python -m http.server 8000
```

y entra en http://localhost:8000.

## Para editar

- **WhatsApp:** número y mensaje inicial en `js/landing.js` (`WHATSAPP_NUMBER` y `WHATSAPP_MESSAGE`).
- **Imágenes del carrusel:** reemplaza los archivos de `img/` manteniendo los nombres; se recortan al centro para llenar el recuadro (16:9 en escritorio, 4:5 en móvil).
- **Formulario de contacto:** `contacto/enviar.php` valida los datos en el servidor, descarta los envíos con el campo trampa (`web`) relleno, limita a 5 mensajes por hora por visitante y 200 al día, y envía cada mensaje por SMTP al buzón de Digital Lab con «Responder a» el email de quien escribe. No guarda nada. Necesita en `dl-auditoria-config.php`: `smtp_usuario` (p. ej. `digital-lab@dl-dev.com`) y `smtp_password`; opcionales `smtp_host` (`smtp.hostinger.com`), `smtp_puerto` (465) y `contacto_para` (uno o varios buzones de destino, p. ej. `['digital-lab@dl-dev.com', 'otro@gmail.com']`; por defecto, `smtp_usuario`).
- **Textos legales:** completa los datos marcados como `[COMPLETAR]` en `aviso-legal.html` y `privacidad.html` (titular, CUIT, domicilio, email, jurisdicción, proveedor de correo).
- **Panel «Tu visita en datos»:** se calcula en el navegador del visitante y no envía datos a ningún sitio.
  La ubicación es aproximada (zona horaria e idioma); para ciudad exacta por IP hace falta un servicio externo.

Las animaciones respetan la opción «reducir movimiento» del sistema.

## Publicar en dl-dev.com (Hostinger)

Sube a `public_html` estos archivos y carpetas, respetando la estructura:

```
.htaccess            404.html          aviso-legal.html   cookies.html
index.html           privacidad.html   robots.txt         sitemap.xml
site.webmanifest     styles.css        favicon.ico        favicon.svg
apple-touch-icon.png icon-192.png      icon-512.png       og-image.jpg
fonts/   (2 .woff2 + OFL.txt)
img/     (6 .webp)
js/      (4 .js)
auditoria/  (completa, con sus 3 archivos .htaccess; ver «Auditoría web»)
studio/     (index.html, studio.css, studio.js, hilos.js y agentes/ con los 35 avatares del manual de estilo)
login/      (completa; ver «Acceso al panel»)
contacto/   (enviar.php; ver «Formulario de contacto»)
demo/       (la página y demo.php; ver «Formulario "Pide tu demo"». Nunca privado/ ni pruebas/)
```

No hace falta subir `README.md`, `.nojekyll`, `.gitignore` ni la carpeta `.git` (el servidor bloquea igualmente su acceso).

1. `.htaccess` es un archivo oculto: activa «mostrar archivos ocultos» en el administrador de archivos o en el cliente FTP para comprobar que se subió.
2. Cada vez que cambies `styles.css` o algo de `js/`, cambia el sufijo `?v=…` en `index.html` (y en las páginas legales, para `styles.css`) para que los visitantes reciban la versión nueva.
3. Si cambias el contenido, actualiza `<lastmod>` en `sitemap.xml`.
4. Tras subir, comprueba que `https://www.dl-dev.com` redirige a `https://dl-dev.com` y que la web carga sin errores en la consola del navegador.

## Auditoría web (`/auditoria/`)

El visitante pega una URL y un equipo de agentes la audita, con el mismo flujo «Auditoría web» que el organigrama de Digital Lab:

1. `api/revisar.php`: nuestro servidor visita la web y revisa seguridad, privacidad, SEO, accesibilidad, rendimiento y conversión.
2. El navegador mide la velocidad con Google PageSpeed Insights.
3. `api/agente.php`: trabajan los agentes, un paso por petición: 25 Cybersecurity y 29 Web Designer → 28 UI/UX Designer → 15 AI Auditor (diagnóstico y plan) → 08 Consultor → 16 QA. Si el 16 pide cambios, corrigen los agentes señalados y el 16 revisa otra vez.
4. Si el 16 aprueba, el visitante descarga el informe en PDF (`informe-pdf.js` con jsPDF en `vendor/` y las tipografías de `fuentes-pdf/`; se genera en su navegador). Si no lo aprueba ni tras la corrección, el informe queda retenido y se le ofrece pedirlo por WhatsApp.

Con `?demo` al final de la dirección se ve una simulación con la auditoría de ejemplo de `demo.json`, sin gastar nada. En GitHub Pages la página funciona siempre en ese modo, porque Pages no ejecuta PHP.

**Requisitos:** PHP 8.1 o superior con `curl`, `mbstring` y `dom` (en Hostinger vienen activadas).

**Configuración** (una sola vez):

1. Copia `auditoria/api/config.example.php` como `dl-auditoria-config.php` en la carpeta que está **por encima** de `public_html`, y complétalo. Si no se puede, cópialo como `auditoria/api/config.php` (lo protege `.htaccess` y git lo ignora). **Nunca subas las claves a GitHub.**
2. `anthropic_api_key`: en console.anthropic.com → API Keys. Pon un límite de gasto mensual en la consola.
3. `google_psi_key`: en console.cloud.google.com, activa «PageSpeed Insights API», crea una clave y restríngela a sitios web `https://dl-dev.com/*` (el navegador la usa directamente, por eso debe estar restringida).
4. **Agentes:** en el organigrama, «Exportar la configuración para la web» descarga un JSON con la instrucción, el modelo y el esfuerzo de cada agente. Súbelo como `dl-auditoria-agentes.json` junto a `dl-auditoria-config.php` (fuera de `public_html`). Contiene información interna: **nunca lo subas a GitHub**. Cada vez que cambies un agente en el organigrama, exporta y reemplaza el archivo.
5. Cambia `sal` por un texto largo y aleatorio.
6. `codigo_acceso`: el código que hay que escribir junto a la URL para lanzar una auditoría. Lo comprueba el servidor; si está vacío, no se audita nada.

**Protecciones:** solo audita webs públicas (rechaza IP privadas, `localhost`, puertos que no sean 80/443 y redirecciones hacia la red interna); 6 auditorías por hora por visitante, 20 llamadas a la IA por auditoría y 400 por día en total (ajustables en la configuración); el servidor decide qué datos recibe cada agente y en qué orden; campo trampa contra bots; solo acepta peticiones desde dl-dev.com. Los datos de cada auditoría se guardan en `auditoria/api/data/` (bloqueada desde internet) y se borran a las 24 h. Cada llamada a la IA deja su consumo de tokens en el registro de errores de PHP.

**Coste aproximado:** con todos los agentes en Claude Opus 5.5, unos US$0,56 por auditoría si el 16 la aprueba a la primera y unos US$1,13 si pide correcciones (medido el 19/10/2026); tarda de 2 a 5 minutos. PageSpeed Insights es gratuito.

## Formulario «Pide tu demo» (`/demo/demo.php`)

`demo/demo.php` recibe el formulario de `/demo/` (POST con JSON) y guarda **un archivo JSON por solicitud** en la cola privada `/dl-leads/` (carpeta hermana de `public_html`, permisos 0700; cada archivo 0600). No envía correos ni guarda la IP. La recoge el ordenador de Digital Lab (tarea 14: `pendientes.php` y `confirmar.php`).

**Configuración** (una sola vez): copia `privado/dl-leads/config.example.php` como `/dl-leads/config.php` en el servidor (0600) y pon una `sal` aleatoria de al menos 32 caracteres. Sin ella, o si la cola no existe o está dentro de `public_html`, responde 503 y no guarda nada. La carpeta `privado/` refleja lo que vive fuera de la web: nunca se sube a `public_html`.

**Protecciones:** solo POST `application/json` con `Origin` de dl-dev.com; 5 envíos por hora y visitante (en IPv6 cuenta el prefijo /64), 10 solicitudes guardadas por minuto y 100 al día en total; campo trampa `web` y tiempo `t` (ms desde que se abrió el formulario: < 3 s se descarta en silencio, > 2 h pide reintentar); `consent_respuesta` debe ser `true` y `consent_version` una de las publicadas en la configuración; listas cerradas y longitudes máximas; UTM de ≤ 60 caracteres `[A-Za-z0-9_-]` (si no, vacías); se quitan los caracteres invisibles (formato, uso privado, sin asignar, etiquetas Unicode U+E0000-E007F, selectores de variación, rellenos Hangul) y, en nombre y empresa, `<` y `>`; el correo no puede llevar comillas. Todo texto del lead sigue siendo un dato no confiable para quien lo lea (tareas 14, 15 y 17). Para el límite por hora se guarda un HMAC de la IP con una sal secreta **fija** a la que se le mezcla el día (UTC): la misma IP da otro código cada día, y el código se borra a la hora del último intento. Las solicitudes de más de 30 días se borran; la purga corre después del límite y como mucho una vez cada 10 minutos (sin tráfico no purga: el Cron llega en la tarea 14). Detrás de un CDN, ver `ip_cabecera` e `ip_proxies` en `privado/dl-leads/config.example.php`: la cabecera solo se cree si la petición llega desde las redes del CDN.

**Respuestas** (siempre JSON con `ok`): 200 éxito · 400 `campos` (con `campos: {nombre: falta|largo, …}`), `datos` o `tiempo` · 403 `origen` · 405 · 409 `version` · 413 · 415 · 429 `limite` (con `Retry-After`) · 500 `error` · 503 `sin_servicio`. Detalle en la cabecera de `demo/demo.php`.

**En el navegador** (`demo/demo.js`, tarea 6): valida cada campo con los textos de `docs/textos/demo.md`, exige la casilla 1 (`consent_respuesta`, sin marcar al cargar), copia las `utm_*` de la URL a los campos ocultos (solo `[A-Za-z0-9_-]`, hasta 60; sin cookies ni `localStorage`) y envía JSON a `demo.php` con las casillas como booleanos y `t` en milisegundos. Si se envía antes de 3 s (autorrelleno), espera a los 3 s para que `demo.php` no lo descarte como robot. Estados: enviando · éxito · error por campo (400 `campos`) · límite (429) · fallo (resto y sin red; sin «Reintentar» en 409 `version`). `?estado=…` fuerza un estado solo en `localhost`/`127.0.0.1`. En móvil y tablet `demo/hilos.js` dibuja los hilos sin pasar por detrás de ningún texto.

**Pruebas:** `php pruebas/demo/prueba-demo.php` y `php pruebas/demo/prueba-carga-e2e.php` (PHP 8.1+, sin dependencias; trabajan en una carpeta temporal con datos ficticios). En el navegador, con el Playwright de DL Studio: `node pruebas/demo/e2e-demo.mjs` (simula `demo.php`; guarda la carga real en `pruebas/demo/carga-e2e.json`) y `node pruebas/demo/hilos-movil.mjs` (0 cruces de hilos con texto de 360 a 1100 px). `pruebas/` no se sube a `public_html`.

## Acceso al panel (`/login/`)

El botón «Login» del menú lleva a `login/`. `login/entrar.php` comprueba el email y la contraseña en el servidor y, si son correctos, abre la sesión del panel y lleva a `/panel/leads.php`. Endurecido en la tarea 50 con los requisitos R1-R12 del 25 (`panel/seguridad.php`): sin segundo factor (riesgo aceptado por dirección).

- **Configuración** (`dl-auditoria-config.php`, fuera de `public_html`): `admin_email`, `admin_password_hash` (argon2id, o bcrypt coste ≥ 12, de una contraseña de 14+ caracteres) y `panel_sal` (32+ caracteres). Las dos últimas se generan **en local** con `python scripts/hash-contrasena.py` (pide la contraseña sin mostrarla; nunca la pegues en un chat). Opcionales: `panel_dir` (carpeta privada; por defecto `/dl-leads/panel`), `panel_aviso_para` (aviso de cada inicio de sesión; por defecto `digital-lab@dl-dev.com`, `''` = sin aviso) y `panel_aviso_de`. La IP real (`ip_cabecera`, `ip_proxies`) se lee de `/dl-leads/config.php`, la misma que usa `/demo` (`inc/ip.php`, compartido).
- **Si falta algo, se cierra**: sin correo o hash válido, o sin sal o carpeta privada válida, el login responde 503.
- **Bloqueo**: 5 fallos en 15 min bloquean al visitante 15 min (IPv6 por /64), duplicando hasta 24 h; 30 fallos en una hora de cualquiera bloquean el login 15 min y avisan por correo. Siempre la misma respuesta («credenciales»).
- **Sesión**: cookie `__Host-dlpanel` (Secure, HttpOnly, SameSite=Strict), 30 min de inactividad y 8 h como máximo, id nuevo al entrar; los archivos de sesión, el registro de accesos (90 días, sin datos personales) y los contadores viven en la carpeta privada, fuera de `public_html`.

En GitHub Pages el login no funciona (no hay PHP).

## Leads en el panel (`/panel/leads.php`, tarea 50)

Lista, búsqueda, filtros, ficha, contadores, «Marcar como atendido» / «Volver a nuevo» y «Descargar CSV» de las solicitudes de `/demo`. Lee `/dl-leads/lead-*.json` (nunca copia nada a `public_html`); el estado «atendido» va en `/dl-leads/atendidos.json` (0600) sin tocar el JSON del lead. Caducidad = recibido + 30 días (la purga la hace `demo.php`). Archivos: `panel/leads.php` (página), `leads.js`, `leads.css`, `leads-api.php`, `leads-csv.php`, `leads-lib.php`.

**Pruebas:** `php pruebas/panel/prueba-panel.php` (R1-R12 y lectura de leads, datos ficticios en carpeta temporal) y `node pruebas/panel/e2e-panel.mjs` (Playwright + `php -S` con una copia temporal de la web y configuración ficticia; `DL_CAPTURAS=<carpeta>` guarda capturas).

## SEO

- La dirección oficial es `https://dl-dev.com/` (etiqueta canónica). `.htaccess` redirige `www.dl-dev.com` e `index.html` a esa dirección.
- Etiquetas para compartir (Open Graph y X/Twitter) con `og-image.jpg` (1200×630).
- Datos estructurados (schema.org) de la empresa, el sitio y los seis servicios en el `<head>`.
- Iconos: `favicon.ico`, `favicon.svg`, `apple-touch-icon.png` e `icon-192/512.png` (manifiesto en `site.webmanifest`).
- Tras publicar: da de alta `dl-dev.com` en Google Search Console y envía `https://dl-dev.com/sitemap.xml`.

## Seguridad

- `.htaccess` añade cabeceras de seguridad: HSTS (siempre HTTPS), una política de contenido (CSP) que solo permite recursos del propio dominio, bloqueo de inserción en marcos de otras webs (`frame-ancestors 'none'`), `nosniff`, política de referente y permisos del navegador desactivados (cámara, micrófono, ubicación…).
- Bloquea el acceso a carpetas y archivos internos (`.git`, `.md`, copias de seguridad…) y desactiva el listado de carpetas.
- No se carga código ni recursos de terceros: si en el futuro se añade analítica, un chat o el envío del formulario a otro dominio, hay que ampliar la CSP (`script-src`, `connect-src`, `form-action`).

## Protección de datos

- Sin cookies, sin analítica y sin conexiones a terceros (tipografías propias), salvo en la auditoría web: Google PageSpeed (velocidad) y Anthropic (redacción con IA), documentados en `privacidad.html#auditoria`. La IP del visitante no se guarda: solo un resumen cifrado con sal durante 24 h para los límites de uso.
- El panel «Tu visita en datos» se calcula en el navegador; solo guarda el contador de visitas en el almacenamiento local (`dl-visitas`, `dl-sesion`), documentado en `cookies.html`.
- Textos legales para la Ley 25.326 (incluida la leyenda obligatoria de la AAIP) y el RGPD para clientes de la UE.

## Estadísticas y panel (`/panel/`)

Medición propia sin cookies: `js/analitica.js` envía a `panel/r.php`; los datos van a una base SQLite en `panel/datos/` (bloqueada por `.htaccess`, fuera de git). El país sale de la base DB-IP Lite (`panel/datos/geo4.bin` y `geo6.bin`, se regeneran cada mes con `node herramientas/geo-dbip.mjs` desde dl-agentes y se publican con `publicar-web.mjs`). El panel (`panel/index.html`) pide sesión: se abre al entrar en `/login/` con el email y la contraseña de `dl-auditoria-config.php` (ver «Acceso al panel»); el menú «Estadísticas / Leads» lleva a la sección Leads. Zona horaria y plazo de conservación: claves opcionales `panel_zona` y `panel_dias` de esa configuración.
