<?php
declare(strict_types=1);

namespace ScandTicket\Security;

use ScandTicket\Core\Config;
use WP_Error;

final class InputValidator
{
    private const ALLOWED_QR_FIELDS = ['t', 'e', 'ts', 'n', 'h'];

    public function parseQrPayload(array $raw): QrPayload|WP_Error
    {
        $extraFields = array_diff(array_keys($raw), self::ALLOWED_QR_FIELDS);
        if (!empty($extraFields)) {
            return new WP_Error(
                'payload_extra_fields',
                sprintf('Payload contains disallowed fields: %s', implode(', ', array_map('strval', $extraFields))),
                ['status' => 400]
            );
        }

        foreach (self::ALLOWED_QR_FIELDS as $field) {
            if (!isset($raw[$field]) || $raw[$field] === '' || $raw[$field] === null) {
                return new WP_Error('payload_missing_field', sprintf('Required field missing: %s', $field), ['status' => 400]);
            }
        }

        if (!is_numeric($raw['t']) || (int) $raw['t'] <= 0) {
            return new WP_Error('invalid_ticket_id', 'Ticket ID must be a positive integer.', ['status' => 400]);
        }
        $ticketId = (int) $raw['t'];

        if (!is_numeric($raw['e']) || (int) $raw['e'] <= 0) {
            return new WP_Error('invalid_event_id', 'Event ID must be a positive integer.', ['status' => 400]);
        }
        $eventId = (int) $raw['e'];

        if (!is_numeric($raw['ts'])) {
            return new WP_Error('invalid_timestamp', 'Timestamp must be numeric.', ['status' => 400]);
        }
        $ts = (int) $raw['ts'];
        $maxDrift = (int) Config::get('timestamp_max_drift', 120);
        $drift = abs(time() - $ts);
        if ($drift > $maxDrift) {
            return new WP_Error('timestamp_expired', sprintf('Timestamp drift %ds exceeds maximum %ds.', $drift, $maxDrift), ['status' => 400]);
        }

        $expectedNonceLength = NonceService::NONCE_HEX_LENGTH;
        $nonce = (string) $raw['n'];
        if (!ctype_xdigit($nonce) || strlen($nonce) !== $expectedNonceLength) {
            return new WP_Error('invalid_nonce', sprintf('Nonce must be %d hex characters.', $expectedNonceLength), ['status' => 400]);
        }

        $signature = (string) $raw['h'];
        if (!ctype_xdigit($signature) || strlen($signature) !== 64) {
            return new WP_Error('invalid_signature', 'Signature must be 64 hex characters.', ['status' => 400]);
        }

        return new QrPayload(
            ticketId: $ticketId, eventId: $eventId, timestamp: $ts,
            nonce: $nonce, signature: $signature,
        );
    }

    public function validateBatchScans(array $scans): true|WP_Error
    {
        if (empty($scans)) {
            return new WP_Error('batch_empty', 'Scans array cannot be empty.', ['status' => 400]);
        }
        $maxSize = (int) Config::get('batch_max_size', 50);
        if (count($scans) > $maxSize) {
            return new WP_Error('batch_too_large', sprintf('Batch size %d exceeds maximum %d.', count($scans), $maxSize), ['status' => 400]);
        }
        foreach ($scans as $i => $scan) {
            if (!is_array($scan)) {
                return new WP_Error('batch_invalid_element', sprintf('Element at index %d is not an object.', $i), ['status' => 400]);
            }
        }
        return true;
    }

    public function sanitizeString(string $input, int $maxLength = 255): string
    {
        return mb_substr(sanitize_text_field($input), 0, $maxLength);
    }
}