<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

class UserManager {
    /**
     * Obtiene o crea un usuario de WordPress basado en el ID de Telegram.
     */
    public function get_or_create_by_tg_id(int $tg_id, array $tg_data = []) {
        $cache_key = 'tbot_user_' . $tg_id;
        $cached_user = wp_cache_get($cache_key, 'tbot');

        if ($cached_user !== false) {
            return $cached_user;
        }

        $user_query = new \WP_User_Query([
            'meta_key'   => 'tbot_telegram_id',
            'meta_value' => $tg_id,
            'number'     => 1
        ]);

        $users = $user_query->get_results();

        if (!empty($users)) {
            wp_cache_set($cache_key, $users[0], 'tbot', 3600); // Cachear por 1 hora
            return $users[0];
        }

        // Crear usuario nuevo (SaaS Style)
        $username = 'tg_' . $tg_id;
        $email    = $tg_id . '@telegram.bot'; // Email ficticio
        $password = wp_generate_password();

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        update_user_meta($user_id, 'tbot_telegram_id', $tg_id);
        update_user_meta($user_id, 'tbot_subscription', 'free');
        
        if (!empty($tg_data['first_name'])) {
            wp_update_user([
                'ID'         => $user_id,
                'first_name' => sanitize_text_field($tg_data['first_name']),
                'last_name'  => sanitize_text_field($tg_data['last_name'] ?? ''),
            ]);
        }

        $new_user = get_user_by('id', $user_id);
        wp_cache_set($cache_key, $new_user, 'tbot', 3600);
        return $new_user;
    }

    public function get_subscription_level($user_id) {
        $cache_key = 'tbot_sub_' . $user_id;
        $sub = wp_cache_get($cache_key, 'tbot');
        if ($sub === false) {
            $sub = get_user_meta($user_id, 'tbot_subscription', true) ?: 'free';
            wp_cache_set($cache_key, $sub, 'tbot', 3600);
        }
        return $sub;
    }

    public function check_quota($user_id, $feature = 'global') {
        $limit = $this->get_limit($user_id, $feature);
        $used  = (int) get_user_meta($user_id, 'tbot_usage_' . $feature, true);

        return $used < $limit;
    }

    private function get_limit($user_id, $feature) {
        $sub = $this->get_subscription_level($user_id);
        
        // Límites harcodeados por ahora (configurable luego)
        $limits = [
            'free'    => ['global' => 20, 'qr_generator' => 5],
            'premium' => ['global' => 1000, 'qr_generator' => 100],
            'admin'   => ['global' => 99999, 'qr_generator' => 99999],
        ];

        return $limits[$sub][$feature] ?? $limits[$sub]['global'] ?? 20;
    }

    public function increment_usage($user_id, $feature = 'global') {
        $used = (int) get_user_meta($user_id, 'tbot_usage_' . $feature, true);
        update_user_meta($user_id, 'tbot_usage_' . $feature, $used + 1);
        
        // Invalidar cache de cuota si se estuviera cacheando (actualmente lee meta directo)
    }

    public function is_blacklisted($user_id) {
        $cache_key = 'tbot_blacklist_' . $user_id;
        $status = wp_cache_get($cache_key, 'tbot');
        if ($status === false) {
            $status = get_user_meta($user_id, 'tbot_status', true);
            wp_cache_set($cache_key, $status, 'tbot', 3600);
        }
        return $status === 'blacklisted';
    }
}
