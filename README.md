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
```

No hace falta subir `README.md`, `.nojekyll`, `.gitignore` ni la carpeta `.git` (el servidor bloquea igualmente su acceso).

1. `.htaccess` es un archivo oculto: activa «mostrar archivos ocultos» en el administrador de archivos o en el cliente FTP para comprobar que se subió.
2. Cada vez que cambies `styles.css` o algo de `js/`, cambia el sufijo `?v=…` en `index.html` (y en las páginas legales, para `styles.css`) para que los visitantes reciban la versión nueva.
3. Si cambias el contenido, actualiza `<lastmod>` en `sitemap.xml`.
4. Tras subir, comprueba que `https://www.dl-dev.com` redirige a `https://dl-dev.com` y que la web carga sin errores en la consola del navegador.

## Auditoría web (`/auditoria/`)

El visitante pega una URL y el agente la revisa: nuestro servidor comprueba seguridad y SEO (`api/revisar.php`), el navegador mide la velocidad con Google PageSpeed Insights, y Claude redacta un resumen (`api/informe.php`). Si deja su email, recibe un informe detallado (`api/enviar.php`). Con `?demo` al final de la dirección se ve una simulación sin gastar nada. En GitHub Pages la página funciona siempre en ese modo, porque Pages no ejecuta PHP.

**Requisitos:** PHP 8.1 o superior con `curl`, `mbstring` y `dom` (en Hostinger vienen activadas), y la función `mail()`.

**Configuración** (una sola vez):

1. Copia `auditoria/api/config.example.php` como `dl-auditoria-config.php` en la carpeta que está **por encima** de `public_html`, y complétalo. Si no se puede, cópialo como `auditoria/api/config.php` (lo protege `.htaccess` y git lo ignora). **Nunca subas las claves a GitHub.**
2. `anthropic_api_key`: en console.anthropic.com → API Keys. Pon un límite de gasto mensual en la consola.
3. `google_psi_key`: en console.cloud.google.com, activa «PageSpeed Insights API», crea una clave y restríngela a sitios web `https://dl-dev.com/*` (el navegador la usa directamente, por eso debe estar restringida).
4. Crea en Hostinger el buzón `auditoria@dl-dev.com` (remitente de los informes) y pon en `email_avisos` dónde quieres recibir cada solicitud.
5. Cambia `sal` por un texto largo y aleatorio.

**Protecciones:** solo audita webs públicas (rechaza IP privadas, `localhost`, puertos que no sean 80/443 y redirecciones hacia la red interna); límites de 6 auditorías por hora y 3 emails por día por visitante, y 300 llamadas a la IA por día en total (ajustables en la configuración); campo trampa contra bots; solo acepta peticiones desde dl-dev.com. Los datos de cada auditoría se guardan en `auditoria/api/data/` (bloqueada desde internet) y se borran a las 24 h o al enviar el informe.

**Coste aproximado:** cada resumen es una llamada corta a Claude Sonnet 5.5 y cada informe por email, una algo más larga; con el límite diario por defecto el gasto máximo queda acotado. PageSpeed Insights es gratuito.

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
