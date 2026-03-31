<?php
declare(strict_types=1);

$wpTestsDir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
require_once $wpTestsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    define('SCANDTICKET_HMAC_SECRET', getenv('SCANDTICKET_HMAC_SECRET') ?: 'test-secret-minimum-32-characters-long-here');
    require dirname(__DIR__) . '/scandticket-core.php';
});

require $wpTestsDir . '/includes/bootstrap.php';

$redis = ScandTicket\Core\RedisAdapter::connection();
if ($redis) $redis->flushDb();