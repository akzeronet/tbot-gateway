<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * MenuBuilder — Constructor de teclados inline y mensajes de menú.
 *
 * Genera los mensajes y botones dinámicamente según:
 * - El plan del usuario (free / standard / premium)
 * - El idioma del usuario (es / en / pt)
 * - Los métodos de pago habilitados en el admin
 * - Las personalidades configuradas en el admin
 */
class MenuBuilder {

    private string $lang;
    private string $plan;

    // Textos configurables desde el admin (con fallback en código)
    private array $texts;

    public function __construct(string $lang = 'es', string $plan = 'free') {
        $this->lang  = $lang;
        $this->plan  = $plan;
        $this->texts = get_option('tbot_bot_messages', $this->default_texts());
        if (!is_array($this->texts)) {
            $this->texts = json_decode($this->texts, true) ?: $this->default_texts();
        }
    }

    // ── Mensajes de Bienvenida ────────────────────────────────────────────────

    /**
     * Construye el mensaje de bienvenida y sus botones según el plan del usuario.
     *
     * @return array ['text' => string, 'keyboard' => array]
     */
    public function welcome(string $user_first_name = ''): array {
        $is_inactive = ($this->plan === 'inactive');
        $name        = $user_first_name ? ", {$user_first_name}" : '';

        if ($is_inactive) {
            $text = $this->t('welcome_inactive');
        } else {
            $text = str_replace('{name}', $name, $this->t('welcome_active'));
        }

        $keyboard = $is_inactive
            ? $this->inactive_keyboard()
            : $this->main_keyboard();

        return compact('text', 'keyboard');
    }

    // ── Menú de Suscripción ──────────────────────────────────────────────────

    /**
     * Construye el mensaje de planes y sus botones de pago.
     * Incluye botón Cerrar + nota de auto-expiración a 10 min.
     */
    public function subscription_menu(int $user_id = 0): array {
        $std_price   = get_option('tbot_plan_standard_price', '12.00');
        $pre_price   = get_option('tbot_plan_premium_price',  '20.00');
        $std_stars   = (int) get_option('tbot_plan_standard_stars', 925);
        $pre_stars   = (int) get_option('tbot_plan_premium_stars',  1540);
        $std_desc    = get_option('tbot_plan_standard_desc', '300 msgs/día');
        $pre_desc    = get_option('tbot_plan_premium_desc',  '500 msgs/día, Bot Personal, Memoria Larga');

        $methods_raw = get_option('tbot_payment_methods', 'stripe,stars');
        $methods     = is_array($methods_raw) ? $methods_raw : array_map('trim', explode(',', $methods_raw));

        $expire_min  = (int) get_option('tbot_plan_menu_expire_min', 10);
        $expire_note = $this->lang === 'en'
            ? "<i>⏳ This menu closes in {$expire_min} min.</i>"
            : ($this->lang === 'pt' ? "<i>⏳ Este menu fecha em {$expire_min} min.</i>"
            : "<i>⏳ Este menú se cierra en {$expire_min} min.</i>");

        $text  = "💎 <b>" . $this->t('plans_title') . "</b>\n\n";
        $text .= "⭐ <b>Standard</b> — \${$std_price}/mes\n<i>{$std_desc}</i>\n\n";
        $text .= "💎 <b>Premium</b> — \${$pre_price}/mes\n<i>{$pre_desc}</i>\n\n" . $expire_note;

        $rows   = [];
        
        $stripe_link_std = get_option('tbot_stripe_link_std', '');
        $stripe_link_pre = get_option('tbot_stripe_link_pre', '');
        
        // Adjuntar client_reference_id para Stripe Webhook
        if ($user_id > 0) {
            if ($stripe_link_std) {
                $qs = (strpos($stripe_link_std, '?') !== false) ? '&' : '?';
                $stripe_link_std .= "{$qs}client_reference_id={$user_id}";
            }
            if ($stripe_link_pre) {
                $qs = (strpos($stripe_link_pre, '?') !== false) ? '&' : '?';
                $stripe_link_pre .= "{$qs}client_reference_id={$user_id}";
            }
        }

        $row_std = [];
        if (in_array('stars', $methods)) {
            $row_std[] = ['text' => "⭐ Standard ({$std_stars} ★)", 'callback_data' => 'pay_stars_std'];
        }
        if (in_array('stripe', $methods) && !empty($stripe_link_std)) {
            $row_std[] = ['text' => '💳 ' . $this->t('pay_card'), 'url' => $stripe_link_std];
        }
        if ($row_std) $rows[] = $row_std;

        $row_pre = [];
        if (in_array('stars', $methods)) {
            $row_pre[] = ['text' => "💎 Premium ({$pre_stars} ★)", 'callback_data' => 'pay_stars_pre'];
        }
        if (in_array('stripe', $methods) && !empty($stripe_link_pre)) {
            $row_pre[] = ['text' => '💳 ' . $this->t('pay_card'), 'url' => $stripe_link_pre];
        }
        if ($row_pre) $rows[] = $row_pre;

        // Fila inferior: Volver + Cerrar
        $rows[] = [
            ['text' => '⬅️ ' . $this->t('back'),  'callback_data' => 'main_menu'],
            ['text' => '✖ ' . $this->t('close'),   'callback_data' => 'close_msg'],
        ];

        return ['text' => $text, 'keyboard' => ['inline_keyboard' => $rows], 'expire_min' => $expire_min];
    }

