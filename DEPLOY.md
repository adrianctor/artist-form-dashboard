# Deploy — Hostinger (archivos, sin VPS)

> **⚠️ Flujo nuevo (recomendado)**: deploy automático a `artist.sunsetgathering.com`
> vía GitHub Actions → ver [`docs/DEPLOY-GITHUB.md`](docs/DEPLOY-GITHUB.md).
> Este documento queda como referencia del **deploy manual legacy**
> (subdirectorio `/administracion/` + base compartida con WordPress).

Producción: `https://artist.sunsetgathering.com/administracion/` (WordPress en la raíz del mismo dominio, Laravel en subdirectorio). Misma base MySQL que WordPress → **todas las tablas Laravel llevan prefijo `dash_`** (ya configurado en `.env`).

## 1. Build una sola vez

```bash
npm run build                      # genera public/build/
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

En local esto se hace dentro de Docker:

```bash
docker compose exec -T vite npm run build
docker compose exec -T app composer install --no-dev --optimize-autoloader
docker compose exec -T app php artisan config:cache
```

## 2. Qué subir (File Manager o FTP)

Sube **todo el proyecto** a `public_html/administracion/` **excepto**:

- `.git/`, `node_modules/`, `docker/`, `docker-compose.yml`
- `tests/`, `odd/`, `reference/`, `wordpress/` (útiles solo en dev)
- `.env.example` (sube `.env` sí, ver paso 3)
- `storage/logs/*`, `storage/framework/cache/data/*`, `storage/framework/sessions/*` (sube la estructura de carpetas, vacías)

Permisos: `storage/` y `bootstrap/cache/` deben ser escribibles por el usuario del hosting (`chmod -R 775 storage bootstrap/cache` desde el File Manager o SSH si lo tienes).

## 3. `.env` de producción

Copia `.env.example` y ajusta:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://artist.sunsetgathering.com/administracion

# MISMA BD que WordPress (hPanel → Databases)
DB_CONNECTION=mysql
DB_HOST=localhost            # Hostinger: localhost
DB_PORT=3306
DB_DATABASE=<la_bd_de_wordpress>
DB_USERNAME=<usuario_bd_wordpress>
DB_PASSWORD=<password_bd_wordpress>
DB_PREFIX=dash_              # CRÍTICO: no chocar con wp_*

# Email (verificación de cuenta + recuperación de contraseña)
# Local: MAIL_MAILER=smtp + MAIL_HOST=mailpit (ya configurado, no tocar).
# Producción: SMTP de Hostinger — NO necesita VPS, ni cron, ni cola:
# Laravel envía estos correos de forma síncrona en el mismo request.
#
# Pasos en hPanel (una sola vez):
#   1. hPanel → Emails → Email Accounts → crear un buzón, p.ej.
#      no-reply@sunsetgathering.com (o la que prefieras de tu dominio).
#   2. Copiar las credenciales del buzón (NO la contraseña de hPanel).
#   3. Rellenar abajo.
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com   # Hostinger Email; si tu buzón es Titan → smtp.titan.email
MAIL_PORT=465                  # SSL. Si falla el handshake → 587 con MAIL_ENCRYPTION=tls
MAIL_USERNAME=no-reply@sunsetgathering.com   # el buzón completo, no el usuario de hPanel
MAIL_PASSWORD=                 # contraseña del buzón creado en el paso 1
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=no-reply@sunsetgathering.com
# Verificación de email: solo aplica a quienes se registran con contraseña
# (los login social y los usuarios creados por el admin entran ya verificados).
# APP_URL debe ser exacta para que el link de verificación apunte bien:
# APP_URL=https://artist.sunsetgathering.com/administracion

# Social login (opcionales — los botones aparecen solo si están seteados)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
APPLE_CLIENT_ID=
APPLE_CLIENT_SECRET=
```

## 4. Migraciones (una vez, y después en cada release)

Sin SSH en Hostinger: ejecuta las migraciones desde un script temporal o usa `php artisan migrate` si tienes terminal (Hostinger lo ofrece en planes Business+).

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndUsersSeeder --force   # crea admin@sunsetgathering.com
php artisan db:seed --class=DemoDataSeeder --force        # opcional: token + canciones demo
```

## 5. Music Release Form (interno — sin WordPress)

El form ya NO vive en WordPress/Elementor (spec S1): está dentro del
dashboard en `/release-form`, con el mismo flujo de 3 steps, el disclaimer
y las cards de ARTIST PROFILE STATUS.

- **No hace falta** token Elementor ni widget HTML en WordPress.
- El envío usa la sesión del dashboard (mismo pipeline de ownership por
  email de artista) y dispara el email del sistema a
  `info@sunsetgathering.com` (cc artistas) — reemplaza a EmailJS (spec S2).
- Requiere perfil de artista completo (gate S5) — primero My profile.
- El identificador interno se llama **Catalogo** (`SONG-…`); el form no lo
  muestra ni lo llena (spec S7, pendiente).

## 6. Releases siguientes (cambios en vivo)

```bash
# local
npm run build
# subir SOLO los archivos cambiados:
#   app/, resources/, routes/, public/build/, database/migrations/, config/, composer.json/lock si cambió
# si hay migraciones nuevas:
php artisan migrate --force
php artisan config:cache && php artisan route:cache
```

No hace falta tocar la BD de WordPress más allá de las migraciones `dash_*`.

## 7. Subdirectorio `/administracion/` — qué revisar

- `public/.htaccess` del proyecto debe respetar `RewriteBase /administracion/` si Apache lo pide (Laravel lo detecta por `APP_URL`; si los assets 404, agrega `RewriteBase /administracion/` al `.htaccess` de `public/`).
- En hPanel, si el document root apunta a `public_html`, el proyecto vive en `public_html/administracion/` con su `public/` **dentro**… opción limpia: subir el contenido de `public/` a `public_html/administracion/` y el resto del código a `public_html/administracion/app/` (fuera del web root) ajustando rutas en `index.php`. Si Hostinger te deja elegir entry point por subdominio, apunta al `public/` del proyecto.
- La ruta de producción del form (`/administracion/api/v1`) debe calzar con `APP_URL`.

## 8. Verificación post-deploy

- `https://artist.sunsetgathering.com/administracion/login` → 200
- Login con `admin@sunsetgathering.com` → entra al dashboard
- `https://artist.sunsetgathering.com/administracion/api/v1/songs/SONG-DEMO0001` con Bearer token → 200
- Login → menú **Music Release Form** → `/release-form` carga (si el perfil está incompleto, redirige a **My profile** primero)
- Enviar un release de prueba → aparece en **Songs** como **Catalogo · SONG-…** y llega el email a info@
