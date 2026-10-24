<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;
use SugarCraft\Image\Renderer\ChafaBinary;
use SugarCraft\Image\Renderer\ChafaRenderer;

/**
 * Binary-discovery hardening pins (rulings-1024 4b, InfocmpBinary
 * precedent): the chafa tier executes ONLY an absolute roster hit or an
 * explicitly-armed {@see ChafaBinary::ENV_OVERRIDE}; a rogue `chafa`
 * that merely sits on PATH is refused and never executed — proven with
 * an execution marker the fake would touch if it ever ran.
 *
 * Both gates tell the same story: PATH is never consulted implicitly;
 * a refused override never silently falls back (no substitution); and
 * when nothing resolves, the degradation is the exact missing-binary
 * shape the lib has always taken.
 *
 * @covers \SugarCraft\Image\Renderer\ChafaBinary
 */
final class ChafaBinaryTest extends TestCase
{
    /** @var list<string> temp dirs to remove in tearDown */
    private array $cleanup = [];

    private string|false $overrideBackup = false;

    private string|false $pathBackup = false;

    protected function setUp(): void
    {
        $this->overrideBackup = getenv(ChafaBinary::ENV_OVERRIDE);
        $this->pathBackup = getenv('PATH');
        putenv(ChafaBinary::ENV_OVERRIDE);
        ChafaBinary::reset();
        ChafaRenderer::reset();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv(ChafaBinary::ENV_OVERRIDE, $this->overrideBackup);
        $this->restoreEnv('PATH', $this->pathBackup);
        ChafaBinary::reset();
        ChafaRenderer::reset();
        foreach ($this->cleanup as $dir) {
            foreach ((array) scandir($dir) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
        $this->cleanup = [];
    }

    private function restoreEnv(string $key, string|false $backup): void
    {
        if ($backup === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $backup);
        }
    }

    // ------------------------------------------------------------------
    // Positive: the override is honored, both accepted shapes.
    // ------------------------------------------------------------------

    public function testAbsoluteOverrideIsHonored(): void
    {
        $fake = $this->makeFakeChafa();

        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $fake);

        $this->assertSame($fake, ChafaBinary::path());
        $this->assertTrue(ChafaRenderer::available());
    }

    public function testBareNameOverrideConsultsPathByExplicitNaming(): void
    {
        $fake = $this->makeFakeChafa();
        $dir = dirname($fake);

        // PATH deliberately lacks the dir until named — the lookup is
        // authorised by the bare-name override, nothing else.
        $orig = (string) getenv('PATH');
        putenv('PATH=' . $dir . PATH_SEPARATOR . $orig);
        putenv(ChafaBinary::ENV_OVERRIDE . '=chafa');

        $this->assertSame($dir . '/chafa', ChafaBinary::path());
    }

    // ------------------------------------------------------------------
    // Negative: the rogue-PATH door, with an execution marker witness.
    // ------------------------------------------------------------------

    public function testRogueChafaOnPathIsNeverExecutedWithoutAnOverride(): void
    {
        $marker = $this->makeMarkerWitness();
        $rogue = $this->makeFakeChafa('chafa', $marker);
        $dir = dirname($rogue);

        $orig = (string) getenv('PATH');
        // The classic hijack shape: rogue FIRST on PATH.
        putenv('PATH=' . $dir . PATH_SEPARATOR . $orig);
        // No override armed.
        $this->assertFalse(getenv(ChafaBinary::ENV_OVERRIDE));

        // available() must answer from the roster alone, never the rogue.
        ChafaRenderer::reset();
        $rosterHit = null;
        foreach (ChafaBinary::rosterCandidates() as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $rosterHit = $candidate;
                break;
            }
        }
        $this->assertSame($rosterHit !== null, ChafaRenderer::available());
        $this->assertNotSame($rogue, ChafaBinary::path());

