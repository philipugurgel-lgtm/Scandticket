<?php
declare(strict_types=1);

namespace ScandTicket\Devices;

use ScandTicket\Core\ServiceProvider;

final class DeviceServiceProvider extends ServiceProvider
{
    public function register(): void { $this->container->singleton(DeviceRepository::class, fn() => new DeviceRepository()); }
}