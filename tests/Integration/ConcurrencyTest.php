<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Integration;

use WP_UnitTestCase;
use ScandTicket\Core\Container;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Scanner\ScanProcessor;
use ScandTicket\Tests\Helpers\QrFactory;
use ScandTicket\Tests\Helpers\RedisHelper;

final class ConcurrencyTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('pcntl required.');
        RedisHelper::flush();
    }

    protected function tearDown(): void { RedisHelper::flush(); parent::tearDown(); }

    public function test_concurrent_same_ticket_one_wins(): void
    {
        $n = 10; $payloads = QrFactory::duplicateBatch(9999, 100, $n);
        $shm = shmop_open(ftok(__FILE__, 't'), 'c', 0644, $n * 64);
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                RedisAdapter::resetAll();
                $r = Container::instance()->make(ScanProcessor::class)->process($payloads[$i], QrFactory::device(1));
                $code = is_array($r) ? 'accepted' : $r->get_error_code();
                shmop_write($shm, str_pad($code, 64, "\0"), $i * 64);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) pcntl_waitpid($pid, $s);
        $results = [];
        for ($i = 0; $i < $n; $i++) $results[] = rtrim(shmop_read($shm, $i * 64, 64), "\0");
        shmop_delete($shm);

        $accepted = array_filter($results, fn($r) => $r === 'accepted');
        $this->assertCount(1, $accepted, 'Exactly one should win. Got: ' . implode(', ', $results));
    }

    public function test_concurrent_different_tickets_all_pass(): void
    {
        $n = 20; $payloads = QrFactory::uniqueBatch(100, $n, 10000);
        $shm = shmop_open(ftok(__FILE__, 'u'), 'c', 0644, $n * 64);
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                RedisAdapter::resetAll();
                $r = Container::instance()->make(ScanProcessor::class)->process($payloads[$i], QrFactory::device(1));
                shmop_write($shm, str_pad(is_array($r) ? 'accepted' : $r->get_error_code(), 64, "\0"), $i * 64);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) pcntl_waitpid($pid, $s);
        $results = [];
        for ($i = 0; $i < $n; $i++) $results[] = rtrim(shmop_read($shm, $i * 64, 64), "\0");
        shmop_delete($shm);

        $this->assertCount($n, array_filter($results, fn($r) => $r === 'accepted'), 'All should pass. Got: ' . implode(', ', $results));
    }
}