    // ── Menú de Ajustes ───────────────────────────────────────────────────────

    public function settings_menu(string $persona_name = 'Asistente'): array {
        $text = "⚙️ <b>" . $this->t('settings_title') . "</b>\n\n"
              . $this->t('settings_persona') . ": <b>{$persona_name}</b>\n"
              . $this->t('settings_lang') . ": <b>" . strtoupper($this->lang) . "</b>";

        $keyboard = ['inline_keyboard' => [
            [
                ['text' => '🎭 ' . $this->t('personality'), 'callback_data' => 'persona_menu'],
                ['text' => '🌐 ' . $this->t('language'),    'callback_data' => 'set_lang_menu'],
            ],
            [
                ['text' => '👤 ' . $this->t('about_me'),    'callback_data' => 'about_me_menu'],
            ],
            [
                ['text' => '⬅️ ' . $this->t('back'),  'callback_data' => 'main_menu'],
                ['text' => '✖ ' . $this->t('close'),   'callback_data' => 'close_msg'],
            ],
        ]];

        return compact('text', 'keyboard');
    }

    // ── Menú de Idiomas ───────────────────────────────────────────────────────

    public function language_menu(): array {
        $text = "🌐 <b>" . $this->t('choose_language') . "</b>";
        $keyboard = ['inline_keyboard' => [
            [
                ['text' => '🇪🇸 Español',   'callback_data' => 'set_lang:es'],
                ['text' => '🇺🇸 English',   'callback_data' => 'set_lang:en'],
                ['text' => '🇧🇷 Português', 'callback_data' => 'set_lang:pt'],
            ],
            [
                ['text' => '⬅️ ' . $this->t('back'),  'callback_data' => 'settings'],
                ['text' => '✖ ' . $this->t('close'),   'callback_data' => 'close_msg'],
            ],
        ]];
        return compact('text', 'keyboard');
    }

    // ── Menú de Personalidades ────────────────────────────────────────────────

    public function persona_menu(): array {
        $text = "🎭 <b>" . $this->t('choose_persona') . "</b>\n\n" . $this->t('persona_desc');

        $personas  = get_option('tbot_personas', []);
        if (!is_array($personas) || empty($personas)) {
            $personas = [
                ['key' => 'asistente', 'icon' => '🤖', 'name' => 'Asistente', 'premium' => 0],
                ['key' => 'dev',       'icon' => '💻', 'name' => 'Programador','premium' => 0],
                ['key' => 'writer',    'icon' => '✍️', 'name' => 'Escritor',   'premium' => 0],
                ['key' => 'coach',     'icon' => '🧘', 'name' => 'Coach',      'premium' => 0],
            ];
        }

        $rows = [];
        $chunk = array_chunk($personas, 2);
        foreach ($chunk as $pair) {
            $row = [];
            foreach ($pair as $p) {
                $is_premium_only = (int)($p['premium'] ?? 0);
                if ($is_premium_only && !in_array($this->plan, ['premium', 'admin'], true)) {
                    $row[] = ['text' => '🔒 ' . $p['name'], 'callback_data' => 'view_plans'];
                } else {
                    $row[] = ['text' => ($p['icon'] ?? '🤖') . ' ' . $p['name'], 'callback_data' => 'set_persona:' . $p['key']];
                }
            }
            $rows[] = $row;
        }

        // Custom persona (solo Premium)
        if (in_array($this->plan, ['premium', 'admin'], true)) {
            $rows[] = [['text' => '✨ ' . $this->t('custom_persona'), 'callback_data' => 'set_persona:custom']];
        } else {
            $rows[] = [['text' => '💎 ' . $this->t('unlock_custom'), 'callback_data' => 'view_plans']];
        }

        $rows[] = [
            ['text' => '⬅️ ' . $this->t('back'),  'callback_data' => 'settings'],
            ['text' => '✖ ' . $this->t('close'),   'callback_data' => 'close_msg'],
        ];

        return ['text' => $text, 'keyboard' => ['inline_keyboard' => $rows]];
    }

