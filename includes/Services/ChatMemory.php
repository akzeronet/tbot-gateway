<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * ChatMemory — Memoria conversacional persistente por usuario.
 *
 * Almacena el historial de mensajes en MySQL (tabla `tbot_chat_messages`)
 * y alimenta el contexto de la IA con los últimos N mensajes.
 *
 * Estrategia:
 *   - Sliding window: carga los últimos N turnos según plan
 *   - Summarization: cuando el historial excede el window, los mensajes
 *     antiguos se comprimen en un resumen automático (ahorra tokens)
 *   - Aislamiento: cada usuario tiene su propio hilo de conversación
 *   - TTL: mensajes > 30 días se purgan automáticamente vía cron
 *
 * Límites por plan:
 *   - Free:     5 mensajes de contexto (~2K tokens)
 *   - Standard: 20 mensajes de contexto (~8K tokens)
 *   - Premium:  50 mensajes de contexto (~20K tokens)
 */
class ChatMemory {

    const TABLE = 'tbot_chat_messages';

    // Mensajes de contexto por plan
    const CONTEXT_LIMITS = [
        'free'     => 5,
        'standard' => 20,
        'premium'  => 50,
        'admin'    => 100,
    ];

    // Máx caracteres por mensaje guardado (truncar si es más largo)
    const MAX_MSG_LENGTH = 4000;

    // Días de retención antes de purga
    const RETENTION_DAYS = 30;

    // ═══════════════════════════════════════════════════════════════════════════
    // INSTALACIÓN
    // ═══════════════════════════════════════════════════════════════════════════

