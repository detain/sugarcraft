<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Executable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Executable\Locator;

/**
 * The PATH walk, pinned in a sandbox directory — never via `putenv('PATH')`.
 *
 * {@see Locator::search()} takes the path list as an argument (the seam every
 * predecessor's docblock said it needed and none shipped), so every rule —
 * empty-element skipping, the non-executable refusal, the separator-makes-it-
 * a-path rule, even the Windows PATHEXT append order — is answered about a
 * temp directory deterministically on whatever OS the suite runs on. Only
 * the two arms that read the LIVE environment ({@see Locator::locate()} with
 * extraPath, and the Windows-gated real-PATHEXT arm) touch the process at
 * all, and neither writes to it.
 */
final class LocatorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/cc_locator_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o700);
        $this->make('aexec', 0o755);
        $this->make('anoexec', 0o644);
        \mkdir($this->dir . '/adir');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testFindsAnExecutableFileAndRefusesEverythingElse(): void
    {
        self::assertSame($this->dir . '/aexec', Locator::search('aexec', $this->dir));
        self::assertNull(Locator::search('anoexec', $this->dir), 'a readable non-executable file is not an executable');
        self::assertNull(Locator::search('adir', $this->dir), 'a directory by the name is not a hit');
        self::assertNull(Locator::search('missing', $this->dir));
    }

    public function testEmptyNameAndEmptyPathListAnswerNullWithoutWalking(): void
    {
        self::assertNull(Locator::search('', $this->dir));
        self::assertNull(Locator::search('aexec', ''));
        self::assertNull(Locator::search('aexec', '::'), 'the entire list being empty elements is still no PATH');
    }

    public function testAnEmptyPathElementIsSkippedNotTreatedAsCurrentDirectory(): void
    {
        // POSIX says an empty element means "."; honouring it would answer a
        // capability question from whatever the CWD happens to be — the exact
        // nondeterminism the walk exists to remove.
        $cwd = \getcwd();
        self::assertIsString($cwd);

        self::assertNull(
            Locator::search(\ltrim($cwd, '/') . '/aexec', ':'),
            'a bare empty element must not resolve against the working directory',
        );
    }

    public function testANameContainingASeparatorIsAnsweredAsAPathNotAProbe(): void
    {
        self::assertSame($this->dir . '/aexec', Locator::search($this->dir . '/aexec', '/nonexistent'));
        self::assertSame($this->dir . '/aexec', Locator::search($this->dir . '/aexec', ''), 'even with no PATH at all, the path-shaped name answers from itself');
        self::assertNull(Locator::search($this->dir . '/anoexec', ''), 'and the executable gate still applies');
    }

    public function testExtensionsAreAppendedInOrderAfterTheExactName(): void
    {
        $this->make('onlyexe.EXE', 0o755);
        $this->make('both', 0o755);
        $this->make('both.EXE', 0o755);

        self::assertNull(
            Locator::search('onlyexe', $this->dir),
            'POSIX default: no extensions, no match — the walk does not invent suffixes',
        );
        self::assertSame($this->dir . '/onlyexe.EXE', Locator::search('onlyexe', $this->dir, ['.EXE']));
        self::assertSame(
            $this->dir . '/both',
            Locator::search('both', $this->dir, ['.EXE']),
            'an explicit name wins over any extension append — cmd.exe semantics',
        );
        $this->make('ord.AL', 0o755);
        $this->make('ord.ZI', 0o755);
        self::assertSame(
            $this->dir . '/ord.AL',
            Locator::search('ord', $this->dir, ['.AL', '.ZI']),
            'candidate extensions are tried in list order, first hit wins',
        );
    }

    public function testTheFirstDirectoryHoldingTheNameWins(): void
    {
        $second = $this->dir . '/second';
        \mkdir($second);
        $this->make('aexec', 0o755, $second);

        self::assertSame(
            $this->dir . '/aexec',
            Locator::search('aexec', $this->dir . ':' . $second),
            'PATH order is a contract: leftmost first',
        );
        self::assertSame(
            $second . '/aexec',
            Locator::search('aexec', $second . ':' . $this->dir),
        );
    }

    public function testExtraPathIsPrependedSoItBeatsTheSystemPath(): void
    {
        // locate()'s documented ordering rule, proven without touching the
        // real environment: a private stub named `php` must answer first.
        $this->make('php', 0o755);

        self::assertSame($this->dir . '/php', Locator::locate('php', $this->dir));
        self::assertNotSame($this->dir . '/php', Locator::locate('php'), 'without extraPath the real PATH answers');
    }

    public function testLocateAgainstTheLivePathFindsTheRunningInterpAndRefusesMyths(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('/bin is POSIX; the live-PATH arm runs where the primary CI is');
        }

        $php = Locator::locate('php');
        self::assertNotNull($php, 'a host running the suite has php on PATH');
        self::assertTrue(\is_file((string) $php));
        self::assertNull(Locator::locate('cc-definitely-not-installed-9f3a'));
    }

    public function testExistsMirrorsLocate(): void
    {
        self::assertTrue(Locator::exists('aexec', $this->dir));
        self::assertFalse(Locator::exists('anoexec', $this->dir));
        self::assertFalse(Locator::exists('missing', $this->dir));
    }

    public function testPlatformCompositionOfPathListAndExtensions(): void
    {
        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        self::assertSame($separator, Locator::pathSeparator());

        $system = (string) \getenv('PATH');
        self::assertSame($system, Locator::pathList(null));
        self::assertSame($system, Locator::pathList(''), 'an empty extra adds nothing, not a stray separator');
        self::assertSame(
            $this->dir . $separator . $system,
            Locator::pathList($this->dir),
            'extraPath PREPENDS — caller-added directories win',
        );

        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame([], Locator::pathExtensions(), 'POSIX executability is a mode bit, never a suffix');
        }
    }

    public function testWindowsPathExtIsHonouredByTheLiveResolver(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('this arm proves the PATHEXT wiring end-to-end, which only exists on Windows');
        }

        $saved = \getenv('PATHEXT');
        \putenv('PATHEXT=.ZTEST');
        try {
            $this->make('winfake.ZTEST', 0o755);
            self::assertSame($this->dir . '/winfake.ZTEST', Locator::locate('winfake', $this->dir));
            self::assertContains('.ZTEST', Locator::pathExtensions());
        } finally {
            \putenv($saved === false ? 'PATHEXT' : 'PATHEXT=' . $saved);
        }
    }

    public function testTheDefaultWindowsExtensionFallbackIsAnExplicitList(): void
    {
        self::assertSame(['.COM', '.EXE', '.BAT', '.CMD'], Locator::DEFAULT_WINDOWS_EXTENSIONS);
    }

    public function testTheLocatorCachesNothingProcessGlobally(): void
    {
        // The statelessness law in the class doc-block: no static property
        // to go stale when a tool is installed mid-session. A future memo
        // must arrive WITH an invalidation seam, and this pin is where that
        // conversation starts.
        self::assertSame(
            [],
            (new \ReflectionClass(Locator::class))->getStaticProperties(),
            'Locator grew mutable process-global state without an invalidation story',
        );
    }

    private function make(string $name, int $mode, ?string $dir = null): void
    {
        \file_put_contents(($dir ?? $this->dir) . '/' . $name, "#!/bin/sh\nexit 0\n");
        \chmod(($dir ?? $this->dir) . '/' . $name, $mode);
    }
}
