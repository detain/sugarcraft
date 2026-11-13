<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;

/**
 * Functional polarity pins for the Probe\InfocmpBinary roster admission
 * rule (rulings-1024 4b cure — SSOT parity with sugar-image's
 * Renderer\ChafaBinary gate): a roster candidate is admitted only when it
 * is BOTH present and executable.
 *
 * The real class hardcodes its roster to absolute system paths, so the
 * two polarities cannot be exercised against the shipped CANDIDATES on a
 * normal box (there is no non-executable /usr/bin/infocmp to catch, and
 * making one would deface the host). Instead the shipped source is read,
 * ONLY its class name and CANDIDATES literal are rewritten, and the
 * result is required from a temp file — the gate body itself runs
 * unmodified against fabricated filesystem states. Neutering the gate
 * back to is_file() alone flips the negative arm: the present-but-
 * non-executable file would be admitted.
 */
final class InfocmpBinaryGateTest extends TestCase
{
    /** @var list<string> temp files to remove in tearDown */
    private array $cleanup = [];

    private static int $serial = 0;

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
        $this->cleanup = [];
    }

    public function testAnExecutableRosterCandidateIsAdmitted(): void
    {
        $candidate = $this->makeCandidateFile(0755);
        $this->assertTrue(is_file($candidate) && is_executable($candidate), 'fixture must be present and executable');

        $this->assertSame($candidate, $this->instantiateGateWith([$candidate]), 'a present, executable candidate must be admitted');
    }

    public function testANonExecutableRosterCandidateIsRefused(): void
    {
        $candidate = $this->makeCandidateFile(0644);
        $this->assertTrue(is_file($candidate), 'fixture must be a present file');
        $this->assertFalse(is_executable($candidate), 'fixture must NOT be executable — the refusal is about the bit, not the bytes');

        $this->assertNull(
            $this->instantiateGateWith([$candidate]),
            'a present but non-executable candidate must be refused — the gate shared with sugar-image ChafaBinary',
        );
    }

    public function testRefusalOfTheFirstCandidateStillAdmitsALaterExecutableOne(): void
    {
        $dead = $this->makeCandidateFile(0644);
        $live = $this->makeCandidateFile(0755);

        $this->assertSame(
            $live,
            $this->instantiateGateWith([$dead, $live]),
            'the roster walks past a non-executable hit instead of stopping on it',
        );
    }

    /**
     * Load the shipped gate body with a fabricated roster, under a fresh
     * class name, in a fresh file — every call gets its own memo.
     *
     * @param list<string> $candidates
     */
    private function instantiateGateWith(array $candidates): ?string
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Probe/InfocmpBinary.php');

        $name = 'InfocmpBinaryUnderProbe_' . ++self::$serial;
        $quoted = '[' . implode(', ', array_map(
            static fn (string $p): string => "'" . addslashes($p) . "'",
            $candidates,
        )) . ']';
        $code = strtr($src, ['final class InfocmpBinary' => 'final class ' . $name]);
        // Anchor on the declaration SHAPE, not its value: the roster is a
        // documented security surface that legitimately grows (CP-38), and
        // a value-literal rewrite anchor would silently stop applying the
        // day a seat is added — the vacuity this harness itself refuses.
        $rewritten = (string) preg_replace_callback(
            '/private const CANDIDATES = \[[^\]]*\];/s',
            static fn (array $m): string => 'private const CANDIDATES = ' . $quoted . ';',
            $code,
            1,
            $applied,
        );
        $this->assertSame(1, $applied, 'roster rewrite anchor must match the shipped const');
        $code = $rewritten;

        // An unapplied rewrite would run the REAL roster and make every
        // assertion below vacuous — fail loudly instead.
        $this->assertStringNotContainsString('/usr/bin/infocmp', $code, 'roster rewrite must apply');
        $this->assertStringContainsString('final class ' . $name, $code, 'class rename must apply');

        $file = sys_get_temp_dir() . '/infocmp-gate-' . $name . '.php';
        file_put_contents($file, $code);
        $this->cleanup[] = $file;

        require_once $file;
        $class = 'SugarCraft\\ColorProfile\\Probe\\' . $name;
        $this->assertTrue(class_exists($class));

        return $class::path();
    }

    private function makeCandidateFile(int $mode): string
    {
        $file = tempnam(sys_get_temp_dir(), 'infocmp-cand-');
        $this->assertIsString($file);
        $this->cleanup[] = $file;
        file_put_contents($file, "#!/bin/sh\nexit 0\n");
        chmod($file, $mode);

        return $file;
    }

    /**
     * CP-38 drift-free roster matrix: the shipped CANDIDATES literal itself
     * is the fixture (read from source — the private const cannot be
     * reflected, and re-hardcoding it here would defeat the pin). Legacy
     * seats first, published alternates (NixOS, FreeBSD/usr-local, Termux)
     * appended, every entry absolute — the no-PATH-scanning invariant the
     * class docblock claims is asserted, not narrated.
     */
    public function testTheShippedRosterIsOrderedAbsoluteAndCoversThePublishedLayouts(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Probe/InfocmpBinary.php'
        );
        $this->assertSame(1, preg_match(
            "/private const CANDIDATES = (\[[^\]]*\]);/s",
            $source,
            $match,
        ), 'the CANDIDATES literal drifted out of the shape this pin reads');

        /** @var list<string> $candidates */
        $candidates = eval('return ' . $match[1] . ';');

        $this->assertSame(['/usr/bin/infocmp', '/bin/infocmp'], array_slice($candidates, 0, 2),
            'legacy hot-path seats must stay first, in order');
        $this->assertContains('/run/current-system/sw/bin/infocmp', $candidates, 'NixOS system profile');
        $this->assertContains('/usr/local/bin/infocmp', $candidates, 'FreeBSD standard location / Nix multi-user');
        $this->assertContains('/data/data/com.termux/files/usr/bin/infocmp', $candidates, 'Termux prefix');
        foreach ($candidates as $path) {
            $this->assertSame('/', $path[0], 'roster is absolute-only — never PATH-derived');
        }
    }
}
