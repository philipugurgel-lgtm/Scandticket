<?php
declare(strict_types=1);

namespace ScandTicket\Logging;

use ScandTicket\Security\QrPayload;

final class ScanLogger
{
    public function logRaw(array $rawInput, int $deviceId, string $action, string $result, ?float $fraudScore = null): void
    {
        $this->write(isset($rawInput['t']) ? (int) $rawInput['t'] : null, isset($rawInput['e']) ? (int) $rawInput['e'] : null, $deviceId, $action, $result, $fraudScore, $this->sanitizeRaw($rawInput));
    }

    public function logPayload(QrPayload $payload, int $deviceId, string $action, string $result, ?float $fraudScore = null): void
    {
        $this->write($payload->ticketId, $payload->eventId, $deviceId, $action, $result, $fraudScore, ['t' => $payload->ticketId, 'e' => $payload->eventId, 'ts' => $payload->timestamp]);
    }

    private function write(?int $ticketId, ?int $eventId, int $deviceId, string $action, string $result, ?float $fraudScore, array $payload): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'scandticket_scan_log', [
            'ticket_id' => $ticketId, 'event_id' => $eventId, 'device_id' => $deviceId,
            'action' => mb_substr(sanitize_text_field($action), 0, 32),
            'result' => mb_substr(sanitize_text_field($result), 0, 32),
            'fraud_score' => $fraudScore, 'ip_address' => $this->getClientIp(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 512) : null,
            'payload' => wp_json_encode($payload),
        ]);
    }

    private function sanitizeRaw(array $data): array
    {
        unset($data['h']);
        $safe = []; $i = 0;
        foreach ($data as $k => $v) { if ($i >= 10) break; $safe[mb_substr(sanitize_text_field((string)$k), 0, 16)] = is_scalar($v) ? mb_substr(sanitize_text_field((string)$v), 0, 128) : '[non-scalar]'; $i++; }
        return $safe;
    }

    private function getClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) { if (!empty($_SERVER[$h])) { $ip = trim(strtok($_SERVER[$h], ',')); if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip; } }
        return '0.0.0.0';
    }
}