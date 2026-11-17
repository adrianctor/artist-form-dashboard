# Deploy — Hostinger (archivos, sin VPS)

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

MAIL_MAILER=smtp             # el SMTP de Hostinger (hPanel → Emails)
MAIL_HOST=...
MAIL_PORT=...
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@sunsetgathering.com

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

**Token para el form de Elementor**: lo imprime `DemoDataSeeder` (`ELEMENTOR_FORM_TOKEN=...`) o genera uno con:

```bash
php artisan tinker
>>> App\Models\User::where('email','admin@sunsetgathering.com')->first()->createToken('elementor-form')->plainTextToken
```

Pégalo en `DASHBOARD_API_TOKEN` del form (paso 5).

## 5. Form de Elementor

1. Edita la página con el form en WordPress → widget **HTML**.
2. Reemplaza el contenido con `wordpress/music-release-form.html` de este repo.
3. Arriba del `<script>`, ajusta:

```js
var DASHBOARD_API_BASE = 'https://artist.sunsetgathering.com/administracion/api/v1';
var DASHBOARD_API_TOKEN = '1|...';   // token del paso 4
```

4. Guarda y prueba: el campo **Song Code** vacío = alta nueva (te devuelve un `SONG-…` en la pantalla de éxito); con el código = precarga y agrega una versión al historial.

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
- En el form de Elementor: enviar uno de prueba con Song Code vacío → aparece el código y aparece en el panel
