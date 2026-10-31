<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\WindowsShell;

/**
 * C-TUI-6.a: the Windows resolution ladder, EXEC-tested as data.
 *
 * The ladder itself — `;`-split PATH, PATHEXT candidate order, the
 * %ProgramFiles%\Git\bin fallback — is a pure function of (PATH-like
 * string, PATHEXT-like string, candidate files), so every row runs on THIS
 * Linux host: the seams inject "which host am I and what does my
 * environment say", and the files checked by the real `is_file()` are real
 * temp-dir fixtures. Only the family read is injected; the filesystem walk
 * is live. That is the C-TUI-6.b/6.c posture (adapters and factories tested
 * by shape, never executed against their OS), upgraded to full execution
 * for the legs that ARE OS-independent arithmetic.
 *
 * Fixture spellings matter: the candidate names are built verbatim from the
 * injected PATHEXT (a real Windows host resolves case-insensitively, so
 * faithful production code does NOT lowercase — the tests therefore name
 * their files with the exact extension spelling the seam advertises).
 */
final class WindowsShellTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = (string) sys_get_temp_dir() . '/w6a-shell-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->fixtureRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        WindowsShell::setOsFamilyForTesting(null);
        WindowsShell::setEnvSeamForTesting(null);
        $this->wipeTree($this->fixtureRoot);
        @rmdir($this->fixtureRoot);
    }

    public function testIsWindowsFollowsTheInjectedFamilyAndTheNullRestoreReadsTheLiveHost(): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');
        self::assertTrue(WindowsShell::isWindows());

        WindowsShell::setOsFamilyForTesting('Darwin');
        self::assertFalse(WindowsShell::isWindows());

        // Null hands the read back: on this (Linux) runner the answer must
        // equal the live constant, and MUST be false — a leaked override
        // would flip production code paths for every later test in the
        // process, which is exactly the shape this pin catches.
        WindowsShell::setOsFamilyForTesting(null);
        self::assertSame(\PHP_OS_FAMILY === 'Windows', WindowsShell::isWindows());
        self::assertFalse(WindowsShell::isWindows(), 'a Linux runner must read itself as not-Windows');
    }

    public function testPathSplitsOnSemicolonAndWalksDirectoriesInOrder(): void
    {
        $dirA = $this->makeDir('dirA');
        $dirB = $this->makeDir('dirB');
        $this->makeFile($dirB, 'tool.exe');
        $this->makeFile($dirA, 'tool.exe');
        $this->arm('Windows', ['PATH' => $dirA . ';' . $dirB, 'PATHEXT' => '.exe']);

        // First hit wins in order.
        self::assertSame($dirA . \DIRECTORY_SEPARATOR . 'tool.exe', WindowsShell::locate('tool'));

        // Reverse the PATH and the answer follows — the walk is order, not
        // a global search.
        $this->arm('Windows', ['PATH' => $dirB . ';' . $dirA, 'PATHEXT' => '.exe']);
        self::assertSame($dirB . \DIRECTORY_SEPARATOR . 'tool.exe', WindowsShell::locate('tool'));
    }

    public function testColonJoinedWindowsPathResolvesUnderTheSemicolonRuleWhereThePosixSplitCollapses(): void
    {
        // THE root-cause row: a Windows PATH was being split on `:`, so
        // `dirA;dirB` (their real separator) was ONE fragment naming nothing
        // and bash.exe was never found. Under locate()'s `;` rule the same
        // string resolves; the colon-joined POSIX form now yields nothing
        // instead of a corrupted C:\ cut.
        $dirA = $this->makeDir('gitbin');
        $this->makeFile($dirA, 'bash.exe');
        $this->arm('Windows', ['PATH' => $dirA, 'PATHEXT' => '.exe']);
        self::assertSame($dirA . \DIRECTORY_SEPARATOR . 'bash.exe', WindowsShell::locate('bash'));

        // A `:`-joined value is not a Windows PATH: no per-colon splitting
        // happens, and the single bogus directory answers the honest ''.
        self::assertSame('', WindowsShell::locate($dirA . ':' . $this->fixtureRoot));
    }

    public function testAsGivenNameIsTriedBeforeAnyExtensionIsAppended(): void
    {
        $dir = $this->makeDir('bin');
        $this->makeFile($dir, 'exactly');
        $this->arm('Windows', ['PATH' => $dir, 'PATHEXT' => '.exe']);

        self::assertSame($dir . \DIRECTORY_SEPARATOR . 'exactly', WindowsShell::locate('exactly'));
    }

    public function testNameCarryingAnExtensionDisablesThePathextWalk(): void
    {
        // Windows' own rule: an explicit extension means THAT file, no
        // PATHEXT appended — otherwise `bash.exe` would also search for the
        // never-installed `bash.exe.exe`.
        $dir = $this->makeDir('bin');
        $this->makeFile($dir, 'ghost.exe.exe');
        $this->arm('Windows', ['PATH' => $dir, 'PATHEXT' => '.exe']);

        self::assertSame('', WindowsShell::locate('ghost.exe'));
    }

    public function testPathextDefaultsToTheWindowsSystemListWhenUnset(): void
    {
        $dir = $this->makeDir('bin');
        // The DEFAULT list spells its entries upper-case (.COM;.EXE;.BAT;.CMD),
        // so the fixture carries the exact default spelling of the last rung.
        $this->makeFile($dir, 'link.CMD');
        $this->arm('Windows', ['PATH' => $dir]);

        self::assertSame($dir . \DIRECTORY_SEPARATOR . 'link.CMD', WindowsShell::locate('link'));
    }

    public function testEmptyPathElementsAreSkippedNotTreatedAsCurrentDirectory(): void
    {
        // Same doctrine as the POSIX locateOnPath(): an empty element must
        // never answer a containment question from the working directory.
        $dir = $this->makeDir('realbin');
        $this->makeFile($dir, 'here.exe');
        // The fixture ROOT deliberately carries a same-named file: were an
        // empty element treated as '.', the root copy (nearer the cwd in a
        // careless walk) could shadow the honest miss.
        $this->makeFile($this->fixtureRoot, 'here.exe');
        $this->arm('Windows', ['PATH' => ';' . $dir . ';', 'PATHEXT' => '.exe']);

        self::assertSame($dir . \DIRECTORY_SEPARATOR . 'here.exe', WindowsShell::locate('here'));

        $this->arm('Windows', ['PATH' => ';;', 'PATHEXT' => '.exe']);
        self::assertSame('', WindowsShell::locate('here'), 'all-empty PATH answers the miss, never the cwd');
    }

    public function testMissingOrEmptyPathAnswersTheMiss(): void
    {
        $this->arm('Windows', ['PATH' => null]);
        self::assertSame('', WindowsShell::locate('bash'));

        $this->arm('Windows', ['PATH' => '']);
        self::assertSame('', WindowsShell::locate('bash'));

        $this->arm('Windows', ['PATH' => $this->makeDir('x')]);
        self::assertSame('', WindowsShell::locate(''), 'an empty binary name is never found');
    }

    public function testBashResolvesPathFirstAndTheInstallerPathOnlyAfter(): void
    {
        $pathDir = $this->makeDir('userbin');
        $this->makeFile($pathDir, 'bash.exe');
        $pf = $this->makeDir('pf');
        $stock = $this->makeFile($pf, 'Git/bin/bash.exe');

        // Rung 1 wins when both exist — the user's PATH choice beats the
        // default installer.
        $this->arm('Windows', ['PATH' => $pathDir, 'PATHEXT' => '.exe', 'ProgramFiles' => $pf]);
        self::assertSame($pathDir . \DIRECTORY_SEPARATOR . 'bash.exe', WindowsShell::bash());

        // Rung 2 only when PATH has nothing.
        $this->arm('Windows', ['PATH' => $this->makeDir('emptybin'), 'PATHEXT' => '.exe', 'ProgramFiles' => $pf]);
        self::assertSame($stock, WindowsShell::bash());
    }

    public function testBashReturnsNothingWhenNeitherRungExistsAndNeverCmdExe(): void
    {
        $pf = $this->makeDir('pf2');
        $this->makeFile($pf, 'Git/bin/cmd.exe'); // lookalike trap: cmd.exe present under Git/bin is NOT bash
        $this->arm('Windows', ['PATH' => $this->makeDir('nada'), 'ProgramFiles' => $pf]);

        self::assertSame('', WindowsShell::bash());
    }

    public function testBashWithoutProgramFilesStillAnswersFromPathOrNothing(): void
    {
        $this->arm('Windows', ['PATH' => null, 'ProgramFiles' => null]);
        self::assertSame('', WindowsShell::bash());

        $dir = $this->makeDir('msys');
        $this->makeFile($dir, 'bash.exe');
        $this->arm('Windows', ['PATH' => $dir, 'PATHEXT' => '.exe', 'ProgramFiles' => null]);
        self::assertSame($dir . \DIRECTORY_SEPARATOR . 'bash.exe', WindowsShell::bash());
    }

    public function testTheMissingBashNoticeNamesTheRemedyAndTheNoFallbackRule(): void
    {
        // The refusal is the user-visible half of the design: it names the
        // thing to install, and it states the cmd.exe rule so a future
        // "just add a fallback" edit reads the reason not to.
        $notice = WindowsShell::BASH_MISSING_NOTICE;
        self::assertStringContainsString('Git for Windows', $notice);
        self::assertStringContainsString('bash.exe', $notice);
        self::assertStringContainsString('cmd.exe', $notice);
        self::assertStringContainsString('does not fall back', $notice);
    }

    public function testTheDefaultPathextConstantStatesTheSystemList(): void
    {
        // Derived-equality: the unset-PATHEXT test above already PROVES the
        // ladder walks this list; the pin here is the exact conventional
        // spelling a Windows host applies.
        self::assertSame('.COM;.EXE;.BAT;.CMD', WindowsShell::DEFAULT_PATHEXT);
    }

    /**
     * Point the two seams at a fixed family and a fixed environment map.
     *
     * @param array<string, string|null> $vars
     */
    private function arm(?string $family, array $vars): void
    {
        WindowsShell::setOsFamilyForTesting($family);
        WindowsShell::setEnvSeamForTesting(static fn (string $name): ?string => $vars[$name] ?? null);
    }

    private function makeDir(string $name): string
    {
        $path = $this->fixtureRoot . '/' . $name;
        mkdir($path, 0o700, true);

        return $path;
    }

    private function makeFile(string $dir, string $relative): string
    {
        $path = $dir . '/' . $relative;
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0o700, true);
        }
        file_put_contents($path, 'fixture');

        // Raw join, NOT realpath(): the production ladder builds its answer
        // by joining the injected strings, and a resolved-vs-injected
        // mismatch (a symlinked /tmp) would read as a false drift in these
        // pins. Both sides are the same spelling by construction.
        return $path;
    }

    private function wipeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->wipeTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
