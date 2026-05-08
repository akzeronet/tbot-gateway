<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Credits — Panel de administración de créditos y configuración de packs.
 */
class Credits {

    public function __construct() {
        add_action('tbot_admin_submenu', [$this, 'register_submenu'], 40);
        add_action('wp_ajax_tbot_save_credit_packs', [$this, 'ajax_save_packs']);
        add_action('wp_ajax_tbot_save_credit_costs', [$this, 'ajax_save_costs']);
        add_action('wp_ajax_tbot_grant_credits',     [$this, 'ajax_grant_credits']);
    }

    public function register_submenu() {
        add_submenu_page('tbot-gateway', 'Créditos', '💳 Créditos', 'manage_options',
            'tbot-credits', [$this, 'render']);
    }

    public function render() {
        if (!current_user_can('manage_options')) return;
        $packs = \TBot\Services\CreditManager::get_packs();
        $costs = get_option('tbot_credit_costs', []);
        if (empty($costs)) {
            $costs = ['text_basic'=>1,'text_advanced'=>3,'photo'=>5,'voice'=>3,'image_gen'=>20];
        }
        ?>
        <div class="wrap tbot-admin">
        <h1>💳 Gestión de Créditos</h1>

        <!-- ── Costos por Operación ─────────────────────────────────── -->
        <div class="tbot-card">
            <h2>⚙️ Costo por Operación</h2>
            <p class="description">Cuántos créditos consume cada tipo de mensaje.</p>
            <table class="widefat striped" id="tbot-cost-table">
                <thead><tr><th>Operación</th><th>Créditos</th></tr></thead>
                <tbody>
                <?php
                $ops = [
                    'text_basic'    => '💬 Texto (modelo básico)',
                    'text_advanced' => '🧠 Texto (modelo avanzado)',
                    'photo'         => '📷 Análisis de foto',
                    'voice'         => '🎙️ Transcripción de voz',
                    'image_gen'     => '🎨 Generación de imagen',
                ];
                foreach ($ops as $key => $label): ?>
                    <tr>
                        <td><?= esc_html($label) ?></td>
                        <td><input type="number" class="small-text" data-key="<?= esc_attr($key) ?>"
                            value="<?= (int)($costs[$key] ?? 1) ?>" min="1" max="100"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <br>
            <button class="button button-primary" id="tbot-save-costs">💾 Guardar Costos</button>
            <span class="tbot-status" id="tbot-costs-status"></span>
        </div>

        <!-- ── Packs de Créditos ───────────────────────────────────── -->
        <div class="tbot-card">
            <h2>📦 Packs de Créditos</h2>
            <p class="description">Configura los packs que verán los usuarios en <code>/topup</code>.</p>
            <table class="widefat striped" id="tbot-packs-table">
                <thead><tr>
                    <th>ID</th><th>Nombre</th><th>Créditos</th>
                    <th>Precio USD</th><th>Stars ⭐</th><th>Link Stripe</th><th>Popular</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($packs as $i => $p): ?>
                <tr data-idx="<?= $i ?>">
                    <td><input type="text" class="small-text" value="<?= esc_attr($p['id']) ?>" data-field="id"></td>
                    <td><input type="text" class="regular-text" value="<?= esc_attr($p['name']) ?>" data-field="name" style="width: 100px;"></td>
                    <td><input type="number" class="small-text" value="<?= (int)$p['credits'] ?>" data-field="credits" min="1"></td>
                    <td><input type="number" class="small-text" value="<?= (float)$p['price_usd'] ?>" data-field="price_usd" step="0.01" min="0"></td>
                    <td><input type="number" class="small-text" value="<?= (int)$p['stars'] ?>" data-field="stars" min="1"></td>
                    <td><input type="url" class="regular-text" value="<?= esc_attr($p['stripe_link'] ?? '') ?>" data-field="stripe_link" placeholder="https://buy.stripe.com/..." style="width: 150px;"></td>
                    <td><input type="checkbox" <?= !empty($p['popular']) ? 'checked' : '' ?> data-field="popular"></td>
                    <td><button class="button tbot-remove-pack">✕</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <br>
            <button class="button" id="tbot-add-pack">➕ Añadir Pack</button>
            <button class="button button-primary" id="tbot-save-packs">💾 Guardar Packs</button>
            <span class="tbot-status" id="tbot-packs-status"></span>
        </div>

        <!-- ── Conceder Créditos Manuales ─────────────────────────── -->
        <div class="tbot-card">
            <h2>🎁 Conceder Créditos a Usuario</h2>
            <table class="form-table">
                <tr>
                    <th>Telegram ID o WP User ID</th>
                    <td><input type="text" id="tbot-grant-user" class="regular-text" placeholder="12345678"></td>
                </tr>
                <tr>
                    <th>Créditos</th>
                    <td><input type="number" id="tbot-grant-amount" class="small-text" value="100" min="1"></td>
                </tr>
                <tr>
                    <th>Nota</th>
                    <td><input type="text" id="tbot-grant-note" class="regular-text" placeholder="Ajuste manual"></td>
                </tr>
            </table>
            <button class="button button-primary" id="tbot-grant-btn">🎁 Conceder Créditos</button>
            <span class="tbot-status" id="tbot-grant-status"></span>
        </div>

        <!-- ── Últimas Transacciones ───────────────────────────────── -->
        <div class="tbot-card">
            <h2>📋 Últimas Transacciones</h2>
            <?php $this->render_transactions_table(); ?>
        </div>
        </div>

        <script>
        jQuery(function($){
            const nonce = '<?= wp_create_nonce('tbot_ajax_nonce') ?>';

            // Guardar costos
            $('#tbot-save-costs').on('click', function() {
                const costs = {};
                $('#tbot-cost-table input').each(function(){
                    costs[$(this).data('key')] = parseInt($(this).val()) || 1;
                });
                $.post(ajaxurl, {action:'tbot_save_credit_costs', nonce, costs: JSON.stringify(costs)},
                    r => $('#tbot-costs-status').text(r.data?.message || 'OK').css('color','green'));
            });

            // Guardar packs
            $('#tbot-save-packs').on('click', function() {
                const packs = [];
                $('#tbot-packs-table tbody tr').each(function(){
                    const row = $(this);
                    packs.push({
                        id:          row.find('[data-field=id]').val(),
                        name:        row.find('[data-field=name]').val(),
                        credits:     parseInt(row.find('[data-field=credits]').val()),
                        price_usd:   parseFloat(row.find('[data-field=price_usd]').val()),
                        stars:       parseInt(row.find('[data-field=stars]').val()),
                        stripe_link: row.find('[data-field=stripe_link]').val(),
                        popular:     row.find('[data-field=popular]').is(':checked'),
                    });
                });
                $.post(ajaxurl, {action:'tbot_save_credit_packs', nonce, packs: JSON.stringify(packs)},
                    r => $('#tbot-packs-status').text(r.data?.message || 'Guardado').css('color','green'));
            });

            // Añadir fila de pack
            $('#tbot-add-pack').on('click', function(){
                const idx = $('#tbot-packs-table tbody tr').length;
                $('#tbot-packs-table tbody').append(`<tr data-idx="${idx}">
                    <td><input type="text" class="small-text" value="pack_new" data-field="id"></td>
                    <td><input type="text" class="regular-text" value="Nuevo Pack" data-field="name" style="width: 100px;"></td>
                    <td><input type="number" class="small-text" value="1000" data-field="credits" min="1"></td>
                    <td><input type="number" class="small-text" value="5.00" data-field="price_usd" step="0.01"></td>
                    <td><input type="number" class="small-text" value="385" data-field="stars" min="1"></td>
                    <td><input type="url" class="regular-text" value="" data-field="stripe_link" placeholder="https://buy.stripe.com/..." style="width: 150px;"></td>
                    <td><input type="checkbox" data-field="popular"></td>
                    <td><button class="button tbot-remove-pack">✕</button></td>
                </tr>`);
            });
            $(document).on('click','.tbot-remove-pack', function(){ $(this).closest('tr').remove(); });

            // Conceder créditos
            $('#tbot-grant-btn').on('click', function(){
                $.post(ajaxurl, {
                    action:  'tbot_grant_credits',
                    nonce,
                    user_id: $('#tbot-grant-user').val(),
                    amount:  $('#tbot-grant-amount').val(),
                    note:    $('#tbot-grant-note').val(),
                }, r => $('#tbot-grant-status').text(r.data?.message || 'Error').css('color', r.success ? 'green' : 'red'));
            });
        });
        </script>
        <?php
    }

