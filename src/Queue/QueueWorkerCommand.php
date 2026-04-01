<?php
declare(strict_types=1);

namespace ScandTicket\Queue;

use ScandTicket\Core\Container;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;
use ScandTicket\Scanner\ScanWorker;
use WP_CLI;

final class QueueWorkerCommand
{
    private bool $stopping = false;
    private string $workerId;
    private bool $heartbeatOk = false;

    public function __invoke(array $args, array $assoc): void
    {
        $maxJobs  = (int) ($assoc['max-jobs'] ?? 0);
        $timeout  = (int) ($assoc['timeout'] ?? 5);
        $sleepMs  = (int) ($assoc['sleep'] ?? 50);
        $memoryMb = (int) ($assoc['memory'] ?? 128);
        $this->workerId = bin2hex(random_bytes(8));

        $this->registerSignals();

        $c = Container::instance();
        $queue  = $c->make(ScanQueue::class);
        $worker = $c->make(ScanWorker::class);
        $processed = 0; $failed = 0;

        WP_CLI::log(sprintf('[worker:%s] Started. memory_limit=%dMB max_jobs=%s', $this->workerId, $memoryMb, $maxJobs > 0 ? $maxJobs : 'unlimited'));

        $reaped = $queue->reap();
        if ($reaped > 0) WP_CLI::log("[worker:{$this->workerId}] Recovered {$reaped} orphaned jobs.");

        while (!$this->stopping) {
            $this->dispatchSignals();
            $this->sendHeartbeat();

            $usedMb = memory_get_usage(true) / 1024 / 1024;
            if ($usedMb >= $memoryMb) { WP_CLI::warning("[worker:{$this->workerId}] Memory limit reached ({$usedMb}MB). Exiting."); break; }

            // If the heartbeat cannot be written, the reaper cannot distinguish
            // this worker from a dead one and will re-queue any jobs we claim,
            // causing double-processing when Redis recovers. Pause until we can
            // confirm our liveness via a successful heartbeat write.
            if (!$this->heartbeatOk) {
                WP_CLI::warning("[worker:{$this->workerId}] Heartbeat write failed — Redis unavailable. Pausing job claims.");
                sleep(2);
                continue;
            }

            $envelope = $queue->claim($this->workerId, $timeout);
            if ($envelope === null) continue;

            $ticketId = $envelope->payload['ticket_id'] ?? '?';
            $eventId  = $envelope->payload['event_id'] ?? '?';
            $success = false;
            try { $success = $worker->processJob($envelope->payload); } catch (\Throwable $e) { WP_CLI::warning("[worker:{$this->workerId}] Exception: {$e->getMessage()}"); }

            if ($success) { $queue->ack($envelope->id); $processed++; WP_CLI::log("[worker:{$this->workerId}] OK ticket={$ticketId} event={$eventId} (#{$processed})"); }
            else { $queue->fail($envelope); $failed++; WP_CLI::warning("[worker:{$this->workerId}] FAIL ticket={$ticketId} event={$eventId}"); }

            if ($maxJobs > 0 && ($processed + $failed) >= $maxJobs) break;
            if ($sleepMs > 0) usleep($sleepMs * 1000);
        }

        $this->removeHeartbeat();
        WP_CLI::success("[worker:{$this->workerId}] Exited. processed={$processed} failed={$failed}");
    }

    private function registerSignals(): void
    {
        if (!function_exists('pcntl_signal')) { WP_CLI::warning('[worker] pcntl not available.'); return; }
        $handler = function (int $sig): void { WP_CLI::log("[worker:{$this->workerId}] Signal received. Finishing current job..."); $this->stopping = true; };
        pcntl_signal(SIGTERM, $handler); pcntl_signal(SIGINT, $handler); pcntl_signal(SIGHUP, $handler);
        pcntl_async_signals(true);
    }

    private function dispatchSignals(): void { if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch(); }

    private function sendHeartbeat(): void
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) {
            $this->heartbeatOk = false;
            return;
        }
        $written = $redis->set(
            RedisKeys::workerHeartbeat($this->workerId),
            json_encode(['pid' => getmypid(), 'started_at' => time(), 'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1)]),
            30,
        );
        $this->heartbeatOk = $written;
    }

    private function removeHeartbeat(): void
    {
        $redis = RedisAdapter::connection();
        $redis?->del(RedisKeys::workerHeartbeat($this->workerId));
    }
}