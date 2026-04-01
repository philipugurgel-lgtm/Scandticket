<?php
declare(strict_types=1);

namespace ScandTicket\Realtime;

use Clue\React\Redis\RedisClient;
use React\EventLoop\LoopInterface;
use ScandTicket\Core\Config;

final class RedisSubscriber
{
    private ?RedisClient $client = null;
    private int $reconnectAttempts = 0;
    private const MAX_BACKOFF = 30;
    private array $channels = [];
    private string $prefix;

    public function __construct(private readonly LoopInterface $loop, private readonly ScanBroadcastHandler $handler)
    {
        $this->prefix = Config::get('redis_prefix', 'scandticket:');
    }

    public function connect(): void
    {
        $host     = Config::get('redis_host', '127.0.0.1');
        $port     = (int) Config::get('redis_port', 6379);
        $password = Config::get('redis_password');
        $database = (int) Config::get('redis_database', 0);

        // Connection URI carries the password for the client library.
        // A credential-free variant is kept separately for safe log output —
        // Clue\React\Redis exceptions often embed the full URI in getMessage().
        $auth    = ($password !== null && $password !== '') ? ':' . rawurlencode($password) . '@' : '';
        $uri     = "redis://{$auth}{$host}:{$port}/{$database}";
        $safeUri = "redis://{$host}:{$port}/{$database}";

        $factory = new \Clue\React\Redis\Factory($this->loop);
        $factory->createClient($uri)->then(
            function (RedisClient $client) use ($safeUri) {
                $this->client = $client;
                $this->reconnectAttempts = 0;
                $client->on('close', function () { $this->client = null; $this->scheduleReconnect(); });
                // Protocol-level errors do not contain credentials — getMessage() is safe here.
                $client->on('error', function (\Throwable $e) { echo '[Redis-Sub] Error: ' . $e->getMessage() . "\n"; });
                $client->on('message', function (string $ch, string $msg) { $this->onMessage($ch, $msg); });
                $this->subscribeDefaults();
            },
            function (\Throwable $e) use ($safeUri) {
                // Do NOT log $e->getMessage() — connection exceptions from this
                // library embed the full connection URI, including the password.
                echo '[Redis-Sub] Connect failed (' . $safeUri . '): ' . get_class($e) . "\n";
                $this->scheduleReconnect();
            }
        );
    }

    private function subscribeDefaults(): void
    {
        if ($this->client === null) return;
        $pattern = $this->prefix . 'events:*';
        $this->client->psubscribe($pattern);
        $this->client->on('pmessage', function (string $p, string $ch, string $msg) { $this->onMessage($ch, $msg); });
    }

    private function onMessage(string $channel, string $message): void
    {
        $clean = str_starts_with($channel, $this->prefix) ? substr($channel, strlen($this->prefix)) : $channel;
        $this->handler->broadcast($clean, $message);
    }

    private function scheduleReconnect(): void
    {
        $this->reconnectAttempts++;
        $delay = min((int) pow(2, $this->reconnectAttempts - 1), self::MAX_BACKOFF);
        $this->loop->addTimer((float) $delay, fn() => $this->connect());
    }
}