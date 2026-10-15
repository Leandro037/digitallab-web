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
img/                  Fotos del carrusel (generadas con IA, para reemplazar)
```

## Verla en local

Abre `index.html` en el navegador, o sirve la carpeta para evitar restricciones del navegador:

```bash
python -m http.server 8000
```

y entra en http://localhost:8000.

## Para editar

- **WhatsApp:** número y mensaje inicial en `js/landing.js` (`WHATSAPP_NUMBER` y `WHATSAPP_MESSAGE`).
- **Fotos del carrusel:** reemplaza los archivos de `img/` manteniendo los nombres (1600×900).
- **Formulario de contacto:** es una maqueta y todavía no envía mensajes; hay que conectarlo a un correo o CRM.
- **Panel «Tu visita en datos»:** se calcula en el navegador del visitante y no envía datos a ningún sitio.
  La ubicación es aproximada (zona horaria e idioma); para ciudad exacta por IP hace falta un servicio externo.

Las animaciones respetan la opción «reducir movimiento» del sistema.
