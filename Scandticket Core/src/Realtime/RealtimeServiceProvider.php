<?php
declare(strict_types=1);

namespace ScandTicket\Realtime;

use ScandTicket\Core\ServiceProvider;

final class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(EventBroadcaster::class, fn() => new EventBroadcaster());
    }

    public function boot(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) return;
        \WP_CLI::add_command('scandticket ws:serve', function ($args, $assoc) {
            $port = (int) ($assoc['port'] ?? get_option('scandticket_ws_port', 8090));
            $host = (string) ($assoc['host'] ?? '0.0.0.0');
            $max  = (int) ($assoc['max-connections'] ?? 1000);
            if (!class_exists('\\Ratchet\\Server\\IoServer')) { \WP_CLI::error('Ratchet is not installed. Run: composer require cboden/ratchet react/event-loop clue/redis-react'); return; }
            (new ServerLauncher())->run($host, $port, $max);
        });
    }
}