# Profile labels, writers list & per-artist publishing

Objetivo: profile con "First name and last name", Rol (select 3), publishing + IPI; form con lista de n escritores (select de profiles + Rol del escritor), publishing por artista y bio ≤500; email y vistas de edición/visualización actualizados.
Rama: `feat/profile-writers-publishing` (desde `develop`). Estrategia de delivery: ask-on-risk.
Test runner: `php artisan test` (+ `npm run build` para type-check del front).

## Specs

### S1 — Label "First name and last name"
"Real name cambia por First name and last name en profile"
- En `MyProfile.vue` el label del campo `real_name` pasa de "Real Name *" a "First name and last name *".
- Aplica también al label homónimo del bloque de artistas del form y a los labels de display (email, historial de la canción) donde el campo aparezca ("modificando tambien el correo y los campos para ver editar del form o del profile según lo que aplique").
- La columna DB `real_name` NO se renombra.

### S2 — Rol del artista (select de 3)
"en profile Role por Rol son 3 Primary, Featuring, Remix" + decisión final: "Select de 3 en ambos".
- Label "Role" → "Rol" en `MyProfile.vue`; el input de texto pasa a `<select>` con opciones exactas: `Primary`, `Featuring`, `Remix`.
- Mismo select en el bloque de artistas del form ("Select de 3 en ambos").
- Validación server-side `Rule::in(['Primary', 'Featuring', 'Remix'])` en `ArtistProfileController::update` y en `ReleaseFormController` (`artists.*.role`).
- Un profile guardado con un valor viejo (ej: "Producer") queda visible en el gate (no vacío), pero al editarlo obliga a elegir uno de los 3; en el form, un valor legacy no presente en el select queda vacío y obliga a elegir.
- NOTA: este rol del ARTISTA es distinto del "Rol del escritor" (S3): "rol del artista es diferente al rol de los escritores".

### S3 — Lista de escritores en el form (n filas)
"En el form, Pueden ser n escritores, debe ser un select con el real name de cada profile. además incluir campo que se llame Rol del escritor y que se puedan agregar n escritores con su respectivo rol."
- El textarea `Writers *` del step 0 se reemplaza por una lista dinámica de filas (agregar/eliminar), mínimo 1 fila:
  - select con el `real_name` de cada profile (fuente: nuevo endpoint que lista todos los profiles);
  - campo **"Rol del escritor"**: select con `Primary`, `Featuring`, `Remix` (decisión: "Select: Primary, Featuring, Remix");
  - botón de eliminar por fila y botón "+ Add writer" para agregar n.
- Sin toggle "¿IA?" (decisión: "No incluirlo").
- Payload: `writers` pasa de string a array de objetos `{ name, role }`.
- Validación: `writers` required array min:1; `writers.*.name` required string max:255; `writers.*.role` required en los 3 valores.

### S4 — Bio máximo 500
"biograph es de 500 caracteres maximo"
- `bio` en profile: `max:500` (hoy `max:5000`) + `maxlength="500"` en el textarea.
- `artists.*.bio` en el form: `max:500` + `maxlength="500"`.

