<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * ImageGenerator — Generación de imágenes vía DALL·E 3 o Stability AI.
 *
 * Operación premium: consume créditos tipo 'image_gen'.
 * El prompt se envía a la API de OpenAI Images (DALL·E 3) o
 * a la API de Stability AI directamente.
 *
 * Flujo:
 *   1. Usuario envía /imagine <prompt>
 *   2. Se deduce créditos (operación costosa)
 *   3. Se genera la imagen
 *   4. Se envía como foto al chat de Telegram
 */
class ImageGenerator {

    const OPERATION = 'image_gen';

    // Configuración por defecto
    const DEFAULTS = [
        'provider'    => 'openai',     // openai | stability
        'model'       => 'dall-e-3',   // dall-e-3 | dall-e-2 | stable-diffusion-xl
        'size'        => '1024x1024',  // 1024x1024 | 1792x1024 | 1024x1792
        'quality'     => 'standard',   // standard | hd
        'style'       => 'vivid',      // vivid | natural
    ];

    /**
     * Genera una imagen a partir de un prompt.
     *
     * @return array{success: bool, url?: string, error?: string}
     */
    public static function generate(string $prompt, int $user_id, array $opts = []): array {
        $cfg      = array_merge(self::DEFAULTS, $opts);
        $provider = get_option('tbot_image_provider', $cfg['provider']);

        // Validar créditos antes de llamar a la API
        if (!CreditManager::can_afford($user_id, self::OPERATION)) {
            return ['success' => false, 'error' => 'insufficient_credits'];
        }

        // Sanitizar prompt
        $prompt = self::sanitize_prompt($prompt);
        if (mb_strlen($prompt) < 3) {
            return ['success' => false, 'error' => 'prompt_too_short'];
        }

        // Deducir créditos ANTES de generar (si falla, se reembolsará)
        CreditManager::deduct($user_id, self::OPERATION);

        $result = match ($provider) {
            'openai'    => self::generate_openai($prompt, $cfg),
            'stability' => self::generate_stability($prompt, $cfg),
            default     => ['success' => false, 'error' => 'unknown_provider'],
        };

        // Si falló, reembolsar créditos
        if (!$result['success']) {
            CreditManager::refund($user_id, self::OPERATION, 'image_gen_failed');
        }

        Logger::log('image_gen', "user#{$user_id} provider={$provider} success=" . ($result['success'] ? '1' : '0'));
        return $result;
    }

    // ── OpenAI DALL·E 3 ───────────────────────────────────────────────────────

    private static function generate_openai(string $prompt, array $cfg): array {
        $api_key = get_option('tbot_openai_api_key', '');
        if (!$api_key) return ['success' => false, 'error' => 'openai_key_missing'];

        $body = [
            'model'   => $cfg['model'],
            'prompt'  => mb_substr($prompt, 0, 4000), // DALL·E 3 limit
            'n'       => 1,
            'size'    => $cfg['size'],
            'quality' => $cfg['quality'],
        ];
        if ($cfg['model'] === 'dall-e-3') {
            $body['style'] = $cfg['style'];
        }

        $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
            'timeout' => 60, // La generación tarda
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($decoded['data'][0]['url'])) {
            $err = $decoded['error']['message'] ?? 'unknown_openai_error';
            return ['success' => false, 'error' => $err];
        }