    // ── Teclados de Contexto ──────────────────────────────────────────────────

    private function main_keyboard(): array {
        $row1 = [
            ['text' => '💎 ' . $this->t('plans'), 'callback_data' => 'view_plans'],
            ['text' => '⚙️ ' . $this->t('settings'), 'callback_data' => 'settings'],
        ];

        $row2 = [
            ['text' => '📥 ' . $this->t('export'), 'callback_data' => 'export_trigger'],
            ['text' => '❓ ' . $this->t('help'), 'callback_data' => 'help'],
        ];

        $row3 = [
            ['text' => '✖ ' . $this->t('close'), 'callback_data' => 'close_msg'],
        ];

        return ['inline_keyboard' => [$row1, $row2, $row3]];
    }

    private function inactive_keyboard(): array {
        $rows = [
            [['text' => '💎 ' . $this->t('reactivate'), 'callback_data' => 'view_plans']],
            [
                ['text' => '📥 ' . $this->t('export'), 'callback_data' => 'export_trigger'],
                ['text' => '🗑️ ' . $this->t('delete_data'), 'callback_data' => 'delete_trigger'],
            ],
            [
                ['text' => '✖ ' . $this->t('close'), 'callback_data' => 'close_msg'],
            ]
        ];
        return ['inline_keyboard' => $rows];
    }

    // ── Wizard de Onboarding ─────────────────────────────────────────────────

    /**
     * Paso 1: Selección de idioma.
     */
    public function onboarding_lang(string $name = ''): array {
        $texts = [
            'es' => "🚀 <b>¡Hola{name}!</b>\n\nAntes de empezar, necesito configurar algunas cosas.\n\n🌐 <b>Paso 1/4</b> — ¿En qué idioma prefieres que te hable?",
            'en' => "🚀 <b>Hello{name}!</b>\n\nBefore we start, I need to set up a few things.\n\n🌐 <b>Step 1/3</b> — What language do you prefer?",
            'pt' => "🚀 <b>Olá{name}!</b>\n\nAntes de começar, preciso configurar algumas coisas.\n\n🌐 <b>Passo 1/3</b> — Em qual idioma prefere que eu fale?",
        ];
        $text = str_replace('{name}', $name ? ", {$name}" : '', $texts[$this->lang] ?? $texts['es']);

        $keyboard = ['inline_keyboard' => [
            [
                ['text' => '🇪🇸 Español',   'callback_data' => 'onb_lang:es'],
                ['text' => '🇺🇸 English',   'callback_data' => 'onb_lang:en'],
                ['text' => '🇧🇷 Português', 'callback_data' => 'onb_lang:pt'],
            ],
        ]];

        return compact('text', 'keyboard');
    }

    /**
     * Paso 2: Selección de personalidad.
     */
    public function onboarding_persona(): array {
        $texts = [
            'es' => "🎭 <b>Paso 2/4</b> — ¿Cómo quieres que me comporte?\n\nElige una personalidad base. Podrás cambiarla cuando quieras desde /settings.",
            'en' => "🎭 <b>Step 2/4</b> — How would you like me to behave?\n\nChoose a base personality. You can change it anytime from /settings.",
            'pt' => "🎭 <b>Passo 2/4</b> — Como você gostaria que eu me comportasse?\n\nEscolha uma personalidade base. Você pode mudar quando quiser em /settings.",
        ];
        $text = $texts[$this->lang] ?? $texts['es'];

        $personas = get_option('tbot_personas', []);
        if (!is_array($personas) || empty($personas)) {
            $personas = [
                ['key' => 'asistente', 'icon' => '🤖', 'name' => 'Asistente'],
                ['key' => 'dev',       'icon' => '💻', 'name' => 'Programador'],
                ['key' => 'writer',    'icon' => '✍️', 'name' => 'Escritor'],
                ['key' => 'coach',     'icon' => '🧘', 'name' => 'Coach'],
            ];
        }

        $rows = [];
        $chunk = array_chunk($personas, 2);
        foreach ($chunk as $pair) {
            $row = [];
            foreach ($pair as $p) {
                $row[] = ['text' => ($p['icon'] ?? '🤖') . ' ' . $p['name'], 'callback_data' => 'onb_persona:' . $p['key']];
            }
            $rows[] = $row;
        }

        return ['text' => $text, 'keyboard' => ['inline_keyboard' => $rows]];
    }

