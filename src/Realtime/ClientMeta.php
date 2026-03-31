<?php
declare(strict_types=1);

namespace ScandTicket\Realtime;

final class ClientMeta
{
    public function __construct(
        public readonly int    $connectedAt,
        public int             $lastPongAt,
        public readonly string $remoteAddress,
        public array           $subscribedEvents = [],
    ) {}
}