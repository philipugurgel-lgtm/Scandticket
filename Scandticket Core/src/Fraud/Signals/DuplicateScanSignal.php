<?php
declare(strict_types=1);

namespace ScandTicket\Fraud\Signals;

use ScandTicket\Core\RedisAdapter;
use ScandTicket\Fraud\FraudSignal;

final class DuplicateScanSignal
{
    public function evaluate(int $ticketId, int $eventId): FraudSignal
    {
        $redis = RedisAdapter::connection();
        if ($redis !== null) {
            $key = 'checkin:' . $ticketId . ':' . $eventId;
            if ($redis->exists($key)) return new FraudSignal('duplicate', 1.0, "Ticket {$ticketId} already checked in (Redis)");
            $dbExists = $this->checkDatabase($ticketId, $eventId);
            if ($dbExists) { $redis->set($key, '1', 86400); return new FraudSignal('duplicate', 1.0, "Ticket {$ticketId} already checked in (DB backfill)"); }
            return new FraudSignal('duplicate', 0.0);
        }
        $dbExists = $this->checkDatabase($ticketId, $eventId);
        return new FraudSignal('duplicate', $dbExists ? 1.0 : 0.0, $dbExists ? "Ticket {$ticketId} already checked in (DB only)" : '');
    }

    private function checkDatabase(int $ticketId, int $eventId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_checkins';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT EXISTS(SELECT 1 FROM {$table} WHERE ticket_id = %d AND event_id = %d AND status = 'checked_in' LIMIT 1)", $ticketId, $eventId)) === 1;
    }
}