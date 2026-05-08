<?php
namespace TBot\Rest;

if (!defined('ABSPATH')) exit;

use TBot\Services\UserState;
use TBot\Services\CreditManager;
use TBot\Services\Leaderboard;
use TBot\Services\Logger;

/**
 * MiniAppAPI — Endpoints para la Telegram Web App (TWA).
 *
 * Endpoints:
 *   POST /tbot/v1/mini-app/init      — Autenticar con initData y devolver datos del user
 *   GET  /tbot/v1/mini-app/leaderboard — Rankings para la TWA
 *
 * Autenticación:
 *   Valida el hash HMAC del initData contra el bot token
 *   según la documentación oficial de Telegram:
 *   https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class MiniAppAPI {

    public function register(): void {
        register_rest_route('tbot/v1', '/mini-app/init', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_init'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('tbot/v1', '/mini-app/leaderboard', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_leaderboard'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ── Init: autenticación + datos del usuario ──────────────────────────────

    public function handle_init(\WP_REST_Request $request): \WP_REST_Response {
        $init_data = $request->get_param('initData');
        if (empty($init_data)) {
            return new \WP_REST_Response(['success' => false, 'error' => 'Missing initData'], 400);
        }

        // Validar HMAC
        $parsed = self::parse_init_data($init_data);
        if (!$parsed) {
            return new \WP_REST_Response(['success' => false, 'error' => 'Invalid initData'], 403);
        }

        $token = (string) get_option('tbot_master_token', '');
        if (!self::validate_hash($init_data, $token)) {
            Logger::log('security', 'Mini App: invalid initData hash');
            return new \WP_REST_Response(['success' => false, 'error' => 'Hash validation failed'], 403);
        }

        // Extraer usuario de Telegram
        $user_data = json_decode($parsed['user'] ?? '{}', true);
        $tg_id     = (int) ($user_data['id'] ?? 0);
        if (!$tg_id) {
            return new \WP_REST_Response(['success' => false, 'error' => 'No user in initData'], 400);
        }

        // Buscar usuario en WordPress
        $wp_user_id = UserState::get_user_id_by_tg($tg_id);
        if (!$wp_user_id) {
            return new \WP_REST_Response(['success' => false, 'error' => 'User not registered'], 404);
        }

        $state     = UserState::get($wp_user_id);
        $balance   = CreditManager::get_balance($wp_user_id);
        $wp_user   = get_userdata($wp_user_id);
        $bot_user  = get_option('tbot_bot_username', '');
        $streak_lb = Leaderboard::get_streak_top(5);

        $lb_formatted = array_map(function ($r) {
            return [
                'name'        => $r['display_name'] ?? 'Anon',
                'streak_days' => (int) ($r['streak_days'] ?? 0),
            ];
        }, $streak_lb);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'name'           => $wp_user->display_name ?? ($user_data['first_name'] ?? 'User'),
                'plan'           => $state['plan'] ?? 'free',
                'credits'        => $balance,
                'streak_days'    => (int) ($state['streak_days'] ?? 0),
                'referral_count' => (int) ($state['referral_count'] ?? 0),
                'referral_link'  => "https://t.me/{$bot_user}?start=ref_{$tg_id}",
                'bot_username'   => $bot_user,
                'leaderboard'    => $lb_formatted,
            ],
        ]);
    }

    // ── Leaderboard endpoint ─────────────────────────────────────────────────

    public function handle_leaderboard(\WP_REST_Request $request): \WP_REST_Response {
        $type = sanitize_key($request->get_param('type') ?? 'streak');
        $rows = match ($type) {
            'referral' => Leaderboard::get_referral_top(10),
            'active'   => Leaderboard::get_active_top(10),
            default    => Leaderboard::get_streak_top(10),
        };

        $field = match ($type) {
            'referral' => 'referral_count',
            'active'   => 'msg_count',
            default    => 'streak_days',
        };

        $formatted = array_map(function ($r) use ($field) {
            return [
                'name'  => $r['display_name'] ?? 'Anon',
                $field  => (int) ($r[$field] ?? 0),
            ];
        }, $rows);

        return new \WP_REST_Response(['success' => true, 'data' => $formatted]);
    }

    // ── Validación de initData ────────────────────────────────────────────────

    /**
     * Parsea el query string de initData en un array asociativo.
     */
    private static function parse_init_data(string $init_data): ?array {
        parse_str($init_data, $params);
        return !empty($params) ? $params : null;
    }

    /**
     * Valida el hash HMAC-SHA256 del initData.
     *
     * Algoritmo oficial de Telegram:
     *   1. Crear data_check_string: todos los params excepto 'hash', ordenados, unidos por "\n"
     *   2. secret_key = HMAC-SHA256("WebAppData", bot_token)
     *   3. hash = HMAC-SHA256(secret_key, data_check_string)
     *   4. Comparar con el hash recibido
     */
    private static function validate_hash(string $init_data, string $bot_token): bool {
        parse_str($init_data, $params);
        $received_hash = $params['hash'] ?? '';
        unset($params['hash']);

        if (!$received_hash) return false;

        // Ordenar por key
        ksort($params);

        // Construir data_check_string
        $data_check_parts = [];
        foreach ($params as $key => $value) {
            $data_check_parts[] = $key . '=' . $value;
        }
        $data_check_string = implode("\n", $data_check_parts);

        // Calcular HMAC
        $secret_key     = hash_hmac('sha256', $bot_token, 'WebAppData', true);
        $calculated_hash = hash_hmac('sha256', $data_check_string, $secret_key);

        return hash_equals($calculated_hash, $received_hash);
    }
}
