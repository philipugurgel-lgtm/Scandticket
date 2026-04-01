<?php
declare(strict_types=1);

namespace ScandTicket\Core;

final class Config
{
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = [
                'hmac_secret'              => defined('SCANDTICKET_HMAC_SECRET') ? SCANDTICKET_HMAC_SECRET : get_option('scandticket_hmac_secret', ''),
                'token_ttl'                => (int) get_option('scandticket_token_ttl', 86400 * 30),
                'rate_limit_per_min'       => (int) get_option('scandticket_rate_limit', 120),
                'queue_retry_max'          => (int) get_option('scandticket_queue_retry_max', 3),
                'queue_retry_delay'        => (int) get_option('scandticket_queue_retry_delay', 5),
                'queue_visibility_timeout' => (int) get_option('scandticket_queue_visibility_timeout', 120),
                'fraud_threshold'          => (float) get_option('scandticket_fraud_threshold', 0.7),
                'redis_host'               => defined('SCANDTICKET_REDIS_HOST') ? SCANDTICKET_REDIS_HOST : '127.0.0.1',
                'redis_port'               => defined('SCANDTICKET_REDIS_PORT') ? SCANDTICKET_REDIS_PORT : 6379,
                'redis_password'           => defined('SCANDTICKET_REDIS_PASSWORD') ? SCANDTICKET_REDIS_PASSWORD : null,
                'redis_database'           => defined('SCANDTICKET_REDIS_DATABASE') ? SCANDTICKET_REDIS_DATABASE : 0,
                'redis_prefix'             => 'scandticket:',
                'ws_port'                  => (int) get_option('scandticket_ws_port', 8090),
                'webhook_timeout'          => 10,
                'webhook_retry_max'        => 3,
                'nonce_ttl'                => (int) get_option('scandticket_nonce_ttl', 300),
                'timestamp_max_drift'      => (int) get_option('scandticket_timestamp_max_drift', 120),
                'batch_max_size'           => 50,
                'metrics_flush_interval'   => 60,
                'auth_bruteforce_window'   => (int) get_option('scandticket_auth_bf_window', 900),
                'auth_bruteforce_limit'    => (int) get_option('scandticket_auth_bf_limit', 20),
            ];
        }

        return self::$cache[$key] ?? $default;
    }

    /**
     * Flush the config cache — call after saving options so the next
     * Config::get() reads fresh values from the database.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}