<?php
declare(strict_types=1);

namespace ScandTicket\Queue;

use ScandTicket\Core\Config;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;

final class ScanQueue
{
    public const DEFAULT_VISIBILITY_TIMEOUT = 120;

    public function push(array $payload): string|false
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return false;
        $envelope = JobEnvelope::create($payload);
        $pushed = $redis->lPush(RedisKeys::queuePending(), $envelope->toJson());
        return $pushed > 0 ? $envelope->id : false;
    }

    public function claim(string $workerId, int $timeout = 5): ?JobEnvelope
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return null;
        $this->promoteDelayed($redis);
        $json = $redis->brPop(RedisKeys::queuePending(), $timeout);
        if ($json === null) return null;
        $envelope = JobEnvelope::fromJson($json);
        $claimed = $envelope->claim($workerId);
        $redis->hSet(RedisKeys::queueProcessing(), $claimed->id, $claimed->toJson());
        return $claimed;
    }

    public function ack(string $jobId): bool
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return false;
        return $redis->hDel(RedisKeys::queueProcessing(), $jobId) > 0;
    }

    public function fail(JobEnvelope $envelope): void
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return;
        $redis->hDel(RedisKeys::queueProcessing(), $envelope->id);
        $maxRetries = (int) Config::get('queue_retry_max', 3);

        if ($envelope->attempts >= $maxRetries) {
            $dlqData = $envelope->payload;
            $dlqData['_dlq_meta'] = ['job_id' => $envelope->id, 'attempts' => $envelope->attempts, 'created_at' => $envelope->createdAt, 'failed_at' => time()];
            $redis->lPush(RedisKeys::queueDlq(), json_encode($dlqData, JSON_THROW_ON_ERROR));
            do_action('scandticket_job_dead_lettered', $envelope->payload, $envelope->id);
            return;
        }

        $baseDelay = (int) Config::get('queue_retry_delay', 5);
        $delay = $baseDelay * (int) pow(2, $envelope->attempts - 1);
        $retryAt = time() + $delay;
        $redis->zAdd(RedisKeys::queueDelayed(), (float) $retryAt, $envelope->forRetry()->toJson());
        do_action('scandticket_job_retry_scheduled', $envelope->id, $delay, $envelope->attempts);
    }

    public function extendVisibility(JobEnvelope $envelope): bool
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return false;
        $json = $redis->hGet(RedisKeys::queueProcessing(), $envelope->id);
        if ($json === null) return false;
        $current = JobEnvelope::fromJson($json);
        $extended = new JobEnvelope($current->id, $current->payload, $current->attempts, $current->createdAt, time(), $current->workerId);
        $redis->hSet(RedisKeys::queueProcessing(), $extended->id, $extended->toJson());
        return true;
    }

    private function promoteDelayed(RedisAdapter $redis): int
    {
        $delayedKey = $redis->key(RedisKeys::queueDelayed());
        $pendingKey = $redis->key(RedisKeys::queuePending());
        $now = (string) time();

        $lua = <<<'LUA'
local ready = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 20)
if #ready == 0 then return 0 end
for _, job in ipairs(ready) do
    redis.call('LPUSH', KEYS[2], job)
    redis.call('ZREM', KEYS[1], job)
end
return #ready
LUA;
        $promoted = $redis->eval($lua, [$delayedKey, $pendingKey], [$now], 2);
        if ($promoted === null) {
            $promoted = $this->promoteDelayedFallback($redis, $now);
        }
        return (int) $promoted;
    }

    private function promoteDelayedFallback(RedisAdapter $redis, string $now): int
    {
        $members = $redis->zRangeByScore(RedisKeys::queueDelayed(), '-inf', $now, 0, 20);
        if (empty($members)) return 0;
        $count = 0;
        foreach ($members as $json) {
            $redis->lPush(RedisKeys::queuePending(), $json);
            $redis->zRem(RedisKeys::queueDelayed(), $json);
            $count++;
        }
        return $count;
    }

    public function reap(): int
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return 0;
        $visibilityTimeout = (int) Config::get('queue_visibility_timeout', self::DEFAULT_VISIBILITY_TIMEOUT);
        $all = $redis->hGetAll(RedisKeys::queueProcessing());
        if (empty($all)) return 0;

        $recovered = 0;
        foreach ($all as $jobId => $json) {
            $envelope = JobEnvelope::fromJson($json);
            if (!$envelope->isExpired($visibilityTimeout)) continue;
            $workerAlive = $redis->exists(RedisKeys::workerHeartbeat($envelope->workerId));
            if ($workerAlive) continue;

            $redis->hDel(RedisKeys::queueProcessing(), $jobId);
            $maxRetries = (int) Config::get('queue_retry_max', 3);

            if ($envelope->attempts >= $maxRetries) {
                $dlqData = $envelope->payload;
                $dlqData['_dlq_meta'] = ['job_id' => $envelope->id, 'attempts' => $envelope->attempts, 'reason' => 'reaped_after_worker_death', 'reaped_at' => time()];
                $redis->lPush(RedisKeys::queueDlq(), json_encode($dlqData, JSON_THROW_ON_ERROR));
            } else {
                $redis->lPush(RedisKeys::queuePending(), $envelope->forRetry()->toJson());
            }
            $recovered++;
            do_action('scandticket_job_reaped', $jobId, $envelope->workerId, $envelope->attempts);
        }
        return $recovered;
    }

    public function depth(): int { $r = RedisAdapter::connection(); return $r ? $r->lLen(RedisKeys::queuePending()) : 0; }
    public function processingCount(): int { $r = RedisAdapter::connection(); return $r ? $r->hLen(RedisKeys::queueProcessing()) : 0; }
    public function delayedCount(): int { $r = RedisAdapter::connection(); return $r ? $r->zCard(RedisKeys::queueDelayed()) : 0; }
    public function dlqDepth(): int { $r = RedisAdapter::connection(); return $r ? $r->lLen(RedisKeys::queueDlq()) : 0; }

    public function stats(): array
    {
        return ['pending' => $this->depth(), 'processing' => $this->processingCount(), 'delayed' => $this->delayedCount(), 'dlq' => $this->dlqDepth()];
    }
}