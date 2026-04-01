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

        // Show a persistent admin notice when the HMAC secret is missing or too
        // short. Activator::activate() sets this option; we clear it here once the
        // secret is properly configured so the notice disappears automatically.
        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) {
                return;
            }

            $hmacSecret = defined('SCANDTICKET_HMAC_SECRET')
                ? SCANDTICKET_HMAC_SECRET
                : get_option('scandticket_hmac_secret', '');

            if (is_string($hmacSecret) && strlen($hmacSecret) >= 32) {
                delete_option('scandticket_hmac_missing_warning');
                return;
            }

            if (!get_option('scandticket_hmac_missing_warning')) {
                return;
            }

            echo '<div class="notice notice-error"><p>'
                . '<strong>ScandTicket:</strong> '
                . esc_html__(
                    'HMAC secret is not configured. QR code signatures cannot be verified. '
                    . 'Define SCANDTICKET_HMAC_SECRET (≥ 32 characters) in wp-config.php '
                    . 'or set the scandticket_hmac_secret option.',
                    'scandticket',
                )
                . '</p></div>';
        });
    }
}