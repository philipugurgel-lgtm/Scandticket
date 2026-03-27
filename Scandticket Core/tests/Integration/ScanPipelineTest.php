<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Integration;

use WP_UnitTestCase;
use ScandTicket\Core\Container;
use ScandTicket\Scanner\ScanProcessor;
use ScandTicket\Tests\Helpers\QrFactory;
use ScandTicket\Tests\Helpers\RedisHelper;

final class ScanPipelineTest extends WP_UnitTestCase
{
    private ScanProcessor $processor;
    private array $device;

    protected function setUp(): void
    {
        parent::setUp();
        RedisHelper::flush();
        $this->processor = Container::instance()->make(ScanProcessor::class);
        $this->device = QrFactory::device(1);
    }

    protected function tearDown(): void { RedisHelper::flush(); parent::tearDown(); }

    public function test_valid_scan_accepted(): void
    {
        $result = $this->processor->process(QrFactory::valid(1, 100), $this->device);
        $this->assertIsArray($result);
        $this->assertSame('accepted', $result['status']);
        $this->assertArrayHasKey('job_id', $result);
    }

    public function test_replay_same_nonce_rejected(): void
    {
        $pair = QrFactory::replayPair(10, 100);
        $this->assertIsArray($this->processor->process($pair['original'], $this->device));
        $result2 = $this->processor->process($pair['replay'], $this->device);
        $this->assertInstanceOf(\WP_Error::class, $result2);
        $this->assertSame('nonce_replayed', $result2->get_error_code());
        $this->assertSame(409, $result2->get_error_data()['status']);
        $this->assertFalse($result2->get_error_data()['retry']);
    }

    public function test_nonce_not_consumed_on_hmac_failure(): void
    {
        $valid = QrFactory::valid(5, 100); $nonce = $valid['n']; $valid['h'] = str_repeat('0', 64);
        $result = $this->processor->process($valid, $this->device);
        $this->assertSame('hmac_failed', $result->get_error_code());
        $this->assertFalse(RedisHelper::nonceExists($nonce));
    }

    public function test_same_ticket_same_device_duplicate(): void
    {
        $this->assertIsArray($this->processor->process(QrFactory::valid(42, 100), $this->device));
        $result2 = $this->processor->process(QrFactory::valid(42, 100), $this->device);
        $this->assertInstanceOf(\WP_Error::class, $result2);
        $this->assertSame('duplicate_scan', $result2->get_error_code());
    }

    public function test_same_ticket_different_event_allowed(): void
    {
        $this->assertIsArray($this->processor->process(QrFactory::valid(42, 100), $this->device));
        $this->assertIsArray($this->processor->process(QrFactory::valid(42, 200), $this->device));
    }

    public function test_different_tickets_same_event_allowed(): void
    {
        $this->assertIsArray($this->processor->process(QrFactory::valid(1, 100), $this->device));
        $this->assertIsArray($this->processor->process(QrFactory::valid(2, 100), $this->device));
    }

    public function test_tampered_hmac_rejected(): void
    {
        $result = $this->processor->process(QrFactory::tamperedSignature(), $this->device);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('hmac_failed', $result->get_error_code());
    }

    public function test_extra_fields_rejected(): void
    {
        $result = $this->processor->process(QrFactory::withExtraFields(), $this->device);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('payload_extra_fields', $result->get_error_code());
    }

    public function test_multi_device_with_high_weight_blocks(): void
    {
        update_option('scandticket_fraud_weights', json_encode(['duplicate' => 0.0, 'rapid' => 0.0, 'multi_device' => 1.0, 'timestamp' => 0.0]));
        update_option('scandticket_fraud_threshold', 0.7);
        \ScandTicket\Fraud\FraudWeights::reset();

        $this->processor->process(QrFactory::valid(99, 100), QrFactory::device(1));
        $qr2 = QrFactory::valid(99, 100);
        $result2 = $this->processor->process($qr2, QrFactory::device(2));
        $this->assertInstanceOf(\WP_Error::class, $result2);
        $this->assertSame('fraud_detected', $result2->get_error_code());
        $this->assertFalse(RedisHelper::nonceExists($qr2['n']));

        delete_option('scandticket_fraud_weights'); delete_option('scandticket_fraud_threshold');
        \ScandTicket\Fraud\FraudWeights::reset();
    }

    public function test_batch_mixed_results(): void
    {
        $scans = [QrFactory::valid(1, 100), QrFactory::tamperedSignature(), QrFactory::valid(2, 100)];
        $results = $this->processor->processBatch($scans, $this->device);
        $this->assertCount(3, $results);
        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('hmac_failed', $results[1]['code']);
        $this->assertSame('accepted', $results[2]['status']);
    }
}