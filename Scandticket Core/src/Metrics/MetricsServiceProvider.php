<?php
declare(strict_types=1);

namespace ScandTicket\Metrics;

use ScandTicket\Core\ServiceProvider;

final class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void { $this->container->singleton(MetricsCollector::class, fn() => new MetricsCollector()); }
}