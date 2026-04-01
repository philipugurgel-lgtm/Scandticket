<?php
declare(strict_types=1);

namespace ScandTicket\API;

use WP_REST_Request;
use WP_REST_Response;

final class CheckinController
{
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'scandticket_checkins';
        $eventId = (int) ($request->get_param('event_id') ?? 0);
        $page    = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?? 50)));
        $offset  = ($page - 1) * $perPage;

        // Each branch uses a complete prepare() call — no string interpolation of
        // user-influenced values anywhere in the query.
        if ($eventId > 0) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $eventId)
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE event_id = %d ORDER BY scanned_at DESC LIMIT %d OFFSET %d",
                    $eventId, $perPage, $offset
                )
            );
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} ORDER BY scanned_at DESC LIMIT %d OFFSET %d",
                    $perPage, $offset
                )
            );
        }

        return new WP_REST_Response([
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ], 200);
    }
}