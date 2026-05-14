<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../public/api/tenant.php';
require_once __DIR__ . '/../../public/api/billing.php';

final class TenantBillingTest extends TestCase
{
    public function testTenantLoadReturnsSeededValues(): void
    {
        $t = tenant_load();
        $this->assertSame('Test Product', $t['product_name']);
        $this->assertSame('#123456', $t['primary_color']);
        $this->assertSame('#abcdef', $t['secondary_color']);
    }

    public function testTenantLoadReturnsDefaultsWhenRowMissing(): void
    {
        $pdo = Database::pdo();
        $pdo->exec('DELETE FROM tenant_settings WHERE id = 1');
        try {
            $t = tenant_load();
            $this->assertSame('Melius Clockin', $t['product_name']);
            $this->assertSame('#07d6da', $t['primary_color']);
            $this->assertNull($t['logo_url']);
        } finally {
            // Restaurar para no afectar tests siguientes.
            $pdo->exec("INSERT INTO tenant_settings (id, product_name, primary_color, secondary_color)
                        VALUES (1, 'Test Product', '#123456', '#abcdef')");
        }
    }

    public function testBillingLoadFallsBackWhenNoSubscriptionRow(): void
    {
        // Sin row → defaults sin crashear.
        $b = billing_load();
        $this->assertSame('free', $b['plan_code']);
        $this->assertSame('trial', $b['status']);
        $this->assertFalse($b['connected']);
    }

    public function testBillingLoadHydratesPlanJoin(): void
    {
        $pdo = Database::pdo();
        $pdo->exec("INSERT OR IGNORE INTO subscription_plans (code, name, price_monthly_cents, currency)
                    VALUES ('pro', 'Pro', 9900, 'USD')");
        // INSERT OR REPLACE para limpiar fila preexistente del bootstrap implicito.
        $pdo->exec("INSERT OR REPLACE INTO subscriptions (id, plan_code, provider, status)
                    VALUES (1, 'pro', 'manual', 'active')");
        try {
            $b = billing_load();
            $this->assertSame('pro', $b['plan_code']);
            $this->assertSame('Pro', $b['plan_name']);
            $this->assertSame(9900, $b['price_monthly_cents']);
            $this->assertSame('active', $b['status']);
            $this->assertSame('manual', $b['provider']);
        } finally {
            $pdo->exec('DELETE FROM subscriptions WHERE id = 1');
        }
    }
}
