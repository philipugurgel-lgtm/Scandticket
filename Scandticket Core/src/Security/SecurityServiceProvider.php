<?php
declare(strict_types=1);

namespace ScandTicket\Security;

use ScandTicket\Core\ServiceProvider;

final class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(HmacService::class, fn() => new HmacService());
        $this->container->singleton(NonceService::class, fn() => new NonceService());
        $this->container->singleton(InputValidator::class, fn() => new InputValidator());
    }

    public function boot(): void
    {
        if (!wp_next_scheduled('scandticket_purge_nonces')) {
            wp_schedule_event(time(), 'hourly', 'scandticket_purge_nonces');
        }

        add_action('scandticket_purge_nonces', function () {
            $purged = $this->container->make(NonceService::class)->purgeExpired();
            if ($purged > 0) {
                do_action('scandticket_nonce_purge_complete', $purged);
            }
        });
    }
}