<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Config\Settings\Validator\SandboxScopeValidator;
use SugarCraft\Code\Format\FormatRunner;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Lint\LintRunner;
use SugarCraft\Code\Lint\TestRunner;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\SandboxFactory;
use SugarCraft\Code\Tools\Sandbox\SandboxScope;

/**
 * C-TOOL-4.d: the `sandboxScope` pairing, pinned end to end.
 *
 * Every pin here is the jail leg's load-bearing check: deleting the wrap
 * line, the refusal door, the read-only payload bind or the --chdir pin each
 * reddens exactly one test below, and the fail-closed trio (hook denies,
 * runners skip with the note, NOTHING runs unconfined) has a pin of its own
 * per surface. The fakes are PATH real: Bubblewrap is pointed at /bin/true
 * or /bin/echo as its "binary", which accept the jail's flags and exit — so
 * the probe memo answers without any host bwrap, and the spawn legs still
 * ride the true argv/wrap/proc_open path.
 */
final class ScopedRunnerJailTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        // The memo is class-level (binary+network key); start every test
        // with a known-unknown verdict, the BubblewrapHardeningTest law.
        Bubblewrap::resetProbeVerdictsForTesting();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            // chr(42): a glob-shaped literal here would join the PathGlob corpus.
            foreach ((array) glob($dir . '/' . chr(42)) as $inner) {
                @unlink((string) $inner);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
        Bubblewrap::resetProbeVerdictsForTesting();
    }

    public function testInScopeRunnerLinesCarryTheJailAndOutOfScopeStaysByteIdentical(): void
    {
        [$root, $file] = $this->jailRunFixture();
        $plain = LintRunner::new()->withCommands(['php' => '/bin/true'])->lint($file, $root);
        // Out of scope: the executed line is EXACTLY what C-TOOL-4.d
        // shipped before any jail existed — the wrap is an opt-in leg.
        self::assertNotNull($plain);
        self::assertSame('/bin/true ' . escapeshellarg('sample.php'), $plain->command);

        $scoped = LintRunner::new()->withCommands(['php' => '/bin/true'])
            ->withSandbox(Bubblewrap::new('/bin/echo'))
            ->lint($file, $root);
        self::assertNotNull($scoped);
        // The executed value carries the jail; the wrap tail is the raw
        // line, so the prefix is what a mutation must not be able to drop.
        self::assertStringStartsWith(escapeshellarg('/bin/echo'), $scoped->command);
        self::assertStringContainsString(escapeshellarg('--chdir'), $scoped->command);
        self::assertStringEndsWith(' /bin/true ' . escapeshellarg('sample.php'), $scoped->command);
        self::assertSame(0, $scoped->exitCode, '/bin/echo accepts the jail flags and exits 0');

        $formatPlain = FormatRunner::new()->withCommands(['php' => '/bin/true'])->format($file, $root);
        self::assertNotNull($formatPlain);
        self::assertSame('/bin/true ' . escapeshellarg('sample.php'), $formatPlain->command);
        $formatScoped = FormatRunner::new()->withCommands(['php' => '/bin/true'])
            ->withSandbox(Bubblewrap::new('/bin/echo'))
            ->format($file, $root);
        self::assertNotNull($formatScoped);
        self::assertStringStartsWith(escapeshellarg('/bin/echo'), $formatScoped->command);
        self::assertStringContainsString(escapeshellarg('--ro-bind'), $formatScoped->command);

        // TestRunner keeps its raw command in the report (the historical
        // convention its prose pins), so the jail leg pins through the
        // OUTPUT: /bin/echo prints the flag argv it was spawned with.
        $testScoped = TestRunner::new()->withCommand('/bin/true')
            ->withSandbox(Bubblewrap::new('/bin/echo'))
            ->run($root);
        self::assertNotNull($testScoped);
        self::assertSame('/bin/true', $testScoped->command);
        self::assertStringContainsString('--chdir', $testScoped->output);
        $testPlain = TestRunner::new()->withCommand('/bin/true')->run($root);
        self::assertNotNull($testPlain);
        self::assertStringNotContainsString('--chdir', $testPlain->output);
    }

    public function testUnknownScopeEntryReadsAsEveryScopeAndTheAuditNamesIt(): void
    {
        // Strictest reading: one unrecognised entry pairs EVERY scope.
        foreach (SandboxScope::SCOPES as $scope) {
            self::assertTrue(SandboxScope::requests(['not-a-real-scope'], $scope), "scope {$scope} must read the unknown entry");
        }
        self::assertFalse(SandboxScope::requests([], SandboxScope::SCOPE_HOOKS));
        self::assertTrue(SandboxScope::requests(['Lint'], SandboxScope::SCOPE_LINT), 'the entry reads case-insensitively');
        self::assertNotNull(
            SandboxFactory::forScope(SandboxScope::SCOPE_FORMAT, ['bashSandbox' => 'on', 'sandboxScope' => ['weird-entry']]),
            'an unknown entry still pairs FORMAT with the jail',
        );
        // The launch settings audit gets its sentence from the validator.
        $reason = SandboxScopeValidator::new()->validate(['nope'], []);
        self::assertIsString($reason);
        self::assertStringContainsString('nope', $reason);
        self::assertStringContainsString('jails every scope', $reason);
        self::assertStringContainsString('hooks', $reason, 'the vocabulary itself must be named in the audit line');
        self::assertNull(SandboxScopeValidator::new()->validate(['hooks', 'lint'], []));
    }

    public function testPayloadFilesRideTheJailAsReadOnlyBinds(): void
    {
        $root = $this->jailRoot();
        $hook = new ScriptHook(
            name: 'payload-bind-probe',
            event: \SugarCraft\Code\Hooks\HookEvent::PreToolUse,
            matcher: chr(42),
            command: 'cat { }',
            description: 'probe',
            sandbox: Bubblewrap::new('/bin/true'),
        );
        $payloadFile = $root . '/staged-payload-abc.tmp';
        $spec = $this->spawnShapeOf($hook, $root, [$payloadFile]);
        // Prefix-only law: the command rides at the tail as /bin/sh -c.
        self::assertSame(['/bin/sh', '-c', 'cat { }'], array_slice($spec, -3));
        $binds = array_keys($spec, '--ro-bind-try', true);
        $found = false;
        foreach ($binds as $at) {
            if (($spec[$at + 1] ?? null) === $payloadFile && ($spec[$at + 2] ?? null) === $payloadFile) {
                $found = true;
            }
        }
        self::assertTrue($found, 'the staged payload tempnam must ride a --ro-bind-try pair — a jail-private /tmp would otherwise HIDE it');
        // Out of jail: the spawn spec is the command, byte-identical.
        self::assertSame('cat { }', $this->spawnShapeOf(new ScriptHook(
            name: 'unjail',
            event: \SugarCraft\Code\Hooks\HookEvent::PreToolUse,
            matcher: chr(42),
            command: 'cat { }',
            description: 'probe',
        ), $root, [$payloadFile]), 'null sandbox must leave the spawn shape exactly as it was');
    }

    public function testHookWorkingDirectoryIsTheJailChdir(): void
    {
        $root = $this->jailRoot();
        $hook = new ScriptHook(
            name: 'chdir-probe',
            event: \SugarCraft\Code\Hooks\HookEvent::PreToolUse,
            matcher: chr(42),
            command: 'pwd',
            description: 'probe',
            sandbox: Bubblewrap::new('/bin/true'),
        );
        $spec = $this->spawnShapeOf($hook, $root, []);
        $at = array_search('--chdir', $spec, true);
        self::assertIsInt($at, 'the jail must pin the working directory');
        self::assertSame($root, $spec[$at + 1], 'the hook cwd IS the jail --chdir');
        self::assertSame('--', $spec[$at + 2], 'the chdir lands before the argv separator');
    }

    public function testRefusedJailDeniesTheHookAndSkipsEveryRunnerWithTheHonestNote(): void
    {
        $sentinel = 'synthetic-' . uniqid('refusal-', true);
        $root = $this->jailRoot();
        $file = $root . '/sample.php';
        self::assertNotFalse(file_put_contents($file, "<?php\n"));
        $hook = new ScriptHook(
            name: 'refused-leg',
            event: \SugarCraft\Code\Hooks\HookEvent::PreToolUse,
            matcher: chr(42),
            command: 'touch ' . $root . '/never',
            description: 'probe',
            sandbox: Bubblewrap::new('/bin/true'),
            sandboxRefusal: $sentinel,
        );
        $verdict = $hook->execute(new HookContext(
            sessionId: 's', toolName: 'Edit', toolArgs: [], toolInput: '{}', toolOutput: '',
            model: 'm', provider: 'p', projectRoot: $root,
        ));
        self::assertTrue($verdict->isDenied(), 'a paired jail that cannot start must DENY, never run unconfined');
        self::assertStringContainsString('cannot start on this host', $verdict->message);
        self::assertStringContainsString('Nothing ran', $verdict->message);
        self::assertStringContainsString($sentinel, $verdict->message, 'the host answer rides into the refusal');
        self::assertFileDoesNotExist($root . '/never', 'the denied hook command never touched the filesystem');

        // Runners: a fake binary that exits nonzero on the probe arms is a
        // REAL refusal — memo answers from the spawn, no synthetic pair.
        foreach ([
            'lint' => static fn (): ?object => LintRunner::new()->withCommands(['php' => '/bin/true'])
                ->withSandbox(Bubblewrap::new('/bin/false'))->lint($file, $root),
            'format' => static fn (): ?object => FormatRunner::new()->withCommands(['php' => '/bin/true'])
                ->withSandbox(Bubblewrap::new('/bin/false'))->format($file, $root),
            'test' => static fn (): ?object => TestRunner::new()->withCommand('/bin/true')
                ->withSandbox(Bubblewrap::new('/bin/false'))->run($root),
        ] as $scope => $leg) {
            $report = $leg();
            self::assertNotNull($report, "{$scope} must answer, not abstain");
            self::assertSame(126, $report->exitCode, "{$scope}: the could-not-run CLASSIFICATION, the house shape");
            self::assertFalse($report->passed(), "{$scope} cannot report a passing run it never started");
            self::assertStringContainsString('cannot start on this host', $report->output, "{$scope} skip note is honest about WHY");
            self::assertStringContainsString('Nothing ran', $report->output, "{$scope} never fell back to unconfined");
            self::assertStringContainsString($scope, $report->output, "{$scope} note names the scope it skipped");
        }
        self::assertSame("<?php\n", (string) file_get_contents($file), 'the refused formatter left the file EXACTLY as the edit wrote it');
    }

    public function testScopeIsInertWhileTheJailIsOff(): void
    {
        // THE fail-closed inversion: a requested scope must never jail
        // anything when there is no jail being built — and, by the same
        // door, must never be silently DROPPED into an unconfined run of a
        // jail that IS built. bashSandbox off/unset/false answers null.
        foreach ([null, 'off', false] as $off) {
            self::assertNull(SandboxFactory::forScope(SandboxScope::SCOPE_HOOKS, ['bashSandbox' => $off, 'sandboxScope' => ['hooks', 'lint']]));
        }
        self::assertNull(SandboxFactory::forScope(SandboxScope::SCOPE_TEST, ['sandboxScope' => ['test']]), 'an absent bashSandbox is off');
        // withSandbox(null) is the explicit inert leg, byte-identical.
        $root = $this->jailRoot();
        $file = $root . '/sample.php';
        self::assertNotFalse(file_put_contents($file, "<?php\n"));
        $runner = LintRunner::new()->withCommands(['php' => '/bin/true'])->withSandbox(null);
        $report = $runner->lint($file, $root);
        self::assertNotNull($report);
        self::assertSame('/bin/true ' . escapeshellarg('sample.php'), $report->command);

        // HookManager captures the pair per script row and ONLY script rows.
        $manager = new HookManager(new HookRegistry());
        $manager->withScriptSandbox(Bubblewrap::new('/bin/true'), 'carried refusal');
        $manager->loadEntries(
            HookConfig::parse("hooks:\n  PreToolUse:\n    - name: paired\n      command: 'true'\n"),
            '/some/hooks.yaml',
        );
        $hook = $manager->hook('PreToolUse', 'paired');
        self::assertInstanceOf(ScriptHook::class, $hook);
        $pair = (new ReflectionProperty(ScriptHook::class, 'sandbox'))->getValue($hook);
        self::assertInstanceOf(Bubblewrap::class, $pair, 'loadEntries must capture the installed pair into each script row');
        $manager2 = new HookManager(new HookRegistry());
        $manager2->loadEntries(
            HookConfig::parse("hooks:\n  PreToolUse:\n    - name: unpaired\n      command: 'true'\n"),
            '/other/hooks.yaml',
        );
        self::assertNull(
            (new ReflectionProperty(ScriptHook::class, 'sandbox'))->getValue($manager2->hook('PreToolUse', 'unpaired')),
            'no installed jail means rows build exactly as before',
        );
    }

    /**
     * The pure spawn-spec shape ScriptHook::spawnCommand() produces, read by
     * reflection because it is the private single-source door — the shape is
     * asserted, then the real spawn path rides it.
     *
     * @param list<string> $payloadFiles
     * @return list<string>|string
     */
    private function spawnShapeOf(ScriptHook $hook, ?string $cwd, array $payloadFiles): array|string
    {
        $method = new ReflectionMethod(ScriptHook::class, 'spawnCommand');

        return $method->invoke($hook, $cwd, $payloadFiles);
    }

    /** @return array{0:string,1:string} */
    private function jailRunFixture(): array
    {
        $root = $this->jailRoot();
        $file = $root . '/sample.php';
        self::assertNotFalse(file_put_contents($file, "<?php\n"));

        return [$root, $file];
    }

    private function jailRoot(): string
    {
        $dir = sys_get_temp_dir() . '/scoped-jail-' . getmypid() . '-' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0700));
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
