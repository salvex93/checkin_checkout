# ROADMAP - clockin-clockout

Plan de ejecucion de pendientes detectados al 2026-05-28. Fuente: BACKLOG.md (tareas con estatus Pendiente) + cambios en working tree sin commitear + brechas observadas entre commits recientes y backlog.

Orden recomendado por dependencias tecnicas y criticidad. Cada fase es independiente y commiteable por si sola.

---

## Fase 0 — Cerrar trabajo en vuelo (1-2h)

Bloquea el resto del roadmap. Hay cambios sin commitear y verificaciones manuales pendientes.

| Item | Origen | Accion |
|---|---|---|
| Verificar visualmente el refactor de 12 modales SPA | sesion 2026-05-27 | Levantar dev server, probar en iPhone SE / iPad / Desktop, modo claro/oscuro, modales prioritarios (Vacaciones, Confirmar invitacion, Revisar CSV, Branding, Marca/Empresa). Si OK -> commit |
| Validar flujo T&C en navegador | sesion 2026-05-25 | Login con `qa.repro@melius.test` / `Test1234!`, aceptar T&C, confirmar transicion a dashboard sin warning React, edge case admin publica nueva version entre login y aceptacion |
| Actualizar BACKLOG con tareas 49-50 retroactivas | commits a4aed9a, 892fd40, 8428779, e7b9901 | Registrar: modulo vacaciones, cifrado AES-256-GCM PII + sanitizacion audit_log, anti-bot defensivo + recordatorios cron, hardening anti-scraper + fixes UX, precompilacion JSX + CSP sin unsafe-inline + fail2ban |

Salida: working tree limpio, BACKLOG sincronizado, base estable para fases siguientes.

---

## Fase 1 — Cerrar sprint "auth + mailer" (BACKLOG #25, #27, #11) (6-8h)

Bloquea cualquier flujo de invitacion/recuperacion en produccion estable. PHPMailer ya esta en lib/ y mailer.php ya existe — falta validacion DNS y endpoints de password.

### 1.1 — Tarea #25 cierre (1.5h)

- Rotar `SMTP_PASS` en `.env` (compartida en chat el 2026-05-13).
- Validar SPF/DKIM/DMARC para `fullman.tech` en DNS (Titan Mail/HostGator).
- Ejecutar smoke real `scripts/send_invitation.php` contra `andrew.arizmendi@meliusservices.com`.
- Marcar #25 como Terminado en BACKLOG.

### 1.2 — Tarea #27 (3h)

- `POST auth/change-password` autenticado: current + new + confirm. Valida fuerza min 10 + mayusc + numero + simbolo. Limpia `must_change_password=0`, setea `password_changed_at`, audit log.
- `POST auth/forgot-password` publico: email -> token 64 hex, hash en `password_reset_tokens`, expira 72h, respuesta neutra anti-enumeracion, email con link `/reset-password?token=...`.
- `POST auth/reset-password`: consume token, valida no expirado/no consumido, exige password nueva con misma regla de fuerza, marca `consumed_at + ip_address`, audit log.
- `auth_login` y `auth_me` devuelven `must_change_password` (ya parcial — verificar).

### 1.3 — Tarea #11 (sub-tarea de #27, 0.5h)

- Cubierta por 1.2 (es la misma tabla `password_reset_tokens` y endpoints `/auth/forgot|reset`).

### 1.4 — Tarea #31 — Frontend de password (3h)

- Rutas cliente `/forgot-password`, `/reset-password?token=...`, `/change-password`.
- Banner persistente bloqueante si `me.must_change_password=1` — bloquea navegacion hasta cambiar.
- Indicador visual de fuerza de password.
- Logout forzado al final del flujo de reset.

Criterio de exito: un usuario invitado puede olvidar su password, recibir email, resetear, loguear, y el sistema lo obliga a cambiarla en primer ingreso.

---

## Fase 2 — Dashboards admin + export RH (BACKLOG #28, #29) (6h)

Habilita el caso de uso real para RH. Sin estos endpoints, el cliente no obtiene valor de la herramienta.

### 2.1 — Tarea #28 (4h)

