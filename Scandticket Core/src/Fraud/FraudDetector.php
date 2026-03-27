<?php
declare(strict_types=1);

namespace ScandTicket\Fraud;

use ScandTicket\Fraud\Signals\DuplicateScanSignal;
use ScandTicket\Fraud\Signals\RapidScanSignal;
use ScandTicket\Fraud\Signals\MultiDeviceSignal;
use ScandTicket\Fraud\Signals\TimestampSignal;

final class FraudDetector
{
    public function __construct(
        private readonly DuplicateScanSignal $duplicateSignal,
        private readonly RapidScanSignal     $rapidSignal,
        private readonly MultiDeviceSignal   $multiDeviceSignal,
        private readonly TimestampSignal     $timestampSignal,
    ) {}

    public function analyze(array $qrData, array $device): FraudScore
    {
        $ticketId = (int) ($qrData['t'] ?? 0);
        $eventId  = (int) ($qrData['e'] ?? 0);
        $deviceId = (int) ($device['id'] ?? 0);
        $ts       = (int) ($qrData['ts'] ?? time());

        $signals = [
            $this->duplicateSignal->evaluate($ticketId, $eventId),
            $this->rapidSignal->evaluate($deviceId),
            $this->multiDeviceSignal->evaluate($ticketId, $eventId, $deviceId),
            $this->timestampSignal->evaluate($ts),
        ];

        $weights = FraudWeights::get();
        $score = 0.0;
        foreach ($signals as $signal) $score += $signal->score * ($weights[$signal->name] ?? 0.0);
        $score = min(1.0, max(0.0, $score));

        $result = new FraudScore($score, $signals, $weights);
        if ($result->isSuspicious()) do_action('scandticket_fraud_suspicious', $result, $ticketId, $eventId, $deviceId);
        if ($result->isBlocked()) do_action('scandticket_fraud_blocked', $result, $ticketId, $eventId, $deviceId);
        return $result;
    }
}