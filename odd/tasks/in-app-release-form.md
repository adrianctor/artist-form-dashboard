# In-app Release Form + Artist Profiles

Objetivo: eliminar WordPress del flujo — el Music Release Form vive dentro de Laravel/Vue, con perfil de artista obligatorio por usuario, búsqueda de artistas registrados, radio Publishing, emails del sistema en vez de EmailJS y rename Song Code → Catalogo.
Branch: `feat/dashboard-mvp` · Runner: `docker compose exec -T app php artisan test` · Strategy: single-branch (sin PR aún).

## Specs

### S1 — El form queda dentro del sistema (sin WordPress)

> "ya no voy a usar el wordpress, el form debe quedar dentro del sistema laravel y vue, debe pasarse tal cual está en el html"

- Portar el HTML completo del form (bloque pegado por el usuario en L1; baseline del repo: `wordpress/music-release-form.html`) a una página Inertia/Vue interna: mismos 3 steps, mismos campos, mismas clases/estilos (`#musicReleaseForm`, radio-cards, steps bar, SweetAlert de disclaimer, cover condicional, artist blocks dinámicos).
- Ruta interna autenticada (p.ej. `GET /release-form`); el item del sidebar "Music Release Form" pasa de link externo (`FORM_URL`) a link interno de la app.
- El submit ya NO usa EmailJS: guarda la canción (mismo pipeline de ownership por `artists[].email`) y dispara el email del sistema (S2).
- Anti-spam `botcheck` se conserva como campo oculto.

### S2 — Emails del sistema reemplazan a EmailJS

> "se debe reemplazar el envio del correo por los email del sistema como los de verificación de cuenta y todo eso o el de recuperación de contraseña, te envio el template que tenía para ese email? practicamente es el mismo form pero en correo"

- Patrón igual que `VerifyEmail` / `ResetPassword`: Mailable/Notification Laravel, envío síncrono (sin queue), `MAIL_MAILER=array` en tests (`Mail::fake`).
- Destinatarios (asunción, ajustar si el template dice otra cosa): `to: info@sunsetgathering.com`, `cc:` emails de artistas, `reply_to:` primer artista — espejo del EmailJS actual (`wordpress/music-release-form.html`).
- **Template del cuerpo: PENDIENTE** — el usuario lo envía ("te envio el template"); hasta entonces se usa un cuerpo estructurado con todos los campos del form como placeholder.

### S3 — Radio "Publishing" antes de elegir número de artistas

> "además en el form hay un nuevo radio button que es Publishing, eso va antes de seleccionar el numero de artistas, el radio debe tener 2 opciones, por defecto marcado Afrodise y en ingles otra opcion de No cuento con esto"

- Ubicación: step de Artists, **arriba** del select "Number Of Artists".
- Opción 1: `Afrodise` — **marcada por defecto**.
- Opción 2: versión en inglés de "No cuento con esto" — **asunción**: label `I don't have this` (confirmar con el usuario si manda otro texto).
- Valor a nivel release (no por artista): viaja en el payload del submit y queda guardado en el `payload` json de la submission; validación API `publishing in:afrodise,none` (nullable para BC con submissions viejas).

### S4 — Búsqueda de artistas registrados en el form

> "despues de seleccionar los artistas se debe poder buscarlos de perfiles registrados en la plataforma como users. se buscan por nombre y correo y se llena en el form toda la info que los artistas hayan llenado"

- En cada artist block: buscador por **nombre o correo** contra users **con perfil de artista completo** (los incompletos NO aparecen — ver S5).
- Endpoint autenticado `GET /artist-search?q=` → `{id, name, artist_name, real_name, email, role, country, city, estate, photo, bio, status, links}`.
- Al elegir un resultado se autocompletan **todos** los campos del artista en el form (el email del resultado es el que viaja al submit para ownership).

### S5 — Perfil de artista obligatorio antes de entrar al dashboard

> "por lo tanto un usuario que se registre ya sea por sign up o por social media debe llenar toda la info de artista antes de poder ingresar a cualquier otra seccion del dashboard, la seccion es la seccion de artista del form html todos esos campos los debe llenar obligatoriamente el artista antes de poder aparecer en la busqueda"

