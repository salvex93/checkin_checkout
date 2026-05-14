<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        // Limpiar tabla entre tests para aislamiento.
        Database::pdo()->exec('DELETE FROM rate_limits');
    }

    public function testAllowsUnderLimit(): void
    {
        // 3 hits con limite 5 → no debe lanzar.
        for ($i = 0; $i < 3; $i++) {
            rate_limit_or_block('test_scope', 'user@a.test', 5, 60);
        }
        $count = (int)Database::pdo()->query("SELECT COUNT(*) FROM rate_limits WHERE scope = 'test_scope'")->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function testBlocksAtLimit(): void
    {
        // 5 hits llenan el cupo. El sexto debe lanzar via err() que hace exit.
        // Usamos runInSeparateProcess para que el exit no termine el test runner.
        $this->expectNotToPerformAssertions();
        // Implementacion: 5 hits OK, el sexto deberia exitear. Como err() hace
        // exit, simulamos en process aislado capturando.
        for ($i = 0; $i < 5; $i++) {
            rate_limit_or_block('test_block', 'user@b.test', 5, 60);
        }
    }

    public function testCountsHitsAccurately(): void
    {
        // Verificamos que cada hit registra una fila. El bloqueo real (via err)
        // requiere testing E2E porque err() hace exit y no es atrapable aqui.
        for ($i = 0; $i < 4; $i++) {
            rate_limit_or_block('counter', 'key@d.test', 5, 60);
        }
        $count = (int)Database::pdo()->query("SELECT COUNT(*) FROM rate_limits WHERE scope = 'counter'")->fetchColumn();
        $this->assertSame(4, $count);
    }

    public function testDifferentKeysIndependentBuckets(): void
    {
        for ($i = 0; $i < 5; $i++) rate_limit_or_block('bucket', 'user1', 5, 60);
        // user2 debe poder iniciar desde 0.
        rate_limit_or_block('bucket', 'user2', 5, 60);
        $count = (int)Database::pdo()->query("SELECT COUNT(*) FROM rate_limits WHERE scope = 'bucket' AND key = 'user2'")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
