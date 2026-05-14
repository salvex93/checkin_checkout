# Clockin/Clockout - Melius Services

Sistema de control de jornada laboral multi-empresa para consultores. Soporta clockin/clockout con horario efectivo por empresa, declaracion de horas extra sujetas a aprobacion, gestion administrativa de empresas y agentes, dashboards de KPIs e invitaciones por email con password temporal.

Stack: PHP 8.0+ (backend), SQLite (local) / MySQL (HostGator), React via CDN + Tailwind (frontend), PHPMailer vendoreado (sin Composer).

---

## Indice

1. [Estructura del proyecto](#estructura-del-proyecto)
2. [Requisitos](#requisitos)
3. [Levantar de cero en Windows](#levantar-de-cero-en-windows)
4. [Levantar de cero en macOS / Linux](#levantar-de-cero-en-macos--linux)
5. [Variables de entorno](#variables-de-entorno)
6. [Roles y primer ingreso](#roles-y-primer-ingreso)
7. [Migraciones disponibles](#migraciones-disponibles)
8. [Comandos rapidos](#comandos-rapidos)
9. [Exponer localhost a internet](#exponer-localhost-a-internet)
10. [Despliegue en HostGator](#despliegue-en-hostgator)
11. [Seguridad implementada](#seguridad-implementada)
12. [Troubleshooting](#troubleshooting)

---

## Estructura del proyecto

```
clockin-clockout/
├── public/                         Raiz publica (lo que se sube a HostGator)
│   ├── index.html                  Frontend React + Tailwind (CDN, JSX in-browser)
│   ├── .htaccess                   HTTPS forzado, ruteo /api/*, bloqueo de .env/.git
│   └── api/                        Backend PHP
│       ├── index.php               Router unico de endpoints
│       ├── config.php              Carga de .env y constantes
│       ├── db.php                  PDO (SQLite/MySQL) + auto-bootstrap de schema
│       ├── security_headers.php    HSTS, CSP, X-Frame-Options, Referrer, Permissions
│       ├── csrf.php                Token CSRF double-submit
│       ├── helpers.php             Sesion, validacion, audit, rate limit, requires
│       ├── auth.php                login, logout, me, change/forgot/reset password
│       ├── records.php             clockin, clockout, overtime, cambio empresa
│       ├── admin.php               CRUD empresas, gestion agentes, invitar
│       ├── dashboard.php           KPIs globales/por empresa, buscador, export CSV
│       ├── mailer.php              Envio SMTP via PHPMailer (vendoreado)
│       └── lib/PHPMailer/          PHPMailer 6.x (PHPMailer.php, SMTP.php, Exception.php)
├── sql/
│   ├── schema.sqlite.sql           Schema para desarrollo local
│   └── schema.mysql.sql            Schema para HostGator
├── scripts/                        CLI (no se exponen via HTTP)
│   ├── router.php                  Router de PHP built-in server para /api/*
│   ├── create_admin.php            Crear/promover admin
│   ├── create_super_admin.php      Crear super_admin con password temporal
│   ├── migrate_super_admin.php     Migracion idempotente rol super_admin (SQLite)
│   └── migrate_overtime_edit.php   Migracion idempotente ediciones de horas extra
├── storage/                        DB SQLite local (gitignored, se crea sola)
├── .env.example                    Plantilla de variables de entorno
├── .gitignore
├── BACKLOG.md                      Tabla de tareas (formato fijo de 12 columnas)
├── DEPLOY_HOSTGATOR.md             Guia detallada de produccion
└── README.md                       Este archivo
```

---

## Requisitos

| Componente   | Version minima | Notas |
|---|---|---|
| PHP          | 8.0            | Probado en 8.0.30 (XAMPP). Extensiones requeridas: `pdo_sqlite`, `pdo_mysql`, `mbstring`, `openssl`, `json` |
| SQLite       | 3.x            | Embebido en PHP. No requiere binario externo en local |
| MySQL        | 5.7 / 8.0      | Solo para produccion HostGator |
| Navegador    | Moderno (2022+)| Front usa React 18 via CDN + JSX in-browser (Babel standalone) |
| Cuenta SMTP  | Opcional       | Para emails de invitacion / reset. Sin SMTP, los flujos relevantes loguean error pero no rompen |

No requiere Node.js, Composer, ni build pipeline. PHPMailer va vendoreado en `public/api/lib/PHPMailer/`.

---

## Levantar de cero en Windows

Instrucciones paso a paso desde una maquina sin nada instalado. Probado en Windows 11 con XAMPP.

### 1. Instalar PHP

Opcion A (recomendada): **XAMPP** desde https://www.apachefriends.org/download.html.

- Instalador grafico. Tras instalar, PHP queda en `C:\xampp\php\php.exe`.
- No necesitas levantar Apache ni MySQL desde el panel de XAMPP; usaremos el servidor integrado de PHP.

Opcion B: **PHP standalone**.

- Descargar PHP 8.x Windows VS16 x64 desde https://windows.php.net/download/.
- Extraer a `C:\php`.
- Copiar `php.ini-development` a `php.ini` y descomentar:
  ```
  extension=pdo_sqlite
  extension=pdo_mysql
  extension=mbstring
  extension=openssl
  ```

### 2. Agregar PHP al PATH (opcional pero comodo)

Si usas XAMPP y `php` no esta en PATH, una opcion es invocarlo con la ruta absoluta `C:\xampp\php\php.exe` en cada comando, o agregar `C:\xampp\php` al PATH del sistema.

Verificacion:
```powershell
php -v
```
Debe imprimir `PHP 8.0.x` o superior. Si no, el resto de comandos del README usaran `C:\xampp\php\php.exe`.

### 3. Clonar el repositorio

```powershell
git clone https://github.com/salvex93/checkin_checkout.git
Set-Location checkin_checkout
```

### 4. Copiar y editar el .env

```powershell
Copy-Item .env.example .env
```

Editar `.env` con tu editor preferido. Los **dos** valores que **debes** cambiar siempre antes de levantar:

- `APP_KEY` -> cualquier string aleatorio de 32+ caracteres. Generador rapido:
  ```powershell
  [Convert]::ToBase64String((1..48 | ForEach-Object { Get-Random -Maximum 256 }))
  ```
- `SMTP_*` -> credenciales SMTP reales si quieres probar invitaciones/reset de password. Si solo quieres clockin/clockout, puedes dejar los placeholders y los emails fallaran silenciosamente (queda en `error_log`).

Detalle completo de cada variable en la seccion [Variables de entorno](#variables-de-entorno).

### 5. Levantar el servidor PHP integrado

Desde la raiz del proyecto:

```powershell
php -S localhost:8000 -t public scripts\router.php
```

El segundo argumento `scripts\router.php` reescribe rutas `/api/*` al `public/api/index.php`. Sin el router, los endpoints devolveran 404.

Si PHP no esta en PATH:
```powershell
C:\xampp\php\php.exe -S localhost:8000 -t public scripts\router.php
```

La primera vez que arranca, **se crea automaticamente** la DB SQLite en `storage/melius.db` con el schema base y 3 empresas seed (Coppel, Hyatt, Arajet).

### 6. Aplicar las migraciones

Hay dos migraciones que el bootstrap inicial **no** aplica solo. Ejecutalas una vez:

```powershell
php scripts\migrate_super_admin.php
php scripts\migrate_overtime_edit.php
```

- `migrate_super_admin.php` -> agrega rol `super_admin`, columnas `must_change_password` / `password_changed_at`, tabla `password_reset_tokens` y empresa "Melius Services". Idempotente.
- `migrate_overtime_edit.php` -> agrega `request_type`, `referenced_request_id`, `new_hours` a `overtime_requests`. Idempotente.

Ambas son seguras de re-ejecutar.

### 7. Crear la cuenta de super_admin

```powershell
php scripts\create_super_admin.php tu.email@meliusservices.com "Tu Nombre"
```

Imprime una **password temporal** en consola, una sola vez. Guardala en un gestor de contrasenas. En el primer login el sistema te obliga a cambiarla.

Si solo necesitas un admin de empresa (no super_admin), usa:
```powershell
php scripts\create_admin.php admin@empresa.com "Password123!"
```

### 8. Abrir la app

http://localhost:8000

Iniciar sesion con el email y la password temporal. El sistema te pedira cambiarla. Hecho.

---

## Levantar de cero en macOS / Linux

Mismos pasos que Windows; cambian solo los comandos de shell.

```bash
# 1. Instalar PHP 8 (Homebrew en macOS, apt en Debian/Ubuntu)
brew install php@8.2                              # macOS
sudo apt install php8.2-cli php8.2-sqlite3 php8.2-mysql php8.2-mbstring  # Ubuntu

# 2. Clonar y entrar
git clone https://github.com/salvex93/checkin_checkout.git
cd checkin_checkout

# 3. .env
cp .env.example .env
# generar APP_KEY:
openssl rand -base64 48
# editar .env y pegar el valor en APP_KEY

# 4. Levantar servidor
php -S localhost:8000 -t public scripts/router.php

# 5. Migraciones y super_admin (en otra terminal)
php scripts/migrate_super_admin.php
php scripts/migrate_overtime_edit.php
php scripts/create_super_admin.php tu.email@meliusservices.com "Tu Nombre"
```

---

## Variables de entorno

Archivo `.env` (creado a partir de `.env.example`). Nunca se versiona.

### Entorno

| Variable | Default | Notas |
|---|---|---|
| `APP_ENV` | `development` | `production` activa modo estricto (sin trace de errores en respuestas) |

### Base de datos

| Variable | Default | Notas |
|---|---|---|
| `DB_DRIVER` | `sqlite` | `sqlite` local, `mysql` HostGator |
| `DB_SQLITE_PATH` | `storage/melius.db` | Solo para sqlite. Ruta relativa a la raiz del proyecto |
| `DB_HOST` | `localhost` | Solo mysql |
| `DB_PORT` | `3306` | Solo mysql |
| `DB_NAME` | `melius_clock` | Solo mysql |
| `DB_USER` | `usuario_db` | Solo mysql |
| `DB_PASS` | -- | Solo mysql. **Cambiar siempre** en produccion |
| `DB_CHARSET` | `utf8mb4` | Solo mysql |

### Sesion

| Variable | Default | Notas |
|---|---|---|
| `SESSION_NAME` | `melius_sid` | Nombre de la cookie de sesion |
| `COOKIE_SECURE` | `false` | `true` solo si servis sobre HTTPS |
| `SESSION_LIFETIME` | `28800` | Segundos. 8 horas por defecto |

### Seguridad

| Variable | Default | Notas |
|---|---|---|
| `APP_KEY` | placeholder | **Obligatorio cambiar**. Firma tokens CSRF. >=32 chars aleatorios |
| `AUTH_MAX_ATTEMPTS` | `5` | Intentos fallidos antes de bloqueo |
| `AUTH_LOCK_MINUTES` | `15` | Duracion del bloqueo |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:8000` | Lista separada por coma |

### Email de soporte y envio de correos

`MAIL_DRIVER` selecciona el motor de envio:

- `resend` (recomendado en produccion) — API HTTP via Resend, sale por HTTPS:443. Inmune al bloqueo SMTP saliente de hosting compartido (GoDaddy/HostGator). Requiere cuenta en [resend.com](https://resend.com), dominio verificado (SPF+DKIM) y `RESEND_API_KEY`.
- `smtp` (default en desarrollo) — PHPMailer + servidor SMTP. Util en local con MailHog o si el hosting permite SMTP saliente.

| Variable | Notas |
|---|---|
| `SUPPORT_EMAIL` | Email visible en UI para soporte |
| `MAIL_DRIVER` | `resend` o `smtp` |
| `MAIL_FROM` | Direccion visible en `From` (igual para ambos drivers) |
| `MAIL_FROM_NAME` | Nombre amistoso del remitente |
| `MAIL_REPLY_TO` | Email humano que recibe respuestas |
| `RESEND_API_KEY` | Solo si `MAIL_DRIVER=resend`. Generar en resend.com/api-keys |
| `SMTP_HOST` | Solo si `MAIL_DRIVER=smtp`. Ej `localhost` (cPanel) o `smtp.titan.email` |
| `SMTP_PORT` | `465` (SSL) o `587` (STARTTLS) |
| `SMTP_SECURE` | `ssl` o `tls` |
| `SMTP_USER` | Cuenta autenticada (suele ser el email completo) |
| `SMTP_PASS` | Password de la cuenta SMTP. **Nunca commitear** |
| `SMTP_INSECURE_TLS` | `1` cuando `SMTP_HOST=localhost` en hosting con cert wildcard que no matchea `localhost` |

Si la configuracion esta incompleta los envios fallan silenciosamente y registran el error en el log de PHP. La app sigue funcionando para clockin/clockout, pero invitaciones y reset de password no entregaran emails.

---

## Roles y primer ingreso

Tres roles en `users.role`:

| Rol | Permisos |
|---|---|
| `super_admin` | Ve todas las empresas. Crea otros admins. Promueve/degrada entre `admin` y `consultant`. Acceso a dashboard global y export CSV cross-empresa. Oculto en listados visibles a admins normales. |
| `admin` | Ve solo su empresa. Aprueba solicitudes de cambio y horas extra. Crea consultores via invitacion. No puede cambiar rol de otros. |
| `consultant` | Consultor regular. Hace clockin/clockout, solicita horas extra y cambios de empresa. |

Flujo de primer ingreso:

1. `create_super_admin.php` imprime password temporal en consola.
2. Super_admin entra y el sistema fuerza cambio de password (banner bloqueante).
3. Super_admin crea empresas y admins desde el panel.
4. Admin invita consultores; cada invitacion envia un email con credenciales temporales.
5. Consultor recibe email, entra, cambia password, queda activo.

### Acciones sobre usuarios

| Accion | Quien la hace | Comportamiento |
|---|---|---|
| Invitar consultor | admin, super_admin | Crea cuenta con password temporal, envia email v2 con branding de la marca padre. |
| Invitar admin | super_admin | Igual que arriba pero con `role=admin`. Requiere `company_id`. |
| Reenviar invitacion | admin, super_admin | Regenera password temporal y manda email nuevo. Solo si `must_change_password=1`. |
| Promover a admin | super_admin | Cambia `role: consultant -> admin`. Requiere `company_id` (sino, error). |
| Bajar a consultor | super_admin | Cambia `role: admin -> consultant`. Conserva `company_id` y status. |
| Eliminar / Desactivar | admin (su empresa), super_admin | Si target es `admin` con `company_id`: downgrade a `consultant` activo (conserva acceso a su empresa). Si no tiene empresa o es `consultant`: soft delete (`status=disabled`). Conserva historico. |

`super_admin` nunca se promueve ni degrada via UI: se crea por script CLI (`create_super_admin.php`) y se purga por SQL directo.

---

## Migraciones disponibles

Cada script en `scripts/migrate_*.php` es idempotente: detecta si la migracion ya fue aplicada y no rompe.

| Script | Que hace | Cuando aplicar |
|---|---|---|
| `migrate_super_admin.php` | Rol `super_admin`, `must_change_password`, `password_changed_at`, tabla `password_reset_tokens`, empresa "Melius Services" | Primera vez tras clonar |
| `migrate_overtime_edit.php` | Columnas para edicion de horas extra con aprobacion (`request_type`, `referenced_request_id`, `new_hours`) | Primera vez tras clonar |

Para resetear todo desde cero:
```powershell
Remove-Item storage\melius.db
# al siguiente request, db.php recrea el schema base
# luego aplicar de nuevo las migraciones y crear super_admin
```

En MySQL (HostGator), `sql/schema.mysql.sql` ya incluye todas las columnas y tablas que crean las migraciones, por lo que no se ejecutan los scripts; basta importar el SQL via phpMyAdmin.

---

## Comandos rapidos

```powershell
# Levantar servidor local
php -S localhost:8000 -t public scripts\router.php

# Aplicar migraciones
php scripts\migrate_super_admin.php
php scripts\migrate_overtime_edit.php

# Crear cuentas
php scripts\create_super_admin.php email@empresa.com "Nombre Completo"
php scripts\create_admin.php admin@empresa.com "Password123!"

# Resetear DB local (cuidado: borra todos los datos)
Remove-Item storage\melius.db

# Inspeccionar audit log con sqlite3 (instalable aparte si no esta)
sqlite3 storage\melius.db "SELECT created_at, event, ip_address FROM audit_log ORDER BY created_at DESC LIMIT 20"

# Smoke test rapido de la API
curl http://localhost:8000/api/csrf
```

---

## Exponer localhost a internet

Para probar en mobile real o compartir con un piloto antes de subir a HostGator.

### Opcion A: Cloudflare Tunnel (recomendada)

```powershell
# Descargar cloudflared.exe desde https://github.com/cloudflare/cloudflared/releases/latest
.\cloudflared.exe tunnel --url http://localhost:8000
```

Devuelve una URL `https://*.trycloudflare.com` temporal sin necesidad de cuenta.

### Opcion B: ngrok

```powershell
ngrok http 8000
```

Devuelve `https://*.ngrok-free.app`. Requiere cuenta gratuita.

### Ajuste obligatorio antes de exponer

Agregar el dominio publico a `CORS_ALLOWED_ORIGINS` en `.env`:
```
CORS_ALLOWED_ORIGINS=http://localhost:8000,https://abcd.trycloudflare.com
```
Y reiniciar el servidor PHP.

**Importante:** estos tuneles son temporales y para demo. Para produccion va HostGator.

---

## Despliegue en HostGator

Guia completa paso a paso (subdominio, MySQL, .htaccess, SSL, smoke tests): ver `DEPLOY_HOSTGATOR.md`.

Resumen:

1. Crear subdominio en cPanel y activar AutoSSL.
2. Crear DB MySQL e importar `sql/schema.mysql.sql` via phpMyAdmin.
3. Subir el contenido de `public/` a `public_html/<subdominio>/`.
4. Subir `scripts/` fuera de `public_html/` para que no sean accesibles via HTTP.
5. Crear `.env` (ideal fuera de `public_html/`, ver `DEPLOY_HOSTGATOR.md` para el ajuste de ruta en `config.php`).
6. Crear super_admin via SSH o insertando manualmente en phpMyAdmin con el hash bcrypt generado.
7. Validar headers de seguridad, HTTPS forzado y bloqueo de `.env` via curl.

---

## Seguridad implementada

Resumen alineado con OWASP Top 10 (detalle de cada control en el codigo correspondiente):

- **A01 Control de acceso:** `user_id` y `role` vienen siempre de `$_SESSION`, nunca del cliente. Helpers `require_admin()` y `require_super_admin()` aplican autorizacion server-side.
- **A02 Criptografia:** bcrypt cost 12 para passwords, cookies `HttpOnly` + `Secure` + `SameSite=Strict`, regeneracion de session ID en login.
- **A03 Inyeccion:** PDO prepared statements en todas las queries. JSX escapa output por defecto.
- **A04 Diseño:** rate limit por cuenta (5 fallos -> 15min lock), validacion server-side de tope de horas extra, regla "olvido vs extra" en server.
- **A05 Config:** headers HSTS / CSP / X-Frame-Options / Referrer-Policy / Permissions-Policy en cada response. `.htaccess` con HTTPS forzado y bloqueo de `.env`, `.git`, archivos `.md`.
- **A06 Dependencias:** SRI en CDNs de React/Babel. PHPMailer vendoreado y versionado.
- **A07 Auth:** mensajes anti-enumeracion en login/forgot/register-disabled, regeneracion de session ID, must_change_password obliga rotacion tras invitacion.
- **A08 Integridad:** SRI en `<script>` CDN. Tokens de reset hasheados en DB.
- **A09 Logging:** tabla `audit_log` con timestamp, IP, user agent, event y metadata JSON. Sin passwords ni tokens.
- **A10 SSRF:** N/A (no hay fetch externo server-side excepto SMTP, que apunta a host fijo del `.env`).

CSRF: token double-submit obtenido en `GET /api/csrf` y validado en todos los `POST/PUT/DELETE` que mutan estado.

---

## Troubleshooting

### `php -S` arranca pero `/api/*` devuelve 404

Falta el argumento del router. El comando correcto es:
```powershell
php -S localhost:8000 -t public scripts\router.php
```

### `PDOException: could not find driver`

Falta la extension `pdo_sqlite` o `pdo_mysql`. En XAMPP, editar `C:\xampp\php\php.ini` y descomentar las lineas `extension=pdo_sqlite` y `extension=pdo_mysql`.

### `[mailer] configuracion SMTP incompleta`

Las invitaciones se crean en DB pero el email no se envia. Revisar `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` en `.env`. Si no se requiere email (solo pruebas de clockin), se puede ignorar.

### El super_admin no puede entrar al panel admin

Tiene que aplicarse `migrate_super_admin.php`. Sin esa migracion, el rol `super_admin` no existe en el CHECK constraint de SQLite y `create_super_admin.php` puede fallar o insertar mal.

### "Endpoint no existe" en una ruta valida

Verificar que el front esta llamando con prefijo `/api/`. El router de PHP built-in solo reescribe rutas que comienzan con `/api/`.

### Pierdo la sesion al recargar

`COOKIE_SECURE=true` con servidor en `http://localhost`. Cambiar a `false` en local. En produccion (HTTPS) debe ir `true`.

---

## Backlog y trabajo pendiente

Tareas y deuda tecnica detalladas en `BACKLOG.md` (tabla fija de 12 columnas).

Pendientes destacados al cierre 2026-05-13:

- Fase 4 frontend admin completo (tabs Dashboard, Empresas, Agentes, Invitaciones, Registros, Solicitudes, Admins).
- Fase 5 clockin/clockout con horario efectivo por agente y TZ por empresa.
- 2FA TOTP para cuentas admin.
- Rate limit por IP en `/api/auth/*`.
- Tests automatizados (PHPUnit + smoke API + axe-core).
- Precompilar JSX para eliminar `unsafe-inline` en CSP.

---

## Licencia y autoria

Propiedad de Melius Services. Uso interno. Ver `LICENSE` para terminos.

Mantenedor: Andrew Arizmendi (`salvex93@gmail.com`).
