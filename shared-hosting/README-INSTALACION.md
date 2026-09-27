# MS Astrología para hosting compartido de Hostinger

Esta edición usa **PHP 8.1+ y MySQL/MariaDB**. Se instala en un plan Web o
Cloud de Hostinger sin VPS. El proyecto Python queda separado como la edición
completa y como motor gratuito para el futuro VPS.

## Qué funciona

- Página pública y formulario de cartas natales.
- Selector país, departamento y municipio con coordenadas y zona horaria.
- Acceso privado del cliente con código y contraseña.
- Panel de administrador, confirmación de pagos y estados.
- Cálculo de carta mediante un motor propio o Astrologer API, con SVG y datos
  técnicos guardados.
- Claves cifradas de OpenAI o Gemini y comprobación de modelos.
- Interpretación por IA en español a partir de la carta ya calculada.
- Horóscopos generados como borrador, revisados y publicados por el administrador.
- Resultado privado, Aurita, correo y enlace preparado para WhatsApp.
- Descarga del resultado mediante **Imprimir > Guardar como PDF**.

## Motor astrológico

PHP compartido no ejecuta Swiss Ephemeris. Para mantener la precisión, esta
edición **no pide a la IA que invente la carta**. En el menú **Motor
astrológico** puedes elegir:

- **Servidor propio**: conecta con la edición VPS de MS Astrología. Usa
  Kerykeion y conserva el cálculo gratuito; requiere que el VPS esté activo.
- **RapidAPI**: conecta con Astrologer API usando una clave propia. El proveedor
  puede aplicar límites o costos según el plan.

Ambas opciones solicitan la rueda con idioma `ES`. La IA solamente interpreta
los datos astronómicos ya calculados.

## Instalación en hPanel

1. En **Sitios web > Administrar > Bases de datos MySQL**, crea una base, un
   usuario y una contraseña. Guarda los cuatro datos.
2. Abre `public_html`, sube el ZIP y extráelo allí. `index.php`, `install.php`,
   `.htaccess` y las carpetas de la aplicación deben quedar directamente en
   `public_html`.
3. Visita `https://msastrologia.xyz/install.php`.
4. Escribe los datos de MySQL y define el usuario y la contraseña del administrador.
5. Pulsa **Instalar todo automáticamente** y espera. El instalador crea
   `config.php`, el secreto, las tablas y el catálogo GeoNames.
6. Abre el administrador. Una vez existe un administrador, el instalador queda
   bloqueado contra nuevas instalaciones.
7. Abre **Motor astrológico** y configura el servidor propio o RapidAPI.
8. Abre una carta natal y pulsa **Generar carta natal**.

## Actualizaciones

Antes de reemplazar archivos, descarga una copia de la base desde phpMyAdmin y
conserva siempre `config.php`. La migración automática agrega las columnas y
tablas nuevas sin borrar solicitudes, resultados o credenciales existentes.

## Requisitos

- PHP 8.1 o superior.
- PDO MySQL, cURL, OpenSSL, ZLib y mbstring.
- MySQL o MariaDB.
- HTTPS habilitado.

