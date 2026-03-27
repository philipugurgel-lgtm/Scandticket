<?php
declare(strict_types=1);

namespace ScandTicket\Fraud;

use ScandTicket\Core\Config;

final class FraudScore
{
    public function __construct(
        public readonly float $score,
        public readonly array $signals = [],
        public readonly array $weights = [],
    ) {}

    public function isBlocked(): bool { return $this->score >= (float) Config::get('fraud_threshold', 0.7); }
    public function isSuspicious(): bool { return $this->score >= 0.4 && !$this->isBlocked(); }

    public function signalScores(): array
    {
        $out = [];
        foreach ($this->signals as $s) $out[$s->name] = $s->score;
        return $out;
    }

    public function signalReasons(): array
    {
        $out = [];
        foreach ($this->signals as $s) if ($s->reason !== '') $out[$s->name] = $s->reason;
        return $out;
    }
}