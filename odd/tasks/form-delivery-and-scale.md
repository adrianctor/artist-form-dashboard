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

### S10 — Sin "Version N" en la info de un submit

> "eso de version 1 quitalo porfa, ningún submit debe llevar version cuando uno abre la info de un submit"

Al abrir el detalle (Show), cada entrada del historial **no** muestra "Version N ·". La cabecera tampoco dice "versions" — el contador pasa a "N submissions" (coherente con el título "Submission history"). Las columnas de Songs/Calendar no se tocan.

### S11 — Editar/borrar un submit: solo el que lo envió

> "no está permitiendo editar ni eliminar un submit, solo lo puede editar el que lo creó no los artistas a los que les aparece el submit, ojo que que lo crea puede estar dentro de los mismos artistas entonces esa sería la unica excepción para editar y eliminar dentro de los artistas, generalmente quien lo crea es parte de la lista de artistas del submit entonces tienes que permitir editar y borrar solo al que envio el submit."

- Nuevas rutas: `GET /submissions/{id}/edit`, `PATCH /submissions/{id}`, `DELETE /submissions/{id}` (mismo grupo auth/verified/profile.complete que Songs).
- **Autorización estricta**: `created_by === user.id` — los artistas que pueden VER el submit (por email en `artist_emails`) **no** lo pueden editar/borrar; solo el que lo envió (que normalmente es uno de los artistas). Si no puede ver la canción → 404; si puede ver pero no creó → 403. (Sin bypass de admin — "solo al que envió el submit".)
- **Edit**: reutiliza `ReleaseForm.vue` con prop `editSubmission` (prefill de todo el payload: publishing, release info, cover, artist blocks con links; sin disclaimer; botón "Save changes"; envía PATCH). Server: misma validación que store (`validatedRelease` extraído), actualiza `payload` + `track_title` de la canción y **recalcula `artist_emails`** (unión de todos los submissions) → redirect a Show.
- **Delete**: borra el submission; si era el último de la canción → borra la canción (redirect a Songs); si no → recalcula `submission_count` + `artist_emails` (redirect a Show). Confirmación en el navegador (`window.confirm`).
- Botones Edit/Delete en cada entrada del historial, solo visibles si `editable` (lo calcula el servidor).

### S12 — Correo con el estilo de la página

> "además los correos se ven horrible, si tienen html y estilos como se muestran en la pagina? si no es asi aplicale el estilo como se ve en la pagina."

El mailable actual es texto plano con mínimos estilos. Rediseño con **estilo de la página** (todo inline, tables para compatibilidad de email): fondo `#000`, tarjeta `#0a0a0a` con borde sutil y radio 20px, título uppercase con letter-spacing, labels en gris `#a1a1aa` (uppercase,11px), valores en blanco, fecha de envío, artistas en tarjetas internas con borde, footer con nota de paridad con el historial. Envelope (to/cc/replyTo) sin cambios. Los tests existentes (valores presentes) se mantienen.

### S13 — Links de artistas como UI, nunca JSON

> "por ultimo para la pagina como para el correo los links de cada artista no los pintes en json mejora su ui ux de acuerdo al estilo de la página"

- **Show**: el servidor transforma `payload.artists` en estructura (`{name, fields:[{label,value}], links:[{label,url}]}`) — el `links` sale del string JSON y se pinta como lista: label de plataforma (Spotify, Apple Music, ...) + ancla clickeable estilo página (`text-primary underline`, `word-break`), nunca `JSON.stringify`. Testeable a nivel de props.
- **Correo**: cada link como fila "Plataforma — ancla clickeable" (color de acento de la página, underline, word-break); los valores URL del release (audio_link, photo, ...) también se vuelven anclas. Test: el mail contiene `href="..."` + label y **no** contiene JSON (`{"spotify"`).

## Tasks

