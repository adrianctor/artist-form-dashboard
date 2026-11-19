# Form Delivery & Scale

Objetivo: hacer visible y confiable el envío del form (navegación al calendario, creador y artistas ven su track, historial/correo completos), abrir el calendario a todos los usuarios y preparar tablas/calendario para millones de registros (paginación + índices + consultas acotadas).
Branch: `feat/dashboard-mvp` · Runner: `docker compose exec -T app php artisan test`.

## Specs

### S1 — Icono en el menú

> "en el menú ponle un icono al music release form"

El item **Music Release Form** del sidebar lleva icono (`FileMusic` de lucide), igual que el resto de los items.

### S2 — Calendario para todos

> "el calendario no solo es para el admin sino para todos"

`GET /calendar` deja de requerir rol admin: accede cualquier usuario autenticado (tras el gate de perfil). Cada usuario ve **sus** canciones (`visibleTo`: admin ve todas); el admin sigue viendo todo. Sidebar: Calendar visible para todos los roles (quitar el gate `isAdmin` del item). Actualizar `CalendarTest` (antes: user→403).

### S3 — Cada submit en el calendario con su fecha

> "cada submit del form que es una cancion o un track debería aparecer en el formulario con la fecha en que se guardó la track o se hizo submit"

(«formulario» = calendario.) El calendario agrupa **submissions** (`song_submissions.created_at`), no canciones por fecha de creación: cada envío del form aparece en el día en que se hizo, aunque sea la N-ésima versión de una canción existente. Cada ítem enlaza a su Show (mismo detalle que desde Songs).

### S4 — Escala del calendario (millones de registros)

> "los calendarios tambien deben soportar millones de registros y funcionar bien, me refiero a que si hay millones de registros en la bd solo carguen una parte y todo esté con indices y optimizado"

- `GET /calendar?month=YYYY-MM` (default: mes actual) → solo se consultan las submissions de ese rango (`whereBetween created_at` con índice).
- Conteo por día vía aggregate SQL (`GROUP BY DATE(created_at)`), no carga de filas.
- Detalle del día bajo demanda: `?day=YYYY-MM-DD&page=N` → `paginate(50)` de ese día.
- Navegación de mes/día en `Calendar.vue` vía `router.get` (props del servidor).
- Índices nuevos (migración): `songs(created_at)`, `songs(updated_at)`, `song_submissions(created_at)`, `song_submissions(created_by)` (ver S6). `songs(song_code)` ya unique, `songs(user_id)` ya FK-indexed, `song_submissions(song_id, created_at)` ya existe.

### S5 — Send form: navega al calendario, sin "no hace nada"

> "hago el send form y no me aparece en songs ... pero no da error la consola solo no hace nada, ni me lleva a songs o en sí debería llevar es al calendario"

Diagnóstico (E2E reproducido): el POST **sí crea** la canción (302 + fila en BD + email en Mailpit), pero el usuario no ve nada porque (a) `showSuccess` era un `ref` inicializado al montar — con redirect de Inertia al mismo componente no se recalcula → el success box nunca aparecía; (b) `redirect()->with('toast')` no llegaba a los props de Inertia (no se compartía); (c) no había navegación.

Fix:
- Éxito → **redirect a `/calendar`** (lo que pidió el usuario) + `Inertia::flash('toast', ...)` (patrón de ProfileController).
- Quitar el success box/`?submitted=1` del form (ya no se usa).
- Errores de validación 422 (redirect back) → **visibles en el form** (lista de `page.props.errors` arriba del form) para que nunca falle en silencio.
- Actualizar `ReleaseFormSubmitTest` (redirect → calendar, quitar test del flag submitted).

### S6 — Quién creó el track + quiénes lo pueden ver

> "no me aparece en songs ni en el usuario que lo creó ( que el historial debe tener eso explicito osea además de los artistas debe decir quien lo creó) ni en los usuarios que agregué al form"

Diagnóstico: el guardado asigna `songs.user_id` = **un solo** artista (primer email que matchea un user). Resultado real en BD: "Title 1" → `user_id=10` (adctg2799); el segundo artista con cuenta (adriantor, id 11) **no la ve**, y el creador (si no coincide por email) tampoco.

