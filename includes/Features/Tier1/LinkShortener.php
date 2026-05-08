<?php
namespace TBot\Features\Tier1;

use TBot\Features\FeatureInterface;

if (!defined('ABSPATH')) exit;

class LinkShortener implements FeatureInterface {
    public function handle($user_id, $chat_id, $bot_token, $text, $language) {
        $parts = explode(' ', $text, 2);
        $url = trim($parts[1] ?? '');

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->send_message($bot_token, $chat_id, "❌ Por favor envía una URL válida. Uso: `/short https://ejemplo.com`", $language);
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tbot_short_links';

        // Verificar si ya existe
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT short_code FROM $table WHERE uid = %d AND original_url = %s",
            $user_id, $url
        ));

        if ($existing) {
            $short_url = $this->build_url($existing);
            $this->send_message($bot_token, $chat_id, "🔗 Enlace ya existente:\n\n`$short_url`", $language);
            return true;
        }

        // Generar nuevo código
        $code = $this->generate_code();
        $wpdb->insert($table, [
            'uid' => $user_id,
            'short_code' => $code,
            'original_url' => $url
        ]);

        $short_url = $this->build_url($code);
        $this->send_message($bot_token, $chat_id, "🔗 *Enlace acortado:*\n\n`$short_url`", $language);
        
        return true;
    }

    private function generate_code() {
        return substr(md5(uniqid()), 0, 7);
    }

    private function build_url($code) {
        return home_url("/wp-json/tbot/v1/s/$code");
    }

    private function send_message($token, $chat_id, $text, $language) {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]
        ]);
    }
}