    /**
     * Menú Acerca de Mí (Configuración)
     */
    public function about_me_menu(string $current_info = ''): array {
        $text = "👤 <b>" . $this->t('about_me') . "</b>\n\n"
              . $this->t('about_me_desc') . "\n\n"
              . "<b>Info actual:</b>\n<i>" . ($current_info ?: 'No definida') . "</i>";

        $keyboard = ['inline_keyboard' => [
            [['text' => $this->t('edit_info'), 'callback_data' => 'edit_about_me']],
            [
                ['text' => '⬅️ ' . $this->t('back'),  'callback_data' => 'settings'],
                ['text' => '✖ ' . $this->t('close'),   'callback_data' => 'close_msg'],
            ],
        ]];

        return compact('text', 'keyboard');
    }

    /**
     * Paso del Wizard: Acerca de Mí
     */
    public function onboarding_about_me(): array {
        $texts = [
            'es' => "👤 <b>Paso 3/4 — Acerca de Mí</b>\n\nCuéntale a la IA quién eres (nombre, profesión, intereses, etc.) para que tus interacciones sean más personalizadas.\n\n<i>Escribe tu descripción a continuación.</i>",
            'en' => "👤 <b>Step 3/4 — About Me</b>\n\nTell the AI who you are (name, profession, interests, etc.) so your interactions are more personalized.\n\n<i>Type your description below.</i>",
            'pt' => "👤 <b>Passo 3/4 — Sobre Mim</b>\n\nDiga à IA quem você é (nome, profissão, interesses, etc.) para que suas interações sejam mais personalizadas.\n\n<i>Escreva sua descrição abaixo.</i>",
        ];
        $text = $texts[$this->lang] ?? $texts['es'];

        $skip_text = match($this->lang) {
            'en' => '⏭️ Skip',
            'pt' => '⏭️ Pular',
            default => '⏭️ Saltar',
        };

        $keyboard = ['inline_keyboard' => [
            [['text' => $skip_text, 'callback_data' => 'onb_skip_about']],
        ]];

        return compact('text', 'keyboard');
    }

    /**
     * Paso 3: Nombrar bot personal (solo si tiene bot delegado).
     */
    public function onboarding_name(): array {
        $texts = [
            'es' => "🏷️ <b>Paso 4/4</b> — Tienes un bot personal conectado.\n\n¿Cómo quieres que se llame tu bot?\n\n<i>Escribe el nombre a continuación (máx. 64 caracteres).</i>",
            'en' => "🏷️ <b>Step 4/4</b> — You have a personal bot connected.\n\nWhat would you like to name your bot?\n\n<i>Type the name below (max 64 characters).</i>",
            'pt' => "🏷️ <b>Passo 4/4</b> — Você tem um bot pessoal conectado.\n\nComo você quer que seu bot se chame?\n\n<i>Escreva o nome abaixo (máx. 64 caracteres).</i>",
        ];
        $text = $texts[$this->lang] ?? $texts['es'];

        $skip_text = match($this->lang) {
            'en' => '⏭️ Skip',
            'pt' => '⏭️ Pular',
            default => '⏭️ Saltar',
        };

        $keyboard = ['inline_keyboard' => [
            [['text' => $skip_text, 'callback_data' => 'onb_skip_name']],
        ]];

        return compact('text', 'keyboard');
    }

