<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Tests\Unit;

use Boralp\Pixelite\Services\IpAnonymizer;
use PHPUnit\Framework\TestCase;

final class IpAnonymizerTest extends TestCase
{
    private IpAnonymizer $anonymizer;

    protected function setUp(): void
    {
        $this->anonymizer = new IpAnonymizer();
    }

    // ── none ──────────────────────────────────────────────────────────────────

    public function test_none_returns_ipv4_unchanged(): void
    {
        $this->assertSame('192.168.1.100', $this->anonymizer->anonymize('192.168.1.100', 'none'));
    }

    public function test_none_returns_ipv6_unchanged(): void
    {
        $ip = '2001:db8::1';
        $this->assertSame($ip, $this->anonymizer->anonymize($ip, 'none'));
    }

    public function test_none_passes_null_through(): void
    {
        $this->assertNull($this->anonymizer->anonymize(null, 'none'));
    }

    // ── partial ───────────────────────────────────────────────────────────────

    public function test_partial_masks_last_ipv4_octet(): void
    {
        $this->assertSame('10.0.0.0', $this->anonymizer->anonymize('10.0.0.1', 'partial'));
        $this->assertSame('203.0.113.0', $this->anonymizer->anonymize('203.0.113.42', 'partial'));
        $this->assertSame('255.255.255.0', $this->anonymizer->anonymize('255.255.255.1', 'partial'));
    }

    public function test_partial_keeps_first_three_ipv4_octets(): void
    {
        $result = $this->anonymizer->anonymize('192.168.10.200', 'partial');
        $this->assertStringStartsWith('192.168.10.', $result);
        $this->assertSame('192.168.10.0', $result);
    }

    public function test_partial_zeroes_ipv6_interface_identifier(): void
    {
        // 2001:db8::/64 prefix kept, interface identifier zeroed
        $result = $this->anonymizer->anonymize('2001:db8::1', 'partial');
        $this->assertNotNull($result);
        // The result must be a valid IP
        $this->assertNotFalse(filter_var($result, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));
        // Last 64 bits must be zero
        $binary = inet_pton($result);
        $this->assertSame(str_repeat("\x00", 8), substr((string) $binary, 8));
    }

    public function test_partial_returns_null_for_invalid_ip(): void
    {
        $this->assertNull($this->anonymizer->anonymize('not-an-ip', 'partial'));
    }

    // ── full ──────────────────────────────────────────────────────────────────

    public function test_full_returns_null_for_ipv4(): void
    {
        $this->assertNull($this->anonymizer->anonymize('1.2.3.4', 'full'));
    }

    public function test_full_returns_null_for_ipv6(): void
    {
        $this->assertNull($this->anonymizer->anonymize('::1', 'full'));
    }

    public function test_full_returns_null_for_null_input(): void
    {
        $this->assertNull($this->anonymizer->anonymize(null, 'full'));
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_empty_string_returns_null_for_partial(): void
    {
        $this->assertNull($this->anonymizer->anonymize('', 'partial'));
    }

    public function test_loopback_is_handled(): void
    {
        $this->assertSame('127.0.0.0', $this->anonymizer->anonymize('127.0.0.1', 'partial'));
        $this->assertNull($this->anonymizer->anonymize('127.0.0.1', 'full'));
        $this->assertSame('127.0.0.1', $this->anonymizer->anonymize('127.0.0.1', 'none'));
    }
}
