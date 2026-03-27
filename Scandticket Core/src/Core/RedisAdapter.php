<?php
declare(strict_types=1);

namespace ScandTicket\Core;

final class RedisAdapter
{
    private static array $pool = [];
    private \Redis|\Predis\Client|null $client = null;
    private string $driver = '';
    private bool $circuitOpen = false;
    private float $lastFailureTime = 0.0;
    private int $consecutiveFailures = 0;
    private const MAX_BACKOFF_SECONDS = 30;
    private string $prefix;

    private function __construct(private readonly string $name)
    {
        $this->prefix = Config::get('redis_prefix', 'scandticket:');
    }

    public static function connection(string $name = 'default'): ?self
    {
        if (!isset(self::$pool[$name])) {
            self::$pool[$name] = new self($name);
        }

        $adapter = self::$pool[$name];

        if ($adapter->circuitOpen) {
            $backoff = $adapter->calculateBackoff();
            $elapsed = microtime(true) - $adapter->lastFailureTime;
            if ($elapsed < $backoff) {
                return null;
            }
            $adapter->circuitOpen = false;
        }

        if ($adapter->client === null) {
            $adapter->connect();
        }

        if ($adapter->client !== null) {
            try {
                $adapter->rawPing();
                return $adapter;
            } catch (\Throwable) {
                $adapter->handleFailure();
                return null;
            }
        }

        return null;
    }

    public static function pubsub(): ?self
    {
        return self::connection('pubsub');
    }

    public static function isAvailable(): bool
    {
        return self::connection('default') !== null;
    }

    public static function resetAll(): void
    {
        foreach (self::$pool as $adapter) {
            $adapter->disconnect();
            $adapter->circuitOpen = false;
            $adapter->consecutiveFailures = 0;
            $adapter->lastFailureTime = 0.0;
        }
        self::$pool = [];
    }

    public static function reset(string $name = 'default'): void
    {
        if (isset(self::$pool[$name])) {
            self::$pool[$name]->disconnect();
            unset(self::$pool[$name]);
        }
    }

    private function connect(): void
    {
        $host     = Config::get('redis_host', '127.0.0.1');
        $port     = (int) Config::get('redis_port', 6379);
        $password = Config::get('redis_password');
        $database = (int) Config::get('redis_database', 0);
        $timeout  = 2.0;

        if (extension_loaded('redis')) {
            $this->connectPhpRedis($host, $port, $timeout, $password, $database);
        } elseif (class_exists('\\Predis\\Client')) {
            $this->connectPredis($host, $port, $timeout, $password, $database);
        } else {
            $this->handleFailure();
            do_action('scandticket_redis_no_driver');
        }
    }

    private function connectPhpRedis(string $host, int $port, float $timeout, ?string $password, int $database): void
    {
        try {
            $redis = new \Redis();
            $redis->connect($host, $port, $timeout);
            if ($password !== null && $password !== '') {
                $redis->auth($password);
            }
            if ($database > 0) {
                $redis->select($database);
            }
            $redis->ping();
            $this->client = $redis;
            $this->driver = 'phpredis';
            $this->consecutiveFailures = 0;
            $this->circuitOpen = false;
        } catch (\RedisException $e) {
            $this->handleFailure();
            do_action('scandticket_redis_connect_failed', $this->name, $e);
        }
    }

