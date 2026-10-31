<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;

/**
 * C-TUI-5.b: `candy-code import claude` driven end-to-end through the real
 * parser and dispatcher against a redirected HOME.
 *
 * The posture these tests exist to pin is the security one: a foreign
 * settings document is UNTRUSTED INPUT — DENY WINS over any allow in the
 * same file, every widening is enumerated in the preview, nothing writes
 * without `--yes` (or a typed confirmation, unrepresentable under a piped
 * test stdin, so the piped refusal is the pinned leg), a malformed file
 * refuses loudly with the config byte-unchanged, and the write lands only
 * on the user tier through the sanctioned settings editor. The imported
 * `model` follows decision D9: it rides the per-provider `models` map under
 * the selected provider, and becomes a named skip on an install with no
 * provider at all.
 */
final class ImportSubcommandTest extends TestCase
{
    private string $dir = '';
    private string $home = '';
    private string $configPath = '';
    private string $userSettings = '';
    private string $project = '';
    private string $originalHome;
    private mixed $originalServerHome;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/import_sub_' . uniqid('', true);
        $this->home = $this->dir . '/home';
        $this->project = $this->dir . '/project';
        mkdir($this->home . '/.candy-code', 0o700, true);
        mkdir($this->home . '/.claude', 0o700, true);
        mkdir($this->project, 0o700, true);

        $this->configPath = $this->home . '/.candy-code/config.json';
        $this->userSettings = $this->home . '/.claude/settings.json';

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        // A provider selected from the ambient environment would outrank the
        // per-test config, and a shell-out backend tier nulls the selection;
        // both must be out of the way for the model tests to mean what they
        // say. Same hygiene as {@see BootstrapHookFileTest}.
        foreach (['CANDY_CODE_PROVIDER', 'CANDY_CODE_BACKEND_CMD', 'CANDY_CODE_BACKEND_CMD_STREAM', 'CANDY_CODE_MODEL'] as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }

