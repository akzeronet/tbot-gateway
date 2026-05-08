<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * AIService — Motor de IA nativo (reemplaza n8n).
 *
 * Llama directamente a las APIs de los proveedores configurados en AIModels.
 * Soporta: OpenAI, Anthropic, Google Gemini, Groq.
 *
 * Flujo:
 *  1. WebhookHandler determina modelo vía AIModels::get_config()
 *  2. AIService::chat() hace la llamada a la API correcta
 *  3. La respuesta se envía directamente al chat de Telegram
 *  4. Sin intermediarios (n8n eliminado del pipeline)
 *
 * Beneficios vs n8n:
 *  - Latencia ~50% menor (1 hop vs 2)
 *  - Control total de errores y reintentos
 *  - Sin dependencia de infraestructura externa
 *  - Streaming futuro posible
 */
class AIService {

    // Mapeo de modelo → proveedor (para auto-detect)
    const PROVIDER_MAP = [
        'gpt-'      => 'openai',
        'o4-'       => 'openai',
        'o3-'       => 'openai',
        'claude'    => 'anthropic',
        'gemini'    => 'google',
    ];

    // Modelos que van a Groq API (cuando NO hay self-hosted)
    const GROQ_MODELS = ['mixtral'];

    // Configuración de Smart Routing por tier
    const SMART_TIERS = [
        'simple'   => 'llama3.1:8b',              // FAQ, saludos, comandos
        'standard' => 'llama3.3:70b-instruct-q4_K_M', // Conversación general
        'premium'  => 'gpt-4o-mini',              // Razonamiento complejo
    ];

    /**
     * Envía un mensaje al modelo de IA y devuelve la respuesta.
     *
     * @param array $params {
     *   string  model           — ID del modelo (ej: gpt-4o, claude-3-7-sonnet-20250219)
     *   string  message         — Mensaje del usuario
     *   string  system_prompt   — Prompt del sistema / personalidad
     *   string  lang            — Idioma del usuario
     *   ?array  image           — Datos de imagen para visión {file_id, mime_type}
     *   ?array  voice           — Datos de voz {file_id, duration}
     *   int     max_tokens      — Máx tokens de respuesta (default 1024)
     * }
     * @return array{success: bool, text?: string, usage?: array, error?: string}
     */
    public static function chat(array $params): array {
        $model   = $params['model'] ?? 'gpt-4o-mini';
        $message = $params['message'] ?? '';
        $system  = $params['system_prompt'] ?? 'Eres un asistente útil y profesional.';

        // ── Self-hosted check: si el modelo es local, usar Ollama/vLLM ─────
        if (SelfHostedLLM::is_self_hosted_model($model)) {
            $result = SelfHostedLLM::chat($model, $message, $system, $params);
            if ($result['success']) return $result;

            Logger::log('ai_fallback', "Self-hosted failed for {$model}, falling back");
            $model = self::SMART_TIERS['premium'];
        }

        $provider = self::detect_provider($model);

        // Anthropic y Google tienen formato nativo (no OpenAI-compatible)
        if ($provider === 'anthropic') {
            return self::call_anthropic($model, $message, $system, $params);
        }
        if ($provider === 'google') {
            return self::call_google($model, $message, $system, $params);
        }

        // Todos los demás usan formato OpenAI → cascading a través de ProviderRegistry
        $messages = [['role' => 'system', 'content' => $system]];
        if (!empty($params['history'])) {
            foreach ($params['history'] as $h) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }
        if (!empty($params['rag_context'])) {
            $messages[] = ['role' => 'system', 'content' => "[Contexto relevante]\n" . $params['rag_context']];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $opts = [
            'max_tokens'  => $params['max_tokens'] ?? 1024,
            'temperature' => $params['temperature'] ?? 0.7,
        ];

        $tools = ToolRegistry::get_active_tools();
        if (!empty($tools)) {
            $opts['tools'] = $tools;
        }

        // Bucle recursivo para Tool Calling (max 3 iteraciones)
        $max_iterations = 3;
        for ($i = 0; $i < $max_iterations; $i++) {
            $result = ProviderRegistry::cascade($model, $messages, $opts);
            
            if (!$result['success']) return $result;
            
            $res_msg = $result['message'] ?? [];
            if (empty($res_msg['tool_calls'])) {
                // Respuesta final sin tool calls
                return $result;
            }
            
            // 1. Añadir el mensaje del asistente con los tool_calls al historial de peticiones
            $messages[] = [
                'role'       => 'assistant',
                'content'    => $res_msg['content'] ?? null,
                'tool_calls' => $res_msg['tool_calls']
            ];
            
            // 2. Ejecutar las herramientas y añadir los resultados al historial
            foreach ($res_msg['tool_calls'] as $tool_call) {
                $tool_name = $tool_call['function']['name'] ?? '';
                $args_str  = $tool_call['function']['arguments'] ?? '{}';
                $args      = json_decode($args_str, true) ?: [];
                
                $tool_result = ToolRegistry::execute($tool_name, $args);
                
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tool_call['id'],
                    'content'      => (string) $tool_result
                ];
            }
            // 3. El for-loop continúa y vuelve a hacer ProviderRegistry::cascade con el contexto actualizado
        }

        return ['success' => false, 'error' => 'Exceeded max tool iterations'];
    }

