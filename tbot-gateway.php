<?php
/**
 * Plugin Name: TBot Gateway
 * Description: Elite SaaS Telegram bot platform — AI assistant, credit economy, gamification, viral referrals, and admin analytics.
 * Version: 2.0.0
 * Author: Alexander Kings
 */

if (!defined('ABSPATH')) exit;

// Autoloader Simple para el namespace TBot
spl_autoload_register(function ($class) {
    $prefix = 'TBot\\';
    $base_dir = __DIR__ . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Iniciar el Plugin
function tbot_gateway_init() {
    \TBot\Gateway::instance();
}
add_action('plugins_loaded', 'tbot_gateway_init');

// Hook de Activación (Se debe pasar la ruta completa al archivo)
register_activation_hook(__FILE__, function() {
    \TBot\Gateway::instance()->activate();
});
