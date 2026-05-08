<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Analytics — Dashboard de métricas clave del bot.
 *
 * KPIs rastreados:
 *  - Mensajes enviados por día (DAU proxy)
 *  - Nuevos usuarios por día
 *  - Créditos consumidos y comprados por día
 *  - Rachas activas
 *  - Top comandos usados
 */
class Analytics {

    public function __construct() {
        add_action('tbot_admin_submenu',    [$this, 'register_submenu'], 50);
        add_action('tbot_track_event',      [$this, 'track_event'], 10, 3);
        add_action('tbot_daily_analytics',  [$this, 'aggregate_daily']);

        // Registrar cron diario de agregación
        if (!wp_next_scheduled('tbot_daily_analytics')) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'tbot_daily_analytics');
        }
    }

    public function register_submenu(): void {
        add_submenu_page('tbot-gateway', 'Analytics', '📊 Analytics', 'manage_options',
            'tbot-analytics', [$this, 'render']);
    }

    // ── Render del Panel ──────────────────────────────────────────────────────

    public function render(): void {
        if (!current_user_can('manage_options')) return;

        $days   = max(7, min(90, (int)($_GET['days'] ?? 30)));
        $data   = $this->get_daily_stats($days);
        $totals = $this->get_totals();
        $cmds   = $this->get_top_commands(10);
        ?>
        <style>
            /* Premium SaaS Analytics Theme */
            .tbot-analytics-wrap {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                color: #0f172a;
                margin-top: 20px;
                max-width: 1400px;
            }
            .tbot-dashboard-header {
                display: flex; justify-content: space-between; align-items: center;
                margin-bottom: 24px;
            }
            .tbot-dashboard-header h1 {
                font-size: 28px; font-weight: 800; letter-spacing: -0.5px;
                background: linear-gradient(135deg, #0f172a 0%, #334155 100%);
                -webkit-background-clip: text; -webkit-text-fill-color: transparent;
                margin: 0;
            }
            .tbot-glass-filter {
                background: rgba(255, 255, 255, 0.7);
                backdrop-filter: blur(12px);
                border: 1px solid rgba(255, 255, 255, 0.5);
                padding: 6px; border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                display: inline-flex; gap: 4px;
            }
            .tbot-glass-filter a {
                padding: 6px 16px; border-radius: 8px; font-weight: 600; font-size: 13px;
                color: #64748b; text-decoration: none; transition: all 0.2s;
            }
            .tbot-glass-filter a:hover { background: rgba(0,0,0,0.03); color: #0f172a; }
            .tbot-glass-filter a.active {
                background: #fff; color: #3b82f6;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .tbot-kpi-premium-grid {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 20px; margin-bottom: 30px;
            }
            .tbot-kpi-premium-card {
                background: #fff; border-radius: 16px; padding: 24px;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.04), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
                border: 1px solid #f1f5f9; position: relative; overflow: hidden;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .tbot-kpi-premium-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            }
            .tbot-kpi-premium-card::before {
                content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
                background: var(--card-color, #e2e8f0);
            }
            .tbot-kpi-icon-wrap {
                width: 48px; height: 48px; border-radius: 12px;
                display: flex; align-items: center; justify-content: center;
                font-size: 24px; margin-bottom: 16px;
                background: var(--card-bg, #f1f5f9);
            }
            .tbot-kpi-label { font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; display: block; }
            .tbot-kpi-value { font-size: 32px; font-weight: 800; color: #0f172a; line-height: 1; margin: 0; }
            .tbot-kpi-sub { font-size: 12px; color: #94a3b8; margin-top: 8px; display: block; }
            
            .tbot-charts-grid {
                display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 30px;
            }
            @media(max-width: 1024px) { .tbot-charts-grid { grid-template-columns: 1fr; } }
            
            .tbot-chart-container {
                background: #fff; border-radius: 16px; padding: 24px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
                border: 1px solid #f1f5f9;
            }
            .tbot-chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .tbot-chart-title { font-size: 16px; font-weight: 700; color: #1e293b; margin: 0; }
            
            .tbot-table-modern { width: 100%; border-collapse: collapse; }
            .tbot-table-modern th { text-align: left; padding: 12px 16px; color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
            .tbot-table-modern td { padding: 16px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; font-weight: 500; }
            .tbot-table-modern tbody tr:hover { background: #f8fafc; }
            .tbot-cmd-badge { background: #eff6ff; color: #3b82f6; padding: 4px 10px; border-radius: 6px; font-family: monospace; font-size: 13px; }
        </style>

        <div class="wrap tbot-admin tbot-analytics-wrap">
            <div class="tbot-dashboard-header">
                <h1>📊 Executive Analytics</h1>
                <div class="tbot-glass-filter">
                    <?php foreach ([7, 14, 30, 60, 90] as $d): ?>
                        <a href="?page=tbot-analytics&days=<?= $d ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tbot-kpi-premium-grid">
                <!-- Ingresos USD -->
                <div class="tbot-kpi-premium-card" style="--card-color: #10b981; --card-bg: #d1fae5;">
                    <div class="tbot-kpi-icon-wrap">💰</div>
                    <span class="tbot-kpi-label">Ingresos Brutos</span>
                    <h3 class="tbot-kpi-value">$<?= number_format($totals['revenue_usd'], 2) ?></h3>
                    <span class="tbot-kpi-sub">Total facturado estimado</span>
                </div>
                
                <!-- Tokens Consumidos -->
                <div class="tbot-kpi-premium-card" style="--card-color: #a855f7; --card-bg: #f3e8ff;">
                    <div class="tbot-kpi-icon-wrap">🧠</div>
                    <span class="tbot-kpi-label">Tokens AI</span>
                    <h3 class="tbot-kpi-value"><?= number_format($totals['tokens_used'] / 1000, 1) ?>K</h3>
                    <span class="tbot-kpi-sub">Consumo total de infraestructura</span>
                </div>
                
                <!-- Mensajes -->
                <div class="tbot-kpi-premium-card" style="--card-color: #3b82f6; --card-bg: #dbeafe;">
                    <div class="tbot-kpi-icon-wrap">🗣️</div>
                    <span class="tbot-kpi-label">Interacciones</span>
                    <h3 class="tbot-kpi-value"><?= number_format($totals['messages']) ?></h3>
                    <span class="tbot-kpi-sub">Mensajes procesados</span>
                </div>
                
                <!-- Usuarios Activos (Retención Proxy) -->
                <div class="tbot-kpi-premium-card" style="--card-color: #f59e0b; --card-bg: #fef3c7;">
                    <div class="tbot-kpi-icon-wrap">👥</div>
                    <span class="tbot-kpi-label">Usuarios (Total)</span>
                    <h3 class="tbot-kpi-value"><?= number_format($totals['users']) ?></h3>
                    <span class="tbot-kpi-sub">Racha máx: <?= $totals['max_streak'] ?> días</span>
                </div>
            </div>

            <div class="tbot-charts-grid">
                <!-- Gráfica Principal -->
                <div class="tbot-chart-container">
                    <div class="tbot-chart-header">
                        <h2 class="tbot-chart-title">Crecimiento y Retención</h2>
                    </div>
                    <canvas id="tbot-chart-growth" height="100"></canvas>
                </div>
                
                <!-- Gráfica Financiera -->
                <div class="tbot-chart-container">
                    <div class="tbot-chart-header">
                        <h2 class="tbot-chart-title">Tokenomics (USD vs Uso)</h2>
                    </div>
                    <canvas id="tbot-chart-tokenomics" height="150"></canvas>
                </div>
            </div>

            <!-- Top Comandos -->
            <div class="tbot-chart-container" style="max-width: 800px;">
                <div class="tbot-chart-header">
                    <h2 class="tbot-chart-title">🏆 Top Comandos</h2>
                </div>
                <table class="tbot-table-modern">
                    <thead><tr><th style="width: 50px;">#</th><th>Comando</th><th style="text-align: right;">Usos</th></tr></thead>
                    <tbody>
                    <?php foreach ($cmds as $i => $row): ?>
                    <tr>
                        <td><span style="color:#94a3b8; font-weight:700;"><?= $i + 1 ?></span></td>
                        <td><span class="tbot-cmd-badge"><?= esc_html($row['command']) ?></span></td>
                        <td style="text-align: right;"><?= number_format((int)$row['count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
        <script>
        (function(){
            const labels  = <?= json_encode(array_column($data, 'date')) ?>;
            const msgs    = <?= json_encode(array_column($data, 'messages')) ?>;
            const users   = <?= json_encode(array_column($data, 'new_users')) ?>;
            const revenue = <?= json_encode(array_column($data, 'revenue_usd')) ?>;
            const tokens  = <?= json_encode(array_column($data, 'tokens_used')) ?>;

            // Chart 1: Growth
            const ctxGrowth = document.getElementById('tbot-chart-growth').getContext('2d');
            const gradientBlue = ctxGrowth.createLinearGradient(0, 0, 0, 400);
            gradientBlue.addColorStop(0, 'rgba(59, 130, 246, 0.2)');
            gradientBlue.addColorStop(1, 'rgba(59, 130, 246, 0)');

            new Chart(ctxGrowth, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'Mensajes', data: msgs, borderColor: '#3b82f6', backgroundColor: gradientBlue, fill: true, tension: 0.4, borderWidth: 3, pointRadius: 0, pointHoverRadius: 6 },
                        { label: 'Usuarios Nuevos', data: users, borderColor: '#10b981', borderDash: [5, 5], tension: 0.4, borderWidth: 2, pointRadius: 0 }
                    ],
                },
                options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false } }, x: { grid: { display: false }, border: { display: false } } } }
            });

            // Chart 2: Tokenomics
            new Chart(document.getElementById('tbot-chart-tokenomics'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Ingresos ($)', data: revenue, backgroundColor: '#10b981', borderRadius: 4, yAxisID: 'y' },
                        { label: 'Tokens', data: tokens, type: 'line', borderColor: '#a855f7', borderWidth: 2, pointRadius: 0, tension: 0.4, yAxisID: 'y1' }
                    ],
                },
                options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } } }, scales: { y: { beginAtZero: true, position: 'left', grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false } }, y1: { beginAtZero: true, position: 'right', grid: { display: false }, border: { display: false } }, x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 7 } } } }
            });
        })();
        </script>
        <?php
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    private function get_daily_stats(int $days): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tbot_analytics_daily';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT date, messages, new_users, credits_sold, credits_used, revenue_usd, tokens_used
             FROM {$table}
             WHERE date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
             ORDER BY date ASC",
            $days
        ), ARRAY_A) ?: [];
    }

    private function get_totals(): array {
        global $wpdb;
        $daily_table = $wpdb->prefix . 'tbot_analytics_daily';
        return [
            'messages'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tbot_logs WHERE event_type IN ('ai_dispatch','ai_response','inline')"),
            'users'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            'max_streak'   => (int) $wpdb->get_var("SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->usermeta} WHERE meta_key='tbot_streak_days'"),
            'credits_sold' => (int) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}tbot_credit_transactions WHERE type LIKE 'pack_%'"),
            'credits_used' => (int) $wpdb->get_var("SELECT COALESCE(ABS(SUM(amount)),0) FROM {$wpdb->prefix}tbot_credit_transactions WHERE amount < 0"),
            'conversions'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tbot_credit_transactions WHERE type LIKE 'pack_%'"),
            'revenue_usd'  => (float) $wpdb->get_var("SELECT COALESCE(SUM(revenue_usd), 0) FROM {$daily_table}"),
            'tokens_used'  => (int) $wpdb->get_var("SELECT COALESCE(SUM(tokens_used), 0) FROM {$daily_table}"),
        ];
    }

    private function get_top_commands(int $limit): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT SUBSTRING_INDEX(message, ':', 1) as command, COUNT(*) as count
             FROM {$wpdb->prefix}tbot_logs
             WHERE event_type IN ('command','callback')
             GROUP BY command
             ORDER BY count DESC
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    // ── Agregación Diaria (cron) ──────────────────────────────────────────────

    public function aggregate_daily(): void {
        global $wpdb;
        $table     = $wpdb->prefix . 'tbot_analytics_daily';
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $messages = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tbot_logs
             WHERE event_type = 'ai_dispatch' AND DATE(created_at) = %s", $yesterday));
        $new_users = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE DATE(user_registered) = %s", $yesterday));
        
        // Ingresos: Buscamos compras y calculamos aprox a $0.004/crédito ($20 = 5000cr)
        $credits_sold = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}tbot_credit_transactions
             WHERE type LIKE 'pack_%' AND DATE(created_at) = %s", $yesterday));
        $revenue_usd = $credits_sold * 0.004;

        $credits_used = (int) abs($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}tbot_credit_transactions
             WHERE amount < 0 AND DATE(created_at) = %s", $yesterday)));

        // Extraer tokens de los logs ("... tokens=150") usando Regex básico
        $logs_ai = $wpdb->get_col($wpdb->prepare(
            "SELECT message FROM {$wpdb->prefix}tbot_logs 
             WHERE event_type = 'ai_response' AND DATE(created_at) = %s", $yesterday));
        $tokens_used = 0;
        foreach ($logs_ai as $msg) {
            if (preg_match('/tokens=(\d+)/', $msg, $matches)) {
                $tokens_used += (int) $matches[1];
            }
        }

        $wpdb->replace($table, [
            'date'         => $yesterday,
            'messages'     => $messages,
            'new_users'    => $new_users,
            'credits_sold' => $credits_sold,
            'credits_used' => $credits_used,
            'revenue_usd'  => $revenue_usd,
            'tokens_used'  => $tokens_used,
        ]);
    }

    // ── Tabla DB ──────────────────────────────────────────────────────────────

    public static function install_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'tbot_analytics_daily';
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            date           DATE         NOT NULL,
            messages       INT UNSIGNED NOT NULL DEFAULT 0,
            new_users      INT UNSIGNED NOT NULL DEFAULT 0,
            credits_sold   INT UNSIGNED NOT NULL DEFAULT 0,
            credits_used   INT UNSIGNED NOT NULL DEFAULT 0,
            revenue_usd    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tokens_used    INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (date)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Actualizar tabla si ya existe
        $row = $wpdb->get_row("SELECT * FROM {$table} LIMIT 1");
        if ($row && !isset($row->revenue_usd)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN revenue_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN tokens_used INT UNSIGNED NOT NULL DEFAULT 0");
        }
    }
}
