<?php
declare(strict_types=1);

namespace ScandTicket\RateLimit;

use ScandTicket\Core\Config;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;
use WP_Error;

final class RateLimiter
{
    public function check(int $deviceId): true|WP_Error
    {
        $redis = RedisAdapter::connection();
        $limit = (int) Config::get('rate_limit_per_min', 120);
        if ($redis === null) return true;

        $key = RedisKeys::rateLimit($deviceId);
        $now = microtime(true);
        $redis->zRemRangeByScore($key, '-inf', sprintf('%.6f', $now - 60.0));
        $count = $redis->zCard($key);

        if ($count >= $limit) {
            $oldest = $redis->zRange($key, 0, 0);
            $retryAfter = !empty($oldest) ? max(1, (int)(60.0 - ($now - (float) $oldest[0]))) : 1;
            return new WP_Error('rate_limited', 'Rate limit exceeded.', ['status' => 429, 'retry_after' => $retryAfter, 'limit' => $limit, 'current' => $count]);
        }

        $member = sprintf('%.6f:%s', $now, bin2hex(random_bytes(4)));
        $redis->zAdd($key, $now, $member);
        $redis->expire($key, 120);
        return true;
    }

    public function getCurrentCount(int $deviceId): int
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return 0;
        $key = RedisKeys::rateLimit($deviceId);
        $redis->zRemRangeByScore($key, '-inf', sprintf('%.6f', microtime(true) - 60.0));
        return $redis->zCard($key);
    }
}