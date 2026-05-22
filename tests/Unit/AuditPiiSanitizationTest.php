<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuditPiiSanitizationTest extends TestCase
{
    public function testMasksEmailInTopLevelString(): void
    {
        $clean = sanitize_pii_for_audit(['email' => 'john.doe@meliusservices.com', 'event' => 'x']);
        $this->assertNotSame('john.doe@meliusservices.com', $clean['email']);
        $this->assertStringEndsWith('@meliusservices.com', $clean['email']);
        $this->assertSame('x', $clean['event']);
    }

    public function testMasksEmailDeepInArray(): void
    {
        $clean = sanitize_pii_for_audit([
            'context' => [
                'user' => ['email' => 'a@b.com', 'name' => 'Anna'],
            ],
        ]);
        $this->assertNotSame('a@b.com', $clean['context']['user']['email']);
        $this->assertStringEndsWith('@b.com', $clean['context']['user']['email']);
        $this->assertSame('Anna', $clean['context']['user']['name']);
    }

    public function testKeepsNonEmailStringsUntouched(): void
    {
        $clean = sanitize_pii_for_audit([
            'event' => 'login_failed',
            'reason' => 'invalid_credentials',
            'remaining_sec' => 600,
        ]);
        $this->assertSame('login_failed', $clean['event']);
        $this->assertSame('invalid_credentials', $clean['reason']);
        $this->assertSame(600, $clean['remaining_sec']);
    }
}