- Schema nuevo: tabla `artist_profiles` (1:1 con users) con los campos de la sección Artists del HTML: `artist_name, real_name, role, country, city, estate, photo, bio, status (New Artist|Existing Artist), links (json)`; `email` = `users.email` (no se duplica columna).
- **Completo** = todos los campos no vacíos + `links` obligatorios solo si `status = Existing Artist` (misma regla condicional que el form).
- Gate middleware `EnsureArtistProfileComplete` (patrón del `verified`): usuario `role=user` sin perfil completo → redirect a la página de My profile; solo esa página (y logout) accesible. **Asunción**: rol `admin` exento (no es artista). Aplica a signup, social y usuarios creados por el admin.
- El gate también cubre la página del form (es otra sección del dashboard).
- Perfiles incompletos no devueltos por el search (S4).

### S6 — Item "My profile" en el menú de usuario

> "además, otro item del menu en usuarios es My profile que lo va a llevar a todo esta info de artistas a completas o posiblemente a editar si ya ha guardado su información"

- **Asunción de ubicación**: item `My profile` en el dropdown del usuario (`UserMenuContent.vue`, debajo de Settings — lo reusan NavUser y AppHeader).
- Página que muestra la sección de artista completa del HTML: crear si no existe, editar si ya guardó. Al guardar → perfil completo → el gate lo deja pasar.
- Validación server-side con las mismas reglas de completitud (S5).

### S7 — Catalogo (ex "Song Code")

> "finalmente, no es song code sino Catalogo y aunque es un identificador unico, debe quedar pendiente por que hasta ahora esto no se llena en el form"

- Rename de todos los labels visibles `Song Code` → `Catalogo` (songs Index/Show, Dashboard, Calendar, headers de columna).
- El identificador único sigue auto-generándose en el sistema (`SONG-`+8 hex, `Song::generateSongCode`) — es el id interno.
- El form **no** tiene ni muestra un campo de catálogo (ya era así en el HTML pegado: success box sin código) — queda pendiente de llenado futuro, fuera de este alcance.

## Tasks

| ID | Specs | Route | Status | Commit |
|----|-------|-------|--------|--------|
| T1 | S5 | inline | done | 38de594 |
| T2 | S5 | inline | done | 38de594 |
| T3 | S5, S6 | inline | done | 22a64ea |
| T4 | S4 | inline | done | (commit) |
| T5 | S1, S3, S4 | inline | done | (commit) |
| T6 | S1, S3 | inline | done | (commit) |
| T7 | S1, S3 | inline | pending | — |
| T8 | S2 | inline | pending | — |
| T9 | S7 | inline | pending | — |

- T1: migración `artist_profiles` + modelo + relación `User::artistProfile` + `hasCompleteArtistProfile()` + tests de completitud (RED→GREEN).
- T2: middleware `EnsureArtistProfileComplete` + alias + aplicación en todos los grupos authed (web/songs/users/calendar/settings/release-form) + tests (user incompleto → redirect my-profile; completo → pasa; admin → pasa).
- T3: `ArtistProfileController` (show/create/update) + rutas + página Vue con los campos del form + item "My profile" en `UserMenuContent.vue` + tests de gate end-to-end.
- T4: endpoint `GET /artist-search` (solo perfiles completos) + tests.
- T5: página Vue del form portada "tal cual" (3 steps, estilos, SweetAlert, cover condicional, artist blocks) + ruta + sidebar link interno + item Publishing (S3) + search/autofill por artista (S4).
- T6: submit del form → POST autenticado (sesión) al pipeline de songs (o ruta web equivalente) con `publishing` en el payload + tests de ownership/BC.
- T7: Mailable `ReleaseFormSubmitted` (to info@, cc artistas, reply_to primero; cuerpo = template del usuario cuando llegue) + `Mail::fake` tests; quitar EmailJS del port.
- T8: rename `Song Code` → `Catalogo` en todos los componentes JS + verificación.
- T9: limpieza `FORM_URL`/`formUrl` share si queda sin uso + docs (DEPLOY.md §5 pasa a "form interno").

## Log

L1 — 2026-11-18, petición original del usuario (verbatim, sin el bloque HTML completo del form que pegó — baseline en `wordpress/music-release-form.html` y en la sesión):

