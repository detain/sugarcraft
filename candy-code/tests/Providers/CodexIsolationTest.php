<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CodexInvocation;
use SugarCraft\Code\Providers\CodexProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\ProviderFactory;

/**
 * C-PRV-4.b: the codex route's isolation contract, pinned end to end.
 *
 * Three laws, each pinned twice — once on the options() shape (private,
 * reached by reflection exactly like CodexProviderTest's effort pins) and
 * once on what the spawned child actually sees on its argv, recorded by a
 * FAKE codex under a private path (the real CLI is never dialed: it would
 * spend the subscription). The laws:
 *
 * 1. `-s` is ALWAYS explicit. The pre-C-PRV-4.b silence deferred to the
 *    child's own config.toml — plan mode over a workspace-write config
 *    could still edit files. Plan and dont-ask force read-only; every
 *    other mode runs the configured dial, defaulting to workspace-write;
 *    `danger-full-access` rides only when an operator configured exactly
 *    that. Host modes narrow. Never widen.
 * 2. `isolate` (default true, the §6.2 ruling) puts `--ignore-user-config`
 *    on every spawn — the flag's own help text at PROBED_CODEX_VERSION:
 *    config.toml is dropped, authentication still uses CODEX_HOME. Only an
 *    explicit isolate:false restores the old inheritance.
 * 3. A request carrying a jsonSchema spills to a 0600 temp file riding
 *    `--output-schema`, and the file exists exactly for the span of the
 *    call — visible to the child, gone on every route out (success or
 *    stream teardown) for both execute paths. supportsJsonSchema() still
 *    answers false: the channel is wired, the authenticated probe record
 *    that flips the capability has not happened on a host with a login.
 *
 * Helper names are deliberately distinct from CodexProviderTest's (the
 * DuplicatedTestHelperDrift discipline); the fake script differs from its
 * twin beyond a one-edit drift bound on purpose — this one inspects the
 * schema file from inside the child.
 */
