<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\ClaudeCodeInvocation;
use SugarCraft\Code\Providers\ClaudeCodeProvider;
use SugarCraft\Code\Providers\CodexInvocation;
use SugarCraft\Code\Providers\CodexProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\HonoursHostPermissionMode;
use SugarCraft\Code\Providers\ProviderFactory;

/**
 * C-PRV-4.a: CLI-provider isolation on the subscription route.
 *
 * The pins here walk the whole shipped shape: the three `--help`-verified
 * isolation switches ride the argv when (and only when) the operator chose
 * `bare: false` with `isolate` on; the host gate's permission mode is
 * translated to the child's own spelling, narrowing only; and the factory
 * normalises the block key the same loud way `bare` is normalised.
 *
 * @see ClaudeCodeInvocation
 * @see ClaudeCodeProvider
 * @see HonoursHostPermissionMode
 */
final class CliProviderIsolationTest extends TestCase
{
    public function testProbeRecordedTheInstalledCliVersion(): void
    {
        // House doctrine (mirrors CodexInvocation::PROBED_CODEX_VERSION):
        // the argv this file spells is only honest against a recorded probe.
        $this->assertSame('2.1.312', ClaudeCodeInvocation::PROBED_CLAUDE_VERSION);
        $this->assertSame('project,local', ClaudeCodeInvocation::ISOLATION_SETTING_SOURCES);
    }

    public function testIsolationTrioRidesTheArgvInItsRecordedShape(): void
    {
        $args = (new ClaudeCodeInvocation())->printModeArgs(['bare' => false, 'isolate' => true]);

        $sourceIndex = array_search('--setting-sources', $args, true);
        $this->assertNotFalse($sourceIndex);
        $this->assertSame(ClaudeCodeInvocation::ISOLATION_SETTING_SOURCES, $args[$sourceIndex + 1]);
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--disable-slash-commands', $args);
        $this->assertNotContains('--bare', $args);
    }

    public function testUnsentIsolationKeepsThePreCPrV4ArgvByteIdentical(): void
    {
        // No key, isolate:false, and bare:true (where the trio must never
        // appear — --bare already skips everything the trio narrows) all
        // answer with exactly the argv the route shipped before this step.
        $invocation = new ClaudeCodeInvocation();

        $this->assertSame(['-p', '--output-format', 'json'], $invocation->printModeArgs());
        $this->assertSame(['-p', '--output-format', 'json'], $invocation->printModeArgs(['bare' => false, 'isolate' => false]));

        $bareArgs = $invocation->printModeArgs(['bare' => true]);
        $this->assertSame(['-p', '--output-format', 'json', '--bare'], $bareArgs);
    }

    public function testSubscriptionRouteIsolatesByDefault(): void
        // Default constructor: bare:false + isolate:true — the plan §6.2
        // ruling "isolation on by default" lands here, not opt-in.
    {
        $options = self::providerOptions(new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false));

        $this->assertTrue($options['isolate']);
        $this->assertFalse($options['bare']);

