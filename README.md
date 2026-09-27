# msastrologia

Aplicacion web para recibir solicitudes de carta natal, calcularlas localmente y generar una interpretacion opcional con OpenAI o Google Gemini.

## Que incluye este MVP

- Formulario publico con fecha, hora, coordenadas y zona horaria IANA.
- Portada publica con la identidad de Miguel Salazar integrada desde la exportacion suministrada.
- Selector local pais → departamento/estado → municipio con coordenadas y zona horaria automaticas.
- Registro de telefono y medio de pago: Nequi, Daviplata o Llave, con confirmacion de pago desde el panel.
- Consulta privada del estado mediante codigo y contrasena del cliente; la contrasena se guarda solamente como hash.
- Entrega del resultado cuando coinciden pago confirmado e interpretacion lista.
- Enlace privado firmado con carta, lectura, descarga PDF y chat contextual con **Aurita**.
- Correo con PDF adjunto mediante SMTP y WhatsApp automatico mediante la Cloud API oficial, ambos opcionales.
- Alternativas manuales de correo y WhatsApp desde el panel cuando no hay credenciales externas.
- Interpretaciones con titulos, listas y negritas renderizadas, sin mostrar marcas `###` o `**`.
- Identidad visual de Miguel Salazar, logo suministrado y fondo animado de estrellas y constelaciones.
- Panel privado con usuario y contrasena.
- Estados de solicitud: pendiente, calculada, completada y cancelada.
- Calculo local de carta natal tropical con casas Placidus mediante Kerykeion 5.12.9.
- Rueda natal SVG y datos estructurados guardados en SQLite.
- Configuracion de OpenAI y Gemini desde el panel.
- Verificacion real de la clave y carga dinamica de los modelos habilitados para esa cuenta.
- Claves API cifradas en reposo con una clave derivada de `APP_SECRET`.
- Cambio de usuario y contrasena del administrador.
- Proteccion de sesion, cookies `HttpOnly`, `SameSite=Strict` y tokens CSRF.

## Costos y licencia

El servidor, SQLite, los calculos y la interfaz no requieren servicios de pago. Las APIs de OpenAI y Gemini son opcionales y sus costos dependen de la cuenta y del modelo elegido.

Kerykeion 5.12.9 se distribuye con licencia **AGPL-3.0**. Por eso msastrologia tambien se entrega bajo `AGPL-3.0-or-later`. Si se ofrece esta aplicacion por Internet, se debe facilitar a sus usuarios el codigo fuente correspondiente. Para mantener un producto cerrado o evitar esa obligacion hace falta una licencia comercial o usar el Astrologer API alojado, que ya no seria completamente gratuito.

## Inicio local

Requiere Python 3.11 o superior.

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -e ".[dev]"
Copy-Item .env.example .env
python -m uvicorn app.main:app --reload
```

Abre `http://127.0.0.1:8000`.

### Base mundial de ubicaciones

Antes del primer uso descarga e importa el catalogo gratuito GeoNames:

```powershell
python -m app.geo_import
```

El proceso descarga `cities500` (aproximadamente 13 MB comprimido) y crea `data/geonames.db` con paises, divisiones administrativas y unas 185.000 localidades. La aplicacion consulta esa base localmente, sin exponer datos de los usuarios ni consumir una API por cada seleccion. Si una localidad muy pequena no aparece, el formulario permite ingresar manualmente lugar, coordenadas y zona horaria.

En desarrollo, si todavia no existe `.env`, el usuario inicial es `admin` y la contrasena `cambiar-esta-clave`. Cambiala inmediatamente desde **Perfil**. En produccion la aplicacion no inicia con los secretos predeterminados.

## Configuracion

