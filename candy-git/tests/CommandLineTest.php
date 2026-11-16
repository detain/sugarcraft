<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Platform;
use SugarCraft\Git\Support\GitRequirement;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Launcher-contract pins: what `bin/candy-git` must answer BEFORE any TUI
 * boots. These behaviors only exist as process exit codes and stderr prose,
 * so they are tested by spawning the real binary with real pipes — the same
 * shape a user (or a distribution smoke test) experiences.
 *
 * Pins the audit's A11/A12/A13 cluster: a missing git, an ancient git, a
 * stray argument, a non-repository, a bare repository, and a piped stdout
 * each get ONE readable sentence and a deliberate exit code — never a stack
 * trace, never escape-sequence soup, never a silent ignore.
 */
final class CommandLineTest extends TestCase
{
    use RecursiveDirCleanup;

    /** @var list<string> */
    private array $trash = [];

    protected function tearDown(): void
    {
        foreach ($this->trash as $path) {
            if (is_dir($path)) {
                $this->removeDir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->trash = [];
    }

    // ------------------------------------------------ GitRequirement (pure)

    /** @return array<string, array{0:string, 1:?string}> */
    public static function versionBannerProvider(): array
    {
        return [
            'plain'            => ['git version 2.43.0', '2.43'],
            'windows build'    => ['git version 2.23.0.windows.1', '2.23'],
            'apple suffix'     => ['git version 2.39.5 (Apple Git-154)', '2.39'],
            'single digit'     => ['git version 1.9', '1.9'],
            'unparseable junk' => ['git: command not found-ish', null],
            'empty'            => ['', null],
        ];
    }

    /** @dataProvider versionBannerProvider */
    public function testTheVersionBannerParsesOrYieldsNull(string $banner, ?string $expected): void
    {
        self::assertSame($expected, GitRequirement::parse($banner));
    }

    public function testTheVersionFloorIsInclusive(): void
    {
        self::assertTrue(GitRequirement::satisfies('2.23'), 'the floor version itself must satisfy');
        self::assertTrue(GitRequirement::satisfies('3.0'));
        self::assertFalse(GitRequirement::satisfies('2.22'));
        self::assertFalse(GitRequirement::satisfies('1.9'));
    }

    // --------------------------------------------------------- flag handling

    public function testVersionFlagAnswersWithoutARepository(): void
    {
        [$exit, $out] = self::spawn(['--version'], sys_get_temp_dir());

        self::assertSame(0, $exit);
        self::assertStringContainsString('candy-git', $out);
    }

    public function testHelpFlagPrintsUsage(): void
    {
        [$exit, $out] = self::spawn(['--help'], sys_get_temp_dir(), ['LANG' => 'C', 'LC_ALL' => 'C']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Usage:', $out);
        // candy-git-019: the descriptions are catalogue copy now, not literals.
        self::assertStringContainsString('terminal git interface', $out);
        self::assertStringContainsString('Browse the repository of the current directory', $out);
        self::assertStringContainsString('Print the version', $out);
        self::assertStringContainsString('Show this help', $out);
    }

    public function testHelpFlagLocalizesUnderGerman(): void
    {
        [$exit, $out] = self::spawn(['--help'], sys_get_temp_dir(), ['LANG' => 'de_DE.UTF-8']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Verwendung:', $out);
        self::assertStringContainsString('Version ausgeben', $out);
        self::assertStringContainsString('Diese Hilfe anzeigen', $out);
        self::assertStringNotContainsString('Usage:', $out);
    }

    public function testTheTryHelpTailLocalizes(): void
    {
        [$exit, , $err] = self::spawn(['--frobnicate'], sys_get_temp_dir(), ['LANG' => 'de_DE.UTF-8']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('versuchen Sie --help', $err);
    }

    /** @return array<string, array{0:string}> */
    public static function strayArgumentProvider(): array
    {
        return [
            'long flag'   => ['--frobnicate'],
            'short flag'  => ['-x'],
            'positional'  => ['/some/repo'],
        ];
    }

    /** @dataProvider strayArgumentProvider */
    public function testAnyArgumentWeDoNotServeExitsWithUsageCode(string $stray): void
    {
        [$exit, $out, $err] = self::spawn([$stray], sys_get_temp_dir());

        self::assertSame(2, $exit, 'unknown arguments are the usage-error class');
        self::assertStringContainsString($stray, $err);
        self::assertStringContainsString('unknown', strtolower($err));
        self::assertSame('', $out, 'nothing may print to stdout for a refused launch');
    }

    // ------------------------------------------------------------- git probe

    public function testAMissingGitBinarySendsOneSentenceNotATrace(): void
    {
        [$exit, $out, $err] = self::spawn([], sys_get_temp_dir(), [
            'PATH' => '/nonexistent-dir-for-candy-git',
            'HOME' => sys_get_temp_dir(),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('git is unavailable', $err);
        self::assertStringNotContainsString('Stack trace', $err);
        self::assertStringNotContainsString('failed to spawn', $err, 'the internal wording must not leak');
    }

    public function testAnAncientGitIsRefusedAtTheDoorWithBothVersions(): void
    {
        $dir = $this->tempDir();
        $this->writeShim($dir, "echo 'git version 1.9.0'\n");

        [$exit, , $err] = self::spawn([], sys_get_temp_dir(), [
            'PATH' => $dir,
            'HOME' => sys_get_temp_dir(),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('too old', $err);
        self::assertStringContainsString('1.9', $err, 'the refusal names what it found');
        self::assertStringContainsString(GitRequirement::MINIMUM, $err, '...and what it needs');
    }

    // ------------------------------------------------------- repository probe

    public function testADirectoryThatIsNotARepositoryStaysRefused(): void
    {
        $this->requireGit();

        [$exit, , $err] = self::spawn([], $this->tempDir());

        self::assertSame(1, $exit);
        self::assertStringContainsString('not a git repository', $err);
    }

    public function testABareRepositoryIsRefusedWithAnExplanation(): void
    {
        $this->requireGit();
        $dir = $this->tempDir();
        exec('git init --bare ' . escapeshellarg($dir) . '' . self::nullSink());

        [$exit, , $err] = self::spawn([], $dir);

        self::assertSame(1, $exit);
        self::assertStringContainsString('bare', $err);
    }

    public function testAPipedStdoutIsRefusedInsteadOfRenderingSoup(): void
    {
        $this->requireGit();
        $repo = $this->makeRepo();

        [$exit, $out, $err] = self::spawn([], $repo);

        // stdout is a pipe for proc_open here — exactly the headless shape
        // the launcher must refuse before the alt-screen program starts.
        // The named refusal is cli.piped_stdout (candy-git-055): the pipe is
        // honored, not rescued onto /dev/tty, so the message says STDOUT was
        // piped — not the old generic needs-an-interactive-terminal line.
        // That naming law is POSIX-side only: TerminalGate cannot distinguish
        // a console-less child stdout from a tty behind a mintty-class rescue
        // without the delegation, so on Windows the honest refusal after a
        // failed Tty::openTty is the needs-tty line (ci-wave-3 L4). Exit and
        // silence — the actual contract — stay unconditional.
        self::assertSame(1, $exit);
        self::assertStringContainsString('interactive terminal', $err);
        if (DIRECTORY_SEPARATOR === '\\') {
            self::assertStringContainsString('not a tty', $err);
        } else {
            self::assertStringContainsString('stdout was piped', $err);
        }
        self::assertSame('', $out, 'no escape sequences may reach a piped stdout');
    }

    public function testTheLauncherSpeaksTheUsersLocale(): void
    {
        // A stray argument under LANG=de proves T::setLocale(T::detect()) runs
        // before the first translated string is emitted: without that call the
        // lang/ catalogue stays dead code and every refusal ships in English
        // no matter what the environment says (audit A15).
        [$exit, , $err] = self::spawn(['--nope'], null, ['LANG' => 'de_DE.UTF-8', 'PATH' => '/usr/bin:/bin']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('unbekanntes Argument', $err);
        self::assertStringNotContainsString('unknown argument', $err);
    }

    public function testTheLocaleFallsBackToEnglishInANeutralEnvironment(): void
    {
        [$exit, , $err] = self::spawn(['--nope'], null, ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('unknown argument', $err);
    }

    /**
     * candy-git-xp-02: the App's paste defenses (applyPaste/flattenPaste) are
     * only reachable if the terminal was asked to wrap pastes in a bracketed
     * envelope (CSI ?2004h) — without the option a "subject\n\nbody" paste
     * arrives as raw keystrokes and submits a commit mid-paste. A piped test
     * runner cannot boot the TUI at all (the refusal is pinned above), so the
     * contract is pinned at the launcher source: the boot line must carry
     * bracketedPaste: true, and the alt screen must be gated on the detected
     * color profile (candy-git-052: a TERM=dumb terminal cannot act on alt
     * screen or cursor addressing, so the launcher boots it inline).
     */
    public function testTheLauncherBootsWithBracketedPasteEnabled(): void
    {
        $bin = realpath(__DIR__ . '/../bin/candy-git');
        self::assertIsString($bin);
        $src = (string) file_get_contents($bin);

        self::assertMatchesRegularExpression(
            '/new ProgramOptions\([^)]*useAltScreen: \$profile !== ColorProfile::NoTty[^)]*bracketedPaste: true[^)]*\)/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/new ProgramOptions\((?![^)]*bracketedPaste)[^)]*\)/',
            $src,
            'no ProgramOptions construction may boot without the paste envelope',
        );
    }

    // ------------------------------------------------------------- plumbing

    /**
     * @param list<string>        $args
     * @param array<string,string>|null $env  when set, REPLACES the child environment
     * @return array{0:int, 1:string, 2:string} exit, stdout, stderr
     */
    private static function spawn(array $args, ?string $cwd, ?array $env = null): array
    {
        $bin     = realpath(__DIR__ . '/../bin/candy-git');
        self::assertIsString($bin, 'the launcher must exist to be tested');
        // The child starts at an immediate EOF on any host: /dev/null is
        // meaningless to cmd.exe, so the seam speaks the host's own device
        // (the same contract src/Process.php keeps via Platform::nullDevice).
        $spec    = [0 => ['file', Platform::new()->nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc    = proc_open(array_merge([PHP_BINARY, $bin], $args), $spec, $pipes, $cwd, $env);
        self::assertIsResource($proc);
        $out     = (string) stream_get_contents($pipes[1]);
        $err     = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/candygitcli_' . uniqid();
        mkdir($dir);
        $this->trash[] = $dir;

        return $dir;
    }

    private function writeShim(string $dir, string $body): void
    {
        if (Platform::new()->isWindows()) {
            // cmd.exe cannot run a shebang script; a .cmd twin answers the
            // same probe (Platform::findExecutable walks .cmd on Windows).
            // cmd echo takes bare text, so the single quotes must go.
            $echo = str_replace(["echo '", "'\n"], ['echo ', "\r\n"], $body);
            file_put_contents($dir . '/git.cmd', "@echo off\r\n" . $echo . "exit /b 0\r\n");
            return;
        }

        $path = $dir . '/git';
        file_put_contents($path, "#!/bin/sh\n" . $body . "exit 0\n");
        chmod($path, 0755);
        // The shim dir holds the fake `git`; removeDir must be able to, too.
    }

    /** Host-native stderr sink for fixture exec lines: ' 2>/dev/null' / ' 2>NUL'. */
    private static function nullSink(): string
    {
        return ' 2>' . Platform::new()->nullDevice();
    }

    private function requireGit(): void
    {
        if (!exec('git --version' . self::nullSink())) {
            $this->markTestSkipped('git not available');
        }
    }

    private function makeRepo(): string
    {
        $dir = $this->tempDir();
        exec('git init ' . escapeshellarg($dir) . '' . self::nullSink());
        exec('git -C ' . escapeshellarg($dir) . " config user.email 'test@test.com'" . self::nullSink());
        exec('git -C ' . escapeshellarg($dir) . " config user.name 'Test'" . self::nullSink());
        file_put_contents($dir . '/file.txt', "line 1\n");
        exec('git -C ' . escapeshellarg($dir) . ' add file.txt' . self::nullSink());
        exec('git -C ' . escapeshellarg($dir) . ' commit -m initial' . self::nullSink());

        return $dir;
    }
}
