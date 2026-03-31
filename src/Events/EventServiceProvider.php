<?php
declare(strict_types=1);

namespace ScandTicket\Events;

use ScandTicket\Core\ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    public function register(): void { $this->container->singleton(QrInterceptor::class, fn() => new QrInterceptor()); }
    public function boot(): void { $this->container->make(QrInterceptor::class)->register(); }
}