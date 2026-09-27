# Desplegar msastrologia en Hostinger

Este paquete esta preparado para **Hostinger VPS con Docker**. No funciona con
solo copiar los archivos a `public_html`, porque la aplicacion usa Python,
FastAPI, SQLite y un proceso permanente de Uvicorn.

## 1. Preparar el VPS

1. Contrata o abre un VPS de Hostinger con la plantilla de Docker.
2. Sube `msastrologia-hostinger.zip` al VPS y descomprimelo en una carpeta
   propia, por ejemplo `/opt/msastrologia`.
3. Entra a esa carpeta por SSH.

## 2. Configurar secretos y dominio

Ejecuta:

```bash
cp .env.hostinger.example .env
nano .env
```

Cambia obligatoriamente:

- `APP_SECRET`: usa una cadena aleatoria larga.
- `ADMIN_PASSWORD`: establece una contrasena segura.
- `PUBLIC_BASE_URL`: coloca el dominio final con `https://`.
- Las variables SMTP y WhatsApp si deseas envios automaticos.

La clave de Gemini u OpenAI no se escribe en `.env`: se configura despues
desde el panel `/admin/ia` y se almacena cifrada en la base privada.

## 3. Iniciar la aplicacion

```bash
docker compose up -d --build
docker compose ps
```

La aplicacion queda disponible inicialmente en el puerto indicado por
`APP_PORT` (por defecto, `8000`). Configura en Hostinger el dominio, proxy
inverso y certificado SSL para dirigir el trafico HTTPS a ese puerto.

Tambien puedes crear el proyecto en **VPS > Docker Manager > Compose**. En ese
caso, conserva el archivo `.env` junto al proyecto y usa el
`docker-compose.yml` incluido.

## 4. Primer acceso

- Sitio publico: `https://tudominio.com/`
- Administracion: `https://tudominio.com/admin/login`
- Usuario inicial: el valor de `ADMIN_USERNAME`.
- Contrasena inicial: el valor de `ADMIN_PASSWORD`.

Despues del primer acceso, cambia la contrasena desde el perfil del
administrador.

## 5. Datos persistentes y copias de seguridad

No borres la carpeta `storage`. Alli quedan la base de solicitudes, los PDF y
las cartas generadas. Haz copias periodicas de:

```text
storage/db/
storage/pdf/
storage/generated/
```

La base `data/geonames.db` contiene solo el catalogo geografico gratuito y
puede restaurarse desde el ZIP.

## Comandos utiles

```bash
# Ver registros
docker compose logs -f --tail=200

# Reiniciar
docker compose restart

# Actualizar despues de reemplazar archivos
docker compose up -d --build

# Detener sin borrar datos
docker compose down
```
