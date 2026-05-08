<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Bots — Gestión de bots personales de usuarios Premium.
 */
class Bots {

    public function __construct() {
        add_action('admin_post_tbot_toggle_bot',  [$this, 'handle_toggle_bot']);
        add_action('admin_post_tbot_delete_bot',  [$this, 'handle_delete_bot']);
    }

    public function render() {
        global $wpdb;

        $bots = $wpdb->get_results("
            SELECT b.*, u.display_name, u.user_email,
                   um.meta_value as tg_id
            FROM {$wpdb->prefix}tbot_user_bots b
            LEFT JOIN {$wpdb->users} u ON b.user_id = u.ID
            LEFT JOIN {$wpdb->usermeta} um ON b.user_id = um.user_id AND um.meta_key = 'tbot_telegram_id'
            ORDER BY b.created_at DESC
        ");

        if (isset($_GET['msg'])) {
            $msg = sanitize_text_field($_GET['msg']);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Bots Personales', 'Bots conectados por usuarios Premium'); ?>

            <div class="tbot-card">
                <p class="tbot-muted" style="margin-bottom:16px;">Los usuarios Premium pueden conectar su propio bot de Telegram usando el comando <code>/addbot TOKEN</code>. Aquí puedes gestionarlos.</p>
                
                <table class="tbot-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Propietario</th>
                            <th>Telegram ID</th>
                            <th>Bot ID / Username</th>
                            <th>Token (oculto)</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($bots): foreach ($bots as $bot): ?>
                        <tr>
                            <td><code>#<?php echo (int)$bot->id; ?></code></td>
                            <td>
                                <strong><?php echo esc_html($bot->display_name ?: $bot->user_email); ?></strong>
                            </td>
                            <td><code><?php echo esc_html($bot->tg_id ?: '—'); ?></code></td>
                            <td><code><?php echo esc_html($bot->bot_id ?: '—'); ?></code></td>
                            <td class="tbot-muted">
                                <code>...<?php echo esc_html(substr($bot->bot_token, -8)); ?></code>
                            </td>
                            <td>
                                <span class="tbot-badge tbot-badge-<?php echo $bot->is_active ? 'success' : 'error'; ?>">
                                    <?php echo $bot->is_active ? '✅ Activo' : '❌ Inactivo'; ?>
                                </span>
                            </td>
                            <td class="tbot-muted"><?php echo date('d/m/Y', strtotime($bot->created_at)); ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="tbot_toggle_bot">
                                    <input type="hidden" name="bot_id" value="<?php echo (int)$bot->id; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo (int)$bot->is_active; ?>">
                                    <?php wp_nonce_field('tbot_toggle_bot_' . $bot->id); ?>
                                    <button type="submit" class="tbot-btn tbot-btn-sm tbot-btn-<?php echo $bot->is_active ? 'warning' : 'primary'; ?>">
                                        <?php echo $bot->is_active ? 'Desactivar' : 'Activar'; ?>
                                    </button>
                                </form>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;" onsubmit="return confirm('¿Eliminar este bot? Esta acción no se puede deshacer.');">
                                    <input type="hidden" name="action" value="tbot_delete_bot">
                                    <input type="hidden" name="bot_id" value="<?php echo (int)$bot->id; ?>">
                                    <?php wp_nonce_field('tbot_delete_bot_' . $bot->id); ?>
                                    <button type="submit" class="tbot-btn tbot-btn-sm tbot-btn-danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="tbot-center tbot-muted">Ningún bot personal registrado aún</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function handle_toggle_bot() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $bot_id = (int) $_POST['bot_id'];
        check_admin_referer('tbot_toggle_bot_' . $bot_id);

        global $wpdb;
        $current = (int) $_POST['current_status'];
        $new_status = $current ? 0 : 1;
        $wpdb->update("{$wpdb->prefix}tbot_user_bots", ['is_active' => $new_status], ['id' => $bot_id]);

        $msg = $new_status ? 'Bot activado correctamente.' : 'Bot desactivado.';
        wp_redirect(admin_url('admin.php?page=tbot-bots&msg=' . urlencode($msg)));
        exit;
    }

    public function handle_delete_bot() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $bot_id = (int) $_POST['bot_id'];
        check_admin_referer('tbot_delete_bot_' . $bot_id);

        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}tbot_user_bots", ['id' => $bot_id]);

        \TBot\Services\Logger::log('admin', "Bot #{$bot_id} eliminado por admin.");
        wp_redirect(admin_url('admin.php?page=tbot-bots&msg=' . urlencode('Bot eliminado.')));
        exit;
    }
}
