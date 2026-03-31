<?php
declare(strict_types=1);

namespace ScandTicket\Database;

use ScandTicket\Core\ServiceProvider;

final class MigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Migrator::class, fn() => new Migrator());
    }

    public function boot(): void
    {
        add_action('wp_initialize_site', function ($site) {
            switch_to_blog($site->blog_id);
            (new Migrator())->up();
            restore_current_blog();
        });
    }
}