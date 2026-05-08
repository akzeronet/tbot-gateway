<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * ToolRegistry — Gestiona las herramientas (Functions) que la IA puede usar.
 * Ahora es Extensible: Otros plugins pueden añadir herramientas usando el filtro 'tbot_registered_tools'.
 */
class ToolRegistry {

    /**
     * Obtiene TODAS las herramientas disponibles en el ecosistema (Core + Añadidas por código).
     * Devuelve un array asociativo donde la key es el ID de la herramienta.
     */
    public static function get_available_tools(): array {
        $core_tools = [
            'fetch_url' => [
                'title' => '🌐 Lector de URLs',
                'desc' => 'Permite a la IA leer el contenido de cualquier enlace web que el usuario le comparta. Ideal para resumir artículos o noticias.',
                'color' => '#3b82f6',
                'schema' => [
                    'type' => 'function',
                    'function' => [
                        'name' => 'fetch_url',
                        'description' => 'Extracts readable text content from any public URL. Use this when the user asks you to read, summarize, or analyze a specific link.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => [
                                    'type' => 'string',
                                    'description' => 'The complete HTTP/HTTPS URL to fetch.'
                                ]
                            ],
                            'required' => ['url']
                        ]
                    ]
                ],
                'callback' => [self::class, 'tool_fetch_url']
            ],
            
            'google_search' => [
                'title' => '🔍 Búsqueda en Internet',
                'desc' => 'Permite a la IA buscar información actualizada en Google de manera autónoma cuando no sepa la respuesta.',
                'color' => '#f59e0b',
                'schema' => [
                    'type' => 'function',
                    'function' => [
                        'name' => 'google_search',
                        'description' => 'Searches the internet for real-time information, news, or facts you do not know. Returns text snippets of top search results.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => [
                                    'type' => 'string',
                                    'description' => 'The search query to look up on Google.'
                                ]
                            ],
                            'required' => ['query']
                        ]
                    ]
                ],
                'callback' => [self::class, 'tool_google_search']
            ]
        ];

        // Permitir a otros desarrolladores inyectar sus propias herramientas
        return apply_filters('tbot_registered_tools', $core_tools);
    }

    /**
     * Devuelve el array de herramientas ACTIVAS en formato OpenAI JSON Schema.
     * Solo incluye las que el usuario encendió en el panel de Admin.
     */
    public static function get_active_tools(): array {
        $tools = [];
        $available = self::get_available_tools();

        foreach ($available as $id => $tool) {
            // El admin registra cada opción como 'tbot_tool_{id}'
            if (get_option('tbot_tool_' . $id)) {
                if (isset($tool['schema'])) {
                    $tools[] = $tool['schema'];
                }
            }
        }

        return $tools;
    }

    /**
     * Ejecuta una herramienta específica basándose en su nombre y argumentos.
     */
    public static function execute(string $tool_name, array $args): string {
        $available = self::get_available_tools();

        // Buscar si existe una herramienta con este 'name' en el schema
        foreach ($available as $id => $tool) {
            $schema_name = $tool['schema']['function']['name'] ?? '';
            if ($schema_name === $tool_name) {
                if (is_callable($tool['callback'])) {
                    try {
                        return call_user_func($tool['callback'], $args);
                    } catch (\Exception $e) {
                        return "Error executing tool: " . $e->getMessage();
                    }
                }
                return "Error: Tool '{$tool_name}' has an invalid callback.";
            }
        }

        return "Error: Tool '{$tool_name}' is not registered or not supported.";
    }

    // ── Core Tool Implementations ─────────────────────────────────────────────

    public static function tool_fetch_url(array $args): string {
        $url = $args['url'] ?? '';
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return "Error: Invalid URL provided.";
        }

        $response = wp_remote_get($url, ['timeout' => 10, 'user-agent' => 'TBot-Gateway/2.0']);
        if (is_wp_error($response)) {
            return "Error fetching URL: " . $response->get_error_message();
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return "Error: The URL returned empty content.";
        }

        // Limpieza súper básica de HTML para extraer el texto
        $body = preg_replace('@<script[^>]*?>.*?</script>@si', '', $body);
        $body = preg_replace('@<style[^>]*?>.*?</style>@si', '', $body);
        $text = wp_strip_all_tags($body);
        
        // Truncar para no exceder contexto
        return mb_substr(trim($text), 0, 8000) . "... [Truncated]";
    }

    public static function tool_google_search(array $args): string {
        $query = $args['query'] ?? '';
        if (empty($query)) return "Error: Empty query.";

        $api_key = get_option('tbot_serper_api_key');
        
        // Si hay API key, usamos Serper (Google)
        if (!empty($api_key)) {
            $url = 'https://google.serper.dev/search';
            $response = wp_remote_post($url, [
                'headers' => [
                    'X-API-KEY' => $api_key,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode(['q' => $query, 'num' => 5]),
                'timeout' => 10
            ]);

            if (is_wp_error($response)) return "Error: " . $response->get_error_message();

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($data['organic'])) return "No results found.";

            $results = [];
            foreach (array_slice($data['organic'], 0, 5) as $idx => $item) {
                $title = $item['title'] ?? '';
                $snippet = $item['snippet'] ?? '';
                $link = $item['link'] ?? '';
                $results[] = ($idx+1) . ". {$title}\n{$snippet}\n(Source: {$link})";
            }
            return implode("\n\n", $results);
        }
        
        // Fallback: DuckDuckGo Lite (gratis, scraping básico)
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)']
        ]);

        if (is_wp_error($response)) return "Error fetching results.";
        $html = wp_remote_retrieve_body($response);
        
        // Scraping súper básico
        preg_match_all('/<a class="result__snippet[^>]+>(.*?)<\/a>/is', $html, $matches);
        if (empty($matches[1])) return "No results found.";

        $results = [];
        foreach (array_slice($matches[1], 0, 3) as $snippet) {
            $results[] = wp_strip_all_tags($snippet);
        }
        return implode("\n\n", $results);
    }
}
