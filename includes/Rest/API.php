<?php
namespace TBot\Rest;

if (!defined('ABSPATH')) exit;

class API {
    public function register_routes() {
        // Webhook Maestro
        register_rest_route('tbot/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'validate_tg_secret'],
        ]);


        // Acortador de Enlaces (Redirección)
        register_rest_route('tbot/v1', '/s/(?P<code>\w+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_short_link'],
            'permission_callback' => '__return_true',
        ]);

        // Pasarela de Pagos (Stripe Webhook)
        register_rest_route('tbot/v1', '/payment/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_payment_webhook'],
            'permission_callback' => '__return_true', // Validación interna con firma de Stripe
        ]);
    }

    public function validate_tg_secret(\WP_REST_Request $request) {
        $secret = $request->get_header('X-Telegram-Bot-Api-Secret-Token');
        $expected = get_option('tbot_webhook_secret');
        if (!empty($expected)) {
            if (empty($secret) || !hash_equals((string)$expected, (string)$secret)) {
                return new \WP_Error('unauthorized', 'Invalid Secret Token', ['status' => 401]);
            }
        }
        return true;
    }

    public function handle_webhook(\WP_REST_Request $request) {
        try {
            $handler = new \TBot\Services\WebhookHandler();
            $handler->process($request);
            return ['status' => 'received'];
        } catch (\Throwable $e) {
            file_put_contents(__DIR__ . '/fatal_error.log', "[" . date('Y-m-d H:i:s') . "] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n", FILE_APPEND);
            return new \WP_Error('fatal_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function handle_short_link(\WP_REST_Request $request) {
        $code = $request->get_param('code');
        // Lógica de redirección (Tier 1)
        global $wpdb;
        $url = $wpdb->get_var($wpdb->prepare(
            "SELECT original_url FROM {$wpdb->prefix}tbot_short_links WHERE short_code = %s",
            $code
        ));

        if ($url) {
            wp_redirect($url, 301);
            exit;
        }

        return new \WP_Error('not_found', 'Short link not found', ['status' => 404]);
    }

    public function handle_payment_webhook(\WP_REST_Request $request) {
        $payload        = $request->get_body();
        $sig_header     = $request->get_header('Stripe-Signature');
        $endpoint_secret= get_option('tbot_stripe_webhook_secret');

        if (!class_exists('\Stripe\Webhook')) {
            return new \WP_Error('missing_lib', 'Stripe Library not found', ['status' => 500]);
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (\Exception $e) {
            return new \WP_Error('invalid_sig', $e->getMessage(), ['status' => 400]);
        }

        if ($event->type === 'checkout.session.completed') {
            $session  = $event->data->object;
            $user_id  = $session->client_reference_id ?? $session->metadata->wp_user_id ?? null;
            $pack_id  = $session->metadata->pack_id    ?? null;
            $plan     = $session->metadata->plan_type  ?? 'standard';

            if ($user_id) {
                if ($pack_id) {
                    \TBot\Services\CreditManager::activate_pack((int)$user_id, $pack_id, 'stripe');
                } else {
                    (new \TBot\Services\SubscriptionManager())->activate_subscription((int)$user_id, $plan);
                }
            }
        }

        return ['status' => 'received'];
    }

    /**
     * Descifra un token de bot personal (AES-256-CBC).
     * Retrocompatible con tokens legacy no cifrados.
     */
    private static function decrypt_token(string $data): string {
        $decoded = base64_decode($data, true);
        if ($decoded === false || strlen($decoded) < 17) {
            return $data; // token legacy sin cifrar
        }
        $key = substr(hash('sha256', AUTH_KEY, true), 0, 32);
        $iv  = substr($decoded, 0, 16);
        $enc = substr($decoded, 16);
        $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : $data;
    }
}
