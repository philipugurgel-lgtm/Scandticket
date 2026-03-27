<?php
declare(strict_types=1);

namespace ScandTicket\Scanner;

use ScandTicket\Core\Container;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Realtime\EventBroadcaster;
use ScandTicket\Webhooks\WebhookDispatcher;
use ScandTicket\Metrics\MetricsCollector;

final class ScanWorker
{
    public function processJob(array $job): bool
    {
        global $wpdb;
        try {
            $table = $wpdb->prefix . 'scandticket_checkins';
            $inserted = $wpdb->insert($table, [
                'ticket_id' => $job['ticket_id'], 'event_id' => $job['event_id'],
                'device_id' => $job['device_id'], 'status' => 'checked_in',
                'scanned_at' => $job['scanned_at'], 'processed_at' => gmdate('Y-m-d H:i:s'),
                'idempotency_key' => $job['idempotency_key'],
                'metadata' => json_encode($job['metadata'] ?? []),
            ]);
            if (!$inserted) throw new \RuntimeException('Failed to insert checkin record.');
            $checkinId = (int) $wpdb->insert_id;

            $redis = RedisAdapter::connection();
            if ($redis !== null) {
                $redis->set('checkin:' . $job['ticket_id'] . ':' . $job['event_id'], (string) $checkinId, 86400);
            }

            $c = Container::instance();
            try { $c->make(EventBroadcaster::class)->publish('checkin', ['checkin_id' => $checkinId, 'ticket_id' => $job['ticket_id'], 'event_id' => $job['event_id'], 'device_id' => $job['device_id'], 'status' => 'checked_in', 'timestamp' => $job['scanned_at']]); } catch (\Throwable) {}
            try { $c->make(WebhookDispatcher::class)->dispatch('checkin.created', ['checkin_id' => $checkinId, 'ticket_id' => $job['ticket_id'], 'event_id' => $job['event_id']]); } catch (\Throwable) {}
            $c->make(MetricsCollector::class)->increment('scan_processed');
            do_action('scandticket_checkin_created', $checkinId, $job);
            return true;
        } catch (\Throwable $e) {
            do_action('scandticket_worker_error', $e, $job);
            return false;
        }
    }
}