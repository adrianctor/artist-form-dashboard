# Deploy a producción — GitHub Actions → Hostinger

Flujo: **`git push origin develop`** → GitHub Actions (tests + build + rsync + migrate) → **https://artist.sunsetgathering.com**

- Repo (público): `https://github.com/adrianctor/artist-form-dashboard`
- Rama de deploy: **`develop`** · Rama de CI: `main`
- Servidor: hosting Hostinger (hPanel), sitio `sunsetgathering.com` con WordPress en la raíz; el dashboard vive en el subdominio `artist`.
- El `.env` de producción **nunca** vive en GitHub: se crea una sola vez en el servidor y rsync lo excluye.

---

## 0. Antes de todo — seguridad del repo público

El repo es público: **no puede contener contraseñas reales.**

- `RolesAndUsersSeeder` ya no hardcodea la contraseña: usa `ADMIN_PASSWORD` de `.env`; si no está seteada en producción, genera una aleatoria y la escribe una sola vez en `storage/logs/laravel.log`.
- **Si el admin de producción quedó con una contraseña antigua conocida**, rotala después del primer deploy: seteá `ADMIN_PASSWORD` en el `.env` del servidor y corré `php artisan db:seed --class=RolesAndUsersSeeder --force`.
- Credenciales de BD, SMTP y SSH **solo** viven en: `.env` del servidor + secrets de GitHub. Nunca en commits.

---

## 1. Subir el código a GitHub

Los branches `develop` y `main` ya existen localmente en tu clone. Solo falta autenticarte y pushear:

```bash
cd ~/artist-form-dashboard        # donde tengas el clone
gh auth login                     # GitHub.com → HTTPS → Login with a web browser

git remote add origin https://github.com/adrianctor/artist-form-dashboard.git

git push -u origin feat/dashboard-mvp   # respaldo de todo el trabajo
git push -u origin develop              # ← rama que dispara el deploy
git push -u origin main                 # ← rama que corre el CI (tests)
```

> Si `gh` no está instalado o preferís otra cosa: creá un Personal Access Token (repo) en GitHub y usá `git remote add origin https://TU_USUARIO:TOKEN@github.com/adrianctor/artist-form-dashboard.git`.

---

## 2. Hostinger — subdominio `artist.sunsetgathering.com`

1. hPanel → **Websites** → dashboard del sitio de `sunsetgathering.com`.
2. Sidebar → **Domains → Subdomains**.
3. Subdomain: `artist` · dominio: `sunsetgathering.com`.
4. Marcá **Custom folder for subdomain** y poné la ruta (relativa al home del hosting):

   ```
   domains/sunsetgathering.com/artist/public
   ```

   Esa carpeta será el **document root** (ahí vive el `public/` de Laravel — es lo que Apache sirve).
5. **Create**. Si el form no acepta la ruta anedada, creala como `domains/sunsetgathering.com/artist` y avisame: se resuelve con 3 symlinks (`index.php`, `.htaccess`, `build`) — el workflow ya trae los `--exclude` necesarios para eso.
6. SSL: hPanel → Domains → `artist.sunsetgathering.com` → **SSL** → instalar Let's Encrypt (gratis).
7. **DNS**: si los nameservers de `sunsetgathering.com` están en Hostinger, el subdominio resuelve solo. Si el DNS vive en otro lado, creá un registro **A** → `artist` → IP del hosting (hPanel → Websites → detalle del plan muestra la IP).

La app se va a desplegar en `domains/sunsetgathering.com/artist` (el project root), y el docroot apunta a su `public/`.

---

## 3. Hostinger — base de datos

hPanel → **Databases → MySQL** → **Create New Database**:

| Campo | Valor |
|---|---|
| Database name | `artist_dash` (hPanel la muestra como `uXXXXXXX_artist_dash`) |
| User name | `artist_dash` |
| Password | `<TU_CONTRASENA_BD>` |
| Host | `localhost` |

Después: **Add user to database** → marcá **ALL PRIVILEGES** → guardar.

- El código usa `DB_PREFIX=dash_` (prefijo propio, no toca las tablas `wp_*` de WordPress aunque compartan servidor).
- **¿Conservar datos del deploy anterior?** Si en la BD de WordPress existían tablas `dash_*` del deploy viejo en `/administracion/`, exportalas e importalas a la nueva:

  ```bash
  mysqldump -u WP_USER -p WP_BASE_DE_WORDPRESS 'dash_*' > dash_old.sql
  mysql -u artist_dash -p'<TU_CONTRASENA_BD>' uXXXXXXX_artist_dash < dash_old.sql
  ```

---

## 4. Hostinger — SSH (requiere plan Premium o superior)

