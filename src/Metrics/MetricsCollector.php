<?php
declare(strict_types=1);

namespace ScandTicket\Metrics;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;

final class MetricsCollector
{
    public function increment(string $metric, int $amount = 1): void
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return;
        $redis->incrBy(RedisKeys::metric($metric), $amount);
        $bucket = RedisKeys::metricTimeBucket($metric, gmdate('YmdHi'));
        $redis->incrBy($bucket, $amount);
        $redis->expire($bucket, 7200);
    }

    public function get(string $metric): int
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return 0;
        $val = $redis->get(RedisKeys::metric($metric));
        return $val !== null ? (int) $val : 0;
    }

    public function getTimeSeries(string $metric, int $minutes = 60): array
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return [];
        $series = [];
        for ($i = $minutes - 1; $i >= 0; $i--) {
            $time = gmdate('YmdHi', strtotime("-{$i} minutes"));
            $val = $redis->get(RedisKeys::metricTimeBucket($metric, $time));
            $series[$time] = $val !== null ? (int) $val : 0;
        }
        return $series;
    }

    public function snapshot(): array
    {
        return [
            'scans_accepted' => $this->get('scan_accepted'), 'scans_processed' => $this->get('scan_processed'),
            'scans_duplicate' => $this->get('scan_duplicate'), 'scans_invalid' => $this->get('scan_invalid'),
            'scans_fraud_blocked' => $this->get('scan_fraud_blocked'), 'scans_hmac_failed' => $this->get('scan_hmac_failed'),
            'scans_replayed' => $this->get('scan_replayed'), 'scans_per_minute' => $this->getTimeSeries('scan_accepted', 5),
        ];
    }
}