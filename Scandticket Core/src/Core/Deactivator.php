<?php
declare(strict_types=1);

namespace ScandTicket\Core;

final class Deactivator
{
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('scandticket_queue_worker');
        wp_clear_scheduled_hook('scandticket_metrics_flush');
        wp_clear_scheduled_hook('scandticket_purge_nonces');
        delete_transient('scandticket_health_cache');
        flush_rewrite_rules();
    }
}