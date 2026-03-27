<?php
declare(strict_types=1);

namespace ScandTicket\Idempotency;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;

final class IdempotencyGuard
{
    private const TTL = 3600;

    public function generateKey(int $ticketId, int $eventId, int $deviceId): string
    {
        return hash('sha256', "{$ticketId}:{$eventId}:{$deviceId}:" . gmdate('Y-m-d'));
    }

    public function acquire(string $key): bool
    {
        $redis = RedisAdapter::connection();
        if ($redis !== null) return $redis->setNxEx(RedisKeys::idempotency($key), (string) time(), self::TTL);
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_checkins';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE idempotency_key = %s", $key)) === 0;
    }

    public function release(string $key): void
    {
        $redis = RedisAdapter::connection();
        $redis?->del(RedisKeys::idempotency($key));
    }
}