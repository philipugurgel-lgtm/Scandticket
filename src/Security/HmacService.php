<?php
declare(strict_types=1);

namespace ScandTicket\Security;

use ScandTicket\Core\Config;

final class HmacService
{
    public const SIGNED_FIELDS = ['t', 'e', 'ts', 'n'];

    public function sign(array $data): string
    {
        $message = $this->buildCanonicalMessage($data);
        return hash_hmac('sha256', $message, $this->getSecret());
    }

    public function verify(array $data, string $signature): bool
    {
        $expected = $this->sign($data);
        return hash_equals($expected, $signature);
    }

    private function buildCanonicalMessage(array $data): string
    {
        $parts = [];
        foreach (self::SIGNED_FIELDS as $field) {
            $parts[] = (string) ($data[$field] ?? '');
        }
        return implode('|', $parts);
    }

    private function getSecret(): string
    {
        $secret = Config::get('hmac_secret');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException(
                'HMAC secret must be at least 32 characters. Define SCANDTICKET_HMAC_SECRET in wp-config.php.'
            );
        }
        return $secret;
    }
}