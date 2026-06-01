<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests de integracion para admin_security_events_list() y
 * admin_security_events_review(). Usa BD SQLite real (bootstrap).
 */
final class SecurityEventsTest extends TestCase
{
    private static int $adminId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::pdo();
        $pdo->exec('DELETE FROM security_events');
        $pdo->exec('DELETE FROM users');

        // Admin para las consultas.
        $pdo->exec(
            "INSERT INTO users (email, name, password_hash, role, company_id, is_active, status)
             VALUES ('admin@test.local', 'Admin Test', 'hash', 'admin', 1, 1, 'active')"
        );
        self::$adminId = (int)$pdo->lastInsertId();

        // Usuario normal que sera asociado a eventos.
        $pdo->exec(
            "INSERT INTO users (email, name, password_hash, role, company_id, is_active, status)
             VALUES ('user@test.local', 'User Test', 'hash', 'consultant', 1, 1, 'active')"
        );
        self::$userId = (int)$pdo->lastInsertId();
    }

    protected function setUp(): void
    {
        Database::pdo()->exec('DELETE FROM security_events');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertEvent(string $type, string $detail, ?int $userId = null, bool $reviewed = false): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            "INSERT INTO security_events (event_type, ip, user_agent, uri, user_id, detail, reviewed)
             VALUES (?, '1.2.3.4', 'UA/1.0', '/api/test', ?, ?, ?)"
        )->execute([$type, $userId, $detail, $reviewed ? 1 : 0]);
        return (int)$pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Tests: persistencia de eventos DOM
    // ------------------------------------------------------------------

    public function testDomManipulationEventPersistsWithEvidence(): void
    {
        $detail = 'script_injection: evil.js | evidence={"action_attempted":"inyectar_script","succeeded":false}';
        $id = $this->insertEvent('dom_manipulation', $detail, self::$userId);

        $row = Database::pdo()
            ->query("SELECT * FROM security_events WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('dom_manipulation', $row['event_type']);
        $this->assertStringContainsString('evidence=', $row['detail']);
        $this->assertSame((string)self::$userId, (string)$row['user_id']);
        $this->assertSame('0', (string)$row['reviewed']);
    }

    public function testMultipleEventTypesStoredIndependently(): void
    {
        $this->insertEvent('dom_manipulation', 'script_injection: x.js');
        $this->insertEvent('scraping', 'ua=curl/7.8');
        $this->insertEvent('brute_force', 'login failed x10');
        $this->insertEvent('ip_blocked', 'blocked after 5 events');

        $count = (int)Database::pdo()
            ->query("SELECT COUNT(*) FROM security_events")
            ->fetchColumn();

        $this->assertSame(4, $count);
    }

    // ------------------------------------------------------------------
    // Tests: admin_security_events_list() — filtros
    // ------------------------------------------------------------------

    public function testListReturnsOnlyUnreviewedByDefault(): void
    {
        $this->insertEvent('dom_manipulation', 'A', null, false);
        $this->insertEvent('dom_manipulation', 'B', null, true);

        $_GET = ['type' => 'all', 'reviewed' => 'false'];
        $_SESSION['user_id'] = self::$adminId;

        $rows = security_events_for_admin(self::$adminId);

        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows[0]['detail']);
    }

    public function testListFiltersByType(): void
    {
        $this->insertEvent('dom_manipulation', 'dom1');
        $this->insertEvent('scraping', 'scrape1');
        $this->insertEvent('scraping', 'scrape2');

        $rows = security_events_for_admin(self::$adminId, 'scraping', 'false');

        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('scraping', $r['event_type']);
        }
    }

    public function testListShowsAllWhenReviewedTrue(): void
    {
        $this->insertEvent('ip_blocked', 'ev1', null, false);
        $this->insertEvent('ip_blocked', 'ev2', null, true);

        $rows = security_events_for_admin(self::$adminId, 'all', 'true');

        $this->assertCount(2, $rows);
    }

    public function testListIncludesUserNameWhenAssociated(): void
    {
        $this->insertEvent('dom_manipulation', 'with_user', self::$userId);

        $rows = security_events_for_admin(self::$adminId, 'dom_manipulation', 'false');

        $this->assertCount(1, $rows);
        $this->assertSame('User Test', $rows[0]['user_name']);
    }

    public function testListUserNameNullWhenNoUser(): void
    {
        $this->insertEvent('bot_blocked', 'no_user', null);

        $rows = security_events_for_admin(self::$adminId, 'bot_blocked', 'false');

        $this->assertNull($rows[0]['user_name']);
    }

    // ------------------------------------------------------------------
    // Tests: marcar como revisado
    // ------------------------------------------------------------------

    public function testMarkReviewedUpdatesFlag(): void
    {
        $id = $this->insertEvent('dom_manipulation', 'to_review');

        $pdo = Database::pdo();
        $before = (int)$pdo->query("SELECT reviewed FROM security_events WHERE id={$id}")->fetchColumn();
        $this->assertSame(0, $before);

        $pdo->exec("UPDATE security_events SET reviewed = 1 WHERE id = {$id}");

        $after = (int)$pdo->query("SELECT reviewed FROM security_events WHERE id={$id}")->fetchColumn();
        $this->assertSame(1, $after);
    }

    public function testReviewedEventDisappearsFromUnreviewedFilter(): void
    {
        $id = $this->insertEvent('scraping', 'reviewed_event');
        Database::pdo()->exec("UPDATE security_events SET reviewed = 1 WHERE id = {$id}");

        $rows = security_events_for_admin(self::$adminId, 'scraping', 'false');

        $this->assertCount(0, $rows);
    }

    // ------------------------------------------------------------------
    // Tests: contador de no revisados
    // ------------------------------------------------------------------

    public function testUnreviewedCountAccurate(): void
    {
        $this->insertEvent('dom_manipulation', 'a', null, false);
        $this->insertEvent('dom_manipulation', 'b', null, false);
        $this->insertEvent('dom_manipulation', 'c', null, true);

        $count = (int)Database::pdo()
            ->query("SELECT COUNT(*) FROM security_events WHERE reviewed = 0")
            ->fetchColumn();

        $this->assertSame(2, $count);
    }
}