Fix:
- Migración: `song_submissions.created_by` (nullable FK users, `nullOnDelete`) + `songs.artist_emails` (text, formato `,email1,email2,` en minúsculas, backfill desde submissions existentes).
- `SongSubmissionService::submit(..., ?int $createdBy)`: setea `created_by` en cada submission (ReleaseFormController pasa `$request->user()?->id`; API Sanctum idem) y recalcula `songs.artist_emails` (unión de emails de todos los submissions, lowercase).
- `Song::scopeVisibleTo` (no-admin): `user_id = me` **OR** existe submission con `created_by = me` **OR** `artist_emails LIKE '%,mi-email,%'` (collation CI de MySQL) — cubre creador, owner y **todos** los artistas agregados con cuenta.
- `SongPageController::show`: autorización vía el mismo scope (`firstOrFail` → 404), sin lógica duplicada.
- **Historial**: cada versión en Show muestra **"Created by: nombre"** (`created_by` → relación User; vacío en submissions antiguas sin dato).

### S7 — Historial/correo con toda la información

> "cuando en el historial quiero ver una track o submit no me aparece sino el titulo y nada de lo que se lleno en todo el form, debe aparecer completo y hasta el correo debe tener toda la información del submit lo mismo que si la reviso desde el calendario o desde songs"

- Show (único destino desde calendario y Songs) renderiza **todo** el payload + todos los campos de cada artista (incl. correos y links) — fijarlo con test: submission con payload completo → todos los campos en los props del Show.
- **Correo** (`ReleaseFormSubmitted`): render **genérico del payload** (todas las claves no-vacias + cada artista con todos sus campos y links) en lugar de lista fija de campos — paridad con Show garantizada por construcción. Test: cada clave del payload aparece en el mail.

### S8 — Mailpit en local

> "el mailpit no está funcionando en local, ahí deberian ir en local todos los correos no?"

Sí — todos los correos locales (verificación, reset, release form) van a Mailpit. Estado: `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit:1025` ya estaba bien y los correos **sí llegaban** (3 mensajes vía API interna); el contenedor tenía el puerto 8025 colgado (respuesta vacía desde el host). Fix: `docker restart afd-mailpit` → UI `http://localhost:8025` responde 200. Documentar el síntoma y el comando. (El restart vació la bandeja de prueba; los próximos envíos vuelven a aparecer.)

### S9 — Auditoría de paginación/índices de tablas

> "las tablas deben llevar paginación para soportar millones de registros y no volverse lentas ... todo esté con indices y optimizado"

Auditado:
- Songs Index: `paginate(15)` + links server-side ✓ (Index.vue ya los renderiza).
- Users Index: `paginate(15)` ✓.
- Dashboard: recent `limit(8)`; stats `count/sum` con índices FK; `submissionsDaily` aggregate de30 días (le gana el índice `song_submissions(created_at)` nuevo).
- Show (historial por canción): acotado a las versions de una canción — sin carga global.
- Migración S4 agrega los índices faltantes (`songs.created_at`, `songs.updated_at`, `song_submissions.created_at`, `song_submissions.created_by`).

## Tasks

| ID | Specs | Route | Status | Commit |
|----|-------|-------|--------|--------|
| T1 | S5, S2, S1 | inline | done | (commit) |
| T2 | S6 | inline | done | (commit) |
| T3 | S3, S4, S2 | inline | pending | — |
| T4 | S7 | inline | pending | — |
| T5 | S8, S9 | inline | pending | — |

- T1: form UX — redirect a `/calendar` + `Inertia::flash` + errores 422 visibles + quitar success box; icono FileMusic en sidebar; Calendar visible para todos en sidebar (S2 sidebar); tests actualizados (`ReleaseFormSubmitTest`, `DashboardTest` si aplica).
- T2: migración (`song_submissions.created_by`, `songs.artist_emails` + backfill + índices de S4/S9) + `SongSubmissionService` (createdBy + artist_emails) + `scopeVisibleTo` ampliado + `show()` vía scope + "Created by" en Show.vue + tests nuevos (creador ve, segundo artista ve, desconocido no, Show incluye created_by).
- T3: calendario — controller con `month/day/page` + aggregate por día + `paginate(50)` del día + `visibleTo` + `Calendar.vue` server-driven (mes/día/paginación) + copy "form submits" + `CalendarTest` reescrito (todos acceden, mes acotado, día paginado, solo-suyas para user).
- T4: Show completo + correo genérico — test de paridad Show (payload completo → todos los campos) + `ReleaseFormSubmitted` render genérico + test "todas las claves del payload en el mail".
- T5: verificación final — Mailpit E2E (envío → API → UI 200), suite completa, `vue-tsc`, build, E2E navegador-sim (login → form → redirect calendario → track visible para creador y artista), doc de cierre.

