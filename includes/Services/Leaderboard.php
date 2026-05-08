<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * Leaderboard — Clasificaciones gamificadas para engagement.
 *
 * Tablas disponibles:
 *  1. 🔥 Rachas más largas (engagement diario)
 *  2. 💬 Más activos del mes (mensajes enviados)
 *  3. 🤝 Top referidores (crecimiento viral)
 *  4. 💳 Top gastadores de créditos (monetización)
 *
 * La posición del usuario se muestra SIEMPRE (aunque no esté en top 10)
 * para motivar el efecto "un puesto más y entro al ranking".
 */
class Leaderboard {

    const CACHE_TTL = 3600; // 1 hora — los leaderboards no cambian al segundo

    // ── Generación de leaderboards ─────────────────────────────────────────

    public static function get_streak_top(int $limit = 10): array {
        $cache_key = 'tbot_lb_streak_' . $limit;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.user_id, s.streak_days,
                    u.display_name,
                    COALESCE(m.meta_value, '') AS tg_username
             FROM {$wpdb->prefix}tbot_user_state s
             LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
             LEFT JOIN {$wpdb->usermeta} m ON m.user_id = s.user_id AND m.meta_key = 'tbot_tg_username'
             WHERE s.streak_days > 0
             ORDER BY s.streak_days DESC, s.updated_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];

        set_transient($cache_key, $rows, self::CACHE_TTL);
        return $rows;
    }

    public static function get_active_top(int $limit = 10): array {
        $cache_key = 'tbot_lb_active_' . $limit;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        global $wpdb;
        $month_start = date('Y-m-01 00:00:00');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.user_id, COUNT(*) as msg_count,
                    u.display_name
             FROM {$wpdb->prefix}tbot_credit_transactions t
             LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
             WHERE t.amount < 0 AND t.created_at >= %s
             GROUP BY t.user_id
             ORDER BY msg_count DESC
             LIMIT %d",
            $month_start, $limit
        ), ARRAY_A) ?: [];

        set_transient($cache_key, $rows, self::CACHE_TTL);
        return $rows;
    }

    public static function get_referral_top(int $limit = 10): array {
        return ReferralManager::get_top_referrers($limit);
    }

    public static function get_spender_top(int $limit = 10): array {
        $cache_key = 'tbot_lb_spenders_' . $limit;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.user_id, SUM(t.amount) as total_spent,
                    u.display_name
             FROM {$wpdb->prefix}tbot_credit_transactions t
             LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
             WHERE t.type LIKE 'pack_%'
             GROUP BY t.user_id
             ORDER BY total_spent DESC
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];

        set_transient($cache_key, $rows, self::CACHE_TTL);
        return $rows;
    }

    // ── Posición individual ────────────────────────────────────────────────

    public static function get_user_streak_rank(int $user_id): int {
        global $wpdb;
        $my_streak = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT streak_days FROM {$wpdb->prefix}tbot_user_state WHERE user_id = %d", $user_id));
        if (!$my_streak) return 0;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) + 1 FROM {$wpdb->prefix}tbot_user_state
             WHERE streak_days > %d", $my_streak));
    }

    // ── Mensaje de Telegram con el leaderboard ──────────────────────────────

    /**
     * Genera el mensaje HTML del leaderboard para enviar en Telegram.
     *
     * @param string $type  streak | active | referral | spender
     * @param string $lang  es | en | pt
     * @param int    $viewer_user_id  Para mostrar posición del usuario actual
     */
    public static function render_message(string $type, string $lang, int $viewer_user_id = 0): string {
        $medals = ['🥇', '🥈', '🥉'];
        $lines  = [];

        switch ($type) {
            case 'streak':
                $rows  = self::get_streak_top(10);
                $title = match($lang) { 'en' => '🔥 Streak Leaderboard', 'pt' => '🔥 Ranking de Sequências', default => '🔥 Ranking de Rachas' };
                $lines[] = "<b>{$title}</b>\n";
                foreach ($rows as $i => $r) {
                    $medal = $medals[$i] ?? ($i + 1 . '.');
                    $name  = self::safe_name($r['display_name']);
                    $lines[] = "{$medal} {$name} — <b>{$r['streak_days']}</b> " . ($lang === 'en' ? 'days' : 'días');
                }
                if ($viewer_user_id) {
                    $rank = self::get_user_streak_rank($viewer_user_id);
                    $my   = UserState::field($viewer_user_id, 'streak_days', 0);
                    $lines[] = "\n" . match($lang) {
                        'en' => "📍 <i>Your position: #{$rank} ({$my} days)</i>",
                        default => "📍 <i>Tu posición: #{$rank} ({$my} días)</i>",
                    };
                }
                break;

            case 'active':
                $rows  = self::get_active_top(10);
                $title = match($lang) { 'en' => '💬 Most Active This Month', 'pt' => '💬 Mais Ativos do Mês', default => '💬 Más Activos del Mes' };
                $lines[] = "<b>{$title}</b>\n";
                foreach ($rows as $i => $r) {
                    $medal = $medals[$i] ?? ($i + 1 . '.');
                    $name  = self::safe_name($r['display_name']);
                    $lines[] = "{$medal} {$name} — <b>{$r['msg_count']}</b> msgs";
                }
                break;

            case 'referral':
                $rows  = self::get_referral_top(10);
                $title = match($lang) { 'en' => '🤝 Top Referrers', 'pt' => '🤝 Top Indicadores', default => '🤝 Top Referidores' };
                $lines[] = "<b>{$title}</b>\n";
                foreach ($rows as $i => $r) {
                    $medal = $medals[$i] ?? ($i + 1 . '.');
                    $name  = self::safe_name($r['display_name']);
                    $lines[] = "{$medal} {$name} — <b>{$r['referral_count']}</b> refs";
                }
                break;
        }

        if (empty($lines)) return match($lang) {
            'en' => '📊 No data yet. Be the first!',
            default => '📊 Aún no hay datos. ¡Sé el primero!',
        };

        return implode("\n", $lines);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /** Sanitiza el nombre para evitar HTML injection en el leaderboard */
    private static function safe_name(?string $name): string {
        if (!$name) return 'Anónimo';
        $clean = htmlspecialchars(mb_substr($name, 0, 20), ENT_QUOTES, 'UTF-8');
        // Ocultar apellido por privacidad: "Alexander K."
        $parts = explode(' ', $clean);
        if (count($parts) > 1) {
            return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.';
        }
        return $clean;
    }
}
