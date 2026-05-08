<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * StreakManager — Sistema de rachas diarias para retención.
 *
 * Flujo:
 *  - Cada vez que el usuario manda un mensaje: check_and_update()
 *  - WP Cron cada hora: check_reminders() → push si lleva 20h sin hablar
 *  - Hitos: día 3, 7, 14, 30, 60, 100 → créditos bonus
 */
class StreakManager {

    // Hitos de racha → créditos bonus
    const MILESTONES = [
        3   => 20,
        7   => 50,
        14  => 100,
        30  => 300,
        60  => 600,
        100 => 1000,
    ];

    // Horas sin actividad para enviar recordatorio
    const REMINDER_HOURS = 20;

    // Horas máximas sin actividad antes de romper la racha
    const BREAK_HOURS = 48;

    // ── Actualización de Racha ────────────────────────────────────────────────

    /**
     * Debe llamarse en cada mensaje del usuario.
     * Retorna array con info del evento de racha (para mostrar al usuario si corresponde).
     *
     * @return array|null  null = sin novedad | array = hay milestone o inicio de racha
     */
    public static function check_and_update(int $user_id): ?array {
        $now         = time();
        $last_active = (int) get_user_meta($user_id, 'tbot_last_active', true);
        $streak      = (int) get_user_meta($user_id, 'tbot_streak', true);
        $streak_date = (string) get_user_meta($user_id, 'tbot_streak_date', true); // YYYY-MM-DD

        $today = date('Y-m-d', $now);

        // Ya actualizamos hoy → no hacer nada
        if ($streak_date === $today) {
            update_user_meta($user_id, 'tbot_last_active', $now);
            return null;
        }

        $hours_since = $last_active > 0 ? ($now - $last_active) / 3600 : 999;

        // Racha se rompe si pasaron más de 48h
        if ($hours_since > self::BREAK_HOURS && $streak > 0) {
            $old_streak = $streak;
            $streak     = 0;
            update_user_meta($user_id, 'tbot_streak', 0);
            update_user_meta($user_id, 'tbot_streak_date', $today);
            update_user_meta($user_id, 'tbot_last_active', $now);
            update_user_meta($user_id, 'tbot_streak_reminder_sent', 0);
            Logger::log('streak', "Racha rota: user#{$user_id}, tenía {$old_streak} días");
            return ['event' => 'streak_broken', 'old_streak' => $old_streak];
        }

        // Incrementar racha
        $streak++;
        $best = (int) get_user_meta($user_id, 'tbot_streak_best', true);
        if ($streak > $best) {
            update_user_meta($user_id, 'tbot_streak_best', $streak);
        }

        update_user_meta($user_id, 'tbot_streak',          $streak);
        update_user_meta($user_id, 'tbot_streak_date',     $today);
        update_user_meta($user_id, 'tbot_last_active',     $now);
        update_user_meta($user_id, 'tbot_streak_reminder_sent', 0);

        Logger::log('streak', "Racha actualizada: user#{$user_id} → {$streak} días");

        // Verificar milestone
        if (isset(self::MILESTONES[$streak])) {
            $bonus = self::MILESTONES[$streak];
            CreditManager::add($user_id, $bonus, 'streak_bonus', "Racha de {$streak} días 🔥");
            return ['event' => 'milestone', 'streak' => $streak, 'bonus' => $bonus];
        }

        // Solo informar del inicio de racha el día 1
        if ($streak === 1) {
            return ['event' => 'streak_started', 'streak' => 1];
        }

        // Días normales: informar si la racha es notable (múltiplo de 5)
        if ($streak % 5 === 0) {
            return ['event' => 'streak_notable', 'streak' => $streak];
        }

        return null;
    }

    /**
     * Genera el mensaje a mostrar según el evento de racha.
     */
    public static function event_message(array $event, string $lang = 'es'): string {
        $e = $event['event'];
        $s = $event['streak'] ?? 0;
        $b = $event['bonus']  ?? 0;
        $o = $event['old_streak'] ?? 0;

        if ($lang === 'en') {
            return match($e) {
                'streak_started'  => "🔥 <b>Streak started!</b> Come back tomorrow to keep it going.",
                'milestone'       => "🏆 <b>{$s}-day streak!</b> You earned <b>+{$b} credits</b> as a reward! Keep it up!",
                'streak_notable'  => "🔥 <b>{$s} days straight!</b> You're on a roll.",
                'streak_broken'   => "💔 <b>Streak lost</b> after {$o} days.\nSpend 10 credits to recover it → /recover",
                default           => '',
            };
        }
        if ($lang === 'pt') {
            return match($e) {
                'streak_started'  => "🔥 <b>Sequência iniciada!</b> Volte amanhã para continuar.",
                'milestone'       => "🏆 <b>{$s} dias seguidos!</b> Você ganhou <b>+{$b} créditos</b>! Continue assim!",
                'streak_notable'  => "🔥 <b>{$s} dias seguidos!</b> Você está arrasando.",
                'streak_broken'   => "💔 <b>Sequência perdida</b> após {$o} dias.\nGaste 10 créditos para recuperar → /recover",
                default           => '',
            };
        }
        return match($e) {
            'streak_started'  => "🔥 <b>¡Racha iniciada!</b> Vuelve mañana para mantenerla.",
            'milestone'       => "🏆 <b>¡{$s} días seguidos!</b> Ganaste <b>+{$b} créditos</b> de recompensa. ¡Sigue así!",
            'streak_notable'  => "🔥 <b>¡{$s} días de racha!</b> Estás imparable.",
            'streak_broken'   => "💔 <b>Racha perdida</b> tras {$o} días.\nGasta 10 créditos para recuperarla → /recover",
            default           => '',
        };
    }

