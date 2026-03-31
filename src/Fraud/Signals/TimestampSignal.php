<?php
declare(strict_types=1);

namespace ScandTicket\Fraud\Signals;

use ScandTicket\Fraud\FraudSignal;

final class TimestampSignal
{
    private const DEFAULT_THRESHOLDS = [300 => 0.0, 600 => 0.2, 3600 => 0.5];

    public function evaluate(int $qrTimestamp): FraudSignal
    {
        $drift = abs(time() - $qrTimestamp);
        $score = 1.0;
        foreach ($this->getThresholds() as $maxDrift => $s) { if ($drift <= $maxDrift) { $score = $s; break; } }
        $reason = $drift > 300 ? sprintf('Timestamp drift: %ds', $drift) : '';
        return new FraudSignal('timestamp', $score, $reason);
    }

    private function getThresholds(): array
    {
        $option = get_option('scandticket_fraud_timestamp_thresholds', null);
        if ($option !== null) {
            $parsed = is_string($option) ? json_decode($option, true) : $option;
            if (is_array($parsed)) {
                $valid = [];
                foreach ($parsed as $k => $v) { if (is_numeric($k) && is_numeric($v)) $valid[(int)$k] = min(1.0, max(0.0, (float)$v)); }
                if (!empty($valid)) { ksort($valid); return $valid; }
            }
        }
        return self::DEFAULT_THRESHOLDS;
    }
}