<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

class SubscriptionManager {
    public function activate_subscription($user_id, $plan_type) {
        // 1. Actualizar Meta en WordPress
        update_user_meta($user_id, 'tbot_subscription', $plan_type);
        update_user_meta($user_id, 'tbot_subscription_status', 'active');
        
        // Calcular expiración (30 días)
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        update_user_meta($user_id, 'tbot_subscription_expiry', $expiry);

        // 2. Notificar al usuario por Telegram
        $tg_id = get_user_meta($user_id, 'tbot_telegram_id', true);
        if ($tg_id) {
            $this->notify_user($tg_id, $plan_type);
        }

        // 3. Registrar log
        error_log("TBot: Suscripción activada para User $user_id - Plan $plan_type");
    }

    private function notify_user($tg_id, $plan_type) {
        $token = get_option('tbot_master_token');
        $text = "🎉 *¡Suscripción Activada!*\n\nTu plan *" . ucfirst($plan_type) . "* ya está activo por 30 días. ¡Disfruta de tus nuevas funciones!";
        
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        wp_remote_post($url, [
            'body' => [
                'chat_id' => $tg_id,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]
        ]);
    }
}