    // ── Recordatorios Proactivos (WP Cron) ───────────────────────────────────

    /**
     * Llama a esto desde un cron cada hora.
     * Envía recordatorio si el usuario tiene racha activa y lleva 20h sin hablar.
     */
    public static function check_reminders(): void {
        global $wpdb;

        $threshold = time() - (self::REMINDER_HOURS * 3600);
        $break_at  = time() - (self::BREAK_HOURS * 3600);

        // Usuarios con racha ≥ 2, activos en las últimas 48h, sin recordatorio enviado hoy
        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value as streak
             FROM {$wpdb->usermeta}
             WHERE meta_key = 'tbot_streak' AND CAST(meta_value AS UNSIGNED) >= 2",
        ));

        foreach ($users as $row) {
            $user_id = (int) $row->user_id;
            $streak  = (int) $row->streak;

            $last_active      = (int) get_user_meta($user_id, 'tbot_last_active', true);
            $reminder_sent    = (int) get_user_meta($user_id, 'tbot_streak_reminder_sent', true);
            $tg_id            = get_user_meta($user_id, 'tbot_telegram_id', true);
            $token            = (string) get_option('tbot_master_token', '');
            $lang             = get_user_meta($user_id, 'tbot_language', true) ?: 'es';

            // No enviar si: ya enviamos hoy | ya se rompió la racha | no tenemos tg_id
            if (!$tg_id || $reminder_sent || $last_active < $break_at || $last_active > $threshold) {
                continue;
            }

            $hours_left = max(1, (int)(self::BREAK_HOURS - ($now = (time() - $last_active) / 3600)));
            $msg = self::reminder_message($streak, (int) $hours_left, $lang);

            $tg = new TelegramService($token);
            $tg->send_message((string) $tg_id, $msg, 'HTML');

            update_user_meta($user_id, 'tbot_streak_reminder_sent', 1);
            Logger::log('streak', "Reminder sent: user#{$user_id}, streak={$streak}");
        }
    }

    private static function reminder_message(int $streak, int $hours_left, string $lang): string {
        $h = $hours_left === 1 ? "1 hora" : "{$hours_left} horas";
        if ($lang === 'en') {
            $h = $hours_left === 1 ? "1 hour" : "{$hours_left} hours";
            return "👋 <b>Hey! Your {$streak}-day streak ends in {$h}.</b>\n\nSend me anything — even just a 'hi!' — to keep it going. 🔥";
        }
        if ($lang === 'pt') {
            $h = $hours_left === 1 ? "1 hora" : "{$hours_left} horas";
            return "👋 <b>Ei! Sua sequência de {$streak} dias acaba em {$h}.</b>\n\nMe mande qualquer coisa — até um 'oi!' — para manter. 🔥";
        }
        return "👋 <b>¡Ey! Tu racha de {$streak} días termina en {$h}.</b>\n\nEscríbeme cualquier cosa — aunque sea un '¡hola!' — para mantenerla. 🔥";
    }

    // ── Recuperar Racha (cuesta créditos) ────────────────────────────────────

    /**
     * Permite al usuario recuperar una racha rota gastando créditos.
     * Solo disponible 24h después de perderla.
     */
    public static function recover_streak(int $user_id): array {
        $cost       = (int) get_option('tbot_streak_recover_cost', 10);
        $streak     = (int) get_user_meta($user_id, 'tbot_streak', true);
        $streak_date= (string) get_user_meta($user_id, 'tbot_streak_date', true);
        $today      = date('Y-m-d');
        $yesterday  = date('Y-m-d', strtotime('-1 day'));

        // Solo se puede recuperar si la racha se perdió ayer
        if ($streak > 0 || !in_array($streak_date, [$yesterday])) {
            return ['success' => false, 'reason' => 'not_eligible'];
        }

        // Deducimos manualmente el costo de recuperación
        global $wpdb;
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tbot_credits SET balance = balance - %d, lifetime_spent = lifetime_spent + %d WHERE user_id = %d AND balance >= %d",
            $cost, $cost, $user_id, $cost
        ));
        if (!$rows) return ['success' => false, 'reason' => 'insufficient_credits'];
        wp_cache_delete("tbot_credits_{$user_id}", 'tbot');

        // Restaurar la racha como si ayer hubiera hablado
        $old_streak = (int) get_user_meta($user_id, 'tbot_streak_best', true);
        update_user_meta($user_id, 'tbot_streak', max(1, $old_streak - 1));
        update_user_meta($user_id, 'tbot_streak_date', $today);
        update_user_meta($user_id, 'tbot_last_active', time());

        Logger::log('streak', "Racha recuperada: user#{$user_id}");
        return ['success' => true, 'streak' => max(1, $old_streak - 1), 'cost' => $cost];
    }

    // ── Info de Racha ─────────────────────────────────────────────────────────

    public static function get_streak_info(int $user_id): array {
        return [
            'streak'      => (int) get_user_meta($user_id, 'tbot_streak', true),
            'best'        => (int) get_user_meta($user_id, 'tbot_streak_best', true),
            'last_active' => (int) get_user_meta($user_id, 'tbot_last_active', true),
        ];
    }
}
