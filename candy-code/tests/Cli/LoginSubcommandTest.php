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

    /**
     * Fake for the `$loginTty` seam: records the mode handshake, feeds
     * queued bytes one at a time, and can throw from the byte read — all
     * with no terminal anywhere. Returns the live fake so tests can assert
     * its counters and call order.
     *
     * @param list<string|false> $bytes
     */
    private function armEchoOffTty(array $bytes, ?\Throwable $thrown = null): object
    {
        $fake = new class($bytes, $thrown) {
            public int $enableRawModeCalls = 0;

            public int $restoreCalls = 0;

            /** @var list<string> */
            public array $callOrder = [];

            /** @param list<string|false> $bytes */
            public function __construct(private array $bytes, private ?\Throwable $thrown)
            {
            }

            public function isTty(): bool
            {
                $this->callOrder[] = 'isTty';

                return true;
            }

            public function enableRawMode(): void
            {
                $this->enableRawModeCalls++;
                $this->callOrder[] = 'enableRawMode';
            }

            public function getc(): string|false
            {
                $this->callOrder[] = 'getc';

                if ($this->thrown !== null) {
                    throw $this->thrown;
                }

                return \array_shift($this->bytes) ?? false;
            }

            public function restore(): void
            {
                $this->restoreCalls++;
                $this->callOrder[] = 'restore';
            }
        };

        Subcommands::setLoginSeamsForTesting(null, null, static fn (): object => $fake);

        return $fake;
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

    public function testRotatingAKeyKeepsTheExistingProviderBlocksSettings(): void
    {
        file_put_contents($this->dir . '/config.json', json_encode([
            'providers' => [
                'openai' => [
                    'type' => 'openai',
                    'apiKey' => 'sk-old-1',
                    'model' => 'gpt-4o-probe',
                    'thinkingBudget' => 4096,
                    'modelPrices' => ['in' => 0.003, 'out' => 0.015],
                ],
            ],
        ]));
        $this->armKeyPaste('sk-new-2');

        [$code] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $saved = json_decode((string) file_get_contents($this->dir . '/config.json'), true);
        $block = $saved['providers']['openai'] ?? null;
        $this->assertIsArray($block);
        $this->assertSame('sk-new-2', $block['apiKey'] ?? null, 'the rotated key landed');
        $this->assertSame('openai', $block['type'] ?? null);
        $this->assertSame('gpt-4o-probe', $block['model'] ?? null, 'C-PRV-1.c: a rotation must not wipe model');
        $this->assertSame(4096, $block['thinkingBudget'] ?? null, '...nor thinkingBudget');
        $this->assertSame(['in' => 0.003, 'out' => 0.015], $block['modelPrices'] ?? null, '...nor modelPrices');
    }

    public function testRotatingAGatewayKeyKeepsItsBaseUrl(): void
    {
        file_put_contents($this->dir . '/config.json', json_encode([
            'providers' => [
                'anthropic' => ['type' => 'anthropic', 'apiKey' => 'sk-ant-old', 'baseUrl' => 'https://gateway.example/v1'],
            ],
        ]));
        $this->armKeyPaste('sk-ant-new');

        [$code] = $this->capture(fn (): int => $this->dispatch(['login', 'anthropic']));

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $saved = json_decode((string) file_get_contents($this->dir . '/config.json'), true);
        $this->assertSame('https://gateway.example/v1', $saved['providers']['anthropic']['baseUrl'] ?? null, 'the gateway endpoint is the worst thing a key rotation could silently eat');
        $this->assertSame('sk-ant-new', $saved['providers']['anthropic']['apiKey'] ?? null);
    }

    public function testABlockOwnedByADifferentTypeRefusesAndLeavesTheFileByteIdentical(): void
    {
        $planted = json_encode([
            'providers' => ['openai' => ['type' => 'custom', 'apiKey' => 'sk-hand-rolled', 'baseUrl' => 'https://gw.example']],
        ], \JSON_PRETTY_PRINT);
        file_put_contents($this->dir . '/config.json', $planted);
        $this->armKeyPaste('sk-would-overwrite');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('as type custom', $out, 'the refusal names the type already in the slot');
        $this->assertStringContainsString('was not written', $out);
        $this->assertSame(
            $planted,
            file_get_contents($this->dir . '/config.json'),
            'a refused rotation leaves every byte — the hand-tuned baseUrl and its key included',
        );
    }

    public function testABlockWithoutATypeKeepsItsFieldsAndGainsOne(): void
    {
        file_put_contents($this->dir . '/config.json', json_encode([
            'providers' => ['gemini' => ['apiKey' => 'hand-old', 'baseUrl' => 'https://hand.example']],
        ]));
        $this->armKeyPaste('sk-g-new');

        [$code] = $this->capture(fn (): int => $this->dispatch(['login', 'gemini']));

        $this->assertSame(NonInteractive::EXIT_OK, $code, 'an untyped block contradicts nothing; it merges and gains a type');
        $saved = json_decode((string) file_get_contents($this->dir . '/config.json'), true);
        $block = $saved['providers']['gemini'] ?? [];
        ksort($block);
        $this->assertSame(
            ['apiKey' => 'sk-g-new', 'baseUrl' => 'https://hand.example', 'type' => 'gemini'],
            $block,
        );
    }

    public function testTheEchoOffReaderAssemblesTheTypedLineAndHandsTheTerminalBack(): void
    {
        // "sk-x" mistyped, DEL erases it, "probe" typed, three Backspaces
        // erase "obe", then "z" + Enter: the stored key is what survives.
        $tty = $this->armEchoOffTty(['s', 'k', '-', 'x', "\x7F", 'p', 'r', 'o', 'b', 'e', "\x08", "\x08", "\x08", 'z', "\r"]);
        [$code] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $saved = json_decode((string) file_get_contents($this->dir . '/config.json'), true);
        $this->assertSame('sk-prz', $saved['providers']['openai']['apiKey'] ?? null, 'each typed byte joins the line; each Backspace erases exactly one before the caret');
        $this->assertSame(1, $tty->enableRawModeCalls);
        $this->assertSame(1, $tty->restoreCalls, 'the terminal is handed back exactly once');
        $this->assertSame(['isTty', 'enableRawMode'], array_slice($tty->callOrder, 0, 2), 'raw mode comes before the first byte is waited on');
        $this->assertSame('restore', $tty->callOrder[\count($tty->callOrder) - 1]);
    }

    public function testCtrlCCancelsTheEchoOffReadWithoutWriting(): void
    {
        $tty = $this->armEchoOffTty(['s', 'k', '-', 'p', "\x03"]);

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('nothing was written', $out, 'Ctrl-C lands on the empty-key refusal — a half-typed key is thrown away, never stored');
        $this->assertFileDoesNotExist($this->dir . '/config.json');
        $this->assertSame(1, $tty->restoreCalls, 'Ctrl-C is an exit path and it restores too');
    }

    public function testTheTerminalIsHandedBackAfterAThrownRead(): void
    {
        $sentinel = 'login-probe-read-' . uniqid('', true);
        $tty = $this->armEchoOffTty([], new \RuntimeException($sentinel));

        $level = ob_get_level();
        try {
            $this->capture(fn (): int => $this->dispatch(['login', 'openai']));
            $this->fail("a throwing byte read must propagate, not launder into a calm refusal ({$sentinel})");
        } catch (\RuntimeException $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->assertSame($sentinel, $e->getMessage());
        }

        $this->assertSame(1, $tty->restoreCalls, 'finally hands the terminal back even when the read itself throws');
        $this->assertSame('restore', $tty->callOrder[\count($tty->callOrder) - 1]);
        $this->assertFileDoesNotExist($this->dir . '/config.json');
    }

    public function testThePasteSeamLeavesTheTtyHandleUntouched(): void
    {
        $fake = $this->armEchoOffTty([]);
        Subcommands::setLoginSeamsForTesting(null, static fn (): string => "sk-piped-1\n", static fn (): object => $fake);

        [$code] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertSame([], $fake->callOrder, 'arming $loginKeyRead keeps the suite TTY-free — the handle is never even opened');
    }

    public function testTheExternalInterruptGuardRestoresTheTerminalBeforeItDies(): void
    {
        // The live SIGINT path cannot be reproduced without a terminal; the
        // SHAPE that must hold is pinable from the source: while the read is
        // in flight SIGINT/SIGTERM are caught, the handle goes back FIRST,
        // and the signal is re-raised at default disposition.
        $method = new \ReflectionMethod(Subcommands::class, 'armLoginKeySignals');
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);
        $body = implode('', \array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString('SIGINT', $body);
        $this->assertStringContainsString('SIGTERM', $body);
        $restore = \strpos($body, '$tty->restore()');
        $default = \strpos($body, '\\SIG_DFL');
        $kill = \strrpos($body, 'posix_kill');
        $this->assertIsInt($restore);
        $this->assertIsInt($default);
        $this->assertIsInt($kill);
        $this->assertLessThan($default, $restore, 'the guard hands the terminal back before re-raising the signal');
        $this->assertLessThan($kill, $default, 'and re-raises at default disposition — restored, then killed true');
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
     * PRV-4 micro-pin (ii): the OTHER silent-bail shape the re-read answers
     * for. userConfigWriteTarget() refuses to write through a symlink that
     * resolves to nothing, and writeUserConfig() returns void on that refusal
     * — without the re-read the command would announce success anyway, and
     * this also pins the stronger half: the refusing write never MINTS the
     * far end of the dangling link.
     */
    public function testADanglingSymlinkedConfigPathCostsTheHonestFailureAndMintsNoTarget(): void
    {
        $target = $this->dir . '/elsewhere.json';
        $this->assertTrue(symlink($target, $this->dir . '/config.json'));
        $this->armKeyPaste('sk-dangling-8');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertStringContainsString('could not be updated', $out);
        $this->assertStringNotContainsString('Stored the', $out);
        $this->assertFileDoesNotExist($target, 'a write that refuses to traverse must not create the far end');
        $this->assertTrue(
            is_link($this->dir . '/config.json'),
            'the link itself survives — the command never "repairs" a config path out from under the operator',
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
