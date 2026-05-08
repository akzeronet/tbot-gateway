<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * ProviderRegistry — Registro central de proveedores LLM.
 *
 * 9 providers organizados en 3 tiers:
 *   Self-hosted:  Ollama, vLLM            ($0/token)
 *   Serverless:   Groq, DeepInfra, Together, Fireworks  ($0.03-0.90/1M)
 *   Premium API:  OpenAI, Anthropic, Google             ($0.15-15.00/1M)
 *
 * Todos los serverless + self-hosted usan formato OpenAI-compatible.
 * Anthropic y Google tienen formato nativo (manejado en AIService).
 *
 * Estrategia de cascading fallback:
 *   Self-hosted → Serverless (por costo) → Premium API
 */
class ProviderRegistry {

    /**
     * Configuración de todos los providers.
     * Formato: id => [name, type, base_url, option_key, models_hint]
     */
    const PROVIDERS = [
        // ── Self-Hosted ──────────────────────────────────────────────
        'ollama' => [
            'name'     => 'Ollama (Local)',
            'type'     => 'self_hosted',
            'base_url' => 'tbot_ollama_url',     // wp_option key
            'api_key'  => null,                    // No requiere key
            'default_url' => 'http://localhost:11434',
            'format'   => 'openai',
        ],
        'vllm' => [
            'name'     => 'vLLM (Local)',
            'type'     => 'self_hosted',
            'base_url' => 'tbot_vllm_url',
            'api_key'  => null,
            'default_url' => '',
            'format'   => 'openai',
        ],

        // ── Serverless (OpenAI-compatible, baratos) ──────────────────
        'groq' => [
            'name'     => 'Groq',
            'type'     => 'serverless',
            'base_url' => null,
            'api_key'  => 'tbot_groq_api_key',
            'default_url' => 'https://api.groq.com/openai',
            'format'   => 'openai',
        ],
        'deepinfra' => [
            'name'     => 'DeepInfra',
            'type'     => 'serverless',
            'base_url' => null,
            'api_key'  => 'tbot_deepinfra_api_key',
            'default_url' => 'https://api.deepinfra.com',
            'format'   => 'openai',
        ],
        'together' => [
            'name'     => 'Together AI',
            'type'     => 'serverless',
            'base_url' => null,
            'api_key'  => 'tbot_together_api_key',
            'default_url' => 'https://api.together.xyz',
            'format'   => 'openai',
        ],
        'fireworks' => [
            'name'     => 'Fireworks AI',
            'type'     => 'serverless',
            'base_url' => null,
            'api_key'  => 'tbot_fireworks_api_key',
            'default_url' => 'https://api.fireworks.ai/inference',
            'format'   => 'openai',
        ],
        'omnirouter' => [
            'name'     => 'Universal Router (LiteLLM/OpenRouter)',
            'type'     => 'serverless',
            'base_url' => 'tbot_omnirouter_url',
            'api_key'  => 'tbot_omnirouter_api_key',
            'default_url' => 'https://openrouter.ai/api',
            'format'   => 'openai',
        ],

        // ── Premium APIs ─────────────────────────────────────────────
        'openai' => [
            'name'     => 'OpenAI',
            'type'     => 'premium',
            'base_url' => null,
            'api_key'  => 'tbot_openai_api_key',
            'default_url' => 'https://api.openai.com',
            'format'   => 'openai',
        ],
        'anthropic' => [
            'name'     => 'Anthropic',
            'type'     => 'premium',
            'base_url' => null,
            'api_key'  => 'tbot_anthropic_api_key',
            'default_url' => 'https://api.anthropic.com',
            'format'   => 'anthropic',  // Formato propio
        ],
        'google' => [
            'name'     => 'Google AI',
            'type'     => 'premium',
            'base_url' => null,
            'api_key'  => 'tbot_google_api_key',
            'default_url' => 'https://generativelanguage.googleapis.com',
            'format'   => 'google',     // Formato propio
        ],
    ];

    /**
     * Orden de prioridad para cascading fallback por tipo.
     * Self-hosted primero (gratis), luego serverless (barato), luego premium.
     */
    const CASCADE_ORDER = [
        'ollama', 'vllm',                           // Self-hosted ($0)
        'omnirouter',                               // Universal Router (si está config, asume que es la mejor vía)
        'deepinfra', 'groq', 'together', 'fireworks', // Serverless (barato)
        'openai', 'anthropic', 'google',              // Premium
    ];

    // ═══════════════════════════════════════════════════════════════════════════
    // CONSULTAS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Devuelve todos los providers con su estado actual.
     */
    public static function get_all(): array {
        $result = [];
        foreach (self::PROVIDERS as $id => $cfg) {
            $result[$id] = array_merge($cfg, [
                'id'        => $id,
                'available' => self::is_available($id),
            ]);
        }
        return $result;
    }

    /**
     * Devuelve solo los providers disponibles (con key o self-hosted online).
     */
    public static function get_available(): array {
        return array_filter(self::get_all(), fn($p) => $p['available']);
    }

    /**
     * Verifica si un provider específico está disponible.
     */
    public static function is_available(string $id): bool {
        $cfg = self::PROVIDERS[$id] ?? null;
        if (!$cfg) return false;

        // Self-hosted: verificar que el servidor responde
        if ($cfg['type'] === 'self_hosted') {
            $url = self::get_base_url($id);
            if (!$url) return false;
            $check = wp_remote_get($url . '/v1/models', ['timeout' => 2]);
            return !is_wp_error($check) && wp_remote_retrieve_response_code($check) === 200;
        }

        // API providers: verificar que hay API key configurada
        if ($cfg['api_key']) {
            return !empty(get_option($cfg['api_key'], ''));
        }

        return false;
    }

