<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Support\Keychain;

/**
 * C-SEC-8.f — `candy-code login <provider> --keychain`.
 *
 * The flag hands the pasted secret to {@see Keychain::store()} and persists
 * ONLY the `${keychain:<provider>}` reference in the user config. These
 * tests drive the real parser into the real dispatcher with the store behind
 * {@see Keychain::armStoreSeamForTesting()} — no test dials a real keychain.
 * The one real-spawn leg runs through a PATH-shimmed fake `secret-tool` in a
 * private bin directory (the SystemClipboardTest idiom) and asserts where the
 * secret went: the child's stdin, never its argv.
 */
final class LoginKeychainTest extends TestCase
{
    private string $dir = '';

    /** @var list<array<string>> */
    private array $spawned = [];

    /** @var list<array{argv: list<string>, secret: string}> */
    private array $stores = [];

    private string $binDir = '';

    /** Env vars the PATH-shim tests replace; snapshot/restore around each. */
    private const VARS = ['PATH', 'TMUX', 'DISPLAY', 'WAYLAND_DISPLAY'];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_lkch_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        Bootstrap::useConfigPath($this->dir . '/config.json');
        Keychain::resetForTesting();
    }

    protected function tearDown(): void
    {
        Subcommands::resetLoginSeamsForTesting();
        Keychain::resetForTesting();
        $this->restoreShimEnv();
        Bootstrap::useConfigPath(null);

        $this->removeKeychainTree($this->binDir);

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

    private function armKeyPaste(string $key): void
    {
        Subcommands::setLoginSeamsForTesting(null, static fn (): string => $key);
    }

    /**
     * @return array<int, array{0: int, 1: string}>
     */
    private function capture(callable $run): array
    {
        ob_start();
        $code = $run();
        $out = (string) ob_get_clean();

        return [$code, $out];
    }

    /**
     * Replace the store backend call with a recorder answering $status.
     *
     * @param 'ok'|'missing'|'timeout'|'exit-nonzero'|'never' $status
     */
    private function armStoreRecorder(string $status): void
    {
        $this->stores = [];
        Keychain::armStoreSeamForTesting(function (array $argv, string $secret) use ($status): array {
            $this->stores[] = ['argv' => $argv, 'secret' => $secret];

            return match ($status) {
                'ok' => ['status' => 'ok', 'stdout' => '', 'exitCode' => 0],
                'missing' => ['status' => 'missing', 'stdout' => '', 'exitCode' => 127],
                'timeout' => ['status' => 'timeout', 'stdout' => '', 'exitCode' => 124],
                'exit-nonzero' => ['status' => 'miss', 'stdout' => '', 'exitCode' => 1],
                default => ['status' => 'hit', 'stdout' => 'unused', 'exitCode' => 0],
            };
        });
    }

    private function keychainOf(): array
    {
        return json_decode((string) file_get_contents($this->dir . '/config.json'), true)['providers']['openai'];
    }

    // ── parse level ─────────────────────────────────────────────────────

    public function testTheFlagParsesScopedToTheLoginVerb(): void
    {
        $parsed = ArgvParser::parse(['candy-code', 'login', 'openai', '--keychain']);

        $this->assertSame(['--keychain' => true], $parsed->subcommandFlags);
        $this->assertSame([], $parsed->unknownFlags);
    }

    public function testTheFlagIsNotAGlobalOption(): void
    {
        $parsed = ArgvParser::parse(['candy-code', '--keychain', 'login', 'openai']);

        $this->assertContains('--keychain', $parsed->unknownFlags, 'a flag before the verb is scoped out');
    }

    public function testTheSwitchRefusesAValueAtTheParser(): void
    {
        $parsed = ArgvParser::parse(['candy-code', 'login', 'openai', '--keychain=yes']);

        $this->assertNotNull($parsed->usageError);
        $this->assertStringContainsString('--keychain takes no value', $parsed->usageError);
    }

    // ── store seam: argv shape and the stdin law ────────────────────────

    public function testTheLinuxStoreCarriesNamesInArgvAndTheSecretOnStdin(): void
    {
        Keychain::armOsForTesting('Linux');
        $this->armStoreRecorder('ok');
        $this->armKeyPaste('top-secret-XYZ');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai', '--keychain']));

        $this->assertSame(NonInteractive::EXIT_OK, $code, $out);
        $this->assertCount(1, $this->stores);
        $this->assertSame(
            ['secret-tool', 'store', 'service', 'candy-code', 'account', 'openai'],
            $this->stores[0]['argv'],
        );
        $this->assertSame('top-secret-XYZ', $this->stores[0]['secret'], 'the secret belongs to stdin');
        foreach ($this->stores[0]['argv'] as $word) {
            $this->assertStringNotContainsString('top-secret-XYZ', $word, 'argv never carries the secret');
        }
        $this->assertStringContainsString('keychain', $out);
    }

    public function testTheDarwinStoreUsesTheReplacingSpelling(): void
    {
        Keychain::armOsForTesting('Darwin');
        $this->armStoreRecorder('ok');
        $this->armKeyPaste('mac-secret-9');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai', '--keychain']));

        $this->assertSame(NonInteractive::EXIT_OK, $code, $out);
        $this->assertSame(
            ['security', 'add-generic-password', '-U', '-s', 'candy-code', '-a', 'openai', '-w'],
            $this->stores[0]['argv'],
            '-U rotates an existing entry instead of refusing',
        );
        $this->assertSame('mac-secret-9', $this->stores[0]['secret']);
        foreach ($this->stores[0]['argv'] as $word) {
            $this->assertStringNotContainsString('mac-secret-9', $word);
        }
    }

    public function testTheConfigKeepsSiblingsAndStoresOnlyTheReference(): void
    {
        file_put_contents($this->dir . '/config.json', json_encode([
            'theme' => 'dark',
            'providers' => [
                'openai' => ['type' => 'openai', 'model' => 'gpt-4o', 'apiKey' => 'old-key'],
                'dev-sglang' => ['type' => 'sglang', 'baseUrl' => 'http://example.test'],
            ],
        ]));
        Keychain::armOsForTesting('Linux');
        $this->armStoreRecorder('ok');
        $this->armKeyPaste('new-secret-9');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai', '--keychain']));

        $this->assertSame(NonInteractive::EXIT_OK, $code, $out);
        $this->assertSame('openai', $this->keychainOf()['type']);
        $this->assertSame('gpt-4o', $this->keychainOf()['model'], 'rotation keeps sibling keys');
        $this->assertSame('${keychain:openai}', $this->keychainOf()['apiKey']);

        $raw = (string) file_get_contents($this->dir . '/config.json');
        $this->assertStringNotContainsString('new-secret-9', $raw, 'the key itself never lands in the config');
        $this->assertStringContainsString('${keychain:openai}', $raw);
        $this->assertStringContainsString('${keychain:openai}', $out, 'the confirmation names the reference');
        $this->assertStringNotContainsString('OPENAI_API_KEY', $out, 'no env hint when the env is not where it went');
    }

    // ── store failure legs: the config stays byte-identical ─────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function storeFailureLegs(): array
    {
        return [
            'nonzero exit' => ['exit-nonzero', 'Linux', 'without --keychain'],
            'missing backend' => ['missing', 'Linux', 'secret-tool is not installed'],
            'timeout' => ['timeout', 'Linux', 'did not answer in time'],
            'no backend for the family' => ['never', 'Windows', 'no keychain backend exists on Windows'],
        ];
    }

    /**
     * @dataProvider storeFailureLegs
     */
    public function testAFailedStoreLeavesTheConfigByteUntouched(string $leg, string $os, string $needle): void
    {
        $seed = json_encode(['theme' => 'dark', 'providers' => ['anthropic' => ['type' => 'anthropic']]]);
        file_put_contents($this->dir . '/config.json', $seed);
        Keychain::armOsForTesting($os);
        $this->armStoreRecorder($leg);
        $this->armKeyPaste('never-stored-key');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai', '--keychain']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code, $out);
        $this->assertStringContainsString($needle, $out);
        $this->assertStringNotContainsString('never-stored-key', $out, 'the refusal never echoes the secret');
        $this->assertSame($seed, file_get_contents($this->dir . '/config.json'), 'a failed write costs the config nothing');
        if ($leg !== 'never') {
            $this->assertCount(1, $this->stores);
        } else {
            $this->assertCount(0, $this->stores, 'a family without a backend spawns nothing');
        }
    }

    public function testATypeMismatchRefusalAfterTheStoreKeepsTheConfigByteIdentical(): void
    {
        $seed = json_encode(['providers' => ['openai' => ['type' => 'anthropic', 'apiKey' => 'x']]]);
        file_put_contents($this->dir . '/config.json', $seed);
        Keychain::armOsForTesting('Linux');
        $this->armStoreRecorder('ok');
        $this->armKeyPaste('slot-stealing-key');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai', '--keychain']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code, $out);
        $this->assertSame($seed, file_get_contents($this->dir . '/config.json'));
        $this->assertCount(1, $this->stores, 'the entry was written; the next attempt rotates it (-U), so the orphan is honest');
    }

    // ── the flag belongs to the storing arms only ───────────────────────

    public function testAPassthroughArmRefusesTheKeychainFlagWithoutReadingOrSpawning(): void
    {
        $this->spawned = [];
        Subcommands::setLoginSeamsForTesting(
            function (array $cmd): int {
                $this->spawned[] = $cmd;

                return 0;
            },
            static fn (): string => throw new \LogicException('the refusal precedes any key prompt'),
        );

        [$code, $out] = $this->capture(
            fn (): int => $this->dispatch(['login', 'claude-code', '--keychain', '--output-format', 'json']),
        );

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
        $this->assertStringContainsString('claude-code', $out, 'the typed document carries the refusal on stdout');
        $this->assertStringContainsString('--keychain', $out);
        $this->assertSame([], $this->spawned, 'a refused flag never reaches the upstream CLI');
        $this->assertFileDoesNotExist($this->dir . '/config.json');
    }

    public function testAnAmbientArmRefusesTheKeychainFlag(): void
    {
        [$code, $out] = $this->capture(
            fn (): int => $this->dispatch(['login', 'sglang', '--keychain', '--output-format', 'json']),
        );

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
        $this->assertStringContainsString('sglang', $out);
        $this->assertStringNotContainsString('baseUrl', $out, 'the flag refusal replaces the ambient hint');
        $this->assertFileDoesNotExist($this->dir . '/config.json');
    }

    public function testThePlainArmStillWritesTheKeyIntoTheConfig(): void
    {
        $this->armStoreRecorder('never');
        $this->armKeyPaste('plain-key-7');

        [$code, $out] = $this->capture(fn (): int => $this->dispatch(['login', 'openai']));

        $this->assertSame(NonInteractive::EXIT_OK, $code, $out);
        $this->assertSame('plain-key-7', $this->keychainOf()['apiKey'], 'no flag, no keychain — the C-PRV-1.c arm is unchanged');
        $this->assertCount(0, $this->stores);
        $this->assertStringContainsString('OPENAI_API_KEY', $out);
    }

    public function testAnEmptyPasteNeverReachesTheStore(): void
    {
        Keychain::armOsForTesting('Linux');
        $this->armStoreRecorder('ok');
        $this->armKeyPaste('');

        [$code] = $this->capture(fn (): int => $this->dispatch(['login', 'openai', '--keychain']));

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertCount(0, $this->stores, 'validation comes before the keychain');
    }

    // ── Keychain::store guards at the API level ─────────────────────────

    public function testStoreRefusesMalformedNamesAndSecretsWithoutSpawning(): void
    {
        Keychain::armOsForTesting('Linux');
        $this->armStoreRecorder('ok');

        $this->assertSame('refused', Keychain::store('', 'secret'));
        $this->assertSame('refused', Keychain::store('-rf', 'secret'), 'a leading dash is option-parseable');
        $this->assertSame('refused', Keychain::store("bad\name", 'secret'), 'a control byte never reaches argv');
        $this->assertSame('refused', Keychain::store('good-name', ''), 'an empty secret has nothing to store');
        $this->assertSame('refused', Keychain::store('good-name', str_repeat('s', 65536)), 'runaway payloads are out');
        $this->assertCount(0, $this->stores);
    }

    public function testAnUnsupportedFamilyRefusesBeforeTheSeamIsConsulted(): void
    {
        Keychain::armOsForTesting('Windows');
        $this->armStoreRecorder('ok');

        $this->assertSame('unsupported', Keychain::store('candy-x', 'secret'));
        $this->assertCount(0, $this->stores);
        $this->assertNull(Keychain::storeArgvFor('Windows', 'candy-x'));
    }

    public function testASuccessfulStoreDropsAStaleLookupMemo(): void
    {
        Keychain::armOsForTesting('Linux');
        $looked = 0;
        Keychain::armSpawnSeamForTesting(static function (array $argv) use (&$looked): array {
            $looked++;

            return $looked === 1
                ? ['status' => 'miss', 'stdout' => '', 'exitCode' => 1]
                : ['status' => 'hit', 'stdout' => 'fresh-key', 'exitCode' => 0];
        });

        $this->assertNull(Keychain::lookup('candy-x'), 'the first miss memoises');
        $this->armStoreRecorder('ok');
        $this->assertSame('ok', Keychain::store('candy-x', 'fresh-key'));
        $this->assertSame('fresh-key', Keychain::lookup('candy-x'), 'the write invalidates the stale miss');
    }

    // ── the real spawn path: a PATH-shimmed fake, never a real keychain ─

    public function testTheRealStorePathDeliversTheSecretOnStdinAndVerdictsOnExitCode(): void
    {
        // Builtins only: the shim runs with PATH set to its own empty-ish
        // directory, so an external `cat` would not resolve.
        $this->armShimPath(<<<'SH'
echo "$*" > "$KEYCHAIN_SHIM_DIR/argv.txt"
: > "$KEYCHAIN_SHIM_DIR/stdin.txt"
while IFS= read -r line || [ -n "$line" ]; do
    printf '%s\n' "$line" >> "$KEYCHAIN_SHIM_DIR/stdin.txt"
done
exit 0
SH);
        Keychain::armOsForTesting('Linux');

        $this->assertSame('ok', Keychain::store('candy-openai', 'REAL-SECRET-XYZ'));

        $argv = (string) file_get_contents($this->binDir . '/argv.txt');
        $stdin = (string) file_get_contents($this->binDir . '/stdin.txt');
        $this->assertStringContainsString('store service candy-code account candy-openai', $argv);
        $this->assertStringNotContainsString('REAL-SECRET-XYZ', $argv, 'the process listing carries names only');
        $this->assertSame("REAL-SECRET-XYZ\n", $stdin, 'the newline is the line-reading backend\'s EOF');
    }

    public function testTheRealStorePathReportsANonZeroExitAsFailed(): void
    {
        $this->armShimPath(<<<'SH'
while IFS= read -r line || [ -n "$line" ]; do
    :
done
exit 2
SH);
        Keychain::armOsForTesting('Linux');

        $this->assertSame('failed', Keychain::store('candy-openai', 'REAL-SECRET-XYZ'));
    }

    public function testTheRealStorePathReportsAMissingBackendWithoutAWrite(): void
    {
        // The bin directory exists but holds no `secret-tool`: locateOnPath
        // answers '' and nothing is spawned.
        $this->armShimPath('', omitBinary: true);
        Keychain::armOsForTesting('Linux');

        $this->assertSame('missing', Keychain::store('candy-openai', 'REAL-SECRET-XYZ'));
    }

    public function testTheShimProofHoldsBecauseTheReadPathSharesTheSpawn(): void
    {
        $this->armShimPath(<<<'SH'
printf 'read-back-secret\n'
exit 0
SH);
        Keychain::armOsForTesting('Linux');

        $this->assertSame('read-back-secret', Keychain::lookup('candy-openai'), 'the PATH-shim harness proves both directions');
    }

    // ── PATH-shim harness (SystemClipboardTest idiom) ───────────────────

    /**
     * Install a fake `secret-tool` answering $script (shell builtins only)
     * first on PATH, with its record files beside it. $omitBinary installs
     * the harness but no script (the missing-backend leg); the record
     * directory is baked into the script as an export so the shim writes
     * where this test can read it back.
     */
    private function armShimPath(string $script, bool $omitBinary = false): void
    {
        $this->binDir = $this->dir . '/bin';
        mkdir($this->binDir, 0o700, true);

        if (!$omitBinary) {
            $body = "#!/bin/sh\nKEYCHAIN_SHIM_DIR={$this->binDir}; export KEYCHAIN_SHIM_DIR\n" . $script . "\n";
            file_put_contents($this->binDir . '/secret-tool', $body);
            chmod($this->binDir . '/secret-tool', 0o755);
        }

        foreach (self::VARS as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv('PATH=' . $this->binDir);
    }

    private function restoreShimEnv(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->savedEnv = [];
    }

    private function removeKeychainTree(string $root): void
    {
        if ($root === '') {
            return;
        }
        foreach (glob($root . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($root);
    }
}
