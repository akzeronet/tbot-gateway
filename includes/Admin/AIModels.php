<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * AIModels — Configuración de modelos IA por plan.
 *
 * Permite al admin asignar qué modelo usa cada plan (free/standard/premium)
 * para texto básico, texto avanzado, visión y síntesis de voz.
 * Incluye costo estimado por 1K tokens para proyectar gastos.
 */
class AIModels {

    // Catálogo de modelos disponibles con metadata
    const MODELS = [
        // OpenAI
        'gpt-4o'              => ['label' => 'GPT-4o',              'provider' => 'openai', 'ctx' => 128,  'vision' => true,  'cost_1k' => 0.005],
        'gpt-4o-mini'         => ['label' => 'GPT-4o Mini',         'provider' => 'openai', 'ctx' => 128,  'vision' => true,  'cost_1k' => 0.00015],
        'gpt-4.1'             => ['label' => 'GPT-4.1',             'provider' => 'openai', 'ctx' => 1000, 'vision' => true,  'cost_1k' => 0.002],
        'gpt-4.1-mini'        => ['label' => 'GPT-4.1 Mini',        'provider' => 'openai', 'ctx' => 1000, 'vision' => true,  'cost_1k' => 0.0004],
        'gpt-4.1-nano'        => ['label' => 'GPT-4.1 Nano',        'provider' => 'openai', 'ctx' => 1000, 'vision' => false, 'cost_1k' => 0.0001],
        'o4-mini'             => ['label' => 'o4-mini (Reasoning)',  'provider' => 'openai', 'ctx' => 200,  'vision' => true,  'cost_1k' => 0.0011],
        // Anthropic
        'claude-3-5-haiku-20241022' => ['label' => 'Claude 3.5 Haiku',  'provider' => 'anthropic', 'ctx' => 200,  'vision' => true, 'cost_1k' => 0.0008],
        'claude-3-7-sonnet-20250219'=> ['label' => 'Claude 3.7 Sonnet', 'provider' => 'anthropic', 'ctx' => 200,  'vision' => true, 'cost_1k' => 0.003],
        // Google
        'gemini-2.0-flash'    => ['label' => 'Gemini 2.0 Flash',    'provider' => 'google', 'ctx' => 1000, 'vision' => true,  'cost_1k' => 0.0001],
        'gemini-2.5-pro'      => ['label' => 'Gemini 2.5 Pro',      'provider' => 'google', 'ctx' => 2000, 'vision' => true,  'cost_1k' => 0.00125],
        // Groq (ultra rápido)
        'llama-3.3-70b-versatile'   => ['label' => 'LLaMA 3.3 70B (Groq)',   'provider' => 'groq',   'ctx' => 128,  'vision' => false, 'cost_1k' => 0.0006],
        'deepseek-r1-distill-llama-70b' => ['label' => 'DeepSeek R1 (Groq)', 'provider' => 'groq',   'ctx' => 128,  'vision' => false, 'cost_1k' => 0.00075],
    ];

    const PROVIDER_COLORS = [
        'openai'    => '#10a37f',
        'anthropic' => '#d97706',
        'google'    => '#4285f4',
        'groq'      => '#7c3aed',
    ];

    public function __construct() {
        add_action('tbot_admin_submenu',        [$this, 'register_submenu'], 70);
        add_action('wp_ajax_tbot_save_ai_models', [$this, 'ajax_save']);
    }

