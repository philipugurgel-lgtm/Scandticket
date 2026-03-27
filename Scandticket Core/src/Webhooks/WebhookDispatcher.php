<?php
declare(strict_types=1);

namespace ScandTicket\Webhooks;

use ScandTicket\Core\Config;

final class WebhookDispatcher
{
    public function dispatch(string $event, array $payload): void
    {
        foreach ($this->getEndpointsForEvent($event) as $endpoint) $this->send($endpoint, $event, $payload);
    }

    private function send(array $endpoint, string $event, array $payload, int $attempt = 0): void
    {
        $body = wp_json_encode(['event' => $event, 'data' => $payload, 'timestamp' => time(), 'id' => wp_generate_uuid4()]);
        $signature = hash_hmac('sha256', $body, $endpoint['secret'] ?? '');
        $response = wp_remote_post($endpoint['url'], ['timeout' => Config::get('webhook_timeout'), 'headers' => ['Content-Type' => 'application/json', 'X-ScandTicket-Event' => $event, 'X-ScandTicket-Signature' => $signature], 'body' => $body]);
        $code = wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $code >= 400) {
            if ($attempt < Config::get('webhook_retry_max')) {
                wp_schedule_single_event(time() + (int) pow(2, $attempt + 1), 'scandticket_webhook_retry', [$endpoint, $event, $payload, $attempt + 1]);
            } else {
                do_action('scandticket_webhook_failed', $endpoint, $event, $payload);
            }
        }
    }

    private function getEndpointsForEvent(string $event): array
    {
        $all = get_option('scandticket_webhooks', []);
        return array_filter($all, fn($ep) => in_array($event, $ep['events'] ?? [], true));
    }
}