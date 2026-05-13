# Clockin/Clockout - Melius Services

Sistema de control de jornada laboral para consultores de Melius Services.
Stack: PHP 8.1+ (backend), SQLite (local) / MySQL (HostGator), React via CDN + Tailwind (frontend).

---

## Estructura

```
clockin-clockout/
├── public/                  Raiz publica (esto es lo que se sube a HostGator)
│   ├── index.html           Front (React + Tailwind, inline)
│   ├── .htaccess            Forzar HTTPS, enrutar /api/*, headers seguridad
│   └── api/                 Backend PHP
│       ├── index.php        Router unico
│       ├── config.php       Carga .env
│       ├── db.php           PDO (SQLite/MySQL)
│       ├── security_headers.php
│       ├── csrf.php         Proteccion CSRF double-submit
│       ├── helpers.php      Validacion, sesion, audit, rate limit
│       ├── auth.php         register, login, logout, me
│       ├── records.php      clockin, clockout, overtime, cambio empresa
│       └── admin.php        Endpoints rol admin
├── sql/
│   ├── schema.mysql.sql     Schema para HostGator
│   └── schema.sqlite.sql    Schema para desarrollo local
├── scripts/
│   └── create_admin.php     CLI para crear/promover admin
├── storage/                 SQLite local (gitignored)
├── .env.example             Plantilla de variables de entorno
├── .gitignore
├── BACKLOG.md
├── README.md                Este archivo
└── DEPLOY_HOSTGATOR.md      Guia de despliegue produccion
```

---

## Levantamiento local (Windows)

### Requisitos

- PHP 8.1 o superior con extensiones `pdo_sqlite`, `mbstring`, `json` (vienen por defecto).
- Verificar instalacion: `php -v` en PowerShell.

Si no tienes PHP:
- Descarga PHP 8.3 para Windows: https://windows.php.net/download/
- Extrae a `C:\php` y agrega `C:\php` al PATH del sistema.
- En `C:\php\php.ini` (copia desde `php.ini-development`), habilita: `extension=pdo_sqlite`, `extension=mbstring`, `extension=openssl`.

### Pasos

1. Copiar el archivo de configuracion:
   ```powershell
   Copy-Item .env.example .env
   ```

2. Editar `.env` y poner una `APP_KEY` aleatoria:
   ```
   APP_KEY=cualquier_string_aleatorio_largo_de_32_caracteres_o_mas
   ```

3. Levantar el servidor PHP integrado desde la raiz del proyecto:
   ```powershell
   php -S localhost:8000 -t public public/api/index.php
   ```

   Nota: el segundo argumento `public/api/index.php` actua como router fallback —
   re-enruta cualquier path `/api/*` al index.php cuando el archivo fisico no existe.

4. Abrir http://localhost:8000 en el navegador.

5. Crear un admin (en otra terminal):
   ```powershell
   php scripts/create_admin.php admin@melius.com "TuPasswordSegura123!"
   ```

   Despues podras iniciar sesion en el front con esas credenciales y veras el panel admin.

### Datos iniciales

La primera vez que arranca, el archivo `storage/melius.db` se crea automaticamente
con el schema y 3 empresas seed (Coppel, Hyatt, Arajet). Para resetear, simplemente
borra `storage/melius.db` y vuelve a abrir la app.

---

## Exponer localhost a internet (para compartir y probar antes de subir)

Tres opciones, ordenadas por simplicidad. Todas exponen tu `localhost:8000` con HTTPS
publico temporal. Ideal para enseñarle el sistema a otra persona o probar en mobile real.

### Opcion A: Cloudflare Tunnel (recomendada, gratis, sin cuenta)

Sin login, URL temporal:
```powershell
# Descarga cloudflared.exe desde https://github.com/cloudflare/cloudflared/releases/latest
.\cloudflared.exe tunnel --url http://localhost:8000
```

Te devuelve algo como `https://xxx-xxx-xxx.trycloudflare.com` que puedes compartir.

### Opcion B: ngrok

```powershell
# Instala ngrok desde https://ngrok.com/download
ngrok http 8000
```

Devuelve `https://xxxx.ngrok-free.app`. Necesita cuenta gratuita.

### Opcion C: Tu propia IP publica con port forwarding

Solo si controlas tu router. Abre el puerto 8000 desde el router hacia tu IP local.
**No recomendado** para esta app porque expone PHP dev server (no production-grade).

### Ajuste necesario antes de exponer

Cuando expongas via tunel, agrega el dominio publico a `CORS_ALLOWED_ORIGINS` en `.env`:
```
CORS_ALLOWED_ORIGINS=http://localhost:8000,https://xxx-xxx-xxx.trycloudflare.com
```
y reinicia el servidor PHP.

**Importante:** los tuneles temporales no son para produccion. Cuando termines la demo,
detenlos. Para produccion real va HostGator (ver `DEPLOY_HOSTGATOR.md`).

---

## Probar la regla "olvido vs horas extra"

La regla se ejecuta en servidor en `public/api/records.php`. Para probarla sin esperar
al dia siguiente, edita temporalmente las constantes al inicio del archivo:
```php
const OVERTIME_GRACE_HOUR_AM = 23;   // simular ventana hasta las 23h
const STANDARD_CLOSE_HOUR = 18;
const OVERTIME_MAX_HOURS = 6.0;
```

Crea un registro con fecha de ayer manualmente via SQLite (con `sqlite3 storage/melius.db`) o usa la UI: haz clockin, no clockout, espera al dia siguiente.

Recuerda revertir antes de subir.

---

## Resumen de seguridad implementada (OWASP Top 10)

- **A01 Control de acceso:** `user_id` viene de `$_SESSION`, jamas del cliente. Endpoints admin verifican rol server-side.
- **A02 Criptografia:** bcrypt cost 12 para passwords. Cookies HttpOnly + Secure + SameSite=Strict.
- **A03 Inyeccion:** PDO prepared statements en todas las queries. JSX escapa output por defecto.
- **A04 Diseño:** rate limit por cuenta (5 fallos → 15min lock). Validacion server-side de horas extra. Regla olvido/extra en server.
- **A05 Config:** Headers HSTS/CSP/X-Frame-Options/Referrer-Policy/Permissions-Policy emitidos en cada response.
- **A06 Dependencias:** SRI en CDNs de React/Babel.
- **A07 Auth:** mensajes genericos (anti-enumeracion), regeneracion de session ID en login.
- **A08 Integridad:** SRI. No hay deps via package manager aun (PHP nativo).
- **A09 Logging:** tabla `audit_log` con timestamp, IP, user agent, evento. Sin passwords ni tokens.
- **A10 SSRF:** N/A (no hay fetch externo server-side).

---

## Comandos rapidos

```powershell
# Levantar local
php -S localhost:8000 -t public public/api/index.php

# Crear admin
php scripts/create_admin.php admin@melius.com "Password123!"

# Resetear DB local (cuidado: borra todos los datos)
Remove-Item storage\melius.db

# Ver eventos de auditoria
sqlite3 storage\melius.db "SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 20"
```
