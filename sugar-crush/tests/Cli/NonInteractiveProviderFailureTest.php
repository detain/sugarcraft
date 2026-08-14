<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * crush_code.md Phase 0 item 10 (§5): a misconfigured provider on the one-shot
 * `-p`/`run` path used to silently degrade to the offline `EchoProvider` and
 * still exit 0, so a CI caller got a plausible canned sentence with no way to
 * tell the model was never called.
 *
 * The fix is an ASYMMETRY, not a global strictness change, so this class
 * asserts both halves of it against the same environment:
 *
 *  - one-shot ({@see NonInteractive::run()}) resolves an explicitly selected
 *    provider through {@see Bootstrap::backendFor()} and hard-fails;
 *  - interactive ({@see Bootstrap::backend()}, which `bin/sugarcrush` reaches
 *    via `Bootstrap::app()`) still warns and degrades, so a developer with no
 *    API key exported still gets a usable offline editor session.
 *
 * Nothing here makes a network call. Every provider in this codebase is
 * constructed without I/O (the factories only build HTTP/SDK clients), so a
 * construction failure is reproducible offline and a construction SUCCESS —
 * asserted below for `dev-sglang` — is equally offline. The one place a real
 * request would happen, `Backend::complete()`, is only ever reached in this
 * file with an in-process fake or the offline echo backend.
 *
 * HOME is redirected to a temp directory for the whole class: the real
 * ~/.sugar-crush/config.json on a developer machine very often carries a
 * persisted `provider` from a previous Ctrl+P "Switch model", and
 * {@see Bootstrap::selectedProviderName()} honours it — without the redirect,
 * the "nothing configured" cases below would silently be testing that
 * developer's provider instead.
 */
final class NonInteractiveProviderFailureTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * Every variable that can steer provider selection, provider CONSTRUCTION,
     * or where the persisted selection is read from. All are cleared in
     * setUp() so each case states its own configuration explicitly, and
     * restored in tearDown() so the rest of the suite is unaffected.
     *
     * Deliberately over-complete rather than "the ones today's cases happen to
     * use". The hazard is concrete: adding an `anthropic` row to
     * {@see self::unusableProviders()} on a developer machine that exports
     * `$ANTHROPIC_API_KEY` would construct successfully, quietly stop testing
     * the thing the row was added for, and read a real credential — the exact
     * failure this file's HOME redirect exists to close, one variable over. So
     * the list is every name `grep -rho "getenv('[A-Z_]*')" src/` reports that
     * can reach a provider (plus the AWS ambient-credential set the Bedrock
     * SDK resolves internally, which never appears in a getenv() call here).
     * Two `src/` hits are deliberately absent: `PATH`, because clearing it
     * would break the shell-out backend this file drives on purpose, and
     * `SUGAR_CRUSH_WORKTREES_DIR`/`SUGARCRUSH_SEARCH_ENDPOINT`/
     * `SUGAR_CRUSH_SHARE_UPLOAD_URL`, which steer tools and sharing, never
     * which backend a run selects.
     */
    private const ENV_KEYS = [
        // Where the persisted Ctrl+P choice is read from, on both platforms.
        'HOME',
        'USERPROFILE',
        // The selection chain itself.
        'SUGARCRUSH_PROVIDER',
        'SUGARCRUSH_MODEL',
        'SUGARCRUSH_TITLE_MODEL',
        'SUGARCRUSH_BACKEND_CMD',
        'SUGARCRUSH_CONNECT_TIMEOUT',
        // Everything ProviderFactory::defaultConfig() and the provider
        // constructors read, i.e. everything that decides whether a given
        // provider name constructs or throws.
        'OPENAI_API_KEY',
        'OPENAI_ORG_ID',
        'ANTHROPIC_API_KEY',
        'ANTHROPIC_AUTH_TOKEN',
        'ANTHROPIC_BASE_URL',
        'SGLANG_API_KEY',
        'GCP_PROJECT_ID',
        'GOOGLE_APPLICATION_CREDENTIALS',
        // Bedrock has no getenv() of its own: the AWS SDK resolves ambient
        // credentials from these, so they are what would decide whether a
        // 'bedrock' row constructs.
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_SESSION_TOKEN',
        'AWS_PROFILE',
        'AWS_REGION',
        'AWS_DEFAULT_REGION',
        'AWS_SHARED_CREDENTIALS_FILE',
        'AWS_CONFIG_FILE',
        'AWS_WEB_IDENTITY_TOKEN_FILE',
        'AWS_ROLE_ARN',
    ];

    /**
     * A provider name that is neither a built-in type nor an entry under
     * 'providers' in .sugar-crush/config.dev.json, so
     * `ProviderFactory::defaultConfig()` throws "Unknown provider type".
     */
    private const UNKNOWN_PROVIDER = 'definitely-not-a-real-provider';

    /**
     * The project's own config.dev.json entry: constructs cleanly with no
     * network I/O, which is exactly what makes it usable as the "a valid
     * provider still works" fixture without ever contacting the server it
     * names.
     */
    private const VALID_PROVIDER = 'dev-sglang';

    private string $tempDir;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/noninteractive_provider_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        mkdir($this->tempDir . '/repo', 0700, true);

        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }

        // BOTH spellings -- see HomeSandboxTrait.
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if (is_string($value)) {
                putenv($key . '=' . $value);
            } else {
                putenv($key);
            }
        }
        $this->savedEnv = [];
        $this->restoreHomeSandbox();

        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The item itself: an explicitly selected, unusable provider hard-fails
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableProviders(): array
    {
        return [
            // MISCONFIGURED, shape 1: a real provider type whose required
            // credential is absent. This is the exact invocation crush_code.md
            // §5 reproduced live (`SUGARCRUSH_PROVIDER=openai OPENAI_API_KEY=`)
            // and watched return a canned Echo reply at exit 0.
            'known type, missing credential' => ['openai'],
            // MISCONFIGURED, shape 2: a name that does not resolve at all —
            // the typo case.
            'unknown provider name' => [self::UNKNOWN_PROVIDER],
        ];
    }

    /**
     * @dataProvider unusableProviders
     */
    public function testExplicitlySelectedUnusableProviderExitsTwoInsteadOfAnsweringFromEcho(string $provider): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . $provider);
        $args = ArgvParser::parse(['sugarcrush', '-p', 'review this diff', '--root', $this->tempDir . '/repo']);

        ob_start();
        $code = NonInteractive::run($args);
        $stdout = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
        // The whole point: no canned sentence on stdout for a caller to
        // mistake for a model's answer.
        $this->assertSame('', $stdout);
    }

    /**
     * The same selection made by a previous Ctrl+P "Switch model" rather than
     * by `$SUGARCRUSH_PROVIDER` must fail identically — it is just as much
     * "this run asked for a specific provider", and a scripted caller reading
     * only stdout cannot tell the two apart.
     */
    public function testPersistedProviderChoiceIsAlsoTreatedAsExplicit(): void
    {
        Bootstrap::writeUserConfig(['provider' => self::UNKNOWN_PROVIDER]);
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi', '--root', $this->tempDir . '/repo']);

        ob_start();
        $code = NonInteractive::run($args);
        ob_get_clean();

        $this->assertSame(self::UNKNOWN_PROVIDER, Bootstrap::selectedProviderName());
        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
    }

    /**
     * `$SUGARCRUSH_BACKEND_CMD` outranks a persisted provider in
     * {@see Bootstrap::backend()}'s chain, so it must outrank it here too —
     * otherwise a shell-out backend would start hard-failing over a stale
     * persisted name it never had any intention of using.
     *
     * Driven through {@see NonInteractive::run()} itself, not just the two
     * selection helpers: asserting only `selectedProviderName()` left this
     * unable to fail if `run()` ever started hard-failing this configuration,
     * which is precisely what the sibling test below cites it as proof
     * against. `cat` is a legitimate backend command — it echoes the JSON
     * history it is handed straight back — so a real one-shot run against it
     * is entirely offline and costs one `proc_open`.
     */
    public function testBackendCommandOutranksAPersistedProviderAndDoesNotHardFail(): void
    {
        Bootstrap::writeUserConfig(['provider' => self::UNKNOWN_PROVIDER]);
        putenv('SUGARCRUSH_BACKEND_CMD=cat');
        $args = ArgvParser::parse(['sugarcrush', '-p', 'echo me back', '--root', $this->tempDir . '/repo']);

        $this->assertNull(Bootstrap::selectedProviderName());
        $this->assertSame('command', Bootstrap::selectedProviderLabel()[0]);

        ob_start();
        $code = NonInteractive::run($args);
        $stdout = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code, 'the stale persisted name must not hard-fail a command backend');
        $this->assertStringContainsString('echo me back', $stdout, 'the command backend never ran');
    }

    /**
     * When BOTH are set, {@see Bootstrap::backend()} drops from the broken
     * provider to the shell-out backend. One-shot mode refuses that too:
     * substituting a shell-out for a requested provider is a smaller lie than
     * substituting Echo, but the caller still reads one string and cannot see
     * which backend produced it. Unsetting `$SUGARCRUSH_PROVIDER` selects the
     * command backend deliberately — which the test above proves still works.
     */
    public function testBrokenProviderIsNotSilentlyReplacedByTheCommandBackendEither(): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . self::UNKNOWN_PROVIDER);
        putenv('SUGARCRUSH_BACKEND_CMD=cat');
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi', '--root', $this->tempDir . '/repo']);

        ob_start();
        $code = NonInteractive::run($args);
        $stdout = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
        $this->assertSame('', $stdout);
    }

    // -------------------------------------------------------------------------
    // The asymmetry: the interactive path keeps degrading, for the same config
    // -------------------------------------------------------------------------

    /**
     * @dataProvider unusableProviders
     */
    public function testInteractiveBootstrapStillDegradesToEchoForTheSameConfiguration(string $provider): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . $provider);

        $backend = Bootstrap::backend($this->tempDir . '/repo');

        $this->assertInstanceOf(Backend::class, $backend);
        $this->assertSame('echo', $this->providerNameOf($backend), 'the TUI must still get a usable offline session');
    }

    /**
     * The other half of the same assertion, at the seam the one-shot path
     * uses: identical configuration, opposite contract. Asserting both against
     * one environment is what pins the asymmetry as intentional rather than
     * two independent behaviours that happen to differ today.
     *
     * @dataProvider unusableProviders
     */
    public function testBackendForThrowsForTheSameConfigurationTheTuiTolerates(string $provider): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . $provider);

        $this->expectException(\Throwable::class);
        Bootstrap::backendFor($provider, $this->tempDir . '/repo');
    }

    // -------------------------------------------------------------------------
    // A valid provider still works on both paths
    // -------------------------------------------------------------------------

    /**
     * The two calls {@see NonInteractive::run()} makes for a selected
     * provider, asserted at the seam rather than through `run()` — the one
     * place in this file where that is deliberate rather than an oversight.
     *
     * `run()` has exactly one hard-fail: the `catch (\Throwable)` around
     * `Bootstrap::backendFor()`. So "a VALID provider is not hard-failed" is,
     * structurally, "`backendFor()` does not throw for it" — which is what is
     * asserted here, offline. Driving the same case through `run()` would
     * require reaching `Backend::complete()`, and no provider in this codebase
     * completes without a socket or a subprocess: `dev-sglang` would issue a
     * live request to the endpoint config.dev.json names, and there is no
     * `echo` entry in `ProviderFactory::availableTypes()` to select instead.
     * The run()-level half of the property is covered offline by the command
     * backend above and by the unset-provider case below, both of which drive
     * `run()` to completion and would fail if it started hard-failing a
     * working configuration.
     */
    public function testValidProviderIsConstructedForTheOneShotPathRatherThanEcho(): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . self::VALID_PROVIDER);

        $this->assertSame(self::VALID_PROVIDER, Bootstrap::selectedProviderName());
        $this->assertSame(
            'sglang',
            $this->providerNameOf(Bootstrap::backendFor(self::VALID_PROVIDER, $this->tempDir . '/repo')),
        );
    }

    public function testValidProviderIsAlsoWhatTheInteractivePathSelects(): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . self::VALID_PROVIDER);

        $this->assertSame('sglang', $this->providerNameOf(Bootstrap::backend($this->tempDir . '/repo')));
    }

    /**
     * A valid provider must not have picked up the new hard-fail: with a
     * constructable backend in hand, `run()` proceeds exactly as before.
     * Driven with an injected backend so no request is made.
     */
    public function testValidRunStillExitsZeroAndPrintsTheAnswer(): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . self::VALID_PROVIDER);
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi']);

        ob_start();
        $code = NonInteractive::run($args, $this->fixedBackend('a real answer'));
        $stdout = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertStringContainsString('a real answer', $stdout);
    }

    // -------------------------------------------------------------------------
    // The unset-provider one-shot case: still offline, still exit 0
    // -------------------------------------------------------------------------

    /**
     * With NOTHING configured, nothing is being substituted for anything: the
     * offline provider is the run's actual selection, not a downgrade from a
     * provider the caller asked for. `sugarcrush -p "..."` with zero config is
     * also the documented zero-network smoke test, and hard-failing it would
     * make {@see \SugarCraft\Crush\Providers\EchoProvider} unreachable from the
     * one-shot path entirely. So it keeps exit 0 — and gains a stderr notice
     * (asserted end-to-end in
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushDispatchTest})
     * so the caller who merely forgot to export the variable is still told.
     */
    public function testUnsetProviderStillAnswersFromTheOfflineEchoProviderAtExitZero(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hello there', '--root', $this->tempDir . '/repo']);

        $this->assertNull(Bootstrap::selectedProviderName());
        $this->assertSame('echo', Bootstrap::selectedProviderLabel()[0]);

        ob_start();
        $code = NonInteractive::run($args);
        $stdout = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertStringContainsString('hello there', $stdout);
    }

    // -------------------------------------------------------------------------
    // Misconfigured (never ran, exit 2) vs unreachable (ran and failed, exit 1)
    // -------------------------------------------------------------------------

    /**
     * A bad host or a rejected key is NOT a configuration error: the provider
     * built fine and the request is what failed. That surfaces from
     * `Backend::complete()`, which keeps the pre-existing exit 1, so a CI gate
     * can tell "fix your config, retrying will not help" (2) from "the model
     * was unreachable, a retry might" (1).
     */
    public function testAnUnreachableButWellConfiguredBackendKeepsExitOne(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi']);

        ob_start();
        $code = NonInteractive::run($args, $this->throwingBackend('cURL error 7: Failed to connect to localhost port 30000'));
        $stdout = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $this->assertSame('', $stdout);

        // The distinction itself, driven rather than restated: the SAME
        // prompt, differing only in whether the provider was constructable,
        // must produce different codes. Comparing the two constants proved
        // nothing — they differ by declaration.
        putenv('SUGARCRUSH_PROVIDER=' . self::UNKNOWN_PROVIDER);
        ob_start();
        $misconfigured = NonInteractive::run(
            ArgvParser::parse(['sugarcrush', '-p', 'hi', '--root', $this->tempDir . '/repo']),
        );
        ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $misconfigured);
        $this->assertNotSame(
            $misconfigured,
            $code,
            'a CI gate must be able to tell "fix your config" from "the model was unreachable"',
        );
    }

    // -------------------------------------------------------------------------
    // --output-format json survives an error message that is not valid UTF-8
    // -------------------------------------------------------------------------

    /**
     * `json_encode()` returns false — not a partial string — on one invalid
     * byte, and the old `(string)` cast turned that into `''`, so stdout was a
     * bare newline: the empty pipe the document exists to prevent, on the very
     * path most likely to carry raw bytes (a provider exception quoting a
     * response body).
     */
    public function testAnErrorMessageWithInvalidUtf8StillProducesOneParseableDocument(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi']);

        ob_start();
        $code = NonInteractive::run(
            $args,
            $this->throwingBackend("HTTP 500 Response body: \xC3\x28\xFF binary"),
            NonInteractive::FORMAT_JSON,
        );
        $stdout = trim((string) ob_get_clean());

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . var_export($stdout, true));
        $this->assertSame('backend', $decoded['error']['type']);
        $this->assertStringStartsWith('HTTP 500 Response body: ', $decoded['error']['message']);
        $this->assertStringContainsString("\u{FFFD}", $decoded['error']['message']);
    }

    // -------------------------------------------------------------------------
    // --output-format json: stdout is always one JSON object, success or not
    // -------------------------------------------------------------------------

    public function testJsonFailureDocumentNamesTheProviderAndTheErrorType(): void
    {
        putenv('SUGARCRUSH_PROVIDER=openai');
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi', '--output-format', 'json', '--root', $this->tempDir . '/repo']);

        ob_start();
        $code = NonInteractive::run($args, null, $args->outputFormat);
        $stdout = trim((string) ob_get_clean());

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, 'a JSON caller must never get an empty stdout on failure');
        $this->assertArrayHasKey('result', $decoded);
        $this->assertNull($decoded['result']);
        $this->assertSame('provider_configuration', $decoded['error']['type']);
        $this->assertSame('openai', $decoded['error']['provider']);
        $this->assertNotSame('', $decoded['error']['message']);
    }

    public function testJsonFailureDocumentForAnUnreachableBackendIsTypedBackend(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi']);

        ob_start();
        $code = NonInteractive::run($args, $this->throwingBackend('connection refused'), NonInteractive::FORMAT_JSON);
        $stdout = trim((string) ob_get_clean());

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        $this->assertNull($decoded['result']);
        $this->assertSame('backend', $decoded['error']['type']);
        $this->assertSame('connection refused', $decoded['error']['message']);
        // No provider is named: the backend was handed in already built, so
        // there is no selection to blame.
        $this->assertArrayNotHasKey('provider', $decoded['error']);
    }

    public function testJsonFailureDocumentForAMissingPromptIsTypedUsage(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', '']);

        ob_start();
        $code = NonInteractive::run($args, null, NonInteractive::FORMAT_JSON);
        $stdout = trim((string) ob_get_clean());

        // 2, with the rest of the "nothing was attempted" causes — `usage` is
        // the type that tells this apart from `provider_configuration`, which
        // is the other 2.
        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        $this->assertSame('usage', $decoded['error']['type']);
        $this->assertStringContainsString('no prompt given', $decoded['error']['message']);
    }

    /**
     * Text mode is unchanged: the human line goes to stderr and stdout stays
     * empty, so a `sugarcrush -p ... > out.txt` caller never finds a JSON
     * document where an answer should be.
     */
    public function testTextModeFailuresWriteNothingToStdout(): void
    {
        putenv('SUGARCRUSH_PROVIDER=' . self::UNKNOWN_PROVIDER);
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi', '--root', $this->tempDir . '/repo']);

        ob_start();
        NonInteractive::run($args, null, NonInteractive::FORMAT_TEXT);

        $this->assertSame('', (string) ob_get_clean());
    }

    public function testExitCodeConstantsMatchTheDocumentedConvention(): void
    {
        $this->assertSame(0, NonInteractive::EXIT_OK);
        $this->assertSame(1, NonInteractive::EXIT_FAILURE);
        // Same value bin/sugarcrush already exits with for an unrecognized
        // flag or a --root naming no directory.
        $this->assertSame(2, NonInteractive::EXIT_CONFIG);
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /**
     * The provider an {@see EngineBackend} was built on. Read reflectively
     * because the field is private and there is no accessor — and asserting on
     * the provider rather than on the class is the only way to tell "the
     * requested provider" from "silently swapped for Echo", which is the entire
     * subject of this file.
     */
    private function providerNameOf(Backend $backend): string
    {
        $this->assertInstanceOf(EngineBackend::class, $backend);

        $property = new \ReflectionProperty(EngineBackend::class, 'provider');
        $property->setAccessible(true);
        $provider = $property->getValue($backend);

        $this->assertInstanceOf(ProviderInterface::class, $provider);

        return $provider->name();
    }

    private function fixedBackend(string $reply): Backend
    {
        return new class($reply) implements Backend {
            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken));
            }
        };
    }

    private function throwingBackend(string $message): Backend
    {
        return new class($message) implements Backend {
            public function __construct(private readonly string $message)
            {
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \RuntimeException($this->message);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException($this->message));
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
