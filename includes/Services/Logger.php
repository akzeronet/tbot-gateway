<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * Logger — Sistema de logs con sanitización de PII y gestión de índices.
 *
 * PII que se elimina automáticamente del payload:
 *  - bot_token, phone_number, first_name, last_name, username
 *  - persona_prompt (puede contener datos personales)
 */
class Logger {

    // Campos PII que nunca deben guardarse completos en logs
    const PII_FIELDS = [
        'bot_token', 'phone_number', 'first_name', 'last_name',
        'username', 'persona_prompt', 'password',
    ];

    public static function log(string $type, string $message, ?array $data = null): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tbot_logs';

        // Sanitizar
        $type    = sanitize_text_field($type);
        $message = sanitize_textarea_field($message);

        // Eliminar PII del payload antes de persistir
        $payload = null;
        if ($data !== null) {
            $payload = json_encode(self::sanitize_pii($data));
        }

        $wpdb->insert($table, [
            'event_type' => $type,
            'message'    => $message,
            'payload'    => $payload,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Elimina o enmascara campos PII del array de datos antes de loguear.
     */
    private static function sanitize_pii(array $data): array {
        foreach ($data as $key => $value) {
            if (in_array($key, self::PII_FIELDS, true)) {
                // Mostrar solo primeros 4 chars + asteriscos para debugging mínimo
                if (is_string($value) && strlen($value) > 4) {
                    $data[$key] = substr($value, 0, 4) . str_repeat('*', min(8, strlen($value) - 4));
                } else {
                    $data[$key] = '[REDACTED]';
                }
            } elseif (is_array($value)) {
                $data[$key] = self::sanitize_pii($value);
            }
        }
        return $data;
    }

    // ── Consultas ─────────────────────────────────────────────────────────────

    public static function get_recent(int $limit = 50, string $type = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tbot_logs';
        if ($type) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_type = %s ORDER BY id DESC LIMIT %d",
                $type, $limit
            )) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit
        )) ?: [];
    }

    // Alias para compatibilidad con código existente
    public static function get_recent_logs(int $limit = 10): array {
        return self::get_recent($limit);
    }

    // ── Limpieza ──────────────────────────────────────────────────────────────

    public static function cleanup_old_logs(int $keep = 1000): void {
        global $wpdb;
        $table  = $wpdb->prefix . 'tbot_logs';
        $cutoff = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d, 1", $keep
        ));
        if ($cutoff) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", (int)$cutoff));
        }
    }

    // ── Asegurar índice en created_at (ejecutar una vez) ─────────────────────

    public static function ensure_index(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tbot_logs';
        $exists = $wpdb->get_var(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_created_at'"
        );
        if (!$exists) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_created_at (created_at)");
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_event_type (event_type)");
        }
    }
}