- `GET admin/dashboard/global` (super_admin): totales hoy/semana/mes, agentes activos por empresa, retrasos del periodo, ausencias (dias laborables sin record), horas extra pending/approved.
- `GET admin/dashboard/company/:id`: mismas metricas filtradas por empresa. Admin solo ve sus empresas asignadas.
- `GET admin/agents/search?q=&company_id=&status=&offset=&limit=`: busqueda paginada por nombre/email.
- super_admin oculto en respuestas para admins normales (mismo patron de #36).

### 2.2 — Tarea #29 (2h)

- `GET admin/records/export?period=week|month|year&company_id=&user_id=&from=&to=` con streaming row-by-row.
- Headers `Content-Type: text/csv; charset=utf-8` + `Content-Disposition: attachment`.
- UTF-8 con BOM (Excel-friendly).
- Columnas: fecha, agente, empresa, clockin, clockout, horas_trabajadas, status, horas_extra, overtime_status.
- Audit log por export con filtros usados.

Criterio de exito: RH descarga CSV mensual del periodo seleccionado y abre en Excel con acentos correctos.

---

## Fase 3 — Frontend admin completo (BACKLOG #30) (8h)

Reemplaza el AdminPanel actual con la version definitiva. Depende de Fase 2 (endpoints) y Fase 0 (modales).

- Tabs definitivas: Dashboard (KPIs + selector empresa), Empresas, Agentes (con search + filtro empresa + acciones), Invitaciones pendientes, Registros (con boton exportar CSV), Solicitudes, Admins (visible solo super_admin), Marcas (super_admin).
- Reutilizar Modal con `showHeader`, Toasts, LoadingScreen, EmptyState ya existentes.
- Ocultar fila super_admin en todos los listados visibles para admin normal.

Criterio de exito: super_admin puede operar 100% de la plataforma sin tocar la DB ni CLI.

---

## Fase 4 — Seguridad endurecida (BACKLOG #8, #9, #10) (10-12h)

### 4.1 — Tarea #8 (3h) — PARCIALMENTE HECHO

- Commit a4aed9a ya precompilo JSX a `/assets/app.js` y endurecio CSP sin `unsafe-inline`. **Verificar que `unsafe-inline` esta efectivamente removido de la CSP de produccion** y que no haya inline handlers residuales en `index.html`.
- Si OK, marcar #8 como Terminado retroactivo en BACKLOG.

### 4.2 — Tarea #9 (6h)

- TOTP (Google Authenticator / 1Password) para cuentas con `role IN ('admin','super_admin')`.
- Tabla `totp_secrets(user_id FK, secret_encrypted, recovery_codes_hash JSON, enabled_at, last_used_at)`.
- Endpoints `/auth/totp/setup`, `/auth/totp/verify`, `/auth/totp/disable`.
- Modal de setup con QR code (libreria endomondopaul/totp o bacon/bacon-qr-code via Composer).
- Storage del secret cifrado con la misma capa AES-256-GCM ya implementada en commit 892fd40.

### 4.3 — Tarea #10 (3h)

- Rate limit por IP en `/api/auth/*` (token bucket en tabla `rate_limits` con limpieza periodica).
- Limite recomendado: 10 intentos por IP / 15 min en `/auth/login` y `/auth/forgot-password`.
- Mismo handler 429 con `Retry-After` header.

Criterio de exito: una IP bloqueada por flooding no puede seguir intentando login; admin con TOTP no puede entrar sin segundo factor.

---

## Fase 5 — QA + tests (BACKLOG #15, #12) (8h)

### 5.1 — Tarea #15 (6h)

- Tests unitarios PHP con PHPUnit para `records.php` (regla olvido/extra, autocierre, TZ hibrida).
- Tests de smoke E2E con cookie jar para flujos criticos: invitacion -> aceptacion -> login -> clockin -> clockout -> overtime -> approve.
- Test de accesibilidad con axe-core en el front (al menos LoginCard, UserDashboard, AcceptTermsCard).
- Tests del modulo vacaciones (commit 8428779) que se agrego sin tests visibles.

### 5.2 — Tarea #12 (2h)

- Email a `SUPPORT_EMAIL` cuando se crea una solicitud (cambio empresa u horas extra u edicion).
- Plantilla HTML minima reutilizando `mailer.php#mail_template_*`.

---

## Fase 6 — Despliegue produccion estable (BACKLOG #13, #14) (1.5h)

Ya hay despliegue parcial en GoDaddy (commits 47b68c2, 2fed0b7, a4aed9a fail2ban). Falta formalizar y dejar runbook.

- **Tarea #13 (1h):** Verificar layout final en `public_html/app.meliusservices.com`, `.env` fuera de public_html, schema MySQL completo, AutoSSL, smoke `/api/csrf` 200.
- **Tarea #14 (0.5h):** Cloudflare Tunnel o ngrok para QA contra dispositivos reales antes de cada release significativo.

---

## Backlog diferido (no urgente)

Estas tareas quedan para despues del MVP estable:

- Tareas #19, #20, #21, #26, #32, #33 — Refactor mayor multi-empresa. **Estan funcionalmente cubiertas por commits posteriores (#34-#48) aunque no marcadas Terminado en BACKLOG.** Auditar y cerrar retroactivo.
- 2FA via WebAuthn (mejora futura del TOTP).
- Reply-To por empresa en emails (mejora de #25).

---

## Resumen ejecutivo

| Fase | Esfuerzo | Bloqueante de | Prioridad |
|---|---|---|---|
| 0 — Cerrar en vuelo | 1-2h | Todo | Critica |
| 1 — Auth + mailer | 6-8h | Fase 2 (dashboards usan agents reales) | Alta |
| 2 — Dashboards + CSV | 6h | Fase 3 (UI consume estos endpoints) | Alta |
| 3 — Frontend admin | 8h | Cierre de MVP | Alta |
| 4 — Seguridad endurecida | 10-12h | Produccion segura | Alta |
| 5 — QA + tests | 8h | Releases sin regresion | Media |
| 6 — Despliegue formal | 1.5h | Adopcion cliente | Media |

**Esfuerzo total estimado: 40-46h netas de implementacion.**

Recomendacion: arrancar Fase 0 hoy mismo (cierra deuda inmediata), Fase 1 el resto de esta semana, Fase 2-3 la proxima.
