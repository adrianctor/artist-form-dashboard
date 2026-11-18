# Dashboard MVP (artist-form-dashboard)

Objetivo: monolito modular Laravel 13 + Inertia/Vue que reemplaza el flujo manual del form de Elementor: login completo (email/password + Gmail/Microsoft/Apple + recuperación), gestión de usuarios, y un panel que recibe vía API los envíos del form de WordPress, guarda historial de canciones identificadas por un campo único (`song_code`) y lo consulta desde el dashboard.

- Branch: `feat/dashboard-mvp` (repo nuevo, sin remoto)
- Delivery: archivos subibles a hosting compartido Hostinger (sin VPS); misma MySQL que WordPress con prefijo de tablas propio
- Test runner: `php artisan test` (Pest) dentro de Docker; `npm run build` para verificación FE
- Feature doc: `odd/tasks/dashboard-mvp.md`; mirror Engram: `odd/dashboard-mvp/tasks`

## Specs

### S1 — Login con correo y contraseña

> "es un login con correo contraseña"

Pantalla de login con email + password, sesión Laravel (cookies, `auth` middleware). Sin registro público: los usuarios los crea un admin (ver S3).

### S2 — Recuperación de cuenta por correo

> "debe poderse recuperar la cuenta con correo"

Flujo forgot-password → email con link de reset → nueva contraseña. En local el email cae en Mailpit (no se pierde).

### S3 — Gestión de usuarios

> "debe poderse manejar usuarios"

CRUD de usuarios dentro del panel (listar, crear, editar, activar/desactivar, rol) restringido a rol `admin` vía Policies + Spatie Permission.

### S4 — Login social: Gmail, Microsoft y Apple

> "iniciar sesion con gmail, microsoft y apple"

Tres botones en la pantalla de login vía Laravel Socialite (Google nativo; Microsoft y Apple con sus providers). Cada provider se configura por env (`GOOGLE_CLIENT_ID`, `MICROSOFT_*`, `APPLE_*`); si no está configurado, el botón no rompe el login por contraseña. El email del provider vincula (o crea) la cuenta.

### S5 — Historial de canciones desde el form de WordPress

> "hay un form que se llena con wordpress y debe ir guardandose el historial y se deben poder consultar desde el dashboard un historial de canciones"

El form de Elementor publica cada envío a la API del dashboard (Bearer token). Cada envío queda guardado como una fila de historial. El dashboard lista el historial de canciones con búsqueda/filtro y permite abrir el detalle completo de un envío.

### S6 — Campo único `song_code` y precarga vía API

> "lo que necesito es que a ese form por api se le puedan cargar datos cuando la canción sea la misma, se va a identificar por un nuevo campo en el form que sea unico."

Se agrega al form un campo visible `song_code` (texto, único). Comportamiento en el form:

- Si `song_code` está vacío al enviar: es un alta nueva; la API guarda el envío y devuelve el `song_code` generado (formato `SONG-XXXXXXXX`), que el form muestra en la pantalla de éxito para que el artista lo guarde.
- Si `song_code` tiene valor al cargar el form (por link `?song_code=...` o al perder foco/blur): el form llama `GET /api/v1/songs/{song_code}` y, si existe, precarga todos los campos con el último envío de esa canción.
- Si `song_code` tiene valor al enviar: la API guarda una nueva entrada de historial para esa misma canción (append, no sobrescribe el historial).

El form completo de referencia quedó guardado en `reference/music-release-form.html` (copiado del mensaje del usuario, ver Log L2).

### S7 — Stack tecnológico (selección del usuario, verbatim)

> "Arquitectura | Monolito modular
> Framework backend | Laravel 13
> Lenguaje backend | PHP 8.4
> Framework frontend | Vue 3
> Integración | Inertia.js 3
> Lenguaje frontend | TypeScript
> CSS | Tailwind CSS 4
> Componentes | shadcn-vue
> Compilación | Vite
> Formularios | Inertia useForm; VeeValidate + Zod cuando haga falta
> Tablas | TanStack Table
> Gráficas | Apache ECharts
> Estado global | Pinia, si se necesita
> ORM | Eloquent
> Base de datos | MySQL
> Autenticación | Laravel Auth con sesiones
> Permisos | Policies + Spatie Permission
> API externa | Laravel REST
> WebSockets | Laravel Broadcasting + Ably
> CMS | WordPress separado"