| ID | Specs | Route | Status | Commit |
|----|-------|-------|--------|--------|
| T1 | S5, S2, S1 | inline | done | (commit) |
| T2 | S6 | inline | done | (commit) |
| T3 | S3, S4, S2 | inline | done | (commit) |
| T4 | S7 | inline | done | (commit) |
| T5 | S8, S9 | inline | done | (ver L6) |
| T6 | S10, S13 | inline | done | 67c6a39 |
| T7 | S11 | inline | done | 46b616a |
| T8 | S12, S13 | inline | done | 8678955 |
| T9 | verificación final | inline | done | (commit) |

- T1: form UX — redirect a `/calendar` + `Inertia::flash` + errores 422 visibles + quitar success box; icono FileMusic en sidebar; Calendar visible para todos en sidebar (S2 sidebar); tests actualizados (`ReleaseFormSubmitTest`, `DashboardTest` si aplica).
- T2: migración (`song_submissions.created_by`, `songs.artist_emails` + backfill + índices de S4/S9) + `SongSubmissionService` (createdBy + artist_emails) + `scopeVisibleTo` ampliado + `show()` vía scope + "Created by" en Show.vue + tests nuevos (creador ve, segundo artista ve, desconocido no, Show incluye created_by).
- T3: calendario — controller con `month/day/page` + aggregate por día + `paginate(50)` del día + `visibleTo` + `Calendar.vue` server-driven (mes/día/paginación) + copy "form submits" + `CalendarTest` reescrito (todos acceden, mes acotado, día paginado, solo-suyas para user).
- T4: Show completo + correo genérico — test de paridad Show (payload completo → todos los campos) + `ReleaseFormSubmitted` render genérico + test "todas las claves del payload en el mail".
- T5: verificación final — Mailpit E2E (envío → API → UI 200) + suite/vue-tsc/build (cerrado en L6).
- T6: Show sin "Version N" + artistas/links server-side (`SongPageController::show` mapea artists a `{name, fields, links}`) + Show.vue pinta links como anclas estilo página + `editable` por submission + tests.
- T7: rutas edit/update/destroy + `ReleaseFormController` (`validatedRelease` extraído de store, `findEditableSubmission` con vista→404 y creador→403) + `SongSubmissionService::recomputeArtistEmails` + botones Edit/Delete en Show (confirm) + `ReleaseForm.vue` con prop `editSubmission` (prefill, sin disclaimer, "Save changes", PATCH) + `SubmissionEditDeleteTest` (~10 tests).
- T8: `ReleaseFormSubmitted` rediseñado al estilo de la página (dark, cards, labels uppercase gris, anclas de accent) + tests de estilo y de links-ancla (no JSON).
- T9: verificación final (suite, vue-tsc, build, E2E edit/delete por curl) + cierre del doc.

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

### L4 — T3: calendario a escala (mes agregado + día paginado) (2026-11-19)

1. **`CalendarController` reescrito** (S3/S4): `?month=YYYY-MM` (default actual, validado con fallback) → **aggregate SQL** `COUNT(*) GROUP BY DATE(created_at)` solo de ese rango (`whereIn song_id` con `visibleTo` como subquery) → `dayCounts` ≤31 filas. `?day=YYYY-MM-DD` (solo si pertenece al mes) → submissions de ese día con join a songs → **`paginate(50)`** → `dayPage` (data/current_page/last_page/links). Props nuevas: `month`, `dayCounts`, `day`, `dayPage`. Gotchas: calificar `song_submissions.created_at` en el whereBetween (el join con songs lo hacía ambiguo →500); día fuera del mes → `day=null`.
2. **`Calendar.vue` server-driven**: estado derivado de props (viewYear/Month desde `month`, selección desde `day`); navegación de mes/día/página = `router.get` con los params; detalle lista `dayPage.data` con `:key` = submission id (puede repetir canción el mismo día); paginación del día con botones Previous/Next; copy "Every form submit, grouped by the date it was made". Se eliminó la carga masiva `songsByDate`.
3. **Tests `CalendarTest` reescrito** (9): guest, non-admin accede, solo-los-suyos por `dayCounts`, admin ve todo, month-scoping (submit viejo solo en su mes, `missing()` en el actual), month inválido → fallback, day bajo demanda, **day paginado** (60 submits →50+11, last_page=2), day fuera del mes → null. Suite **137/700**, vue-tsc 0, build OK.

