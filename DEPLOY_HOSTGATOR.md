# Despliegue en HostGator

Guia paso a paso para publicar la aplicacion en un subdominio de HostGator
(ejemplo: `clock.tudominio.com`). Cubre creacion de DB, subida de archivos,
proteccion del `.env` y cuenta admin inicial.

---

## Pre-requisitos

- Plan HostGator con cPanel.
- PHP 8.1+ activo (verificar en cPanel → MultiPHP Manager).
- Acceso a phpMyAdmin (incluido en cPanel).
- Idealmente SSH habilitado (para `create_admin.php`). Si no, usaras phpMyAdmin manual.

---

## Paso 1 - Crear subdominio

1. cPanel → **Subdomains**.
2. Crear `clock` apuntando al document root `public_html/clock`.
3. Activar SSL (cPanel → SSL/TLS Status → AutoSSL para el subdominio).

---

## Paso 2 - Crear base de datos MySQL

1. cPanel → **MySQL Databases**.
2. Crear base: `cuenta_melius_clock` (HostGator prefija con tu cuenta).
3. Crear usuario: `cuenta_melius_user`. Asignar **password robusto** (>=16 chars, generado por cPanel).
4. Agregar el usuario a la base con **ALL PRIVILEGES** excepto `GRANT`.

Anota los valores para el `.env`:
- DB name: `cuenta_melius_clock`
- DB user: `cuenta_melius_user`
- DB pass: el generado
- DB host: `localhost`

---

## Paso 3 - Importar el schema

1. cPanel → **phpMyAdmin** → seleccionar `cuenta_melius_clock`.
2. Pestaña **Importar** → subir `sql/schema.mysql.sql`.
3. Ejecutar. Confirmar que se crearon 6 tablas (`companies`, `users`, `attendance_records`, `change_requests`, `overtime_requests`, `audit_log`).

---

## Paso 4 - Subir los archivos

Opcion **File Manager** (mas simple):

1. cPanel → **File Manager** → `public_html/clock/`.
2. Subir el contenido de `public/` (NO la carpeta, su contenido) en `public_html/clock/`.
   Debe quedar:
   ```
   public_html/clock/
   ├── index.html
   ├── .htaccess
   └── api/
       ├── index.php
       ├── ...
   ```
3. Subir `scripts/create_admin.php` a `public_html/` (FUERA de `clock/`, para no exponerlo).

Opcion **SFTP/FileZilla**: idem, arrastrar `public/*` a `public_html/clock/`.

---

## Paso 5 - Crear el archivo .env

Por seguridad, el `.env` debe estar fuera de `public_html` cuando sea posible.
HostGator permite leer un nivel arriba. Estructura recomendada:

```
/home/cuenta/
├── .env                    <- aqui (NO accesible por web)
└── public_html/
    └── clock/
        ├── index.html
        └── api/...
```

Y editar `public/api/config.php` linea 53 para apuntar al `.env` correcto:
```php
load_env('/home/cuenta/.env');   // ruta absoluta
```

**Alternativa simple** (si no puedes subir fuera de public_html): subir `.env` en `public_html/clock/` y confiar en el `.htaccess` que ya niega acceso HTTP a `.env`. **Verifica** que `https://clock.tudominio.com/.env` devuelva 403/404 antes de proceder.

Contenido del `.env` produccion:
```
APP_ENV=production
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=cuenta_melius_clock
DB_USER=cuenta_melius_user
DB_PASS=password_robusto_generado
DB_CHARSET=utf8mb4

SESSION_NAME=melius_sid
COOKIE_SECURE=true
SESSION_LIFETIME=28800

APP_KEY=string_aleatorio_de_64_caracteres_generado_con_openssl_rand_base64_48

AUTH_MAX_ATTEMPTS=5
AUTH_LOCK_MINUTES=15

CORS_ALLOWED_ORIGINS=https://clock.tudominio.com

SUPPORT_EMAIL=andrew.arizmendi@meliusservices.com
```

Genera `APP_KEY` con (en tu maquina local):
```powershell
[Convert]::ToBase64String((1..48 | ForEach-Object { Get-Random -Maximum 256 }))
```

---

## Paso 6 - Crear cuenta admin inicial

**Opcion A: via SSH (recomendada)**

```bash
ssh cuenta@tudominio.com
cd /home/cuenta
php public_html/create_admin.php admin@meliusservices.com "PasswordRobusta2026!"
```

**Opcion B: via phpMyAdmin (manual)**

1. Genera el hash bcrypt localmente en tu maquina:
   ```powershell
   php -r "echo password_hash('PasswordRobusta2026!', PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;"
   ```
2. En phpMyAdmin → tabla `users` → Insertar:
   - `email`: admin@meliusservices.com
   - `name`: Administrador
   - `password_hash`: el hash generado
   - `role`: admin
   - `is_active`: 1

---

## Paso 7 - Verificacion post-deploy

Smoke tests obligatorios. Si alguno falla, NO compartas el subdominio.

1. **HTTPS forzado:**
   - Abrir `http://clock.tudominio.com` → debe redirigir a `https://`.

2. **Headers de seguridad:**
   ```
   curl -I https://clock.tudominio.com
   ```
   Debe incluir: `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options`, `Content-Security-Policy`.

3. **.env protegido:**
   - Visitar `https://clock.tudominio.com/.env` → debe devolver 403 o 404. **NUNCA** debe descargar el archivo.
   - Lo mismo con `https://clock.tudominio.com/api/config.php` → 404 (mod_rewrite lo enmascara).

4. **API funciona:**
   ```
   curl https://clock.tudominio.com/api/csrf
   ```
   Debe devolver `{"ok":true,"data":{"csrf_token":"..."}}`.

5. **Login admin:**
   - Ir a `https://clock.tudominio.com`, iniciar sesion con el admin creado.
   - Deberia llevar al panel admin con las 3 pestañas.

6. **Audit log activo:**
   - En phpMyAdmin: `SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 5;`
   - Debe ver eventos `login_success`, `csrf`, etc.

---

## Mantenimiento

### Revisar intentos de login fallidos

```sql
SELECT u.email, COUNT(*) as intentos, MAX(al.created_at) as ultimo
FROM audit_log al
LEFT JOIN users u ON u.id = al.user_id
WHERE al.event LIKE 'login_failed%' AND al.created_at > NOW() - INTERVAL 24 HOUR
GROUP BY al.user_id
ORDER BY intentos DESC;
```

### Desbloquear una cuenta manualmente

```sql
UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE email = '...';
```

### Backup recomendado

cPanel → **Backups** → descarga semanal de la DB. Almacenar fuera del servidor.

---

## Que viene despues (BACKLOG)

Tareas que NO se entregaron en este turno pero estan registradas:

1. Precompilar JSX para eliminar `unsafe-inline` en CSP (endurecer A05).
2. 2FA via TOTP para cuentas admin.
3. Rate limit por IP en `/api/auth/*` (hoy solo por cuenta).
4. Migracion: agregar `password_reset_tokens` para flujo de "olvide mi contraseña".
5. Email notifications para solicitudes pendientes (PHPMailer + SMTP de Hostgator).

Estan en `BACKLOG.md`.
