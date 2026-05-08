<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * ClickHouseMemory — Nivel L3 de memoria (warm storage, 30-180 días).
 *
 * Usa la HTTP API de ClickHouse (puerto 8123) directamente.
 * No requiere extensiones PHP ni Composer — solo wp_remote_*.
 *
 * Tabla: tbot_messages (MergeTree, ordenada por user_id + created_at)
 * TTL: 180 días automático (ClickHouse lo gestiona)
 *
 * Flujo:
 *   1. Cron (cada 5 min): MySQL msgs > 30 días → batch insert a ClickHouse
 *   2. Lectura: búsqueda full-text cuando IA necesita deep recall
 */
class ClickHouseMemory {

    const TABLE = 'tbot_messages';

    /**
     * Base URL del servidor ClickHouse.
     */
    private static function base_url(): string {
        return rtrim(get_option('tbot_clickhouse_url', 'http://localhost:8123'), '/');
    }

    /**
     * Credenciales de ClickHouse.
     */
    private static function credentials(): array {
        return [
            'user'     => get_option('tbot_clickhouse_user', 'default'),
            'password' => get_option('tbot_clickhouse_password', ''),
        ];
    }

    /**
     * Verifica si ClickHouse está disponible.
     */
    public static function is_available(): bool {
        $response = wp_remote_get(self::base_url() . '/ping', ['timeout' => 3]);
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // INSTALACIÓN
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Crea la tabla en ClickHouse si no existe.
     */
    public static function install(): bool {
        $sql = "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            user_id UInt64,
            role Enum8('user' = 1, 'assistant' = 2, 'summary' = 3),
            content String,
            model LowCardinality(String) DEFAULT '',
            tokens UInt16 DEFAULT 0,
            created_at DateTime DEFAULT now()
        ) ENGINE = MergeTree()
        ORDER BY (user_id, created_at)
        SETTINGS index_granularity = 8192";

        return self::execute($sql);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ESCRITURA — Batch insert desde MySQL
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Mueve mensajes > 30 días de MySQL (ChatMemory) a ClickHouse.
     * Se ejecuta vía cron cada 5 minutos.
     *
     * @return int Número de mensajes migrados
     */
    public static function flush_from_mysql(): int {
        if (!self::is_available()) return 0;

        global $wpdb;
        $mysql_table = $wpdb->prefix . ChatMemory::TABLE;
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

        // Leer mensajes antiguos de MySQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, role, content, model, tokens_used AS tokens, created_at
             FROM {$mysql_table}
             WHERE created_at < %s AND role IN ('user', 'assistant')
             ORDER BY created_at ASC LIMIT 1000",
            $cutoff
        ), ARRAY_A);

        if (empty($rows)) return 0;

        // Insertar en ClickHouse en formato JSONEachRow
        $success = self::insert_batch($rows);

