<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Support\WindowsShell;
use SugarCraft\Code\Tools\BuiltIn\Bash;

/**
 * C-TUI-6.a: the Bash tool's Windows spawn shape, EXEC-tested as data.
 *
 * Under an injected Windows family the tool hands `proc_open` the ARRAY
 * argv `[bash.exe, '-c', $script]` — PHP quotes by CreateProcess rules and
 * no cmd.exe is involved. The fixture "bash.exe" is a real Linux script
 * with that name: it prints its argc and every argument on its own line, so
 * the run PROVES the shape (argc 2, the -c payload byte-intact with `%`,
 * `!` and `"`), not just the plan to use it. The missing-bash refusal and
 * the POSIX regression leg (single-quoted argv through the real bash,
 * host-independent quoting keeping the payload literal) close the pair.
 *
 * @see Bash
 */
final class BashWindowsSpecTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = (string) sys_get_temp_dir() . '/w6a-bash-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->fixtureRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        WindowsShell::setOsFamilyForTesting(null);
        WindowsShell::setEnvSeamForTesting(null);
        $this->sweep($this->fixtureRoot);
        @rmdir($this->fixtureRoot);
    }

    public function testWindowsSpawnIsTheBashArgvArrayWithTheScriptByteIntact(): void
    {
        $this->armWindowsBashFixture();

        $result = (new Bash())->execute([
            'id' => 'call_win_shape',
            'command' => 'echo %PATH% !x "q"',
        ]);

        self::assertFalse($result->isError(), $result->content());
        // The fixture runs as a script file: argv after the script name is
        // ['-c', SCRIPT], so it prints argc 2, then '-c', then the script.
        // argc 2 is the array-shape proof — a cmd.exe /c or /bin/sh -c
        // wrapper would print a different argc or quote-scarred payload —
        // and the payload lines are the command VERBATIM, with `%`, `!` and
        // `"` intact because quoting is now the receiver's (bash's single
        // quotes, from PosixShellQuote) rather than the building host's.
        $lines = explode("\n", trim($result->content()));
        self::assertSame('2', $lines[0]);
        self::assertSame('-c', $lines[1], 'the fixture was exec-ed as the -c carrier, nothing else');
        self::assertSame('echo %PATH% !x "q"', $lines[2]);
    }

    public function testWindowsSpawnCarriesTheWorkingDirectoryGuardInsideTheScript(): void
    {
        $this->armWindowsBashFixture();
        $inside = $this->fixtureRoot . '/cwd here';
        mkdir($inside, 0o700, true);

        $result = (new Bash(root: $inside))->execute([
            'id' => 'call_win_cwd',
            'command' => 'true',
        ]);

        self::assertFalse($result->isError(), $result->content());
        // With a root, the script the array carries is cdGuard+command — and
        // the guard is quoted by PosixShellQuote even for a path holding a
        // space, the exact input escapeshellarg-on-Windows mangled. The
        // fixture echoes it back, so the bytes are the proof.
        self::assertStringContainsString("cd '" . $inside . "' || exit 1", $result->content());
    }

    public function testMissingBashRefusesWithTheInstallerReasonWithoutSpawningAnything(): void
    {
        $this->armWindowsFamilyWith(bashPresent: false);

        $result = (new Bash())->execute(['id' => 'call_win_none', 'command' => 'echo HI-RAN']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('Git for Windows', $result->content());
        self::assertStringNotContainsString('echo HI-RAN', $result->content(), 'nothing ran, so nothing echoed');
    }

    public function testBackgroundWithMissingBashRefusesBeforeTheRegistryIsTouched(): void
    {
        $root = $this->fixtureRoot . '/regroot';
        mkdir($root, 0o700, true);
        $registry = BackgroundShellRegistry::forSession('w6a-bshtest', $root);
        $this->armWindowsFamilyWith(bashPresent: false);

        $result = (new Bash(registry: $registry))->execute([
            'id' => 'call_win_bg_none',
            'command' => 'sleep 5',
            'run_in_background' => true,
        ]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('Git for Windows', $result->content());
        self::assertCount(0, $registry->ids(), 'the tool-level refusal lands before any row is minted');
    }

    public function testPosixLegKeepsItsHistoricBytesAndQuotesGlobally(): void
    {
        // Family unset: the real /bin/bash with the single-quoted builder —
        // the byte-identity the whole refactor leans on. A payload with
        // `%`, `!`, `"` and a `*?` glob stays literal under correct POSIX
        // single-quoting (non-interactive bash does no history expansion,
        // a quoted glob does not expand), the same guarantee Windows users
        // now get, which is why ONE builder is safe on both hosts.
        WindowsShell::setOsFamilyForTesting(null);
        $dir = $this->fixtureRoot . '/pdir';
        mkdir($dir, 0o700, true);

        $result = (new Bash(root: $dir))->execute([
            'id' => 'call_posix_quote',
            'command' => 'echo literal A B C',
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('literal A B C', trim($result->content()));
    }

    /**
     * Make this host claim to be Windows with a working fixture bash.exe on
     * PATH: a plain executable script that records its own argv shape.
     */
    private function armWindowsBashFixture(): void
    {
        $bin = $this->fixtureRoot . '/gitbin';
        mkdir($bin, 0o700, true);
        $bash = $bin . '/bash.exe';
        file_put_contents(
            $bash,
            "#!/bin/bash\n" . 'printf "%s\n" "$#"' . "\n" . 'for a in "$@"; do printf "%s\n" "$a"; done' . "\n" . "exit 0\n"
        );
        chmod($bash, 0o755);
        $this->armWindowsFamilyWith(bashPresent: true, pathDir: $bin);
    }

    private function armWindowsFamilyWith(bool $bashPresent, string $pathDir = ''): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');
        $vars = [
            'PATH' => $bashPresent ? $pathDir : $this->fixtureRoot . '/emptybin-missing',
            'PATHEXT' => '.exe',
            'ProgramFiles' => $this->fixtureRoot . '/no-pf',
        ];
        WindowsShell::setEnvSeamForTesting(static fn (string $name): ?string => $vars[$name] ?? null);
    }

    private function sweep(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->sweep($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
