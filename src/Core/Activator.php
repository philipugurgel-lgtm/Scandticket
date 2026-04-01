<?php
declare(strict_types=1);

namespace ScandTicket\Core;

use ScandTicket\Database\Migrator;

final class Activator
{
    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            $sites = get_sites(['fields' => 'ids']);
            foreach ($sites as $siteId) {
                switch_to_blog($siteId);
                self::runMigrations();
                restore_current_blog();
            }
        } else {
            self::runMigrations();
        }

        update_option('scandticket_version', SCANDTICKET_VERSION);

        // Warn if HMAC secret is missing or too short — cannot sign QR codes safely without it.
        $hmacSecret = defined('SCANDTICKET_HMAC_SECRET')
            ? SCANDTICKET_HMAC_SECRET
            : get_option('scandticket_hmac_secret', '');

        if (!is_string($hmacSecret) || strlen($hmacSecret) < 32) {
            update_option('scandticket_hmac_missing_warning', true);
        } else {
            delete_option('scandticket_hmac_missing_warning');
        }

        flush_rewrite_rules();
    }

    private static function runMigrations(): void
    {
        (new Migrator())->up();
    }
}