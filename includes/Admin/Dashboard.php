<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Admin Dashboard — Punto de entrada principal del panel TBot Gateway.
 * Registra todos los submenús y las páginas de admin.
 */
class Dashboard {

    public function __construct() {
        add_action('admin_menu',    [$this, 'register_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menus() {
        add_menu_page(
            'TBot Gateway',
            'TBot Gateway',
            'manage_options',
            'tbot-gateway',
            [$this, 'render_dashboard'],
            'dashicons-rest-api',
            30
        );
        add_submenu_page('tbot-gateway', 'Dashboard',     'Dashboard',     'manage_options', 'tbot-gateway',    [$this, 'render_dashboard']);
        add_submenu_page('tbot-gateway', 'Usuarios',      'Usuarios',      'manage_options', 'tbot-usuarios',   [new Users(),       'render']);
        add_submenu_page('tbot-gateway', 'Bots',          'Bots',          'manage_options', 'tbot-bots',       [new Bots(),        'render']);
        add_submenu_page('tbot-gateway', 'Planes',        'Planes',        'manage_options', 'tbot-planes',     [new Plans(),       'render']);
        add_submenu_page('tbot-gateway', 'Personalidades','Personalidades','manage_options', 'tbot-personas',   [new Personas(),    'render']);
        add_submenu_page('tbot-gateway', 'Mensajes Bot',  'Mensajes Bot',  'manage_options', 'tbot-mensajes',   [new BotMessages(), 'render']);
        add_submenu_page('tbot-gateway', 'Ajustes',       'Ajustes',       'manage_options', 'tbot-ajustes',    [new Settings(),    'render_page']);
        add_submenu_page('tbot-gateway', 'Diagnóstico',   'Diagnóstico',   'manage_options', 'tbot-diagnostico',[new Diagnostics(), 'render']);
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'tbot') === false) return;

        wp_enqueue_style(
            'tbot-admin-ui',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/admin.css',
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'tbot-admin-js',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );
        wp_localize_script('tbot-admin-js', 'TBotAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tbot_ajax_nonce'),
        ]);
    }

    public function render_dashboard() {
        global $wpdb;

        // Métricas
        $total_users   = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'tbot_telegram_id'");
        $premium_users = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'tbot_subscription' AND meta_value = 'premium'");
        $total_bots    = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tbot_user_bots WHERE is_active = 1");
        $total_links   = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tbot_short_links");

        $recent_logs = \TBot\Services\Logger::get_recent_logs(5);
        ?>
        <div class="tbot-wrap">
            <?php self::render_header('Dashboard', ''); ?>

            <div class="tbot-stats-grid">
                <?php self::stat_card('👥', 'Usuarios Totales',  $total_users   ?? 0, '#3b82f6'); ?>
                <?php self::stat_card('💎', 'Usuarios Premium',  $premium_users ?? 0, '#8b5cf6'); ?>
                <?php self::stat_card('🤖', 'Bots Activos',       $total_bots    ?? 0, '#10b981'); ?>
                <?php self::stat_card('🔗', 'Links Acortados',    $total_links   ?? 0, '#f59e0b'); ?>
            </div>

            <div class="tbot-grid-2">
                <div class="tbot-card">
                    <h3 class="tbot-card-title">⚡ Actividad Reciente</h3>
                    <table class="tbot-table">
                        <thead><tr><th>Hora</th><th>Evento</th><th>Mensaje</th></tr></thead>
                        <tbody>
                        <?php if ($recent_logs): foreach ($recent_logs as $log): ?>
                            <tr>
                                <td class="tbot-muted"><?php echo esc_html(date('H:i:s', strtotime($log->created_at))); ?></td>
                                <td><span class="tbot-badge tbot-badge-<?php echo esc_attr($log->event_type); ?>"><?php echo esc_html($log->event_type); ?></span></td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="tbot-muted tbot-center">Sin actividad aún</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <a href="<?php echo admin_url('admin.php?page=tbot-diagnostico'); ?>" class="tbot-link">Ver todos los logs →</a>
                </div>

                <div class="tbot-card">
                    <h3 class="tbot-card-title">🚀 Acceso Rápido</h3>
                    <div class="tbot-quick-actions">
                        <a href="<?php echo admin_url('admin.php?page=tbot-usuarios'); ?>" class="tbot-action-btn">
                            <span>👥</span> Gestionar Usuarios
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=tbot-bots'); ?>" class="tbot-action-btn">
                            <span>🤖</span> Gestionar Bots
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=tbot-planes'); ?>" class="tbot-action-btn">
                            <span>💎</span> Configurar Planes
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=tbot-ajustes'); ?>" class="tbot-action-btn">
                            <span>⚙️</span> Ajustes del Bot
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=tbot-diagnostico'); ?>" class="tbot-action-btn">
                            <span>🔍</span> Diagnóstico
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=tbot-personas'); ?>" class="tbot-action-btn">
                            <span>🎭</span> Personalidades IA
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_header(string $title, string $subtitle = '') {
        ?>
        <div class="tbot-header">
            <div class="tbot-header-inner">
                <div class="tbot-logo">
                    <span class="tbot-logo-icon">🤖</span>
                    <div>
                        <h1 class="tbot-title">TBot Gateway</h1>
                        <p class="tbot-subtitle"><?php echo esc_html($title); ?><?php if ($subtitle): ?> — <?php echo esc_html($subtitle); ?><?php endif; ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function stat_card(string $icon, string $label, int $value, string $color) {
        ?>
        <div class="tbot-stat-card" style="--accent-color: <?php echo esc_attr($color); ?>">
            <div class="tbot-stat-icon"><?php echo $icon; ?></div>
            <div class="tbot-stat-body">
                <div class="tbot-stat-value"><?php echo number_format($value); ?></div>
                <div class="tbot-stat-label"><?php echo esc_html($label); ?></div>
            </div>
        </div>
        <?php
    }
}