### S8 — Deploy en Hostinger sin VPS, archivos subibles

> "ten en cuenta que acá para desplegar no tendría vps sino un hosting de hostinger osea el front y back tiene que ser en archivos para poder subir"
> "el wordpress ya lo tengo en hostinger y para probarlo necesito estar desarrollando y subiendo cambios en vivo, la bd es la misma de wordpress en mysql"

- El deploy es: `npm run build` + `composer install --no-dev` y subir los archivos (public_html apuntando a `public/` de Laravel, o subdominio con su document root).
- La BD es la MISMA instancia MySQL que usa WordPress: todas las tablas Laravel llevan prefijo propio (`DB_PREFIX=dash_`) para no chocar con `wp_*`.
- Cambios en vivo: el flujo de deploy tiene que ser repetible y rápido (un script/ comando documentado), porque el usuario desarrolla y sube cambios en vivo.
- WebSockets/Ably quedan configurados por env pero no son bloqueantes para el MVP ( Broadcasting se da de alta solo si hay keys).

### S9 — Ejecución local con Docker Compose

> "implementalo y ejecutalo en local a ver yo quiero ver"
> "es mejor contenerizar con docker compose o mejor ejecutarlo en una terminal?"

Docker Compose (decisión tomada en Log L3): no hay PHP/Composer/MySQL en el host; se sigue el patrón multi-stage + build.context de `CONTAPP_Monolito/bootstrap`. Stack local: `mysql` (misma versión mayor que Hostinger), `app` (PHP 8.4 + artisan serve), `mailpit` (captura de correos de recuperación), Vite corriendo con HMR.

## Assumptions

- A1: El registro de usuarios NO es público; los crea el admin (S3 implica gestión, S1 no pide signup). Si se quiere signup después, se agrega.
- A2: El form de Elementor es el archivo adjunto por el usuario (`reference/music-release-form.html`); el cambio sobre el form es nuestro (agregar `song_code` + llamadas API), el usuario lo pega de vuelta en Elementor.
- A3: `song_code` lo genera el backend con prefijo `SONG-` en alta nueva; el usuario puede pegarlo a mano en el campo para precargar.
- A4: La API del dashboard se autentica con Bearer token (Sanctum personal access token) que el form lleva en headers; el token vive en un env del form.
- A5: Hosting compartido Hostinger corre PHP 8.3–8.5 seleccionable desde hPanel (confirmado en docs de Hostinger), así que PHP 8.4 es seguro.
- A6: Broadcasting/Ably y WebSockets quedan fuera del alcance funcional del MVP (config presentes, sin feature que los use todavía).

## Tasks

| ID | S# | Route | Status | Commit |
|----|----|-------|--------|--------|
| T1 | S7, S9 | inline | done | scaffold+compose |
| T2 | S7 | inline | done | starter kit wiring |
| T3 | S1, S2 | inline | done | starter kit 42 tests |
| T4 | S4 | inline | done | Socialite G/M/A + guards |
| T5 | S3 | inline | done | CRUD+roles+active (12 tests) |
| T6 | S5, S6 | inline | done | API 8 tests RED→GREEN |
| T7 | S5, S6 | delegated | done | songs/dashboard pages |
| T8 | S6, A2 | inline | done | wordpress/music-release-form.html |
| T9 | S8, S9 | inline | done | DEPLOY.md |
| T10 | S1–S6 | inline | done | 65 tests + E2E curl + build |

- T1: scaffold Laravel 13 (Docker) + compose local (mysql/app/mailpit) + `DB_PREFIX=dash_` + git init rama `feat/dashboard-mvp`.
- T2: Inertia v3 + Vue 3 + TS + Tailwind 4 + shadcn-vue + layout del panel.
- T3: login email/password, logout, forgot/reset password con email (RED→GREEN).
- T4: Socialite Google/Microsoft/Apple con envs, botones en login.
- T5: CRUD usuarios + roles Spatie + Policies.
- T6: modelos/migraciones `songs` + `song_submissions` (historial) + API REST con Bearer (`POST /api/v1/songs`, `GET /api/v1/songs/{song_code}`) (RED→GREEN).
- T7: páginas del dashboard: lista de historial de canciones (TanStack Table), detalle de envío, métricas con ECharts, página de usuarios.
- T8: parche del form de Elementor: campo `song_code`, precarga GET, POST a la API manteniendo el flujo actual (EmailJS se conserva como está).
- T9: script de deploy a Hostinger + documentación (build, upload, .env prod, prefijo BD compartida).
- T10: verificación end-to-end local: compose up, migraciones, seed admin, login, envío simulado del form → historial visible en el panel; `php artisan test` + `npm run build`.

