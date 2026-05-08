<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * WebhookHandler — Pipeline central de updates de Telegram.
 */
class WebhookHandler {

    public function process(\WP_REST_Request $request): array {
        $body  = $request->get_json_params();
        if (empty($body)) return ['status' => 'no_body'];

        $tg    = new TelegramService();
        
        // ── Extraer "from" del update ─────────────────────────────────────────
        $tg_user = $body['message']['from']
            ?? $body['callback_query']['from']
            ?? $body['pre_checkout_query']['from']
            ?? null;

        if (!$tg_user) return ['status' => 'no_user'];

        $tg_id = (int) $tg_user['id'];

        // ── 1. Rate Limiter (30 msgs/min por tg_id) ───────────────────────────
        $rl_key  = 'tbot_rl_' . $tg_id;
        $rl_hits = (int) get_transient($rl_key);
        if ($rl_hits >= 30) {
            Logger::log('security', "Rate limited: tg#{$tg_id}");
            return ['status' => 'rate_limited'];
        }
        set_transient($rl_key, $rl_hits + 1, 60);

        // ── pre_checkout_query → responder ANTES de todo ──────────────────────
        if (isset($body['pre_checkout_query'])) {
            $pq = $body['pre_checkout_query'];
            $tg->answer_pre_checkout($pq['id'], true);
            return ['status' => 'pre_checkout_ok'];
        }

        // ── successful_payment ────────────────────────────────────────────────
        if (isset($body['message']['successful_payment'])) {
            $sp = $body['message']['successful_payment'];
            $payload = $sp['invoice_payload'] ?? '';
            
            // Sincronizar usuario WP para poder asignarle cosas
            $users   = new UserManager();
            $wp_user = $users->get_or_create_by_tg_id($tg_id, $tg_user);
            if (!is_wp_error($wp_user)) {
                $user_id = $wp_user->ID;
                if ($payload === 'sub:standard') {
                    $sm = new SubscriptionManager();
                    $sm->activate_subscription($user_id, 'standard');
                    $tg->send_message($tg_id, "✅ <b>¡Pago exitoso!</b>\nTu suscripción <b>Standard</b> ha sido activada.", 'HTML');
                } elseif ($payload === 'sub:premium') {
                    $sm = new SubscriptionManager();
                    $sm->activate_subscription($user_id, 'premium');
                    $tg->send_message($tg_id, "✅ <b>¡Pago exitoso!</b>\nTu suscripción <b>Premium</b> ha sido activada.", 'HTML');
                } elseif (str_starts_with($payload, 'topup:')) {
                    $pack_id = substr($payload, 6);
                    CreditManager::activate_pack($user_id, $pack_id, 'stars');
                    $tg->send_message($tg_id, "✅ <b>¡Recarga exitosa!</b>\nTus créditos han sido añadidos.", 'HTML');
                }
            }
            return ['status' => 'payment_success'];
        }

        // ── 2. Sincronizar usuario WP ─────────────────────────────────────────
        $users   = new UserManager();
        $wp_user = $users->get_or_create_by_tg_id($tg_id, $tg_user);
        if (is_wp_error($wp_user)) return ['status' => 'user_sync_error'];

        $user_id = $wp_user->ID;

        // ── Hot-path: leer estado del usuario ─────────────────────────────────
        CreditManager::initialize($user_id);
        $ustate  = UserState::get($user_id);
        $lang    = $ustate['lang'] ?: 'es';
        $plan    = $ustate['plan'] ?: 'free';

        // ── Texto libre / Media / Comandos rápidos ────────────────────────────
        $chat_id   = (string) ($body['message']['chat']['id'] ?? $tg_id);
        $chat_type = $body['message']['chat']['type'] ?? 'private';
        $text      = trim($body['message']['text'] ?? '');

        // Comandos rápidos (no pasan por CommandRouter)
        $cmd_lower = strtolower(explode(' ', $text)[0] ?? '');
        if (in_array($cmd_lower, ['/balance', '/saldo', '/creditos', '/credits'])) {
            $tg->send_message($chat_id, CreditManager::balance_msg($user_id, $lang), 'HTML', $this->topup_keyboard($lang, $plan, $user_id));
            return ['status' => 'balance_shown'];
        }
        if (in_array($cmd_lower, ['/topup', '/recargar', '/comprar', '/buy'])) {
            $tg->send_message($chat_id, $this->topup_message($lang), 'HTML', $this->topup_keyboard($lang, $plan, $user_id));
            return ['status' => 'topup_shown'];
        }

        // ── Callback / Comando (Restantes) → CommandRouter ───────────────────
        $router = new CommandRouter($tg);
        if (isset($body['callback_query']) || $this->is_command($body)) {
            $router->dispatch($body, $wp_user);
            return ['status' => 'command_or_callback_handled'];
        }

        // Modo Grupo
        if (in_array($chat_type, ['group', 'supergroup'])) {
            $bot_username = '@' . ltrim((string) get_option('tbot_bot_username', ''), '@');
            $is_mentioned = $bot_username !== '@' && str_contains($text, $bot_username);
            $is_reply_to_bot = isset($body['message']['reply_to_message']['from']['is_bot'])
                && $body['message']['reply_to_message']['from']['is_bot'] === true;
            if (!$is_mentioned && !$is_reply_to_bot) {
                return ['status' => 'group_ignored'];
            }
            $text = trim(str_replace($bot_username, '', $text));
        }

        // ── Tipo de operación y Modelo ─────────────────────────────────────────
        $operation = 'text_basic';
        $has_voice = isset($body['message']['voice']);
        $has_photo = isset($body['message']['photo']);
        
        if ($has_voice) $operation = 'voice';
        elseif ($has_photo) $operation = 'photo';
        elseif (stripos($text, 'genera una imagen') !== false || stripos($text, 'dibuja') !== false) $operation = 'image_gen';

        // ── Restricciones del Plan Free ────────────────────────────────────────
        if ($plan === 'free') {
            $allow_photo = get_option('tbot_free_allow_photo', '1') === '1';
            $allow_voice = get_option('tbot_free_allow_voice', '1') === '1';
            $allow_image = get_option('tbot_free_allow_image', '0') === '1';

            if (($operation === 'photo' && !$allow_photo) ||
                ($operation === 'voice' && !$allow_voice) ||
                ($operation === 'image_gen' && !$allow_image)) {
                
                $menu = new MenuBuilder($lang, $plan);
                $d = $menu->subscription_menu();
                $msg = $lang === 'en' 
                    ? "💎 <b>Premium Feature</b>\n\nThis feature requires a Subscription. Upgrade now to unlock it!"
                    : "💎 <b>Función Premium</b>\n\nEsta característica requiere una Suscripción. ¡Mejora tu plan para desbloquearla!";
                
                $tg->send_message($chat_id, $msg, 'HTML', $d['keyboard']);
                return ['status' => 'feature_restricted'];
            }
        }

        $model_cfg  = \TBot\Admin\AIModels::get_config();
        $plan_key   = in_array($plan, ['premium', 'admin']) ? 'premium' : ($plan === 'standard' ? 'standard' : 'free');
        $op_key     = ($operation === 'photo') ? 'vision' : (($operation === 'text_advanced') ? 'text_advanced' : 'text_basic');
        $model_key  = $model_cfg[$plan_key][$op_key] ?? 'gpt-4o-mini';

        // Override model_key if voice (handled differently in AIService)
        if ($operation === 'voice') $model_key = 'voice';

        // ── Verificar créditos ────────────────────────────────────────────────
        if (!CreditManager::can_afford($user_id, $model_key)) {
            $menu = new MenuBuilder($lang, $plan);
            $d = $menu->subscription_menu();
            $tg->send_message($chat_id, CreditManager::no_credits_msg($lang), 'HTML', $d['keyboard']);
            return ['status' => 'no_credits'];
        }

        // ── 5. Streak update ──────────────────────────────────────────────────
        $streak_event = StreakManager::check_and_update($user_id);

        // ── 6. Indicador de escritura ─────────────────────────────────────────
        $tg->send_chat_action($chat_id, $has_voice ? 'record_voice' : 'typing');

        // ── 7. Procesar con IA ───────────────────────────────────────────────
        $persona_prompt = get_user_meta($user_id, 'tbot_persona_prompt', true) ?: 'Eres un asistente útil y profesional.';
        $user_profile = get_user_meta($user_id, 'tbot_user_profile', true);
        if ($user_profile) {
            $persona_prompt .= "\n\nInformación sobre el usuario (Tenla en cuenta en tus respuestas si es relevante): " . $user_profile;
        }

        CreditManager::deduct($user_id, $model_key, $operation);

        AIService::process_and_reply([
            'wp_user_id'     => $user_id,
            'tg_id'          => $tg_id,
            'chat_id'        => $chat_id,
            'bot_token'      => '', // Prevent TypeError in TelegramService constructor
            'text'           => $text,
            'lang'           => $lang,
            'subscription'   => $plan,
            'operation'      => $operation,
            'model'          => $model_key,
            'persona_prompt' => $persona_prompt,
            'photo'          => $body['message']['photo'] ?? null,
            'voice'          => $body['message']['voice'] ?? null,
        ]);

        return ['status' => 'processed'];
    }

