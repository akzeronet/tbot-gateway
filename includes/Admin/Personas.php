<?php
namespace TBot\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Personas — Gestión de personalidades de IA disponibles para los usuarios.
 * Basado en el sistema de Personas del MenuController original.
 */
class Personas {

    public function __construct() {
        add_action('admin_post_tbot_save_personas', [$this, 'handle_save_personas']);
    }

    public function render() {
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Personalidades guardadas correctamente.</p></div>';
        }

        $personas = get_option('tbot_personas', $this->default_personas());
        if (!is_array($personas)) {
            $personas = json_decode($personas, true) ?: $this->default_personas();
        }
        ?>
        <div class="tbot-wrap">
            <?php Dashboard::render_header('Personalidades IA', 'Define las personalidades que los usuarios pueden elegir'); ?>

            <p class="tbot-muted" style="margin-bottom:20px;">
                Estas personalidades son las que aparecen en el menú <code>/settings → Personalidad</code> de tu bot. 
                Los usuarios Premium pueden crear una personalidad <strong>Custom</strong> propia.
            </p>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="tbot_save_personas">
                <?php wp_nonce_field('tbot_save_personas'); ?>

                <div class="tbot-personas-grid" id="personas-container">
                    <?php foreach ($personas as $idx => $persona): ?>
                        <?php $this->render_persona_card($idx, $persona); ?>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
                    <button type="button" class="tbot-btn tbot-btn-secondary" id="add-persona-btn">
                        ➕ Añadir Personalidad
                    </button>
                    <button type="submit" class="tbot-btn tbot-btn-primary">
                        💾 Guardar Personalidades
                    </button>
                </div>
            </form>

            <div class="tbot-card" style="margin-top:30px;">
                <h3 class="tbot-card-title">ℹ️ Personalidad Custom (Solo Premium)</h3>
                <p class="tbot-muted">
                    Los usuarios Premium pueden crear su propia personalidad usando el comando <code>/persona [su descripción]</code>. 
                    Por ejemplo: <em>"Eres un chef experto en comida caribeña."</em><br><br>
                    Esta personalidad se almacena en los metadatos del usuario y se envía como system prompt con cada mensaje a la IA.
                </p>
            </div>
        </div>

        <script>
        document.getElementById('add-persona-btn').addEventListener('click', function() {
            const container = document.getElementById('personas-container');
            const idx = container.querySelectorAll('.tbot-persona-card').length;
            const card = document.createElement('div');
            card.className = 'tbot-card tbot-persona-card';
            card.innerHTML = `
                <div class="tbot-form-row">
                    <label>Emoji / Icono</label>
                    <input type="text" name="personas[${idx}][icon]" class="tbot-input" value="🤖" maxlength="4" style="width:80px;">
                </div>
                <div class="tbot-form-row">
                    <label>Nombre (ID interno)</label>
                    <input type="text" name="personas[${idx}][key]" class="tbot-input" placeholder="mi_persona" required>
                </div>
                <div class="tbot-form-row">
                    <label>Nombre visible</label>
                    <input type="text" name="personas[${idx}][name]" class="tbot-input" placeholder="Mi Personalidad" required>
                </div>
                <div class="tbot-form-row">
                    <label>Prompt del sistema</label>
                    <textarea name="personas[${idx}][prompt]" class="tbot-input" rows="3" placeholder="Eres un experto en..." required></textarea>
                </div>
                <div class="tbot-form-row">
                    <label>¿Requiere Premium?</label>
                    <select name="personas[${idx}][premium]" class="tbot-input">
                        <option value="0">No — disponible para todos</option>
                        <option value="1">Sí — solo Premium</option>
                    </select>
                </div>
                <button type="button" class="tbot-btn tbot-btn-danger tbot-btn-sm remove-persona" style="margin-top:8px;">🗑 Eliminar</button>
            `;
            container.appendChild(card);
            card.querySelector('.remove-persona').addEventListener('click', () => card.remove());
        });

