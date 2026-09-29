# MS Astrología para hosting compartido de Hostinger

Esta edición usa **PHP 8.1+ y MySQL/MariaDB**. Se instala en un plan Web o
Cloud de Hostinger sin VPS. El proyecto Python queda separado como la edición
completa y como motor gratuito para el futuro VPS.

## Qué funciona

- Página pública y formulario de cartas natales y horóscopos personalizados.
- Selector país, departamento y municipio con coordenadas y zona horaria.
- Acceso privado del cliente con código y contraseña.
- Panel de administrador, confirmación de pagos y estados.
- Cálculo gratuito desde GitHub Pages con Swiss Ephemeris WebAssembly y
  archivos de efemérides oficiales, rueda SVG, casas Placidus, nodos, aspectos,
  tránsitos y datos técnicos guardados en Hostinger.
- Claves cifradas de OpenAI o Gemini y comprobación de modelos.
- Interpretación por IA en español a partir de la carta ya calculada.
- Horóscopos generados como borrador, revisados y publicados por el administrador.
- Resultado privado, Aurita, correo y enlace preparado para WhatsApp.
- Descarga del resultado mediante **Imprimir > Guardar como PDF**.

## Motor astrológico

La opción recomendada es **GitHub Pages + WebAssembly**. GitHub sirve los
archivos estáticos de Swiss Ephemeris y el navegador del administrador calcula
localmente las posiciones tropicales, las casas Placidus y los aspectos. El SVG
y los datos técnicos se validan y guardan después en MySQL. No requiere VPS,
RapidAPI ni una clave astrológica.

La versión 0.6.1 corrige la orientación tradicional: las casas avanzan en
sentido contrario a las manecillas del reloj desde el Ascendente, el MC queda
arriba y el IC abajo. También valida que cada Nodo Sur sea la oposición exacta
de 180 grados de su Nodo Norte, e incorpora nodos verdadero y medio, Lilith,
Quirón, partes arábigas, fase lunar, distribuciones y tránsitos personalizados.
Los archivos incluidos cubren de 1800 a 2399.

También se conservan dos alternativas: un servidor propio con Kerykeion para
VPS y Astrologer API mediante RapidAPI. La IA solamente interpreta datos
astronómicos ya calculados; nunca inventa la carta.

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
7. Publica `github-pages/` mediante el flujo **Publicar motor astrológico** del
   repositorio. En GitHub, configura Pages con origen **GitHub Actions**.
8. Abre **Motor astrológico**, elige **GitHub Pages + WebAssembly** y guarda
   `https://gasv1984-prog.github.io/ms-astrologia-motor1/motor`.
9. Abre una solicitud y pulsa **Generar carta natal** o **Generar carta y
   tránsitos**, según el servicio solicitado.

## Actualizaciones

Antes de reemplazar archivos, descarga una copia de la base desde phpMyAdmin y
conserva siempre `config.php`. La migración automática agrega las columnas y
tablas nuevas sin borrar solicitudes, resultados o credenciales existentes.

Desde la versión 0.5.1 la aplicación también unifica automáticamente la sesión
y las columnas de MySQL en `utf8mb4_unicode_ci`. Esto corrige el error 1267
`Illegal mix of collations` en instalaciones creadas previamente con la
collation predeterminada `utf8mb4_general_ci` de Hostinger.

## Requisitos

- PHP 8.1 o superior.
- PDO MySQL, cURL, OpenSSL, ZLib y mbstring.
- MySQL o MariaDB.
- HTTPS habilitado.

