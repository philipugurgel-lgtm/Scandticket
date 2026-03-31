<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Helpers;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;

final class RedisHelper
{
    public static function flush(): void
    {
        $redis = RedisAdapter::connection();
        $redis?->flushDb();
    }

    public static function nonceExists(string $nonce): bool
    {
        $redis = RedisAdapter::connection();
        return $redis !== null && $redis->exists(RedisKeys::nonce($nonce));
    }

    public static function idempotencyExists(string $key): bool
    {
        $redis = RedisAdapter::connection();
        return $redis !== null && $redis->exists(RedisKeys::idempotency($key));
    }

    public static function rateLimitCount(int $deviceId): int
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return 0;
        $key = RedisKeys::rateLimit($deviceId);
        $redis->zRemRangeByScore($key, '-inf', sprintf('%.6f', microtime(true) - 60));
        return $redis->zCard($key);
    }

    public static function rapidScanCount(int $deviceId): int
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return 0;
        $key = RedisKeys::fraudRapid($deviceId);
        $redis->zRemRangeByScore($key, '-inf', sprintf('%.6f', microtime(true) - 10));
        return $redis->zCard($key);
    }

    public static function simulateDown(): void { RedisAdapter::resetAll(); }
}