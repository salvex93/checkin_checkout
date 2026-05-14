<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IpCidrTest extends TestCase
{
    public function testIpMatchesExactCidr32(): void
    {
        $this->assertTrue(ip_in_cidr('192.168.1.1', '192.168.1.1/32'));
    }

    public function testIpMatchesClass24(): void
    {
        $this->assertTrue(ip_in_cidr('192.168.1.42', '192.168.1.0/24'));
        $this->assertFalse(ip_in_cidr('192.168.2.42', '192.168.1.0/24'));
    }

    public function testIpMatchesClass16(): void
    {
        $this->assertTrue(ip_in_cidr('10.5.123.99', '10.0.0.0/8'));
        $this->assertFalse(ip_in_cidr('11.0.0.1', '10.0.0.0/8'));
    }

    public function testSlashZeroMatchesEverything(): void
    {
        $this->assertTrue(ip_in_cidr('1.2.3.4', '0.0.0.0/0'));
    }

    public function testInvalidInputsReturnFalse(): void
    {
        $this->assertFalse(ip_in_cidr('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse(ip_in_cidr('10.0.0.1', 'not-a-cidr/8'));
        $this->assertFalse(ip_in_cidr('10.0.0.1', '10.0.0.0/99'));
    }

    public function testCidrWithoutSlashTreatedAsExact(): void
    {
        $this->assertTrue(ip_in_cidr('10.0.0.1', '10.0.0.1'));
        $this->assertFalse(ip_in_cidr('10.0.0.2', '10.0.0.1'));
    }
}