    private function is_command(array $body): bool {
        return str_starts_with(trim($body['message']['text'] ?? ''), '/');
    }

    private function topup_keyboard(string $lang, string $plan, int $user_id = 0): array {
        $packs = CreditManager::get_packs();
        $methods_raw = get_option('tbot_payment_methods', 'stripe,stars');
        $methods = is_array($methods_raw) ? $methods_raw : array_map('trim', explode(',', $methods_raw));

        $rows  = [];
        foreach ($packs as $p) {
            $label = ($p['popular'] ?? false) ? "{$p['name']} ({$p['credits']} cr) ⭐" : "{$p['name']} ({$p['credits']} cr)";
            $row = [];
            
            if (in_array('stars', $methods)) {
                $row[] = ['text' => "⭐ Stars", 'callback_data' => 'topup:' . $p['id']];
            }
            if (in_array('stripe', $methods) && !empty($p['stripe_link'])) {
                $stripe_link = $p['stripe_link'];
                if ($user_id > 0) {
                    $qs = (strpos($stripe_link, '?') !== false) ? '&' : '?';
                    $stripe_link .= "{$qs}client_reference_id={$user_id}";
                }
                $row[] = ['text' => "💳 Tarjeta", 'url' => $stripe_link];
            }
            
            if (empty($row)) {
                // Si no hay métodos, solo mostrar informativo sin acción
                $rows[] = [['text' => $label, 'callback_data' => 'ignore']];
            } else {
                // Añadir fila de título y fila de botones
                $rows[] = [['text' => $label, 'callback_data' => 'ignore']];
                $rows[] = $row;
            }
        }
        return ['inline_keyboard' => $rows];
    }

    private function topup_message(string $lang): string {
        $packs = CreditManager::get_packs();
        $lines = $lang === 'en' ? "💳 <b>Buy Credits</b>\n\n" : "💳 <b>Comprar Créditos</b>\n\n";
        foreach ($packs as $p) {
            $star = ($p['popular'] ?? false) ? ' ← popular' : '';
            $price = $p['price_usd'] ?? '0.00';
            $stars = $p['stars'] ?? '0';
            $lines .= "• <b>{$p['name']}</b>: {$p['credits']} cr. — \${$price} / {$stars} ⭐{$star}\n";
        }
        return $lines;
    }
}
