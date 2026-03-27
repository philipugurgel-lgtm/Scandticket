<?php
declare(strict_types=1);

namespace ScandTicket\Security;

use ScandTicket\Core\Config;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;

final class NonceService
{
    public const BYTE_LENGTH = 16;
    public const NONCE_HEX_LENGTH = self::BYTE_LENGTH * 2;

    public function generate(): string
    {
        return bin2hex(random_bytes(self::BYTE_LENGTH));
    }

    public function validate(string $nonce): bool
    {
        $ttl = (int) Config::get('nonce_ttl', 300);

        $redis = RedisAdapter::connection();
        if ($redis !== null) {
            return $redis->setNxEx(RedisKeys::nonce($nonce), '1', $ttl);
        }

        return $this->validateViaMysql($nonce, $ttl);
    }

    public function release(string $nonce): void
    {
        $redis = RedisAdapter::connection();
        if ($redis !== null) {
            $redis->del(RedisKeys::nonce($nonce));
            return;
        }
        $this->releaseViaMysql($nonce);
    }

    private function validateViaMysql(string $nonce, int $ttl): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_nonces';
        $hash  = hash('sha256', $nonce);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);

        $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($table, [
            'nonce_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);
        $err = $wpdb->last_error;
        $wpdb->suppress_errors(false);

        if ($inserted === false) {
            if (str_contains($err, 'Duplicate entry')) {
                return false;
            }
            do_action('scandticket_nonce_db_error', $err, $nonce);
            return false;
        }

        return true;
    }

    private function releaseViaMysql(string $nonce): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_nonces';
        $wpdb->delete($table, ['nonce_hash' => hash('sha256', $nonce)]);
    }

    public function purgeExpired(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_nonces';
        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s", gmdate('Y-m-d H:i:s'))
        );
    }
}