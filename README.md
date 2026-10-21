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
login/                Acceso al panel de administración (página + entrar.php)
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
- **Formulario de contacto:** es una maqueta y todavía no envía mensajes; hay que conectarlo a un correo o CRM. Ya incluye la casilla de consentimiento, la información básica de privacidad, límites de longitud y un campo trampa (`web`) contra el spam: el servicio que lo reciba debe validar los datos en el servidor y descartar los envíos con ese campo relleno.
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
login/      (completa; ver «Acceso al panel»)
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

## Acceso al panel (`/login/`)

El botón «Login» del menú lleva a `login/`. `login/entrar.php` comprueba el email y la contraseña en el servidor y, si son correctos, devuelve la dirección del panel (el organigrama de Digital Lab en claude.ai). Ni la contraseña ni esa dirección están en el código público. Se configuran en `dl-auditoria-config.php`:

- `admin_email`, `admin_password_hash` (la contraseña cifrada: `php -r "echo password_hash('LA_CONTRASEÑA', PASSWORD_DEFAULT);"`) y `admin_url`.

Límite de 8 intentos cada 15 minutos por visitante. Ojo: si el organigrama es público en claude.ai, quien tenga su dirección puede abrirlo sin pasar por aquí. En GitHub Pages el login no funciona (no hay PHP).

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
