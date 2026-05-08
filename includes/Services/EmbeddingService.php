<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * EmbeddingService — Genera embeddings de texto vía Ollama o OpenAI.
 *
 * Estrategia de fallback:
 *   1. Ollama local (nomic-embed-text, 768 dims) → $0, ultra rápido
 *   2. OpenAI API (text-embedding-3-small, 1536 dims) → $0.02/1M tokens
 *
 * Ollama API: POST /api/embed {"model":"nomic-embed-text","input":"texto"}
 * OpenAI API: POST /v1/embeddings {"model":"text-embedding-3-small","input":"texto"}
 */
class EmbeddingService {

    const OLLAMA_MODEL  = 'nomic-embed-text';
    const OPENAI_MODEL  = 'text-embedding-3-small';
    const DIMENSION     = 768; // nomic-embed-text default

    /**
     * Genera un embedding para un texto.
     *
     * @param string $text Texto a embeder
     * @return array|null Vector de floats [768] o null si falla
     */
    public static function embed(string $text): ?array {
        // Sanitizar y truncar
        $text = trim(strip_tags($text));
        if (mb_strlen($text) < 3) return null;
        $text = mb_substr($text, 0, 8000); // Límite razonable

        // Intentar Ollama primero (gratis)
        $vector = self::embed_ollama($text);
        if ($vector) return $vector;

        // Fallback a OpenAI
        $vector = self::embed_openai($text);
        if ($vector) return $vector;

        Logger::log('error', 'EmbeddingService: all providers failed');
        return null;
    }

    /**
     * Genera embeddings para múltiples textos (batch).
     *
     * @param array $texts Array de strings
     * @return array<array|null> Array de vectores (o null para fallos)
     */
    public static function embed_batch(array $texts): array {
        // Sanitizar
        $clean = array_map(function($t) {
            $t = trim(strip_tags($t));
            return mb_strlen($t) >= 3 ? mb_substr($t, 0, 8000) : '';
        }, $texts);

        // Intentar batch con Ollama
        $results = self::embed_ollama_batch($clean);
        if ($results) return $results;

        // Fallback: embeddings individuales con OpenAI
        $output = [];
        foreach ($clean as $text) {
            $output[] = !empty($text) ? self::embed_openai($text) : null;
        }
        return $output;
    }

    /**
     * Verifica si Ollama está disponible con el modelo de embeddings.
     */
    public static function is_ollama_available(): bool {
        $url = self::ollama_url() . '/api/tags';
        $response = wp_remote_get($url, ['timeout' => 3]);
        if (is_wp_error($response)) return false;

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $models = array_column($decoded['models'] ?? [], 'name');

        // Verificar que nomic-embed-text esté cargado
        foreach ($models as $name) {
            if (str_contains($name, self::OLLAMA_MODEL)) return true;
        }
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // OLLAMA (local, gratuito)
    // ═══════════════════════════════════════════════════════════════════════════

    private static function ollama_url(): string {
        return rtrim(get_option('tbot_ollama_url', 'http://localhost:11434'), '/');
    }

    private static function embed_ollama(string $text): ?array {
        $url = self::ollama_url() . '/api/embed';

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'model' => self::OLLAMA_MODEL,
                'input' => $text,
            ]),
        ]);

        if (is_wp_error($response)) return null;

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $embeddings = $decoded['embeddings'] ?? [];

        if (!empty($embeddings[0]) && is_array($embeddings[0])) {
            return $embeddings[0];
        }

        return null;
    }

    private static function embed_ollama_batch(array $texts): ?array {
        $url = self::ollama_url() . '/api/embed';

        // Filtrar vacíos
        $valid_indices = [];
        $valid_texts   = [];
        foreach ($texts as $i => $text) {
            if (!empty($text)) {
                $valid_indices[] = $i;
                $valid_texts[]   = $text;
            }
        }

        if (empty($valid_texts)) return null;

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'model' => self::OLLAMA_MODEL,
                'input' => $valid_texts,
            ]),
        ]);

        if (is_wp_error($response)) return null;

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $embeddings = $decoded['embeddings'] ?? [];

        if (empty($embeddings)) return null;

        // Reconstruir array con índices originales
        $results = array_fill(0, count($texts), null);
        foreach ($valid_indices as $j => $orig_idx) {
            $results[$orig_idx] = $embeddings[$j] ?? null;
        }

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // OPENAI (fallback, pagado)
    // ═══════════════════════════════════════════════════════════════════════════

    private static function embed_openai(string $text): ?array {
        $api_key = get_option('tbot_openai_api_key', '');
        if (!$api_key) return null;

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'      => self::OPENAI_MODEL,
                'input'      => $text,
                'dimensions' => self::DIMENSION, // Reducir a 768 para compatibilidad con Qdrant
            ]),
        ]);

        if (is_wp_error($response)) return null;

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return $decoded['data'][0]['embedding'] ?? null;
    }
}
