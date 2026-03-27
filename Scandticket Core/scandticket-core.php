<?php
/**
 * Plugin Name: ScandTicket Core
 * Description: Enterprise-grade ticket scanning, QR validation, and real-time event entry platform.
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: ScandTicket
 * License: GPL-2.0-or-later
 * Text Domain: scandticket
 * Network: true
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('SCANDTICKET_VERSION', '1.0.0');
define('SCANDTICKET_FILE', __FILE__);
define('SCANDTICKET_PATH', plugin_dir_path(__FILE__));
define('SCANDTICKET_URL', plugin_dir_url(__FILE__));
define('SCANDTICKET_BASENAME', plugin_basename(__FILE__));

if (file_exists(SCANDTICKET_PATH . 'vendor/autoload.php')) {
    require_once SCANDTICKET_PATH . 'vendor/autoload.php';
}

require_once SCANDTICKET_PATH . 'bootstrap/app.php';

register_activation_hook(__FILE__, [\ScandTicket\Core\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\ScandTicket\Core\Deactivator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \ScandTicket\Core\Plugin::instance()->boot();
}, 5);