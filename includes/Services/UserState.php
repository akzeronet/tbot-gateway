<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * UserState — Hot-path store para metadatos de usuario de alta frecuencia.
 *
 * Reemplaza wp_usermeta para los campos que se leen/escriben en CADA request:
 *   - tg_id, lang, plan, credits_cached, streak_days,
 *     last_active, persona_prompt, bot_id
 *
 * Beneficios vs usermeta:
 *   - 1 SELECT único por request (en lugar de N get_user_meta() individuales)
 *   - Índice PRIMARY en tg_id para lookup O(log n)
 *   - Campo `updated_at` para analíticas sin escanear usermeta
 *   - Compatible con Redis Object Cache (wp_cache_*)
 *
 * Modo de uso:
 *   $state = UserState::get($user_id);
 *   UserState::set($user_id, ['lang' => 'en', 'streak_days' => 5]);
 *
 * Migración:
 *   UserState::migrate_from_usermeta() — copia datos existentes de usermeta.
 */
class UserState {

    const CACHE_GROUP = 'tbot_user_state';
    const CACHE_TTL   = 300; // 5 min

    // Columnas de la tabla (subset que va a usermeta legacy)
    const COLUMNS = [
        'tg_id', 'lang', 'plan', 'credits_cached',
        'streak_days', 'last_active', 'persona_prompt',
        'personal_bot_id', 'referral_count', 'referred_by',
    ];

    // ── Lectura ───────────────────────────────────────────────────────────────

    /**
     * Obtiene el estado completo de un usuario.
     * Prioriza cache de objetos (Redis si disponible).
     */
    public static function get(int $user_id): array {
        $cached = wp_cache_get($user_id, self::CACHE_GROUP);
        if ($cached !== false) return (array) $cached;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbot_user_state WHERE user_id = %d", $user_id
        ), ARRAY_A);

        if (!$row) {
            // Usuario sin fila → crear desde usermeta (migración transparente)
            $row = self::init_from_usermeta($user_id);
        }

        wp_cache_set($user_id, $row, self::CACHE_GROUP, self::CACHE_TTL);
        return $row;
    }

    /**
     * Obtiene un campo específico (con fallback a valor por defecto).
     */
    public static function field(int $user_id, string $field, mixed $default = null): mixed {
        $state = self::get($user_id);
        return $state[$field] ?? $default;
    }

    /**
     * Lookup por Telegram ID (para el webhook, que llega con tg_id).
     * Retorna wp user_id o null.
     */
    public static function get_user_id_by_tg(int $tg_id): ?int {
        $cached = wp_cache_get('tg_' . $tg_id, self::CACHE_GROUP);
        if ($cached !== false) return (int) $cached ?: null;

        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}tbot_user_state WHERE tg_id = %d", $tg_id
        ));

        wp_cache_set('tg_' . $tg_id, $user_id ? (int)$user_id : 0, self::CACHE_GROUP, self::CACHE_TTL);
        return $user_id ? (int)$user_id : null;
    }

    // ── Escritura ─────────────────────────────────────────────────────────────

    /**
     * Actualiza campos en la tabla. Solo escribe los campos provistos.
     */
    public static function set(int $user_id, array $fields): void {
        global $wpdb;

        $clean = [];
        foreach ($fields as $key => $val) {
            if (in_array($key, self::COLUMNS, true)) {
                $clean[$key] = $val;
            }
        }
        if (empty($clean)) return;

        $clean['updated_at'] = current_time('mysql');

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}tbot_user_state WHERE user_id = %d", $user_id
        ));

        if ($exists) {
            $wpdb->update("{$wpdb->prefix}tbot_user_state", $clean, ['user_id' => $user_id]);
        } else {
            $wpdb->insert("{$wpdb->prefix}tbot_user_state",
                array_merge(['user_id' => $user_id, 'created_at' => current_time('mysql')], $clean));
        }

        // Invalidar cache
        wp_cache_delete($user_id, self::CACHE_GROUP);
        if (!empty($fields['tg_id'])) {
            wp_cache_delete('tg_' . $fields['tg_id'], self::CACHE_GROUP);
        }
    }

    /**
     * Incrementa un campo numérico de forma atómica (sin race condition).
     */
    public static function increment(int $user_id, string $field, int $by = 1): void {
        if (!in_array($field, self::COLUMNS, true)) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tbot_user_state
             SET `{$field}` = COALESCE(`{$field}`, 0) + %d, updated_at = %s
             WHERE user_id = %d",
            $by, current_time('mysql'), $user_id
        ));
        wp_cache_delete($user_id, self::CACHE_GROUP);
    }

    // ── Migración desde usermeta ──────────────────────────────────────────────

    /**
     * Crea la fila de state para un usuario ya existente (migración on-demand).
     */
    private static function init_from_usermeta(int $user_id): array {
        $state = [
            'user_id'        => $user_id,
            'tg_id'          => (int)  get_user_meta($user_id, 'tbot_telegram_id',    true),
            'lang'           =>         get_user_meta($user_id, 'tbot_language',       true) ?: 'es',
            'plan'           =>         get_user_meta($user_id, 'tbot_subscription',   true) ?: 'free',
            'credits_cached' => (int)  get_user_meta($user_id, 'tbot_credits',         true),
            'streak_days'    => (int)  get_user_meta($user_id, 'tbot_streak_days',     true),
            'last_active'    => (int)  get_user_meta($user_id, 'tbot_last_active',     true),
            'persona_prompt' =>         get_user_meta($user_id, 'tbot_persona_prompt', true),
            'personal_bot_id'=> (int)  get_user_meta($user_id, 'tbot_personal_bot_id',true),
            'referral_count' => (int)  get_user_meta($user_id, 'tbot_referral_count',  true),
            'referred_by'    => (int)  get_user_meta($user_id, 'tbot_referred_by',     true),
            'created_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ];

        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}tbot_user_state", $state);
        return $state;
    }

    /**
     * Migración masiva: copia todos los usuarios de usermeta a tbot_user_state.
     * Ejecutar una vez desde un WP-CLI command o el panel de admin.
     */
    public static function migrate_all(): int {
        global $wpdb;
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'tbot_telegram_id' AND meta_value != ''"
        );

        $count = 0;
        foreach ($user_ids as $uid) {
            self::init_from_usermeta((int)$uid);
            $count++;
        }
        return $count;
    }

    // ── Instalación de Tabla ──────────────────────────────────────────────────

    public static function install_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tbot_user_state (
            user_id         BIGINT UNSIGNED  NOT NULL,
            tg_id           BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            lang            VARCHAR(8)       NOT NULL DEFAULT 'es',
            plan            VARCHAR(20)      NOT NULL DEFAULT 'free',
            credits_cached  INT              NOT NULL DEFAULT 0,
            streak_days     SMALLINT         NOT NULL DEFAULT 0,
            last_active     INT UNSIGNED     NULL,
            persona_prompt  TEXT             NULL,
            personal_bot_id INT UNSIGNED     NULL,
            referral_count  SMALLINT         NOT NULL DEFAULT 0,
            referred_by     BIGINT UNSIGNED  NULL,
            created_at      DATETIME         NOT NULL,
            updated_at      DATETIME         NOT NULL,
            PRIMARY KEY (user_id),
            UNIQUE  KEY idx_tg_id      (tg_id),
            KEY     idx_plan           (plan),
            KEY     idx_last_active    (last_active),
            KEY     idx_streak_days    (streak_days)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
