<?php
declare(strict_types=1);

namespace ScandTicket\Core;

final class RedisKeys
{
    public static function nonce(string $nonce): string
    {
        return 'nonce:' . $nonce;
    }

    public static function queuePending(): string { return 'queue:pending'; }
    public static function queueProcessing(): string { return 'queue:processing'; }
    public static function queueDelayed(): string { return 'queue:delayed'; }
    public static function queueDlq(): string { return 'queue:dlq'; }

    public static function workerHeartbeat(string $workerId): string
    {
        return 'queue:meta:worker:' . $workerId;
    }

    public static function rateLimit(int $deviceId): string
    {
        return 'ratelimit:device:' . $deviceId;
    }

    public static function idempotency(string $key): string
    {
        return 'idempotency:' . $key;
    }

    public static function fraudRapid(int $deviceId): string
    {
        return 'fraud:rapid:' . $deviceId;
    }

    public static function fraudTicketDevice(int $ticketId, int $eventId): string
    {
        return 'fraud:ticket_device:' . $ticketId . ':' . $eventId;
    }

    public static function metric(string $name): string
    {
        return 'metrics:' . $name;
    }

    public static function metricTimeBucket(string $name, string $minuteKey): string
    {
        return 'metrics:ts:' . $name . ':' . $minuteKey;
    }

    public static function eventChannel(int|string $eventId): string
    {
        return 'events:' . $eventId;
    }

    public static function globalChannel(): string
    {
        return 'events:global';
    }

    public static function deviceSeen(int $deviceId): string
    {
        return 'device:seen:' . $deviceId;
    }

    public static function authBruteForce(string $ip): string
    {
        return 'auth:bruteforce:' . substr(hash('sha256', $ip), 0, 16);
    }

    public static function authAuditDebounce(int $deviceId): string
    {
        return 'auth:audit_debounce:' . $deviceId;
    }
}