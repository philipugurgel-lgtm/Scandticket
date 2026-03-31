<?php
declare(strict_types=1);

namespace ScandTicket\API;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\Container;
use ScandTicket\Queue\ScanQueue;
use WP_REST_Response;

final class HealthController
{
    public function check(): WP_REST_Response
    {
        global $wpdb;
        $checks = [];
        $checks['database'] = (bool) $wpdb->get_var('SELECT 1');
        $redis = RedisAdapter::connection();
        $checks['redis'] = $redis !== null;
        if ($redis !== null) $checks['redis_diagnostics'] = $redis->diagnostics();
        $queue = Container::instance()->make(ScanQueue::class);
        $checks['queue'] = $queue->stats();
        $checks['version'] = SCANDTICKET_VERSION;
        $warnings = [];
        if ($checks['queue']['dlq'] > 0) $warnings[] = sprintf('DLQ has %d jobs.', $checks['queue']['dlq']);
        if ($checks['queue']['processing'] > 50) $warnings[] = sprintf('Processing queue has %d jobs.', $checks['queue']['processing']);
        if (!empty($warnings)) $checks['warnings'] = $warnings;
        $healthy = $checks['database'];
        return new WP_REST_Response(['status' => match (true) { !$checks['database'] => 'critical', !$checks['redis'] => 'degraded', default => 'healthy' }, 'checks' => $checks, 'timestamp' => gmdate('c')], $healthy ? 200 : 503);
    }
}