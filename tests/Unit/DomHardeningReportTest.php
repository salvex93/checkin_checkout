<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para anti_bot_dom_report().
 * Verifica que el endpoint extrae y persiste correctamente la evidencia forense.
 */
final class DomHardeningReportTest extends TestCase
{
    protected function setUp(): void
    {
        Database::pdo()->exec('DELETE FROM security_events');
        // Simular sesion sin usuario autenticado.
        if (!isset($_SESSION)) $_SESSION = [];
        unset($_SESSION['user_id']);
        // Simular IP del cliente.
        $_SERVER['REMOTE_ADDR']      = '192.168.1.99';
        $_SERVER['HTTP_USER_AGENT']  = 'Mozilla/5.0 (test)';
        $_SERVER['REQUEST_URI']      = '/test';
    }

    public function testPersistsBasicDomManipulationEvent(): void
    {
        $body = ['detail' => 'script_injection: https://evil.com/x.js'];
        anti_bot_dom_report_testable($body);

        $row = Database::pdo()
            ->query("SELECT * FROM security_events WHERE event_type='dom_manipulation' LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame('dom_manipulation', $row['event_type']);
        $this->assertStringContainsString('script_injection', $row['detail']);
        $this->assertSame('192.168.1.99', $row['ip']);
    }

    public function testPersistsActionAttemptedInEvidence(): void
    {
        $body = [
            'detail'           => 'script_injection: evil.js',
            'action_attempted' => 'inyectar_script',
            'succeeded'        => false,
            'fingerprint'      => 'Mozilla/5.0|es|1920x1080|8|America/Mexico_City|Win32',
        ];
        anti_bot_dom_report_testable($body);

        $row = Database::pdo()
            ->query("SELECT detail FROM security_events WHERE event_type='dom_manipulation' LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertStringContainsString('inyectar_script', $row['detail']);
        $this->assertStringContainsString('evidence=', $row['detail']);
    }

    public function testSucceededFlagPersistedCorrectly(): void
    {
        $body = [
            'detail'           => 'root_attr_modified: id',
            'action_attempted' => 'modificar_atributo_root',
            'succeeded'        => true,
        ];
        anti_bot_dom_report_testable($body);

        $row = Database::pdo()
            ->query("SELECT detail FROM security_events WHERE event_type='dom_manipulation' LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $evidence = json_decode(
            substr($row['detail'], strpos($row['detail'], '| evidence=') + 11),
            true
        );
        $this->assertTrue($evidence['succeeded']);
    }

    public function testSucceededFalseWhenBlocked(): void
    {
        $body = [
            'detail'    => 'script_injection: x.js',
            'succeeded' => false,
        ];
        anti_bot_dom_report_testable($body);

        $row = Database::pdo()
            ->query("SELECT detail FROM security_events WHERE event_type='dom_manipulation' LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $evidence = json_decode(
            substr($row['detail'], strpos($row['detail'], '| evidence=') + 11),
            true
        );
        $this->assertFalse($evidence['succeeded']);
    }

    public function testFingerprintStoredInEvidence(): void
    {
        $fp = 'Mozilla/5.0|es|1366x768|4|America/Mexico_City|Linux x86_64';
        $body = [
            'detail'      => 'fetch_override_detected',
            'fingerprint' => $fp,
        ];
        anti_bot_dom_report_testable($body);

        $row = Database::pdo()
            ->query("SELECT detail FROM security_events LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertStringContainsString($fp, $row['detail']);
    }

    public function testDetailTruncatedAt1000Chars(): void
    {
        $body = ['detail' => str_repeat('A', 600)];
        anti_bot_dom_report_testable($body);

        $row = Database::pdo()
            ->query("SELECT detail FROM security_events LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertLessThanOrEqual(1000, strlen($row['detail']));
    }

    public function testUserIdCapturedFromSession(): void
    {
        // Insertar usuario real para satisfacer FK
        $pdo = Database::pdo();
        $pdo->exec("INSERT OR IGNORE INTO users (id, email, name, password_hash, role, is_active, status)
                    VALUES (42, 'test42@test.local', 'Test 42', 'hash', 'consultant', 1, 'active')");

        $_SESSION['user_id'] = 42;
        $body = ['detail' => 'hidden_iframe: https://evil.com/track'];
        anti_bot_dom_report_testable($body);

        $row = $pdo->query("SELECT user_id FROM security_events WHERE event_type='dom_manipulation' ORDER BY id DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Se esperaba al menos una fila en security_events');
        $this->assertSame('42', (string)$row['user_id']);
        unset($_SESSION['user_id']);
    }

    public function testNullUserIdWhenNoSession(): void
    {
        unset($_SESSION['user_id']);
        $body = ['detail' => 'xhr_override_detected'];
        anti_bot_dom_report_testable($body);

        $row = Database::pdo()
            ->query("SELECT user_id FROM security_events LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertNull($row['user_id']);
    }
}
