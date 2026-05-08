<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Broadcast — Campañas masivas segmentadas con programación.
 *
 * Segmentos disponibles:
 *  - Todos los usuarios
 *  - Por plan (free / standard / premium)
 *  - Con racha activa ≥ N días
 *  - Sin actividad en los últimos N días (reactivación)
 *  - Con créditos ≤ N (incentivar recarga)
 *
 * Programación: inmediata o diferida vía WP Cron.
 * Rate: máx 30 msgs/s (Telegram limit), procesado en chunks.
 */
class Broadcast {

    const CHUNK_SIZE = 25; // usuarios por tick de cron

    public function __construct() {
        add_action('tbot_admin_submenu',       [$this, 'register_submenu'], 60);
        add_action('wp_ajax_tbot_send_broadcast', [$this, 'ajax_send']);
        add_action('wp_ajax_tbot_broadcast_status', [$this, 'ajax_status']);
        add_action('tbot_process_broadcast',   [$this, 'process_chunk'], 10, 3);
    }

    public function register_submenu(): void {
        add_submenu_page('tbot-gateway', 'Broadcast', '📣 Broadcast', 'manage_options',
            'tbot-broadcast', [$this, 'render']);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $history = $this->get_recent_campaigns(10);
        ?>
        <div class="wrap tbot-admin">
        <h1>📣 Broadcast — Campañas Masivas</h1>

        <div class="tbot-grid-2" style="gap:24px;align-items:start">

        <!-- ── Composer ───────────────────────────────────────────────────── -->
        <div class="tbot-card">
            <h2>✉️ Nueva Campaña</h2>

            <div class="tbot-form-row">
                <label for="bc-name">Nombre de la campaña</label>
                <input type="text" id="bc-name" class="tbot-input" placeholder="Ej: Promo Mayo 2026">
            </div>

            <div class="tbot-form-row">
                <label for="bc-segment">Segmento</label>
                <select id="bc-segment" class="tbot-input">
                    <option value="all">👥 Todos los usuarios</option>
                    <option value="free">🆓 Plan Free</option>
                    <option value="standard">⭐ Plan Standard</option>
                    <option value="premium">💎 Plan Premium</option>
                    <option value="streak_active">🔥 Con racha activa</option>
                    <option value="inactive_7">😴 Sin actividad 7+ días</option>
                    <option value="inactive_30">💤 Sin actividad 30+ días</option>
                    <option value="low_credits">💳 Créditos bajos (≤50)</option>
                </select>
            </div>

            <div class="tbot-form-row">
                <label for="bc-message">Mensaje <small style="font-weight:400;text-transform:none">(HTML: <b>, <i>, <code>, <a href="">)</small></label>
                <textarea id="bc-message" class="tbot-input" rows="6"
                    placeholder="Escribe tu mensaje aquí...&#10;&#10;Puedes usar {nombre} como variable."></textarea>
            </div>

            <div class="tbot-form-row">
                <label for="bc-button-text">Botón (opcional)</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <input type="text" id="bc-button-text" class="tbot-input" placeholder="Texto del botón">
                    <input type="text" id="bc-button-url" class="tbot-input" placeholder="URL o callback_data">
                </div>
            </div>

            <div class="tbot-form-row">
                <label for="bc-schedule">Programación</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <select id="bc-schedule-type" class="tbot-input">
                        <option value="now">⚡ Enviar ahora</option>
                        <option value="scheduled">📅 Programar</option>
                    </select>
                    <input type="datetime-local" id="bc-schedule-dt" class="tbot-input" style="display:none">
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:8px">
                <button class="button button-primary" id="bc-preview-btn">👁️ Vista previa</button>
                <button class="button button-primary" id="bc-send-btn" style="background:#10b981;border-color:#10b981">📣 Lanzar Campaña</button>
                <span class="tbot-status" id="bc-status"></span>
            </div>

            <!-- Preview -->
            <div id="bc-preview" style="display:none;margin-top:16px;padding:14px;background:#f1f5f9;border-radius:8px;border:1px solid #e2e8f0">
                <p style="font-size:11px;color:#64748b;margin:0 0 8px;font-weight:600;text-transform:uppercase">Vista previa del mensaje</p>
                <div id="bc-preview-content" style="font-size:13px;line-height:1.6;color:#0f172a"></div>
            </div>
        </div>

        <!-- ── Historial ──────────────────────────────────────────────────── -->
        <div class="tbot-card">
            <h2>📋 Historial de Campañas</h2>
            <?php if (empty($history)): ?>
                <p style="color:#94a3b8;font-style:italic">No hay campañas enviadas aún.</p>
            <?php else: ?>
            <table class="widefat striped">
                <thead><tr>
                    <th>Campaña</th><th>Segmento</th><th>Enviados</th><th>Estado</th><th>Fecha</th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $c): ?>
                <tr>
                    <td><strong><?= esc_html($c->name) ?></strong></td>
                    <td><code><?= esc_html($c->segment) ?></code></td>
                    <td><?= number_format((int)$c->sent_count) ?> / <?= number_format((int)$c->total_count) ?></td>
                    <td>
                        <?php
                        $badges = ['pending'=>'⏳','running'=>'🔄','done'=>'✅','failed'=>'❌'];
                        echo ($badges[$c->status] ?? '?') . ' ' . esc_html($c->status);
                        ?>
                    </td>
                    <td style="font-size:11px;color:#64748b"><?= esc_html(substr($c->created_at, 0, 16)) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        </div><!-- .tbot-grid-2 -->
        </div>

