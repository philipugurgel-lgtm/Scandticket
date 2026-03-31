<?php
declare(strict_types=1);

namespace ScandTicket\Queue;

use ScandTicket\Core\Container;
use WP_CLI;

final class QueueReaperCommand
{
    private bool $stopping = false;

    public function __invoke(array $args, array $assoc): void
    {
        $daemon   = (bool) ($assoc['daemon'] ?? false);
        $interval = (int) ($assoc['interval'] ?? 30);
        if ($daemon) $this->registerSignals();

        $queue = Container::instance()->make(ScanQueue::class);
        WP_CLI::log('[reaper] Started.');

        do {
            $recovered = $queue->reap();
            $stats = $queue->stats();
            WP_CLI::log(sprintf('[reaper] Recovered: %d | Pending: %d | Processing: %d | Delayed: %d | DLQ: %d', $recovered, $stats['pending'], $stats['processing'], $stats['delayed'], $stats['dlq']));
            if ($daemon && !$this->stopping) { sleep($interval); if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch(); }
        } while ($daemon && !$this->stopping);

        WP_CLI::success('[reaper] Exited.');
    }

    private function registerSignals(): void
    {
        if (!function_exists('pcntl_signal')) return;
        pcntl_async_signals(true);
        $handler = fn() => $this->stopping = true;
        pcntl_signal(SIGTERM, $handler); pcntl_signal(SIGINT, $handler);
    }
}