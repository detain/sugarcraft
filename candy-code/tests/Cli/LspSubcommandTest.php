<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\LSP\LspInstaller;
use SugarCraft\Code\LSP\LspReleases;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-TOOL-5.d — `candy-code lsp install <language> | candy-code lsp list`,
 * the headless surface over {@see LspInstaller} and {@see LspReleases}.
 *
 * The door order the arm commits to, one pin per door: a missing action and
 * an unknown action are usage doors (exit 2 — nothing attempted), as are a
 * missing language operand and a second operand on `install`. The install
 * itself answers with ONE exit code per outcome for both output formats —
 * the mcp-list law — so a JSON consumer never sees a verdict the text arm
 * would call a failure.
 *
 * In-process, the way {@see UsageSubcommandTest} drives its verb: ArgvParser
 * → Subcommands::dispatch with `HOME` pointed at a private directory, so the
 * launch probe under test can never read a real user's install tree, and the
 * installer itself is reached only through
 * {@see Subcommands::setLspInstallSeamForTesting()} — THE SUITE NEVER
 * DOWNLOADS. stdout is ob-captured; stderr is not capturable in-process, so
 * every failure arm is asserted through the `--output-format json` document.
 */
final class LspSubcommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/clsp5d-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.candy-code', 0700, true));
        chmod($this->home, 0700);
        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        Subcommands::resetLspInstallSeamForTesting();
        $this->restoreHomeSandbox();
        $this->purgeLspHome($this->home);
    }

    private function purgeLspHome(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }

    // ── the usage doors ──────────────────────────────────────────────────

    public function testNoActionIsALoudUsageDoor(): void
    {
        [$rc, $out] = $this->runLsp(['lsp']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $out);

        $message = $this->lspDoorMessage(['lsp']);
        self::assertStringContainsString('lsp: no action given', $message);
    }

    public function testUnknownActionRefusesNamingIt(): void
    {
        [$rc, $out] = $this->runLsp(['lsp', 'frobnicate']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $out);

        $message = $this->lspDoorMessage(['lsp', 'frobnicate']);
        self::assertStringContainsString('lsp frobnicate: unknown action', $message);
    }

    public function testInstallWithoutLanguageRefuses(): void
    {
        [$rc, $out] = $this->runLsp(['lsp', 'install']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $out);

        // With no seam armed this is also the hermeticity proof: exit 2 means
        // the door answered BEFORE any installer call — an accidental dial of
        // the real installer would have failed with exit 1 instead.
        $message = $this->lspDoorMessage(['lsp', 'install']);
        self::assertStringContainsString('lsp install: no language given', $message);
    }

    public function testInstallRefusesASecondOperand(): void
    {
        [$rc, $out] = $this->runLsp(['lsp', 'install', 'php', 'extra']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $out);

        $message = $this->lspDoorMessage(['lsp', 'install', 'php', 'extra']);
        self::assertStringContainsString('unexpected operand extra', $message);
    }

    /**
     * ParsedArgs::SUBCOMMAND_FLAGS spells `'lsp' => []` and justifies the
     * entry with exactly this invocation: `lsp list --all` must answer
     * "unknown option", never a silently swallowed flag or a printed list.
     * The parser only RECORDS the flag — the refusal is the binary's own
     * door (a non-empty unknownFlags exits 2 before dispatch is ever
     * reached), so this is the one usage case in the file that cannot be
     * pinned in-process; it drives the real bin once.
     */
    public function testAnUnknownFlagOnListIsAUsageErrorFromTheBin(): void
    {
        $args = ArgvParser::parse(['candy-code', 'lsp', 'list', '--all']);
        self::assertSame(['--all'], $args->unknownFlags, 'the verb-scoped flag must be reported verbatim');
        self::assertSame(['list'], $args->subcommandArgs, 'the flag must never reach the verb as an operand');

        $root = \dirname(__DIR__, 2);
        $command = 'exec ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($root . '/bin/candy-code')
            . ' lsp list --all';
        $process = \proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process, 'failed to spawn bin/candy-code');

        // Drain to EOF, then judge proc_close — the usage answer is two
        // short lines, and reap-first leaves no window where a dead child
        // could still read as running (the single-shot proc_get_status
        // race the bin harnesses document).
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        self::assertNotFalse(\fclose($pipes[1]));
        self::assertNotFalse(\fclose($pipes[2]));
        $rc = \proc_close($process);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, 'stderr: ' . $stderr);
        self::assertStringContainsString('unrecognized option', $stderr);
        self::assertStringContainsString('--all', $stderr, 'the refused flag must be named');
        self::assertSame('', $stdout, 'a usage door prints nothing to stdout');
    }

    // ── install, through the seam ────────────────────────────────────────

    public function testInstallHandsTheLanguageToTheInstallerAndReportsTheVerdict(): void
    {
        $received = [];
        $this->armSeam($received, [
            'ok' => true,
            'message' => 'lsp install: installed intelephense 1.18.5',
            'path' => $this->home . '/.candy-code/lsp/node_modules/.bin/intelephense',
        ]);

        [$rc, $out] = $this->runLsp(['lsp', 'install', 'php']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        self::assertSame(['php'], $received, 'the CLI stopped handing the one language operand to the installer');
        self::assertStringContainsString('installed intelephense 1.18.5', $out);
        self::assertStringContainsString(' — ', $out, 'the done line lost the join the catalogue text carries');
        self::assertStringContainsString('node_modules/.bin/intelephense', $out);
    }

    public function testInstallSuccessDocumentCarriesTheWholeVerdict(): void
    {
        $received = [];
        $this->armSeam($received, [
            'ok' => true,
            'message' => 'lsp install: installed pyright 1.1.414',
            'path' => '/tmp/clsp5d-fake/bin/pyright-langserver',
        ]);

        [$rc, $out] = $this->runLsp(['lsp', 'install', 'python', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        self::assertSame(['python'], $received);

        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$out}");
        self::assertSame(['language', 'ok', 'message', 'path'], array_keys($decoded['result']), $out);
        self::assertSame('python', $decoded['result']['language']);
        self::assertTrue($decoded['result']['ok']);
        self::assertStringContainsString('pyright 1.1.414', $decoded['result']['message']);
        self::assertSame('/tmp/clsp5d-fake/bin/pyright-langserver', $decoded['result']['path']);
    }

    public function testInstallFailureExitsOneInBothFormats(): void
    {
        $received = [];
        $this->armSeam($received, [
            'ok' => false,
            'message' => 'lsp install: no pinned golang release for linux/x86_64',
            'path' => null,
        ]);

        [$textRc, $textOut] = $this->runLsp(['lsp', 'install', 'golang']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $textRc, $textOut);
        // The text arm's verdict sentence goes to stderr, never stdout.
        self::assertSame('', trim($textOut));

        [$rc, $out] = $this->runLsp(['lsp', 'install', 'golang', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $out);
        self::assertSame($textRc, $rc, 'the exit code drifted between formats — the mcp-list law');

        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$out}");
        self::assertNull($decoded['result'], $out);
        self::assertSame('lsp-install', $decoded['error']['type'] ?? null, $out);
        self::assertStringContainsString('no pinned golang release', (string) ($decoded['error']['message'] ?? ''));
        // Each format arm reached the installer exactly once — two runs,
        // two calls, and the SAME recorded language both times.
        self::assertSame(['golang', 'golang'], $received);
    }

    // ── list ─────────────────────────────────────────────────────────────

    public function testListReportsEveryPinnedServerAndItsState(): void
    {
        [$rc, $out] = $this->runLsp(['lsp', 'list']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);

        // Every language the release table pins has a row, with its pinned
        // version, in table order. The home is empty, so nothing is
        // installed; the empty host still answers 0, never a refusal.
        self::assertMatchesRegularExpression('/^php\s+intelephense@1\.18\.5\s+not installed$/m', $out);
        self::assertMatchesRegularExpression('/^typescript\s+typescript-language-server@6\.0\.2\s+not installed$/m', $out);
        self::assertMatchesRegularExpression('/^python\s+pyright@1\.1\.414\s+not installed$/m', $out);
        self::assertMatchesRegularExpression(
            '/^rust\s+2026-10-26\s+(not installed|no release for [a-z0-9_]+\/[a-z0-9_]+)$/m',
            $out,
            'the rust row lost its pinned release tag or its availability verdict',
        );
    }

    public function testListShowsTheInstallPathOnceTheLaunchProbeFindsTheBinary(): void
    {
        // Plant an executable exactly where the installer would leave the php
        // server, through the installer's own path contract.
        $root = (string) LspInstaller::installRoot();
        self::assertStringStartsWith($this->home, $root, 'installRoot ignored the sandboxed HOME');
        $binDir = $root . '/node_modules/.bin';
        self::assertTrue(mkdir($binDir, 0700, true));
        $binary = $binDir . '/intelephense';
        self::assertNotFalse(file_put_contents($binary, "#!/bin/sh\nexit 0\n"));
        chmod($binary, 0755);

        [$rc, $out] = $this->runLsp(['lsp', 'list']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        self::assertMatchesRegularExpression('/^php\s+intelephense@1\.18\.5\s+' . preg_quote($binary, '/') . '$/m', $out);
        // The other servers were not planted and must still read not installed.
        self::assertMatchesRegularExpression('/^python\s+pyright@1\.1\.414\s+not installed$/m', $out);
    }

    public function testListDocumentCarriesTheRootAndEveryRowShape(): void
    {
        [$rc, $out] = $this->runLsp(['lsp', 'list', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$out}");

        self::assertSame(LspInstaller::installRoot(), $decoded['result']['installRoot'], $out);
        $rows = $decoded['result']['languages'];
        self::assertSame(['php', 'typescript', 'python', 'rust'], array_column($rows, 'language'));
        self::assertSame(['npm', 'npm', 'npm', 'release'], array_column($rows, 'kind'));
        foreach ($rows as $row) {
            self::assertSame(
                ['language', 'kind', 'pinned', 'binary', 'path', 'available', 'os', 'arch'],
                array_keys($row),
            );
            self::assertNull($row['path'], 'the empty sandbox home must read as nothing installed: ' . $row['language']);
        }

        self::assertSame('intelephense', $rows[0]['binary']);
        self::assertSame('pyright-langserver', $rows[2]['binary']);
        self::assertSame('rust-analyzer', $rows[3]['binary']);
        self::assertSame(LspReleases::RUST_ANALYZER_RELEASE_TAG, $rows[3]['pinned']);
        self::assertSame(true, $rows[0]['available']);
        // The npm arm is always installable; the release arm's availability
        // is exactly what the public table says for THIS machine's target.
        $expected = LspReleases::binaryEntry(
            'rust',
            strcasecmp(\PHP_OS_FAMILY, 'Darwin') === 0 ? 'darwin' : 'linux',
            self::lspHostArchForExpectation(),
        ) !== null;
        self::assertSame($expected, $rows[3]['available'], 'the rust availability column diverged from the release table');
    }

    /** The installer's spelling of this machine's arch key, mirrored for the expectation above. */
    private static function lspHostArchForExpectation(): string
    {
        $lower = strtolower(php_uname('m'));
        if ($lower === 'amd64' || $lower === 'x86_64' || $lower === 'x64') {
            return 'x86_64';
        }
        if ($lower === 'arm64' || $lower === 'aarch64') {
            return 'aarch64';
        }

        return $lower;
    }

    // ── completion ───────────────────────────────────────────────────────

    public function testAllThreeCompletionDialectsOfferLspAndItsActions(): void
    {
        $bash = (string) (new \ReflectionMethod(Subcommands::class, 'bashCompletion'))->invoke(null);
        self::assertStringContainsString(' lsp ', $bash, 'bash stopped offering the verb itself');
        self::assertStringContainsString('lsp) COMPREPLY=($(compgen -W "install list" -- "$cur"))', $bash);

        $zsh = (string) (new \ReflectionMethod(Subcommands::class, 'zshCompletion'))->invoke(null);
        self::assertStringContainsString("'lsp:Install and list the pinned language servers'", $zsh);
        self::assertStringContainsString("lsp) _values 'action' install list ;;", $zsh);

        $fish = (string) (new \ReflectionMethod(Subcommands::class, 'fishCompletion'))->invoke(null);
        self::assertStringContainsString("-a 'lsp' -d 'Install and list the pinned language servers'", $fish);
        self::assertStringContainsString("-n '__fish_seen_subcommand_from lsp' -a 'install list'", $fish);
    }

    // ── the target mirror ────────────────────────────────────────────────

    /**
     * `lsp list` derives the host target itself because LspInstaller keeps
     * its derivation private. This pin makes the mirror fail-closed: the
     * installer source and the subcommand source must carry the same
     * comparison tokens, or `list` would report availability against a
     * target the installer never uses.
     */
    public function testTheListTargetMirrorCarriesTheInstallersOwnDerivationTokens(): void
    {
        $installerSource = (string) file_get_contents(
            (new \ReflectionMethod(LspInstaller::class, 'install'))->getFileName(),
        );
        $subcommandSource = (string) file_get_contents(
            (new \ReflectionMethod(Subcommands::class, 'lspHostTarget'))->getFileName(),
        );
        $block = self::lspHostTargetBody($subcommandSource);

        foreach (["'Darwin'", "'darwin'", "'linux'", "'amd64'", "'x86_64'", "'x64'", "'arm64'", "'aarch64'"] as $token) {
            self::assertStringContainsString($token, $installerSource, "the installer stopped comparing {$token}");
            self::assertStringContainsString($token, $block, "the list mirror lost the installer's {$token} comparison");
        }
    }

    private static function lspHostTargetBody(string $source): string
    {
        $start = strpos($source, 'private static function lspHostTarget(');
        self::assertIsInt($start, 'the mirror method vanished from Subcommands — the pin has no subject');
        $body = substr($source, $start, strpos($source, "\n    }", $start) - $start);
        self::assertNotFalse($body);

        return $body;
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * @param (callable(string): array{ok: bool, message: string, path: ?string}) $installer
     * @param list<string> $received
     */
    private function armSeam(array &$received, array $result): void
    {
        Subcommands::setLspInstallSeamForTesting(static function (string $language) use (&$received, $result): array {
            $received[] = $language;

            return $result;
        });
    }

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string}
     */
    private function runLsp(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['candy-code', ...$args]));

        return [$rc, (string) ob_get_clean()];
    }

    /**
     * @param list<string> $args
     */
    private function lspDoorMessage(array $args): string
    {
        [$rc, $stdout] = $this->runLsp([...$args, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$stdout}");
        self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);

        return (string) ($decoded['error']['message'] ?? '');
    }
}
