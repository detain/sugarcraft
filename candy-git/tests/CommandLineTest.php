<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
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
        [$exit, $out] = self::spawn(['--help'], sys_get_temp_dir());

        self::assertSame(0, $exit);
        self::assertStringContainsString('Usage:', $out);
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
        exec('git init --bare ' . escapeshellarg($dir) . ' 2>/dev/null');

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
        self::assertSame(1, $exit);
        self::assertStringContainsString('interactive terminal', $err);
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
        $spec    = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
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
        $path = $dir . '/git';
        file_put_contents($path, "#!/bin/sh\n" . $body . "exit 0\n");
        chmod($path, 0755);
        // The shim dir holds the fake `git`; removeDir must be able to, too.
    }

    private function requireGit(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
    }

    private function makeRepo(): string
    {
        $dir = $this->tempDir();
        exec('git init ' . escapeshellarg($dir) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($dir) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($dir) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($dir . '/file.txt', "line 1\n");
        exec('git -C ' . escapeshellarg($dir) . ' add file.txt 2>/dev/null');
        exec('git -C ' . escapeshellarg($dir) . ' commit -m initial 2>/dev/null');

        return $dir;
    }
}
