<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * BotMessages — Editor visual de los textos del bot por idioma.
 *
 * Permite configurar desde el admin:
 * - Mensaje de bienvenida (activo / inactivo)
 * - Título del menú de planes
 * - Textos de ajustes y personalidades
 * - Mensajes de cuota y sistema
 */
class BotMessages {

    private array $langs = [
        'es' => '🇪🇸 Español',
        'en' => '🇺🇸 English',
        'pt' => '🇧🇷 Português',
    ];

    private array $fields = [
        'welcome_active'   => ['label' => 'Bienvenida (cuenta activa)',   'type' => 'textarea', 'hint' => 'Usa {name} para insertar el nombre del usuario.'],
        'welcome_inactive' => ['label' => 'Bienvenida (cuenta inactiva)', 'type' => 'textarea', 'hint' => 'Se muestra cuando la suscripción está vencida.'],
        'plans_title'      => ['label' => 'Título del menú de planes',    'type' => 'text',     'hint' => ''],
        'pay_card'         => ['label' => 'Texto botón de pago (web)',    'type' => 'text',     'hint' => ''],
        'settings_title'   => ['label' => 'Título de Ajustes',            'type' => 'text',     'hint' => ''],
        'choose_persona'   => ['label' => 'Título de Personalidades',     'type' => 'text',     'hint' => ''],
        'persona_desc'     => ['label' => 'Descripción de personalidades','type' => 'text',     'hint' => ''],
        'custom_persona'   => ['label' => 'Texto: Personalidad Custom',   'type' => 'text',     'hint' => ''],
        'unlock_custom'    => ['label' => 'Texto: Desbloquear Custom',    'type' => 'text',     'hint' => ''],
        'quota_warning'    => ['label' => 'Aviso de cuota',              'type' => 'text',     'hint' => 'Usa {used} y {limit}.'],
        'quota_exceeded'   => ['label' => 'Límite alcanzado',            'type' => 'text',     'hint' => ''],
        'blacklisted'      => ['label' => 'Mensaje: cuenta suspendida',  'type' => 'text',     'hint' => ''],
        'persona_updated'  => ['label' => 'Confirmación: personalidad',  'type' => 'text',     'hint' => 'Usa {name}.'],
        'language_updated' => ['label' => 'Confirmación: idioma',        'type' => 'text',     'hint' => ''],
    ];

    public function __construct() {
        add_action('admin_post_tbot_save_messages', [$this, 'handle_save']);
        add_action('wp_ajax_tbot_preview_message',  [$this, 'handle_preview_ajax']);
    }

    public function render() {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Mensajes del bot guardados.</p></div>';
        }

        $current_lang = sanitize_key($_GET['lang'] ?? 'es');
        $texts = get_option('tbot_bot_messages', []);
        if (!is_array($texts) || empty($texts)) {
            $builder = new \TBot\Services\MenuBuilder();
            // Los textos por defecto los provee MenuBuilder internamente, los usamos como base
            $texts = [];
        }

        // Obtener los textos por defecto del MenuBuilder para prellenar si están vacíos
        $defaults = $this->get_defaults();
        $current = array_merge($defaults[$current_lang] ?? [], $texts[$current_lang] ?? []);
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Mensajes del Bot', 'Textos que el bot envía a los usuarios en Telegram'); ?>

            <!-- Language Tabs -->
            <div class="tbot-lang-tabs">
                <?php foreach ($this->langs as $code => $label): ?>
                    <a href="?page=tbot-mensajes&lang=<?php echo esc_attr($code); ?>"
                       class="tbot-lang-tab <?php echo $current_lang === $code ? 'active' : ''; ?>">
                        <?php echo $label; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="tbot_save_messages">
                <input type="hidden" name="lang" value="<?php echo esc_attr($current_lang); ?>">
                <?php wp_nonce_field('tbot_save_messages_' . $current_lang); ?>

                <div class="tbot-messages-grid">
                    <?php foreach ($this->fields as $key => $field): ?>
                        <div class="tbot-card tbot-message-card">
                            <label class="tbot-message-label">
                                <?php echo esc_html($field['label']); ?>
                                <?php if ($field['hint']): ?>
                                    <span class="tbot-message-hint"><?php echo esc_html($field['hint']); ?></span>
                                <?php endif; ?>
                            </label>
                            <?php if ($field['type'] === 'textarea'): ?>
                                <textarea name="messages[<?php echo esc_attr($key); ?>]"
                                          class="tbot-input tbot-message-textarea"
                                          rows="4"><?php echo esc_textarea($current[$key] ?? ''); ?></textarea>
                            <?php else: ?>
                                <input type="text"
                                       name="messages[<?php echo esc_attr($key); ?>]"
                                       value="<?php echo esc_attr($current[$key] ?? ''); ?>"
                                       class="tbot-input">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:20px; display:flex; gap:12px; align-items:center;">
                    <button type="submit" class="tbot-btn tbot-btn-primary">💾 Guardar Mensajes (<?php echo $this->langs[$current_lang]; ?>)</button>
                    <span class="tbot-muted" style="font-size:12px;">Los cambios aplican en el siguiente mensaje que reciba el bot.</span>
                </div>
            </form>