        $args = (new ClaudeCodeInvocation())->printModeArgs($options);
        $this->assertContains('--setting-sources', $args);
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--disable-slash-commands', $args);
    }

    public function testBareRouteSendsNoTrioEvenWhenIsolateIsOn(): void
    {
        // The CLI's wholesale skip already covers every channel the trio
        // narrows; spelling them again would only invite drift.
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: true);
        $this->assertTrue($provider->isolate());

        $options = self::providerOptions($provider);
        $this->assertFalse($options['isolate']);
        $this->assertStringNotContainsStringIgnoringCase(
            '--setting-sources',
            implode(' ', (new ClaudeCodeInvocation())->printModeArgs($options)),
        );
    }

    public function testIsolateFalseProviderRestoresTheFullUserState(): void
    {
        $args = (new ClaudeCodeInvocation())->printModeArgs(
            self::providerOptions(new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false, isolate: false)),
        );

        $this->assertNotContains('--setting-sources', $args);
        $this->assertNotContains('--strict-mcp-config', $args);
        $this->assertNotContains('--disable-slash-commands', $args);
        $this->assertNotContains('--permission-mode', $args);
    }

    /**
     * @return iterable<string, array{PermissionMode, string}>
     */
    public static function hostModesToChildSpellings(): iterable
    {
        // The spellings are the `claude --help` choices at
        // PROBED_CLAUDE_VERSION — not the enum's kebab wire values.
        yield 'default' => [PermissionMode::Default, 'manual'];
        yield 'accept edits' => [PermissionMode::AcceptEdits, 'acceptEdits'];
        yield 'plan' => [PermissionMode::Plan, 'plan'];
        yield 'auto' => [PermissionMode::Auto, 'auto'];
        yield 'dont ask' => [PermissionMode::DontAsk, 'dontAsk'];
        yield 'bypass' => [PermissionMode::BypassPermissions, 'bypassPermissions'];
    }

    #[DataProvider('hostModesToChildSpellings')]
    public function testHostPermissionModeTranslatesToTheChildSpelling(PermissionMode $mode, string $word): void
    {
        $provider = (new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false))
            ->withHostPermissionMode($mode);

        $options = self::providerOptions($provider);
        $this->assertSame($word, $options['permissionMode']);

        $args = (new ClaudeCodeInvocation())->printModeArgs($options);
        $modeIndex = array_search('--permission-mode', $args, true);
        $this->assertNotFalse($modeIndex);
        $this->assertSame($word, $args[$modeIndex + 1]);
    }

    public function testAbsentHostModeSendsNoPermissionFlag(): void
    {
        // No bound gate is no verdict: the child keeps its own defaults
        // rather than quietly inheriting a grant the host never made.
        $options = self::providerOptions(new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false));

        $this->assertArrayNotHasKey('permissionMode', $options);
        $this->assertNotContains('--permission-mode', (new ClaudeCodeInvocation())->printModeArgs($options));
    }

    public function testRebindingModelCarriesIsolationAndHostMode(): void
    {
        // withModel() clones through get_object_vars(); a rebind that lost
        // the isolation state would silently widen a mid-session model flip.
        $provider = (new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false))
            ->withHostPermissionMode(PermissionMode::Plan)
            ->withModel('claude-opus-4-5');

        $options = self::providerOptions($provider);
        $this->assertTrue($options['isolate']);
        $this->assertSame('plan', $options['permissionMode']);
    }

    public function testEngineBackendRebindsAHonouringProvider(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false);
        $backend = new EngineBackend($provider, 'claude-sonnet-4-6');

        $rebound = $backend->withHostPermissionMode(PermissionMode::Plan);

        $this->assertNotSame($backend, $rebound);
        $this->assertNotSame($provider, $rebound->provider());
        $this->assertSame('plan', self::providerOptions($rebound->provider())['permissionMode']);

        // A turn-start with no mode bound (null) must not leave yesterday's
        // mode on the wire: rebinding to null strips the key.
        $unbound = $rebound->withHostPermissionMode(null);
        $this->assertArrayNotHasKey('permissionMode', self::providerOptions($unbound->provider()));
    }

    public function testEngineBackendLeavesANonHonouringProviderAlone(): void
    {
        // Codex is deliberately outside this step's contract; the seam
        // copies the engine but shares the provider object untouched —
        // identity is the proof no method was ever called on it.
        $provider = new CodexProvider(new CodexInvocation());
        $backend = new EngineBackend($provider, 'gpt-5');

        $this->assertNotInstanceOf(HonoursHostPermissionMode::class, $provider);
        $rebound = $backend->withHostPermissionMode(PermissionMode::Plan);
        $this->assertNotSame($backend, $rebound);
        $this->assertSame($provider, $rebound->provider());
    }

    public function testTurnEntryReadsTheLiveGateMode(): void
    {
        // The per-turn seam (Shift+Tab honored): honouringHostGate() takes
        // whatever mode the gate holds at turn entry, not the launch copy.
        $gate = new PermissionGate(PermissionMode::Default);
        $backend = (new EngineBackend(new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false), 'claude-sonnet-4-6'))
            ->withPermissionGate($gate->withMode(PermissionMode::Plan));

        $honouring = self::callPrivate($backend, 'honouringHostGate');

        $this->assertSame('plan', self::providerOptions($honouring->provider())['permissionMode']);
    }

    public function testTurnEntryWithoutAGateStripsAnyStaleMode(): void
    {
        $backend = (new EngineBackend(new ClaudeCodeProvider(new ClaudeCodeInvocation(), bare: false), 'claude-sonnet-4-6'))
            ->withHostPermissionMode(PermissionMode::BypassPermissions);

        $honouring = self::callPrivate($backend, 'honouringHostGate');

        // No gate → the pre-step argv: no permission flag, even though the
        // bound provider carried one from an earlier wiring.
        $this->assertArrayNotHasKey('permissionMode', self::providerOptions($honouring->provider()));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function isolateConfigValues(): iterable
    {
        yield 'absent means isolated' => [null, true];
        yield 'empty string falls back to the default' => ['', true];
        yield 'false drops it' => [false, false];
        yield 'string false drops it' => ['false', false];
        yield 'zero drops it' => [0, false];
        yield 'no drops it' => ['no', false];
        yield 'true keeps it' => [true, true];
        yield 'string yes keeps it' => ['yes', true];
    }

    #[DataProvider('isolateConfigValues')]
    public function testIsolateIsAConfigurableBlockOption(mixed $value, bool $expected): void
    {
        $config = ['type' => 'claude-code', 'claudePath' => '/usr/local/bin/claude', 'bare' => false];
        if ($value !== null) {
            $config['isolate'] = $value;
        }

        $provider = (new ProviderFactory())->create($config);

        $this->assertSame($expected, $provider->isolate());
    }

    public function testClaudeCodeIsolateRefusesNonBooleanNonsense(): void
    {
        // Same law as `bare`: an isolation decision is never guessed at
        // from garbage.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('isolate must be true or false');

        (new ProviderFactory())->create([
            'type' => 'claude-code',
            'claudePath' => '/usr/local/bin/claude',
            'isolate' => 'sometimes',
        ]);
    }

    public function testTypeSchemaListsTheBlockKey(): void
    {
        $schemas = (new \ReflectionClass(ProviderFactory::class))->getReflectionConstant('TYPE_SCHEMAS')->getValue();

        $this->assertContains('isolate', $schemas['claude-code']['optional']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function providerOptions(object $provider): array
    {
        // options() is the provider's private decision table; complete()
        // and completeStream() both feed it to printModeArgs verbatim.
        $optionsOf = new ReflectionMethod(ClaudeCodeProvider::class, 'options');
        $optionsOf->setAccessible(true);

        return $optionsOf->invoke($provider, 'json', new CompleteRequest(model: 'claude-sonnet-4-6', messages: []), null);
    }

    private static function callPrivate(object $target, string $method): EngineBackend
    {
        $reflected = new ReflectionMethod(EngineBackend::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invoke($target);
    }
}
