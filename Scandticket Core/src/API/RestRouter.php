<?php
declare(strict_types=1);

namespace ScandTicket\API;

use ScandTicket\Core\Container;
use WP_REST_Server;

final class RestRouter
{
    private const NS = 'scandticket/v1';

    public function register(): void
    {
        $c = Container::instance();
        register_rest_route(self::NS, '/scan', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$c->make(ScanController::class), 'scan'], 'permission_callback' => [$c->make(ScanController::class), 'deviceAuth']]);
        register_rest_route(self::NS, '/scan/batch', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$c->make(ScanController::class), 'batchScan'], 'permission_callback' => [$c->make(ScanController::class), 'deviceAuth']]);
        register_rest_route(self::NS, '/devices', [['methods' => WP_REST_Server::READABLE, 'callback' => [$c->make(DeviceController::class), 'index'], 'permission_callback' => fn() => current_user_can('manage_options')], ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$c->make(DeviceController::class), 'create'], 'permission_callback' => fn() => current_user_can('manage_options')]]);
        register_rest_route(self::NS, '/devices/(?P<id>\d+)/revoke', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$c->make(DeviceController::class), 'revoke'], 'permission_callback' => fn() => current_user_can('manage_options')]);
        register_rest_route(self::NS, '/metrics', ['methods' => WP_REST_Server::READABLE, 'callback' => [$c->make(MetricsController::class), 'index'], 'permission_callback' => fn() => current_user_can('manage_options')]);
        register_rest_route(self::NS, '/checkins', ['methods' => WP_REST_Server::READABLE, 'callback' => [$c->make(CheckinController::class), 'index'], 'permission_callback' => fn() => current_user_can('manage_options')]);
        register_rest_route(self::NS, '/health', ['methods' => WP_REST_Server::READABLE, 'callback' => [$c->make(HealthController::class), 'check'], 'permission_callback' => '__return_true']);
    }
}