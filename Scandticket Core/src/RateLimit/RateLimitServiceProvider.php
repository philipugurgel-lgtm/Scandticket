<?php
declare(strict_types=1);

namespace ScandTicket\RateLimit;

use ScandTicket\Core\ServiceProvider;

final class RateLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RateLimiter::class, fn() => new RateLimiter());
    }
}