1. hPanel → **Websites** → dashboard → sidebar **SSH Access** → **Enable**.
2. Copiá de esa pantalla: el **comando SSH** (trae host, puerto — p. ej. `65002` — y usuario `uXXXXXXX`).
3. Generá un par de claves en tu terminal (WSL):

   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/hostinger_deploy -N "" -C "github-actions-deploy"
   cat ~/.ssh/hostinger_deploy.pub
   ```

4. Pegá la **clave pública** (la línea que empieza con `ssh-ed25519`) en hPanel → SSH Access → **Add SSH key**. (La privada NO se comparte nunca.)
5. Probá la conexión:

   ```bash
   ssh -p 65002 -i ~/.ssh/hostinger_deploy uXXXXXXX@TU_HOST
   ```

---

## 5. `.env` de producción (una vez, en el servidor)

Entrá por SSH y creá el archivo **antes** del primer push (el deploy lo respeta y nunca lo toca):

```bash
ssh -p 65002 -i ~/.ssh/hostinger_deploy uXXXXXXX@TU_HOST
mkdir -p domains/sunsetgathering.com/artist
nano domains/sunsetgathering.com/artist/.env
```

Pegá y completá:

```dotenv
APP_NAME="Sunset Gathering"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://artist.sunsetgathering.com
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=uXXXXXXX_artist_dash
DB_USERNAME=artist_dash
DB_PASSWORD=<TU_CONTRASENA_BD>
DB_PREFIX=dash_

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_PATH=/
SESSION_DOMAIN=null
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=info@sunsetgathering.com
MAIL_PASSWORD=CONTRASEÑA_DEL_BUZÓN
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=info@sunsetgathering.com
MAIL_FROM_NAME="Sunset Gathering"

# Contraseña del admin (ver paso 0) — la usás para loguearte en producción
ADMIN_PASSWORD=ELEGÍ_UNA_CONTRASEÑA_LARGA_ACA

# Social login (opcionales; los botones solo aparecen si están seteados)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
APPLE_CLIENT_ID=
APPLE_CLIENT_SECRET=
```

`APP_KEY` puede quedar vacía: el workflow la genera en el primer deploy (`key:generate`) si no existe.

---

## 6. Secrets en GitHub

Con `gh` autenticado (o desde la UI: repo → Settings → Secrets and variables → Actions → New repository secret):

```bash
REPO=adrianctor/artist-form-dashboard

gh secret set DEPLOY_HOST   -b "TU_HOST"              -R $REPO   # host del comando SSH
gh secret set DEPLOY_PORT   -b "65002"                -R $REPO   # puerto del comando SSH
gh secret set DEPLOY_USER   -b "uXXXXXXX"             -R $REPO   # usuario SSH
gh secret set DEPLOY_PATH   -b "domains/sunsetgathering.com/artist" -R $REPO
gh secret set DEPLOY_SSH_KEY < ~/.ssh/hostinger_deploy -R $REPO   # clave PRIVADA
```

---

## 7. Primer deploy

```bash
git push origin develop
```

Mirá la corrida en **Actions → deploy-develop**: checkout → composer (sin dev) → `npm ci` → tests (sqlite) → `npm run build` → rsync → en el servidor: `key:generate` (si faltaba) → `migrate --force` → `config:cache` → `view:cache`.

Cuando pase verde, entrá por SSH una última vez para el arranque:

```bash
cd domains/sunsetgathering.com/artist
php artisan db:seed --class=RolesAndUsersSeeder --force   # crea label@admin.com con ADMIN_PASSWORD
[ -e public/storage ] || php artisan storage:link
chmod -R 775 storage bootstrap/cache
```

Login: `https://artist.sunsetgathering.com/login` → `label@admin.com` + tu `ADMIN_PASSWORD`.

---

## 8. Correo en producción

1. hPanel → **Emails → Email Accounts** (en `sunsetgathering.com`) → crear el buzón **`info@sunsetgathering.com`** con su contraseña (es el destinatario de todos los submits del form y a la vez el remitente). La contraseña del buzón **no** es la de hPanel.
2. Completá en el `.env` del servidor `MAIL_USERNAME` / `MAIL_PASSWORD` con ese buzón (ya está en la plantilla del paso 5).
3. SMTP de Hostinger: `smtp.hostinger.com`, puerto **465 SSL** (si el handshake falla: **587** + `MAIL_ENCRYPTION=tls`).
4. Después de cambiar `.env`:

   ```bash
   php artisan config:clear && php artisan config:cache
   ```

   (o simplemente hacé el siguiente push: el deploy recachea solo).
5. **Verificación**: usá "Forgot password?" con cualquier usuario → el correo tiene que llegar (revisá Spam). Si cae en spam, revisá SPF/DKIM: hPanel → Emails → dominio → los registros; con DNS en Hostinger se autoconfiguran.
6. No hay queue ni cron: los correos de Laravel salen **síncronos** en el mismo request.

