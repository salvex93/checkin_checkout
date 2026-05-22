<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/public/api/crypto.php';

final class CryptoTest extends TestCase
{
    public function testRoundTripEncryptDecrypt(): void
    {
        $plain = 'andrew.arizmendi@meliusservices.com';
        $cipher = pii_encrypt($plain);
        $this->assertNotNull($cipher);
        $this->assertStringStartsWith('v1:', $cipher);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, pii_decrypt($cipher));
    }

    public function testEncryptionIsRandomized(): void
    {
        $plain = 'same-email@test.com';
        $c1 = pii_encrypt($plain);
        $c2 = pii_encrypt($plain);
        // GCM con IV aleatorio: dos cifrados del mismo plaintext difieren.
        $this->assertNotSame($c1, $c2);
        $this->assertSame($plain, pii_decrypt($c1));
        $this->assertSame($plain, pii_decrypt($c2));
    }

    public function testHashDeterministic(): void
    {
        $h1 = pii_hash('USER@meliusservices.com');
        $h2 = pii_hash('user@meliusservices.com');
        $h3 = pii_hash('  user@meliusservices.com  ');
        $this->assertSame($h1, $h2, 'hash debe ser case-insensitive');
        $this->assertSame($h1, $h3, 'hash debe ignorar whitespace envolvente');
        $this->assertSame(64, strlen($h1), 'sha256 hex = 64 chars');
    }

    public function testHashRejectsEmptyAndNull(): void
    {
        $this->assertNull(pii_hash(null));
        $this->assertNull(pii_hash(''));
    }

    public function testDecryptToleratesPlaintext(): void
    {
        // Durante backfill, una lectura puede encontrar valores aun no cifrados.
        // pii_decrypt debe devolverlos sin error si no tienen prefijo de version.
        $this->assertSame('plaintext@dev.com', pii_decrypt('plaintext@dev.com'));
    }

    public function testTamperingDetected(): void
    {
        $cipher = pii_encrypt('secret@test.com');
        $this->assertNotNull($cipher);
        // Corromper un byte del cipher: el tag GCM debe rechazar la autenticacion.
        $blob = base64_decode(substr($cipher, 3));
        $blob[strlen($blob) - 1] = chr((ord($blob[strlen($blob) - 1]) ^ 1) & 0xFF);
        $tampered = 'v1:' . base64_encode($blob);
        $this->expectException(RuntimeException::class);
        pii_decrypt($tampered);
    }

    public function testNullAndEmptyEncryptToSameOutput(): void
    {
        $this->assertNull(pii_encrypt(null));
        $this->assertSame('', pii_encrypt(''));
    }

    public function testUserDecryptPiiPopulatesPlainKeys(): void
    {
        $email = 'jane@meliusservices.com';
        $name = 'Jane Doe';
        $row = [
            'id' => 42,
            'email' => 'OBSOLETO',
            'name' => 'OBSOLETO',
            'email_enc' => pii_encrypt($email),
            'full_name_enc' => pii_encrypt($name),
        ];
        $out = user_decrypt_pii($row);
        $this->assertSame($email, $out['email']);
        $this->assertSame($name, $out['name']);
    }
}
