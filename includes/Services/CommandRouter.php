<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * CommandRouter — Enruta comandos y callbacks de Telegram.
 */
class CommandRouter {

    private TelegramService $tg;

    public function __construct(TelegramService $tg) {
        $this->tg = $tg;
    }

    public function dispatch(array $update, \WP_USER $wp_user): void {
        $user_id = $wp_user->ID;
        $lang    = get_user_meta($user_id, 'tbot_language', true) ?: 'es';
        $plan    = get_user_meta($user_id, 'tbot_subscription', true) ?: 'free';
        $tg_from = $update['message']['from'] ?? $update['callback_query']['from'] ?? [];
        $name    = sanitize_text_field($tg_from['first_name'] ?? '');

        if (isset($update['callback_query'])) {
            $cq    = $update['callback_query'];
            $this->tg->answer_callback($cq['id']);
            $chat_id = (string) ($cq['message']['chat']['id'] ?? $cq['from']['id']);
            $msg_id  = (int) ($cq['message']['message_id'] ?? 0);
            $this->handle_callback($cq['data'], $user_id, $chat_id, $msg_id, $name, $lang, $plan);
            return;
        }

        if (isset($update['message'])) {
            $msg     = $update['message'];
            $chat_id = (string) ($msg['chat']['id'] ?? $msg['from']['id']);
            $text    = trim($msg['text'] ?? '');

            if (!str_starts_with($text, '/')) return;

            $parts = explode(' ', $text, 2);
            $cmd   = strtolower(explode('@', $parts[0])[0]);
            $args  = trim($parts[1] ?? '');

            $this->handle_command($cmd, $args, $user_id, $chat_id, $name, $lang, $plan);
        }
    }

    private function handle_command($cmd, $args, $user_id, $chat_id, $name, $lang, $plan): void {
        $menu = new MenuBuilder($lang, $plan);

        switch ($cmd) {
            case '/start':
                $onboarded = get_user_meta($user_id, 'tbot_onboarded', true);
                if (!$onboarded) {
                    update_user_meta($user_id, 'tbot_onboarding_step', '1');
                    $d = $menu->onboarding_lang($name);
                    $this->tg->send_message($chat_id, $d['text'], 'HTML', $d['keyboard']);
                } else {
                    $d = $menu->welcome($name);
                    $this->tg->send_message($chat_id, $d['text'], 'HTML', $d['keyboard']);
                }
                break;
            case '/perfil':
            case '/profile':
                if (empty($args)) {
                    $profile = get_user_meta($user_id, 'tbot_user_profile', true);
                    $msg = $profile 
                        ? "👤 <b>Tu Perfil Actual:</b>\n<i>{$profile}</i>\n\nPara cambiarlo usa:\n<code>/perfil [tu nueva descripción]</code>" 
                        : "👤 <b>No tienes un perfil configurado.</b>\n\nPara ayudar a la IA a conocerte mejor, usa el comando así:\n<code>/perfil Soy desarrollador, me gusta el diseño y tengo 30 años.</code>";
                    $this->tg->send_message($chat_id, $msg, 'HTML');
                } else {
                    update_user_meta($user_id, 'tbot_user_profile', sanitize_textarea_field($args));
                    $this->tg->send_message($chat_id, "✅ <b>Perfil actualizado.</b>\nLa IA usará esta información en tus futuras conversaciones.", 'HTML');
                }
                break;
            case '/help':
            case '/ayuda':
                $d = $menu->help();
                $this->tg->send_message($chat_id, $d['text'], 'HTML', $d['keyboard']);
                break;

            case '/delete_data':
                $this->delete_user_data($user_id, $chat_id);
                break;
        }
    }

