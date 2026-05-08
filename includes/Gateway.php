<?php
namespace TBot;

if (!defined('ABSPATH')) exit;

class Gateway {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();

        if (is_admin()) {
            new \TBot\Admin\Dashboard();
            new \TBot\Admin\AjaxHandler();
            new \TBot\Admin\Users();
            new \TBot\Admin\Bots();
            new \TBot\Admin\Plans();
            new \TBot\Admin\Personas();
            new \TBot\Admin\Credits();
            new \TBot\Admin\Analytics();
            new \TBot\Admin\Broadcast();
            new \TBot\Admin\AIModels();
            new \TBot\Admin\BotMessages();
            new \TBot\Admin\Diagnostics();
            new \TBot\Admin\Settings();
            new \TBot\Admin\UserFields();
            new \TBot\Admin\AITools();
        }
    }

    private function init_hooks() {
        // Registrar rutas de la REST API
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Registrar tareas programadas (WP Cron)
        add_action('tbot_daily_cleanup', [$this, 'daily_cleanup']);
        add_action('tbot_daily_log_cleanup', ['\TBot\Services\Logger', 'cleanup_old_logs']);

        if (!wp_next_scheduled('tbot_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tbot_daily_cleanup');
        }
        if (!wp_next_scheduled('tbot_daily_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tbot_daily_log_cleanup');
        }

        // ── Servicios que registran hooks propios ────────────────────
        \TBot\Services\ABTester::init_hooks();

        // ── Cron para recordatorios de racha (cada hora) ─────────────────────
        add_action('tbot_streak_reminder', ['\TBot\Services\StreakManager', 'check_reminders']);
        if (!wp_next_scheduled('tbot_streak_reminder')) {
            wp_schedule_event(time(), 'hourly', 'tbot_streak_reminder');
        }

        // ── Cron para purga de memoria de chat (diario) ─────────────────────
        add_action('tbot_purge_chat_memory', ['\TBot\Services\ChatMemory', 'purge_old']);
        if (!wp_next_scheduled('tbot_purge_chat_memory')) {
            wp_schedule_event(time(), 'daily', 'tbot_purge_chat_memory');
        }

        // ── Cron para flush MySQL → ClickHouse (cada 5 min) ─────────────
        add_filter('cron_schedules', function($schedules) {
            $schedules['five_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
            return $schedules;
        });
        add_action('tbot_flush_clickhouse', ['\TBot\Services\ClickHouseMemory', 'flush_from_mysql']);
        if (!wp_next_scheduled('tbot_flush_clickhouse')) {
            wp_schedule_event(time(), 'five_minutes', 'tbot_flush_clickhouse');
        }

        // ── Broadcast chunk processor ───────────────────────────────────
        add_action('tbot_process_broadcast', function(int $campaign_id, array $users, string $token) {
            (new \TBot\Admin\Broadcast())->process_chunk($campaign_id, $users, $token);
        }, 10, 3);

        // ── Hook para mensajes con delay (streaks milestones) ─────────────────
        add_action('tbot_send_message', function(string $chat_id, string $text, string $token) {
            $tg = new \TBot\Services\TelegramService($token);
            $tg->send_message($chat_id, $text, 'HTML');
        }, 10, 3);

        // ── Cron para auto-expiración de menús de Telegram ────────────────────
        add_action('tbot_expire_keyboard', function(string $chat_id, int $msg_id, string $token) {
            $tg = new \TBot\Services\TelegramService($token);
            $tg->edit_message_reply_markup($chat_id, $msg_id, null);
        }, 10, 3);

        add_action('tbot_delete_message', function(string $chat_id, int $msg_id, string $token) {
            $tg = new \TBot\Services\TelegramService($token);
            $tg->delete_message($chat_id, $msg_id);
        }, 10, 3);

        // Activación (Crear tablas)
        register_activation_hook(WP_PLUGIN_DIR . '/tbot-gateway/tbot-gateway.php', [$this, 'activate']);
    }

    public function activate() {
        global $wpdb;
        $table_short_links = $wpdb->prefix . 'tbot_short_links';
        $table_user_bots   = $wpdb->prefix . 'tbot_user_bots';
        $charset_collate   = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_short_links (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            uid bigint(20) NOT NULL,
            short_code varchar(10) NOT NULL,
            original_url text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY short_code (short_code)
        ) $charset_collate;

        CREATE TABLE $table_user_bots (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            bot_id varchar(50) NOT NULL,
            bot_token text NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY bot_id (bot_id)
        ) $charset_collate;";

        $table_logs = $wpdb->prefix . 'tbot_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            message text NOT NULL,
            payload longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql_logs);

        // Tablas de créditos
        \TBot\Services\CreditManager::install_tables();

        // Tabla de analytics diarios
        \TBot\Admin\Analytics::install_table();

        // Tabla de broadcast / campañas
        \TBot\Admin\Broadcast::install_table();

        // Tabla hot-path de estado de usuario
        \TBot\Services\UserState::install_table();

        // Tabla de memoria conversacional
        \TBot\Services\ChatMemory::install();

        // Colección vectorial en Qdrant (solo si disponible)
        if (\TBot\Services\VectorMemory::is_available()) {
            \TBot\Services\VectorMemory::install();
        }

        // Tabla en ClickHouse (solo si disponible)
        if (\TBot\Services\ClickHouseMemory::is_available()) {
            \TBot\Services\ClickHouseMemory::install();
        }

        // Índices de rendimiento en tbot_logs
        \TBot\Services\Logger::ensure_index();
    }

    public function register_routes() {
        $api = new \TBot\Rest\API();
        $api->register_routes();

        $mini = new \TBot\Rest\MiniAppAPI();
        $mini->register();
    }

    public function daily_cleanup() {
        // Lógica para resetear cuotas diarias en usermeta
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'tbot_usage_%'");
    }
}