            <!-- Panel de Vista Previa -->
            <div class="tbot-card" style="margin-top:28px; border:1px dashed var(--tbot-border-2);">
                <h3 class="tbot-card-title">📱 Vista Previa del Menú de Bienvenida</h3>
                <p class="tbot-muted" style="margin-bottom:16px;">Así verá el usuario el mensaje al escribir <code>/start</code>.</p>

                <div class="tbot-tg-preview-wrap">
                    <div class="tbot-tg-phone">
                        <div class="tbot-tg-header">
                            <div class="tbot-tg-avatar">🤖</div>
                            <div class="tbot-tg-bot-name">Tu Bot</div>
                        </div>
                        <div class="tbot-tg-body">
                            <div class="tbot-tg-bubble">
                                <?php
                                $welcome = $current['welcome_active'] ?? '';
                                $welcome_html = nl2br(esc_html(str_replace('{name}', ', Alex', $welcome)));
                                echo $welcome_html;
                                ?>
                            </div>
                            <div class="tbot-tg-buttons">
                                <div class="tbot-tg-btn-row">
                                    <span class="tbot-tg-btn">💎 <?php echo esc_html($current['plans'] ?? 'Ver Planes'); ?></span>
                                    <span class="tbot-tg-btn">⚙️ <?php echo esc_html($current['settings'] ?? 'Ajustes'); ?></span>
                                </div>
                                <div class="tbot-tg-btn-row">
                                    <span class="tbot-tg-btn">📥 <?php echo esc_html($current['export'] ?? 'Exportar Chat'); ?></span>
                                    <span class="tbot-tg-btn">❓ <?php echo esc_html($current['help'] ?? 'Ayuda'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        /* ── Language Tabs ────────────────────────────────────────────────── */
        .tbot-lang-tabs { display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid var(--tbot-border); }
        .tbot-lang-tab {
            padding:8px 20px; font-size:13px; font-weight:600;
            text-decoration:none; color:var(--tbot-muted);
            border-bottom:2px solid transparent; margin-bottom:-2px;
            transition:var(--tbot-transition);
        }
        .tbot-lang-tab:hover { color:var(--tbot-accent); }
        .tbot-lang-tab.active { color:var(--tbot-accent); border-bottom-color:var(--tbot-accent); }

        /* ── Messages Grid ────────────────────────────────────────────────── */
        .tbot-messages-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:16px; }
        .tbot-message-card { padding:16px 20px; }
        .tbot-message-label { display:block; font-size:12px; font-weight:700; color:var(--tbot-text); margin-bottom:6px; }
        .tbot-message-hint { display:block; font-size:11px; font-weight:400; color:var(--tbot-muted); margin-top:2px; }
        .tbot-message-textarea { font-family:'Inter', monospace; line-height:1.6; }

        /* ── Telegram Phone Preview ───────────────────────────────────────── */
        .tbot-tg-preview-wrap { display:flex; justify-content:center; }
        .tbot-tg-phone {
            width: 320px;
            background: #efeae2;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }
        .tbot-tg-header {
            background: #075e54;
            padding: 12px 16px;
            display: flex; align-items: center; gap: 10px;
        }
        .tbot-tg-avatar {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .tbot-tg-bot-name { color: #fff; font-weight: 600; font-size: 14px; }
        .tbot-tg-body { padding: 12px; min-height: 200px; }
        .tbot-tg-bubble {
            background: #fff;
            border-radius: 12px 12px 12px 2px;
            padding: 10px 14px;
            font-size: 13px;
            line-height: 1.5;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-bottom: 8px;
            max-width: 90%;
            white-space: pre-wrap;
        }
        .tbot-tg-buttons { display: flex; flex-direction: column; gap: 6px; }
        .tbot-tg-btn-row { display: flex; gap: 6px; }
        .tbot-tg-btn {
            flex: 1;
            background: #fff;
            border-radius: 8px;
            padding: 8px 6px;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #128c7e;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
            cursor: pointer;
        }
        </style>
        <?php
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $lang = sanitize_key($_POST['lang'] ?? 'es');
        check_admin_referer('tbot_save_messages_' . $lang);

        $texts = get_option('tbot_bot_messages', []);
        if (!is_array($texts)) $texts = [];

        $incoming = $_POST['messages'] ?? [];
        $sanitized = [];
        foreach ($incoming as $key => $val) {
            $sanitized[sanitize_key($key)] = sanitize_textarea_field($val);
        }
        $texts[$lang] = $sanitized;

        update_option('tbot_bot_messages', $texts);
        Logger::log('admin', "Mensajes del bot actualizados para idioma: {$lang}");

        wp_redirect(admin_url("admin.php?page=tbot-mensajes&lang={$lang}&saved=1"));
        exit;
    }

    private function get_defaults(): array {
        // Construir un MenuBuilder temporal para obtener sus defaults
        $reflection = new \ReflectionClass(\TBot\Services\MenuBuilder::class);
        $method     = $reflection->getMethod('default_texts');
        $method->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        return $method->invoke($instance);
    }
}
