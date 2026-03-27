<?php
declare(strict_types=1);

namespace ScandTicket\Queue;

use ScandTicket\Core\ServiceProvider;

final class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ScanQueue::class, fn() => new ScanQueue());
    }

    public function boot(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) return;
        \WP_CLI::add_command('scandticket queue:work', QueueWorkerCommand::class);
        \WP_CLI::add_command('scandticket queue:reap', QueueReaperCommand::class);
        \WP_CLI::add_command('scandticket queue:stats', function () {
            $stats = $this->container->make(ScanQueue::class)->stats();
            \WP_CLI::log('Queue Statistics:');
            foreach ($stats as $k => $v) \WP_CLI::log(sprintf('  %-12s %d', $k . ':', $v));
        });
        \WP_CLI::add_command('scandticket queue:flush', function ($args) {
            $target = $args[0] ?? '';
            $allowed = ['pending', 'processing', 'delayed', 'dlq'];
            if (!in_array($target, $allowed, true)) { \WP_CLI::error('Usage: wp scandticket queue:flush <' . implode('|', $allowed) . '>'); return; }
            $redis = \ScandTicket\Core\RedisAdapter::connection();
            if (!$redis) { \WP_CLI::error('Redis unavailable.'); return; }
            $key = match ($target) { 'pending' => \ScandTicket\Core\RedisKeys::queuePending(), 'processing' => \ScandTicket\Core\RedisKeys::queueProcessing(), 'delayed' => \ScandTicket\Core\RedisKeys::queueDelayed(), 'dlq' => \ScandTicket\Core\RedisKeys::queueDlq() };
            $redis->del($key);
            \WP_CLI::success("Flushed {$target} queue.");
        });
    }
}