        if ($success) {
            // Borrar de MySQL los que ya fueron migrados
            $ids_migrated = count($rows);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$mysql_table}
                 WHERE created_at < %s AND role IN ('user', 'assistant')
                 ORDER BY created_at ASC LIMIT %d",
                $cutoff, $ids_migrated
            ));

            Logger::log('storage', "ClickHouse: flushed {$ids_migrated} messages from MySQL");
            return $ids_migrated;
        }

        return 0;
    }

    /**
     * Inserta un batch de mensajes en ClickHouse.
     *
     * @param array $rows [['user_id'=>int, 'role'=>string, 'content'=>string, ...], ...]
     */
    public static function insert_batch(array $rows): bool {
        if (empty($rows)) return true;

        // Formato JSONEachRow: una línea JSON por fila
        $payload = '';
        foreach ($rows as $row) {
            $payload .= json_encode([
                'user_id'    => (int) ($row['user_id'] ?? 0),
                'role'       => $row['role'] ?? 'user',
                'content'    => $row['content'] ?? '',
                'model'      => $row['model'] ?? '',
                'tokens'     => (int) ($row['tokens'] ?? 0),
                'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
            ]) . "\n";
        }

        $sql = "INSERT INTO " . self::TABLE . " FORMAT JSONEachRow";
        $creds = self::credentials();

        $response = wp_remote_post(self::base_url() . '/?query=' . urlencode($sql), [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $payload,
        ]);

        if (is_wp_error($response)) {
            Logger::log('error', 'ClickHouse insert failed: ' . $response->get_error_message());
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // LECTURA — Búsqueda para deep recall
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Busca mensajes de un usuario que contengan un texto relevante.
     * Usado para "deep recall" cuando la IA necesita contexto de 30-180 días.
     *
     * @param int    $user_id  ID del usuario
     * @param string $query    Texto a buscar
     * @param int    $limit    Máximo resultados
     * @return array<array{role: string, content: string, created_at: string}>
     */
    public static function search(int $user_id, string $query, int $limit = 5): array {
        // Sanitizar query para LIKE
        $safe_query = addslashes(mb_substr($query, 0, 200));

        $sql = "SELECT role, content, model, created_at
                FROM " . self::TABLE . "
                WHERE user_id = {$user_id}
                AND content LIKE '%{$safe_query}%'
                ORDER BY created_at DESC
                LIMIT {$limit}
                FORMAT JSON";

        $result = self::query($sql);
        return $result['data'] ?? [];
    }

    /**
     * Obtiene los mensajes de un usuario en un rango de fechas.
     * Usado para export (/export) y GDPR.
     */
    public static function get_user_messages(int $user_id, ?string $from = null, ?string $to = null, int $limit = 1000): array {
        $where = "user_id = {$user_id}";
        if ($from) $where .= " AND created_at >= '" . addslashes($from) . "'";
        if ($to)   $where .= " AND created_at <= '" . addslashes($to) . "'";

        $sql = "SELECT role, content, model, tokens, created_at
                FROM " . self::TABLE . "
                WHERE {$where}
                ORDER BY created_at ASC
                LIMIT {$limit}
                FORMAT JSON";

        $result = self::query($sql);
        return $result['data'] ?? [];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GDPR / BORRADO
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Borra todos los mensajes de un usuario en ClickHouse.
     */
    public static function delete_user(int $user_id): bool {
        $sql = "ALTER TABLE " . self::TABLE . " DELETE WHERE user_id = {$user_id}";
        return self::execute($sql);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // STATS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Estadísticas de la tabla.
     */
    public static function get_stats(): array {
        if (!self::is_available()) return ['available' => false];

        $result = self::query("SELECT
            count() AS total_messages,
            uniq(user_id) AS unique_users,
            min(created_at) AS oldest,
            max(created_at) AS newest
            FROM " . self::TABLE . " FORMAT JSON");

        $data = $result['data'][0] ?? [];
        return [
            'available'      => true,
            'total_messages'  => (int) ($data['total_messages'] ?? 0),
            'unique_users'    => (int) ($data['unique_users'] ?? 0),
            'oldest'          => $data['oldest'] ?? null,
            'newest'          => $data['newest'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HTTP HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Ejecuta una query SELECT y devuelve los datos.
     */
    private static function query(string $sql): array {
        $creds = self::credentials();
        $url = self::base_url() . '/?query=' . urlencode($sql);

        if ($creds['user'] !== 'default' || $creds['password']) {
            $url .= '&user=' . urlencode($creds['user']);
            if ($creds['password']) {
                $url .= '&password=' . urlencode($creds['password']);
            }
        }

        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) return [];

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return $decoded ?? [];
    }

    /**
     * Ejecuta una query DDL/DML sin resultado.
     */
    private static function execute(string $sql): bool {
        $creds = self::credentials();

        $response = wp_remote_post(self::base_url() . '/', [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'text/plain'],
            'body'    => $sql,
        ]);

        if (is_wp_error($response)) {
            Logger::log('error', 'ClickHouse execute failed: ' . $response->get_error_message());
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }
}
