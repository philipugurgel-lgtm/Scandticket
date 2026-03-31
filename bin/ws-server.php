<?php
declare(strict_types=1);

$wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
if (getenv('WP_ROOT')) {
    $wpLoad = getenv('WP_ROOT') . '/wp-load.php';
}
if (!file_exists($wpLoad)) {
    fwrite(STDERR, "Cannot find wp-load.php at {$wpLoad}\n");
    fwrite(STDERR, "Set WP_ROOT environment variable or adjust path.\n");
    exit(1);
}

define('DOING_CRON', true);
define('WP_USE_THEMES', false);

require_once $wpLoad;
require_once dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['port:', 'host:', 'max-connections:']);
$port = (int) ($options['port'] ?? get_option('scandticket_ws_port', 8090));
$host = (string) ($options['host'] ?? '0.0.0.0');
$maxConn = (int) ($options['max-connections'] ?? 1000);

$launcher = new \ScandTicket\Realtime\ServerLauncher();
$launcher->run($host, $port, $maxConn);