> "listo los nuevos requisitos que tengo son, ya no voy a usar el wordpress, el form debe quedar dentro del sistema laravel y vue, debe pasarse tal cual está en el html **«HTML completo del form pegado aquí»** y se debe reemplazar el envio del correo por los email del sistema como los de verificación de cuenta y todo eso o el de recuperación de contraseña, te envio el template que tenía para ese email? practicamente es el mismo form pero en correo, tambien en el form hay un nuevo radio button que es Publishing, eso va antes de seleccionar el numero de artistas, el radio debe tener 2 opciones, por defecto marcado Afrodise y en ingles otra opcion de No cuento con esto. Además despues de seleccionar los artistas se debe poder buscarlos de perfiles registrados en la plataforma como users. se buscan por nombre y correo y se llena en el form toda la info que los artistas hayan llenado por lo tanto un usuario que se registre ya sea por sign up o por social media debe llenar toda la info de artista antes de poder ingresar a cualquier otra seccion del dashboard, la seccion es la seccion de artista del form html todos esos campos los debe llenar obligatoriamente el artista antes de poder aparecer en la busqueda que te digo, además, otro item del menu en usuarios es My profile que lo va a llevar a todo esta info de artistas a completas o posiblemente a editar si ya ha guardado su información. finalmente, no es song code sino Catalogo y aunque es un identificador unico, debe quedar pendiente por que hasta ahora esto no se llena en el form."

### L2 — T1+T2: artist_profiles schema + profile gate (2026-11-19)

1. **T1 (S5)**: migración `artist_profiles` (1:1 users, json `links`, sin columna email — accessor lee de `users.email`), modelo `ArtistProfile` con `isComplete()` (todos los campos + links solo si `Existing Artist`), `User::artistProfile()` + `hasCompleteArtistProfile()`. Tests `ArtistProfileTest` (6) RED→GREEN. Gotcha: `user_id` tenía que entrar al `#[Fillable]` (mismo patrón que el bug de `email_verified_at`).
2. **T2 (S5)**: middleware `EnsureArtistProfileComplete` (alias `profile.complete` en bootstrap/app.php) — admin exento, `my-profile*` siempre pasa, resto redirige a `route('my-profile')`. Aplicado a todos los grupos authed (web/songs/users/calendar/settings). Ruta `GET /my-profile` + `ArtistProfileController@show` (página Vue en T3). Tests `ProfileGateTest` (8) RED→GREEN.
3. **Factory**: `UserFactory::configure()` crea un perfil completo vía `afterCreating` (los tests existentes de otras features necesitan usuarios que pasen el gate). Gotcha Laravel: `configure()` debe **devolver** el resultado de `afterCreating()` (devuelve nueva instancia) — `: void` rompe la cadena `->configure()->count()` con "count() on null". Tests que necesitan usuario sin perfil lo borran (`artistProfile()->delete()`).
4. **SocialLoginTest adaptado**: signup social nuevo → primer destino ahora `my-profile` (gate S5), ya no dashboard directo. El pino de "no cae en /email/verify" se mantiene (assertRedirect my-profile, no verification.notice).
5. Suite: **100 tests / 443 assertions** verdes.

### L3 — T3: página My profile + guardado + item de menú (2026-11-19)

1. **Endpoint** `PATCH /my-profile` (`ArtistProfileController::update`): valida los 9 campos required + `status in (New Artist, Existing Artist)` + `links.*` (12 plataformas); regla condicional — `Existing Artist` exige ≥1 link no vacío (error `links`), `New Artist` normaliza links a solo no-vacíos. `updateOrCreate` por `user_id`. Éxito → `to_route('dashboard')` (el gate lo deja pasar) + toast.
2. **Página `MyProfile.vue`**: campos de la sección artist del HTML (artist_name, real_name, role, country, city, estate, photo, bio) + radio cards **ARTIST PROFILE STATUS** (New/Existing, misma copy y estilo `#39d353` del form) + grid de 12 platform links (3 primeros marcados `*` si Existing). Inertia `Form` + Wayfinder (`wayfinder:generate --with-form` — sin `--with-form` rompe `.form()` de todas las páginas).
3. **Menú**: item **My profile** (icono User) en `UserMenuContent.vue` debajo de Settings — lo reusan NavUser y AppHeader. Spec S6.
4. **Gotcha vue-tsc**: `placeholder="https://..."` (con `//` + `...`) en atributos estáticos rompe el parser del template — `errors`/`processing` del `v-slot` y variables de `v-for` dejan de resolver desde ese punto (TS2304 en cadena). Fix: quitar `//`+`...` de placeholders (`"https://"` solo también falla; `"Link URL"` OK).
5. Tests: `MyProfileTest` (6) RED→GREEN. Suite: **106 tests / 477 assertions**, `vue-tsc` 0 errores, build OK (en `afd-vite`), E2E `GET /my-profile` → `component: MyProfile`. Commit `22a64ea`.

