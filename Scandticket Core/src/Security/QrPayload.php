<?php
declare(strict_types=1);

namespace ScandTicket\Security;

final class QrPayload
{
    public function __construct(
        public readonly int    $ticketId,
        public readonly int    $eventId,
        public readonly int    $timestamp,
        public readonly string $nonce,
        public readonly string $signature,
    ) {}

    public function signingData(): array
    {
        return [
            't'  => $this->ticketId,
            'e'  => $this->eventId,
            'ts' => $this->timestamp,
            'n'  => $this->nonce,
        ];
    }

    public function toArray(): array
    {
        return [
            't'  => $this->ticketId,
            'e'  => $this->eventId,
            'ts' => $this->timestamp,
            'n'  => $this->nonce,
            'h'  => $this->signature,
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'QrPayload(ticket=%d, event=%d, ts=%d, nonce=%s)',
            $this->ticketId, $this->eventId, $this->timestamp,
            substr($this->nonce, 0, 8) . '...'
        );
    }
}