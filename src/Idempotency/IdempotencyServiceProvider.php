<?php
declare(strict_types=1);

namespace ScandTicket\Idempotency;

use ScandTicket\Core\ServiceProvider;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(IdempotencyGuard::class, fn() => new IdempotencyGuard());
    }
}