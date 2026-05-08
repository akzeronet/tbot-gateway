# TBot Gateway — Guía de Rendimiento y Hardening en Producción

## 1. Deshabilitar WP Cron Falso y Usar Cron Real

Por defecto WordPress ejecuta el cron en cada visita de un usuario.
En un bot de alta frecuencia esto es **inestable**. Solución:

### `wp-config.php`
```php
define('DISABLE_WP_CRON', true);
```

### `crontab -e` en el servidor
```bash
# Ejecutar WP Cron cada minuto (real)
* * * * * php /var/www/html/wp-cron.php > /dev/null 2>&1

# Alternativa con WP-CLI (mejor para logs)
* * * * * cd /var/www/html && wp cron event run --due-now --quiet
```

---

## 2. Redis Object Cache

Redis elimina las consultas repetidas a MySQL para cada request del webhook.

### Instalar Redis en Ubuntu/Debian
```bash
sudo apt install redis-server php-redis -y
sudo systemctl enable redis-server --now
```

### Plugin WordPress
Instalar **Redis Object Cache** (Til Krüger) desde el repositorio oficial o:
```bash
wp plugin install redis-cache --activate
wp redis enable
```

### `wp-config.php`
```php
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_DATABASE', 0);
define('WP_REDIS_PREFIX', 'tbot_');
define('WP_REDIS_TIMEOUT', 1);
define('WP_REDIS_READ_TIMEOUT', 1);
// Opcional: contraseña
// define('WP_REDIS_PASSWORD', 'tu_password_segura');
```

### Verificar que funciona
```bash
wp redis status
# Debe mostrar: Status: Connected
```

**Impacto esperado:** Reducción del ~70% en consultas MySQL por webhook.
Los grupos cacheados por TBot son:
- `tbot_user_state` — Estado de usuario (5 min TTL)
- `tbot_credits_*` — Balance de créditos (1 min TTL)
- `tbot_lb_*` — Leaderboards (1 hora TTL)

---

## 3. Nginx — Protección del Webhook

### Rate limiting por IP en Nginx
```nginx
# /etc/nginx/conf.d/tbot-rate-limit.conf
limit_req_zone $binary_remote_addr zone=tbot_webhook:10m rate=10r/s;

server {
    # ... tu config normal ...

    location ~ ^/wp-json/tbot/v1/webhook {
        # Solo aceptar requests de los servidores de Telegram
        allow 149.154.160.0/20;
        allow 91.108.4.0/22;
        allow 91.108.8.0/22;
        allow 91.108.56.0/22;
        allow 95.161.64.0/20;
        allow 2001:b28:f23d::/48;
        allow 2001:b28:f23f::/48;
        allow 2001:67c:4e8::/48;
        deny all;

        limit_req zone=tbot_webhook burst=30 nodelay;
        limit_req_status 429;

        proxy_pass http://127.0.0.1:80;
    }
}
```

> ⚠️ **Actualiza las IPs de Telegram periódicamente:**
> https://core.telegram.org/bots/webhooks#the-short-version

---

## 4. MySQL — Optimización de Tablas TBot

```sql
-- Verificar índices existentes
SHOW INDEX FROM wp_tbot_logs;
SHOW INDEX FROM wp_tbot_credit_transactions;
SHOW INDEX FROM wp_tbot_user_state;

-- Índices adicionales recomendados (si no los creó dbDelta)
ALTER TABLE wp_tbot_credit_transactions
    ADD INDEX IF NOT EXISTS idx_user_type (user_id, type),
    ADD INDEX IF NOT EXISTS idx_created (created_at);

ALTER TABLE wp_tbot_broadcasts
    ADD INDEX IF NOT EXISTS idx_status_created (status, created_at);

-- Limpiar logs antiguos manualmente si es necesario
DELETE FROM wp_tbot_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Análisis de rendimiento
EXPLAIN SELECT * FROM wp_tbot_user_state WHERE tg_id = 123456789;
```

---

## 5. Migración de usermeta a tbot_user_state

Una vez habilitada la tabla, migra los usuarios existentes:

### Vía WP-CLI
```bash
wp eval '\TBot\Services\UserState::migrate_all(); echo "Migración completada\n";'
```

### Vía Admin (Diagnostics)
En el panel **TBot → Diagnósticos**, ejecuta "Migrar a UserState".

---

## 6. PHP — Configuración Recomendada para el Bot

### `php.ini` o `.htaccess`
```ini
; Tiempo máximo para procesar el webhook (Telegram espera máx 30s)
max_execution_time = 30

; Telegram puede mandar payloads grandes con fotos/documentos
post_max_size = 50M
upload_max_filesize = 50M

; OPCache — crítico para performance
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0    ; en producción, deshabilitar validación
opcache.revalidate_freq = 0
```

---

## 7. Webhook Secret Token

**Nunca** exponer el webhook sin validar la firma de Telegram.

El `WebhookHandler` ya verifica `X-Telegram-Bot-Api-Secret-Token`.
Asegúrate de tener configurado en el admin:

```
TBot → Ajustes → Webhook Secret Token: [genera un token aleatorio fuerte]
```

Y al registrar el webhook:
```bash
curl "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -d "url=https://tu-dominio.com/wp-json/tbot/v1/webhook" \
  -d "secret_token=TU_SECRET_TOKEN_AQUI" \
  -d "allowed_updates=[\"message\",\"callback_query\",\"inline_query\",\"pre_checkout_query\",\"successful_payment\"]"
```

---

## 8. Monitoreo con UptimeRobot / Betterstack

Monitorea el endpoint de salud del plugin:
```
GET https://tu-dominio.com/wp-json/tbot/v1/health
```

Debe retornar `{"status":"ok","version":"x.x.x"}`.

Configura alertas si el endpoint cae o retorna tiempo > 2s.

---

## 9. Escalabilidad — Checklist para 10K+ Usuarios

| Item | Estado | Notas |
|------|--------|-------|
| Redis Object Cache | Instalar | Elimina 70% queries MySQL |
| Real Cron (`crontab`) | Configurar | Reemplaza WP pseudo-cron |
| Nginx rate limit Telegram IPs | Configurar | Protección DDoS |
| `tbot_user_state` tabla | ✅ Creada | Auto-migración on-demand |
| `tbot_credits` tabla atómica | ✅ Creada | Previene race conditions |
| `tbot_analytics_daily` | ✅ Creada | Agrega datos diariamente |
| OPCache PHP | Configurar | Reducir CPU ~40% |
| AI Provider API Keys | Configurar | OpenAI / Anthropic / Google / Groq |
| AI timeout < 30s | ✅ Configurado | AIService con wp_remote timeout |
| Leaderboard TTL 1h | ✅ Activo | Evita queries frecuentes |
| Broadcast chunked 20msg/s | ✅ Activo | Respeta límites de Telegram |