    private function handle_callback($data, $user_id, $chat_id, $msg_id, $name, $lang, $plan): void {
        $menu = new MenuBuilder($lang, $plan);

        if (str_starts_with($data, 'onb_lang:')) {
            $new_lang = substr($data, 9);
            update_user_meta($user_id, 'tbot_language', $new_lang);
            update_user_meta($user_id, 'tbot_onboarding_step', '2');
            $new_menu = new MenuBuilder($new_lang, $plan);
            $d = $new_menu->onboarding_persona();
            $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
            return;
        }

        if (str_starts_with($data, 'onb_persona:')) {
            $persona = substr($data, 12);
            $this->onboarding_set_persona($user_id, $persona);
            update_user_meta($user_id, 'tbot_onboarded', '1');
            delete_user_meta($user_id, 'tbot_onboarding_step');
            $d = $menu->welcome($name);
            $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
            return;
        }

        if (str_starts_with($data, 'topup:')) {
            $pack_id = substr($data, 6);
            $packs   = CreditManager::get_packs();
            $pack    = array_values(array_filter($packs, fn($p) => $p['id'] === $pack_id))[0] ?? null;

            if ($pack) {
                $stars = (int) ($pack['stars'] ?? 0);
                if ($stars > 0) {
                    $this->tg->send_invoice(
                        $chat_id,
                        "Recarga: {$pack['name']}",
                        "Recarga de {$pack['credits']} créditos.",
                        "topup:{$pack_id}",
                        [['label' => $pack['name'], 'amount' => $stars]],
                        'XTR'
                    );
                } else {
                    $this->tg->send_message($chat_id, "⚠️ Error: El paquete no tiene configurado un precio en Telegram Stars.", 'HTML');
                }
            }
            return;
        }

        if ($data === 'pay_stars_std') {
            $std_stars = (int) get_option('tbot_plan_standard_stars', 925);
            $this->tg->send_invoice(
                $chat_id,
                "Plan Standard",
                "Suscripción mensual Standard (300 msgs/día).",
                "sub:standard",
                [['label' => 'Plan Standard', 'amount' => $std_stars]],
                'XTR'
            );
            return;
        }

        if ($data === 'pay_stars_pre') {
            $pre_stars = (int) get_option('tbot_plan_premium_stars', 1540);
            $this->tg->send_invoice(
                $chat_id,
                "Plan Premium",
                "Suscripción mensual Premium (500 msgs/día, Bots Personales, Memoria Larga).",
                "sub:premium",
                [['label' => 'Plan Premium', 'amount' => $pre_stars]],
                'XTR'
            );
            return;
        }

        if (str_starts_with($data, 'set_lang:')) {
            $new_lang = substr($data, 9);
            update_user_meta($user_id, 'tbot_language', $new_lang);
            $new_menu = new MenuBuilder($new_lang, $plan);
            $d = $new_menu->settings_menu('Asistente');
            $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
            return;
        }

        if (str_starts_with($data, 'set_persona:')) {
            $persona = substr($data, 12);
            $this->onboarding_set_persona($user_id, $persona);
            $d = $menu->settings_menu($persona);
            $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
            return;
        }

        switch ($data) {
            case 'main_menu':
                $d = $menu->welcome($name);
                $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
                break;
            case 'settings_lang':
                $d = $menu->language_menu();
                $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
                break;
            case 'settings_persona':
                $d = $menu->persona_menu();
                $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
                break;
            case 'about_me_menu':
                $profile = get_user_meta($user_id, 'tbot_user_profile', true);
                $msg = $profile 
                    ? "👤 <b>Tu Perfil Actual:</b>\n<i>{$profile}</i>\n\nPara cambiarlo usa el comando:\n<code>/perfil [tu nueva descripción]</code>" 
                    : "👤 <b>No tienes un perfil configurado.</b>\n\nPara ayudar a la IA a conocerte mejor, usa el comando así:\n<code>/perfil Soy desarrollador, me gusta el diseño y tengo 30 años.</code>";
                $this->tg->send_message($chat_id, $msg, 'HTML');
                break;
            case 'settings':
                $persona_key = get_user_meta($user_id, 'tbot_persona_key', true) ?: 'asistente';
                $names = ['asistente' => 'Asistente', 'dev' => 'Programador', 'writer' => 'Escritor', 'coach' => 'Coach', 'custom' => 'Personalizado'];
                $persona_name = $names[$persona_key] ?? 'Asistente';
                $d = $menu->settings_menu($persona_name);
                $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
                break;
            case 'view_plans':
                $d = $menu->subscription_menu($user_id);
                $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
                break;
            case 'help':
                $d = $menu->help();
                $this->tg->edit_message_text($chat_id, $msg_id, $d['text'], 'HTML', $d['keyboard']);
                break;
            case 'export_trigger':
                $this->export_user_data($user_id, $chat_id);
                break;
            case 'delete_trigger':
                $this->delete_user_data($user_id, $chat_id);
                break;
            case 'close_msg':
                $this->tg->delete_message($chat_id, $msg_id);
                break;
        }
    }

