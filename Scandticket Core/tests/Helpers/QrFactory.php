<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Helpers;

use ScandTicket\Core\Container;
use ScandTicket\Security\HmacService;
use ScandTicket\Security\NonceService;

final class QrFactory
{
    private static ?HmacService $hmac = null;
    private static ?NonceService $nonce = null;

    private static function hmac(): HmacService { return self::$hmac ??= Container::instance()->make(HmacService::class); }
    private static function nonce(): NonceService { return self::$nonce ??= Container::instance()->make(NonceService::class); }

    public static function valid(int $ticketId = 1, int $eventId = 100, ?int $timestamp = null, ?string $nonceValue = null): array
    {
        $data = ['t' => $ticketId, 'e' => $eventId, 'ts' => $timestamp ?? time(), 'n' => $nonceValue ?? self::nonce()->generate()];
        $data['h'] = self::hmac()->sign($data);
        return $data;
    }

    public static function tamperedSignature(int $ticketId = 1, int $eventId = 100): array
    {
        $data = self::valid($ticketId, $eventId);
        $sig = $data['h'];
        $data['h'] = ($sig[0] === 'a' ? 'b' : 'a') . substr($sig, 1);
        return $data;
    }

    public static function withExtraFields(int $ticketId = 1, int $eventId = 100): array
    {
        $data = self::valid($ticketId, $eventId);
        $data['admin'] = true;
        $data['role'] = 'superuser';
        return $data;
    }

    public static function missingField(string $field): array
    {
        $data = self::valid();
        unset($data[$field]);
        return $data;
    }

    public static function expiredTimestamp(int $driftSeconds = 300): array
    {
        return self::valid(timestamp: time() - $driftSeconds);
    }

    public static function replayPair(int $ticketId = 1, int $eventId = 100): array
    {
        $sharedNonce = (new NonceService())->generate();
        return [
            'original' => self::valid($ticketId, $eventId, nonceValue: $sharedNonce),
            'replay'   => self::valid($ticketId + 1, $eventId, nonceValue: $sharedNonce),
        ];
    }

    public static function duplicateBatch(int $ticketId, int $eventId, int $count): array
    {
        $payloads = [];
        for ($i = 0; $i < $count; $i++) $payloads[] = self::valid($ticketId, $eventId);
        return $payloads;
    }

    public static function uniqueBatch(int $eventId, int $count, int $startTicketId = 1): array
    {
        $payloads = [];
        for ($i = 0; $i < $count; $i++) $payloads[] = self::valid($startTicketId + $i, $eventId);
        return $payloads;
    }

    public static function device(int $id = 1): array
    {
        return ['id' => $id, 'device_uid' => 'test-device-' . $id, 'name' => 'Test Scanner ' . $id, 'is_active' => 1];
    }
}