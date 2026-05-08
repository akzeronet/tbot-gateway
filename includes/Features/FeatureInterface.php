<?php
namespace TBot\Features;

if (!defined('ABSPATH')) exit;

interface FeatureInterface {
    public function handle($user_id, $chat_id, $bot_token, $text, $language);
}
