<?php
namespace TBot\Features\Tier1;

use TBot\Features\FeatureInterface;

if (!defined('ABSPATH')) exit;

class FileConverter implements FeatureInterface {
    public function handle($user_id, $chat_id, $bot_token, $text, $language) {
        $msg = $this->get_message($language);
        
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'Markdown'
            ]
        ]);

        return true;
    }

    private function get_message($language) {
        return match ($language) {
            'en' => "🔄 *File Converter*\n\nTo convert a file, please reply to a file with the command `/convert <format>`.\n\n_Example: Reply to a .webp image with `/convert png`_",
            'pt' => "🔄 *Conversor de Arquivos*\n\nPara converter, responda a um arquivo com o comando `/convert <formato>`.\n\n_Exemplo: Responda a uma imagen .webp com `/convert png`_",
            default => "🔄 *Conversor de Archivos*\n\nPara convertir un archivo, responde a un archivo con el comando `/convert <formato>`.\n\n_Ejemplo: Responde a una imagen .webp con `/convert png`_",
        };
    }
}
