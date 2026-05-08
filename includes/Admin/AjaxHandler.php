<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * AjaxHandler — Todos los endpoints wp_ajax_tbot_* del panel de admin.
 */
class AjaxHandler {

    public function __construct() {
        $actions = [
            'check_telegram',
            'test_ai',
            'set_webhook',
            'flush_webhook',
            'clear_logs',
            'get_logs',
            'update_user_field',
        ];
        foreach ($actions as $action) {
            add_action('wp_ajax_tbot_' . $action, [$this, 'handle_' . $action]);
        }
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    private function verify() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
        if (!check_ajax_referer('tbot_ajax_nonce', 'nonce', false)) wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    private function ok($data = []) {
        wp_send_json_success($data);
    }

    private function fail($message, $extra = []) {
        wp_send_json_error(array_merge(['message' => $message], $extra));
    }

    // ── HANDLERS ─────────────────────────────────────────────────────────────

    public function handle_check_telegram() {
        $this->verify();

        $token    = get_option('tbot_master_token');
        $response = wp_remote_get("https://api.telegram.org/bot{$token}/getWebhookInfo", ['timeout' => 10]);

        if (is_wp_error($response)) {
            $this->fail('No se pudo contactar la API de Telegram: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            $this->fail('Telegram respondió con error. Verifica tu Token Maestro.', $body['result'] ?? []);
            return;
        }

        \TBot\Services\Logger::log('test', 'Verificación de webhook via AJAX', $body['result']);
        $this->ok($body['result']);
    }

    public function handle_test_ai() {
        $this->verify();

        $result = \TBot\Services\AIService::chat([
            'model'         => 'gpt-4o-mini',
            'message'       => 'Di "OK" y nada más.',
            'system_prompt' => 'Responde con una sola palabra.',
            'max_tokens'    => 10,
        ]);

        if ($result['success']) {
            \TBot\Services\Logger::log('test', 'AI test OK via AJAX: ' . ($result['text'] ?? ''));
            $this->ok(['message' => 'IA respondió: ' . ($result['text'] ?? 'OK'), 'tokens' => $result['usage']['total_tokens'] ?? 0]);
        } else {
            \TBot\Services\Logger::log('error', 'AI test FAIL via AJAX: ' . ($result['error'] ?? 'unknown'));
            $this->fail('Error al contactar la IA: ' . ($result['error'] ?? 'Verifica tus API Keys en Ajustes.'));
        }
    }

    public function handle_set_webhook() {
        $this->verify();

        $token       = get_option('tbot_master_token');
        $secret      = get_option('tbot_webhook_secret');
        $webhook_url = get_rest_url(null, 'tbot/v1/webhook');

        if (empty($token)) {
            $this->fail('No hay un Token Maestro configurado. Ve a Ajustes primero.');
            return;
        }

        $response = wp_remote_post("https://api.telegram.org/bot{$token}/setWebhook", [
            'timeout' => 15,
            'body'    => [
                'url'             => $webhook_url,
                'secret_token'    => $secret,
                'allowed_updates' => json_encode(['message', 'callback_query', 'inline_query', 'pre_checkout_query', 'successful_payment']),
            ]
        ]);

        if (is_wp_error($response)) {
            $this->fail('Error de red: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['ok'])) {
            \TBot\Services\Logger::log('test', 'Webhook configurado via AJAX: ' . $webhook_url);
            $this->ok(['webhook_url' => $webhook_url, 'description' => $body['description'] ?? 'OK']);
        } else {
            \TBot\Services\Logger::log('error', 'Telegram rechazó el webhook via AJAX', $body);
            $this->fail($body['description'] ?? 'Telegram rechazó el webhook.', $body);
        }
    }

    public function handle_flush_webhook() {
        $this->verify();

        $token       = get_option('tbot_master_token');
        $secret      = get_option('tbot_webhook_secret');
        $webhook_url = get_rest_url(null, 'tbot/v1/webhook');

        if (empty($token)) {
            $this->fail('No hay un Token Maestro configurado. Ve a Ajustes primero.');
            return;
        }

        $response = wp_remote_post("https://api.telegram.org/bot{$token}/setWebhook", [
            'timeout' => 15,
            'body'    => [
                'url'                  => $webhook_url,
                'secret_token'         => $secret,
                'allowed_updates'      => json_encode(['message', 'callback_query', 'inline_query', 'pre_checkout_query', 'successful_payment']),
                'drop_pending_updates' => true,
            ]
        ]);

        if (is_wp_error($response)) {
            $this->fail('Error de conexión con Telegram: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['ok']) && $body['ok']) {
            $this->ok(['message' => '¡Tubería limpiada (Drop Pending Updates)! El webhook está fresco y activo.']);
        } else {
            $this->fail("Error de Telegram: " . ($body['description'] ?? 'Desconocido'));
        }
    }

    public function handle_clear_logs() {
        $this->verify();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tbot_logs");

        $this->ok(['message' => 'Logs borrados correctamente']);
    }

    public function handle_get_logs() {
        $this->verify();

        $limit = min(100, max(1, (int)($_POST['limit'] ?? 50)));
        $logs  = \TBot\Services\Logger::get_recent_logs($limit);

        ob_start();
        if ($logs): foreach ($logs as $log): ?>
            <tr>
                <td class="tbot-muted" style="white-space:nowrap;font-size:11px;"><?php echo esc_html($log->created_at); ?></td>
                <td><span class="tbot-badge tbot-badge-<?php echo esc_attr($log->event_type); ?>"><?php echo esc_html($log->event_type); ?></span></td>
                <td><?php echo esc_html($log->message); ?></td>
                <td>
                    <?php if ($log->payload): ?>
                        <details>
                            <summary>Ver payload</summary>
                            <pre class="tbot-payload-preview"><?php echo esc_html(json_encode(json_decode($log->payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                        </details>
                    <?php else: ?>
                        <span class="tbot-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="4" class="tbot-center tbot-muted" style="padding:20px;">Sin logs registrados.</td></tr>
        <?php endif;
        $html = ob_get_clean();

        $this->ok(['html' => $html]);
    }

    public function handle_update_user_field() {
        $this->verify();

        $user_id = (int)($_POST['user_id'] ?? 0);
        $field   = sanitize_key($_POST['field'] ?? '');
        $value   = sanitize_text_field($_POST['value'] ?? '');

        $allowed_fields = ['tbot_subscription', 'tbot_status', 'tbot_language'];
        if (!$user_id || !in_array($field, $allowed_fields, true)) {
            $this->fail('Campo o usuario no válido.');
            return;
        }

        update_user_meta($user_id, $field, $value);

        // Invalidar caché de objeto
        wp_cache_delete('tbot_user_' . get_user_meta($user_id, 'tbot_telegram_id', true), 'tbot');
        wp_cache_delete('tbot_sub_' . $user_id, 'tbot');
        wp_cache_delete('tbot_blacklist_' . $user_id, 'tbot');

        \TBot\Services\Logger::log('admin', "Campo {$field} del usuario #{$user_id} actualizado a: {$value} (AJAX)");
        $this->ok(['message' => 'Actualizado correctamente']);
    }
}
