<?php
namespace TBot\Services;

if (!defined('ABSPATH')) exit;

/**
 * RedisMemory — Capa L0 (Ultra-Hot Cache)
 * 
 * Implementa el patrón Write-Through para guardar los últimos 100 mensajes
 * de un usuario en memoria RAM (Redis) por un máximo de 3 días.
 * Si Redis no está disponible, el sistema falla silenciosamente hacia MySQL.
 */
class RedisMemory {

    const PREFIX = 'tbot_chat_';
    const TTL_SECONDS = 259200; // 3 días
    const MAX_MESSAGES = 100;

    private static ?\Redis $client = null;
    private static bool $connection_attempted = false;

    /**
     * Obtiene y mantiene la conexión a Redis.
     */
    private static function get_client(): ?\Redis {
        if (self::$connection_attempted) return self::$client;
        self::$connection_attempted = true;

        if (!class_exists('Redis')) {
            Logger::log('storage', 'Redis extension not installed. L0 cache disabled.');
            return null;
        }

        $host = get_option('tbot_redis_host', '127.0.0.1');
        $port = (int) get_option('tbot_redis_port', 6379);
        $pass = get_option('tbot_redis_password', '');

        try {
            $redis = new \Redis();
            // Timeout corto (100ms) para no bloquear la ejecución si Redis está caído
            if (@$redis->connect($host, $port, 0.1)) {
                if ($pass && !$redis->auth($pass)) {
                    Logger::log('error', 'Redis authentication failed.');
                    return null;
                }
                self::$client = $redis;
            }
        } catch (\Exception $e) {
            Logger::log('error', 'Redis connection failed: ' . $e->getMessage());
        }

        return self::$client;
    }

    /**
     * Comprueba si la capa L0 está operativa.
     */
    public static function is_available(): bool {
        return self::get_client() !== null;
    }

    /**
     * Añade un mensaje al historial en Redis.
     * Mantiene un máximo de MAX_MESSAGES y reinicia el TTL de 3 días.
     */
    public static function push_message(int $user_id, array $message): void {
        $redis = self::get_client();
        if (!$redis) return;

        $key = self::PREFIX . $user_id;
        $payload = json_encode($message);

        try {
            // Añadir al inicio de la lista (más reciente primero)
            $redis->lPush($key, $payload);
            // Mantener solo los últimos MAX_MESSAGES
            $redis->lTrim($key, 0, self::MAX_MESSAGES - 1);
            // Reiniciar el TTL a 3 días
            $redis->expire($key, self::TTL_SECONDS);
        } catch (\Exception $e) {
            Logger::log('error', 'Redis push failed: ' . $e->getMessage());
        }
    }

    /**
     * Devuelve los últimos N mensajes de un usuario desde Redis.
     * Retorna array vacío si Redis falla o la llave no existe.
     * 
     * @return array<array{role: string, content: string}>
     */
    public static function get_messages(int $user_id, int $limit): array {
        $redis = self::get_client();
        if (!$redis) return [];

        $key = self::PREFIX . $user_id;

        try {
            if (!$redis->exists($key)) return [];

            // Obtener de 0 a limit-1 (Redis lRange es inclusivo)
            $raw_messages = $redis->lRange($key, 0, $limit - 1);
            
            $messages = [];
            foreach ($raw_messages as $raw) {
                $decoded = json_decode($raw, true);
                if ($decoded) {
                    $messages[] = $decoded;
                }
            }

            // Los mensajes se guardan con lPush (más reciente al principio de la lista).
            // Para el LLM, necesitamos el orden cronológico (más antiguo primero), 
            // así que invertimos el array.
            return array_reverse($messages);

        } catch (\Exception $e) {
            Logger::log('error', 'Redis get failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Repuebla la memoria de Redis desde MySQL.
     * Útil si Redis se vació pero el usuario sigue teniendo historial caliente.
     */
    public static function populate_from_mysql(int $user_id, array $messages): void {
        $redis = self::get_client();
        if (!$redis || empty($messages)) return;

        $key = self::PREFIX . $user_id;

        try {
            // Limpiar lista actual
            $redis->del($key);

            // $messages asume orden cronológico (más antiguo primero).
            // Necesitamos meterlos en Redis (lPush) de forma que el más reciente quede en índice 0.
            foreach ($messages as $msg) {
                // lPush empuja al principio, así que si iteramos cronológicamente,
                // el último mensaje insertado será el más reciente (índice 0).
                $redis->lPush($key, json_encode([
                    'role'    => $msg['role'],
                    'content' => $msg['content']
                ]));
            }

            $redis->lTrim($key, 0, self::MAX_MESSAGES - 1);
            $redis->expire($key, self::TTL_SECONDS);

        } catch (\Exception $e) {
            Logger::log('error', 'Redis populate failed: ' . $e->getMessage());
        }
    }

    /**
     * Borra la memoria de un usuario en Redis (GDPR/Clear).
     */
    public static function clear(int $user_id): void {
        $redis = self::get_client();
        if ($redis) {
            try {
                $redis->del(self::PREFIX . $user_id);
            } catch (\Exception $e) {}
        }
    }
}
