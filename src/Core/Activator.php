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
        flush_rewrite_rules();
    }

    private static function runMigrations(): void
    {
        (new Migrator())->up();
    }
}