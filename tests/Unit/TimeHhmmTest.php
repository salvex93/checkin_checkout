<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// La funcion time_hhmm_to_minutes vive en dashboard.php. La cargamos
// directamente para testearla aislada.
require_once __DIR__ . '/../../public/api/dashboard.php';

final class TimeHhmmTest extends TestCase
{
    public function testValidHourReturnsMinutes(): void
    {
        $this->assertSame(0, time_hhmm_to_minutes('00:00'));
        $this->assertSame(540, time_hhmm_to_minutes('09:00'));
        $this->assertSame(1080, time_hhmm_to_minutes('18:00'));
    }

    public function testWithSeconds(): void
    {
        // El regex acepta HH:MM ignorando segundos posteriores.
        $this->assertSame(540, time_hhmm_to_minutes('09:00:30'));
    }

    public function testSingleDigitHour(): void
    {
        $this->assertSame(540, time_hhmm_to_minutes('9:00'));
    }

    public function testInvalidReturnsNull(): void
    {
        $this->assertNull(time_hhmm_to_minutes('invalid'));
        $this->assertNull(time_hhmm_to_minutes(''));
        $this->assertNull(time_hhmm_to_minutes('25-99'));
    }
}
