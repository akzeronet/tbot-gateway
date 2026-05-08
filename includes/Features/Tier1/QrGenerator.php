<?php
namespace TBot\Features\Tier1;

use TBot\Features\FeatureInterface;

if (!defined('ABSPATH')) exit;

class QrGenerator implements FeatureInterface {
    public function handle($user_id, $chat_id, $bot_token, $text, $language) {
        $parts = explode(' ', $text, 2);
        $content = trim($parts[1] ?? '');

        if (empty($content)) {
            $this->send_message($bot_token, $chat_id, "❌ Proporciona un texto o URL para codificar.\n\nUso: `/qr https://ejemplo.com`", $language);
            return true;
        }

        if (strlen($content) > 500) {
            $this->send_message($bot_token, $chat_id, "❌ Contenido muy largo. Máximo 500 caracteres.", $language);
            return true;
        }

        // Generar URL del QR (usando API de Google Charts para rapidez)
        $qr_url = "https://chart.googleapis.com/chart?chs=400x400&cht=qr&chl=" . urlencode($content) . "&choe=UTF-8";

        // Enviar a Telegram como foto
        $url = "https://api.telegram.org/bot{$bot_token}/sendPhoto";
        wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'photo'   => $qr_url,
                'caption' => "📷 *Código QR generado*\n\nContenido: `{$content}`",
                'parse_mode' => 'Markdown'
            ]
        ]);

        return true;
    }

    private function send_message($token, $chat_id, $text, $language) {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'text'    => $text,
                'parse_mode' => 'Markdown'
            ]
        ]);
    }
}