        document.querySelectorAll('.remove-persona').forEach(btn => {
            btn.addEventListener('click', () => btn.closest('.tbot-persona-card').remove());
        });
        </script>
        <?php
    }

    private function render_persona_card(int $idx, array $persona) {
        ?>
        <div class="tbot-card tbot-persona-card">
            <div class="tbot-persona-header">
                <span class="tbot-persona-icon-preview"><?php echo esc_html($persona['icon'] ?? '🤖'); ?></span>
                <strong><?php echo esc_html($persona['name'] ?? 'Personalidad'); ?></strong>
            </div>
            <div class="tbot-form-row">
                <label>Emoji / Icono</label>
                <input type="text" name="personas[<?php echo $idx; ?>][icon]" class="tbot-input persona-icon-input" 
                    value="<?php echo esc_attr($persona['icon'] ?? '🤖'); ?>" maxlength="4" style="width:80px;">
            </div>
            <div class="tbot-form-row">
                <label>ID Interno (sin espacios)</label>
                <input type="text" name="personas[<?php echo $idx; ?>][key]" class="tbot-input" 
                    value="<?php echo esc_attr($persona['key'] ?? ''); ?>" required>
            </div>
            <div class="tbot-form-row">
                <label>Nombre visible</label>
                <input type="text" name="personas[<?php echo $idx; ?>][name]" class="tbot-input" 
                    value="<?php echo esc_attr($persona['name'] ?? ''); ?>" required>
            </div>
            <div class="tbot-form-row">
                <label>Prompt del sistema</label>
                <textarea name="personas[<?php echo $idx; ?>][prompt]" class="tbot-input" rows="3" required><?php echo esc_textarea($persona['prompt'] ?? ''); ?></textarea>
            </div>
            <div class="tbot-form-row">
                <label>¿Requiere Premium?</label>
                <select name="personas[<?php echo $idx; ?>][premium]" class="tbot-input">
                    <option value="0" <?php selected(($persona['premium'] ?? 0), 0); ?>>No — disponible para todos</option>
                    <option value="1" <?php selected(($persona['premium'] ?? 0), 1); ?>>Sí — solo Premium</option>
                </select>
            </div>
            <button type="button" class="tbot-btn tbot-btn-danger tbot-btn-sm remove-persona" style="margin-top:8px;">🗑 Eliminar</button>
        </div>
        <?php
    }

    public function handle_save_personas() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tbot_save_personas');

        $raw = $_POST['personas'] ?? [];
        $personas = [];
        foreach ($raw as $p) {
            if (empty($p['key']) || empty($p['name']) || empty($p['prompt'])) continue;
            $personas[] = [
                'key'     => sanitize_key($p['key']),
                'icon'    => sanitize_text_field($p['icon'] ?? '🤖'),
                'name'    => sanitize_text_field($p['name']),
                'prompt'  => sanitize_textarea_field($p['prompt']),
                'premium' => (int)($p['premium'] ?? 0),
            ];
        }

        update_option('tbot_personas', $personas);
        \TBot\Services\Logger::log('admin', 'Personalidades de IA actualizadas. Total: ' . count($personas));

        wp_redirect(admin_url('admin.php?page=tbot-personas&saved=1'));
        exit;
    }

    private function default_personas(): array {
        return [
            ['key' => 'asistente', 'icon' => '🤖', 'name' => 'Asistente',   'prompt' => 'Eres un asistente útil y profesional.',                                                              'premium' => 0],
            ['key' => 'dev',       'icon' => '💻', 'name' => 'Programador', 'prompt' => 'Eres un experto programador senior. Respondes con código limpio y explicaciones técnicas precisas.', 'premium' => 0],
            ['key' => 'writer',    'icon' => '✍️', 'name' => 'Escritor',    'prompt' => 'Eres un escritor creativo experto en storytelling y redacción persuasiva.',                          'premium' => 0],
            ['key' => 'coach',     'icon' => '🧘', 'name' => 'Coach',       'prompt' => 'Eres un coach de vida y productividad. Tu tono es motivador pero disciplinado.',                     'premium' => 0],
        ];
    }
}
