<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;

/**
 * PRV-4 tier 2: `candy-code login <provider>`.
 *
 * Every test drives the real parser (ArgvParser::parse) into the real
 * dispatcher, but NEVER a real vendor login: the passthrough arm is pinned
 * through the setLoginSeamsForTesting() spawn seam (recording argv, returning
 * a canned status), and the key-store arm writes only into a --config temp
 * file. The suite would otherwise open browsers and spend accounts.
 */
final class LoginSubcommandTest extends TestCase
{
    private string $dir = '';

    /** @var list<array<string>> */
    private array $spawned = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_login_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        Bootstrap::useConfigPath($this->dir . '/config.json');
    }

    protected function tearDown(): void
    {
        Subcommands::resetLoginSeamsForTesting();
        Bootstrap::useConfigPath(null);

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(array $argv): int
    {
        return Subcommands::dispatch(ArgvParser::parse(array_merge(['candy-code'], $argv)));
    }

    private function armRecordingSpawn(int $status): void
    {
        $this->spawned = [];
        Subcommands::setLoginSeamsForTesting(
            function (array $cmd) use ($status): int {
                $this->spawned[] = $cmd;

                return $status;
            },
            null,
        );
    }

    private function armKeyPaste(string $key): void
    {
        Subcommands::setLoginSeamsForTesting(null, static fn (): string => $key);
    }

    /** Captures the command's echo; failUsage's STDERR is asserted by exit code. */
    private function capture(callable $run): array
    {
        ob_start();
        $code = $run();
        $out = (string) ob_get_clean();

        return [$code, $out];
    }

    public function testBareLoginRefusesAtExitTwo(): void
    {
        $this->assertSame(NonInteractive::EXIT_CONFIG, $this->dispatch(['login']));
    }

    public function testAnUnselectableProviderRefusesAtExitTwo(): void
    {
        $this->assertSame(NonInteractive::EXIT_CONFIG, $this->dispatch(['login', 'not-a-provider']));
    }

    public function testTwoWordsRefuseAtExitTwo(): void
    {
        $this->assertSame(NonInteractive::EXIT_CONFIG, $this->dispatch(['login', 'openai', 'extra']));
    }

    public function testClaudeCodeHandsOverTheMeasuredUpstreamArgv(): void
    {
        $this->armRecordingSpawn(0);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'claude-code']));

        $this->assertSame([['claude', 'auth', 'login']], $this->spawned);
        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertStringContainsString('~/.claude', $out, 'the operator is told WHERE the credential will live');
        $this->assertStringContainsString('exited with code 0', $out);
    }

    public function testCodexHandsOverTheMeasuredUpstreamArgvAndRidesItsStatus(): void
    {
        $this->armRecordingSpawn(3);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'codex']));

        $this->assertSame([['codex', 'login']], $this->spawned);
        $this->assertSame(NonInteractive::EXIT_FAILURE, $code, 'a failed upstream login is this command failing');
        $this->assertStringContainsString('~/.codex', $out);
        $this->assertStringContainsString('exited with code 3', $out);
    }

    public function testAPassthroughBinaryAbsentFromPathNamesItselfInsteadOfSpawning(): void
    {
        // Seam removed: the real pre-check/proc_open path must classify an
        // unlaunchable binary as -1. PATH is pointed at an empty dir, so this
        // holds identically with and without the containment wrapper.
        $empty = $this->dir . '/bin-empty';
        mkdir($empty, 0o700, true);
        Subcommands::setLoginSeamsForTesting(null, null);

        $savedPath = getenv('PATH');
        putenv('PATH=' . $empty);

        try {
            [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'codex']));
        } finally {
            putenv(false === $savedPath ? 'PATH' : 'PATH=' . $savedPath);
        }

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('not found on PATH', $out);
    }

    public function testTheKeyArmPersistsThroughTheExistingUserConfigWriter(): void
    {
        $this->armKeyPaste("  sk-probe-key-9  \n");

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $saved = json_decode((string) file_get_contents($this->dir . '/config.json'), true);
        $this->assertSame(
            ['type' => 'openai', 'apiKey' => 'sk-probe-key-9'],
            $saved['providers']['openai'] ?? null,
            'the entry carries the type providerOverrides() requires and the trimmed key',
        );
        $this->assertStringContainsString('OPENAI_API_KEY', $out, 'the precedence of the env var is disclosed');
        $this->assertStringNotContainsString('sk-probe-key-9', $out, 'the key never echoes back');
    }

    public function testStoringOneKeyKeepsEveryOtherProviderEntry(): void
    {
        file_put_contents($this->dir . '/config.json', json_encode([
            'theme' => 'dark',
            'providers' => ['dev-sglang' => ['type' => 'sglang', 'baseUrl' => 'http://example.test']],
        ]));
        $this->armKeyPaste('sk-anthropic-7');

        [$code] = $this->capture(fn (): int => $this->dispatch(['login', 'anthropic']));

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $saved = json_decode((string) file_get_contents($this->dir . '/config.json'), true);
        $this->assertSame('dark', $saved['theme'] ?? null);
        $this->assertSame(
            ['type' => 'sglang', 'baseUrl' => 'http://example.test'],
            $saved['providers']['dev-sglang'] ?? null,
            'the shallow top-level merge of writeUserConfig() is hand-compensated: the sibling map survives',
        );
        $this->assertSame('sk-anthropic-7', $saved['providers']['anthropic']['apiKey'] ?? null);
    }

    public function testAnEmptyPasteWritesNothing(): void
    {
        $this->armKeyPaste("   \n");

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'gemini']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('nothing was written', $out);
        $this->assertFileDoesNotExist($this->dir . '/config.json');
    }

    public function testControlCharactersInAPasteRefuseTheWrite(): void
    {
        $this->armKeyPaste("sk-\x1b[31mred");

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('control characters', $out);
        $this->assertFileDoesNotExist($this->dir . '/config.json');
    }

    public function testACorruptConfigFileRefusesRatherThanTrashingIt(): void
    {
        file_put_contents($this->dir . '/config.json', '{not json');
        $this->armKeyPaste('sk-whatever');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('not valid JSON', $out);
        $this->assertSame('{not json', file_get_contents($this->dir . '/config.json'));
    }

    /**
     * PRV-4 micro-pin (i): the post-write re-read is the ONLY thing separating
     * a silent writeUserConfig() bail from an announced persist. The writer
     * returns void and walks away quietly when acquireUserConfigLock() times
     * out; with the re-read dropped, this whole suite stayed GREEN, which is
     * another way of saying the command could print "Stored the openai API
     * key" about bytes that never reached the disk.
     *
     * flock() locks are per open file description, so holding the sidecar on
     * a SECOND handle from this very process reproduces the concurrent-writer
     * case the bail exists for in full — at the honest price of one bounded
     * TimedFileLock::DEFAULT_WAIT_SECONDS spin before the writer gives up.
     */
    public function testAWriteThatBailedUnderLockContentionIsCaughtByTheReReadAndNotAnnouncedAsStored(): void
    {
        $configPath = $this->dir . '/config.json';
        file_put_contents($configPath, '{"theme":"dark"}');
        $this->armKeyPaste('sk-lost-in-the-lock-3');

        $lock = fopen($this->dir . '/.config.json.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, \LOCK_EX), 'two handles, one file: flock still contends');

        try {
            [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('could not be updated', $out);
        $this->assertStringNotContainsString('Stored the', $out, 'a bail must never borrow the success wording');
        $this->assertSame(
            '{"theme":"dark"}',
            file_get_contents($configPath),
            'the silent bail must also have left the file byte-for-byte the planted one',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ambientProviderProvider(): array
    {
        return [
            'sglang' => ['sglang', 'baseUrl'],
            'bedrock' => ['bedrock', 'AWS'],
            'vertex' => ['vertex', 'application-default'],
            'custom' => ['custom', 'providers'],
        ];
    }

    /**
     * @dataProvider ambientProviderProvider
     */
    public function testAmbientProvidersAnswerWithTheirRealCredentialOwner(string $type, string $needle): void
    {
        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', $type]));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code, 'the requested login did not happen');
        $this->assertStringContainsString($needle, $out, "the refusal must name who actually owns {$type} credentials");
        $this->assertFileDoesNotExist($this->dir . '/config.json');
    }
}
