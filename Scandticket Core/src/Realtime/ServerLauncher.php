<?php
declare(strict_types=1);

namespace ScandTicket\Realtime;

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

final class ServerLauncher
{
    public function run(string $host, int $port, int $maxConnections): void
    {
        $loop = Loop::get();
        echo "[WS] ScandTicket WebSocket Server v" . SCANDTICKET_VERSION . "\n";

        $handler = new ScanBroadcastHandler($maxConnections);
        $wsServer = new WsServer($handler);
        $wsServer->enableKeepAlive($loop, 30);
        $socket = new SocketServer("{$host}:{$port}", [], $loop);
        new IoServer(new HttpServer($wsServer), $socket, $loop);
        echo "[WS] Listening on {$host}:{$port}\n";

        $subscriber = new RedisSubscriber($loop, $handler);
        $subscriber->connect();

        $loop->addPeriodicTimer(30.0, fn() => $handler->pingAll());
        $loop->addPeriodicTimer(60.0, function () use ($handler) { $r = $handler->reapStale(); if ($r > 0) echo "[WS] Reaped {$r} stale connections\n"; });
        $loop->addPeriodicTimer(60.0, function () use ($handler) { $s = $handler->getStats(); echo sprintf("[WS] Connections: %d | Messages: %d\n", $s['connections'], $s['messages_sent']); });

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($loop, $handler) { $handler->shutdownAll('Server restarting.'); $loop->addTimer(1.0, fn() => $loop->stop()); });
            pcntl_signal(SIGINT, function () use ($loop, $handler) { $handler->shutdownAll('Server stopped.'); $loop->addTimer(0.5, fn() => $loop->stop()); });
            $loop->addPeriodicTimer(1.0, fn() => pcntl_signal_dispatch());
        }

        echo "[WS] Server ready.\n";
        $loop->run();
    }
}