## Log

L1 — 2026-11-19, petición original del usuario (verbatim):

> "además el mailpit no está funcionando en local, ahí deberian ir en local todos los correos no? Además el mailpit no está funcionando en local, ahí deberian ir en local todos los correos no?"

(Nota: L1 corresponde al turno de Mailpit; el lote completo de requisitos S1-S9 viene del mensaje inmediatamente anterior, transcrito en sus specs:)

> "en el menú ponle un icono al music release form, además el calendario no solo es para el admin sino para todos, las tablas deben llevar paginación para soportar millones de registros y no volverse lentas, los calendarios tambien deben soportar millones de registros y funcionar bien, me refiero a que si hay millones de registros en la bd solo carguen una parte y todo esté con indices y optimizado. Además hago el send form y no me aparece en songs ni en el usuario que lo creó ( que el historial debe tener eso explicito osea además de los artistas debe decir quien lo creó) ni en los usuarios que agregué al form. pero no da error la consola solo no hace nada, ni me lleva a songs o en sí debería llevar es al calendario y cada submit del form que es una cancion o un track debería aparecer en el formulario con la fecha en que se guardó la track o se hizo submit. Además cuando en el historial quiero ver una track o submit no me aparece sino el titulo y nada de lo que se lleno en todo el form, debe aparecer completo y hasta el correo debe tener toda la información del submit lo mismo que si la reviso desde el calendario o desde songs."

### L2 — T1: form→calendario, errores visibles, icono, calendario para todos (2026-11-19)

1. **S5**: `ReleaseFormController::store` → éxito ahora hace `Inertia::flash('toast')` + **redirect a `/calendar`** (antes: `?submitted=1` que nunca se veía — `showSuccess` era un `ref` no reactivo al redirect de Inertia al mismo componente). Eliminado el success box/flag del form. Nuevo **banner de errores 422** en `ReleaseForm.vue` (`page.props.errors` → lista) — nunca falla en silencio.
2. **S1**: icono `FileMusic` en el item Music Release Form del sidebar.
3. **S2 (sidebar+controller)**: Calendar sale del bloque `isAdmin` del sidebar (ahora para todos, junto a Users solo-admin); `CalendarController` sin `abort_unless admin` + `visibleTo(user)` (user ve las suyas, admin todo).
4. Tests: `ReleaseFormSubmitTest` redirects→calendar (3), test de aterrizaje en Calendar, test success-flag eliminado; `CalendarTest`: non-admin ACCede (200) + solo-los-suyos en el calendario. Suite **127/574**, vue-tsc 0, build OK.

### L3 — T2: creador + artistas ven el track, historial con "Created by" (2026-11-19)

1. **Migración** `2026_11_19_000002_add_creator_and_scale_to_songs`: `song_submissions.created_by` (FK users nullOnDelete), `songs.artist_emails` (text `,email,` lowercase) **+ backfill chunked** desde los submissions existentes, e **índices de escala** (S4/S9): `songs(created_at)`, `songs(updated_at)`, `song_submissions(created_at)`, `song_submissions(created_by)`.
2. **`SongSubmissionService`**: firma `submit($validated, $songCode, $createdBy)` — setea `created_by` en cada versión y recalcula `songs.artist_emails` (unión con lo existente en cada append). Controllers (form + API) pasan `$request->user()?->id`.
3. **`scopeVisibleTo`** (S6): admin todo; user = `user_id = me` OR existe submit con `created_by = me` OR `artist_emails LIKE '%,mi-email,%'` — cubre owner, **creador** y **todos** los artistas agregados con cuenta (el gap real: solo el primer match era owner). `show()` ahora autoriza con el mismo scope (`firstOrFail` →404), sin lógica duplicada.
4. **Show**: cada versión muestra "**Created by**: nombre" junto a la fecha (mapeo `created_by` + eager load `createdBy`).
5. **Gotcha**: `artist_emails` tenía que entrar al `$fillable` de Song (mass assignment la descartaba → LIKE nunca matcheaba →404 en el test).
6. Tests `CreatorVisibilityTest` (5) RED→GREEN: creador sin email-match ve su song; segundo artista con cuenta la ve; desconocido →404/0; historial incluye created_by; merge de emails en append. Suite **132/613**, vue-tsc 0, build OK.
