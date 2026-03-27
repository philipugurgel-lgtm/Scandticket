<?php
declare(strict_types=1);

use ScandTicket\Core\Container;
use ScandTicket\Database\MigrationServiceProvider;
use ScandTicket\API\ApiServiceProvider;
use ScandTicket\Auth\AuthServiceProvider;
use ScandTicket\Security\SecurityServiceProvider;
use ScandTicket\Scanner\ScannerServiceProvider;
use ScandTicket\Devices\DeviceServiceProvider;
use ScandTicket\Staff\StaffServiceProvider;
use ScandTicket\Events\EventServiceProvider;
use ScandTicket\Queue\QueueServiceProvider;
use ScandTicket\Realtime\RealtimeServiceProvider;
use ScandTicket\Fraud\FraudServiceProvider;
use ScandTicket\Metrics\MetricsServiceProvider;
use ScandTicket\RateLimit\RateLimitServiceProvider;
use ScandTicket\Logging\LoggingServiceProvider;
use ScandTicket\Webhooks\WebhookServiceProvider;
use ScandTicket\Idempotency\IdempotencyServiceProvider;

Container::instance()->registerProviders([
    MigrationServiceProvider::class,
    SecurityServiceProvider::class,
    AuthServiceProvider::class,
    DeviceServiceProvider::class,
    StaffServiceProvider::class,
    EventServiceProvider::class,
    IdempotencyServiceProvider::class,
    RateLimitServiceProvider::class,
    QueueServiceProvider::class,
    ScannerServiceProvider::class,
    RealtimeServiceProvider::class,
    FraudServiceProvider::class,
    MetricsServiceProvider::class,
    LoggingServiceProvider::class,
    WebhookServiceProvider::class,
    ApiServiceProvider::class,
]);