<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * ReferralManager — Sistema de referidos con recompensas en créditos.
 *
 * Flujo:
 *  1. Usuario A comparte su link: https://t.me/Bot?start=ref_USER_ID
 *  2. Usuario B abre el link → /start?ref=USER_A_ID
 *  3. WebhookHandler detecta el parámetro ref → register_referral()
 *  4. Usuario B recibe bonus de bienvenida + Usuario A recibe créditos
 *  5. Si B compra un pack → A recibe % de comisión en créditos
 */
class ReferralManager {

    // Créditos que recibe el referidor al traer a alguien
    const REFERRER_SIGNUP_BONUS  = 200;

    // Créditos extra para el nuevo usuario referido (además del bonus de signup normal)
    const REFERRED_WELCOME_BONUS = 50;

    // % de los créditos comprados que recibe el referidor (en créditos equivalentes)
    const REFERRER_PURCHASE_PCT  = 10; // 10%

    // ── Registro de Referido ──────────────────────────────────────────────────

    /**
     * Registra que el nuevo usuario llegó vía referido.
     * Llamar desde WebhookHandler al procesar /start?start=ref_XXXX.
     *
     * @param int $new_user_id    WP User ID del nuevo usuario
     * @param int $referrer_tg_id Telegram ID del referidor (desde el payload de /start)
     */
    public static function register_referral(int $new_user_id, int $referrer_tg_id): void {
        // Evitar auto-referidos
        $new_tg_id = (int) get_user_meta($new_user_id, 'tbot_telegram_id', true);
        if ($new_tg_id === $referrer_tg_id) return;

        // Verificar que el usuario no tenga ya un referidor
        if (get_user_meta($new_user_id, 'tbot_referred_by', true)) return;

        // Resolver WP User ID del referidor
        global $wpdb;
        $referrer_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'tbot_telegram_id' AND meta_value = %s",
            $referrer_tg_id
        ));

        if (!$referrer_id || $referrer_id === $new_user_id) return;

        // Guardar relación de referido
        update_user_meta($new_user_id, 'tbot_referred_by', $referrer_id);

        // Incrementar contador de referidos del referidor
        $count = (int) get_user_meta($referrer_id, 'tbot_referral_count', true);
        update_user_meta($referrer_id, 'tbot_referral_count', $count + 1);

        // Bonuses
        $signup_bonus    = (int) get_option('tbot_referrer_signup_bonus', self::REFERRER_SIGNUP_BONUS);
        $referred_bonus  = (int) get_option('tbot_referred_welcome_bonus', self::REFERRED_WELCOME_BONUS);

        CreditManager::add($referrer_id, $signup_bonus, 'referral_signup',
            "Referido: user#{$new_user_id} se unió");
        CreditManager::add($new_user_id, $referred_bonus, 'referral_welcome',
            "Bonus por llegar vía invitación 🎉");

        Logger::log('referral', "Referral: user#{$new_user_id} por user#{$referrer_id}");

        // Notificar al referidor por Telegram
        self::notify_referrer($referrer_id, $new_user_id, $signup_bonus);
    }

    /**
     * Llama a esto cuando un referido compra un pack.
     * El referidor recibe un % en créditos como comisión.
     *
     * @param int $buyer_user_id  WP User ID del comprador
     * @param int $credits_bought Créditos que compró
     */
    public static function on_purchase(int $buyer_user_id, int $credits_bought): void {
        $referrer_id = (int) get_user_meta($buyer_user_id, 'tbot_referred_by', true);
        if (!$referrer_id) return;

        $pct      = (int) get_option('tbot_referrer_purchase_pct', self::REFERRER_PURCHASE_PCT);
        $commission = max(1, (int) ($credits_bought * $pct / 100));

        CreditManager::add($referrer_id, $commission, 'referral_commission',
            "Comisión {$pct}% por compra de user#{$buyer_user_id}: +{$commission} cr.");

        Logger::log('referral', "Comisión: user#{$referrer_id} +{$commission} cr. (compra user#{$buyer_user_id})");
        self::notify_referrer_commission($referrer_id, $buyer_user_id, $commission);
    }

    // ── Deep Link ─────────────────────────────────────────────────────────────

    /**
     * Genera el deep link de referido para un usuario.
     * Formato: https://t.me/BotUsername?start=ref_WP_USER_ID
     */
    public static function get_referral_link(int $user_id): string {
        $bot_username = get_option('tbot_bot_username', '');
        if (!$bot_username) {
            // Obtener desde la API
            $token = (string) get_option('tbot_master_token', '');
            if ($token) {
                $tg  = new TelegramService($token);
                $me  = $tg->get_me();
                $bot_username = $me['result']['username'] ?? '';
                if ($bot_username) {
                    update_option('tbot_bot_username', $bot_username);
                }
            }
        }
        return $bot_username ? "https://t.me/{$bot_username}?start=ref_{$user_id}" : '';
    }

    /**
     * Analiza el payload de /start y extrae el referrer_tg_id si existe.
     * Payload ejemplo: "ref_12345678" → retorna 12345678
     */
    public static function parse_start_payload(string $payload): ?int {
        if (str_starts_with($payload, 'ref_')) {
            $id = (int) substr($payload, 4);
            return $id > 0 ? $id : null;
        }
        return null;
    }

    // ── Leaderboard de Referidos ──────────────────────────────────────────────

    /**
     * Top referidores (para Admin y para motivar a usuarios).
     */
    public static function get_top_referrers(int $limit = 10): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.display_name,
                    CAST(m.meta_value AS UNSIGNED) as referral_count
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'tbot_referral_count'
             WHERE CAST(m.meta_value AS UNSIGNED) > 0
             ORDER BY referral_count DESC
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    // ── Stats de un Usuario ────────────────────────────────────────────────────

    public static function get_user_stats(int $user_id): array {
        return [
            'referral_count'  => (int) get_user_meta($user_id, 'tbot_referral_count', true),
            'referred_by'     => (int) get_user_meta($user_id, 'tbot_referred_by', true),
            'referral_link'   => self::get_referral_link($user_id),
        ];
    }

    // ── Notificaciones ────────────────────────────────────────────────────────

    private static function notify_referrer(int $referrer_id, int $new_user_id, int $bonus): void {
        $tg_id = get_user_meta($referrer_id, 'tbot_telegram_id', true);
        $lang  = get_user_meta($referrer_id, 'tbot_language', true) ?: 'es';
        $token = (string) get_option('tbot_master_token', '');
        if (!$tg_id || !$token) return;

        $msg = match($lang) {
            'en' => "🎉 <b>New referral!</b>\n\nSomeone joined with your link. You earned <b>+{$bonus} credits</b>!\n\nTotal referrals: " . get_user_meta($referrer_id, 'tbot_referral_count', true),
            'pt' => "🎉 <b>Novo indicado!</b>\n\nAlguém entrou com seu link. Você ganhou <b>+{$bonus} créditos</b>!\n\nTotal de indicados: " . get_user_meta($referrer_id, 'tbot_referral_count', true),
            default => "🎉 <b>¡Nuevo referido!</b>\n\nAlguien se unió con tu link. Ganaste <b>+{$bonus} créditos</b>!\n\nTotal de referidos: " . get_user_meta($referrer_id, 'tbot_referral_count', true),
        };

        (new TelegramService($token))->post_async('sendMessage', [
            'chat_id'    => $tg_id,
            'text'       => $msg,
            'parse_mode' => 'HTML',
        ]);
    }

    private static function notify_referrer_commission(int $referrer_id, int $buyer_id, int $commission): void {
        $tg_id = get_user_meta($referrer_id, 'tbot_telegram_id', true);
        $lang  = get_user_meta($referrer_id, 'tbot_language', true) ?: 'es';
        $token = (string) get_option('tbot_master_token', '');
        if (!$tg_id || !$token) return;

        $msg = match($lang) {
            'en' => "💸 <b>Commission earned!</b>\n\nOne of your referrals made a purchase. You earned <b>+{$commission} credits</b> commission! 🎉",
            'pt' => "💸 <b>Comissão recebida!</b>\n\nUm dos seus indicados fez uma compra. Você ganhou <b>+{$commission} créditos</b> de comissão! 🎉",
            default => "💸 <b>¡Comisión recibida!</b>\n\nUno de tus referidos hizo una compra. Ganaste <b>+{$commission} créditos</b> de comisión. 🎉",
        };

        (new TelegramService($token))->post_async('sendMessage', [
            'chat_id'    => $tg_id,
            'text'       => $msg,
            'parse_mode' => 'HTML',
        ]);
    }
}
