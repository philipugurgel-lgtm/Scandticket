<?php
declare(strict_types=1);

namespace ScandTicket\Fraud;

final class FraudSignal
{
    public function __construct(
        public readonly string $name,
        public readonly float  $score,
        public readonly string $reason = '',
    ) {}
}