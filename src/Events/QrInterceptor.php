<?php
declare(strict_types=1);

namespace ScandTicket\Events;

use ScandTicket\Core\Container;
use ScandTicket\Security\HmacService;
use ScandTicket\Security\NonceService;

final class QrInterceptor
{
    public function register(): void
    {
        add_filter('tribe_tickets_plus_qr_data', [$this, 'interceptQrData'], 10, 3);
        add_filter('tribe_tickets_plus_qr_payload', [$this, 'extendQrPayload'], 10, 2);
        add_filter('tribe_tickets_attendee_data', [$this, 'injectQrMeta'], 20, 3);
    }

    public function interceptQrData(string $qrData, int $attendeeId, int $eventId): string
    {
        $ticketId = $this->getTicketIdForAttendee($attendeeId);
        if (!$ticketId) return $qrData;
        return wp_json_encode($this->buildSignedPayload($ticketId, $eventId));
    }

    public function extendQrPayload(array $payload, int $attendeeId): array
    {
        $ticketId = $payload['ticket_id'] ?? $this->getTicketIdForAttendee($attendeeId);
        $eventId  = $payload['event_id'] ?? 0;
        if (!$ticketId || !$eventId) return $payload;
        return array_merge($payload, $this->buildSignedPayload((int) $ticketId, (int) $eventId));
    }

    public function injectQrMeta(array $data, int $attendeeId, int $eventId): array
    {
        $ticketId = $data['ticket_id'] ?? $this->getTicketIdForAttendee($attendeeId);
        if ($ticketId) $data['scandticket_qr'] = $this->buildSignedPayload((int) $ticketId, $eventId);
        return $data;
    }

    private function buildSignedPayload(int $ticketId, int $eventId): array
    {
        $c = Container::instance();
        $data = ['t' => $ticketId, 'e' => $eventId, 'ts' => time(), 'n' => $c->make(NonceService::class)->generate()];
        $data['h'] = $c->make(HmacService::class)->sign($data);
        return $data;
    }

    private function getTicketIdForAttendee(int $attendeeId): ?int
    {
        $id = get_post_meta($attendeeId, '_tribe_tickets_ticket_id', true) ?: get_post_meta($attendeeId, '_tec_ticket_id', true);
        return $id ? (int) $id : null;
    }
}