    /**
     * Paso final: Resumen de la configuración.
     */
    public function onboarding_summary(string $persona_name, string $lang_label, ?string $bot_name = null): array {
        $bot_line = $bot_name ? "\n🏷️ Bot: <b>{$bot_name}</b>" : '';

        $texts = [
            'es' => "✅ <b>¡Todo listo!</b>\n\nTu asistente está configurado:\n\n🎭 Personalidad: <b>{$persona_name}</b>\n🌐 Idioma: <b>{$lang_label}</b>{$bot_line}\n\n💬 Ahora simplemente escríbeme lo que necesites. ¡Estoy listo para ayudarte!",
            'en' => "✅ <b>All set!</b>\n\nYour assistant is configured:\n\n🎭 Personality: <b>{$persona_name}</b>\n🌐 Language: <b>{$lang_label}</b>{$bot_line}\n\n💬 Now just type what you need. I'm ready to help!",
            'pt' => "✅ <b>Tudo pronto!</b>\n\nSeu assistente está configurado:\n\n🎭 Personalidade: <b>{$persona_name}</b>\n🌐 Idioma: <b>{$lang_label}</b>{$bot_line}\n\n💬 Agora basta escrever o que precisa. Estou pronto para ajudar!",
        ];
        $text = $texts[$this->lang] ?? $texts['es'];

        $start_text = match($this->lang) {
            'en' => '🚀 Start chatting!',
            'pt' => '🚀 Começar a conversar!',
            default => '🚀 ¡Empezar a conversar!',
        };

        $keyboard = ['inline_keyboard' => [
            [['text' => $start_text, 'callback_data' => 'onb_done']],
        ]];

        return compact('text', 'keyboard');
    }

    // ── Mensajes de Sistema ───────────────────────────────────────────────────

    public function quota_warning(int $used, int $limit): string {
        return str_replace(
            ['{used}', '{limit}'],
            [$used, $limit],
            $this->t('quota_warning')
        );
    }

    public function quota_exceeded(): string {
        return $this->t('quota_exceeded');
    }

    public function blacklisted(): string {
        return $this->t('blacklisted');
    }

    public function persona_updated(string $name): string {
        return str_replace('{name}', $name, $this->t('persona_updated'));
    }

    public function language_updated(): string {
        return $this->t('language_updated');
    }

    // ── Internacionalización ──────────────────────────────────────────────────

    public function t(string $key): string {
        return $this->texts[$this->lang][$key]
            ?? $this->texts['es'][$key]
            ?? $key;
    }

