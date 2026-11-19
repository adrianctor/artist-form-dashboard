# First Password Setup (social users)

Objetivo: un usuario que se registró por Google/otro social (nunca eligió contraseña) pueda **asignar su primera contraseña** mediante un enlace de confirmación enviado a su correo — sin pedirle "la contraseña anterior" (que no tiene) — manteniendo la seguridad: quien YA tiene contraseña solo puede cambiarla con su contraseña actual.
Branch: `feat/dashboard-mvp` · Runner: `docker compose exec -T app php artisan test`.

## Specs

### S1 — El problema (verbatim)

> "un user que ingrese por google o algun social media no puede crear su contraseña por que le pide la contraseña anterior y si nunca ha tenido contraseña es un problema, mas bien para ingresar la contraseña se pide nuevamente un correo de confirmación para asignar por primera vez la contraseña que no exija la contraseña anterior por que en este caso no tiene"

Dos bloqueos hoy para un user social:
1. `GET settings/security` tiene middleware `RequirePassword` → redirige a `/user/confirm-password` que pide la contraseña → nunca puede pasar.
2. El form "Update password" valida `current_password` → falla siempre.

Requisito: si el user **nunca eligió contraseña** → flujo de "primera asignación" por correo de confirmación, sin `current_password`.

### S2 — Seguridad: distinguir "primera asignación" de "cambio" (verbatim)

> "ojo con la seguridad de eso ya que no es lo mismo cambiarle la contraseña a un user que ya haya tenido contraseña a uno que no."

- Columna nueva `users.password_set_at` (nullable): `NULL` = nunca eligió contraseña (social signup); con valor = ya eligió alguna vez.
- Backfill: usuarios existentes → `now()` (se asume que conocen su contraseña; los que no, usan "Forgot password").
- **Nueva contraseña solo por correo firmado**: `POST settings/security/setup-password-link` (solo autenticado, `throttle:6,1`) envía un mail con `URL::temporarySignedRoute('password.setup', +60 min, ['user' => id])`.
- `GET/POST /set-password`: válida la firma (`hasValidSignature` → 403 si inválida/expirada) y **rechaza con 410 si `password_set_at` ya no es null** → un link robado/reativado NUNCA sirve para cambiar una contraseña existente; quien ya tiene contraseña solo puede cambiarla con `current_password` (flujo actual intacto).
- `password_set_at` se pone en: registro con contraseña (`CreateNewUser`), cambio desde Settings (`SecurityController::update`), y reset por correo (`PasswordReset` event listener) — así "olvidé mi contraseña" también lo deja en estado "ya tiene contraseña".
- La generación del link solo está disponible para el propio user autenticado (el mail va a su email); throttle evita spam.

### S3 — Flujo UI

- `GET settings/security` (con `RequirePassword` actual): si `password_set_at === null` → **no exige confirmación de contraseña** (nunca la tuvo) y muestra bloque "Set your password" con botón "Email me a setup link" (sin form de `current_password`). Si ya eligió contraseña → comportamiento actual (RequirePassword + form Update password).
- Middleware nuevo `ConfirmPasswordWhenChosen`: delega a `RequirePassword` solo cuando `password_set_at !== null`.
- Tras enviar el link → toast "Setup link sent to {email}".
- `GET /set-password` (público, firma válida): formulario Password + Confirmation.
- `POST /set-password` → setea contraseña + `password_set_at=now()` → si la sesión actual es ese user → redirect a Settings con toast; si no → redirect a Login con toast ("Password set. You can now sign in.").
- Registro por contraseña → `password_set_at=now()` de entrada (no ve el flujo nuevo).

## Tasks

| ID | Specs | Route | Status | Commit |
|----|-------|-------|--------|--------|
| T1 | S1, S2 | inline | done | (commit) |
| T2 | S1, S2, S3 | inline | done | (commit) |
| T3 | S2, S3 | inline | done | (commit) |

- T1: migración `password_set_at` (+ backfill `now()`), `UserFactory` con `password_set_at => now()`, `forceFill` en `CreateNewUser` y `SecurityController::update`, listener `PasswordReset` en `AppServiceProvider`. Tests base.
- T2: middleware `ConfirmPasswordWhenChosen` (reemplaza `RequirePassword` en `settings/security`) + `SecurityController::edit` expone `mustSetFirstPassword` + rutas del flujo (`password.setup.link` con throttle, `password.setup` GET/POST firmadas) + `PasswordSetupController` (sendLink/show/store con 403/410) + `SetPasswordSetupLink` mailable. Tests RED→GREEN: flujo completo, firma inválida/expirada, link ya usado (410), throttle, PasswordReset listener.
- T3: `Security.vue` bloque condicional "Set your password" + `auth/SetPassword.vue` (AuthLayout) + E2E completo (user sin contraseña → link vía Mailpit API → set → login con la nueva contraseña) + doc.

## Log

L1 — 2026-11-19, petición original del usuario (verbatim):

> "ahora un user que ingrese por google o algun social media no puede crear su contraseña por que le pide la contraseña anterior y si nunca ha tenido contraseña es un problema, mas bien para ingresar la contraseña se pide nuevamente un correo de confirmación para asignar por primera vez la contraseña que no exija la contraseña anterior por que en este caso no tiene, ojo con la seguridad de eso ya que no es lo mismo cambiarle la contraseña a un user que ya haya tenido contraseña a uno que no."

### L2 — Implementación completa T1-T3 (2026-11-19)

1. **T1 — marcador de contraseña**: migración `password_set_at` (nullable; backfill `now()` para existentes), `UserFactory` con `password_set_at => now()`, `forceFill` en `CreateNewUser` y `SecurityController::update`, listener `PasswordReset` en `AppServiceProvider` (el reset por correo también sale del flujo de primera asignación). Gotcha: el listener recibe el **evento** (`$event->user`), no el model.
2. **T2 — flujo backend**: middleware `ConfirmPasswordWhenChosen` (delega a `RequirePassword` solo si `password_set_at !== null`; reemplaza a RequirePassword en `settings/security`); `SecurityController::edit` expone `mustSetFirstPassword`; rutas `password.setup.link` (POST, throttle:6,1), `password.setup` GET + `password.setup.store` POST (públicas, firma temporal 60min); `PasswordSetupController` (sendLink/show/store con403 firma inválida,410 link ya usado) + `SetPasswordSetupLink` mailable.
3. **T3 — UI**: `Security.vue` bloque condicional (sin contraseña → botón "Email me a setup link"; con contraseña → form actual) + `auth/SetPassword.vue` (AuthLayout, action = URL con la firma). Tests `FirstPasswordSetupTest` (12) RED→GREEN: página sin RequirePassword para social, email con URL firmada, apertura anónima, firma trucada/expirada (403), **link reutilizado →410 sin tocar la contraseña existente**, seteo completo + validación fuerte, throttle (7º→429), PasswordReset/update marcan `password_set_at`.
4. **E2E real (Mailpit)**: user social → send-link → mail → GET form sin sesión (200) → POST con firma+CSRF (302 login) → **reuse →410** → **login con la contraseña nueva → dashboard200** → `password_set_at` marcado.
5. Suite **151/774** (sin deprecations), vue-tsc 0, build OK.

Gotchas: (a) el primer intento del E2E chocó con el gate de perfil (settings exige perfil completo — correcto por S5, el flujo real completa My profile primero); (b) `email_verified_at` no está en `#[Fillable]` — crear users de prueba con `forceFill`; (c) POST anónimo con firma necesita el token XSRF igual (CSRF global); (d) Mailpit API: detalle en `/api/v1/message/{id}` (singular) con campos `Text`/`HTML`.