### L4 — T4: endpoint de búsqueda de artistas (2026-11-19)

`GET /artist-search?q=` (`ArtistSearchController@index`): matchea `users.name`, `users.email`, `artist_profiles.artist_name/real_name` (LIKE), filtra por `hasCompleteArtistProfile()`, ordena por nombre, límite 10 (busca ×5 candidatos antes del filtro para no quedarse corto). Respuesta JSON con todos los campos para autofill (spec S4). Guest → 401 (JSON). Tests `ArtistSearchTest` (8) RED→GREEN: nombre, email, real name, incompletos excluidos, query vacía, shape, límite.

### L5 — T5: página ReleaseForm portada + Publishing + búsqueda (2026-11-19)

1. **Página `ReleaseForm.vue`** (spec S1 "tal cual"): 3 steps (Release Info / Cover Information / Artists), barra de steps, disclaimer SweetAlert idéntico al original (bloqueado hasta aceptar), cover condicional (original_song_link/credits solo si is_cover=Yes), artist blocks dinámicos 1-4 con ARTIST PROFILE STATUS cards y 12 platform links (core 3 con `*` si Existing), success box. CSS portado a `resources/css/music-release-form.css` (2 style blocks del HTML original).
2. **Spec S3 — Publishing**: radio arriba de "Number Of Artists": `Afrodise` (default) | `I don't have this` (asunción EN de "No cuento con esto"). Valor `afrodise|none` en el payload.
3. **Spec S4 — búsqueda por artista**: input "Search registered artist" por block con debounce 250ms → `GET /artist-search` → dropdown → `applySearchHit` rellena todos los campos (nombre, real, email, role, país, city, estate, photo, bio, status, links). Blur cierra con delay (helper `closeSearchLater` — `setTimeout` inline en template rompe vue-tsc TS2339).
4. **Sidebar**: item Music Release Form ahora apunta a `releaseForm()` (ruta interna) en vez de `formUrl` externo — spec S1. `FileMusic` import removido.
5. **Ruta** `GET /release-form` (`ReleaseFormController@show`) bajo el gate `profile.complete`. Payload del submit armado (`buildPayload`) — el POST se conecta en T6.
6. Tests `ReleaseFormPageTest` (3): guest→login, auth→200 component, dashboard sigue compartiendo formUrl. Suite: **117 tests / 525 assertions**, vue-tsc 0, build OK.

### L6 — T6: submit del form al pipeline de songs (2026-11-19)

1. **`SongSubmissionService`** extraído del `SongController::store` — ownership por artist emails + transacción (create/append/backfill) en UN solo lugar; el API y el form in-app lo comparten (spec S1, invariante de duplicación).
2. **`POST /release-form`** (`ReleaseFormController::store`): validación estricta (campos required del form, `publishing in:afrodise,none`, artistas completos, `status in(New/Existing)`), regla condicional Existing→≥1 link (error `artists.N.links`), normaliza links/cover vacíos. Éxito → redirect `release-form?submitted=1` + toast; **no muestra Catalogo** (spec S7: pendiente, el form no lo llena).
3. **API BC**: `SongController` ahora acepta `publishing` nullable (`in:afrodise,none`) y delega al servicio — 8 tests de API siguen verdes.
4. **Frontend**: `showSuccess` lee `?submitted=1` de la URL de Inertia (skip del disclaimer al volver, success box visible).
5. Tests `ReleaseFormSubmitTest` (7) RED→GREEN: guest→login, submit crea song+ownership+publishing en payload, `none` aceptado, publishing inválido→422, track_title vacío→422, Existing sin links→`artists.0.links`, flag submitted. Suite: **124 tests / 557 assertions**, vue-tsc 0, build OK.