### S5 — Publishing por artista en el form
"[Image 2] en el form el publishing va por artista"
- Se elimina el radio global "Publishing *" (Afrodise / I don't have this) del step 2.
- Cada bloque de artista lleva:
  - select `publishing` con valores exactos: `AFRODISE`, `COPYRIGHT CONTROL`, `OTHER` (label de la opción: "OTHER / CUSTOM PUBLISHING"), required;
  - si `OTHER` → input "Write the publishing company name" (required cuando OTHER);
  - input "IPI Number" (opcional, placeholder "Enter IPI Number (if available)").
- Al seleccionar un artista registrado (búsqueda), publishing/IPI se autocompletan desde su profile y quedan editables: "en el perfil se llena esa info, y en el form se carga automaticamente al seleccionar el artista, igualmente se puede permitir editar así como cualquier otro campo".
- Validación: `artists.*.publishing` required en los 3; `artists.*.publishing_custom` required cuando OTHER (loop manual, igual que la regla condicional de `links`); `artists.*.ipi_number` nullable.
- Payload legacy: el top-level `publishing` ya no se envía ni se valida en el form (los payloads viejos en DB siguen mostrándose en email/historial).

### S6 — Publishing en el profile (+ gate)
"el profile tambien lleva el publishing con estas 3 opciones: <select ...>AFRODISE / COPYRIGHT CONTROL / OTHER / CUSTOM PUBLISHING</select> si es other debe activarse otro campo [Image 3]" + decisión de gate: "Obligatorio y cuenta para el gate".
- Select `publishing` required con valores `AFRODISE`, `COPYRIGHT CONTROL`, `OTHER` (label de opción "OTHER / CUSTOM PUBLISHING").
- Si `OTHER` → input "Write the publishing company name" (required).
- Input "IPI Number" opcional ("Enter IPI Number (if available)").
- Migración: columnas `publishing` (nullable), `publishing_custom` (nullable), `ipi_number` (nullable) en `artist_profiles`.
- `ArtistProfile::isComplete()` exige `publishing` no vacío (y `publishing_custom` cuando `OTHER`) → los usuarios existentes deberán completarlo la próxima vez que entren (gate los redirige a My profile; admin exento, como hoy).

### S7 — Email, historial y edición consistentes
"Todo debes entregarlo testeado modificando tambien el correo y los campos para ver editar del form o del profile según lo que aplique."
- Email `ReleaseFormSubmitted`:
  - `writers` array se renderiza como filas "name — role" (no JSON);
  - labels override: `real_name` → "First name and last name", `ipi_number` → "IPI Number", `publishing_custom` → "Publishing company";
  - `publishing` acepta los valores nuevos (`AFRODISE`/`COPYRIGHT CONTROL`/`OTHER`) y los legacy (`afrodise`/`none`);
  - publishing/IPI/custom por artista se muestran dentro de la card del artista (ya itera todos los campos).
- Historial (`songs/Show.vue` + `SongPageController::artistsForDisplay`): `writers` array se muestra como filas "name — role"; mismos labels override para `real_name`, `ipi_number`, `publishing_custom`.
- Edición del form (`prefillFromPayload`): filas de writers desde array; si el payload es legacy (writers string, sin publishing por artista) se prefilla lo que se pueda (legacy `publishing: afrodise` → `AFRODISE` por artista; `none` → vacío) y lo faltante queda required.

## Tasks

- T1 — Migración + model + gate: columnas publishing/publishing_custom/ipi_number, `isComplete()`, factory/seeders con valores válidos (rol `Primary`, publishing `AFRODISE`). Ruta: inline. Commit: pendiente.
- T2 — Profile UI + `ArtistProfileController`: labels S1, select Rol S2, bloque publishing S6, bio 500 S4 + tests (`MyProfileTest`, `ArtistProfileTest`). Ruta: inline. Commit: pendiente.
- T3 — Form: endpoint de listado de profiles, lista de escritores S3, publishing por artista S5, select Rol en bloque de artista S2, bio 500 S4 + validación server + tests (`ReleaseFormSubmitTest`, `ReleaseFormPageTest`). Ruta: inline. Commit: pendiente.
- T4 — Email + historial + prefill de edición S7 + tests (`ReleaseFormEmailTest`, `SongsPageTest`, `SubmissionEditDeleteTest`). Ruta: inline. Commit: pendiente.
- T5 — Suite completa, `npm run build`, pint/phpstan. Ruta: inline. Commit: pendiente.

Rutas declaradas inline (no writer trigger): las unidades son dependientes entre sí (comparten la forma del payload `writers`/`publishing`, la factory y los fixtures de tests) — no son independientes para correr en paralelo.

## Log

L1 (user, 2026-11-25): "necesito hacer estos cambios:
Real name cambia por First name and last name en profile
en profile Role por Rol son 3 Primary, Featuring, Remix
En el form, Pueden ser n escritores, debe ser un select con el real name de cada profile. además incluir campo que se llame Rol del escritor y que se puedan agregar n escritores con su respectivo rol.
[Image 1]
biograph es de 500 caracteres maximo
[Image 2] en el form el publishing va por artista
el profile tambien lleva el publishing con estas 3 opciones:
<select name="artist_publishing_1" class="artist-publishing-select" data-artist="1" required="">
    <option value="AFRODISE" selected="">AFRODISE</option>
    <option value="COPYRIGHT CONTROL">COPYRIGHT CONTROL</option>
    <option value="OTHER">OTHER / CUSTOM PUBLISHING</option>
</select>
si es other debe activarse otro campo [Image 3]
Todo debes entregarlo testeado modificando tambien el correo y los campos para ver editar del form o del profile según lo que aplique."

L2 (user, decisiones): IPI "en el perfil se llena esa info, y en el form se carga automaticamente al seleccionar el artista, igualmente se puede permitir editar así como cualquier otro campo"; Rol del escritor = "Select: Primary, Featuring, Remix"; gate = "Obligatorio y cuenta para el gate"; toggle ¿IA? = "No incluirlo"; "no, rol del artista es diferente al rol de los escritores, el de los escritores tiene solo 3 posibles valores, el de los artistas en el profile son diferentes valores" → aclarado luego: "Select de 3 en ambos" para el rol del artista.

L3 (evidencia): `resources/js/pages/MyProfile.vue:84-104` (labels Real Name/Role), `ReleaseForm.vue:595-598` (textarea writers), `ReleaseForm.vue:680-703` (radio publishing global), `ReleaseFormController.php:165-197` (validación), `ArtistProfileController.php:38-61` (validación profile, bio max:5000), `ArtistProfile.php:64-90` (isComplete), `ReleaseFormSubmitted.php` (email), `SongPageController.php:115-175` (display historial).
