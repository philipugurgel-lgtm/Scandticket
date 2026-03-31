<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Integration;

use WP_UnitTestCase;
use ScandTicket\Core\Container;
use ScandTicket\Scanner\ScanProcessor;
use ScandTicket\Tests\Helpers\QrFactory;
use ScandTicket\Tests\Helpers\RedisHelper;

final class RateLimitTest extends WP_UnitTestCase
{
    private ScanProcessor $processor;
    private array $device;

    protected function setUp(): void
    {
        parent::setUp();
        RedisHelper::flush();
        update_option('scandticket_rate_limit', 5);
        $this->processor = Container::instance()->make(ScanProcessor::class);
        $this->device = QrFactory::device(1);
    }

    protected function tearDown(): void { delete_option('scandticket_rate_limit'); RedisHelper::flush(); parent::tearDown(); }

    public function test_within_limit_passes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertIsArray($this->processor->process(QrFactory::valid(1000 + $i, 100), $this->device));
        }
    }

    public function test_exceeding_limit_blocked(): void
    {
        for ($i = 0; $i < 5; $i++) $this->processor->process(QrFactory::valid(2000 + $i, 100), $this->device);
        $result = $this->processor->process(QrFactory::valid(3000, 100), $this->device);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('rate_limited', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertSame(429, $data['status']);
        $this->assertTrue($data['retry']);
        $this->assertSame('rate_limit', $data['step']);
    }

    public function test_nonce_not_consumed_when_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) $this->processor->process(QrFactory::valid(4000 + $i, 100), $this->device);
        $qr = QrFactory::valid(5000, 100);
        $this->assertSame('rate_limited', $this->processor->process($qr, $this->device)->get_error_code());
        $this->assertFalse(RedisHelper::nonceExists($qr['n']));
    }

    public function test_different_device_independent_limit(): void
    {
        for ($i = 0; $i < 5; $i++) $this->processor->process(QrFactory::valid(6000 + $i, 100), $this->device);
        $this->assertInstanceOf(\WP_Error::class, $this->processor->process(QrFactory::valid(7000, 100), $this->device));
        $this->assertIsArray($this->processor->process(QrFactory::valid(7001, 100), QrFactory::device(2)));
    }
}