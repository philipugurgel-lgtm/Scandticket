<?php
declare(strict_types=1);

namespace ScandTicket\API;

use ScandTicket\Core\Container;
use ScandTicket\Metrics\MetricsCollector;
use WP_REST_Response;

final class MetricsController
{
    public function index(): WP_REST_Response { return new WP_REST_Response(Container::instance()->make(MetricsCollector::class)->snapshot(), 200); }
}