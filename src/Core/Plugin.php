<?php
declare(strict_types=1);

namespace ScandTicket\Core;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $container = Container::instance();
        $container->bootProviders();

        if (is_admin()) {
            add_action('admin_menu', [$this, 'registerAdminMenus']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        }

        $this->booted = true;
        do_action('scandticket_booted', $container);
    }

    public function registerAdminMenus(): void
    {
        add_menu_page(
            __('ScandTicket', 'scandticket'),
            __('ScandTicket', 'scandticket'),
            'manage_options',
            'scandticket',
            [$this, 'renderDashboard'],
            'dashicons-tickets-alt',
            30
        );

        add_submenu_page('scandticket', __('Devices', 'scandticket'), __('Devices', 'scandticket'), 'manage_options', 'scandticket-devices', [$this, 'renderDevices']);
        add_submenu_page('scandticket', __('Staff', 'scandticket'), __('Staff', 'scandticket'), 'manage_options', 'scandticket-staff', [$this, 'renderStaff']);
        add_submenu_page('scandticket', __('Scan Log', 'scandticket'), __('Scan Log', 'scandticket'), 'manage_options', 'scandticket-scanlog', [$this, 'renderScanLog']);
        add_submenu_page('scandticket', __('Metrics', 'scandticket'), __('Metrics', 'scandticket'), 'manage_options', 'scandticket-metrics', [$this, 'renderMetrics']);
        add_submenu_page('scandticket', __('Settings', 'scandticket'), __('Settings', 'scandticket'), 'manage_options', 'scandticket-settings', [$this, 'renderSettings']);
    }

    public function renderDashboard(): void { include SCANDTICKET_PATH . 'admin/views/dashboard.php'; }
    public function renderDevices(): void { include SCANDTICKET_PATH . 'admin/views/devices.php'; }
    public function renderStaff(): void { include SCANDTICKET_PATH . 'admin/views/staff.php'; }
    public function renderScanLog(): void { include SCANDTICKET_PATH . 'admin/views/scan-log.php'; }
    public function renderMetrics(): void { include SCANDTICKET_PATH . 'admin/views/metrics.php'; }
    public function renderSettings(): void { include SCANDTICKET_PATH . 'admin/views/settings.php'; }

    public function enqueueAdminAssets(string $hook): void
    {
        if (str_starts_with($hook, 'toplevel_page_scandticket') || str_starts_with($hook, 'scandticket_page_')) {
            wp_enqueue_style('scandticket-admin', SCANDTICKET_URL . 'assets/css/admin.css', [], SCANDTICKET_VERSION);
            wp_enqueue_script('scandticket-admin', SCANDTICKET_URL . 'assets/js/admin.js', ['jquery'], SCANDTICKET_VERSION, true);
            wp_localize_script('scandticket-admin', 'ScandTicket', [
                'api'   => rest_url('scandticket/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);
        }
    }
}