    /**
     * Procesa un mensaje completo: memoria + IA + respuesta a Telegram.
     * Pipeline: cargar contexto → llamar IA → guardar en memoria → responder.
     */
    public static function process_and_reply(array $context): void {
        $tg         = new TelegramService($context['bot_token']);
        $chat_id    = $context['chat_id'];
        $model      = $context['model'];
        $text       = $context['text'] ?? '';
        $lang       = $context['lang'] ?? 'es';
        $user_id    = $context['wp_user_id'];
        $operation  = $context['operation'] ?? 'text_basic';
        $plan       = $context['subscription'] ?? 'free';

        // ── CORE PERSONA: Construir el System Prompt ──
        $bot_persona = get_option('tbot_bot_persona', 'Eres un asistente útil y profesional.');
        $system      = $context['persona_prompt'] ?: $bot_persona;
        
        // Inyectar identidad del usuario (JSON extraído previamente)
        $user_persona = get_user_meta($user_id, 'tbot_user_persona', true);
        if (is_array($user_persona) && !empty($user_persona)) {
            $user_persona_json = json_encode($user_persona, JSON_UNESCAPED_UNICODE);
            $system .= "\n\n[DATOS FIJOS DEL USUARIO:\n{$user_persona_json}\n(Usa estos datos implícitamente, no menciones que los leíste de un JSON)]";
        }

        // Mostrar "escribiendo..." mientras procesamos
        $tg->send_chat_action($chat_id, 'typing');

        // Manejar voz: descargar y transcribir
        if ($operation === 'voice' && !empty($context['voice'])) {
            $voice_text = self::transcribe_voice($context['voice'], $context['bot_token']);
            if ($voice_text) {
                $text = $voice_text;
            } else {
                $err = match($lang) {
                    'en' => '❌ Could not transcribe the voice note. Please try again.',
                    default => '❌ No se pudo transcribir la nota de voz. Intenta de nuevo.',
                };
                $tg->send_message($chat_id, $err, 'HTML');
                CreditManager::refund($user_id, $operation, 'transcription_failed');
                return;
            }
        }

        // Manejar foto: obtener URL para visión
        $image_url = null;
        if ($operation === 'photo' && !empty($context['photo'])) {
            $photos = $context['photo'];
            $largest = end($photos);
            $file_id = $largest['file_id'] ?? '';
            if ($file_id) {
                $image_url = self::get_telegram_file_url($file_id, $context['bot_token']);
            }
        }

        // ── Memoria: cargar historial de conversación ─────────────────────
        $history = ChatMemory::get_context($user_id, $plan);

        // ── RAG: buscar contexto relevante en memoria vectorial (Qdrant) ────
        $rag_context = '';
        if (VectorMemory::is_available() && mb_strlen($text) > 10) {
            $query_vector = EmbeddingService::embed($text);
            if ($query_vector) {
                $relevant = VectorMemory::search($query_vector, $user_id, 5, 0.35);
                if (!empty($relevant)) {
                    $fragments = [];
                    foreach ($relevant as $r) {
                        $ago = human_time_diff($r['created_at'], time());
                        $role_label = $r['role'] === 'user' ? 'Usuario' : 'Asistente';
                        $fragments[] = "[{$ago} atrás] {$role_label}: {$r['text']}";
                    }
                    $rag_context = implode("\n", $fragments);
                }
            }
        }

        // ── L3: Deep recall de ClickHouse (30-180 días) ──────────────────────
        if (ClickHouseMemory::is_available() && mb_strlen($text) > 30) {
            // Solo activar deep recall para preguntas que parecen referir al pasado
            if (self::needs_deep_recall($text)) {
                $deep = ClickHouseMemory::search($user_id, $text, 3);
                if (!empty($deep)) {
                    $deep_frags = [];
                    foreach ($deep as $d) {
                        $role_label = ($d['role'] ?? 'user') === 'user' ? 'Usuario' : 'Asistente';
                        $deep_frags[] = "[archivo] {$role_label}: " . mb_substr($d['content'] ?? '', 0, 500);
                    }
                    $rag_context .= "\n" . implode("\n", $deep_frags);
                }
            }
        }

        // Guardar mensaje del usuario ANTES de llamar a la IA
        ChatMemory::save_user_message($user_id, $text);

        // ── Smart Route: elegir modelo según complejidad ───────────────────
        $use_self_hosted = (bool) get_option('tbot_use_self_hosted', false);
        if ($use_self_hosted && SelfHostedLLM::is_available()) {
            $model = self::smart_route($text, $plan);
        }

        // Construir params para la IA (con historial + RAG)
        $ai_params = [
            'model'         => $model,
            'message'       => $text,
            'system_prompt' => $system,
            'lang'          => $lang,
            'history'       => $history,
            'rag_context'   => $rag_context,
            'max_tokens'    => in_array($operation, ['text_advanced', 'photo']) ? 2048 : 1024,
        ];

        if ($image_url) {
            $ai_params['image_url'] = $image_url;
            // Visión requiere modelos con soporte — forzar API si es self-hosted
            if (SelfHostedLLM::is_self_hosted_model($model)) {
                $ai_params['model'] = 'gpt-4o-mini'; // Fallback a API para visión
            }
        }

        // Llamar a la IA
        $result = self::chat($ai_params);

        if (!$result['success']) {
            Logger::log('error', "AI call failed: model={$model}, error=" . ($result['error'] ?? 'unknown'));
            $err_msg = match($lang) {
                'en' => "⚠️ I couldn't process your message right now. Please try again in a moment.",
                default => "⚠️ No pude procesar tu mensaje ahora. Intenta de nuevo en un momento.",
            };
            $tg->send_message($chat_id, $err_msg, 'HTML');
            CreditManager::refund($user_id, $model, 'ai_error');
            return;
        }

        // Enviar la respuesta
        $reply = $result['text'] ?? '';
        if (empty($reply)) {
            $reply = match($lang) {
                'en' => "🤔 I received an empty response. Try rephrasing your question.",
                default => "🤔 Recibí una respuesta vacía. Intenta reformular tu pregunta.",
            };
        }

        // ── Memoria: guardar respuesta del asistente ──────────────────────────
        $tokens_used = ($result['usage']['total_tokens'] ?? 0);
        ChatMemory::save_assistant_message($user_id, $reply, $tokens_used, $model);

        // Auto-resumir si el historial es muy largo
        ChatMemory::maybe_summarize($user_id, $plan);

        // ── Memoria vectorial: guardar en Qdrant para búsqueda futura ──────
        if (VectorMemory::is_available()) {
            if (VectorMemory::should_vectorize($text, 'user')) {
                $user_vector = EmbeddingService::embed($text);
                if ($user_vector) VectorMemory::store($user_id, 'user', $text, $user_vector);
            }
            if (VectorMemory::should_vectorize($reply, 'assistant')) {
                $reply_vector = EmbeddingService::embed($reply);
                if ($reply_vector) VectorMemory::store($user_id, 'assistant', $reply, $reply_vector);
            }
        }

        // Telegram limita mensajes a 4096 chars — dividir si es necesario
        $chunks = self::split_message($reply, 4000);
        foreach ($chunks as $chunk) {
            $tg->send_message($chat_id, $chunk, 'Markdown');
        }

        // Log de uso
        Logger::log('ai_response', "user#{$user_id} model={$model} tokens={$tokens_used}");

        // Actualizar last_active en UserState
        UserState::set($user_id, ['last_active' => time()]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PROVEEDORES
    // ═══════════════════════════════════════════════════════════════════════════

    private static function call_openai(string $model, string $message, string $system, array $params): array {
        $api_key = get_option('tbot_openai_api_key', '');
        if (!$api_key) return ['success' => false, 'error' => 'openai_api_key_not_set'];

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        // Inyectar historial de conversación (memoria)
        if (!empty($params['history'])) {
            foreach ($params['history'] as $h) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }

        // Visión: mensaje con imagen
        if (!empty($params['image_url'])) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text',      'text' => $message ?: 'Describe esta imagen.'],
                    ['type' => 'image_url', 'image_url' => ['url' => $params['image_url']]],
                ],
            ];
        } else {
            $messages[] = ['role' => 'user', 'content' => $message];
        }

