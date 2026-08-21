<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\HeadlessPermissionPrompt;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Sessions\BackgroundSessionRunner;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * The wiring half of the engine-path approver.
 *
 * {@see EngineBackend::withPermissionApprover()} had NO caller in `src/` or
 * `bin/` — measured at master, the only hit outside its own file was a
 * docblock — so every {@see \SugarCraft\Crush\Hooks\HookResult::ask()} on the
 * engine path settled through
 * {@see \SugarCraft\Crush\Runtime::settleAsk()}'s fail-closed arm. These pin
 * the two facts that changed and the one that deliberately did not:
 *
 *  - the `-p` one-shot path builds its backend WITH
 *    {@see HeadlessPermissionPrompt} attached, carrying the launch's mode;
 *  - {@see Bootstrap::backend()}/{@see Bootstrap::backendFor()} still leave it
 *    OFF for every caller that does not ask, which is what keeps a blocking
 *    `fgets(STDIN)` out of the TUI;
 *  - the default permission mode is untouched.
 *
 * The FULL decision table for the prompt itself is
 * {@see HeadlessPermissionPromptTest}'s job. What this file adds beyond
 * "something is attached" is the one assertion that separates the two:
 * {@see testTheAttachedClosureRefusesRatherThanReturningAHardCodedGrant()}
 * calls the closure the wiring actually installed. Without it every
 * assertion in this file survives replacing that closure with
 * `fn(): bool => true`, and the whole change becomes a fail-open.
 *
 * HOME is redirected for the whole class, and the backend-selection chain
 * cleared, for the reasons {@see BootstrapPermissionGateTest} documents: an
 * ambient `$SUGARCRUSH_BACKEND_CMD` makes `Bootstrap::backend()` return a
 * `CommandBackend` and every assertion here vacuous.
 */
final class ConsolePermissionApproverWiringTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    /** @var list<resource> */
    private array $streams = [];

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;
    private string|false $originalMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/console_approver_wiring_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        mkdir($this->tempDir . '/project', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');

        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $this->originalMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('SUGARCRUSH_PERMISSION_MODE');

        $this->clearBackendSelectionEnv();
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->streams = [];

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

        if ($this->originalMode !== false) {
            putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalMode);
        } else {
            putenv('SUGARCRUSH_PERMISSION_MODE');
        }

        $this->restoreBackendSelectionEnv();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------ TUI stays off --

    /**
     * The default is OFF, and this is the assertion that keeps it that way.
     *
     * {@see Bootstrap::chat()} and {@see \SugarCraft\Crush\Chat}'s Ctrl+P
     * provider switch both reach `backend()`/`backendFor()` from inside a TUI
     * holding the terminal in raw mode and alt-screen. An approver attached
     * there would block the render loop on `fgets(STDIN)` and eat the
     * keystrokes it is competing for.
     */
    public function testBackendLeavesTheApproverOffForCallersThatDoNotAskForIt(): void
    {
        $backend = Bootstrap::backend($this->tempDir . '/project');

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertNull($this->approverOf($backend));
    }

    public function testBackendForLeavesTheApproverOffForCallersThatDoNotAskForIt(): void
    {
        $backend = Bootstrap::backendFor('custom', $this->tempDir . '/project');

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertNull($this->approverOf($backend));
    }

    // --------------------------------------------------------- opt-in on --

    public function testBackendAttachesTheConsolePromptWhenAskedFor(): void
    {
        $backend = Bootstrap::backend($this->tempDir . '/project', null, null, true);

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $this->approverOf($backend));
    }

    /**
     * `custom` is the one built-in provider type `ProviderFactory` can
     * construct with no credential in the environment.
     */
    public function testBackendForAttachesTheConsolePromptWhenAskedFor(): void
    {
        $backend = Bootstrap::backendFor('custom', $this->tempDir . '/project', null, null, true);

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $this->approverOf($backend));
    }

    /**
     * `backend()` delegating to `backendFor()` for a persisted provider is a
     * third construction route, and it has to carry the flag across the
     * delegation or a launch with a persisted provider silently loses the
     * approver a launch without one keeps.
     */
    public function testTheFlagSurvivesBackendDelegatingToBackendForAPersistedProvider(): void
    {
        Bootstrap::writeUserConfig(['provider' => 'custom']);

        $backend = Bootstrap::backend($this->tempDir . '/project', null, null, true);

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $this->approverOf($backend));
    }

    // ------------------------------------------------- mode is threaded ---

    /**
     * The prompt names the mode in both the question and the refusal, and a
     * message naming the wrong mode is worse than one naming none: the remedy
     * it suggests would be for a policy the run is not under. So the mode has
     * to come off the gate the engine actually got.
     */
    public function testTheAttachedPromptCarriesTheLaunchsResolvedMode(): void
    {
        putenv('SUGARCRUSH_PERMISSION_MODE=plan');

        $backend = Bootstrap::backend($this->tempDir . '/project', null, null, true);
        $prompt = $this->approverOf($backend);

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $prompt);
        $this->assertSame(PermissionMode::Plan, $this->modeOf($prompt));
    }

    // ------------------------------------------------ the -p path uses it --

    /**
     * {@see NonInteractive::consoleBackend()} is `run()`'s ONLY route to a
     * backend when the caller supplied none, so this is the wiring claim for
     * the `-p` path itself rather than for `Bootstrap` in the abstract.
     */
    public function testTheOneShotPathBuildsItsOfflineBackendWithThePromptAttached(): void
    {
        $backend = NonInteractive::consoleBackend($this->tempDir . '/project', null);

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $this->approverOf($backend));
    }

    public function testTheOneShotPathBuildsItsNamedProviderBackendWithThePromptAttached(): void
    {
        $backend = NonInteractive::consoleBackend($this->tempDir . '/project', 'custom');

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $this->approverOf($backend));
    }

    // ------------------------------------ the daemon uses it, too --------

    /**
     * The background-session daemon is the OTHER console caller, and the
     * reasoning that keeps the approver off the TUI does not reach it.
     *
     * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::spawnSession()}
     * hands the daemon `['file', '/dev/null', 'r']` as descriptor 0, so its
     * stdin is not a terminal and the prompt can never read a keystroke that
     * belonged to anyone else — measured, not assumed:
     * `php -r 'var_dump(stream_isatty(STDIN));' < /dev/null` is `bool(false)`.
     * What attaching it buys is the REFUSAL TEXT: the no-terminal branch names
     * the tool, the mode and the remedies into the session log, where
     * {@see \SugarCraft\Crush\Runtime::settleAsk()}'s no-approver arm says
     * only "no approver is attached to this run".
     */
    public function testTheBackgroundSessionDaemonBuildsItsBackendWithThePromptAttached(): void
    {
        $backend = $this->sessionRunner()->backend();

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $this->approverOf($backend));
    }

    public function testTheBackgroundSessionDaemonKeepsThePromptOnItsNamedProviderRoute(): void
    {
        $backend = $this->sessionRunner('custom')->backend();

        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $this->approverOf($backend));
    }

    // ------------------------------------- the closure DOES the deciding --

    /**
     * THE ASSERTIONS ABOVE ARE NOT ENOUGH ON THEIR OWN, and that is a
     * measurement rather than a worry. Replace the attached closure with
     *
     *     \Closure::bind(
     *         fn(ToolCall $c, HookResult $a): bool => true,
     *         new HeadlessPermissionPrompt($gate->mode()),
     *         HeadlessPermissionPrompt::class,
     *     )
     *
     * and every `assertInstanceOf(HeadlessPermissionPrompt::class, ...)` in
     * this file still passes, because `getClosureThis()` reports what a
     * closure is BOUND to and says nothing about what it RUNS. That mutation
     * makes every `-p` run auto-grant every ASK under
     * `default`/`accept-edits`/`auto` — never prompting, never checking for a
     * terminal — which is precisely the fail-open this change exists to
     * prevent, and it was green across the entire suite.
     *
     * So call it. The prompt's two streams are swapped for `php://memory`
     * ones, which can never be a tty by construction, putting the real
     * `__invoke()` on its no-terminal branch; then the closure is invoked
     * exactly as {@see \SugarCraft\Crush\Runtime::settleAsk()} invokes it. A
     * hard-coded `true` returns true and writes nothing. The real one refuses
     * and says why.
     */
    public function testTheAttachedClosureRefusesRatherThanReturningAHardCodedGrant(): void
    {
        putenv('SUGARCRUSH_PERMISSION_MODE=default');

        $backend = Bootstrap::backend($this->tempDir . '/project', null, null, true);
        $prompt = $this->approverOf($backend);
        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $prompt);

        $in = $this->memoryStream();
        $err = $this->memoryStream();
        $this->rebindStreams($prompt, $in, $err);

        $closure = $this->approverClosureOf($backend);
        $this->assertInstanceOf(\Closure::class, $closure);

        $granted = $closure(
            new ToolCall('c1', 'Edit', ['file_path' => 'a.txt']),
            HookResult::ask('Allow Edit?'),
        );

        $this->assertFalse($granted, 'the attached approver GRANTED an ASK with no terminal to ask at');

        $text = (string) stream_get_contents($err, -1, 0);
        $this->assertStringContainsString('stdin is not a terminal', $text);
        $this->assertStringContainsString('tool: Edit', $text);
        $this->assertStringContainsString('mode: default', $text);
        $this->assertSame(0, ftell($in), 'the no-terminal branch consumed stdin');
    }

    /**
     * The same claim pinned structurally, as a second net rather than the
     * primary one: the installed closure IS the prompt's `__invoke`, not some
     * other body that merely borrowed its `$this`. A `\Closure::bind()` of an
     * arrow function reports `{closure}` here.
     */
    public function testTheAttachedClosureIsThePromptsOwnInvoke(): void
    {
        $backend = Bootstrap::backend($this->tempDir . '/project', null, null, true);
        $closure = $this->approverClosureOf($backend);
        $this->assertInstanceOf(\Closure::class, $closure);

        $function = new \ReflectionFunction($closure);

        $this->assertSame('__invoke', $function->getShortName());
        $this->assertSame(HeadlessPermissionPrompt::class, $function->getClosureScopeClass()?->getName());
    }

    // ------------------------------------------------ the default stands --

    /**
     * Attaching the approver is a PREREQUISITE for a stricter default, not the
     * flip. The TUI path still fails closed, and that is the path an
     * interactive session runs on.
     */
    public function testAttachingTheApproverDidNotMoveTheDefaultMode(): void
    {
        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
    }

    // ----------------------------------------------------------- helpers --

    /**
     * The `$permissionApprover` an EngineBackend is carrying, unwrapped from
     * the `\Closure` back to the object that will answer — reflection because
     * the field is private readonly and there is no accessor, and the closure
     * because that is the shape the seam takes.
     */
    private function approverOf(object $backend): ?object
    {
        if (!$backend instanceof EngineBackend) {
            return null;
        }

        $property = new \ReflectionProperty(EngineBackend::class, 'permissionApprover');
        $closure = $property->getValue($backend);

        if (!$closure instanceof \Closure) {
            return null;
        }

        return (new \ReflectionFunction($closure))->getClosureThis();
    }

    /**
     * The raw `\Closure` the backend carries, before
     * {@see approverOf()} unwraps it to the bound object.
     */
    private function approverClosureOf(object $backend): ?\Closure
    {
        if (!$backend instanceof EngineBackend) {
            return null;
        }

        $closure = (new \ReflectionProperty(EngineBackend::class, 'permissionApprover'))->getValue($backend);

        return $closure instanceof \Closure ? $closure : null;
    }

    /**
     * Point a live prompt at in-memory streams.
     *
     * Reflection because the fields are private with no setter, which is the
     * right shape for production — the streams are constructor state — and
     * the only way a test can drive the object the WIRING built rather than
     * one it constructed itself. Constructing its own would test the class,
     * not the wiring, which is the gap this whole file exists to close.
     *
     * @param resource $in
     * @param resource $err
     */
    private function rebindStreams(HeadlessPermissionPrompt $prompt, $in, $err): void
    {
        (new \ReflectionProperty(HeadlessPermissionPrompt::class, 'in'))->setValue($prompt, $in);
        (new \ReflectionProperty(HeadlessPermissionPrompt::class, 'err'))->setValue($prompt, $err);
    }

    /** @return resource */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'r+');
        assert(is_resource($stream));
        $this->streams[] = $stream;

        return $stream;
    }

    private function sessionRunner(string $provider = ''): BackgroundSessionRunner
    {
        return new BackgroundSessionRunner(
            sessionId: 'wiring',
            socketPath: $this->tempDir . '/wiring.sock',
            bufferPath: $this->tempDir . '/wiring.buffer',
            task: 'x',
            workingDirectory: $this->tempDir . '/project',
            provider: $provider,
        );
    }

    private function modeOf(HeadlessPermissionPrompt $prompt): PermissionMode
    {
        $property = new \ReflectionProperty(HeadlessPermissionPrompt::class, 'mode');

        return $property->getValue($prompt);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
