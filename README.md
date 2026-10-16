# Digital Lab · Landing

Landing de **Digital Lab**, laboratorio de inteligencia artificial para empresas.
Sitio estático (HTML, CSS y JavaScript sin dependencias ni paso de compilación).

## Estructura

```
index.html            Página completa
styles.css            Estilos
js/hero-animation.js  Animación del hero: matriz de datos, hilos de luz y nodos
js/logo-animations.js Ojo digital animado del logo («Hilo de luz»)
js/text-build.js      Títulos que se construyen con cuadraditos («lluvia de datos»)
js/landing.js         Hilos por toda la página, servicios, carrusel, panel de la visita, WhatsApp y formulario
img/                  Imágenes del carrusel (WebP)
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
- **Formulario de contacto:** es una maqueta y todavía no envía mensajes; hay que conectarlo a un correo o CRM.
- **Panel «Tu visita en datos»:** se calcula en el navegador del visitante y no envía datos a ningún sitio.
  La ubicación es aproximada (zona horaria e idioma); para ciudad exacta por IP hace falta un servicio externo.

Las animaciones respetan la opción «reducir movimiento» del sistema.

## Publicar en dl-dev.com (Hostinger)

1. Sube **todo** el contenido de esta carpeta a `public_html`, incluidos `.htaccess`, `robots.txt`, `sitemap.xml`, `404.html`, los iconos y `og-image.jpg`.
2. Cada vez que cambies `styles.css` o algo de `js/`, cambia el número `?v=20261016` en `index.html` (por ejemplo, a la fecha del día) para que los visitantes reciban la versión nueva.
3. Si cambias el contenido, actualiza `<lastmod>` en `sitemap.xml`.

## SEO

- La dirección oficial es `https://dl-dev.com/` (etiqueta canónica). `.htaccess` redirige `www.dl-dev.com` e `index.html` a esa dirección.
- Etiquetas para compartir (Open Graph y X/Twitter) con `og-image.jpg` (1200×630).
- Datos estructurados (schema.org) de la empresa, el sitio y los seis servicios en el `<head>`.
- Iconos: `favicon.ico`, `favicon.svg`, `apple-touch-icon.png` e `icon-192/512.png` (manifiesto en `site.webmanifest`).
- Tras publicar: da de alta `dl-dev.com` en Google Search Console y envía `https://dl-dev.com/sitemap.xml`.