    public static function install(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('user','assistant','summary') NOT NULL DEFAULT 'user',
            content TEXT NOT NULL,
            tokens_used SMALLINT UNSIGNED DEFAULT 0,
            model VARCHAR(50) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_cleanup (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GUARDAR MENSAJES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Guarda un mensaje del usuario.
     */
    public static function save_user_message(int $user_id, string $content): void {
        self::save($user_id, 'user', $content);
    }

    /**
     * Guarda la respuesta del asistente.
     */
    public static function save_assistant_message(int $user_id, string $content, int $tokens = 0, string $model = ''): void {
        self::save($user_id, 'assistant', $content, $tokens, $model);
    }

    /**
     * Guarda un resumen comprimido de mensajes antiguos.
     */
    public static function save_summary(int $user_id, string $summary): void {
        self::save($user_id, 'summary', $summary);
    }

    private static function save(int $user_id, string $role, string $content, int $tokens = 0, string $model = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Truncar contenido muy largo
        if (mb_strlen($content) > self::MAX_MSG_LENGTH) {
            $content = mb_substr($content, 0, self::MAX_MSG_LENGTH) . '…';
        }

        $wpdb->insert($table, [
            'user_id'     => $user_id,
            'role'        => $role,
            'content'     => $content,
            'tokens_used' => $tokens,
            'model'       => $model ?: null,
            'created_at'  => current_time('mysql'),
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        // ── L0 Cache: Write-Through a Redis ──
        if (in_array($role, ['user', 'assistant'])) {
            RedisMemory::push_message($user_id, [
                'role'    => $role,
                'content' => $content
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // RECUPERAR CONTEXTO PARA LA IA
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Obtiene el historial de conversación formateado para la API de IA.
     *
     * Devuelve un array de mensajes [{role, content}, ...] listo para inyectar
     * en el campo `messages` de la API de OpenAI/Anthropic/etc.
     *
     * @param int    $user_id  ID de WordPress del usuario
     * @param string $plan     Plan actual del usuario (free/standard/premium)
     * @return array<array{role: string, content: string}>
     */
    public static function get_context(int $user_id, string $plan = 'free'): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $limit = self::CONTEXT_LIMITS[$plan] ?? self::CONTEXT_LIMITS['free'];

        // 1. Buscar el resumen más reciente (si existe)
        $summary = $wpdb->get_var($wpdb->prepare(
            "SELECT content FROM {$table}
             WHERE user_id = %d AND role = 'summary'
             ORDER BY created_at DESC LIMIT 1",
            $user_id
        ));

        // 2. Intentar cargar los últimos N mensajes desde Redis (L0 Cache)
        $cached_msgs = RedisMemory::get_messages($user_id, $limit);
        
        if (!empty($cached_msgs)) {
            $rows = $cached_msgs;
        } else {
            // Fallback: Leer de MySQL y repoblar Redis
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT role, content FROM {$table}
                 WHERE user_id = %d AND role IN ('user', 'assistant')
                 ORDER BY created_at DESC LIMIT %d",
                $user_id, $limit
            ), ARRAY_A);

            // Los resultados vienen DESC, invertir para orden cronológico
            $rows = array_reverse($rows);

            // Repoblar Redis para la próxima vez
            if (!empty($rows)) {
                RedisMemory::populate_from_mysql($user_id, $rows);
            }
        }

        $messages = [];

        // Inyectar resumen como contexto de fondo
        if ($summary) {
            $messages[] = [
                'role'    => 'user',
                'content' => "[Resumen de conversaciones anteriores]\n{$summary}",
            ];
            $messages[] = [
                'role'    => 'assistant',
                'content' => 'Entendido, tengo en cuenta el contexto previo.',
            ];
        }

        // Añadir historial reciente
        foreach ($rows as $row) {
            $messages[] = [
                'role'    => $row['role'],
                'content' => $row['content'],
            ];
        }

        return $messages;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SUMMARIZATION — Comprimir historial antiguo
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Comprime mensajes antiguos en un resumen usando la IA.
     * Se ejecuta cuando el historial excede 2× el límite del plan.
     *
     * @return bool True si se generó un resumen
     */
    public static function maybe_summarize(int $user_id, string $plan = 'free'): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $limit = self::CONTEXT_LIMITS[$plan] ?? self::CONTEXT_LIMITS['free'];

        // Contar mensajes totales del usuario
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND role IN ('user','assistant')",
            $user_id
        ));

        // Solo resumir si hay más del doble del límite
        if ($total < $limit * 2) return false;

        // Cargar los mensajes más antiguos (los que serán resumidos)
        $old_count = $total - $limit; // Cuántos quedan fuera del window
        $old_msgs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, role, content FROM {$table}
             WHERE user_id = %d AND role IN ('user','assistant')
             ORDER BY created_at ASC LIMIT %d",
            $user_id, $old_count
        ), ARRAY_A);

        if (count($old_msgs) < 4) return false; // No vale la pena resumir < 4 msgs

        // Construir texto para resumir
        $text_to_summarize = '';
        $ids_to_delete = [];
        foreach ($old_msgs as $msg) {
            $label = $msg['role'] === 'user' ? 'Usuario' : 'Asistente';
            $text_to_summarize .= "{$label}: {$msg['content']}\n\n";
            $ids_to_delete[] = (int) $msg['id'];
        }

        // Llamar a la IA para generar el resumen
        $result = AIService::chat([
            'model'         => 'gpt-4o-mini', // Modelo económico para resumir
            'message'       => "Resume la siguiente conversación en máximo 500 palabras, capturando los temas principales, preferencias del usuario, y cualquier información importante:\n\n{$text_to_summarize}",
            'system_prompt' => 'Eres un asistente de resumen. Genera un resumen conciso y útil de la conversación. Incluye: temas discutidos, preferencias mencionadas, datos importantes del usuario, y el tono de la conversación.',
            'max_tokens'    => 500,
        ]);

        if (!$result['success']) {
            Logger::log('error', "ChatMemory: summarization failed for user#{$user_id}");
            return false;
        }

        // Guardar resumen en MySQL
        self::save_summary($user_id, $result['text']);

        // Vectorizar el resumen en Qdrant (Smart Vectorization)
        if (VectorMemory::is_available()) {
            if (VectorMemory::should_vectorize($result['text'], 'summary')) {
                $summary_vector = EmbeddingService::embed($result['text']);
                if ($summary_vector) {
                    VectorMemory::store($user_id, 'summary', $result['text'], $summary_vector);
                }
            }
        }

        // ── CORE PERSONA: Extraer Identidad en JSON ──
        $persona_result = AIService::chat([
            'model'         => 'gpt-4o-mini',
            'message'       => "A partir del siguiente texto, extrae EXCLUSIVAMENTE datos de identidad permanente del usuario (nombre, edad, profesión, alergias, gustos estables, tono preferido). Devuelve un JSON válido. Si no hay datos relevantes, devuelve {}.\n\nTexto:\n{$text_to_summarize}",
            'system_prompt' => 'Eres un extractor de identidad estricto. Tu única salida debe ser un objeto JSON plano (clave: valor en string). No incluyas markdown, no incluyas texto extra.',
            'max_tokens'    => 300,
        ]);

        if ($persona_result['success']) {
            $json_text = trim(str_replace(['```json', '```'], '', $persona_result['text']));
            $new_persona = json_decode($json_text, true);
            if (is_array($new_persona) && !empty($new_persona)) {
                $existing_persona = get_user_meta($user_id, 'tbot_user_persona', true);
                if (!is_array($existing_persona)) $existing_persona = [];
                // Fusionar conservando lo nuevo
                $merged = array_merge($existing_persona, $new_persona);
                update_user_meta($user_id, 'tbot_user_persona', $merged);
            }
        }

        // Eliminar mensajes antiguos resumidos
        if (!empty($ids_to_delete)) {
            $placeholders = implode(',', array_fill(0, count($ids_to_delete), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                ...$ids_to_delete
            ));
        }

        // Eliminar resúmenes anteriores (solo guardar el más reciente)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE user_id = %d AND role = 'summary'
             AND id != (SELECT max_id FROM (SELECT MAX(id) AS max_id FROM {$table} WHERE user_id = %d AND role = 'summary') AS t)",
            $user_id, $user_id
        ));

        Logger::log('memory', "Summarized {$old_count} messages for user#{$user_id}");
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // STATS Y UTILIDADES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Obtiene estadísticas de memoria de un usuario.
     */
    public static function get_stats(int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND role IN ('user','assistant')",
            $user_id
        ));

        $has_summary = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND role = 'summary'",
            $user_id
        ));

        $first_msg = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(created_at) FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        return [
            'total_messages' => $total,
            'has_summary'    => $has_summary,
            'first_message'  => $first_msg,
        ];
    }

    /**
     * Borra todo el historial de un usuario (GDPR / /delete_data).
     */
    public static function clear(int $user_id): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        
        RedisMemory::clear($user_id); // Limpiar L0
        
        return (int) $wpdb->delete($table, ['user_id' => $user_id], ['%d']);
    }

    /**
     * Purga mensajes antiguos de todos los usuarios (cron diario).
     */
    public static function purge_old(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $days  = self::RETENTION_DAYS;

        return (int) $wpdb->query(
            "DELETE FROM {$table}
             WHERE role IN ('user','assistant')
             AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
    }

    /**
     * Cuenta total de mensajes de un usuario (para analytics).
     */
    public static function count(int $user_id): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id
        ));
    }
}
