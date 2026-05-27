# Fail2ban — integracion con anti-bot de Clock System

Activa el bloqueo automatico de IPs que disparen el filtro anti-scraper de la API.

## Origen del dato

`public/api/anti_bot.php` emite por `error_log()` una linea por cada bloqueo:

```
[anti-bot] {"event":"anti_bot_block","reason":"scanner_ua","ip":"203.0.113.42","ua":"ahrefsbot/...","uri":"/api/csrf","ts":"2026-05-27T19:42:00+00:00"}
```

Fail2ban consume esa linea, extrae la IP y la banea segun la politica de `jail.d`.

## Instalacion

Estos archivos NO se aplican automaticamente. El operador los copia al servidor que tenga fail2ban instalado (no es el caso del shared hosting GoDaddy actual; aplican a VPS o servidor dedicado futuro).

```bash
# 1. Copiar filtro y jail
sudo cp deploy/fail2ban/filter.d/clockin-antibot.conf /etc/fail2ban/filter.d/
sudo cp deploy/fail2ban/jail.d/clockin-antibot.conf  /etc/fail2ban/jail.d/

# 2. Ajustar logpath en jail.d/clockin-antibot.conf segun la ruta REAL del
#    error_log de PHP en el servidor objetivo.

# 3. Probar el regex contra una linea conocida
sudo fail2ban-regex /home/z90qbbt5eewc/logs/app.meliusservices.com/error_log \
                    /etc/fail2ban/filter.d/clockin-antibot.conf

# 4. Recargar fail2ban y verificar el jail
sudo systemctl reload fail2ban
sudo fail2ban-client status clockin-antibot
```

## Notas

- **GoDaddy shared hosting actual**: no expone fail2ban, los archivos quedan listos para una migracion futura a VPS.
- **Cloudflare**: si el sitio queda detras de CF (decision 2026-05-14), la IP que el servidor ve es la del proxy. Configurar `RemoteIPHeader CF-Connecting-IP` en Apache y en `jail.d` usar `usedns = warn` o un action que llame a la API de CF en vez de iptables.
- **Politica actual**: 3 bloqueos en 10 min disparan ban de 1h. Conservador para evitar falsos positivos con monitorizacion legitima.
- **No interfiere con el filtro de `.htaccess`**: el .htaccess bloquea sin loguear; fail2ban se nutre del log estructurado de PHP. Son capas complementarias.