### L5 — T4: correo e historial con toda la información (2026-11-19)

1. **`ReleaseFormSubmitted::renderBody` → render genérico** (S7): recorre TODAS las claves no-vacias del payload (label `ucwords` por key) + cada artista con TODOS sus campos (incl. `links` platform→url) — paridad con Show garantizada por construcción (si el form gana un campo, el correo lo lleva sin tocar código). Legacy `artists_info` (string) se renderiza como bloque `<pre>`. `publishing` sigue mapeado a "Afrodise"/"I don't have this".
2. **Tests**: `email_contains_every_non_empty_payload_field` (valores PARITY en writers/country/genre + campos de artista presentes) + `show_delivers_the_full_submission_payload` (payload completo con links en los props de Show — el song lleva `artist_emails` para que el artista acceda, S6). Suite **139/714** (tras corregir: label "Bio" y artist_emails en el test de paridad).


L6 — 2026-11-19, cierre de T5 + nuevos requisitos del usuario (verbatim):

> "eso de version 1 quitalo porfa, ningún submit debe llevar version cuando uno abre la info de un submit, además no está permitiendo editar ni eliminar un submit, solo lo puede editar el que lo creó no los artistas a los que les aparece el submit, ojo que que lo crea puede estar dentro de los mismos artistas entonces esa sería la unica excepción para editar y eliminar dentro de los artistas, generalmente quien lo crea es parte de la lista de artistas del submit entonces tienes que permitir editar y borrar solo al que envio el submit. Además los correos se ven horrible, si tienen html y estilos como se muestran en la pagina? si no es asi aplicale el estilo como se ve en la pagina. y por ultimo para la pagina como para el correo los links de cada artista no los pintes en json mejora su ui ux de acuerdo al estilo de toda la pagina."

T5 quedó verificado en el turno anterior (Mailpit: UI 200 + API con mensajes; E2E de envío del form → calendario con el track del día).

### L7 — T6: Show sin "Version N" + artistas/links server-side (2026-11-19)

1. **S10**: `SongPageController::show` ya no emite `version`; Show.vue sin "Version N ·" por entrada y la cabecera dice "**N submissions**" (antes "N versions"). Test: `missing('submissions.0.version')`.
2. **S13 (página)**: nuevo `artistsForDisplay(payload)` en el controller — `payload.artists` → `[{name, fields:[{label,value}], links:[{label,url}]}]`; `links` (antes JSON stringified en el cliente) sale como pares, plataformas vacías descartadas, `name` es el encabezado de la tarjeta (no se duplica como fila). Show.vue pinta cada link como **ancla** (`text-primary underline break-all`) con label de plataforma — nunca JSON.
3. **S11 (data)**: cada submission lleva `editable = (created_by !== null && created_by === user.id)` — los botones vienen en T7.
4. Gotcha de test: `AssertableInertia::where` pasa una **Collection** al closure (no array) — usar `pluck()/contains()`.
5. Tests: `SongsPageTest` 6/6 (paridad de payload + shape de links: name único, sin fila "Links", Spotify pair exacto). Suite **152/789**, vue-tsc 0, build OK.

### L8 — T7: editar/borrar submit — solo el que lo envió (2026-11-19)