        <script>
        jQuery(function($){
            const nonce = '<?= wp_create_nonce('tbot_ajax_nonce') ?>';

            // Schedule type toggle
            $('#bc-schedule-type').on('change', function(){
                $('#bc-schedule-dt').toggle($(this).val() === 'scheduled');
            });

            // Vista previa
            $('#bc-preview-btn').on('click', function(){
                const msg = $('#bc-message').val();
                if (!msg) return;
                $('#bc-preview').show();
                $('#bc-preview-content').html(msg
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/&lt;b&gt;(.*?)&lt;\/b&gt;/g,'<b>$1</b>')
                    .replace(/&lt;i&gt;(.*?)&lt;\/i&gt;/g,'<i>$1</i>')
                    .replace(/&lt;code&gt;(.*?)&lt;\/code&gt;/g,'<code>$1</code>')
                    .replace(/\n/g,'<br>')
                );
            });

            // Enviar campaña
            $('#bc-send-btn').on('click', function(){
                const btn = $(this);
                const msg = $('#bc-message').val().trim();
                if (!msg) { alert('El mensaje está vacío.'); return; }

                const data = {
                    action:        'tbot_send_broadcast',
                    nonce,
                    name:          $('#bc-name').val(),
                    segment:       $('#bc-segment').val(),
                    message:       msg,
                    button_text:   $('#bc-button-text').val(),
                    button_url:    $('#bc-button-url').val(),
                    schedule_type: $('#bc-schedule-type').val(),
                    schedule_dt:   $('#bc-schedule-dt').val(),
                };

                btn.prop('disabled', true).text('⏳ Lanzando...');
                $.post(ajaxurl, data, function(r){
                    btn.prop('disabled', false).text('📣 Lanzar Campaña');
                    const s = $('#bc-status').addClass('visible');
                    if (r.success) {
                        s.text('✅ ' + r.data.message).css('color','#10b981');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        s.text('❌ ' + (r.data?.message || 'Error')).css('color','#ef4444');
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function ajax_send(): void {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden']);

        $name     = sanitize_text_field($_POST['name'] ?? 'Campaña');
        $segment  = sanitize_key($_POST['segment'] ?? 'all');
        $message  = wp_kses($_POST['message'] ?? '', ['b'=>[],'i'=>[],'code'=>[],'a'=>['href'=>[]],'u'=>[],'s'=>[]]);
        $btn_text = sanitize_text_field($_POST['button_text'] ?? '');
        $btn_url  = esc_url_raw($_POST['button_url'] ?? '');
        $sched    = sanitize_text_field($_POST['schedule_type'] ?? 'now');
        $sched_dt = sanitize_text_field($_POST['schedule_dt'] ?? '');

        if (empty($message)) wp_send_json_error(['message' => 'Mensaje vacío']);

        // Construir keyboard si hay botón
        $keyboard = null;
        if ($btn_text) {
            $btn = str_starts_with($btn_url, 'http') || str_starts_with($btn_url, 'tg://')
                ? ['text' => $btn_text, 'url' => $btn_url]
                : ['text' => $btn_text, 'callback_data' => $btn_url ?: 'main_menu'];
            $keyboard = ['inline_keyboard' => [[$btn]]];
        }

        // Obtener usuarios del segmento
        $users = $this->get_segment_users($segment);
        $total = count($users);

        if ($total === 0) {
            wp_send_json_error(['message' => 'No hay usuarios en este segmento']);
        }

        // Guardar campaña en DB
        $campaign_id = $this->save_campaign($name, $segment, $message, $keyboard, $total);

        // Programar o ejecutar inmediatamente
        $token = (string) get_option('tbot_master_token', '');
        if ($sched === 'scheduled' && $sched_dt) {
            $ts = strtotime($sched_dt);
            if ($ts && $ts > time()) {
                wp_schedule_single_event($ts, 'tbot_process_broadcast',
                    [$campaign_id, $users, $token]);
                wp_send_json_success(['message' => "✅ Campaña programada para " . date('d/m/Y H:i', $ts) . " ({$total} usuarios)"]);
                return;
            }
        }

        // Lanzar en background inmediatamente en chunks
        $this->schedule_chunks($campaign_id, $users, $token);
        wp_send_json_success(['message' => "🚀 Campaña iniciada: {$total} mensajes en cola"]);
    }

    // ── Procesamiento por Chunks ──────────────────────────────────────────────

    private function schedule_chunks(int $campaign_id, array $users, string $token): void {
        $chunks = array_chunk($users, self::CHUNK_SIZE);
        foreach ($chunks as $i => $chunk) {
            wp_schedule_single_event(time() + ($i * 2), 'tbot_process_broadcast',
                [$campaign_id, $chunk, $token]);
        }
    }

    public function process_chunk(int $campaign_id, array $users, string $token): void {
        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbot_broadcasts WHERE id = %d", $campaign_id));
        if (!$campaign) return;

        $tg      = new \TBot\Services\TelegramService($token);
        $message = $campaign->message;
        $kb      = $campaign->keyboard ? json_decode($campaign->keyboard, true) : null;
        $sent    = 0;

        foreach ($users as $user_id) {
            $tg_id = get_user_meta($user_id, 'tbot_telegram_id', true);
            $name  = get_userdata($user_id)->display_name ?? '';
            if (!$tg_id) continue;

            $personalized = str_replace('{nombre}', $name, $message);
            $result = $tg->send_message((string)$tg_id, $personalized, 'HTML', $kb);
            if (!empty($result['ok'])) $sent++;
            usleep(50000); // 50ms entre mensajes (20 msgs/s, safe)
        }

        // Actualizar contadores
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tbot_broadcasts
             SET sent_count = sent_count + %d, status = 'running'
             WHERE id = %d", $sent, $campaign_id));

        // Si ya terminamos, marcar como done
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tbot_broadcasts
             SET status = IF(sent_count >= total_count, 'done', status)
             WHERE id = %d", $campaign_id));
    }

    // ── Segmentos ─────────────────────────────────────────────────────────────

    private function get_segment_users(string $segment): array {
        global $wpdb;
        switch ($segment) {
            case 'free':
            case 'standard':
            case 'premium':
                return $wpdb->get_col($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta}
                     WHERE meta_key = 'tbot_subscription' AND meta_value = %s", $segment));
            case 'streak_active':
                return $wpdb->get_col(
                    "SELECT user_id FROM {$wpdb->usermeta}
                     WHERE meta_key = 'tbot_streak_days' AND CAST(meta_value AS UNSIGNED) > 0");
            case 'inactive_7':
                return $wpdb->get_col(
                    "SELECT user_id FROM {$wpdb->usermeta}
                     WHERE meta_key = 'tbot_last_active'
                     AND CAST(meta_value AS UNSIGNED) < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))");
            case 'inactive_30':
                return $wpdb->get_col(
                    "SELECT user_id FROM {$wpdb->usermeta}
                     WHERE meta_key = 'tbot_last_active'
                     AND CAST(meta_value AS UNSIGNED) < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))");
            case 'low_credits':
                return $wpdb->get_col(
                    "SELECT user_id FROM {$wpdb->prefix}tbot_credits WHERE balance <= 50");
            default: // 'all'
                return $wpdb->get_col(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
                     WHERE meta_key = 'tbot_telegram_id' AND meta_value != ''");
        }
    }

    // ── DB ────────────────────────────────────────────────────────────────────

    private function save_campaign(string $name, string $segment, string $message, ?array $keyboard, int $total): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}tbot_broadcasts", [
            'name'        => $name,
            'segment'     => $segment,
            'message'     => $message,
            'keyboard'    => $keyboard ? json_encode($keyboard) : null,
            'total_count' => $total,
            'sent_count'  => 0,
            'status'      => 'pending',
            'created_at'  => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function get_recent_campaigns(int $limit): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tbot_broadcasts ORDER BY id DESC LIMIT %d", $limit
        )) ?: [];
    }

    public static function install_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tbot_broadcasts (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(120) NOT NULL,
            segment     VARCHAR(40)  NOT NULL DEFAULT 'all',
            message     TEXT         NOT NULL,
            keyboard    TEXT         NULL,
            total_count INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count  INT UNSIGNED NOT NULL DEFAULT 0,
            status      ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
            created_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
