<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * TelegramService — Bot API 9.6 wrapper (Abril 2026).
 *
 * REGLA CRÍTICA: answerCallbackQuery debe llamarse ANTES de cualquier
 * otro procesamiento. Esta clase garantiza comunicación no bloqueante.
 */
class TelegramService {

    private string $token;
    private string $base_url;

    public function __construct(string $token = '') {
        $this->token    = $token ?: (string) get_option('tbot_master_token', '');
        $this->base_url = "https://api.telegram.org/bot{$this->token}/";
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CRÍTICO: Responder callback queries INMEDIATAMENTE (quitar spinner)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Responde a un callback_query. DEBE llamarse como primera acción.
     * Si no se llama en <10s el botón queda girando para siempre.
     *
     * @param string $id           callback_query.id
     * @param string $text         Texto toast (opcional, máx 200 chars)
     * @param bool   $show_alert   true = popup modal, false = toast rápido
     * @param string $url          URL a abrir (para juegos u otro uso)
     * @param int    $cache_time   Segundos de caché del resultado
     */
    public function answer_callback(
        string $id,
        string $text       = '',
        bool   $show_alert = false,
        string $url        = '',
        int    $cache_time = 0
    ): ?array {
        $body = ['callback_query_id' => $id];
        if ($text)       $body['text']       = mb_substr($text, 0, 200);
        if ($show_alert) $body['show_alert'] = true;
        if ($url)        $body['url']        = $url;
        if ($cache_time) $body['cache_time'] = $cache_time;
        return $this->post('answerCallbackQuery', $body);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MENSAJES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Envía un mensaje de texto.
     *
     * @param array $extra Parámetros extra: link_preview_options, reply_parameters, message_effect_id, etc.
     */
    public function send_message(
        string  $chat_id,
        string  $text,
        string  $parse_mode = 'HTML',
        ?array  $reply_markup = null,
        array   $extra = []
    ): ?array {
        $body = array_merge($extra, [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => $parse_mode,
        ]);
        if ($reply_markup !== null) {
            $body['reply_markup'] = json_encode($reply_markup);
        }
        return $this->post('sendMessage', $body);
    }

    /**
     * Edita el texto de un mensaje existente (evita spam en la UI).
     */
    public function edit_message_text(
        string $chat_id,
        int    $message_id,
        string $text,
        string $parse_mode = 'HTML',
        ?array $reply_markup = null
    ): ?array {
        $body = [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => $text,
            'parse_mode' => $parse_mode,
        ];
        if ($reply_markup !== null) {
            $body['reply_markup'] = json_encode($reply_markup);
        }
        return $this->post('editMessageText', $body);
    }

    /**
     * Edita solo el teclado inline de un mensaje (sin tocar el texto).
     */
    public function edit_message_reply_markup(
        string $chat_id,
        int    $message_id,
        ?array $reply_markup = null
    ): ?array {
        $body = ['chat_id' => $chat_id, 'message_id' => $message_id];
        if ($reply_markup !== null) {
            $body['reply_markup'] = json_encode($reply_markup);
        }
        return $this->post('editMessageReplyMarkup', $body);
    }

    /**
     * Borra un mensaje (útil para limpiar menús intermedios).
     */
    public function delete_message(string $chat_id, int $message_id): ?array {
        return $this->post('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
    }

    /**
     * Envía una imagen.
     */
    public function send_photo(
        string  $chat_id,
        string  $photo,
        string  $caption    = '',
        string  $parse_mode = 'HTML',
        ?array  $reply_markup = null
    ): ?array {
        $body = ['chat_id' => $chat_id, 'photo' => $photo];
        if ($caption)      $body['caption']    = $caption;
        if ($parse_mode)   $body['parse_mode'] = $parse_mode;
        if ($reply_markup) $body['reply_markup'] = json_encode($reply_markup);
        return $this->post('sendPhoto', $body);
    }

    /**
     * Envía un documento/archivo.
     */
    public function send_document(
        string $chat_id,
        string $document,
        string $caption = '',
        string $parse_mode = 'HTML'
    ): ?array {
        return $this->post('sendDocument', [
            'chat_id'    => $chat_id,
            'document'   => $document,
            'caption'    => $caption,
            'parse_mode' => $parse_mode,
        ]);
    }

    /**
     * sendChatAction — muestra "escribiendo...", "enviando foto...", etc.
     * Acciones: typing | upload_photo | record_video | upload_video |
     *           record_voice | upload_voice | upload_document |
     *           choose_sticker | find_location | record_video_note |
     *           upload_video_note
     */
    public function send_chat_action(string $chat_id, string $action = 'typing'): ?array {
        return $this->post('sendChatAction', ['chat_id' => $chat_id, 'action' => $action]);
    }

    /**
     * Reenvía un mensaje de un chat a otro.
     */
    public function forward_message(string $to_chat_id, string $from_chat_id, int $message_id): ?array {
        return $this->post('forwardMessage', [
            'chat_id'      => $to_chat_id,
            'from_chat_id' => $from_chat_id,
            'message_id'   => $message_id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PAGOS (Telegram Stars — Bot API 7.4+)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Envía una factura de pago con Telegram Stars (XTR).
     *
     * NOTA: Para XTR (Stars), provider_token DEBE ser string vacío "".
     *       title: máx 32 chars. description: máx 255 chars.
     *       prices: array de LabeledPrice objects.
     */
    public function send_invoice(
        string $chat_id,
        string $title,
        string $description,
        string $payload,
        array  $prices,
        string $currency          = 'XTR',
        string $provider_token    = '',
        ?array $reply_markup      = null,
        string $photo_url         = '',
        bool   $need_name         = false,
        bool   $need_email        = false,
        bool   $need_phone        = false,
        bool   $need_address      = false
    ): ?array {
        $body = [
            'chat_id'        => $chat_id,
            'title'          => mb_substr($title, 0, 32),
            'description'    => mb_substr($description, 0, 255),
            'payload'        => $payload,
            'currency'       => $currency,
            'prices'         => json_encode($prices),
            'provider_token' => $provider_token, // '' para Stars
        ];
        if ($photo_url)   $body['photo_url']   = $photo_url;
        if ($need_name)   $body['need_name']   = true;
        if ($need_email)  $body['need_email']  = true;
        if ($need_phone)  $body['need_phone_number'] = true;
        if ($need_address)$body['need_shipping_address'] = true;
        if ($reply_markup)$body['reply_markup'] = json_encode($reply_markup);
        return $this->post('sendInvoice', $body);
    }

    /**
     * Responde a un pre_checkout_query. DEBE enviarse en <10s.
     * ok=true aprueba el pago; ok=false lo rechaza con un mensaje de error.
     */
    public function answer_pre_checkout(
        string $pre_checkout_query_id,
        bool   $ok    = true,
        string $error = ''
    ): ?array {
        $body = [
            'pre_checkout_query_id' => $pre_checkout_query_id,
            'ok'                    => $ok,
        ];
        if (!$ok && $error) {
            $body['error_message'] = mb_substr($error, 0, 255);
        }
        return $this->post('answerPreCheckoutQuery', $body);
    }

    /**
     * Reembolsa un pago de Telegram Stars.
     */
    public function refund_star_payment(int $user_id, string $telegram_payment_charge_id): ?array {
        return $this->post('refundStarPayment', [
            'user_id'                       => $user_id,
            'telegram_payment_charge_id'    => $telegram_payment_charge_id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // INLINE QUERIES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Responde a una inline query (modo inline del bot).
     * results: array de InlineQueryResult objects (máx 50).
     */
    public function answer_inline_query(
        string $inline_query_id,
        array  $results,
        int    $cache_time = 300,
        bool   $is_personal = true
    ): ?array {
        return $this->post('answerInlineQuery', [
            'inline_query_id' => $inline_query_id,
            'results'         => json_encode($results),
            'cache_time'      => $cache_time,
            'is_personal'     => $is_personal,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONFIGURACIÓN DEL BOT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Establece el webhook de Telegram.
     * allowed_updates vacío = todos los tipos de update.
     * max_connections: 1-100 (default 40).
     */
    public function set_webhook(
        string $url,
        string $secret_token   = '',
        array  $allowed_updates = [],
        int    $max_connections = 40
    ): ?array {
        $body = ['url' => $url, 'max_connections' => $max_connections];
        if ($secret_token)    $body['secret_token']    = $secret_token;
        if ($allowed_updates) $body['allowed_updates'] = json_encode($allowed_updates);
        return $this->post('setWebhook', $body);
    }

    /** Elimina el webhook actual. */
    public function delete_webhook(bool $drop_pending = false): ?array {
        return $this->post('deleteWebhook', ['drop_pending_updates' => $drop_pending]);
    }

    /** Obtiene información del webhook actual. */
    public function get_webhook_info(): ?array {
        return $this->post('getWebhookInfo', []);
    }

    /**
     * Establece los comandos que aparecen en el menú "/" de Telegram.
     * commands: [['command' => 'start', 'description' => 'Inicio'], ...]
     * scope: null = todos | BotCommandScopeDefault | BotCommandScopeChat | etc.
     */
    public function set_my_commands(array $commands, ?array $scope = null, string $lang = ''): ?array {
        $body = ['commands' => json_encode($commands)];
        if ($scope) $body['scope']         = json_encode($scope);
        if ($lang)  $body['language_code'] = $lang;
        return $this->post('setMyCommands', $body);
    }

    /** Elimina todos los comandos del bot. */
    public function delete_my_commands(?array $scope = null, string $lang = ''): ?array {
        $body = [];
        if ($scope) $body['scope']         = json_encode($scope);
        if ($lang)  $body['language_code'] = $lang;
        return $this->post('deleteMyCommands', $body);
    }

    /** Obtiene información del bot. */
    public function get_me(): ?array {
        return $this->post('getMe', []);
    }

    /**
     * Establece la descripción del bot (visible en el perfil).
     * description: máx 512 chars.
     */
    public function set_my_description(string $description, string $lang = ''): ?array {
        $body = ['description' => mb_substr($description, 0, 512)];
        if ($lang) $body['language_code'] = $lang;
        return $this->post('setMyDescription', $body);
    }

    /**
     * Establece el texto corto que aparece en el chat vacío del bot.
     * short_description: máx 120 chars.
     */
    public function set_my_short_description(string $short_desc, string $lang = ''): ?array {
        $body = ['short_description' => mb_substr($short_desc, 0, 120)];
        if ($lang) $body['language_code'] = $lang;
        return $this->post('setMyShortDescription', $body);
    }

    /**
     * Establece el nombre del bot.
     * name: máx 64 chars.
     */
    public function set_my_name(string $name, string $lang = ''): ?array {
        $body = ['name' => mb_substr($name, 0, 64)];
        if ($lang) $body['language_code'] = $lang;
        return $this->post('setMyName', $body);
    }

    /**
     * Establece el menú de botones (Bottom Button) del bot.
     * menu_button: ReplyKeyboardMarkup JSON object.
     * chat_id null = default; chat_id int = para ese chat específico.
     */
    public function set_chat_menu_button(?int $chat_id = null, ?array $menu_button = null): ?array {
        $body = [];
        if ($chat_id)     $body['chat_id']     = $chat_id;
        if ($menu_button) $body['menu_button'] = json_encode($menu_button);
        return $this->post('setChatMenuButton', $body);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS DE TECLADO (factories)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Construye un InlineKeyboardMarkup.
     * $rows = [
     *   [['text'=>'Btn1','callback_data'=>'c1'], ['text'=>'Btn2','url'=>'https://...']],
     *   [['text'=>'Btn3','callback_data'=>'c3']],
     * ]
     */
    public static function inline_keyboard(array $rows): array {
        return ['inline_keyboard' => $rows];
    }

    /**
     * Construye un ReplyKeyboardMarkup (teclado permanente abajo del chat).
     * $rows = [
     *   [['text'=>'📍 Ubicación','request_location'=>true]],
     *   [['text'=>'📞 Contacto','request_contact'=>true]],
     *   [['text'=>'Menú Principal']],
     * ]
     */
    public static function reply_keyboard(
        array $rows,
        bool  $resize        = true,
        bool  $one_time      = false,
        bool  $persistent    = false,
        string $placeholder  = ''
    ): array {
        $kb = ['keyboard' => $rows, 'resize_keyboard' => $resize];
        if ($one_time)   $kb['one_time_keyboard'] = true;
        if ($persistent) $kb['is_persistent']     = true;
        if ($placeholder)$kb['input_field_placeholder'] = mb_substr($placeholder, 0, 64);
        return $kb;
    }

    /** Elimina el ReplyKeyboard del usuario. */
    public static function remove_keyboard(bool $selective = false): array {
        $kb = ['remove_keyboard' => true];
        if ($selective) $kb['selective'] = true;
        return $kb;
    }

    /** ForceReply — fuerza respuesta al mensaje del bot. */
    public static function force_reply(bool $selective = false, string $placeholder = ''): array {
        $fr = ['force_reply' => true];
        if ($selective)  $fr['selective']               = true;
        if ($placeholder)$fr['input_field_placeholder'] = mb_substr($placeholder, 0, 64);
        return $fr;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HTTP CORE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Ejecuta una llamada a la Telegram Bot API.
     * Usa JSON body y bloqueante (necesario para obtener message_id de respuesta).
     */
    private function post(string $method, array $body): ?array {
        if (empty($this->token)) {
            Logger::log('error', "TelegramService [{$method}]: Token vacío.");
            return null;
        }

        $response = wp_remote_post($this->base_url . $method, [
            'timeout'     => 10,
            'blocking'    => true,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            Logger::log('error', "TelegramService [{$method}] WP_Error: " . $response->get_error_message());
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($decoded['ok'])) {
            Logger::log('error', "TelegramService [{$method}] API Error: " . ($decoded['description'] ?? 'Unknown') . " (code: " . ($decoded['error_code'] ?? '?') . ")", $decoded);
        }

        return $decoded ?? null;
    }

    /**
     * Llamada no bloqueante (fire-and-forget).
     * Útil para enviar mensajes de IA donde no necesitamos el message_id.
     */
    public function post_async(string $method, array $body): void {
        if (empty($this->token)) return;

        wp_remote_post($this->base_url . $method, [
            'timeout'     => 0.01,  // No esperar respuesta
            'blocking'    => false,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => json_encode($body),
        ]);
    }
}
