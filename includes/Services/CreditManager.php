<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * CreditManager — Sistema de créditos (reemplaza límite diario de mensajes).
 *
 * Costos por operación (configurables en Admin > Créditos):
 *  text_basic=1 | text_advanced=3 | photo=5 | voice=3 | image_gen=20
 */
class CreditManager {

    const FREE_ON_SIGNUP  = 100;

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tbot_credits';
    }

    // ── Balance ───────────────────────────────────────────────────────────────

    public static function get_balance(int $user_id): int {
        $key    = "tbot_credits_{$user_id}";
        $cached = wp_cache_get($key, 'tbot');
        if ($cached !== false) return (int) $cached;

        global $wpdb;
        $bal = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM " . self::table() . " WHERE user_id = %d", $user_id
        ));
        wp_cache_set($key, $bal, 'tbot', 300);
        return $bal;
    }

    public static function get_stats(int $user_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT balance, lifetime_earned, lifetime_spent FROM " . self::table() . " WHERE user_id = %d", $user_id
        ), ARRAY_A);
        return $row ?: ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_spent' => 0];
    }

    public static function can_afford(int $user_id, string $model_key): bool {
        return self::get_balance($user_id) >= self::get_cost($model_key);
    }

    public static function get_cost(string $model_key): int {
        return \TBot\Admin\AIModels::get_model_cost($model_key);
    }

    // ── Inicializar usuario nuevo ─────────────────────────────────────────────

    public static function initialize(int $user_id): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d", $user_id
        ));
        if ($exists) return;

        $free = (int) get_option('tbot_free_credits_signup', self::FREE_ON_SIGNUP);
        $wpdb->insert(self::table(), [
            'user_id'        => $user_id,
            'balance'        => $free,
            'lifetime_earned'=> $free,
            'lifetime_spent' => 0,
            'created_at'     => current_time('mysql'),
        ]);
        self::log_tx($user_id, 'signup_bonus', $free, 'Créditos de bienvenida');
    }

    // ── Deducción atómica ─────────────────────────────────────────────────────

    /**
     * Deduce créditos según el costo del modelo. Usa UPDATE con condición para evitar race conditions.
     * Retorna false si saldo insuficiente.
     */
    public static function deduct(int $user_id, string $model_key, string $note = ''): bool {
        $cost = self::get_cost($model_key);
        if ($cost === 0) return true; // Si es gratis, no deduce nada.

        global $wpdb;
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . "
             SET balance = balance - %d, lifetime_spent = lifetime_spent + %d, updated_at = %s
             WHERE user_id = %d AND balance >= %d",
            $cost, $cost, current_time('mysql'), $user_id, $cost
        ));

        if (!$rows) return false;

        wp_cache_delete("tbot_credits_{$user_id}", 'tbot');
        self::log_tx($user_id, "use_{$model_key}", -$cost, $note ?: "Uso de modelo: {$model_key}");
        return true;
    }

    public static function refund(int $user_id, string $model_key, string $note = ''): bool {
        $final_note = $note ?: "Reembolso {$model_key}";
        return self::add($user_id, self::get_cost($model_key), 'refund', $final_note);
    }

    // ── Añadir créditos ───────────────────────────────────────────────────────

    public static function add(int $user_id, int $amount, string $reason = 'purchase', string $note = ''): bool {
        if ($amount <= 0) return false;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, balance, lifetime_earned, lifetime_spent, created_at)
             VALUES (%d, %d, %d, 0, %s)
             ON DUPLICATE KEY UPDATE
                balance = balance + %d,
                lifetime_earned = lifetime_earned + %d,
                updated_at = %s",
            $user_id, $amount, $amount, current_time('mysql'),
            $amount, $amount, current_time('mysql')
        ));
        wp_cache_delete("tbot_credits_{$user_id}", 'tbot');
        self::log_tx($user_id, $reason, $amount, $note);
        Logger::log('credits', "User#{$user_id} +{$amount} ({$reason})");
        return true;
    }

    // ── Packs ─────────────────────────────────────────────────────────────────

    public static function get_packs(): array {
        $saved = get_option('tbot_credit_packs', '');
        if ($saved) {
            $d = json_decode($saved, true);
            if (is_array($d) && !empty($d)) return $d;
        }
        return [
            ['id'=>'pack_s', 'name'=>'Pack S',  'credits'=>1000,  'price_usd'=>5.00,   'stars'=>385,   'stripe_link'=>'', 'popular'=>false],
            ['id'=>'pack_m', 'name'=>'Pack M',  'credits'=>5000,  'price_usd'=>20.00,  'stars'=>1540,  'stripe_link'=>'', 'popular'=>true ],
            ['id'=>'pack_l', 'name'=>'Pack L',  'credits'=>15000, 'price_usd'=>50.00,  'stars'=>3850,  'stripe_link'=>'', 'popular'=>false],
            ['id'=>'pack_xl','name'=>'Pack XL', 'credits'=>50000, 'price_usd'=>150.00, 'stars'=>11550, 'stripe_link'=>'', 'popular'=>false],
        ];
    }

    public static function activate_pack(int $user_id, string $pack_id, string $via = 'stars'): bool {
        foreach (self::get_packs() as $p) {
            if ($p['id'] === $pack_id) {
                $credits = (int) $p['credits'];
                self::add($user_id, $credits, 'purchase', "{$p['name']} via {$via}");

                // +10% bonus en la primera compra
                global $wpdb;
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tbot_credit_transactions WHERE user_id=%d AND type='purchase'",
                    $user_id
                ));
                if ($count === 1) {
                    self::add($user_id, (int)($credits * 0.1), 'first_purchase_bonus', '+10% primera compra 🎉');
                }
                return true;
            }
        }
        return false;
    }

    // ── Mensajes multilingüe ──────────────────────────────────────────────────

    public static function no_credits_msg(string $lang = 'es'): string {
        $t = [
            'es' => "💳 <b>Sin créditos</b>\n\nHas agotado tus créditos gratuitos.\nTe sugerimos adquirir una <b>Suscripción</b> para obtener uso continuo, o usar /topup para recargar créditos individuales.",
            'en' => "💳 <b>No credits left</b>\n\nYou have exhausted your free credits.\nWe suggest getting a <b>Subscription</b> for continuous use, or use /topup to buy individual credits.",
            'pt' => "💳 <b>Sem créditos</b>\n\nVocê esgotou seus créditos gratuitos.\nSugerimos adquirir uma <b>Assinatura</b> para uso contínuo, ou usar /topup para recarregar créditos individuais.",
        ];
        return $t[$lang] ?? $t['es'];
    }

    public static function balance_msg(int $user_id, string $lang = 'es'): string {
        $s    = self::get_stats($user_id);
        $bal  = (int) $s['balance'];
        $msgs = self::get_cost('text_basic') > 0 ? (int)($bal / self::get_cost('text_basic')) : 0;
        if ($lang === 'en') {
            return "💳 <b>Credits</b>\n\nBalance: <b>{$bal} credits</b> (~{$msgs} messages)\n\nEarned: {$s['lifetime_earned']} | Spent: {$s['lifetime_spent']}\n\n/topup — Buy more credits";
        }
        if ($lang === 'pt') {
            return "💳 <b>Créditos</b>\n\nSaldo: <b>{$bal} créditos</b> (~{$msgs} msgs)\n\nGanho: {$s['lifetime_earned']} | Gasto: {$s['lifetime_spent']}\n\n/topup — Comprar créditos";
        }
        return "💳 <b>Tus Créditos</b>\n\nSaldo: <b>{$bal} créditos</b> (~{$msgs} mensajes)\n\nGanado: {$s['lifetime_earned']} | Gastado: {$s['lifetime_spent']}\n\n/topup — Comprar créditos";
    }

    // ── Log interno ───────────────────────────────────────────────────────────

    private static function log_tx(int $user_id, string $type, int $amount, string $note = ''): void {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tbot_credit_transactions', [
            'user_id'    => $user_id,
            'type'       => $type,
            'amount'     => $amount,
            'note'       => mb_substr($note, 0, 255),
            'created_at' => current_time('mysql'),
        ]);
    }

    // ── Instalar tablas ───────────────────────────────────────────────────────

    public static function install_tables(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $sql = "
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tbot_credits (
            user_id          BIGINT  NOT NULL,
            balance          INT     NOT NULL DEFAULT 0,
            lifetime_earned  INT     NOT NULL DEFAULT 0,
            lifetime_spent   INT     NOT NULL DEFAULT 0,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) {$c};
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tbot_credit_transactions (
            id          BIGINT      NOT NULL AUTO_INCREMENT,
            user_id     BIGINT      NOT NULL,
            type        VARCHAR(50) NOT NULL,
            amount      INT         NOT NULL,
            note        VARCHAR(255),
            created_at  DATETIME    DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user (user_id),
            INDEX idx_date (created_at)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
