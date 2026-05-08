<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Plans — Configuración visual de planes, precios y métodos de pago.
 */
class Plans {

    public function __construct() {
        add_action('admin_post_tbot_save_plans', [$this, 'handle_save_plans']);
        add_action('admin_init', function() {
            register_setting('tbot_plans_group', 'tbot_plan_standard_stars');
            register_setting('tbot_plans_group', 'tbot_plan_premium_stars');
            register_setting('tbot_plans_group', 'tbot_plan_standard_price');
            register_setting('tbot_plans_group', 'tbot_plan_premium_price');
            register_setting('tbot_plans_group', 'tbot_plan_standard_quota');
            register_setting('tbot_plans_group', 'tbot_plan_premium_quota');
            register_setting('tbot_plans_group', 'tbot_payment_methods');
            register_setting('tbot_plans_group', 'tbot_plan_standard_desc');
            register_setting('tbot_plans_group', 'tbot_plan_premium_desc');
        });
    }

    public function render() {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Planes guardados correctamente.</p></div>';
        }

        $methods_enabled = get_option('tbot_payment_methods', 'stripe,stars');
        $methods = is_array($methods_enabled) ? $methods_enabled : explode(',', $methods_enabled);
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Planes & Pagos', 'Configura tus precios y métodos de pago'); ?>

            <form method="post" action="options.php">
                <?php settings_fields('tbot_plans_group'); ?>

                <div class="tbot-grid-2" style="align-items:start;">
                    <!-- Plan Standard -->
                    <div class="tbot-card tbot-plan-card">
                        <div class="tbot-plan-header" style="--plan-color: #3b82f6;">
                            <span class="tbot-plan-icon">⭐</span>
                            <h3>Plan Standard</h3>
                        </div>
                        <div class="tbot-form-row">
                            <label>Precio en USD / mes</label>
                            <div class="tbot-input-prefix">
                                <span>$</span>
                                <input type="number" name="tbot_plan_standard_price" step="0.01" min="0"
                                    value="<?php echo esc_attr(get_option('tbot_plan_standard_price', '12.00')); ?>"
                                    class="tbot-input" placeholder="12.00">
                            </div>
                        </div>
                        <div class="tbot-form-row">
                            <label>Precio en Telegram Stars</label>
                            <div class="tbot-input-prefix">
                                <span>⭐</span>
                                <input type="number" name="tbot_plan_standard_stars" min="1"
                                    value="<?php echo esc_attr(get_option('tbot_plan_standard_stars', '925')); ?>"
                                    class="tbot-input" placeholder="925">
                            </div>
                        </div>
                        <div class="tbot-form-row">
                            <label>Límite diario de mensajes</label>
                            <input type="number" name="tbot_plan_standard_quota" min="1"
                                value="<?php echo esc_attr(get_option('tbot_plan_standard_quota', '300')); ?>"
                                class="tbot-input">
                        </div>
                        <div class="tbot-form-row">
                            <label>Descripción (para el bot)</label>
                            <textarea name="tbot_plan_standard_desc" class="tbot-input" rows="2"><?php echo esc_textarea(get_option('tbot_plan_standard_desc', '300 msgs/día. Acceso a IA estándar.')); ?></textarea>
                        </div>
                    </div>

                    <!-- Plan Premium -->
                    <div class="tbot-card tbot-plan-card">
                        <div class="tbot-plan-header" style="--plan-color: #8b5cf6;">
                            <span class="tbot-plan-icon">💎</span>
                            <h3>Plan Premium</h3>
                        </div>
                        <div class="tbot-form-row">
                            <label>Precio en USD / mes</label>
                            <div class="tbot-input-prefix">
                                <span>$</span>
                                <input type="number" name="tbot_plan_premium_price" step="0.01" min="0"
                                    value="<?php echo esc_attr(get_option('tbot_plan_premium_price', '20.00')); ?>"
                                    class="tbot-input" placeholder="20.00">
                            </div>
                        </div>
                        <div class="tbot-form-row">
                            <label>Precio en Telegram Stars</label>
                            <div class="tbot-input-prefix">
                                <span>⭐</span>
                                <input type="number" name="tbot_plan_premium_stars" min="1"
                                    value="<?php echo esc_attr(get_option('tbot_plan_premium_stars', '1540')); ?>"
                                    class="tbot-input" placeholder="1540">
                            </div>
                        </div>
                        <div class="tbot-form-row">
                            <label>Límite diario de mensajes</label>
                            <input type="number" name="tbot_plan_premium_quota" min="1"
                                value="<?php echo esc_attr(get_option('tbot_plan_premium_quota', '500')); ?>"
                                class="tbot-input">
                        </div>
                        <div class="tbot-form-row">
                            <label>Descripción (para el bot)</label>
                            <textarea name="tbot_plan_premium_desc" class="tbot-input" rows="2"><?php echo esc_textarea(get_option('tbot_plan_premium_desc', '500 msgs/día, Bot Personal, Memoria Larga, Personalidad Custom.')); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Métodos de Pago -->
                <div class="tbot-card" style="margin-top:20px;">
                    <h3 class="tbot-card-title">💳 Métodos de Pago Habilitados</h3>
                    <p class="tbot-muted">Activa los métodos que quieres ofrecer a tus usuarios en el menú /subscribe.</p>

                    <div class="tbot-methods-grid">
                        <?php
                        $available = [
                            'stars'       => ['label' => 'Telegram Stars ⭐',  'desc' => 'Pago nativo de Telegram, sin comisión externa.'],
                            'stripe'      => ['label' => 'Stripe 💳',           'desc' => 'Tarjeta de crédito/débito, Apple Pay, Google Pay.'],
                            'paypal'      => ['label' => 'PayPal 🅿️',           'desc' => 'Pagos con cuenta PayPal.'],
                            'mercadopago' => ['label' => 'Mercado Pago 🤝',     'desc' => 'Ideal para usuarios de LATAM.'],
                            'uepapay'     => ['label' => 'UepaPay 🇩🇴',          'desc' => 'Pagos locales República Dominicana.'],
                        ];
                        foreach ($available as $key => $method):
                            $checked = in_array($key, $methods);
                        ?>
                        <label class="tbot-method-toggle <?php echo $checked ? 'active' : ''; ?>">
                            <input type="checkbox" name="tbot_payment_methods[]" value="<?php echo esc_attr($key); ?>" <?php checked($checked); ?> onchange="this.closest('.tbot-method-toggle').classList.toggle('active', this.checked)">
                            <div class="tbot-method-info">
                                <strong><?php echo esc_html($method['label']); ?></strong>
                                <span><?php echo esc_html($method['desc']); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="tbot_payment_methods_raw" id="tbot_methods_raw">
                </div>

                <div style="margin-top:20px;">
                    <?php submit_button('Guardar Configuración de Planes', 'primary', 'submit', false, ['class' => 'tbot-btn tbot-btn-primary']); ?>
                </div>
            </form>
        </div>
        <?php
    }

    public function handle_save_plans() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_plans_group-options');

        $methods = array_map('sanitize_text_field', $_POST['tbot_payment_methods'] ?? []);
        update_option('tbot_payment_methods', implode(',', $methods));

        wp_redirect(admin_url('admin.php?page=tbot-planes&saved=1'));
        exit;
    }
}