| Variable | Funcion |
| --- | --- |
| `APP_ENV` | `development` o `production` |
| `APP_SECRET` | Firma sesiones y deriva la llave de cifrado de credenciales |
| `ADMIN_USERNAME` | Usuario creado solamente cuando la base esta vacia |
| `ADMIN_PASSWORD` | Contrasena inicial, minimo 8 caracteres |
| `DATABASE_PATH` | Ruta del archivo SQLite |
| `GEODATA_PATH` | Ruta de la base SQLite local construida desde GeoNames |
| `PUBLIC_BASE_URL` | URL publica prevista para el despliegue |
| `RESULTS_DIR` | Carpeta privada donde se generan los PDF |
| `SMTP_HOST`, `SMTP_PORT` | Servidor y puerto del correo saliente |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | Credenciales SMTP |
| `SMTP_FROM_EMAIL` | Remitente del resultado |
| `SMTP_SECURITY` | `ssl`, `starttls` o `none` |
| `WHATSAPP_API_URL` | URL completa del endpoint oficial de mensajes de WhatsApp Cloud API |
| `WHATSAPP_ACCESS_TOKEN` | Token de acceso de Meta |
| `DEFAULT_COUNTRY_DIAL_CODE` | Prefijo usado para telefonos locales, `57` para Colombia |
| `ASTROLOGY_API_KEY` | Clave opcional para proteger el motor astrológico que consume la edición PHP |

Genera `APP_SECRET` con un gestor de secretos o con:

```powershell
python -c "import secrets; print(secrets.token_urlsafe(48))"
```

No cambies `APP_SECRET` despues de guardar claves de IA: se perderia la capacidad de descifrarlas y tendrias que ingresarlas de nuevo.

## Precision astrologica

La aplicacion usa Kerykeion 5.12.9 y Swiss Ephemeris, la misma base de calculo de la rama v5 de Astrologer-API. Para evitar dependencias externas y errores de geocodificacion, el formulario requiere coordenadas exactas y zona horaria IANA. La hora natal sigue siendo la principal fuente de incertidumbre: unos minutos pueden cambiar casas y, cerca de una cuspide, el Ascendente.

Configuracion actual:

- Zodiaco tropical.
- Casas Placidus.
- Posiciones geocentricas aparentes del motor.
- Calculo sin GeoNames ni RapidAPI.

## Modelos de IA

El administrador pega la clave, pulsa **Comprobar clave y cargar modelos**, elige uno de los modelos devueltos por el proveedor y guarda. El servidor vuelve a validar la seleccion antes de cifrar la clave. No se codifican nombres de modelos en el proyecto, de modo que la lista sigue lo que cada cuenta tenga habilitado.

Para Gemini se acepta la clave sola (`AIza...`) o una linea copiada del archivo de entorno (`GEMINI_API_KEY=AIza...` o `GOOGLE_API_KEY=AIza...`). La comprobacion consulta el endpoint de modelos de Google y solo ofrece modelos compatibles con `generateContent`.

Aurita utiliza el mismo proveedor y modelo guardado para la interpretacion. Cada conversacion se limita a la carta del cliente y solo esta disponible mediante el enlace privado cuando el pago figura como pagado. Cada pregunta consume la API de IA configurada.

## Entrega por correo y WhatsApp

Al confirmar el pago, la aplicacion entrega inmediatamente si la interpretacion ya existe. Si la carta aun no esta lista, la entrega se ejecuta al finalizar la interpretacion. El correo SMTP incluye el PDF adjunto. WhatsApp envia el enlace privado cuando `WHATSAPP_API_URL` y `WHATSAPP_ACCESS_TOKEN` estan configurados; de lo contrario, el administrador puede abrir el mensaje preparado desde la solicitud.

La automatizacion de WhatsApp depende de una cuenta oficial de Meta y de sus reglas, plantillas y tarifas vigentes. No se usan automatizaciones no oficiales ni control del navegador.

## Pruebas

```powershell
python -m pytest
```

## GitHub y despliegue sin VPS

El repositorio incluye dos flujos de GitHub Actions:

- **Pruebas** valida Python, PHP y JavaScript en cada cambio de `main`.
- **Desplegar en Hostinger** publica manualmente `shared-hosting/` mediante
  FTPS y conserva `config.php` y los datos existentes.

Para activar el despliegue crea un entorno llamado `produccion` y configura
estos secretos en GitHub: `HOSTINGER_FTP_HOST`, `HOSTINGER_FTP_USER`,
`HOSTINGER_FTP_PASSWORD` y `HOSTINGER_FTP_DIRECTORY`. La carpeta remota suele
ser `/public_html/`, pero debe confirmarse en hPanel.

## Siguiente etapa recomendada

Antes de operar con usuarios reales: configurar HTTPS, copias de seguridad cifradas, politica de privacidad y retencion/eliminacion de datos, correo transaccional, control de intentos de acceso y un servidor de produccion con proxy inverso.
