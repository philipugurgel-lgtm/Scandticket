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
        $lua = <<<'LUA'
local old = redis.call('GET', KEYS[1])
redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
return old
LUA;
        $result = $redis->eval($lua, [$redis->key($key)], [$newValue, (string) $ttl], 1);
        if ($result !== null) return is_string($result) ? $result : null;
        $old = $redis->get($key);
        $redis->set($key, $newValue, $ttl);
        return $old;
    }
}