    private function connectPredis(string $host, int $port, float $timeout, ?string $password, int $database): void
    {
        try {
            $params = ['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'timeout' => $timeout];
            if ($password !== null && $password !== '') {
                $params['password'] = $password;
            }
            if ($database > 0) {
                $params['database'] = $database;
            }
            $client = new \Predis\Client($params, ['exceptions' => true]);
            $client->ping();
            $this->client = $client;
            $this->driver = 'predis';
            $this->consecutiveFailures = 0;
            $this->circuitOpen = false;
        } catch (\Throwable $e) {
            $this->client = null;
            $this->handleFailure();
            do_action('scandticket_redis_connect_failed', $this->name, $e);
        }
    }

    private function disconnect(): void
    {
        if ($this->client !== null) {
            try {
                if ($this->driver === 'phpredis') {
                    $this->client->close();
                } else {
                    $this->client->disconnect();
                }
            } catch (\Throwable) {}
            $this->client = null;
        }
    }

    private function handleFailure(): void
    {
        $this->client = null;
        $this->circuitOpen = true;
        $this->consecutiveFailures++;
        $this->lastFailureTime = microtime(true);
    }

    private function calculateBackoff(): float
    {
        return (float) min(pow(2, $this->consecutiveFailures - 1), self::MAX_BACKOFF_SECONDS);
    }

    private function rawPing(): void
    {
        $this->client->ping();
    }

    private function safeExecute(callable $operation, mixed $fallback = null): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->handleFailure();
            do_action('scandticket_redis_op_failed', $this->name, $e);
            return $fallback;
        }
    }

    public function key(string $raw): string
    {
        return $this->prefix . $raw;
    }

    public function flushDb(): bool
    {
        return $this->safeExecute(function (): bool {
            if ($this->driver === 'phpredis') {
                $this->client->flushDB();
            } else {
                $this->client->flushdb();
            }
            return true;
        }, false);
    }

    public function get(string $key): ?string
    {
        return $this->safeExecute(function () use ($key): ?string {
            $val = $this->client->get($this->key($key));
            if ($val === false || $val === null) {
                return null;
            }
            return (string) $val;
        });
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        return $this->safeExecute(function () use ($key, $value, $ttl): bool {
            $k = $this->key($key);
            if ($ttl !== null) {
                $this->client->setex($k, $ttl, $value);
            } else {
                $this->client->set($k, $value);
            }
            return true;
        }, false);
    }

    public function setNxEx(string $key, string $value, int $ttlSeconds): bool
    {
        return $this->safeExecute(function () use ($key, $value, $ttlSeconds): bool {
            $k = $this->key($key);
            if ($this->driver === 'phpredis') {
                $result = $this->client->set($k, $value, ['NX', 'EX' => $ttlSeconds]);
                return $result === true;
            }
            $result = $this->client->set($k, $value, 'EX', $ttlSeconds, 'NX');
            return $result !== null;
        }, false);
    }

    public function del(string ...$keys): int
    {
        if (empty($keys)) return 0;
        return $this->safeExecute(function () use ($keys): int {
            $prefixed = array_map(fn(string $k) => $this->key($k), $keys);
            return (int) $this->client->del($prefixed);
        }, 0);
    }

    public function exists(string $key): bool
    {
        return $this->safeExecute(function () use ($key): bool {
            return ((int) $this->client->exists($this->key($key))) > 0;
        }, false);
    }

    public function incrBy(string $key, int $amount = 1): int
    {
        return $this->safeExecute(function () use ($key, $amount): int {
            return (int) $this->client->incrBy($this->key($key), $amount);
        }, 0);
    }

    public function expire(string $key, int $ttl): bool
    {
        return $this->safeExecute(function () use ($key, $ttl): bool {
            return (bool) $this->client->expire($this->key($key), $ttl);
        }, false);
    }

    public function lPush(string $key, string $value): int
    {
        return $this->safeExecute(function () use ($key, $value): int {
            return (int) $this->client->lPush($this->key($key), $value);
        }, 0);
    }

    public function rPush(string $key, string $value): int
    {
        return $this->safeExecute(function () use ($key, $value): int {
            return (int) $this->client->rPush($this->key($key), $value);
        }, 0);
    }

    public function brPop(string $key, int $timeout = 5): ?string
    {
        return $this->safeExecute(function () use ($key, $timeout): ?string {
            $k = $this->key($key);
            if ($this->driver === 'phpredis') {
                $result = $this->client->brPop([$k], $timeout);
                if (empty($result)) return null;
                return (string) $result[1];
            }
            $result = $this->client->brpop([$k], $timeout);
            if ($result === null) return null;
            return (string) $result[1];
        });
    }

    public function lRem(string $key, string $value, int $count = 1): int
    {
        return $this->safeExecute(function () use ($key, $value, $count): int {
            $k = $this->key($key);
            if ($this->driver === 'phpredis') {
                return (int) $this->client->lRem($k, $value, $count);
            }
            return (int) $this->client->lrem($k, $count, $value);
        }, 0);
    }

    public function lLen(string $key): int
    {
        return $this->safeExecute(function () use ($key): int {
            return (int) $this->client->lLen($this->key($key));
        }, 0);
    }

    public function hSet(string $key, string $field, string $value): bool
    {
        return $this->safeExecute(function () use ($key, $field, $value): bool {
            $this->client->hSet($this->key($key), $field, $value);
            return true;
        }, false);
    }

    public function hGet(string $key, string $field): ?string
    {
        return $this->safeExecute(function () use ($key, $field): ?string {
            $val = $this->client->hGet($this->key($key), $field);
            if ($val === false || $val === null) return null;
            return (string) $val;
        });
    }

    public function hDel(string $key, string ...$fields): int
    {
        if (empty($fields)) return 0;
        return $this->safeExecute(function () use ($key, $fields): int {
            return (int) $this->client->hDel($this->key($key), ...$fields);
        }, 0);
    }

    public function hGetAll(string $key): array
    {
        return $this->safeExecute(function () use ($key): array {
            $result = $this->client->hGetAll($this->key($key));
            return is_array($result) ? $result : [];
        }, []);
    }

    public function hLen(string $key): int
    {
        return $this->safeExecute(function () use ($key): int {
            return (int) $this->client->hLen($this->key($key));
        }, 0);
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        return $this->safeExecute(function () use ($key, $score, $member): int {
            $k = $this->key($key);
            if ($this->driver === 'phpredis') {
                return (int) $this->client->zAdd($k, $score, $member);
            }
            return (int) $this->client->zadd($k, [$member => $score]);
        }, 0);
    }

    public function zRemRangeByScore(string $key, string $min, string $max): int
    {
        return $this->safeExecute(function () use ($key, $min, $max): int {
            return (int) $this->client->zRemRangeByScore($this->key($key), $min, $max);
        }, 0);
    }

    public function zCard(string $key): int
    {
        return $this->safeExecute(function () use ($key): int {
            return (int) $this->client->zCard($this->key($key));
        }, 0);
    }

    public function zRange(string $key, int $start, int $stop): array
    {
        return $this->safeExecute(function () use ($key, $start, $stop): array {
            return (array) $this->client->zRange($this->key($key), $start, $stop);
        }, []);
    }

    public function zRangeByScore(string $key, string $min, string $max, int $offset = 0, int $count = 100): array
    {
        return $this->safeExecute(function () use ($key, $min, $max, $offset, $count): array {
            $k = $this->key($key);
            if ($this->driver === 'phpredis') {
                return (array) $this->client->zRangeByScore($k, $min, $max, ['limit' => [$offset, $count]]);
            }
            return (array) $this->client->zrangebyscore($k, $min, $max, ['limit' => ['offset' => $offset, 'count' => $count]]);
        }, []);
    }

    public function zRem(string $key, string ...$members): int
    {
        if (empty($members)) return 0;
        return $this->safeExecute(function () use ($key, $members): int {
            return (int) $this->client->zRem($this->key($key), ...$members);
        }, 0);
    }

    public function publish(string $channel, string $message): int
    {
        return $this->safeExecute(function () use ($channel, $message): int {
            return (int) $this->client->publish($this->key($channel), $message);
        }, 0);
    }

    public function subscribe(array $channels, callable $callback): void
    {
        if ($this->name !== 'pubsub') {
            throw new \LogicException('subscribe() must only be called on the pubsub connection.');
        }

        $prefixed = array_map(fn(string $ch) => $this->key($ch), $channels);

        try {
            if ($this->driver === 'phpredis') {
                $this->client->subscribe($prefixed, function ($redis, $channel, $message) use ($callback) {
                    $cleanChannel = $this->stripPrefix($channel);
                    $callback($cleanChannel, $message);
                });
            } else {
                $pubsub = $this->client->pubSubLoop();
                $pubsub->subscribe(...$prefixed);
                foreach ($pubsub as $msg) {
                    if ($msg->kind === 'message') {
                        $cleanChannel = $this->stripPrefix($msg->channel);
                        $result = $callback($cleanChannel, $msg->payload);
                        if ($result === false) {
                            $pubsub->unsubscribe();
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->handleFailure();
            do_action('scandticket_redis_subscribe_failed', $e);
        }
    }

    private function stripPrefix(string $key): string
    {
        if (str_starts_with($key, $this->prefix)) {
            return substr($key, strlen($this->prefix));
        }
        return $key;
    }

    public function eval(string $script, array $keys = [], array $args = [], int $numKeys = 0): mixed
    {
        return $this->safeExecute(function () use ($script, $keys, $args, $numKeys): mixed {
            if ($this->driver === 'phpredis') {
                return $this->client->eval($script, array_merge($keys, $args), $numKeys);
            }
            return $this->client->eval($script, $numKeys, ...$keys, ...$args);
        });
    }

    public function diagnostics(): array
    {
        return [
            'name'                 => $this->name,
            'driver'               => $this->driver ?: 'none',
            'connected'            => $this->client !== null,
            'circuit_open'         => $this->circuitOpen,
            'consecutive_failures' => $this->consecutiveFailures,
            'current_backoff_secs' => $this->circuitOpen ? $this->calculateBackoff() : 0,
        ];
    }
}