        // The render path must not reach the rogue either: with no
        // roster binary it degrades to the exact missing-binary
        // exception; with a real roster chafa it may render, but the
        // marker witness below settles which binary ran.
        if ($rosterHit === null) {
            // expectException would abort before the marker assertion,
            // so capture and assert the degradation shape inline.
            try {
                (new ChafaRenderer())->render(ImageSource::fromString((string) base64_decode(
                    'R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw=='
                )), 10, 10);
                $this->fail('an unresolved binary must degrade exactly like a missing one');
            } catch (\RuntimeException $failure) {
                $this->assertSame(Lang::t('chafa.not_found'), $failure->getMessage());
            }
        }

        clearstatcache();
        $this->assertFileDoesNotExist($marker, 'the rogue PATH chafa must never be executed');
    }

    public function testRelativePathEntriesAreSkippedEvenForABareOverride(): void
    {
        $dir = $this->tempDir();
        $outer = $dir . '/outer';
        mkdir($outer . '/bin', 0700, true);
        $this->writeFake($outer . '/bin/chafa');

        $cwd = getcwd();
        chdir($outer);
        try {
            // `bin` resolves relative to cwd — the door a dot-entry
            // hijack rides in on. Refused even with explicit naming.
            putenv('PATH=bin');
            putenv(ChafaBinary::ENV_OVERRIDE . '=chafa');

            $this->assertNull(ChafaBinary::path());
        } finally {
            chdir($cwd !== false ? $cwd : '/');
        }
    }

    // ------------------------------------------------------------------
    // Refusal discipline: a refused override never substitutes.
    // ------------------------------------------------------------------

    public function testRefusedAbsoluteOverrideNeverFallsBack(): void
    {
        $fake = $this->makeFakeChafa();
        $dir = dirname($fake);
        $orig = (string) getenv('PATH');
        putenv('PATH=' . $dir . PATH_SEPARATOR . $orig);

        // Broken override naming a non-existent absolute path: refused
        // outright — no PATH fallback to the working fake, no roster
        // fallback to a different binary. A misconfiguration stays loud
        // (degradation, never a silent substitution).
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $dir . '/no-such-chafa');

        $this->assertNull(ChafaBinary::path());
        $this->assertFalse(ChafaRenderer::available());
    }

    /**
     * STRUCTURAL no-fallback pin. The functional test above is host-
     * conditional: on a roster-less box (no chafa at any CANDIDATES path)
     * a mutated refusal that FELL THROUGH to the roster loop would still
     * answer null, and the substitution defect survives green. This pin
     * reads the shape of resolve()'s body instead of its answer, so the
     * law "a refused absolute override returns before any roster walk"
     * reddens on every host: the refusal is one return statement ending
     * in a literal `: null;`, and between it and the roster `foreach`
     * only the override guard's closing brace may stand.
     */
    public function testTheRefusedOverrideStructurallyCannotReachTheRosterLoop(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Renderer/ChafaBinary.php');

        $refusal = '/return str_starts_with\(\$override, \'\/\'\) && self::usable\(\$override\)\s*\?\s*\$override\s*:\s*null;/';
        $this->assertSame(
            1,
            preg_match($refusal, $src, $m, PREG_OFFSET_CAPTURE),
            'the refused absolute override must be a single return ending in a literal null — never a fall-through to the roster',
        );
        $refusalEnd = $m[0][1] + \strlen($m[0][0]);

        $roster = strpos($src, 'foreach (self::CANDIDATES');
        $this->assertIsInt($roster, 'the roster walk must still exist after the override guard');
        $this->assertLessThan(
            $roster,
            $refusalEnd,
            'the refusal return must precede the roster foreach — control reaches CANDIDATES only when no override was set',
        );
        $this->assertMatchesRegularExpression(
            '/^\s*\}\s*$/',
            substr($src, $refusalEnd, $roster - $refusalEnd),
            'nothing may be wired between the refusal return and the roster walk — the early return is the arm\'s last word',
        );
    }

    public function testRelativePathOverrideIsRefused(): void
    {
        putenv(ChafaBinary::ENV_OVERRIDE . '=./chafa');

        $this->assertNull(ChafaBinary::path());
    }

    public function testNonExecutableOverrideIsRefused(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/chafa';
        file_put_contents($file, "#!/bin/sh\nexit 0\n");
        chmod($file, 0644); // a file, but NOT executable

        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $file);

        $this->assertNull(ChafaBinary::path());
    }

    public function testDirectoryOverrideIsRefused(): void
    {
        $dir = $this->tempDir();

        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $dir);

        $this->assertNull(ChafaBinary::path());
    }

    public function testEmptyOverrideIsTreatedAsUnset(): void
    {
        $fake = $this->makeFakeChafa();
        $orig = (string) getenv('PATH');
        putenv('PATH=' . dirname($fake) . PATH_SEPARATOR . $orig);
        putenv(ChafaBinary::ENV_OVERRIDE . '=');

        // Empty string must not authorise the PATH lookup; the answer
        // is roster-only (null on a chafa-less host).
        $rosterHit = null;
        foreach (ChafaBinary::rosterCandidates() as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $rosterHit = $candidate;
                break;
            }
        }
        $this->assertSame($rosterHit, ChafaBinary::path());
    }

    // ------------------------------------------------------------------
    // Roster shape + memoisation.
    // ------------------------------------------------------------------

    public function testRosterCandidateShapeIsFrozen(): void
    {
        $this->assertSame(
            ['/usr/bin/chafa', '/usr/local/bin/chafa', '/opt/homebrew/bin/chafa'],
            ChafaBinary::rosterCandidates(),
        );
        foreach (ChafaBinary::rosterCandidates() as $candidate) {
            $this->assertStringStartsWith('/', $candidate);
            $this->assertSame('chafa', basename($candidate));
        }
    }

    public function testAnswerIsMemoisedUntilReset(): void
    {
        $fakeA = $this->makeFakeChafa();
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $fakeA);
        $this->assertSame($fakeA, ChafaBinary::path());

        // The filesystem layout seen at first call is the layout every
        // later call reports — even when the env moves underneath.
        $fakeB = $this->makeFakeChafa();
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $fakeB);
        $this->assertSame($fakeA, ChafaBinary::path());

        ChafaBinary::reset();
        $this->assertSame($fakeB, ChafaBinary::path());
    }

    public function testRendererResetClearsTheBinaryMemoToo(): void
    {
        $fake = $this->makeFakeChafa();
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $fake);
        $this->assertTrue(ChafaRenderer::available());

        $other = $this->makeFakeChafa();
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $other);
        // Renderer memo AND binary memo both still hold the first answer.
        $this->assertTrue(ChafaRenderer::available());

        ChafaRenderer::reset();
        $this->assertSame($other, ChafaBinary::path());
    }

    // ------------------------------------------------------------------

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/chafa-binary-' . getmypid() . '-' . uniqid();
        mkdir($dir, 0700);
        $this->cleanup[] = $dir;

        return $dir;
    }

    /**
     * @return string absolute path to an executable fake chafa (exit 0,
     *                echoing a --version line; any other call prints
     *                mock bytes)
     */
    private function makeFakeChafa(string $name = 'chafa', ?string $marker = null): string
    {
        $dir = $this->tempDir();
        $file = $dir . '/' . $name;
        $this->writeFake($file, $marker);

        return $file;
    }

    private function writeFake(string $file, ?string $marker = null): void
    {
        $touch = $marker === null ? '' : 'touch ' . escapeshellarg($marker) . "\n";
        $script = "#!/bin/sh\n{$touch}if [ \"\$1\" = \"--version\" ]; then echo \"chafa 99.9 (sugar-image gate fake)\"; exit 0; fi\nprintf 'FAKE-OUT'\nexit 0\n";
        file_put_contents($file, $script);
        chmod($file, 0755);
    }

    private function makeMarkerWitness(): string
    {
        $dir = $this->tempDir();

        return $dir . '/executed.marker';
    }
}