    /**
     * Obtiene la base URL de un provider.
     */
    public static function get_base_url(string $id): string {
        $cfg = self::PROVIDERS[$id] ?? null;
        if (!$cfg) return '';

        // Si tiene URL configurable en opciones
        if ($cfg['base_url']) {
            $url = get_option($cfg['base_url'], $cfg['default_url']);
            return rtrim($url ?: $cfg['default_url'], '/');
        }

        return rtrim($cfg['default_url'], '/');
    }

    /**
     * Obtiene la API key de un provider.
     */
    public static function get_api_key(string $id): string {
        $cfg = self::PROVIDERS[$id] ?? null;
        if (!$cfg || !$cfg['api_key']) return 'ollama'; // Self-hosted no necesita key real
        return get_option($cfg['api_key'], '');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // LLAMADAS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Llama a un provider OpenAI-compatible específico.
     *
     * Funciona con: Ollama, vLLM, Groq, DeepInfra, Together, Fireworks, OpenAI.
     * NO funciona con: Anthropic, Google (tienen formato propio en AIService).
     *
     * @return array{success: bool, text?: string, usage?: array, error?: string, provider?: string}
     */
    public static function call(string $provider_id, string $model, array $messages, array $opts = []): array {
        $cfg = self::PROVIDERS[$provider_id] ?? null;
        if (!$cfg) return ['success' => false, 'error' => "Unknown provider: {$provider_id}"];

        // Anthropic y Google tienen formato propio
        if ($cfg['format'] !== 'openai') {
            return ['success' => false, 'error' => "Provider {$provider_id} requires native format"];
        }

        $base_url = self::get_base_url($provider_id);
        $api_key  = self::get_api_key($provider_id);

        if (!$base_url) return ['success' => false, 'error' => "{$provider_id}_url_not_set"];

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $opts['max_tokens'] ?? 1024,
            'temperature' => $opts['temperature'] ?? 0.7,
            'stream'      => false,
        ];

        if (!empty($opts['tools'])) {
            $body['tools'] = $opts['tools'];
            $body['tool_choice'] = 'auto';
        }

        $response = wp_remote_post($base_url . '/v1/chat/completions', [
            'timeout' => $opts['timeout'] ?? 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $provider_id . ': ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $err_body = wp_remote_retrieve_body($response);
            $decoded = json_decode($err_body, true);
            $err_msg = $decoded['error']['message'] ?? mb_substr($err_body, 0, 200);
            return ['success' => false, 'error' => "{$provider_id}_http_{$code}: {$err_msg}"];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $res_msg = $decoded['choices'][0]['message'] ?? [];

        if (!empty($res_msg['content']) || !empty($res_msg['tool_calls'])) {
            return [
                'success'  => true,
                'message'  => $res_msg,
                'text'     => $res_msg['content'] ?? '',
                'provider' => $provider_id,
                'model'    => $model,
                'usage'    => [
                    'prompt_tokens'     => $decoded['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $decoded['usage']['completion_tokens'] ?? 0,
                    'total_tokens'      => $decoded['usage']['total_tokens'] ?? 0,
                ],
            ];
        }

        return ['success' => false, 'error' => $decoded['error']['message'] ?? "{$provider_id}_empty_response"];
    }

    /**
     * Cascading fallback: intenta providers en orden hasta que uno funcione.
     *
     * @param string $model    Modelo deseado
     * @param array  $messages Array de mensajes [{role, content}, ...]
     * @param array  $opts     Opciones adicionales
     * @param array  $preferred_order Orden personalizado (default: CASCADE_ORDER)
     * @return array{success: bool, text?: string, provider?: string, ...}
     */
    public static function cascade(string $model, array $messages, array $opts = [], array $preferred_order = []): array {
        $order = !empty($preferred_order) ? $preferred_order : self::CASCADE_ORDER;
        $errors = [];

        foreach ($order as $provider_id) {
            if (!self::is_available($provider_id)) continue;

            $cfg = self::PROVIDERS[$provider_id] ?? null;
            if (!$cfg) continue;

            // Saltar providers con formato propio (Anthropic/Google se manejan en AIService)
            if ($cfg['format'] !== 'openai') continue;

            $result = self::call($provider_id, $model, $messages, $opts);

            if ($result['success']) {
                Logger::log('ai_route', "Cascade OK: provider={$provider_id} model={$model}");
                return $result;
            }

            $errors[] = "{$provider_id}: " . ($result['error'] ?? 'unknown');
            Logger::log('ai_fallback', "Cascade skip {$provider_id}: " . ($result['error'] ?? 'unknown'));
        }

        return [
            'success' => false,
            'error'   => 'All providers failed: ' . implode(' | ', $errors),
        ];
    }

    /**
     * Resumen rápido para el panel de admin.
     */
    public static function get_status_summary(): array {
        $summary = ['total' => 0, 'available' => 0, 'by_type' => []];

        foreach (self::PROVIDERS as $id => $cfg) {
            $summary['total']++;
            $available = self::is_available($id);
            if ($available) $summary['available']++;

            $type = $cfg['type'];
            if (!isset($summary['by_type'][$type])) {
                $summary['by_type'][$type] = ['total' => 0, 'available' => 0, 'providers' => []];
            }
            $summary['by_type'][$type]['total']++;
            if ($available) $summary['by_type'][$type]['available']++;
            $summary['by_type'][$type]['providers'][] = [
                'id'        => $id,
                'name'      => $cfg['name'],
                'available' => $available,
            ];
        }

        return $summary;
    }
}