    private function render_transactions_table(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT t.*, u.display_name
             FROM {$wpdb->prefix}tbot_credit_transactions t
             LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
             ORDER BY t.created_at DESC LIMIT 50"
        );
        echo '<table class="widefat striped"><thead><tr>
            <th>ID</th><th>Usuario</th><th>Tipo</th><th>Cantidad</th><th>Nota</th><th>Fecha</th>
        </tr></thead><tbody>';
        foreach ($rows as $r) {
            $color = $r->amount > 0 ? 'color:green' : 'color:#c00';
            $sign  = $r->amount > 0 ? '+' : '';
            echo '<tr>
                <td>' . (int)$r->user_id . '</td>
                <td>' . esc_html($r->display_name ?? 'N/A') . '</td>
                <td><code>' . esc_html($r->type) . '</code></td>
                <td style="' . $color . ';font-weight:bold">' . $sign . (int)$r->amount . '</td>
                <td>' . esc_html($r->note ?? '') . '</td>
                <td>' . esc_html($r->created_at) . '</td>
            </tr>';
        }
        echo '</tbody></table>';
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

    public function ajax_save_packs(): void {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden']);
        $packs = json_decode(stripslashes($_POST['packs'] ?? '[]'), true);
        if (!is_array($packs)) wp_send_json_error(['message' => 'Datos inválidos']);
        update_option('tbot_credit_packs', json_encode($packs));
        wp_send_json_success(['message' => '✅ Packs guardados']);
    }

