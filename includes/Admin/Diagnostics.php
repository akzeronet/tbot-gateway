<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Diagnostics — Logs, tests de conexión y configuración de webhook (AJAX).
 */
class Diagnostics {

    public function __construct() {
        // Fallback handlers (admin_post_*) — también disponibles via AjaxHandler
        add_action('admin_post_tbot_test_ai',        [$this, 'handle_test_ai']);
        add_action('admin_post_tbot_check_telegram', [$this, 'handle_check_telegram']);
        add_action('admin_post_tbot_set_webhook',    [$this, 'handle_set_webhook']);
        add_action('admin_post_tbot_clear_logs',     [$this, 'handle_clear_logs']);
        add_action('wp_ajax_tbot_migrate_userstate',  [$this, 'handle_migrate_userstate']);
    }

    public function render() {
        $logs = \TBot\Services\Logger::get_recent_logs(50);
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Diagnóstico', 'Logs y pruebas de conexión'); ?>

            <div class="tbot-grid-3">

                <!-- 1. Telegram → WP -->
                <div class="tbot-card tbot-diag-card">
                    <div class="tbot-diag-icon">📡</div>
                    <h3>Telegram → WordPress</h3>
                    <p class="tbot-muted">Consulta el estado de tu webhook directamente en la API de Telegram.</p>
                    <form id="tbot-form-check-telegram">
                        <button type="submit" class="tbot-btn tbot-btn-primary tbot-btn-block">Verificar Estado</button>
                    </form>
                    <div id="tbot-result-telegram"></div>
                </div>

                <!-- 2. WP → IA Providers -->
                <div class="tbot-card tbot-diag-card">
                    <div class="tbot-diag-icon">🤖</div>
                    <h3>Test de IA</h3>
                    <p class="tbot-muted">Envía un prompt de prueba al proveedor IA configurado y valida la respuesta.</p>
                    <form id="tbot-form-test-ai">
                        <button type="submit" class="tbot-btn tbot-btn-primary tbot-btn-block">Probar IA</button>
                    </form>
                    <div id="tbot-result-ai"></div>
                </div>

                <!-- 3. Activar Webhook -->
                <div class="tbot-card tbot-diag-card" style="border:2px solid #f59e0b;">
                    <div class="tbot-diag-icon">🚀</div>
                    <h3>Activar Webhook</h3>
                    <p class="tbot-muted">Registra tu WordPress en Telegram. Solo necesitas hacer esto una vez.</p>
                    <p class="tbot-muted" style="font-size:11px; margin:0 0 6px;">
                        URL: <code><?php echo esc_url(get_rest_url(null, 'tbot/v1/webhook')); ?></code>
                    </p>
                    <form id="tbot-form-set-webhook" style="margin-bottom:10px;">
                        <button type="submit" class="tbot-btn tbot-btn-warning tbot-btn-block">Configurar Webhook</button>
                    </form>
                    <form id="tbot-form-flush-webhook">
                        <button type="submit" class="tbot-btn tbot-btn-primary tbot-btn-block" style="background:#ef4444;border-color:#ef4444;">Desentaponar Webhook (Limpiar Cola)</button>
                    </form>
                    <div id="tbot-result-webhook"></div>
                </div>

                <!-- 4. Self-Hosted Status -->
                <div class="tbot-card tbot-diag-card" style="border:2px solid #10b981;">
                    <div class="tbot-diag-icon">🖥️</div>
                    <h3>Self-Hosted Status</h3>
                    <?php
                    $ollama_ok = \TBot\Services\SelfHostedLLM::is_available();
                    $qdrant_ok = \TBot\Services\VectorMemory::is_available();
                    $qdrant_stats = $qdrant_ok ? \TBot\Services\VectorMemory::get_stats() : [];
                    $ollama_models = $ollama_ok ? \TBot\Services\SelfHostedLLM::list_models() : [];
                    ?>
                    <table style="width:100%;font-size:13px;margin:8px 0;">
                        <tr>
                            <td>Ollama</td>
                            <td style="text-align:right;font-weight:700;color:<?php echo $ollama_ok ? '#10b981' : '#ef4444'; ?>">
                                <?php echo $ollama_ok ? '🟢 Online' : '🔴 Offline'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Qdrant</td>
                            <td style="text-align:right;font-weight:700;color:<?php echo $qdrant_ok ? '#10b981' : '#ef4444'; ?>">
                                <?php echo $qdrant_ok ? '🟢 Online' : '🔴 Offline'; ?>
                            </td>
                        </tr>
                        <?php if ($qdrant_ok): ?>
                        <tr>
                            <td>Vectores almacenados</td>
                            <td style="text-align:right;font-weight:700;"><?php echo number_format($qdrant_stats['points_count'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ollama_ok && !empty($ollama_models)): ?>
                        <tr>
                            <td>Modelos cargados</td>
                            <td style="text-align:right;font-size:11px;">
                                <?php echo implode(', ', array_column($ollama_models, 'name')); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                        Smart Routing: <?php echo get_option('tbot_use_self_hosted') ? '<span style="color:#10b981;">✅ Activo</span>' : '<span style="color:#f59e0b;">⚠️ Desactivado</span>'; ?>
                    </p>
                </div>

                <!-- 5. LLM Providers Status -->
                <div class="tbot-card tbot-diag-card" style="border:2px solid #8b5cf6;">
                    <div class="tbot-diag-icon">🤖</div>
                    <h3>LLM Providers (Cascading)</h3>
                    <?php
                    $prov_stats = \TBot\Services\ProviderRegistry::get_status_summary();
                    ?>
                    <table style="width:100%;font-size:13px;margin:8px 0;">
                        <?php foreach (['self_hosted' => 'Local ($0)', 'serverless' => 'Serverless', 'premium' => 'Premium API'] as $type => $label): ?>
                            <tr>
                                <td colspan="2" style="font-size:11px;font-weight:700;color:var(--tbot-muted);padding-top:6px;"><?php echo $label; ?></td>
                            </tr>
                            <?php foreach ($prov_stats['by_type'][$type]['providers'] ?? [] as $p): ?>
                                <tr>
                                    <td style="padding-left:8px;"><?php echo esc_html($p['name']); ?></td>
                                    <td style="text-align:right;font-weight:700;color:<?php echo $p['available'] ? '#10b981' : '#ef4444'; ?>">
                                        <?php echo $p['available'] ? '🟢 ON' : '🔴 OFF'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </table>
                    <p class="tbot-muted" style="font-size:11px;margin:4px 0 0;">
                        Total activos: <strong><?php echo $prov_stats['available']; ?>/<?php echo $prov_stats['total']; ?></strong>
                    </p>
                </div>

                <!-- 6. Storage Tiers Status -->
                <div class="tbot-card tbot-diag-card" style="border:2px solid #3b82f6;">
                    <div class="tbot-diag-icon">💾</div>
                    <h3>Storage Tiers</h3>
                    <?php
                    $ch_ok = \TBot\Services\ClickHouseMemory::is_available();
                    $redis_ok = \TBot\Services\RedisMemory::is_available();
                    $ch_stats = $ch_ok ? \TBot\Services\ClickHouseMemory::get_stats() : [];
                    ?>
                    <table style="width:100%;font-size:13px;margin:8px 0;">
                        <tr>
                            <td><strong>L0</strong> Redis (Ultra-Hot)</td>
                            <td style="text-align:right;font-weight:700;color:<?php echo $redis_ok ? '#ef4444' : 'var(--tbot-muted)'; ?>">
                                <?php echo $redis_ok ? '🚀 Online' : '⚪ Inactivo'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>L1</strong> MySQL (Hot)</td>
                            <td style="text-align:right;font-weight:700;color:#10b981;">🟢 Online</td>
                        </tr>
                        <tr>
                            <td><strong>L2</strong> Qdrant (Vector)</td>
                            <td style="text-align:right;font-weight:700;color:<?php echo $qdrant_ok ? '#10b981' : '#ef4444'; ?>">
                                <?php echo $qdrant_ok ? '🟢 Online' : '🔴 Offline'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>L3</strong> ClickHouse (Archive)</td>
                            <td style="text-align:right;font-weight:700;color:<?php echo $ch_ok ? '#10b981' : '#ef4444'; ?>">
                                <?php echo $ch_ok ? '🟢 Online' : '🔴 Offline'; ?>
                            </td>
                        </tr>
                        <?php if ($ch_ok && !empty($ch_stats['total_messages'])): ?>
                        <tr>
                            <td colspan="2" style="text-align:right;font-size:11px;color:var(--tbot-muted);">
                                <?php echo number_format($ch_stats['total_messages']); ?> msgs en archivo
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>

            </div>

            <!-- Tabla de Logs -->
            <div class="tbot-card" style="margin-top:24px;">
                <div class="tbot-card-toolbar" id="tbot-logs-toolbar">
                    <h3 class="tbot-card-title" style="margin:0;">📋 Logs del Sistema</h3>
                    <form id="tbot-form-clear-logs" style="display:inline;">
                        <button type="submit" class="tbot-btn tbot-btn-danger tbot-btn-sm">🗑 Borrar Logs</button>
                    </form>
                </div>

                <div style="overflow-x:auto;">
                    <table class="tbot-table tbot-logs-table">
                        <thead>
                            <tr>
                                <th style="width:150px;">Fecha/Hora</th>
                                <th style="width:120px;">Tipo</th>
                                <th>Mensaje</th>
                                <th>Payload</th>
                            </tr>
                        </thead>
                        <tbody id="tbot-logs-tbody">
                        <?php if ($logs): foreach ($logs as $log): ?>
                            <tr>
                                <td class="tbot-muted" style="font-size:11px;white-space:nowrap;"><?php echo esc_html($log->created_at); ?></td>
                                <td><span class="tbot-badge tbot-badge-<?php echo esc_attr($log->event_type); ?>"><?php echo esc_html($log->event_type); ?></span></td>
                                <td><?php echo esc_html($log->message); ?></td>
                                <td>
                                    <?php if ($log->payload): ?>
                                        <details>
                                            <summary>Ver payload</summary>
                                            <pre class="tbot-payload-preview"><?php echo esc_html(json_encode(json_decode($log->payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                        </details>
                                    <?php else: ?>
                                        <span class="tbot-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="4" class="tbot-center tbot-muted" style="padding:20px;">Sin logs registrados aún.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Fallback handlers (admin_post_* — por si AJAX falla) ────────────────

    public function handle_test_ai() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_test_ai');
        $result = \TBot\Services\AIService::chat([
            'model'         => 'gpt-4o-mini',
            'message'       => 'Di "OK" y nada más.',
            'system_prompt' => 'Responde con una sola palabra.',
            'max_tokens'    => 10,
        ]);
        \TBot\Services\Logger::log(
            $result['success'] ? 'test' : 'error',
            $result['success'] ? 'AI test OK: ' . ($result['text'] ?? '') : 'AI test FAIL: ' . ($result['error'] ?? 'unknown')
        );
        wp_redirect(admin_url('admin.php?page=tbot-diagnostico'));
        exit;
    }

    public function handle_migrate_userstate() {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $count = \TBot\Services\UserState::migrate_all();
        wp_send_json_success(['migrated' => $count, 'message' => "{$count} usuarios migrados a tbot_user_state"]);
    }

    public function handle_check_telegram() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_check_telegram');
        wp_redirect(admin_url('admin.php?page=tbot-diagnostico'));
        exit;
    }

    public function handle_set_webhook() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_set_webhook');
        wp_redirect(admin_url('admin.php?page=tbot-diagnostico'));
        exit;
    }

    public function handle_clear_logs() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_clear_logs');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tbot_logs");
        wp_redirect(admin_url('admin.php?page=tbot-diagnostico'));
        exit;
    }
}
