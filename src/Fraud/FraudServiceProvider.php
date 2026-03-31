<?php
declare(strict_types=1);

namespace ScandTicket\Fraud;

use ScandTicket\Core\ServiceProvider;
use ScandTicket\Fraud\Signals\{DuplicateScanSignal, RapidScanSignal, MultiDeviceSignal, TimestampSignal};

final class FraudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(DuplicateScanSignal::class, fn() => new DuplicateScanSignal());
        $this->container->singleton(RapidScanSignal::class, fn() => new RapidScanSignal());
        $this->container->singleton(MultiDeviceSignal::class, fn() => new MultiDeviceSignal());
        $this->container->singleton(TimestampSignal::class, fn() => new TimestampSignal());
        $this->container->singleton(FraudDetector::class, fn($c) => new FraudDetector($c->make(DuplicateScanSignal::class), $c->make(RapidScanSignal::class), $c->make(MultiDeviceSignal::class), $c->make(TimestampSignal::class)));
    }

    public function boot(): void
    {
        add_action('admin_init', function () {
            register_setting('scandticket_settings', 'scandticket_fraud_threshold', ['type' => 'number', 'default' => 0.7, 'sanitize_callback' => fn($v) => min(1.0, max(0.0, (float)$v))]);
            register_setting('scandticket_settings', 'scandticket_fraud_weights', ['type' => 'string', 'default' => wp_json_encode(FraudWeights::defaults()), 'sanitize_callback' => function ($v) { $p = is_string($v) ? json_decode($v, true) : $v; if (!is_array($p)) return wp_json_encode(FraudWeights::defaults()); return wp_json_encode(FraudWeights::save($p)); }]);
        });

        add_action('rest_api_init', function () {
            register_rest_route('scandticket/v1', '/fraud/weights', [
                ['methods' => 'GET', 'callback' => fn() => new \WP_REST_Response(['weights' => FraudWeights::get(), 'defaults' => FraudWeights::defaults(), 'threshold' => (float) \ScandTicket\Core\Config::get('fraud_threshold', 0.7)], 200), 'permission_callback' => fn() => current_user_can('manage_options')],
                ['methods' => 'POST', 'callback' => function (\WP_REST_Request $r) { $w = $r->get_param('weights'); if (!is_array($w)) return new \WP_REST_Response(['error' => 'weights must be an object'], 400); $saved = FraudWeights::save($w); $t = $r->get_param('threshold'); if ($t !== null) update_option('scandticket_fraud_threshold', min(1.0, max(0.0, (float)$t))); return new \WP_REST_Response(['weights' => $saved], 200); }, 'permission_callback' => fn() => current_user_can('manage_options')],
            ]);
        });
    }
}