        return [
            'success'         => true,
            'url'             => $decoded['data'][0]['url'],
            'revised_prompt'  => $decoded['data'][0]['revised_prompt'] ?? $prompt,
        ];
    }

    // ── Stability AI ──────────────────────────────────────────────────────────

    private static function generate_stability(string $prompt, array $cfg): array {
        $api_key = get_option('tbot_stability_api_key', '');
        if (!$api_key) return ['success' => false, 'error' => 'stability_key_missing'];

        $response = wp_remote_post(
            'https://api.stability.ai/v2beta/stable-image/generate/core', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => json_encode([
                'prompt'       => mb_substr($prompt, 0, 10000),
                'output_format'=> 'png',
                'aspect_ratio' => '1:1',
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($decoded['image'])) {
            // Base64 → guardar temporalmente y devolver URL
            $tmp = wp_upload_dir();
            $file = $tmp['basedir'] . '/tbot_img_' . uniqid() . '.png';
            file_put_contents($file, base64_decode($decoded['image']));
            return ['success' => true, 'url' => $tmp['baseurl'] . '/' . basename($file)];
        }

        return ['success' => false, 'error' => $decoded['message'] ?? 'stability_error'];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function sanitize_prompt(string $prompt): string {
        // Eliminar /imagine y variantes
        $prompt = preg_replace('/^\/(imagine|imagen|genera|draw)\s*/i', '', $prompt);
        // Limpiar HTML tags
        $prompt = strip_tags($prompt);
        // Limitar longitud
        return trim(mb_substr($prompt, 0, 2000));
    }

    /**
     * Procesa el comando /imagine en el bot de Telegram.
     * Llamado desde WebhookHandler.
     */
    public static function handle_command(
        int $user_id, string $chat_id, string $text,
        string $lang, TelegramService $tg
    ): array {
        $prompt = self::sanitize_prompt($text);
        if (empty($prompt)) {
            $msg = match($lang) {
                'en' => "🎨 <b>Image Generation</b>\n\nUsage: <code>/imagine a cyberpunk city at sunset</code>\n\n💡 Be specific with your prompt for better results!",
                default => "🎨 <b>Generación de Imágenes</b>\n\nUso: <code>/imagine una ciudad cyberpunk al atardecer</code>\n\n💡 ¡Sé específico con tu prompt para mejores resultados!",
            };
            $tg->send_message($chat_id, $msg, 'HTML');
            return ['status' => 'imagine_help'];
        }

        // Mostrar "uploading photo..." mientras generamos
        $tg->send_chat_action($chat_id, 'upload_photo');

        $result = self::generate($prompt, $user_id);

        if (!$result['success']) {
            $err_msg = match($result['error']) {
                'insufficient_credits' => match($lang) {
                    'en' => "💳 Not enough credits for image generation.\n\nUse /topup to buy more!",
                    default => "💳 No tienes créditos suficientes para generar imágenes.\n\n¡Usa /topup para recargar!",
                },
                'prompt_too_short' => match($lang) {
                    'en' => "✏️ Prompt too short. Write at least 3 characters.",
                    default => "✏️ Prompt muy corto. Escribe al menos 3 caracteres.",
                },
                default => match($lang) {
                    'en' => "❌ Image generation failed. Credits refunded.\n\nError: " . $result['error'],
                    default => "❌ Error al generar la imagen. Créditos reembolsados.\n\nError: " . $result['error'],
                },
            };
            $tg->send_message($chat_id, $err_msg, 'HTML');
            return ['status' => 'imagine_error'];
        }

        // Enviar la imagen generada
        $caption = match($lang) {
            'en' => "🎨 <b>Generated image</b>\n\n<i>" . htmlspecialchars(mb_substr($prompt, 0, 200)) . "</i>",
            default => "🎨 <b>Imagen generada</b>\n\n<i>" . htmlspecialchars(mb_substr($prompt, 0, 200)) . "</i>",
        };

        $kb = ['inline_keyboard' => [
            [
                ['text' => '🔄 ' . ($lang === 'en' ? 'Regenerate' : 'Regenerar'),
                 'callback_data' => 'imagine:' . substr(md5($prompt), 0, 8)],
            ],
            [['text' => $lang === 'en' ? '✖ Close' : '✖ Cerrar', 'callback_data' => 'close_msg']],
        ]];

        $tg->send_photo($chat_id, $result['url'], $caption, 'HTML', $kb);
        return ['status' => 'imagine_sent'];
    }
}