    private function default_texts(): array {
        return [
            'es' => [
                'welcome_active'   => "👋 <b>¡Bienvenido{name}!</b>\n\nSoy tu asistente de IA con memoria. ¿En qué puedo ayudarte hoy?\n\nEscribe cualquier pregunta o usa los botones de abajo.",
                'welcome_inactive' => "🔒 <b>Cuenta Inactiva</b>\n\nTu historial se conserva 6 meses. Renueva tu plan para continuar.",
                'plans_title'      => 'Elige tu Plan',
                'pay_card'         => 'Tarjeta/Web',
                'settings_title'   => 'Ajustes',
                'settings_persona' => 'Personalidad',
                'settings_lang'    => 'Idioma',
                'personality'      => 'Personalidad',
                'language'         => 'Idioma',
                'choose_language'  => 'Elige tu idioma',
                'choose_persona'   => 'Elige una Personalidad',
                'persona_desc'     => 'Define cómo quieres que la IA te responda.',
                'custom_persona'   => 'Personalidad Custom',
                'unlock_custom'    => 'Desbloquear Custom (Premium)',
                'plans'            => 'Ver Planes',
                'settings'         => 'Ajustes',
                'help'             => 'Ayuda',
                'export'           => 'Exportar Chat',
                'delete_data'      => 'Borrar Datos',
                'reactivate'       => 'Reactivar Plan',
                'back'             => 'Volver',
                'close'            => 'Cerrar',
                'quota_warning'    => '⚠️ Has usado {used}/{limit} mensajes hoy. Actualiza tu plan para más.',
                'quota_exceeded'   => '🚫 Has alcanzado tu límite diario. Vuelve mañana o actualiza tu plan con /subscribe.',
                'blacklisted'      => '🚫 Tu cuenta está suspendida. Contacta al soporte.',
                'persona_updated'  => '✅ Personalidad actualizada: <b>{name}</b>',
                'language_updated' => '✅ Idioma actualizado a Español.',
                'about_me'         => 'Acerca de Mí',
                'about_me_desc'    => 'Cuéntale a la IA quién eres para que te responda mejor.',
                'edit_info'        => '📝 Editar Información',
                'personal_bot_exclusive' => '🔒 <b>Acceso Restringido</b>\n\nEste es un bot personal privado. No tienes permiso para interactuar con él.',
            ],
            'en' => [
                'welcome_active'   => "👋 <b>Welcome{name}!</b>\n\nI'm your AI assistant with long-term memory. How can I help you today?\n\nType any question or use the buttons below.",
                'welcome_inactive' => "🔒 <b>Account Inactive</b>\n\nYour history is preserved for 6 months. Renew your plan to continue.",
                'plans_title'      => 'Choose your Plan',
                'pay_card'         => 'Card/Web',
                'settings_title'   => 'Settings',
                'settings_persona' => 'Personality',
                'settings_lang'    => 'Language',
                'personality'      => 'Personality',
                'language'         => 'Language',
                'choose_language'  => 'Choose your language',
                'choose_persona'   => 'Choose a Personality',
                'persona_desc'     => 'Define how you want the AI to respond to you.',
                'custom_persona'   => 'Custom Personality',
                'unlock_custom'    => 'Unlock Custom (Premium)',
                'plans'            => 'View Plans',
                'settings'         => 'Settings',
                'help'             => 'Help',
                'export'           => 'Export Chat',
                'delete_data'      => 'Delete Data',
                'reactivate'       => 'Reactivate Plan',
                'back'             => 'Back',
                'close'            => 'Close',
                'quota_warning'    => '⚠️ You have used {used}/{limit} messages today. Upgrade for more.',
                'quota_exceeded'   => '🚫 Daily limit reached. Come back tomorrow or upgrade with /subscribe.',
                'blacklisted'      => '🚫 Your account is suspended. Contact support.',
                'persona_updated'  => '✅ Personality updated: <b>{name}</b>',
                'language_updated' => '✅ Language updated to English.',
                'about_me'         => 'About Me',
                'about_me_desc'    => 'Tell the AI who you are for better responses.',
                'edit_info'        => '📝 Edit Info',
                'personal_bot_exclusive' => '🔒 <b>Restricted Access</b>\n\nThis is a private personal bot. You do not have permission to interact with it.',
            ],
            'pt' => [
                'welcome_active'   => "👋 <b>Bem-vindo{name}!</b>\n\nSou seu assistente de IA com memória de longo prazo. Como posso ajudar?\n\nEscreva qualquer pergunta ou use os botões abaixo.",
                'welcome_inactive' => "🔒 <b>Conta Inativa</b>\n\nSeu histórico é preservado por 6 meses. Renove seu plano para continuar.",
                'plans_title'      => 'Escolha seu Plano',
                'pay_card'         => 'Cartão/Web',
                'settings_title'   => 'Configurações',
                'settings_persona' => 'Personalidade',
                'settings_lang'    => 'Idioma',
                'personality'      => 'Personalidade',
                'language'         => 'Idioma',
                'choose_language'  => 'Escolha seu idioma',
                'choose_persona'   => 'Escolha uma Personalidade',
                'persona_desc'     => 'Defina como você quer que a IA responda.',
                'custom_persona'   => 'Personalidade Personalizada',
                'unlock_custom'    => 'Desbloquear Custom (Premium)',
                'plans'            => 'Ver Planos',
                'settings'         => 'Configurações',
                'help'             => 'Ajuda',
                'export'           => 'Exportar Chat',
                'delete_data'      => 'Excluir Dados',
                'reactivate'       => 'Reativar Plano',
                'back'             => 'Voltar',
                'close'            => 'Fechar',
                'quota_warning'    => '⚠️ Você usou {used}/{limit} mensagens hoje. Atualize para mais.',
                'quota_exceeded'   => '🚫 Limite diário atingido. Volte amanhã ou atualize com /subscribe.',
                'blacklisted'      => '🚫 Sua conta está suspensa. Contate o suporte.',
                'persona_updated'  => '✅ Personalidade atualizada: <b>{name}</b>',
                'language_updated' => '✅ Idioma atualizado para Português.',
                'about_me'         => 'Sobre Mim',
                'about_me_desc'    => 'Diga à IA quem você é para obter melhores respostas.',
                'edit_info'        => '📝 Editar Informações',
                'personal_bot_exclusive' => '🔒 <b>Acesso Restrito</b>\n\nEste é um bot pessoal privado. Você não tem permissão para interagir com ele.',
            ],
        ];
    }
}