    private function export_user_data($user_id, $chat_id): void {
        $meta = get_user_meta($user_id);
        $balance = CreditManager::get_balance($user_id);
        $history = ChatMemory::get_recent($user_id, 10);
        
        $export = "📦 <b>Tus Datos Exportados</b>\n\n";
        $export .= "<b>ID:</b> {$user_id}\n";
        $export .= "<b>Créditos:</b> {$balance}\n";
        $export .= "<b>Plan:</b> " . ($meta['tbot_subscription'][0] ?? 'free') . "\n";
        $export .= "<b>Idioma:</b> " . ($meta['tbot_language'][0] ?? 'es') . "\n";
        $export .= "<b>Mejor Racha:</b> " . ($meta['tbot_streak_best'][0] ?? '0') . " días\n\n";
        
        $export .= "<b>Últimos mensajes en memoria:</b>\n";
        if (empty($history)) {
            $export .= "No hay mensajes en memoria.\n";
        } else {
            foreach ($history as $h) {
                $role = $h['role'] === 'user' ? '👤 Tú' : '🤖 Bot';
                $content = mb_strimwidth($h['content'], 0, 50, '...');
                $export .= "<i>{$role}:</i> {$content}\n";
            }
        }
        
        $this->tg->send_message($chat_id, $export, 'HTML');
    }

    private function onboarding_set_persona($user_id, $key): void {
        $prompts = [
            'asistente' => 'Eres un asistente útil y profesional.',
            'dev'       => 'Eres un experto programador senior.',
            'coach'     => 'Eres un coach de vida y productividad.',
            'writer'    => 'Eres un escritor creativo experto.'
        ];
        if (isset($prompts[$key])) {
            update_user_meta($user_id, 'tbot_persona_prompt', $prompts[$key]);
            update_user_meta($user_id, 'tbot_persona_key', $key);
        } elseif ($key === 'custom') {
            // Handle custom persona prompt request later, default to assistant for now
            update_user_meta($user_id, 'tbot_persona_prompt', 'Eres un asistente personalizado.');
            update_user_meta($user_id, 'tbot_persona_key', 'custom');
        }
    }

    private function delete_user_data($user_id, $chat_id): void {
        ChatMemory::clear($user_id);
        
        // Eliminar meta del usuario
        delete_user_meta($user_id, 'tbot_language');
        delete_user_meta($user_id, 'tbot_onboarded');
        delete_user_meta($user_id, 'tbot_onboarding_step');
        delete_user_meta($user_id, 'tbot_persona_prompt');
        delete_user_meta($user_id, 'tbot_subscription');
        delete_user_meta($user_id, 'tbot_subscription_expires');
        delete_user_meta($user_id, 'tbot_streak_current');
        delete_user_meta($user_id, 'tbot_streak_best');
        delete_user_meta($user_id, 'tbot_streak_last');
        
        // Eliminar créditos (opcionalmente se puede mantener el histórico, pero borramos el balance)
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'tbot_credits', ['user_id' => $user_id]);
        wp_cache_delete("tbot_credits_{$user_id}", 'tbot');

        $this->tg->send_message($chat_id, "🗑️ <b>Todos tus datos han sido eliminados de nuestros servidores.</b>\n\nPuedes usar /start para comenzar de nuevo.", 'HTML');
    }
}