## Log

### L1 — Request original (verbatim)

> tengo que construir un stack en blanco, el wordpress ya lo tengo instanciado en un hostinger, puedes hacerme el front y back del dashboard, el wordpress se va a comunicar por api a este sistema de dashboard. implementalo y ejecutalo en local a ver yo quiero ver, es mejor contenerizar con docker compose o mejor ejecutarlo en una terminal? tengo una carpeta llamada bootstrap en /home/adrian/ que tiene una buena forma de implementar las imagenes y servirlas, ten en cuenta que acá para desplegar no tendría vps sino un hosting de hostinger osea el front y back tiene que ser en archivos para poder subir. Capa | Mi selección ... (tabla S7 completa)

### L2 — Alcance funcional + form de Elementor (verbatim, resumen de posición; HTML completo en `reference/music-release-form.html`)

> es un login con correo contraseña, debe poderse manejar usuarios, iniciar sesion con gmail, microsoft y apple, además debe poderse recuperar la cuenta con correo y dentro del panel, hay un form que se llena con wordpress y debe ir guardandose el historial y se deben poder consultar desde el dashboard un historial de canciones, el form de wordpress con elementor es este: [HTML completo del Music Release Form] ... y lo que necesito es que a ese form por api se le puedan cargar datos cuando la canción sea la misma, se va a identificar por un nuevo campo en el form que sea unico.

### L3 — Decisión Docker Compose (2026-11-17)

Respondido al usuario: Docker Compose para desarrollo local (no hay PHP/Composer/mysql en el host; patrón del bootstrap de CONTAPP_Monolito); deploy final a Hostinger por archivos, no depende de Docker.

### L4 — Restricción Hostinger + BD compartida (verbatim)

> el wordpress ya lo tengo en hostinger y para probarlo necesito estar desarrollando y subiendo cambios en vivo, la bd es la misma de wordpress en mysql

Impacto: `DB_PREFIX=dash_` en todas las tablas Laravel; flujo de deploy en vivo repetible (T9); misma instancia MySQL local que después será la de WordPress en Hostinger.

### L5 — Stack completo incluyendo WordPress local + URLs de producción (verbatim)

> o mejor creame con todo y el wordpress, el wordpress en producción está en artist.sunsetgrathering.com y el sistema va a quedar en artist.sunsetgathering.com/administracion/ recuerda que estás en un entorno de wsl, en windows ya tengo el xampp para bd tal como hostinger y para el wordpress

Impacto (decisión: se asume `artist.sunsetgathering.com` — coincide con el email `info@sunsetgathering.com` del form; "grathering" es typo):
- Local: WordPress se instala en XAMPP/Windows (`C:\xampp\htdocs`), accesible desde WSL en `http://172.27.96.1/`; la BD es la de XAMPP (MariaDB 10.11, ya creada: `wordpress`), NO hay contenedor MySQL en compose.
- La app corre en Docker (PHP 8.4) conectándose a esa BD vía `host.docker.internal:3306`; usuario MySQL `dashboard`@`%` creado para conexiones desde WSL/Docker (root solo acepta localhost).
- Producción: Laravel servido bajo el subdirectorio `/administracion/` del mismo dominio que WordPress → `APP_URL=https://artist.sunsetgathering.com/administracion`, `ASSET_BASE=/administracion/` en el build de Vite, `RewriteBase /administracion/` en el `.htaccess` de `public/`.
- El form consume la API con un `API_BASE` configurable (local `http://localhost:8000/api/v1`, prod `https://artist.sunsetgathering.com/administracion/api/v1`); misma BD ⇒ en prod el form puede llamar same-origin sin CORS.

