<?php
declare(strict_types=1);

$wpLoad = dirname(__DIR__, 2) . '/../../wp-load.php';
if (getenv('WP_ROOT')) $wpLoad = getenv('WP_ROOT') . '/wp-load.php';
if (!file_exists($wpLoad)) { fwrite(STDERR, "Cannot find wp-load.php. Set WP_ROOT.\n"); exit(1); }

define('DOING_CRON', true);
define('WP_USE_THEMES', false);
require_once $wpLoad;
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['scenario:']);
$scenario = $options['scenario'] ?? null;
$runner = new \ScandTicket\Tests\Stress\StressRunner();
exit($scenario === null || $scenario === 'all' ? $runner->runAll() : $runner->runScenario($scenario));