1. **Rutas** (grupo auth/verified/profile.complete de Songs): `GET /submissions/{id}/edit`, `PATCH /submissions/{id}`, `DELETE /submissions/{id}`.
2. **Autorización estricta** (`findEditableSubmission`): primero el scope `visibleTo` (desconocido → **404**, no revela existencia), luego `created_by === user.id` (artista que solo ve → **403**). **Sin bypass de admin** — "editar y borrar solo al que envió el submit" (spec S11).
3. **`validatedRelease()`** extraído de `store()` (misma validación + regla condicional de links + normalización de cover) → lo comparten store y update.
4. **update**: actualiza `payload` + `track_title` de la canción (nunca crea versiones) + `recomputeArtistEmails` (unión de TODOS los submits de la canción) → redirect a Show + toast.
5. **destroy**: borra el submission; si era el último → borra la canción (redirect a Songs); si no → recalcula `submission_count` + `artist_emails` → redirect a Show. Confirm con `window.confirm`.
6. **`SongSubmissionService::recomputeArtistEmails`** nuevo (usado por update y destroy).
7. **UI**: botones **Edit** (outline) y **Delete** (destructive) por entrada del historial, solo si `editable` (server-side); `@click.prevent.stop` para no togglear el `<details>`.
8. **`ReleaseForm.vue` modo edición**: prop `editSubmission` → prefill completo (publishing, release info, cover, artistCount, artist blocks con links), **sin disclaimer**, botón "Save changes"/"Saving...", envía `router.patch(/submissions/{id})`. `artistCount` tipado a `string` (submits de API pueden tener >4 artistas).
9. Tests `SubmissionEditDeleteTest` (11) RED→GREEN. E2E curl: edit 200 → PATCH renombra payload+canción → DELETE última → show 404. Suite **163/834**, vue-tsc 0, build OK.

### L9 — T8: correo al estilo de la página + links como anclas (2026-11-19)

1. **`ReleaseFormSubmitted` rediseñado** (S12): todo inline con tables (compat email) — fondo `#000000`, tarjeta `#0a0a0a` borde sutil radio20, título uppercase letter-spacing3px, "New submission · fecha", labels de campo uppercase11px `#a1a1aa`, valores blancos14px, footer de paridad con el historial. Envelope sin cambios.
2. **S13 en el correo**: links de plataforma como filas "Label — ancla" (`#5b7fff` underline, word-break) y cualquier valor-URL del release (audio_link, photo...) también como ancla — nunca JSON. Artistas en tarjetas con heading = nombre real (paridad con Show).
3. **Tests**: `email_is_styled_like_the_page` (#000000/#0a0a0a/uppercase/#a1a1aa) + `email_renders_artist_links_as_anchors_never_json` (href + "Spotify" + sin `{"spotify"`). Test de paridad actualizado: el heading es el nombre real ("Aaron Sevilla"/"Badrops"), no "Artist N". Suite completa verificada en T9.

### L10 — T9: verificación final + cierre (2026-11-19)

**E2E del correo real (Mailpit)**: POST /release-form → redirect `/calendar` ✓; el mensaje llega con **todos los markers verificados**: `background-color:#000000` (fondo), `background-color:#0a0a0a` (tarjeta), `text-transform:uppercase`, `color:#a1a1aa` (labels), ancla `href="https://open.spotify.com/artist/abc"` del link del artista, y **sin JSON** (`{"spotify"` ausente).

**Estado final de la feature**: **165 tests / 840 assertions**, `vue-tsc` 0, `npm build` OK. Todos los tasks T1-T9 completados:
- S10: sin "Version N" en la info de un submit (badge = "N submissions").
- S11: editar/borrar solo el creador (`created_by`),404/403 según visibilidad, sin bypass de admin; edit reutiliza ReleaseForm; la canción muere con su último submit.
- S12: correo con el estilo de la página (fondo #000, tarjeta #0a0a0a, labels uppercase #a1a1aa).
- S13: links de artistas como anclas en Show Y en el correo — nunca JSON.

**Nota**: `AssertableInertia::where` entrega una `Collection` a los closures (no array) — usar `pluck()/contains()` en tests.
