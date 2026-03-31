<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ScandTicket\Security\InputValidator;
use ScandTicket\Security\QrPayload;
use ScandTicket\Tests\Helpers\QrFactory;

final class InputValidatorTest extends TestCase
{
    private InputValidator $validator;

    protected function setUp(): void { $this->validator = new InputValidator(); }

    public function test_valid_payload_returns_qr_payload(): void
    {
        $raw = QrFactory::valid(ticketId: 42, eventId: 200);
        $result = $this->validator->parseQrPayload($raw);
        $this->assertInstanceOf(QrPayload::class, $result);
        $this->assertSame(42, $result->ticketId);
        $this->assertSame(200, $result->eventId);
        $this->assertSame(64, strlen($result->signature));
    }

    public function test_extra_fields_rejected(): void
    {
        $result = $this->validator->parseQrPayload(QrFactory::withExtraFields());
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('payload_extra_fields', $result->get_error_code());
    }

    /** @dataProvider missingFieldProvider */
    public function test_missing_field_rejected(string $field): void
    {
        $result = $this->validator->parseQrPayload(QrFactory::missingField($field));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('payload_missing_field', $result->get_error_code());
    }

    public static function missingFieldProvider(): array { return [['t'], ['e'], ['ts'], ['n'], ['h']]; }

    public function test_negative_ticket_id_rejected(): void
    {
        $raw = QrFactory::valid(); $raw['t'] = -5;
        $result = $this->validator->parseQrPayload($raw);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_ticket_id', $result->get_error_code());
    }

    public function test_expired_timestamp_rejected(): void
    {
        $result = $this->validator->parseQrPayload(QrFactory::expiredTimestamp(300));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('timestamp_expired', $result->get_error_code());
    }

    public function test_short_nonce_rejected(): void
    {
        $raw = QrFactory::valid(); $raw['n'] = 'abc123';
        $result = $this->validator->parseQrPayload($raw);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_nonce', $result->get_error_code());
    }

    public function test_non_hex_signature_rejected(): void
    {
        $raw = QrFactory::valid(); $raw['h'] = str_repeat('z', 64);
        $result = $this->validator->parseQrPayload($raw);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    public function test_batch_validation_empty(): void
    {
        $result = $this->validator->validateBatchScans([]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('batch_empty', $result->get_error_code());
    }
}