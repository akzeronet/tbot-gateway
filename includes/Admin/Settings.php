<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

class Settings {
    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_tbot_test_ai',       [$this, 'handle_test_ai']);
        add_action('admin_post_tbot_check_telegram', [$this, 'handle_check_telegram']);
        add_action('admin_post_tbot_set_webhook', [$this, 'handle_set_webhook']);
    }

    public function add_menu() {
        add_menu_page(
            'TBot Gateway',
            'TBot Gateway',
            'manage_options',
            'tbot-gateway',
            [$this, 'render_page'],
            'dashicons-rest-api'
        );
    }

    public function register_settings() {
        $options = [
            'tbot_master_token',
            'tbot_webhook_secret',
            'tbot_bot_username',
            'tbot_bot_persona',
            'tbot_free_credits_signup',
            'tbot_free_allow_photo',
            'tbot_free_allow_voice',
            'tbot_free_allow_image',
            'tbot_openai_api_key',
            'tbot_anthropic_api_key',
            'tbot_google_api_key',
            'tbot_groq_api_key',
            'tbot_stripe_secret',
            'tbot_stripe_webhook_secret',
            'tbot_stripe_link_std',
            'tbot_stripe_link_pre',
            'tbot_stripe_link_topup',
            'tbot_ollama_url',
            'tbot_qdrant_url',
            'tbot_vllm_url',
            'tbot_use_self_hosted',
            'tbot_deepinfra_api_key',
            'tbot_together_api_key',
            'tbot_fireworks_api_key',
            'tbot_omnirouter_url',
            'tbot_omnirouter_api_key',
            'tbot_clickhouse_url',
            'tbot_clickhouse_user',
            'tbot_clickhouse_password',
            'tbot_redis_host',
            'tbot_redis_port',
            'tbot_redis_password',
        ];

        foreach ($options as $option) {
            register_setting('tbot_settings_group', $option);
        }
    }

    public function render_page() {
        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Configuración guardada correctamente.</p></div>';
        }
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Ajustes', 'Tokens, secretos y conexiones externas'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('tbot_settings_group'); ?>
                <?php do_settings_sections('tbot_settings_group'); ?>
            <div class="tbot-card" style="margin-top:20px;">
                <h3 class="tbot-card-title">⚙️ Tokens y Claves API</h3>
                <table class="form-table" style="margin-top:0;">
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Master Bot Token</th>
                        <td><input type="text" name="tbot_master_token" value="<?php echo esc_attr(get_option('tbot_master_token')); ?>" class="tbot-input" style="max-width:440px;" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Telegram Webhook Secret</th>
                        <td><input type="text" name="tbot_webhook_secret" value="<?php echo esc_attr(get_option('tbot_webhook_secret')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Bot Username <small style="font-weight:400;text-transform:none">(sin @)</small></th>
                        <td><input type="text" name="tbot_bot_username" value="<?php echo esc_attr(get_option('tbot_bot_username')); ?>" class="tbot-input" style="max-width:440px;" placeholder="mi_bot" /></td>
                    </tr>
                </table>
            </div>

            <div class="tbot-card" style="margin-top:20px;">
                <h3 class="tbot-card-title">🎁 Nivel Gratuito (Onboarding)</h3>
                <p class="tbot-muted" style="margin:-8px 0 16px;font-size:12px">Configura la experiencia de los usuarios sin suscripción (Plan Free).</p>
                <table class="form-table" style="margin-top:0;">
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Créditos Iniciales</th>
                        <td><input type="number" name="tbot_free_credits_signup" value="<?php echo esc_attr(get_option('tbot_free_credits_signup', 20)); ?>" class="tbot-input" style="max-width:100px;" min="0" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Permitir enviar Fotos</th>
                        <td><input type="checkbox" name="tbot_free_allow_photo" value="1" <?php checked(get_option('tbot_free_allow_photo', '1'), '1'); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Permitir Notas de Voz</th>
                        <td><input type="checkbox" name="tbot_free_allow_voice" value="1" <?php checked(get_option('tbot_free_allow_voice', '1'), '1'); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Permitir Generar Imágenes</th>
                        <td><input type="checkbox" name="tbot_free_allow_image" value="1" <?php checked(get_option('tbot_free_allow_image', '0'), '1'); ?> /></td>
                    </tr>
                </table>
            </div>

            <div class="tbot-card" style="margin-top:20px;">
                <h3 class="tbot-card-title">🤖 API Keys de Proveedores IA</h3>
                <p class="tbot-muted" style="margin:-8px 0 16px;font-size:12px">Configura al menos un proveedor. El modelo activo por plan se gestiona en <strong>Modelos IA</strong>.</p>
                <table class="form-table" style="margin-top:0;">
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">🟢 OpenAI API Key</th>
                        <td><input type="password" name="tbot_openai_api_key" value="<?php echo esc_attr(get_option('tbot_openai_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" placeholder="sk-..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">🟠 Anthropic API Key</th>
                        <td><input type="password" name="tbot_anthropic_api_key" value="<?php echo esc_attr(get_option('tbot_anthropic_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" placeholder="sk-ant-..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">🔵 Google AI API Key</th>
                        <td><input type="password" name="tbot_google_api_key" value="<?php echo esc_attr(get_option('tbot_google_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" placeholder="AIza..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">🟣 Groq API Key</th>
                        <td><input type="password" name="tbot_groq_api_key" value="<?php echo esc_attr(get_option('tbot_groq_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" placeholder="gsk_..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:#06b6d4;font-size:12px;font-weight:700;text-transform:uppercase;">🔵 DeepInfra API Key</th>
                        <td><input type="password" name="tbot_deepinfra_api_key" value="<?php echo esc_attr(get_option('tbot_deepinfra_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" placeholder="di_..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:#f97316;font-size:12px;font-weight:700;text-transform:uppercase;">🟠 Together AI API Key</th>
                        <td><input type="password" name="tbot_together_api_key" value="<?php echo esc_attr(get_option('tbot_together_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:#ef4444;font-size:12px;font-weight:700;text-transform:uppercase;">🔴 Fireworks AI API Key</th>
                        <td><input type="password" name="tbot_fireworks_api_key" value="<?php echo esc_attr(get_option('tbot_fireworks_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" /></td>
                    </tr>
                    <tr><td colspan="2"><hr style="border-color:rgba(255,255,255,0.1);"></td></tr>
                    <tr valign="top">
                        <th scope="row" style="color:#a855f7;font-size:12px;font-weight:700;text-transform:uppercase;">🌐 OmniRouter URL<br><small style="font-weight:400;color:var(--tbot-muted)">(OpenRouter, LiteLLM, etc)</small></th>
                        <td><input type="url" name="tbot_omnirouter_url" value="<?php echo esc_attr(get_option('tbot_omnirouter_url', 'https://openrouter.ai/api')); ?>" class="tbot-input" style="max-width:440px;" placeholder="https://openrouter.ai/api" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:#a855f7;font-size:12px;font-weight:700;text-transform:uppercase;">🌐 OmniRouter API Key</th>
                        <td><input type="password" name="tbot_omnirouter_api_key" value="<?php echo esc_attr(get_option('tbot_omnirouter_api_key')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" placeholder="sk-or-v1-..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Stripe Secret Key</th>
                        <td><input type="password" name="tbot_stripe_secret" value="<?php echo esc_attr(get_option('tbot_stripe_secret')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Stripe Webhook Secret</th>
                        <td><input type="password" name="tbot_stripe_webhook_secret" value="<?php echo esc_attr(get_option('tbot_stripe_webhook_secret')); ?>" class="tbot-input" style="max-width:440px;" autocomplete="new-password" /></td>
                    </tr>
                </table>
                <?php submit_button('💾 Guardar Configuración', 'primary', 'submit', false, ['class' => 'tbot-btn tbot-btn-primary', 'style' => 'margin-top:8px;']); ?>
            </div>

            <div class="tbot-card" style="margin-top:20px; border-left:4px solid #10b981;">
                <h3 class="tbot-card-title">💳 Enlaces de Pago (Stripe Payment Links)</h3>
                <p class="tbot-muted">Pega aquí los enlaces directos de pago generados en tu panel de Stripe. El bot les agregará automáticamente <code>?client_reference_id=123</code> para identificar al usuario en el webhook.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Stripe Link: Standard</th>
                        <td><input type="url" name="tbot_stripe_link_std" value="<?php echo esc_attr(get_option('tbot_stripe_link_std')); ?>" class="tbot-input" style="max-width:440px;" placeholder="https://buy.stripe.com/..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Stripe Link: Premium</th>
                        <td><input type="url" name="tbot_stripe_link_pre" value="<?php echo esc_attr(get_option('tbot_stripe_link_pre')); ?>" class="tbot-input" style="max-width:440px;" placeholder="https://buy.stripe.com/..." /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Stripe Link: Recarga (Topup)</th>
                        <td><input type="url" name="tbot_stripe_link_topup" value="<?php echo esc_attr(get_option('tbot_stripe_link_topup')); ?>" class="tbot-input" style="max-width:440px;" placeholder="https://buy.stripe.com/..." /></td>
                    </tr>
                </table>
            </div>

            <div class="tbot-card" style="margin-top:20px; border-left:4px solid #f43f5e;">
                <h3 class="tbot-card-title">🤖 Bot Persona (Identidad Central)</h3>
                <p class="tbot-muted">Define la personalidad, reglas y comportamiento base del bot. Este prompt se inyectará en todas las conversaciones.</p>
                <div class="tbot-form-row">
                    <textarea name="tbot_bot_persona" rows="4" class="tbot-input" style="width:100%; max-width:800px;"><?php echo esc_textarea(get_option('tbot_bot_persona', 'Eres un asistente de IA experto, útil y conciso.')); ?></textarea>
                </div>
            </div>

            <div class="tbot-card" style="margin-top:20px; border-left:4px solid var(--tbot-accent);">
                <h3 class="tbot-card-title">🔗 URLs de Endpoints</h3>
                <div class="tbot-form-row">
                    <label>Master Webhook URL</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" value="<?php echo esc_url(get_rest_url(null, 'tbot/v1/webhook')); ?>" class="tbot-input" readonly style="max-width:440px;background:var(--tbot-surface-2) !important;color:var(--tbot-accent) !important;">
                    </div>
                </div>
                <div class="tbot-form-row">
                    <label>Personal Bots Base URL</label>
                    <input type="text" value="<?php echo esc_url(get_rest_url(null, 'tbot/v1/webhook/')); ?>{bot_id}" class="tbot-input" readonly style="max-width:440px;background:var(--tbot-surface-2) !important;">
                </div>
                <div class="tbot-form-row">
                    <label>Stripe Webhook URL</label>
                    <input type="text" value="<?php echo esc_url(get_rest_url(null, 'tbot/v1/payment/webhook')); ?>" class="tbot-input" readonly style="max-width:440px;background:var(--tbot-surface-2) !important;">
                </div>
            </div>

            <div class="tbot-card" style="margin-top:20px; border-left:4px solid #10b981;">
                <h3 class="tbot-card-title">🖥️ Infraestructura Self-Hosted (Ollama + Qdrant)</h3>
                <p class="tbot-muted">Configuración del stack de IA local. Requiere Docker + GPU NVIDIA. Si no está disponible, el sistema usa las APIs externas automáticamente.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Activar Self-Hosted</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="tbot_use_self_hosted" value="1" <?php checked(get_option('tbot_use_self_hosted'), '1'); ?> />
                                <span>Usar modelos locales como primarios (fallback automático a APIs)</span>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:#10b981;font-size:12px;font-weight:700;text-transform:uppercase;">🟢 Ollama URL</th>
                        <td><input type="url" name="tbot_ollama_url" value="<?php echo esc_attr(get_option('tbot_ollama_url', 'http://localhost:11434')); ?>" class="tbot-input" style="max-width:440px;" placeholder="http://localhost:11434" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:#8b5cf6;font-size:12px;font-weight:700;text-transform:uppercase;">🟣 Qdrant URL</th>
                        <td><input type="url" name="tbot_qdrant_url" value="<?php echo esc_attr(get_option('tbot_qdrant_url', 'http://localhost:6333')); ?>" class="tbot-input" style="max-width:440px;" placeholder="http://localhost:6333" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:#f59e0b;font-size:12px;font-weight:700;text-transform:uppercase;">🟡 vLLM URL (opcional)</th>
                        <td><input type="url" name="tbot_vllm_url" value="<?php echo esc_attr(get_option('tbot_vllm_url', '')); ?>" class="tbot-input" style="max-width:440px;" placeholder="http://localhost:8000" /></td>
                    </tr>
                </table>
            </div>

            <div class="tbot-card" style="margin-top:20px; border-left:4px solid #ef4444;">
                <h3 class="tbot-card-title">🚀 Almacenamiento L0 (Redis Ultra-Hot Cache)</h3>
                <p class="tbot-muted">L0 mantiene los mensajes activos en RAM (Write-Through) para máximo rendimiento de lectura.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" style="color:#ef4444;font-size:12px;font-weight:700;text-transform:uppercase;">Redis Host</th>
                        <td><input type="text" name="tbot_redis_host" value="<?php echo esc_attr(get_option('tbot_redis_host', '127.0.0.1')); ?>" class="tbot-input" style="max-width:200px;" placeholder="127.0.0.1" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Redis Port</th>
                        <td><input type="number" name="tbot_redis_port" value="<?php echo esc_attr(get_option('tbot_redis_port', 6379)); ?>" class="tbot-input" style="max-width:100px;" placeholder="6379" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">Redis Password</th>
                        <td><input type="password" name="tbot_redis_password" value="<?php echo esc_attr(get_option('tbot_redis_password', '')); ?>" class="tbot-input" style="max-width:200px;" autocomplete="new-password" /></td>
                    </tr>
                </table>
            </div>

            <div class="tbot-card" style="margin-top:20px; border-left:4px solid #8b5cf6;">
                <h3 class="tbot-card-title">💾 Almacenamiento L3 (ClickHouse)</h3>
                <p class="tbot-muted">L3 actúa como memoria analítica y archivo definitivo infinito (>30 días).</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" style="color:#f59e0b;font-size:12px;font-weight:700;text-transform:uppercase;">ClickHouse URL</th>
                        <td><input type="url" name="tbot_clickhouse_url" value="<?php echo esc_attr(get_option('tbot_clickhouse_url', 'http://localhost:8123')); ?>" class="tbot-input" style="max-width:440px;" placeholder="http://localhost:8123" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">ClickHouse User</th>
                        <td><input type="text" name="tbot_clickhouse_user" value="<?php echo esc_attr(get_option('tbot_clickhouse_user', 'default')); ?>" class="tbot-input" style="max-width:200px;" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row" style="color:var(--tbot-muted);font-size:12px;font-weight:700;text-transform:uppercase;">ClickHouse Password</th>
                        <td><input type="password" name="tbot_clickhouse_password" value="<?php echo esc_attr(get_option('tbot_clickhouse_password', '')); ?>" class="tbot-input" style="max-width:200px;" autocomplete="new-password" /></td>
                    </tr>
                </table>
            </div>

            <div class="tbot-card" style="margin-top:20px; background:var(--tbot-accent-light); border:1px solid rgba(59,130,246,0.25);">
                <h3 class="tbot-card-title" style="color:var(--tbot-accent) !important;">🔍 Tests de Conexión y Logs</h3>
                <p class="tbot-muted">Usa <strong>Diagnóstico</strong> para verificar todos los providers, storage tiers y servicios self-hosted.</p>
                <a href="<?php echo admin_url('admin.php?page=tbot-diagnostico'); ?>" class="tbot-btn tbot-btn-primary">Ir a Diagnóstico →</a>
            </div>
        </div>
        <?php
    }

    public function handle_test_ai() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_test_ai');

        $result = \TBot\Services\AIService::chat([
            'model'         => 'gpt-4o-mini',
            'message'       => 'Di "OK" y nada más.',
            'system_prompt' => 'Responde con una sola palabra.',
            'max_tokens'    => 10,
        ]);

        if ($result['success']) {
            \TBot\Services\Logger::log('test', 'AI test OK: ' . ($result['text'] ?? ''));
            wp_redirect(admin_url('admin.php?page=tbot-gateway&test=success'));
        } else {
            \TBot\Services\Logger::log('error', 'AI test FAIL: ' . ($result['error'] ?? ''));
            wp_redirect(admin_url('admin.php?page=tbot-gateway&test=error'));
        }
        exit;
    }

    public function handle_check_telegram() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_check_telegram');

        $token = get_option('tbot_master_token');
        $url = "https://api.telegram.org/bot{$token}/getWebhookInfo";
        
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            $data = ['last_error_message' => 'Fallo al contactar API de Telegram: ' . $response->get_error_message()];
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $data = $body['result'] ?? ['last_error_message' => 'Error en respuesta de Telegram'];
        }

        wp_redirect(admin_url('admin.php?page=tbot-gateway&tg_status=' . urlencode(json_encode($data))));
        exit;
    }

    public function handle_set_webhook() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_set_webhook');

        $token = get_option('tbot_master_token');
        $secret = get_option('tbot_webhook_secret');
        $webhook_url = get_rest_url(null, 'tbot/v1/webhook');

        $url = "https://api.telegram.org/bot{$token}/setWebhook";
        
        $response = wp_remote_post($url, [
            'body' => [
                'url' => $webhook_url,
                'secret_token' => $secret,
                'allowed_updates' => json_encode(['message', 'callback_query', 'pre_checkout_query', 'successful_payment'])
            ]
        ]);

        if (is_wp_error($response)) {
            \TBot\Services\Logger::log('error', "Fallo al configurar webhook: " . $response->get_error_message());
            wp_redirect(admin_url('admin.php?page=tbot-gateway&set_webhook=error'));
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if ($body['ok']) {
                \TBot\Services\Logger::log('test', "Webhook configurado con éxito");
                wp_redirect(admin_url('admin.php?page=tbot-gateway&set_webhook=success'));
            } else {
                \TBot\Services\Logger::log('error', "Telegram rechazó el webhook", $body);
                wp_redirect(admin_url('admin.php?page=tbot-gateway&set_webhook=error'));
            }
        }
        exit;
    }
}
