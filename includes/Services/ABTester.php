<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * ABTester — A/B testing de mensajes de bienvenida y onboarding.
 *
 * Cómo funciona:
 *   1. Admin define variantes (A/B/C...) del mensaje de bienvenida
 *   2. Cada usuario nuevo es asignado aleatoriamente a una variante
 *   3. Se trackean conversiones (ej. primer mensaje enviado, compra)
 *   4. Dashboard muestra métricas por variante
 *
 * La variante se persiste en tbot_user_state.ab_variant.
 */
class ABTester {

    const OPTION_KEY = 'tbot_ab_tests';

    /**
     * Asigna una variante a un usuario nuevo (si hay test activo).
     * Llamar en el flujo de onboarding (/start).
     *
     * @return string|null La variante asignada ('A', 'B', etc.) o null si no hay test
     */
    public static function assign(int $user_id, string $test_id = 'welcome'): ?string {
        $tests = self::get_tests();
        if (empty($tests[$test_id]) || empty($tests[$test_id]['active'])) return null;

        $test     = $tests[$test_id];
        $variants = $test['variants'] ?? [];
        if (empty($variants)) return null;

        // Verificar si ya tiene variante
        $existing = get_user_meta($user_id, "tbot_ab_{$test_id}", true);
        if ($existing) return $existing;

        // Asignar aleatoriamente según weights
        $variant = self::weighted_random($variants);
        update_user_meta($user_id, "tbot_ab_{$test_id}", $variant);

        // Incrementar contador de asignaciones
        $counts = get_option("tbot_ab_counts_{$test_id}", []);
        $counts[$variant] = ($counts[$variant] ?? 0) + 1;
        update_option("tbot_ab_counts_{$test_id}", $counts);

        Logger::log('ab_test', "Assigned user#{$user_id} to {$test_id}:{$variant}");
        return $variant;
    }

    /**
     * Obtiene el mensaje de bienvenida según la variante del usuario.
     * Fallback al mensaje por defecto si no hay test activo.
     */
    public static function get_welcome_message(int $user_id, string $lang, string $name): string {
        $variant = self::assign($user_id, 'welcome');
        $tests   = self::get_tests();
        $test    = $tests['welcome'] ?? [];

        if (!$variant || empty($test['messages'][$variant])) {
            // Default welcome
            return match($lang) {
                'en' => "👋 Welcome, <b>{$name}</b>! I'm your AI assistant.\n\nSend me any question to get started! 🚀",
                default => "👋 ¡Hola, <b>{$name}</b>! Soy tu asistente IA.\n\n¡Envíame cualquier pregunta para empezar! 🚀",
            };
        }

        $msg = $test['messages'][$variant][$lang] ?? $test['messages'][$variant]['es'] ?? '';
        return str_replace('{nombre}', $name, $msg);
    }

    /**
     * Registra una conversión para el test.
     * Eventos típicos: 'first_message', 'first_purchase', 'day_3_active'
     */
    public static function track_conversion(int $user_id, string $test_id, string $event): void {
        $variant = get_user_meta($user_id, "tbot_ab_{$test_id}", true);
        if (!$variant) return;

        $key = "tbot_ab_conv_{$test_id}_{$event}";
        $convs = get_option($key, []);
        $convs[$variant] = ($convs[$variant] ?? 0) + 1;
        update_option($key, $convs);
    }

    /**
     * Obtiene estadísticas del test para el dashboard admin.
     *
     * @return array{variants: array, stats: array}
     */
    public static function get_stats(string $test_id = 'welcome'): array {
        $tests  = self::get_tests();
        $test   = $tests[$test_id] ?? [];
        $counts = get_option("tbot_ab_counts_{$test_id}", []);

        $events = ['first_message', 'first_purchase', 'day_3_active'];
        $stats  = [];
        foreach ($test['variants'] ?? [] as $v => $weight) {
            $assigned  = $counts[$v] ?? 0;
            $row       = ['variant' => $v, 'assigned' => $assigned, 'weight' => $weight];
            foreach ($events as $ev) {
                $convs = get_option("tbot_ab_conv_{$test_id}_{$ev}", []);
                $conv_count = $convs[$v] ?? 0;
                $row[$ev]         = $conv_count;
                $row["{$ev}_pct"] = $assigned > 0 ? round(($conv_count / $assigned) * 100, 1) : 0;
            }
            $stats[] = $row;
        }

        return ['test_id' => $test_id, 'test' => $test, 'stats' => $stats];
    }

    // ── Admin AJAX ────────────────────────────────────────────────────────────

    public static function init_hooks(): void {
        add_action('wp_ajax_tbot_save_ab_test', [self::class, 'ajax_save_test']);
        add_action('wp_ajax_tbot_ab_stats',     [self::class, 'ajax_get_stats']);
    }

    public static function ajax_save_test(): void {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $test_id = sanitize_key($_POST['test_id'] ?? 'welcome');
        $active  = ($_POST['active'] ?? '0') === '1';
        $data    = json_decode(stripslashes($_POST['config'] ?? '{}'), true);

        $tests = self::get_tests();
        $tests[$test_id] = [
            'active'   => $active,
            'variants' => $data['variants'] ?? ['A' => 50, 'B' => 50],
            'messages' => $data['messages'] ?? [],
        ];
        update_option(self::OPTION_KEY, json_encode($tests));
        wp_send_json_success(['message' => 'Test guardado']);
    }

    public static function ajax_get_stats(): void {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $test_id = sanitize_key($_GET['test_id'] ?? 'welcome');
        wp_send_json_success(self::get_stats($test_id));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function get_tests(): array {
        $raw = get_option(self::OPTION_KEY, '');
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * Selecciona una variante aleatoria respetando los pesos.
     * $variants = ['A' => 60, 'B' => 30, 'C' => 10]
     */
    private static function weighted_random(array $variants): string {
        $total = array_sum($variants);
        $rand  = mt_rand(1, max(1, $total));
        $acc   = 0;
        foreach ($variants as $variant => $weight) {
            $acc += $weight;
            if ($rand <= $acc) return (string) $variant;
        }
        return (string) array_key_first($variants);
    }
}
