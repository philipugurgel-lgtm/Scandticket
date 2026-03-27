<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ScandTicket\Security\HmacService;

final class HmacServiceTest extends TestCase
{
    private HmacService $hmac;

    protected function setUp(): void { $this->hmac = new HmacService(); }

    public function test_sign_produces_64_hex_chars(): void
    {
        $data = ['t' => 1, 'e' => 100, 'ts' => time(), 'n' => bin2hex(random_bytes(16))];
        $sig = $this->hmac->sign($data);
        $this->assertSame(64, strlen($sig));
        $this->assertTrue(ctype_xdigit($sig));
    }

    public function test_sign_is_deterministic(): void
    {
        $data = ['t' => 1, 'e' => 100, 'ts' => 1700000000, 'n' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'];
        $this->assertSame($this->hmac->sign($data), $this->hmac->sign($data));
    }

    public function test_verify_accepts_valid(): void
    {
        $data = ['t' => 42, 'e' => 200, 'ts' => time(), 'n' => bin2hex(random_bytes(16))];
        $this->assertTrue($this->hmac->verify($data, $this->hmac->sign($data)));
    }

    public function test_verify_rejects_tampered(): void
    {
        $data = ['t' => 42, 'e' => 200, 'ts' => time(), 'n' => bin2hex(random_bytes(16))];
        $sig = $this->hmac->sign($data);
        $data['t'] = 43;
        $this->assertFalse($this->hmac->verify($data, $sig));
    }

    public function test_extra_fields_ignored(): void
    {
        $base = ['t' => 1, 'e' => 100, 'ts' => 1700000000, 'n' => str_repeat('ab', 16)];
        $extended = array_merge($base, ['admin' => true, 'extra' => 'field']);
        $this->assertSame($this->hmac->sign($base), $this->hmac->sign($extended));
    }
}