<?php
namespace TBot\Admin;

use TBot\Services\ToolRegistry;

if (!defined('ABSPATH')) exit;

class AITools {

    public function __construct() {
        add_action('tbot_admin_submenu', [$this, 'register_submenu'], 40);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_submenu(): void {
        add_submenu_page('tbot-gateway', 'Herramientas IA', '🛠️ Herramientas', 'manage_options',
            'tbot-tools', [$this, 'render']);
    }

    public function register_settings(): void {
        $tools = ToolRegistry::get_available_tools();
        
        // Registrar opción por cada herramienta dinámicamente
        foreach ($tools as $id => $tool) {
            register_setting('tbot_tools_group', 'tbot_tool_' . $id);
        }
        
        // Configuraciones globales de tools
        register_setting('tbot_tools_group', 'tbot_serper_api_key');
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;

        if (isset($_GET['settings-updated'])) {
            add_settings_error('tbot_messages', 'tbot_message', 'Herramientas actualizadas.', 'updated');
        }
        settings_errors('tbot_messages');
        
        $tools = ToolRegistry::get_available_tools();
        ?>
        <div class="wrap tbot-admin">
            <div class="tbot-header">
                <div class="tbot-header-inner">
                    <div class="tbot-logo-icon">🛠️</div>
                    <div>
                        <h1 class="tbot-title">Herramientas de IA (Function Calling)</h1>
                        <p class="tbot-subtitle">El Ecosistema es Extensible: Nuevas herramientas se añadirán aquí automáticamente.</p>
                    </div>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('tbot_tools_group'); ?>
                
                <div class="tbot-grid-2">
                    <?php foreach ($tools as $id => $tool): 
                        $option_name = 'tbot_tool_' . $id;
                        $color = $tool['color'] ?? '#64748b';
                    ?>
                        <div class="tbot-card" style="border-top: 4px solid <?php echo esc_attr($color); ?>;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <h3 style="margin:0; font-size:16px; display:flex; align-items:center; gap:8px;">
                                    <?php echo esc_html($tool['title']); ?>
                                </h3>
                                <label class="tbot-switch">
                                    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>" value="1" <?php checked(1, get_option($option_name), true); ?> />
                                    <span class="tbot-slider"></span>
                                </label>
                            </div>
                            <p class="tbot-muted"><?php echo esc_html($tool['desc']); ?></p>
                            
                            <?php 
                            // Custom UI additions based on tool ID
                            if ($id === 'google_search'): ?>
                                <div style="margin-top: 16px; padding-top:16px; border-top:1px solid #e2e8f0;">
                                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:6px;">SerpApi Key (Opcional pero recomendado)</label>
                                    <input type="password" name="tbot_serper_api_key" value="<?php echo esc_attr(get_option('tbot_serper_api_key')); ?>" class="tbot-input" style="width:100%;" placeholder="sk-..." />
                                    <p style="font-size:11px; color:#94a3b8; margin-top:4px;">Si lo dejas en blanco, intentará usar DuckDuckGo gratuito (menos estable).</p>
                                </div>
                            <?php endif; ?>
                            
                            <?php do_action('tbot_admin_tool_settings_' . $id); ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:24px;">
                    <?php submit_button('💾 Guardar Ecosistema', 'primary', 'submit', false, ['class' => 'tbot-btn tbot-btn-primary']); ?>
                </div>
            </form>

            <style>
                /* Switch Toggle UI */
                .tbot-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
                .tbot-switch input { opacity: 0; width: 0; height: 0; }
                .tbot-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 24px; }
                .tbot-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                input:checked + .tbot-slider { background-color: #10b981; }
                input:checked + .tbot-slider:before { transform: translateX(20px); }
            </style>
        </div>
        <?php
    }
}