    public function register_submenu(): void {
        add_submenu_page('tbot-gateway', 'Modelos IA', '🤖 Modelos IA', 'manage_options',
            'tbot-ai-models', [$this, 'render']);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;

        $cfg = $this->get_config();
        $plans = [
            'free'     => ['icon' => '🆓', 'label' => 'Free',     'color' => '#64748b'],
            'standard' => ['icon' => '⭐', 'label' => 'Standard', 'color' => '#2563eb'],
            'premium'  => ['icon' => '💎', 'label' => 'Premium',  'color' => '#7c3aed'],
        ];
        $ops = [
            'text_basic'    => ['label' => '💬 Texto Básico',     'desc' => 'Conversación estándar'],
            'text_advanced' => ['label' => '🧠 Texto Avanzado',   'desc' => 'Análisis, código, razonamiento'],
            'vision'        => ['label' => '📷 Visión (Fotos)',    'desc' => 'Análisis de imágenes'],
        ];
        ?>
        <div class="wrap tbot-admin">
        <h1>🤖 Modelos IA por Plan</h1>
        <p class="description">Asigna qué modelo de IA usa cada plan para cada tipo de operación. Los cambios se aplican inmediatamente.</p>

        <!-- Tarjetas de cobertura de modelos -->
        <div class="tbot-model-legend">
            <?php foreach (self::PROVIDER_COLORS as $prov => $color): ?>
            <span class="tbot-model-badge" style="background:<?= $color ?>20;color:<?= $color ?>;border:1px solid <?= $color ?>40">
                <?= ucfirst($prov) ?>
            </span>
            <?php endforeach; ?>
        </div>

        <form id="tbot-models-form">
        <div class="tbot-models-grid">
            <?php foreach ($plans as $plan_key => $plan): ?>
            <div class="tbot-card tbot-model-plan-card" style="--plan-accent:<?= $plan['color'] ?>">
                <div class="tbot-model-plan-header">
                    <span class="tbot-model-plan-icon"><?= $plan['icon'] ?></span>
                    <h2><?= $plan['label'] ?></h2>
                </div>

                <?php foreach ($ops as $op_key => $op): ?>
                <div class="tbot-form-row">
                    <label><?= $op['label'] ?> <small style="font-weight:400;text-transform:none;color:#94a3b8"><?= $op['desc'] ?></small></label>
                    <select class="tbot-input tbot-model-select"
                            name="models[<?= $plan_key ?>][<?= $op_key ?>]"
                            data-plan="<?= $plan_key ?>" data-op="<?= $op_key ?>">
                        <?php foreach (self::MODELS as $model_id => $meta):
                            if ($op_key === 'vision' && !$meta['vision']) continue;
                            $selected = ($cfg[$plan_key][$op_key] ?? '') === $model_id ? 'selected' : '';
                            $color = self::PROVIDER_COLORS[$meta['provider']] ?? '#000';
                        ?>
                        <option value="<?= esc_attr($model_id) ?>" <?= $selected ?>>
                            <?= esc_html($meta['label']) ?> — $<?= number_format($meta['cost_1k'], 5) ?>/1K tkns
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>

                <!-- Estimación de costo mensual -->
                <div class="tbot-model-cost-estimate" id="cost-<?= $plan_key ?>">
                    <span>💡 Costo estimado:</span>
                    <strong class="tbot-model-cost-value">calculando...</strong>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:20px">
            <button type="button" class="button button-primary" id="tbot-save-models" style="font-size:14px;height:40px;padding:0 24px">
                💾 Guardar Configuración
            </button>
            <span class="tbot-status" id="tbot-models-status"></span>
        </div>

        <!-- Tabla de referencia de modelos -->
        <div class="tbot-card" style="margin-top:24px">
            <h2>📊 Referencia de Modelos Disponibles</h2>
            <table class="widefat striped">
                <thead><tr>
                    <th>Modelo</th><th>Provider</th><th>Contexto</th>
                    <th>Visión</th><th>Costo /1K tokens</th>
                    <th>Costo en Créditos (Economía)</th>
                </tr></thead>
                <tbody>
                <?php foreach (self::MODELS as $id => $m):
                    $color = self::PROVIDER_COLORS[$m['provider']] ?? '#000';
                    $current_cost = self::get_model_cost($id);
                ?>
                <tr>
                    <td><code><?= esc_html($id) ?></code></td>
                    <td><span style="color:<?= $color ?>;font-weight:600"><?= ucfirst($m['provider']) ?></span></td>
                    <td><?= $m['ctx'] ?>K tokens</td>
                    <td><?= $m['vision'] ? '✅' : '—' ?></td>
                    <td>$<?= number_format($m['cost_1k'], 5) ?></td>
                    <td>
                        <input type="number" name="model_costs[<?= esc_attr($id) ?>]" value="<?= esc_attr($current_cost) ?>" min="1" step="1" style="width:80px;height:28px;font-size:13px;" class="tbot-input" />
                        <span style="font-size:11px;color:#64748b;margin-left:4px;">créditos</span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        </div>
        </form>

        </div><!-- .wrap -->

        <script>
        jQuery(function($){
            const nonce  = '<?= wp_create_nonce('tbot_ajax_nonce') ?>';
            const costs  = <?= json_encode(array_map(fn($m) => $m['cost_1k'], self::MODELS)) ?>;
            const models = <?= json_encode(array_keys(self::MODELS)) ?>;

            function updateCostEstimate(plan) {
                const selects = $(`[data-plan="${plan}"]`);
                let totalCost = 0;
                selects.each(function(){
                    const idx = models.indexOf($(this).val());
                    if (idx >= 0) totalCost += (Object.values(costs)[idx] || 0);
                });
                // Estimación basada en 10K mensajes/mes, 500 tokens promedio
                const monthly = (totalCost * 10000 * 0.5).toFixed(2);
                $(`#cost-${plan} .tbot-model-cost-value`).text(`~$${monthly}/mes (10K msgs)`);
            }

            // Calcular al cargar
            ['free','standard','premium'].forEach(updateCostEstimate);
            $('.tbot-model-select').on('change', function(){
                updateCostEstimate($(this).data('plan'));
            });

            // Guardar
            $('#tbot-save-models').on('click', function(){
                const cfg = {};
                $('select[name^="models["]').each(function(){
                    const plan = $(this).data('plan');
                    const op   = $(this).data('op');
                    if (!cfg[plan]) cfg[plan] = {};
                    cfg[plan][op] = $(this).val();
                });

                const modelCosts = {};
                $('input[name^="model_costs["]').each(function(){
                    const name = $(this).attr('name');
                    const key = name.substring(12, name.length - 1);
                    modelCosts[key] = parseInt($(this).val(), 10);
                });

                $.post(ajaxurl, {
                    action:'tbot_save_ai_models', 
                    nonce, 
                    config: JSON.stringify(cfg),
                    model_costs: JSON.stringify(modelCosts)
                }, function(r){
                        const s = $('#tbot-models-status').addClass('visible');
                        s.text(r.success ? '✅ Guardado' : '❌ Error').css('color', r.success ? '#10b981' : '#ef4444');
                        setTimeout(() => s.removeClass('visible'), 3000);
                    });
            });
        });
        </script>

        <style>
        .tbot-models-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
        @media(max-width:1100px){ .tbot-models-grid { grid-template-columns:1fr 1fr; } }
        @media(max-width:700px) { .tbot-models-grid { grid-template-columns:1fr; } }
        .tbot-model-plan-card { border-top: 4px solid var(--plan-accent); }
        .tbot-model-plan-header { display:flex; align-items:center; gap:10px; margin-bottom:18px; }
        .tbot-model-plan-icon { font-size:24px; }
        .tbot-model-plan-header h2 { margin:0 !important; font-size:18px !important; border:none !important; color:var(--plan-accent) !important; }
        .tbot-model-cost-estimate {
            margin-top:16px; padding:10px 14px;
            background:#f8fafc; border-radius:8px;
            border:1px solid #e2e8f0;
            font-size:12px; color:#64748b;
            display:flex; justify-content:space-between; align-items:center;
        }
        .tbot-model-cost-value { color:#0f172a; font-size:13px; }
        .tbot-model-legend { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
        .tbot-model-badge { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
        </style>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function ajax_save(): void {
        check_ajax_referer('tbot_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $config = json_decode(stripslashes($_POST['config'] ?? '{}'), true);
        if (!is_array($config)) wp_send_json_error(['message' => 'Datos inválidos']);

        $model_costs = json_decode(stripslashes($_POST['model_costs'] ?? '{}'), true);
        if (is_array($model_costs)) {
            $clean_costs = [];
            foreach ($model_costs as $key => $cost) {
                if (is_numeric($cost)) {
                    $clean_costs[$key] = max(1, (int) $cost);
                }
            }
            update_option('tbot_model_costs', json_encode($clean_costs));
        }

        $allowed_plans = ['free', 'standard', 'premium'];
        $allowed_ops   = ['text_basic', 'text_advanced', 'vision'];
        $allowed_models= array_keys(self::MODELS);

        $clean = [];
        foreach ($allowed_plans as $plan) {
            foreach ($allowed_ops as $op) {
                $val = $config[$plan][$op] ?? '';
                if (in_array($val, $allowed_models, true)) {
                    $clean[$plan][$op] = $val;
                    // Sincronizar con options legacy usadas en WebhookHandler
                    if ($op === 'text_basic' || $op === 'text_advanced') {
                        $opt_key = "tbot_model_{$plan}";
                        update_option($opt_key, $val);
                    }
                }
            }
        }

        update_option('tbot_ai_model_config', json_encode($clean));
        wp_send_json_success(['message' => 'Configuración guardada']);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    public static function get_config(): array {
        $raw = get_option('tbot_ai_model_config', '');
        $cfg = $raw ? json_decode($raw, true) : [];
        // Defaults seguros
        $defaults = [
            'free'     => ['text_basic' => 'gpt-4o-mini', 'text_advanced' => 'gpt-4o-mini', 'vision' => 'gpt-4o-mini'],
            'standard' => ['text_basic' => 'gpt-4o-mini', 'text_advanced' => 'gpt-4o',      'vision' => 'gpt-4o'],
            'premium'  => ['text_basic' => 'gpt-4o',      'text_advanced' => 'gpt-4o',      'vision' => 'gpt-4o'],
        ];
        return array_replace_recursive($defaults, is_array($cfg) ? $cfg : []);
    }

    public static function get_model_cost(string $key): int {
        $raw = get_option('tbot_model_costs', '');
        $costs = $raw ? json_decode($raw, true) : [];

        if (isset($costs[$key]) && is_numeric($costs[$key])) {
            return max(1, (int) $costs[$key]);
        }

        // Defaults for non-models
        if ($key === 'voice') return 3;
        if ($key === 'image_gen') return 20;

        // Defaults for known models
        if (str_contains($key, 'mini') || str_contains($key, 'flash') || str_contains($key, 'haiku')) {
            return 1;
        }
        if (str_contains($key, 'pro') || str_contains($key, 'sonnet') || str_contains($key, 'gpt-4o')) {
            return 3;
        }
        return 1;
    }
}
