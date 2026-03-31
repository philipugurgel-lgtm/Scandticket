<?php
declare(strict_types=1);

namespace ScandTicket\Auth;

use ScandTicket\Core\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(DeviceAuthenticator::class, fn() => new DeviceAuthenticator());
    }

    public function boot(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('scandticket auth:rotate', function ($args) {
                $deviceId = (int) ($args[0] ?? 0);
                if ($deviceId <= 0) { \WP_CLI::error('Usage: wp scandticket auth:rotate <device_id>'); return; }
                $repo = $this->container->make(\ScandTicket\Devices\DeviceRepository::class);
                $newToken = $repo->rotateToken($deviceId);
                if ($newToken === null) { \WP_CLI::error("Device {$deviceId} not found or rotation failed."); return; }
                \WP_CLI::success("Token rotated for device {$deviceId}.");
                \WP_CLI::log("New token: {$newToken}");
                \WP_CLI::warning('Save this token — it will not be shown again.');
            });
        }

        add_filter('rest_request_after_callbacks', function ($response, $handler, $request) {
            if (is_wp_error($response) && $response->get_error_code() === 'auth_rate_limited') {
                $data = $response->get_error_data();
                $rest = new \WP_REST_Response(['code' => 'auth_rate_limited', 'message' => $response->get_error_message()], 429);
                $rest->header('Retry-After', (string) ($data['retry_after'] ?? 60));
                return $rest;
            }
            return $response;
        }, 10, 3);
    }
}