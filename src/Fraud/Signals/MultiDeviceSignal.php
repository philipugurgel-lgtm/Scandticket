<?php
declare(strict_types=1);

namespace ScandTicket\Fraud\Signals;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;
use ScandTicket\Fraud\FraudSignal;

final class MultiDeviceSignal
{
    private const TTL = 3600;

    public function evaluate(int $ticketId, int $eventId, int $deviceId): FraudSignal
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return new FraudSignal('multi_device', 0.0, 'Redis unavailable');

        $key = RedisKeys::fraudTicketDevice($ticketId, $eventId);
        $deviceStr = (string) $deviceId;
        $previousDevice = $this->atomicSwap($redis, $key, $deviceStr, self::TTL);

        if ($previousDevice === null) return new FraudSignal('multi_device', 0.0);
        if ($previousDevice === $deviceStr) return new FraudSignal('multi_device', 0.0);
        return new FraudSignal('multi_device', 1.0, sprintf('Ticket %d scanned on device %s, previously on device %s', $ticketId, $deviceStr, $previousDevice));
    }

    private function atomicSwap(RedisAdapter $redis, string $key, string $newValue, int $ttl): ?string
    {
        // Return a sentinel instead of nil so callers can distinguish three states:
        //   '__nil__'   → Lua ran successfully, key was absent (first scan)
        //   string      → Lua ran successfully, previous device ID returned
        //   null        → Lua eval entirely failed (old Redis, eval disabled, etc.)
        //
        // Without the sentinel, phpredis maps Lua nil → false and Predis maps it → null,
        // making "no previous value" indistinguishable from "eval failed" on Predis,
        // which caused the old non-atomic GET+SET fallback to fire on every first scan.
        // The non-atomic fallback had a race: two concurrent requests could both GET
        // null and both score 0.0, silently missing a multi-device fraud signal.
        $lua = <<<'LUA'
local old = redis.call('GET', KEYS[1])
redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
return old or '__nil__'
LUA;
        $result = $redis->eval($lua, [$redis->key($key)], [$newValue, (string) $ttl], 1);

        if ($result === '__nil__') {
            return null; // key was absent — no previous device
        }

        if (is_string($result)) {
            return $result; // previous device ID
        }

        // Lua eval failed entirely. The non-atomic GET+SET alternative is unsafe
        // under concurrency, so we degrade gracefully: return null (score 0.0)
        // rather than risk a race that corrupts the stored device or misses fraud.
        return null;
    }
}