        Bootstrap::useConfigPath($this->configPath);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);

        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        foreach ($this->originalEnv as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }

        $this->removeTree($this->dir);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(array $argv): int
    {
        return Subcommands::dispatch(ArgvParser::parse(array_merge(['candy-code'], $argv)));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function capture(callable $run): array
    {
        ob_start();
        $code = $run();
        $out = (string) ob_get_clean();

        return [$code, $out];
    }

    /** @param array<string, mixed> $doc */
    private function writeUserSettings(array $doc): void
    {
        file_put_contents($this->userSettings, (string) json_encode($doc));
    }

    private function readConfig(): array
    {
        clearstatcache();
        return is_file($this->configPath)
            ? (array) (json_decode((string) file_get_contents($this->configPath), true) ?? [])
            : [];
    }

    // ---------------------------------------------------------------- doors

    /**
     * The usage doors go through {@see NonInteractive::failUsage()}: in text
     * mode the sentence lands on stderr (not observable in-process — the bin
     * dispatch integration pins that leg), while `--output-format json` puts
     * the same message in a stdout envelope the capture can read. Same idiom
     * as {@see SubcommandsMcpImportTest}.
     *
     * @param list<string> $argv
     */
    private function usageDoorMessage(array $argv): string
    {
        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['--output-format', 'json', ...$argv]));

        self::assertSame(NonInteractive::EXIT_CONFIG, $code, $out);
        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, 'stdout was not a JSON envelope: ' . $out);
        self::assertSame('usage', $decoded['error']['type'] ?? null, $out);

        return (string) ($decoded['error']['message'] ?? '');
    }

    public function testMissingSourceIsAUsageRefusal(): void
    {
        $message = $this->usageDoorMessage(['import']);

        self::assertStringContainsString('No import source given', $message);
    }

    public function testUnknownSourceNamesItself(): void
    {
        $message = $this->usageDoorMessage(['import', 'bogus']);

        self::assertStringContainsString('Unknown import source bogus', $message);
    }

    public function testImportClaudeTakesNoPathOperand(): void
    {
        $message = $this->usageDoorMessage(['import', 'claude', '/tmp/other.json']);

        self::assertStringContainsString('Unexpected operand /tmp/other.json', $message);
    }

    public function testTextModeUsageStaysSilentOnStdout(): void
    {
        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import']));

        self::assertSame(NonInteractive::EXIT_CONFIG, $code);
        self::assertSame('', $out, 'a text-mode usage error must not write to stdout');
    }

    public function testNothingToImportIsQuietSuccess(): void
    {
        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude']));

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertStringContainsString('No Claude settings file at ' . $this->userSettings, $out);
        self::assertFileDoesNotExist($this->configPath, 'the no-op must not mint a config file');
    }

    public function testProjectScopeWithoutAProjectFileIsARefusal(): void
    {
        $this->writeUserSettings(['permissions' => ['deny' => ['Write']]]);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(
            ['--root', $this->project, 'import', 'claude', '--project', '--yes'],
        ));

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);
        self::assertStringContainsString('No Claude settings file at ' . $this->project . '/.claude/settings.json', $out);
        self::assertFileDoesNotExist($this->configPath);
    }

    // ------------------------------------------------------- preview posture

    public function testPreviewRefusesToWriteUnattendedAndLeaksNoBytes(): void
    {
        $this->writeUserSettings(['permissions' => ['allow' => ['Bash(git:*)']]]);
        file_put_contents($this->configPath, '{}');
        $before = (string) file_get_contents($this->configPath);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude']));

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);
        self::assertStringContainsString('preview (nothing written yet)', $out);
        self::assertStringContainsString('  allow  Bash(git *)', $out);
        self::assertStringContainsString('1 of these are allow rules that WIDEN', $out);
        self::assertStringContainsString('Refusing to write without confirmation', $out,
            'a piped stdin must never stand in for the typed confirmation');
        self::assertSame($before, file_get_contents($this->configPath));
    }

    public function testAnEmptyDocumentSaysNothingWasImportable(): void
    {
        $this->writeUserSettings([]);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertStringContainsString('nothing candy-code has a home for', $out);
        self::assertFileDoesNotExist($this->configPath);
    }

    // ------------------------------------------------------------- the write

    public function testYesWritesTheUserTierWithDenyWinningAndForeignKeysNamed(): void
    {
        $this->writeUserSettings([
            'permissions' => [
                'allow' => ['Bash(git:*)'],
                'deny'  => ['Bash(git:*)', 'Bash(rm:*)'],
            ],
            'hooks' => ['PreToolUse' => []],
            'env'   => ['A' => 'b'],
            'model' => 'claude-sonnet-x',
        ]);
        file_put_contents($this->configPath, '{"theme":"dark"}');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));

        self::assertSame(NonInteractive::EXIT_OK, $code, $out);
        // The vetoed allow is ENUMERATED, not silently dropped — the widening
        // the document asked for must be visible to whoever reads the preview.
        self::assertStringContainsString('vetoed    Bash(git *)', $out);
        self::assertStringContainsString('skipped   hooks', $out);
        self::assertStringContainsString('skipped   env', $out);
        // No provider is selected on this install: the model becomes a named
        // skip rather than a config entry nothing reads.
        self::assertStringContainsString('skipped   model', $out);
        self::assertStringContainsString('no provider selected yet', $out);
        self::assertStringContainsString('Wrote 2 new rule(s)', $out);

        $after = $this->readConfig();
        self::assertSame('dark', $after['theme'] ?? null, 'unrelated keys survive');
        self::assertSame([
            ['pattern' => 'Bash(git *)', 'action' => 'deny'],
            ['pattern' => 'Bash(rm *)', 'action' => 'deny'],
        ], $after['permissionRules'] ?? null);
        self::assertArrayNotHasKey('model', $after, 'candy-code has no scalar model key');
        self::assertArrayNotHasKey('models', $after);

        // Re-running is a no-op on the rules.
        [$againCode, $againOut] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));
        self::assertSame(NonInteractive::EXIT_OK, $againCode);
        self::assertStringContainsString('Wrote 0 new rule(s)', $againOut);
    }

    /**
     * MINOR-2 fix pin (C-TUI-5.b review round 1): the widening note speaks
     * about "these" — the rule rows just printed — so its count must be the
     * rules that LAND as allow, not the whole widenings list. A document
     * mixing one allow that survives with one allow the same document's deny
     * vetoes prints both enumerated (the vetoed one as a rule row and a
     * vetoed row) yet only one printed rule widens: the landed allow.
     * Counting `widenings` here would print 2 and overstate the write.
     */
    public function testTheWideningNoteCountsLandedAllowsNotTheVetoedOnes(): void
    {
        $this->writeUserSettings(['permissions' => [
            'allow' => ['Bash(git:*)', 'Bash(npm:*)'],
            'deny'  => ['Bash(npm:*)'],
        ]]);
        file_put_contents($this->configPath, '{"theme":"dark"}');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));

        self::assertSame(NonInteractive::EXIT_OK, $code, $out);
        // Both allow-spelled patterns stay visible in the preview...
        self::assertStringContainsString('  allow  Bash(git *)', $out);
        self::assertStringContainsString('  deny  Bash(npm *)', $out);
        self::assertStringContainsString('vetoed    Bash(npm *)', $out);
        // ...but the note counts what the write actually widens with.
        self::assertStringContainsString('1 of these are allow rules that WIDEN', $out);
        self::assertStringNotContainsString('2 of these are allow rules', $out);
        self::assertStringContainsString('Wrote 2 new rule(s)', $out);

        $after = $this->readConfig();
        self::assertSame('dark', $after['theme'] ?? null, 'unrelated keys survive');
        self::assertSame([
            ['pattern' => 'Bash(npm *)', 'action' => 'deny'],
            ['pattern' => 'Bash(git *)', 'action' => 'allow'],
        ], $after['permissionRules'] ?? null, 'the vetoed allow never lands as an allow');
    }

    public function testTheImportedModelLandsUnderTheSelectedProvider(): void
    {
        $this->writeUserSettings(['model' => 'claude-sonnet-x']);
        file_put_contents($this->configPath, '{"provider":"anthropic","models":{"openai":"gpt-keep-me"}}');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));

        self::assertSame(NonInteractive::EXIT_OK, $code, $out);
        self::assertStringContainsString('  model     claude-sonnet-x', $out);
        self::assertStringContainsString('The imported model is selected as well.', $out);

        $after = $this->readConfig();
        self::assertSame('anthropic', $after['provider'] ?? null);
        self::assertSame('claude-sonnet-x', $after['models']['anthropic'] ?? null);
        self::assertSame('gpt-keep-me', $after['models']['openai'] ?? null,
            'another provider entry is never lost');
    }

    public function testProjectAndUserFilesMergeUnderDenyWins(): void
    {
        $this->writeUserSettings(['permissions' => ['allow' => ['Bash(git:*)']]]);
        mkdir($this->project . '/.claude', 0o700, true);
        file_put_contents($this->project . '/.claude/settings.json', (string) json_encode([
            'permissions' => ['deny' => ['Bash(git:*)']],
        ]));

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(
            ['--root', $this->project, 'import', 'claude', '--project', '--yes'],
        ));

        self::assertSame(NonInteractive::EXIT_OK, $code, $out);
        self::assertStringContainsString('vetoed    Bash(git *)', $out,
            'the project deny vetoes the user allow across files');
        $after = $this->readConfig();
        self::assertSame([['pattern' => 'Bash(git *)', 'action' => 'deny']], $after['permissionRules'] ?? null);
    }

    // ------------------------------------------------------------ fail-loud

    public function testAMalformedFileRefusesLoudlyAndWritesNothing(): void
    {
        file_put_contents($this->userSettings, '{"permissions": ');
        file_put_contents($this->configPath, '{}');
        $before = (string) file_get_contents($this->configPath);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);
        self::assertStringContainsString('is not valid JSON', $out);
        self::assertStringContainsString($this->userSettings, $out, 'the refusal names the file');
        self::assertSame($before, file_get_contents($this->configPath), 'no partial application, ever');
    }

    public function testASymlinkedSettingsFileIsRefused(): void
    {
        if (!@symlink('/etc/hostname', $this->userSettings)) {
            self::markTestSkipped('symlinks unavailable on this platform');
        }

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);
        self::assertStringContainsString('not a plain readable file', $out);
    }

    /**
     * A pattern the grammar gate refuses is skipped AND named, never guessed
     * into some nearby valid shape.
     */
    public function testRejectedGrammarIsShownNotApplied(): void
    {
        $this->writeUserSettings(['permissions' => ['allow' => ['Read(', 'Read']]]);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['import', 'claude', '--yes']));

        self::assertSame(NonInteractive::EXIT_OK, $code, $out);
        self::assertStringContainsString('rejected  Read(', $out);
        self::assertStringContainsString('skipped and named, never applied', $out);

        $after = $this->readConfig();
        self::assertSame([['pattern' => 'Read', 'action' => 'allow']], $after['permissionRules'] ?? null);
    }
}