        $body = [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $params['max_tokens'] ?? 1024,
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        return self::parse_openai_response($response);
    }

    private static function call_anthropic(string $model, string $message, string $system, array $params): array {
        $api_key = get_option('tbot_anthropic_api_key', '');
        if (!$api_key) return ['success' => false, 'error' => 'anthropic_api_key_not_set'];

        $content = [];
        if (!empty($params['image_url'])) {
            // Descargar imagen y convertir a base64 (Anthropic requiere inline)
            $img_data = wp_remote_get($params['image_url'], ['timeout' => 10]);
            if (!is_wp_error($img_data)) {
                $img_body = wp_remote_retrieve_body($img_data);
                $mime = wp_remote_retrieve_header($img_data, 'content-type') ?: 'image/jpeg';
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mime,
                        'data' => base64_encode($img_body),
                    ],
                ];
            }
        }
        $content[] = ['type' => 'text', 'text' => $message ?: 'Describe esta imagen.'];

        // Construir mensajes multi-turno con historial
        $api_messages = [];
        if (!empty($params['history'])) {
            foreach ($params['history'] as $h) {
                $api_messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }
        $api_messages[] = ['role' => 'user', 'content' => $content];

        $body = [
            'model'      => $model,
            'max_tokens' => $params['max_tokens'] ?? 1024,
            'system'     => $system,
            'messages'   => $api_messages,
        ];

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($decoded['content'][0]['text'])) {
            return [
                'success' => true,
                'text'    => $decoded['content'][0]['text'],
                'usage'   => [
                    'total_tokens' => ($decoded['usage']['input_tokens'] ?? 0) + ($decoded['usage']['output_tokens'] ?? 0),
                ],
            ];
        }

        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'anthropic_error'];
    }

    private static function call_google(string $model, string $message, string $system, array $params): array {
        $api_key = get_option('tbot_google_api_key', '');
        if (!$api_key) return ['success' => false, 'error' => 'google_api_key_not_set'];

        $parts = [['text' => $message ?: 'Describe esta imagen.']];

        if (!empty($params['image_url'])) {
            $img_data = wp_remote_get($params['image_url'], ['timeout' => 10]);
            if (!is_wp_error($img_data)) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => wp_remote_retrieve_header($img_data, 'content-type') ?: 'image/jpeg',
                        'data'      => base64_encode(wp_remote_retrieve_body($img_data)),
                    ],
                ];
            }
        }

        $body = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [['parts' => $parts]],
            'generationConfig'   => ['maxOutputTokens' => $params['max_tokens'] ?? 1024],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text) {
            $usage = $decoded['usageMetadata'] ?? [];
            return [
                'success' => true,
                'text'    => $text,
                'usage'   => ['total_tokens' => ($usage['promptTokenCount'] ?? 0) + ($usage['candidatesTokenCount'] ?? 0)],
            ];
        }

        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'google_error'];
    }

    private static function call_groq(string $model, string $message, string $system, array $params): array {
        $api_key = get_option('tbot_groq_api_key', '');
        if (!$api_key) return ['success' => false, 'error' => 'groq_api_key_not_set'];

        $body = [
            'model'      => $model,
            'messages'   => [
                ['role' => 'system',  'content' => $system],
            ],
            'max_tokens' => $params['max_tokens'] ?? 1024,
        ];

        // Inyectar historial
        if (!empty($params['history'])) {
            foreach ($params['history'] as $h) {
                $body['messages'][] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }
        $body['messages'][] = ['role' => 'user', 'content' => $message];

        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
            'timeout' => 15, // Groq es ultra rápido
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        return self::parse_openai_response($response); // Groq usa formato OpenAI
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // VOZ — Transcripción directa con Whisper
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Descarga un voice note de Telegram y lo transcribe con Whisper.
     */
    private static function transcribe_voice(array $voice_data, string $bot_token): ?string {
        $file_id = $voice_data['file_id'] ?? '';
        if (!$file_id) return null;

        // 1. Obtener URL del archivo de Telegram
        $file_url = self::get_telegram_file_url($file_id, $bot_token);
        if (!$file_url) return null;

        // 2. Descargar el archivo de voz
        $audio_data = wp_remote_get($file_url, ['timeout' => 15]);
        if (is_wp_error($audio_data)) return null;

        $audio_body = wp_remote_retrieve_body($audio_data);
        $tmp_file   = wp_tempnam('tbot_voice_') . '.ogg';
        file_put_contents($tmp_file, $audio_body);

        // 3. Enviar a Whisper API
        $api_key = get_option('tbot_openai_api_key', '');
        if (!$api_key) { @unlink($tmp_file); return null; }

        $boundary = wp_generate_password(24, false);
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\nwhisper-1\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"voice.ogg\"\r\n";
        $body .= "Content-Type: audio/ogg\r\n\r\n";
        $body .= $audio_body . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post('https://api.openai.com/v1/audio/transcriptions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => "multipart/form-data; boundary={$boundary}",
            ],
            'body' => $body,
        ]);

        @unlink($tmp_file);

        if (is_wp_error($response)) return null;
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return $decoded['text'] ?? null;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    private static function detect_provider(string $model): string {
        foreach (self::PROVIDER_MAP as $prefix => $provider) {
            if (str_starts_with($model, $prefix)) return $provider;
        }
        // Check Groq-only models
        foreach (self::GROQ_MODELS as $groq_model) {
            if (str_starts_with($model, $groq_model)) return 'groq';
        }
        return 'openai'; // fallback
    }

    private static function parse_openai_response($response): array {
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($decoded['choices'][0]['message']['content'])) {
            return [
                'success' => true,
                'text'    => $decoded['choices'][0]['message']['content'],
                'usage'   => $decoded['usage'] ?? [],
            ];
        }
        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'api_error'];
    }

    /**
     * Obtiene la URL directa de un archivo de Telegram por su file_id.
     */
    private static function get_telegram_file_url(string $file_id, string $bot_token): ?string {
        $response = wp_remote_get("https://api.telegram.org/bot{$bot_token}/getFile?file_id={$file_id}", [
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) return null;
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $path = $decoded['result']['file_path'] ?? '';
        return $path ? "https://api.telegram.org/file/bot{$bot_token}/{$path}" : null;
    }

    /**
     * Divide un mensaje largo en chunks de máximo $max_len caracteres,
     * respetando saltos de línea para no cortar mid-sentence.
     */
    private static function split_message(string $text, int $max_len = 4000): array {
        if (mb_strlen($text) <= $max_len) return [$text];

        $chunks = [];
        while (mb_strlen($text) > $max_len) {
            $cut = mb_strrpos(mb_substr($text, 0, $max_len), "\n");
            if ($cut === false || $cut < $max_len * 0.5) {
                $cut = $max_len;
            }
            $chunks[] = mb_substr($text, 0, $cut);
            $text = mb_substr($text, $cut);
        }
        if ($text) $chunks[] = $text;
        return $chunks;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SMART ROUTING — Clasificación de complejidad
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Clasifica la query por complejidad y asigna el modelo self-hosted apropiado.
     *
     * Tier 1 (Simple):   saludos, FAQs, comandos cortos → 8B (ultra rápido)
     * Tier 2 (Standard): conversación general, análisis → 70B (potente)
     * Tier 3 (Premium):  código, razonamiento, multi-step → API externa
     *
     * @param string $text Texto del usuario
     * @param string $plan Plan de suscripción del usuario
     * @return string Nombre del modelo a usar
     */
    public static function smart_route(string $text, string $plan = 'free'): string {
        // Admins siempre usan el modelo configurado en admin
        if ($plan === 'admin') {
            $cfg = \TBot\Admin\AIModels::get_config();
            return $cfg['model'] ?? self::SMART_TIERS['standard'];
        }

        $len = mb_strlen($text);

        // ── Tier 1: Simple (8B) ──────────────────────────────────────────────
        // Mensajes cortos, saludos, emojis, preguntas simples
        if ($len < 30) {
            return self::SMART_TIERS['simple'];
        }

        // Patrones de saludos / FAQ
        $simple_patterns = [
            '/^(hola|hi|hey|buenos?\s+d[ií]as?|buenas?\s+(tardes?|noches?)|hello|howdy)/iu',
            '/^(gracias|thanks|ok|vale|genial|perfecto|listo|entendido|claro)/iu',
            '/^(qu[ée]\s+(es|son|hora|d[ií]a|fecha|clima|tiempo))/iu',
            '/^[\p{So}\p{Sk}\s]{1,10}$/u', // Solo emojis
        ];
        foreach ($simple_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return self::SMART_TIERS['simple'];
            }
        }

        // ── Tier 3: Premium (API) ────────────────────────────────────────────
        // Código, matemáticas, razonamiento complejo, multi-step
        $premium_patterns = [
            '/```[\s\S]+```/',                          // Code blocks
            '/\b(function|class|def|import|require|SELECT|INSERT)\b/',  // Código
            '/\b(explica\s+paso\s+a\s+paso|step\s+by\s+step)\b/i',     // Multi-step
            '/\b(analiza|compara|evalúa|diseña|optimiza|refactoriza)\b/i', // Análisis profundo
            '/\b(escribe\s+un\s+(ensayo|artículo|informe|código|script))\b/i',
        ];
        foreach ($premium_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                // Premium users get API models, others get 70B self-hosted
                return ($plan === 'premium') ? self::SMART_TIERS['premium'] : self::SMART_TIERS['standard'];
            }
        }

        // ── Tier 2: Standard (70B) ───────────────────────────────────────────
        // Todo lo demás: conversación general, preguntas, discusión
        return self::SMART_TIERS['standard'];
    }

    /**
     * Detecta si el usuario está preguntando sobre conversaciones pasadas.
     * En ese caso, activar deep recall desde ClickHouse (L3).
     */
    private static function needs_deep_recall(string $text): bool {
        $patterns = [
            '/\b(recuerdas?|remember|acordar|acuerdas?)\b/iu',
            '/\b(la\s+vez\s+que|aquella\s+vez|antes\s+me)\b/iu',
            '/\b(dijiste|mencionaste|sugeriste|recomendaste)\b/iu',
            '/\b(hace\s+(\d+\s+)?(días?|semanas?|meses?))\b/iu',
            '/\b(el\s+otro\s+día|la\s+semana\s+pasada|el\s+mes\s+pasado)\b/iu',
            '/\b(historial|history|previous|anteriormente)\b/iu',
            '/\b(what\s+did\s+(you|I)\s+(say|mention|suggest))\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) return true;
        }

        return false;
    }
}
