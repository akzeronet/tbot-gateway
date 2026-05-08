<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

class UserFields {
    public function __construct() {
        add_action('show_user_profile', [$this, 'render_fields']);
        add_action('edit_user_profile', [$this, 'render_fields']);
        add_action('personal_options_update', [$this, 'save_fields']);
        add_action('edit_user_profile_update', [$this, 'save_fields']);
    }

    public function render_fields($user) {
        $status = get_user_meta($user->ID, 'tbot_status', true);
        $tg_id = get_user_meta($user->ID, 'tbot_telegram_id', true);
        $sub = get_user_meta($user->ID, 'tbot_subscription', true) ?: 'free';
        ?>
        <h3>TBot Gateway - Configuración de Usuario</h3>
        <table class="form-table">
            <tr>
                <th><label>Telegram ID</label></th>
                <td><code><?php echo esc_html($tg_id ?: 'No vinculado'); ?></code></td>
            </tr>
            <tr>
                <th><label for="tbot_status">Estado del Usuario</label></th>
                <td>
                    <select name="tbot_status" id="tbot_status">
                        <option value="active" <?php selected($status, 'active'); ?>>Activo</option>
                        <option value="blacklisted" <?php selected($status, 'blacklisted'); ?>>Baneado (Blacklist)</option>
                    </select>
                    <p class="description">Si está baneado, el bot ignorará todos sus mensajes.</p>
                </td>
            </tr>
            <tr>
                <th><label for="tbot_subscription">Nivel de Suscripción</label></th>
                <td>
                    <select name="tbot_subscription" id="tbot_subscription">
                        <option value="free" <?php selected($sub, 'free'); ?>>Free</option>
                        <option value="premium" <?php selected($sub, 'premium'); ?>>Premium</option>
                        <option value="admin" <?php selected($sub, 'admin'); ?>>Admin</option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) return false;

        if (isset($_POST['tbot_status'])) {
            update_user_meta($user_id, 'tbot_status', sanitize_text_field($_POST['tbot_status']));
        }
        if (isset($_POST['tbot_subscription'])) {
            update_user_meta($user_id, 'tbot_subscription', sanitize_text_field($_POST['tbot_subscription']));
        }
    }
}