### Remitente vs. destino (independientes y parametrizables)

Son dos configuraciones distintas; una puede ser de un dominio y la otra de otro:

- **Remitente (From)**: `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` + las credenciales SMTP (`MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`).
- **Destino de los submits**: `MAIL_SUBMIT_TO` (default `info@sunsetgathering.com`). Es lo único que hay que cambiar si montás el mismo sistema en otro proyecto.
- Los mails de reseteo/contraseña van al correo del usuario que los pide; solo los submits del form usan `MAIL_SUBMIT_TO`. El cc a los artistas y el reply-to al primer artista salen del mailable (dinámicos).

**Ejemplo: enviar desde `dashboard@djcare.world` y que llegue siempre a `info@sunsetgathering.com`:**

```env
MAIL_HOST=<SMTP del proveedor que tenga el buzón dashboard@djcare.world>
MAIL_PORT=465
MAIL_USERNAME="dashboard@djcare.world"
MAIL_PASSWORD=<contraseña de ese buzón>
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="dashboard@djcare.world"
MAIL_FROM_NAME="Sunset Gathering"
MAIL_SUBMIT_TO="info@sunsetgathering.com"
```

Dos restricciones:

1. **El From debe ser un buzón (o alias) del proveedor SMTP que use `MAIL_USERNAME`**: Google Workspace solo envía como direcciones de su dominio (o alias verificados); Hostinger solo autentica sus buzones. O sea: si `dashboard@djcare.world` vive en Google → SMTP de Google; si vive en Hostinger → SMTP de Hostinger.
2. **SPF/DKIM del dominio From** (`djcare.world`) deben autorizar a ese proveedor; con DNS en el mismo proveedor se autoconfiguran, con DNS en otro hay que agregar los registros a mano. Sin eso, los correos caen en spam.

**Hostinger y GSuite con el mismo buzón (`info@sunsetgathering.com`)**: no se pisan y no hay doble entrega.

- **Envío**: la app usa un solo SMTP (el del `.env`) — solo uno envía.
- **Recepción**: la decide el **MX del DNS de `sunsetgathering.com`**. Si los MX siguen en Hostinger, el buzón de Google no recibe nada aunque exista en el panel; si los MX apuntan a Google, recibe Google. Elegí dónde querés que llegue y dejá los MX ahí.

El envío (dominio From) y la recepción (dominio destino) son dominios distintos, así que usar SMTP de un proveedor no afecta dónde se recibe el correo.

Si usás **Google login**, agregá en Google Console el redirect URI:
`https://artist.sunsetgathering.com/auth/google/callback` (y `GOOGLE_CLIENT_ID/SECRET` en el `.env`).

---

## 9. Verificación post-deploy

- `https://artist.sunsetgathering.com/login` → 200, login con `label@admin.com`
- Dashboard carga, Songs con paginación, Calendar
- Music Release Form completo → redirige al calendario y el track aparece en Songs
- Email del submit llega a `info@sunsetgathering.com`
- My profile funciona (gate de perfil de artista)

---

## Operación diaria

```bash
# trabajás en una feature branch y cuando está lista:
git checkout develop
git merge tu-feature-branch
git push origin develop      # ← deploy automático
```

- `main` corre solo el CI (tests + build); no deployea.
- Cada push a `develop` = tests → build → deploy. Si los tests fallan, **no se sube nada**.
- Migraciones nuevas: el workflow corre `migrate --force` en cada deploy (se aplican solas).

## Problemas comunes

| Síntoma | Causa / fix |
|---|---|
| Action falla en *Configure SSH* / *Upload* | Secret mal seteado (`DEPLOY_HOST/PORT/USER/PATH/SSH_KEY`) o la clave pública mal pegada en hPanel |
| `Permission denied (publickey)` | SSH deshabilitado, clave equivocada, o falta el `-p` del puerto |
| 500 / "No application encryption key" | Falta el `.env` (paso 5) — el workflow genera `APP_KEY` solo si el archivo existe |
| `Access denied for user` en migrate | Credenciales de BD o el usuario sin ALL PRIVILEGES en la BD |
| Los assets dan 404 | `APP_URL` mal escrito o el docroot del subdominio no apunta a `.../artist/public` |
| Edité `.env` y "no hace efecto" | `php artisan config:clear` (el deploy vuelve a cachear en el próximo push) |
| Plan **Single** sin SSH | SSH requiere Premium o superior (o usá el deploy manual legacy de `DEPLOY.md`) |
| Querés deployar sin tocar develop | No se puede: `develop` es la única rama con deploy a propósito |

## Referencia

- Deploy manual legacy (subdirectorio `/administracion/` + BD compartida con WordPress): [`DEPLOY.md`](../DEPLOY.md)
- Workflow: [`.github/workflows/deploy-develop.yml`](../.github/workflows/deploy-develop.yml)