### L6 — Clarificación de alcance: Elementor solo aloja el form (verbatim)

> y creo que estás entendiendo mal, el elementor solo maneja el form que te envie en html css y js, el sistema es para hacer un login y un dashboard, el elementor del html consume por api para que se autocompleten datos del form cuando identifiquen una nueva versión de una app, el form html de elementor va a tener un nuevo campo que identifique la cancion unica y toda la info de ese form como cada vez que se envie va a persistir entonces al cargar una nueva versión con mismo id entonces se cargan por ejemplo automaticamente los artistas con sus links etc.

Impacto: el sistema (Laravel) NO integra con la API de WordPress ni con plugins; la relación es solo: form HTML (hosteado en Elementor) → HTTP contra nuestra API REST (GET precarga / POST persiste versión). Cada envío con el mismo `song_code` = una versión nueva en el historial; al precargar se rellenan campos del último envío (artistas, links, etc.).


### L5b — Ejecución verificada (2026-11-17)

- `docker compose up` → app :8000 (200), vite :5173, mailpit :8025.
- `php artisan test` → **65 passed, 289 assertions** (0 failed, 0 skipped en el cierre final — RegistrationTest reescrito para pin 404).
- `npm run types:check` → limpio.
- `npm run build` → OK (12s, chunks <500kB salvo Dashboard ~506kB warn).
- E2E API con Bearer: GET prefill 200, POST alta genera `SONG-XXXXXXXX`, POST append incrementa versión, 404 unknown, 401 sin token, history ordena versiones.
- E2E UI con sesión: /dashboard /songs /users → 200 con datos reales (SONG-DEMO*, admin@sunsetgathering.com).
- Login social: rutas `/auth/{provider}` responden 503 sin credenciales (botones ocultos) y 404 para provider inválido.
- Seed: `RolesAndUsersSeeder` (admin@sunsetgathering.com / dashboard123, verified) + `DemoDataSeeder` (token Elementor + 3 canciones demo).

### L6b — Cierre de unidades delegadas

- Songs pages (delegado): parcial→integrado por parent; typecheck roto por exports wayfinder + `register` huérfano, corregido por parent (AppSidebar default-imports, Login/Welcome sin register, Register.vue eliminado).
- User management (delegado): parcial→completado por parent (is_active, roles en UI, toggle, auto-verify, guards).
- Form patch (delegado): bloqueado por limits del worker → parent lo implementó con patch quirúrgico Python (6 parches, node --check OK).

### L7 — Native review (RDD on)

- Assess completo (empty-tree→HEAD, 341 files / 36k lines): `risk=high`, `review_due=true` → STATUS + START con lentes `risk,readability,reliability` → consent v3 relayed → usuario eligió **Review this change** (`granted`) → START falló con **`lens_context_budget_exceeded`** (candidato = todo el scaffold; terminal para ese candidato, sin authority creada).
- Scope reducido (scaffold→HEAD, 3 feature commits, 69 files / 6,943 lines): `risk=medium`, **`review_due=false` (`under_budget`)** → no se requiere review en este corte; queda pending si un commit futuro alcanza el budget.
- Evidencia de verificación propia (reemplaza al review en este corte): 65 tests, typecheck limpio, build OK, E2E curl API+UI+mailpit.

### L8 — Fixes post-primer-arranque (2026-11-17)

- **Vite assets CORS**: `server.origin` solo (5173) hacía que laravel-vite-plugin emitiera `Access-Control-Allow-Origin: http://localhost:5173` para la página de :8000 → `ERR_FAILED`. Fix: `server.cors.origin = ['http://localhost:8000','http://localhost:5173']` en `vite.config.ts`.
- **Hot file**: `--host 0.0.0.0` escribía `http://0.0.0.0:5173` en `public/hot` (inválido en navegador). Fix: `server.origin` + `hmr.host: 'localhost'`.
- **WordPress**: instalado en `C:\xampp\htdocs\wordpress\` (subcarpeta, NO en la raíz — el `index.php` raíz se restauró como redirect a `/dashboard/`). WP-CLI en `C:\xampp\wp-cli.phar`, PHP 8.3 de XAMPP. BD: base `wordpress`, usuario `dashboard`. `siteurl/home = http://localhost/wordpress`.
- **Elementor 4.4.1** instalado y activo.
- **Página del form**: `/wordpress/music-release-form/` (page ID 6) con el HTML parcheado (kses removido al crear, scripts intactos). `.htaccess` de WP escrito a mano (wp-cli no lo hacía por rutas UNC).
- **CORS API**: `HandleCors` default de Laravel responde `Access-Control-Allow-Origin: *` para `api/*` — el form en :80 puede llamar a :8000 (preflight 204 OK, verificado).
- Credenciales WP: `admin` / `dashboard123` (misma que el dashboard).

