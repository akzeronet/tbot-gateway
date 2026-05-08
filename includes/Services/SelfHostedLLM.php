<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * SelfHostedLLM — Provider de chat para Ollama y vLLM locales.
 *
 * Usa el API compatible con OpenAI (`/v1/chat/completions`).
 * Tanto Ollama como vLLM exponen este endpoint.
 *
 * Modelos disponibles (configurables vía admin):
 *   - llama3.1:8b           → Rápido, ~50 tok/s (RTX 4090)
 *   - llama3.3:70b-instruct → Potente, ~15 tok/s (A100 80GB)
 *   - qwen2.5:72b           → Alternativa, excelente en español
 *   - mistral:7b            → Ligero, buena relación calidad/tamaño
 *
 * Se integra en AIService como un provider más (junto a openai, anthropic, etc.)
 */
class SelfHostedLLM {

    // Modelos self-hosted conocidos (para auto-detect del provider)
    const SELF_HOSTED_PREFIXES = [
        'llama',    // llama3.1:8b, llama3.3:70b
        'qwen',     // qwen2.5:72b
        'mistral',  // mistral:7b
        'phi',      // phi-3
        'gemma',    // gemma2:9b
        'codellama', // codellama:34b
        'deepseek', // deepseek-coder
    ];

    /**
     * Determina si un modelo debería ejecutarse en self-hosted.
     */
    public static function is_self_hosted_model(string $model): bool {
        // Si tiene ":" es formato Ollama (ej: llama3.1:8b)
        if (str_contains($model, ':')) return true;

        foreach (self::SELF_HOSTED_PREFIXES as $prefix) {
            if (str_starts_with(strtolower($model), $prefix)) return true;
        }

        return false;
    }

    /**
     * Llama al modelo local vía API OpenAI-compatible.
     *
     * @param string $model   Nombre del modelo (ej: llama3.1:8b)
     * @param string $message Mensaje del usuario
     * @param string $system  System prompt
     * @param array  $params  Params adicionales (history, max_tokens, etc.)
     * @return array{success: bool, text?: string, usage?: array, error?: string}
     */
    public static function chat(string $model, string $message, string $system, array $params = []): array {
        $base_url = self::resolve_url($model);

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        // Inyectar historial de conversación
        if (!empty($params['history'])) {
            foreach ($params['history'] as $h) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }

        // Inyectar contexto RAG (de Qdrant)
        if (!empty($params['rag_context'])) {
            $rag_text = "[Contexto relevante de conversaciones anteriores]\n" . $params['rag_context'];
            $messages[] = ['role' => 'system', 'content' => $rag_text];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $params['max_tokens'] ?? 1024,
            'temperature' => $params['temperature'] ?? 0.7,
            'stream'      => false,
        ];

        $response = wp_remote_post($base_url . '/v1/chat/completions', [
            'timeout' => 60, // Modelos grandes pueden tardar
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ollama', // Requerido pero ignorado por Ollama
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => 'self_hosted_unreachable: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body_str = wp_remote_retrieve_body($response);
            return [
                'success' => false,
                'error'   => "self_hosted_error_{$code}: " . mb_substr($body_str, 0, 200),
            ];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($decoded['choices'][0]['message']['content'])) {
            $usage = $decoded['usage'] ?? [];
            return [
                'success'  => true,
                'text'     => $decoded['choices'][0]['message']['content'],
                'provider' => 'self_hosted',
                'model'    => $model,
                'usage'    => [
                    'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                    'completion_tokens' => $usage['completion_tokens'] ?? 0,
                    'total_tokens'      => $usage['total_tokens'] ?? 0,
                ],
            ];
        }

        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'self_hosted_empty_response'];
    }

    /**
     * Verifica si el servidor self-hosted está disponible.
     */
    public static function is_available(): bool {
        $url = self::ollama_url() . '/api/tags';
        $response = wp_remote_get($url, ['timeout' => 3]);
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Lista los modelos disponibles en Ollama.
     *
     * @return array<array{name: string, size: int, modified_at: string}>
     */
    public static function list_models(): array {
        $url = self::ollama_url() . '/api/tags';
        $response = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response)) return [];

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return $decoded['models'] ?? [];
    }

    /**
     * Obtiene estadísticas del servidor (modelos cargados, VRAM, etc.).
     */
    public static function get_status(): array {
        $available = self::is_available();
        $models    = $available ? self::list_models() : [];

        // Verificar vLLM también
        $vllm_url = self::vllm_url();
        $vllm_ok  = false;
        if ($vllm_url) {
            $vllm_check = wp_remote_get($vllm_url . '/v1/models', ['timeout' => 3]);
            $vllm_ok = !is_wp_error($vllm_check) && wp_remote_retrieve_response_code($vllm_check) === 200;
        }

        return [
            'ollama_available'  => $available,
            'ollama_models'     => array_column($models, 'name'),
            'ollama_model_count'=> count($models),
            'vllm_available'    => $vllm_ok,
            'vllm_url'          => $vllm_url,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // URL RESOLUTION
    // ═══════════════════════════════════════════════════════════════════════════

    private static function ollama_url(): string {
        return rtrim(get_option('tbot_ollama_url', 'http://localhost:11434'), '/');
    }

    private static function vllm_url(): string {
        return rtrim(get_option('tbot_vllm_url', ''), '/');
    }

    /**
     * Resuelve qué servidor usar según el modelo.
     * Si hay un vLLM configurado y el modelo es 70B+, usar vLLM (mejor throughput).
     * Si no, usar Ollama.
     */
    private static function resolve_url(string $model): string {
        $vllm = self::vllm_url();

        // Si vLLM está configurado y el modelo es grande, preferir vLLM
        if ($vllm && (str_contains($model, '70b') || str_contains($model, '72b'))) {
            return $vllm;
        }

        return self::ollama_url();
    }
}