    public function ajax_save_costs(): void {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden']);
        $costs = json_decode(stripslashes($_POST['costs'] ?? '{}'), true);
        if (!is_array($costs)) wp_send_json_error(['message' => 'Datos inválidos']);
        $clean = [];
        foreach ($costs as $k => $v) {
            $clean[sanitize_key($k)] = max(1, (int)$v);
        }
        update_option('tbot_credit_costs', $clean);
        wp_send_json_success(['message' => '✅ Costos guardados']);
    }

    public function ajax_grant_credits(): void {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden']);

        $raw_id = sanitize_text_field($_POST['user_id'] ?? '');
        $amount = max(1, (int)($_POST['amount'] ?? 0));
        $note   = sanitize_text_field($_POST['note'] ?? 'Admin grant');

        // Resolver user_id (puede ser WP ID o Telegram ID)
        $user_id = 0;
        if (is_numeric($raw_id)) {
            $by_wp = get_user_by('id', (int)$raw_id);
            if ($by_wp) {
                $user_id = $by_wp->ID;
            } else {
                global $wpdb;
                $user_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='tbot_telegram_id' AND meta_value=%s",
                    $raw_id
                ));
            }
        }

        if (!$user_id) {
            wp_send_json_error(['message' => '❌ Usuario no encontrado']);
        }

        \TBot\Services\CreditManager::add($user_id, $amount, 'admin_grant', $note);
        $new_bal = \TBot\Services\CreditManager::get_balance($user_id);
        wp_send_json_success(['message' => "✅ +{$amount} créditos. Nuevo saldo: {$new_bal}"]);
    }
}
