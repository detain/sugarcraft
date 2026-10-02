<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ToolResult as BootProbe;
use SugarCraft\Crush\Tools\BuiltIn\Doctor;
use SugarCraft\Mosaic\Detect;
use SugarCraft\Mosaic\Mosaic;

/**
 * Audit F-T6: `doctor` reports the boot-time probe and never queries the tty.
 *
 * The tool runs inside the forked turn child, which shares the TUI's tty
 * descriptors; a probe from there writes DA1/XTWINOPS into the parent's frame
 * and drains stdin the parent is reading. So the contract pinned here is
 * two-sided: the answer is {@see BootProbe::mosaic()}'s, and the probe's stdin
 * is left untouched.
 *
 * WHY THE BOOT CACHE IS SEEDED BY REFLECTION. The pre-fix Doctor kept a static
 * of its own, which an earlier test in the same process may already have
 * filled — a tripwire alone would then pass against the bug. Seeding the boot
 * cache with quarter-block, a renderer `Mosaic::auto()` never selects, makes
 * the protocol assertion fail against any implementation that answers from a
 * probe of its own, whatever ran first.
 *
 * @see Doctor
 */
final class DoctorBootProbeTest extends TestCase
{
    /** Bytes a probe would consume: a sixel-capable DA1 reply. */
    private const PRELOADED = "\x1b[?62;4;0c";

    private ?Mosaic $savedBootProbe = null;

    /** @var array{0: resource, 1: resource}|null */
    private ?array $pair = null;

    protected function setUp(): void
    {
        $this->savedBootProbe = $this->bootCache()->getValue();
        $this->bootCache()->setValue(null, Mosaic::quarterBlock());
        Detect::reset();

        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        $this->pair = $pair;
        fwrite($pair[1], self::PRELOADED);
        Detect::setProbeStdin($pair[0]);
    }

    protected function tearDown(): void
    {
        Detect::setProbeStdin(null);
        Detect::reset();
        $this->bootCache()->setValue(null, $this->savedBootProbe);
        foreach ($this->pair ?? [] as $end) {
            fclose($end);
        }
        $this->pair = null;
    }

    public function testReportsTheBootProbeWithoutTouchingTheTty(): void
    {
        $result = (new Doctor())->execute(['id' => 'call_doctor']);

        self::assertSame('quarterblock', BootProbe::mosaic()->protocol());
        self::assertSame(BootProbe::mosaic()->protocol(), $result->imageProtocol());
        self::assertStringContainsString("'quarterblock'", $result->content());
        self::assertNull(Detect::lastDa1Reply(), 'doctor sent a DA1 query from the tool');
        self::assertSame(self::PRELOADED, $this->unreadProbeInput(), 'doctor drained the probe stdin');
    }

    public function testRepeatedCallsStillNeverProbe(): void
    {
        $doctor = new Doctor();
        $doctor->execute([]);
        $second = $doctor->execute([]);

        self::assertSame('quarterblock', $second->imageProtocol());
        self::assertSame(self::PRELOADED, $this->unreadProbeInput());
    }

    private function unreadProbeInput(): string
    {
        self::assertNotNull($this->pair);
        stream_set_blocking($this->pair[0], false);

        return (string) fread($this->pair[0], 8192);
    }

    private function bootCache(): \ReflectionProperty
    {
        return new \ReflectionProperty(BootProbe::class, 'mosaic');
    }
}
