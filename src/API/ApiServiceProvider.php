<?php
declare(strict_types=1);

namespace ScandTicket\API;

use ScandTicket\Core\ServiceProvider;

final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RestRouter::class, fn() => new RestRouter());
        $this->container->singleton(ScanController::class, fn() => new ScanController());
        $this->container->singleton(DeviceController::class, fn() => new DeviceController());
        $this->container->singleton(HealthController::class, fn() => new HealthController());
        $this->container->singleton(MetricsController::class, fn() => new MetricsController());
        $this->container->singleton(CheckinController::class, fn() => new CheckinController());
    }

    public function boot(): void
    {
        add_action('rest_api_init', fn() => $this->container->make(RestRouter::class)->register());
    }
}