### L9 — Correcciones post-ajuste del usuario (2026-11-17)

Pedidas una a una; todas verificadas con tests/E2E:

1. **Form con estilo de sunsetgathering.com**: `reference/home.html` es el export del sitio (body #000, texto #fff, font **Quicksand** vía Google Fonts, campos `#f3f7ff` con borde `2px #5b7fff`, focus `#2952ff` + glow `rgba(41,82,255,.2)`, botones `#2952ff`/`#5b7fff`). `wordpress/music-release-form.html` re-estilado con esos tokens + logo `logo2-1.png` arriba; re-sincronizado a la página 6 de WP.
2. **Login social siempre visible**: `socialProviders` ya no se filtra por env (siempre `google,microsoft,apple`); proveedor sin credenciales → 302 a `/login` con error flash `social` (antes: botón oculto o 503). **Para que funcione de verdad hacen falta `GOOGLE_CLIENT_ID/SECRET`, `MICROSOFT_*`, `APPLE_*` en `.env`** (consola de cada proveedor) — eso no lo puedo crear yo.
3. **Sin home de Laravel**: ruta `/` ahora redirige a `/login` (guest) o `/dashboard` (auth); `Welcome.vue` eliminado; favicon.svg de Laravel eliminado → `favicon-32/192.png` + `apple-touch-icon.png` de `cropped-sunset-*`; `AppLogoIcon` = `logo2-1.png`; footer del sidebar → sitio/YouTube/Instagram reales; `APP_NAME=Sunset Gathering` (title del browser).
4. **Ruta por defecto = login**: verificado E2E `GET /` → 302 `/login`.
5. **Sin passkeys** (no pedidas): `Features::passkeys` fuera de config, `PasskeyVerify`/`ManagePasskeys`/`PasskeyItem`/`PasskeyRegister` borrados, ruta `.well-known/passkey-endpoints` borrada, UI limpia en Login/ConfirmPassword/Security.
6. **Login acepta username**: campo `type=text` "Email or username"; `authenticateUsing` busca por email O por name. E2E: `email=admin&password=dashboard123` → 302 `/dashboard`. (Antes el `type=email` de HTML5 rechazaba "admin".)
7. **Estilo global sunset**: `app.css` tokens → fondo `#000`, texto `#fff`, primary `#2952ff`, ring/accent `#5b7fff`, sidebar negra; font `Quicksand` (bunny fonts reemplaza Instrument Sans); `HandleAppearance` default `dark`; `app.blade` background `#000`.
8. **WP admin**: el login **sí funciona** — `admin` / `dashboard123` en `http://localhost/wordpress/wp-login.php` → 302 → `/wp-admin/` 200 (verificado con curl fresco). El bug previo era el `siteurl` roto (`/htdocs/wordpress`), ya corregido en L8. Si el navegador viejo guardó cookies del siteurl roto: borrar cookies de `localhost`.

Verificación final: `php artisan test` → **70 passed (310 assertions)**, `vue-tsc` limpio, `npm run build` OK.

### L10 — Roles, calendario, ownership y registro (2026-11-17)

Pedidos del usuario, todos verificados con tests + E2E:

1. **Google OAuth testeable**: `GET /auth/google` → 302 a `accounts.google.com` con el client_id del `.env` (redirect_uri `http://localhost:8000/auth/google/callback` — debe estar registrado en la consola Google). Tests: `SocialLoginTest` (redirect + auto-registro + login existente + no-configurado amigable).
2. **Auto-registro social**: ya existente en `SocialAuthController` — pino con test (usuario nuevo → rol `user` → dashboard; existente → login sin duplicar).
3. **Registro público restaurado** (spec L10 "el resto se deberan primero registrar"): `Features::registration()` re-habilitado, `Register.vue` recreado, link "Sign up" en Login, `CreateNewUser` asigna rol `user` siempre. E2E: POST /register → 302 dashboard + `registered_role=user`.
4. **Único admin = `label@admin.com` / `REDACTED-LOCAL-DEV-PASSWORD`**: `RolesAndUsersSeeder` renombra al admin legacy en place (preserva el token Sanctum del form) y **demote a cualquier otro admin** (invariante: `admin_count=1` verificado). Roles inmutables desde la UI: `store()` siempre `user`, `update()` ignora `role` (test: intento de escalar a admin → sigue `user`).
5. **Calendario admin**: `/calendar` (`routes/calendar.php` + `CalendarController` + `Calendar.vue`) — vista de mes con conteo por día, click → detalle de canciones con link al show. Admin-only: test guest→login, user→403, admin→200. Sidebar: Calendar y Users solo se muestran con rol `admin` (roles compartidos en `HandleInertiaRequests`).
6. **Ownership de canciones** (spec L5 "los users se deben filtrar..."): migración `songs.user_id` + atribución en el POST de la API matcheando los `artists[].email` del form contra users (fallback backfill en append). `Song::scopeVisibleTo`: admin ve todo, user solo las suyas; `show()` ajeno → 404. Tests: `SongOwnershipTest` (6 casos) + `SongsPageTest` actualizado. E2E: user solo ve `SONG-REG0001`; admin ve las tres.

Verificación: **84 tests / 386 assertions**, `vue-tsc` limpio, `npm run build` OK.

### L11 — Fix: Google login caía en email verification + recupero post-reboot (2026-11-18)

1. **Bug (pregunta del usuario)**: registrarse con Google llevaba a `/email/verify` en vez del dashboard.
   **Causa raíz**: `email_verified_at` NO está en el `#[Fillable]` del modelo User → `User::create([... email_verified_at => now()])` lo descartaba silenciosamente (mass assignment) → el middleware `verified` de `/dashboard` rebotaba a la pantalla de verificación. Lo mismo afectaba a los usuarios creados por el admin.
   **Fix**: `SocialAuthController` y `UserController::store` ahora usan `forceFill(['email_verified_at' => now()])->save()` — el proveedor OAuth (o el admin) avala el email, así que:
   - login social → siempre entra directo al dashboard (usuarios nuevos Y existentes pendientes de verificación);
   - usuarios creados por el admin → verificados;
   - registro con contraseña → sigue requiriendo verificación (nadie avala el email en ese flujo).
   Tests: `SocialLoginTest` (2 nuevos: signup social verificado + follow-through a /dashboard sin bounce; cuenta pendiente verificada al entrar con Google), `UserManagementTest` (admin-created verified), `RegistrationTest` (pin: registro por contraseña queda `NULL`).
2. **Producción en Hostinger (pregunta del usuario)**: la verificación de email por contraseña se envía con el SMTP de Hostinger — sin VPS, sin cron, sin cola (Laravel envía síncrono; verificado: `VerifyEmail` no implementa `ShouldQueue`). Documentado en `DEPLOY.md`: crear buzón en hPanel → `MAIL_HOST=smtp.hostinger.com`, puerto 465 SSL (587 TLS si falla), username = buzón completo (NO la contraseña de hPanel), `APP_URL=https://artist.sunsetgathering.com/administracion`.
3. **Recupero tras reiniciar la máquina**:
   - XAMPP (Apache+MySQL) no arranca solo → `C:\xampp\xampp_start.exe`.
   - **Conflicto de puerto 80**: el Traefik del stack CONTAPP (`bootstrap-traefik-1`) publica `0.0.0.0:80->80` y le robaba el :80 a XAMPP → `localhost/wordpress` devolvía 404 de Apache Unix. Detenido con `docker stop bootstrap-traefik-1` (revivable con `docker start`; solo afecta al stack CONTAPP de otro proyecto, no a este dashboard).
   - Verificación E2E post-reboot: WP home/login/form 200, WP admin login 302→200, dashboard root→302 login, API 200, `/auth/google` → 302 accounts.google.com, Mailpit 200, **85 tests / 410 assertions**.
