<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * VectorMemory — Cliente PHP para Qdrant REST API.
 *
 * Proporciona memoria a largo plazo semántica para las conversaciones.
 * Cada mensaje se embede y almacena como un punto vectorial en Qdrant,
 * permitiendo búsqueda por similitud para recuperar contexto relevante (RAG).
 *
 * Aislamiento: cada búsqueda filtra por user_id, garantizando privacidad.
 * 
 * Endpoints usados:
 *   PUT  /collections/{name}           — Crear colección
 *   PUT  /collections/{name}/points    — Upsert puntos
 *   POST /collections/{name}/points/search — Buscar similares
 *   POST /collections/{name}/points/delete — Borrar por filtro
 */
class VectorMemory {

    const COLLECTION = 'tbot_conversations';
    const DIMENSION  = 768; // nomic-embed-text dimension

    /**
     * Base URL del servidor Qdrant.
     */
    private static function base_url(): string {
        return rtrim(get_option('tbot_qdrant_url', 'http://localhost:6333'), '/');
    }

    /**
     * Verifica si Qdrant está disponible.
     */
    public static function is_available(): bool {
        $response = wp_remote_get(self::base_url() . '/healthz', ['timeout' => 3]);
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // INSTALACIÓN
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Crea la colección en Qdrant si no existe.
     */
    public static function install(): bool {
        $url = self::base_url() . '/collections/' . self::COLLECTION;

        // Verificar si ya existe
        $check = wp_remote_get($url, ['timeout' => 5]);
        if (!is_wp_error($check) && wp_remote_retrieve_response_code($check) === 200) {
            return true; // Ya existe
        }

        // Crear colección
        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'vectors' => [
                    'size'     => self::DIMENSION,
                    'distance' => 'Cosine',
                ],
                'optimizers_config' => [
                    'indexing_threshold' => 10000, // Indexar después de 10K puntos
                ],
                'on_disk_payload' => true, // Payloads en disco para ahorrar RAM
            ]),
        ]);

        if (is_wp_error($response)) {
            Logger::log('error', 'Qdrant: failed to create collection: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200 || $code === 201) {
            // Crear índice de payload para user_id (filtrado rápido)
            self::create_payload_index('user_id', 'integer');
            self::create_payload_index('role', 'keyword');
            Logger::log('admin', 'Qdrant: collection ' . self::COLLECTION . ' created');
            return true;
        }

        Logger::log('error', 'Qdrant: unexpected response ' . $code);
        return false;
    }

    /**
     * Crea un índice de payload para filtrado eficiente.
     */
    private static function create_payload_index(string $field, string $type): void {
        $url = self::base_url() . '/collections/' . self::COLLECTION . '/index';
        wp_remote_request($url, [
            'method'  => 'PUT',
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'field_name'   => $field,
                'field_schema' => $type,
            ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GUARDAR MENSAJES (UPSERT)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Filtro inteligente de vectorización.
     * Evalúa si un texto tiene suficiente valor semántico para ser vectorizado,
     * ahorrando espacio en memoria RAM en Qdrant.
     */
    public static function should_vectorize(string $text, string $role = 'user'): bool {
        // Los resúmenes condensados siempre aportan mucho valor
        if ($role === 'summary') return true;

        $text = trim($text);
        
        // Descartar mensajes demasiado cortos (ej: "ok", "hola", "jaja")
        $word_count = str_word_count($text);
        if ($word_count < 4) return false;

        // Descartar si es un enlace, puro número o comando
        if (preg_match('/^https?:\/\//i', $text)) return false;
        if (is_numeric($text)) return false;
        if (str_starts_with($text, '/')) return false;

        return true;
    }

    /**
     * Almacena un mensaje embebido en Qdrant.
     *
     * @param int    $user_id  ID de WordPress del usuario
     * @param string $role     'user' o 'assistant'
     * @param string $text     Contenido del mensaje
     * @param array  $vector   Embedding del mensaje [float × 768]
     * @return bool
     */
    public static function store(int $user_id, string $role, string $text, array $vector): bool {
        $url = self::base_url() . '/collections/' . self::COLLECTION . '/points';

        // ID único basado en user + timestamp + hash
        $point_id = self::generate_uuid();

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'points' => [[
                    'id'      => $point_id,
                    'vector'  => $vector,
                    'payload' => [
                        'user_id'    => $user_id,
                        'role'       => $role,
                        'text'       => mb_substr($text, 0, 2000), // Limitar payload
                        'created_at' => time(),
                    ],
                ]],
            ]),
        ]);

        if (is_wp_error($response)) {
            Logger::log('error', 'Qdrant upsert failed: ' . $response->get_error_message());
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Almacena múltiples puntos de una vez (batch).
     *
     * @param array $points [['user_id'=>int, 'role'=>string, 'text'=>string, 'vector'=>array], ...]
     */
    public static function store_batch(array $points): bool {
        $url = self::base_url() . '/collections/' . self::COLLECTION . '/points';

        $qdrant_points = [];
        foreach ($points as $p) {
            $qdrant_points[] = [
                'id'      => self::generate_uuid(),
                'vector'  => $p['vector'],
                'payload' => [
                    'user_id'    => $p['user_id'],
                    'role'       => $p['role'],
                    'text'       => mb_substr($p['text'], 0, 2000),
                    'created_at' => time(),
                ],
            ];
        }

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode(['points' => $qdrant_points]),
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BÚSQUEDA SEMÁNTICA (RAG)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Busca los K fragmentos más relevantes del historial de un usuario.
     *
     * @param array  $query_vector Embedding de la query actual [float × 768]
     * @param int    $user_id      ID del usuario (filtro obligatorio)
     * @param int    $limit        Número de resultados (default 5)
     * @param float  $score_threshold Score mínimo de similitud (0-1, default 0.3)
     * @return array<array{text: string, role: string, score: float, created_at: int}>
     */
    public static function search(array $query_vector, int $user_id, int $limit = 5, float $score_threshold = 0.3): array {
        $url = self::base_url() . '/collections/' . self::COLLECTION . '/points/search';

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'vector'          => $query_vector,
                'limit'           => $limit * 3, // Pedir más para rerankear
                'with_payload'    => true,
                'score_threshold' => $score_threshold,
                'filter'          => [
                    'must' => [[
                        'key'   => 'user_id',
                        'match' => ['value' => $user_id],
                    ]],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            Logger::log('error', 'Qdrant search failed: ' . $response->get_error_message());
            return [];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $results = [];
        $now = time();

        foreach ($decoded['result'] ?? [] as $hit) {
            $payload = $hit['payload'] ?? [];
            $created_at = $payload['created_at'] ?? $now;
            
            // ── TIME-WEIGHTED RAG (Decaimiento Temporal) ──
            // Penaliza un 0.5% el score por cada día de antigüedad.
            // Recuerdos de hoy: score * 1.0. Hace 100 días: score * 0.60
            $days_old = max(0, ($now - $created_at) / 86400);
            $time_penalty = pow(0.995, $days_old);
            $adjusted_score = ($hit['score'] ?? 0) * $time_penalty;

            $results[] = [
                'text'       => $payload['text'] ?? '',
                'role'       => $payload['role'] ?? 'user',
                'raw_score'  => $hit['score'] ?? 0,
                'score'      => round($adjusted_score, 4),
                'created_at' => $created_at,
            ];
        }

        // Re-ordenar por el score penalizado por tiempo
        usort($results, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Devolver solo los $limit mejores
        return array_slice($results, 0, $limit);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GDPR / BORRADO
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Borra TODOS los vectores de un usuario (GDPR compliance).
     */
    public static function delete_user(int $user_id): bool {
        $url = self::base_url() . '/collections/' . self::COLLECTION . '/points/delete';

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'filter' => [
                    'must' => [[
                        'key'   => 'user_id',
                        'match' => ['value' => $user_id],
                    ]],
                ],
            ]),
        ]);

        if (is_wp_error($response)) return false;
        Logger::log('memory', "Qdrant: deleted vectors for user#{$user_id}");
        return wp_remote_retrieve_response_code($response) === 200;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // STATS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Obtiene info de la colección (total de puntos, segmentos, etc.).
     */
    public static function get_stats(): array {
        $url = self::base_url() . '/collections/' . self::COLLECTION;
        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            return ['available' => false, 'error' => $response->get_error_message()];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $result  = $decoded['result'] ?? [];

        return [
            'available'    => true,
            'points_count' => $result['points_count'] ?? 0,
            'segments'     => count($result['segments'] ?? []),
            'status'       => $result['status'] ?? 'unknown',
            'vectors_size' => $result['vectors_count'] ?? 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Genera un UUID v4 para usar como ID de punto en Qdrant.
     */
    private static function generate_uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
