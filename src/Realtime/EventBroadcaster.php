<?php
declare(strict_types=1);

namespace ScandTicket\Realtime;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;

final class EventBroadcaster
{
    public function publish(string $eventType, array $data): bool
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return false;
        $payload = json_encode(['type' => $eventType, 'data' => $data, 'timestamp' => microtime(true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $published = 0;
        $eventId = $data['event_id'] ?? null;
        if ($eventId !== null) $published += $redis->publish(RedisKeys::eventChannel((int) $eventId), $payload);
        $published += $redis->publish(RedisKeys::globalChannel(), $payload);
        return $published > 0;
    }
}