final class CodexIsolationTest extends TestCase
{
    /**
     * The fake: logs argv and stdin, and — when --output-schema was passed —
     * records the file's existence, mode and content BEFORE exiting happy.
     */
    private const FAKE_ISOLATED_CODEX = <<<'PHP'
    #!PHP_BIN
    <?php
    if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); pcntl_signal(SIGALRM, static function (): void { fwrite(STDERR, "fake-codex: alarm\n"); exit(9); }); pcntl_alarm(20); }
    $log = getenv('FAKE_CODEX_LOG');
    file_put_contents("$log/argv.json", json_encode($argv));
    file_put_contents("$log/stdin.txt", stream_get_contents(STDIN));
    $schema = null;
    $at = array_search('--output-schema', $argv, true);
    if ($at !== false && isset($argv[$at + 1])) {
        $path = $argv[$at + 1];
        $schema = [
            'path' => $path,
            'existed' => file_exists($path),
            'content' => file_exists($path) ? file_get_contents($path) : null,
            'perms' => file_exists($path) ? (fileperms($path) & 0o777) : null,
        ];
        file_put_contents("$log/schema.json", json_encode($schema));
    }
    $frame = static function (array $data): void { echo json_encode($data), "\n"; };
    $frame(['type' => 'item.completed', 'item' => ['id' => 'item_0', 'type' => 'agent_message', 'text' => 'Isolation checked.']]);
    $frame(['type' => 'turn.completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12]]);
    exit(0);
    PHP;

    private string $harnessDir = '';

    private string $fakeBin = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->harnessDir = sys_get_temp_dir() . '/sc_codex_iso_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->harnessDir, 0o700, true);

        $this->fakeBin = $this->harnessDir . '/codex';
        file_put_contents($this->fakeBin, str_replace('#!PHP_BIN', '#!' . PHP_BINARY, self::FAKE_ISOLATED_CODEX));
        chmod($this->fakeBin, 0o755);

        putenv('FAKE_CODEX_LOG=' . $this->harnessDir);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_CODEX_LOG');

        foreach (scandir($this->harnessDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->harnessDir . '/' . $entry);
            }
        }
        @rmdir($this->harnessDir);

        parent::tearDown();
    }

    /**
     * A provider whose child is this harness's fake, dialed through the same
     * code paths production uses (complete()/completeStream()).
     */
    private function fakeSpawner(?string $sandbox = null, bool $isolate = true): CodexProvider
    {
        return new CodexProvider(new CodexInvocation(codexPath: $this->fakeBin), sandbox: $sandbox, isolate: $isolate);
    }

    /**
     * @return array<string, mixed>
     */
    private function optionShape(CodexProvider $provider, ?CompleteRequest $request = null): array
    {
        $optionsOf = new \ReflectionMethod(CodexProvider::class, 'options');
        $optionsOf->setAccessible(true);

        return $optionsOf->invoke($provider, $request ?? new CompleteRequest(model: 'probe-model', messages: [new UserMessage('hi')]));
    }

    /**
     * @return array<string>
     */
    private function spawnedArgv(): array
    {
        $argv = json_decode((string) file_get_contents($this->harnessDir . '/argv.json'), true);

        $this->assertIsArray($argv, 'the fake child never logged its argv');

        return $argv;
    }

    /**
     * The value of $flag as the child saw it, or null when the flag was not
     * on the command line at all.
     *
     * @param array<string> $argv
     */
    private function flagValue(array $argv, string $flag): ?string
    {
        $at = array_search($flag, $argv, true);
        if ($at === false) {
            return null;
        }

        return (string) ($argv[$at + 1] ?? '');
    }

    // ======================================================================
    // Law 1: -s always rides; host modes narrow, never widen
    // ======================================================================

    public function testTheDefaultProviderStillSendsAnExplicitSandbox(): void
    {
        $shape = $this->optionShape($this->fakeSpawner());
        $this->assertSame('workspace-write', $shape['sandbox'], 'silence is no longer an option: the pre-C-PRV-4.b null meant "defer to config.toml"');

        $this->fakeSpawner()->complete(new CompleteRequest(model: 'probe-model', messages: [new UserMessage('hi')]));
        $this->assertSame('workspace-write', $this->flagValue($this->spawnedArgv(), '-s'), 'the dial lands on the child argv, not just the options array');
    }

    public function testPlanModeNarrowsAWorkspaceWriteConfigToReadOnly(): void
    {
        // The exact defect this step root-caused: plan mode over a
        // workspace-write config — the host gated its own tools while the
        // codex child kept editing files.
        $provider = $this->fakeSpawner('workspace-write')->withHostPermissionMode(PermissionMode::Plan);

        $this->assertSame('read-only', $this->optionShape($provider)['sandbox']);

        $provider->complete(new CompleteRequest(model: 'probe-model', messages: [new UserMessage('hi')]));
        $this->assertSame('read-only', $this->flagValue($this->spawnedArgv(), '-s'));
    }

    public function testDontAskModeNarrowsTooAndPlainModesKeepTheConfiguredDial(): void
    {
        $dontAsk = $this->fakeSpawner('workspace-write')->withHostPermissionMode(PermissionMode::DontAsk);
        $this->assertSame('read-only', $this->optionShape($dontAsk)['sandbox'], 'dont-ask promises read-only tools only');

        $keeps = $this->fakeSpawner('read-only')->withHostPermissionMode(PermissionMode::AcceptEdits);
        $this->assertSame('read-only', $this->optionShape($keeps)['sandbox'], 'a narrower configured dial is never widened by a looser host mode');

        $default = $this->fakeSpawner('workspace-write')->withHostPermissionMode(PermissionMode::Default);
        $this->assertSame('workspace-write', $this->optionShape($default)['sandbox']);
    }

    public function testBypassPermissionsNeverWidensAndDangerOnlyRidesWhenConfigured(): void
    {
        $bypass = $this->fakeSpawner()->withHostPermissionMode(PermissionMode::BypassPermissions);
        $this->assertSame('workspace-write', $this->optionShape($bypass)['sandbox'], 'the widest HOST mode still only earns the product default, never danger-full-access');

        $configured = $this->fakeSpawner('danger-full-access');
        $this->assertSame('danger-full-access', $this->optionShape($configured)['sandbox'], 'the operator spelling rides verbatim - narrowing is ours, widening is not');
    }

    public function testTheHostModeRebindCarriesEveryOtherDial(): void
    {
        $provider = new CodexProvider(new CodexInvocation(codexPath: $this->fakeBin), 'gpt-rebound', 'read-only', false);
        $planned = $provider->withHostPermissionMode(PermissionMode::Plan);

        $shape = $this->optionShape($planned);
        $this->assertSame('gpt-rebound', $shape['model']);
        $this->assertFalse($planned->isolate(), 'the isolate dial rides the clone');
        $this->assertArrayNotHasKey('ignoreUserConfig', $shape);
        $this->assertSame('read-only', $shape['sandbox'], 'plan over a read-only config is a no-op, not a widening');

        // null = no gate bound: back to the configured dial, exactly as before.
        $this->assertSame('read-only', $this->optionShape($planned->withHostPermissionMode(null))['sandbox']);
    }

    // ======================================================================
    // Law 2: isolate default true puts --ignore-user-config on the spawn
    // ======================================================================

    public function testIsolationLandsByDefaultAndRetreatsOnlyWhenTold(): void
    {
        $this->fakeSpawner()->complete(new CompleteRequest(model: 'probe-model', messages: [new UserMessage('hi')]));
        $this->assertContains('--ignore-user-config', $this->spawnedArgv(), 'the §6.2 default: a foreign config.toml drops out, auth still uses CODEX_HOME');

        $this->fakeSpawner(isolate: false)->complete(new CompleteRequest(model: 'probe-model', messages: [new UserMessage('hi')]));
        $this->assertNotContains('--ignore-user-config', $this->spawnedArgv(), 'isolate:false restores the old full-config inheritance, loudly opted into');
    }

    public function testTheFactoryNormalisesTheCodexIsolateLikeClaudes(): void
    {
        $factory = new ProviderFactory();

        $this->assertTrue($factory->create(['type' => 'codex', 'codexPath' => 'codex'])->isolate());
        $this->assertFalse($factory->create(['type' => 'codex', 'codexPath' => 'codex', 'isolate' => false])->isolate());
        $this->assertFalse($factory->create(['type' => 'codex', 'codexPath' => 'codex', 'isolate' => 'false'])->isolate());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('isolate must be true or false');
        $factory->create(['type' => 'codex', 'codexPath' => 'codex', 'isolate' => 'sometimes']);
    }

    // ======================================================================
    // Law 3: the schema spill — private, visible to the child, uncreated residue
    // ======================================================================

    public function testTheSchemaFileIsPrivatePresentForTheChildAndGoneAfter(): void
    {
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']];

        $response = $this->fakeSpawner()->complete(new CompleteRequest(
            model: 'probe-model',
            messages: [new UserMessage('hi')],
            jsonSchema: $schema,
        ));

        $this->assertSame('Isolation checked.', $response->content, 'the run is undisturbed by the channel being wired');

        $record = json_decode((string) file_get_contents($this->harnessDir . '/schema.json'), true);
        $this->assertIsArray($record);
        $this->assertTrue($record['existed'], 'the child must find the file the flag names');
        $this->assertSame(0o600, $record['perms'], 'tempnam 0600: the schema is never readable by another user, not for an instant');
        $this->assertSame($schema, json_decode((string) $record['content'], true));

        $this->assertSame(['--output-schema', $record['path']], array_slice($this->spawnedArgv(), (int) array_search('--output-schema', $this->spawnedArgv(), true), 2));
        $this->assertFileDoesNotExist($record['path'], 'complete() finally removed the spill');
    }

    public function testTheStreamTeardownRemovesTheSpillToo(): void
    {
        $chunks = iterator_to_array($this->fakeSpawner()->completeStream(new CompleteRequest(
            model: 'probe-model',
            messages: [new UserMessage('hi')],
            jsonSchema: '{"type":"object"}',
        )));

        $this->assertNotEmpty($chunks);

        $record = json_decode((string) file_get_contents($this->harnessDir . '/schema.json'), true);
        $this->assertTrue($record['existed']);
        $this->assertSame('{"type":"object"}', $record['content'], 'a pre-encoded schema string rides verbatim');
        $this->assertFileDoesNotExist($record['path'], 'the generator-owned finally covers the stream path as well');
    }

    public function testASchemaFreeRunEmitsNoFlagAndLeavesNoFile(): void
    {
        $this->fakeSpawner()->complete(new CompleteRequest(model: 'probe-model', messages: [new UserMessage('hi')]));

        $argv = $this->spawnedArgv();
        $this->assertNotContains('--output-schema', $argv);
        $this->assertFileDoesNotExist($this->harnessDir . '/schema.json');
    }

    public function testTheChannelIsWiredWhileTheCapabilityStaysUnadvertised(): void
    {
        // supportsJsonSchema() false is a PROBE record, not an absence of
        // plumbing: options() still spills when a caller hands a schema. If
        // a future authenticated probe flips the capability, this pin is the
        // half that says the wire was already real when it did.
        $provider = $this->fakeSpawner();
        $this->assertFalse($provider->supportsJsonSchema());

        $shape = $this->optionShape($provider, new CompleteRequest(model: 'probe-model', messages: [], jsonSchema: ['type' => 'object']));
        $this->assertArrayHasKey('outputSchemaFile', $shape);
        $this->assertFileExists($shape['outputSchemaFile']);
        @unlink((string) $shape['outputSchemaFile']);
    }

    // ======================================================================
    // argv unit shape
    // ======================================================================

    public function testExecArgsPlaceTheNewFlagsInTheirDeclaredPositions(): void
    {
        $invocation = new CodexInvocation();

        $args = $invocation->execArgs([
            'ignoreUserConfig' => true,
            'model' => 'm',
            'sandbox' => 'read-only',
            'reasoningEffort' => 'high',
            'outputSchemaFile' => '/tmp/sc_pin_schema',
        ]);

        $this->assertSame(
            ['exec', '--json', '--ignore-user-config', '-m', 'm', '-s', 'read-only', '-c', 'model_reasoning_effort=high', '--output-schema', '/tmp/sc_pin_schema', '-'],
            $args,
        );
    }
}
