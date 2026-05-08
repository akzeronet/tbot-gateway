<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Users — Gestión de usuarios del bot (suscripciones, blacklist, etc.)
 */
class Users {

    public function __construct() {
        add_action('admin_post_tbot_update_user', [$this, 'handle_update_user']);
    }

    public function render() {
        global $wpdb;

        $action  = $_GET['action'] ?? 'list';
        $user_id = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;

        if ($action === 'edit' && $user_id) {
            $this->render_edit($user_id);
            return;
        }

        $search = sanitize_text_field($_GET['s'] ?? '');
        $filter = sanitize_text_field($_GET['filter'] ?? '');

        // Obtener usuarios del bot
        $meta_join = $wpdb->prepare("INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = %s", 'tbot_telegram_id');
        $where = "WHERE 1=1";
        if ($search) {
            $where .= $wpdb->prepare(" AND (u.display_name LIKE %s OR u.user_email LIKE %s)", "%{$search}%", "%{$search}%");
        }
        if ($filter === 'premium') {
            $where .= " AND sub.meta_value = 'premium'";
            $sub_join = $wpdb->prepare("LEFT JOIN {$wpdb->usermeta} sub ON u.ID = sub.user_id AND sub.meta_key = %s", 'tbot_subscription');
        } elseif ($filter === 'blacklisted') {
            $where .= " AND st.meta_value = 'blacklisted'";
        }

        $users = $wpdb->get_results("
            SELECT u.ID, u.display_name, u.user_email, u.user_registered,
                um.meta_value as tg_id,
                (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'tbot_subscription' LIMIT 1) as subscription,
                (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'tbot_status' LIMIT 1) as status,
                (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'tbot_usage_global' LIMIT 1) as usage_today
            FROM {$wpdb->users} u
            {$meta_join}
            {$where}
            ORDER BY u.user_registered DESC
            LIMIT 100
        ");
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Usuarios', 'Gestión de usuarios del bot'); ?>

            <div class="tbot-card">
                <div class="tbot-card-toolbar">
                    <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="tbot-usuarios">
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar usuario..." class="tbot-input" style="width:220px;">
                        <select name="filter" class="tbot-input">
                            <option value="">Todos</option>
                            <option value="premium" <?php selected($filter, 'premium'); ?>>Solo Premium</option>
                            <option value="blacklisted" <?php selected($filter, 'blacklisted'); ?>>Bloqueados</option>
                        </select>
                        <button type="submit" class="tbot-btn tbot-btn-primary">Filtrar</button>
                    </form>
                    <span class="tbot-muted"><?php echo count($users); ?> usuarios encontrados</span>
                </div>

                <table class="tbot-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Telegram ID</th>
                            <th>Suscripción</th>
                            <th>Estado</th>
                            <th>Uso Hoy</th>
                            <th>Registro</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($users): foreach ($users as $u): 
                        $sub    = $u->subscription ?: 'free';
                        $status = $u->status ?: 'active';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($u->display_name ?: $u->user_email); ?></strong>
                                <br><span class="tbot-muted" style="font-size:11px;"><?php echo esc_html($u->user_email); ?></span>
                            </td>
                            <td><code><?php echo esc_html($u->tg_id ?: '—'); ?></code></td>
                            <td>
                                <span class="tbot-badge tbot-badge-<?php echo esc_attr($sub); ?>">
                                    <?php echo $sub === 'premium' ? '💎 Premium' : '🆓 Free'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="tbot-badge tbot-badge-<?php echo $status === 'blacklisted' ? 'error' : 'success'; ?>">
                                    <?php echo $status === 'blacklisted' ? '🚫 Bloqueado' : '✅ Activo'; ?>
                                </span>
                            </td>
                            <td><?php echo (int)$u->usage_today; ?> msgs</td>
                            <td class="tbot-muted"><?php echo date('d/m/Y', strtotime($u->user_registered)); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=tbot-usuarios&action=edit&uid=' . $u->ID); ?>" class="tbot-btn tbot-btn-sm">Editar</a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="tbot-center tbot-muted">No se encontraron usuarios</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_edit(int $user_id) {
        $user = get_userdata($user_id);
        if (!$user) { echo '<div class="tbot-wrap"><p>Usuario no encontrado.</p></div>'; return; }

        $sub    = get_user_meta($user_id, 'tbot_subscription', true) ?: 'free';
        $status = get_user_meta($user_id, 'tbot_status', true) ?: 'active';
        $tg_id  = get_user_meta($user_id, 'tbot_telegram_id', true);
        $persona= get_user_meta($user_id, 'tbot_persona_name', true) ?: 'Asistente';
        $lang   = get_user_meta($user_id, 'tbot_language', true) ?: 'es';

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Usuario actualizado correctamente.</p></div>';
        }
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Editar Usuario', esc_html($user->display_name)); ?>
            
            <a href="<?php echo admin_url('admin.php?page=tbot-usuarios'); ?>" class="tbot-btn tbot-btn-secondary" style="margin-bottom:20px; display:inline-block;">← Volver a Usuarios</a>

            <div class="tbot-grid-2">
                <div class="tbot-card">
                    <h3 class="tbot-card-title">Información del Usuario</h3>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="tbot_update_user">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        <?php wp_nonce_field('tbot_update_user_' . $user_id); ?>

                        <div class="tbot-form-row">
                            <label>Telegram ID</label>
                            <input type="text" value="<?php echo esc_attr($tg_id); ?>" class="tbot-input" disabled>
                        </div>
                        <div class="tbot-form-row">
                            <label>Nombre</label>
                            <input type="text" value="<?php echo esc_attr($user->display_name); ?>" class="tbot-input" disabled>
                        </div>
                        <div class="tbot-form-row">
                            <label>Suscripción</label>
                            <select name="subscription" class="tbot-input">
                                <option value="free"    <?php selected($sub, 'free'); ?>>🆓 Free</option>
                                <option value="standard" <?php selected($sub, 'standard'); ?>>⭐ Standard</option>
                                <option value="premium" <?php selected($sub, 'premium'); ?>>💎 Premium</option>
                                <option value="admin"   <?php selected($sub, 'admin'); ?>>🔑 Admin</option>
                            </select>
                        </div>
                        <div class="tbot-form-row">
                            <label>Estado</label>
                            <select name="status" class="tbot-input">
                                <option value="active"      <?php selected($status, 'active'); ?>>✅ Activo</option>
                                <option value="blacklisted" <?php selected($status, 'blacklisted'); ?>>🚫 Bloqueado (Blacklist)</option>
                            </select>
                        </div>
                        <div class="tbot-form-row">
                            <label>Idioma</label>
                            <select name="language" class="tbot-input">
                                <option value="es" <?php selected($lang, 'es'); ?>>🇪🇸 Español</option>
                                <option value="en" <?php selected($lang, 'en'); ?>>🇺🇸 English</option>
                                <option value="pt" <?php selected($lang, 'pt'); ?>>🇧🇷 Português</option>
                            </select>
                        </div>
                        <button type="submit" class="tbot-btn tbot-btn-primary">Guardar Cambios</button>
                    </form>
                </div>

                <div class="tbot-card">
                    <h3 class="tbot-card-title">Detalles de Uso</h3>
                    <div class="tbot-form-row">
                        <label>Personalidad IA Actual</label>
                        <input type="text" value="<?php echo esc_attr($persona); ?>" class="tbot-input" disabled>
                    </div>
                    <div class="tbot-form-row">
                        <label>Mensajes hoy (global)</label>
                        <input type="text" value="<?php echo esc_attr(get_user_meta($user_id, 'tbot_usage_global', true) ?: 0); ?>" class="tbot-input" disabled>
                    </div>
                    <div class="tbot-form-row">
                        <label>Registrado</label>
                        <input type="text" value="<?php echo esc_attr(date('d/m/Y H:i', strtotime($user->user_registered))); ?>" class="tbot-input" disabled>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_update_user() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $user_id = (int) $_POST['user_id'];
        check_admin_referer('tbot_update_user_' . $user_id);

        $subscription = sanitize_text_field($_POST['subscription'] ?? 'free');
        $status       = sanitize_text_field($_POST['status'] ?? 'active');
        $language     = sanitize_text_field($_POST['language'] ?? 'es');

        update_user_meta($user_id, 'tbot_subscription', $subscription);
        update_user_meta($user_id, 'tbot_status', $status);
        update_user_meta($user_id, 'tbot_language', $language);

        // Invalidar caché
        wp_cache_delete('tbot_sub_' . $user_id, 'tbot');
        wp_cache_delete('tbot_blacklist_' . $user_id, 'tbot');

        \TBot\Services\Logger::log('admin', "Usuario #{$user_id} actualizado: sub={$subscription}, status={$status}");

        wp_redirect(admin_url('admin.php?page=tbot-usuarios&action=edit&uid=' . $user_id . '&updated=1'));
        exit;
    }
}
