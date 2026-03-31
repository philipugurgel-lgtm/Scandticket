<?php
declare(strict_types=1);

namespace ScandTicket\Staff;

use ScandTicket\Core\ServiceProvider;

final class StaffServiceProvider extends ServiceProvider
{
    public function register(): void { $this->container->singleton(StaffRepository::class, fn() => new StaffRepository()); }
}