<?php
declare(strict_types=1);

namespace ScandTicket\Scanner;

use ScandTicket\Core\ServiceProvider;
use ScandTicket\Security\HmacService;
use ScandTicket\Security\NonceService;
use ScandTicket\Security\InputValidator;
use ScandTicket\Idempotency\IdempotencyGuard;
use ScandTicket\RateLimit\RateLimiter;
use ScandTicket\Queue\ScanQueue;
use ScandTicket\Fraud\FraudDetector;
use ScandTicket\Logging\ScanLogger;
use ScandTicket\Metrics\MetricsCollector;

final class ScannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ScanProcessor::class, fn($c) => new ScanProcessor(
            $c->make(HmacService::class), $c->make(NonceService::class), $c->make(InputValidator::class),
            $c->make(IdempotencyGuard::class), $c->make(RateLimiter::class), $c->make(ScanQueue::class),
            $c->make(FraudDetector::class), $c->make(ScanLogger::class), $c->make(MetricsCollector::class),
        ));
        $this->container->singleton(ScanWorker::class, fn() => new ScanWorker());
    }
}