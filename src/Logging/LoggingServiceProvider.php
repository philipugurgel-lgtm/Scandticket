<?php
declare(strict_types=1);

namespace ScandTicket\Logging;

use ScandTicket\Core\ServiceProvider;

final class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void { $this->container->singleton(ScanLogger::class, fn() => new ScanLogger()); }
}