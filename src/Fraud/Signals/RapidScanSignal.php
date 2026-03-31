<?php
declare(strict_types=1);

namespace ScandTicket\Fraud\Signals;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;
use ScandTicket\Fraud\FraudSignal;

final class RapidScanSignal
{
    private const WINDOW_SECONDS = 10.0;
    private const KEY_TTL = 30;
    private const DEFAULT_THRESHOLDS = [3 => 0.0, 5 => 0.2, 10 => 0.6];

    public function evaluate(int $deviceId): FraudSignal
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return new FraudSignal('rapid', 0.0, 'Redis unavailable');

        $key = RedisKeys::fraudRapid($deviceId);
        $now = microtime(true);
        $redis->zRemRangeByScore($key, '-inf', sprintf('%.6f', $now - self::WINDOW_SECONDS));
        $count = $redis->zCard($key);
        $member = sprintf('%.6f:%s', $now, bin2hex(random_bytes(4)));
        $redis->zAdd($key, $now, $member);
        $redis->expire($key, self::KEY_TTL);

        $score = $this->scoreFromCount($count);
        $reason = $count > 3 ? sprintf('%d scans in last %ds from device %d', $count, (int) self::WINDOW_SECONDS, $deviceId) : '';
        return new FraudSignal('rapid', $score, $reason);
    }

    private function scoreFromCount(int $count): float
    {
        foreach ($this->getThresholds() as $max => $score) { if ($count <= $max) return $score; }
        return 1.0;
    }

    private function getThresholds(): array
    {
        $option = get_option('scandticket_fraud_rapid_thresholds', null);
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