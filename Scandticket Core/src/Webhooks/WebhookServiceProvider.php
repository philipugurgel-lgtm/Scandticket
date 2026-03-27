<?php
declare(strict_types=1);

namespace ScandTicket\Webhooks;

use ScandTicket\Core\ServiceProvider;

final class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void { $this->container->singleton(WebhookDispatcher::class, fn() => new WebhookDispatcher()); }
    public function boot(): void { add_action('scandticket_webhook_retry', function ($ep, $ev, $p, $a) { $this->container->make(WebhookDispatcher::class)->dispatch($ev, $p); }, 10, 4); }
}