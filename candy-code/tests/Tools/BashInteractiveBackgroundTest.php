<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;

/**
 * C-TOOL-6.k: Bash's `run_in_background` + `interactive` combination is a
 * ROUTE now — it starts a relayed interactive shell instead of refusing a
 * contradiction. This file pins the ROUTE: the sandbox wrap applies before
 * the hand-off and the hand-off receives the wrapped string, the refusal of
 * a dowsed host costs nothing, and the live answer teaches the model the
 * typing channel. The door shape for a registry-less launch is pinned in
 * {@see BashBackgroundTest}; the relay engine itself is the 6.j suites'
 * subject, never re-tested here.
 *
 * THIS FILE NEVER SKIPS (the 6.j host discipline): the machine that ships
 * the phar carries ffi/posix/pcntl and a ptmx, so the live legs EXECUTE.
 */
final class BashInteractiveBackgroundTest extends TestCase
{
    private string $ibRoot;

    /** @var list<BackgroundShellRegistry> */
    private array $ibRegistries = [];

    protected function setUp(): void
    {
        $this->ibRoot = (string) sys_get_temp_dir() . '/c6k-bash-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->ibRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->ibRegistries as $registry) {
            $registry->teardown();
        }
        $this->ibRegistries = [];
        ProcessContainment::resetInteractiveAvailabilityForTesting();
        foreach (scandir($this->ibRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $this->ibRoot . '/' . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
        @rmdir($this->ibRoot);
    }

    private function ibRegistry(): BackgroundShellRegistry
    {
        $registry = BackgroundShellRegistry::forSession('c6k-bash-test', $this->ibRoot);
        $this->ibRegistries[] = $registry;

        return $registry;
    }

    /**
     * A jail spy: `probe()` answers available WITHOUT touching a binary,
     * `wrap()` records the shell string and the interactive flag and returns
     * a sentinel-prefixed copy. It never spawns, so the combo route's cost
     * is a refused relay start on a dowsed host — nothing runs. State rides
     * a shared stdClass because PHP forbids by-reference promoted
     * constructors.
     */
    private function ibSpySandbox(\stdClass $seen): SandboxBackend
    {
        return new class($seen) implements SandboxBackend {
            public function __construct(private \stdClass $seen) {}

            public function name(): string
            {
                return 'spy';
            }

            public function mode(): string
            {
                return SandboxBackend::MODE_ON;
            }

            public function resolvedBinary(): string
            {
                return '/bin/true';
            }

            public function argv(
                string $writableRoot,
                bool $interactive = false,
                ?string $tmpDir = null,
                ?string $chdir = null,
                array $readOnlyBinds = [],
            ): array {
                return [];
            }

            public function wrap(
                string $shellCommand,
                string $writableRoot,
                bool $interactive = false,
                ?string $tmpDir = null,
                ?string $chdir = null,
                array $readOnlyBinds = [],
            ): string {
                $this->seen->calls++;
                $this->seen->interactive = $interactive;
                $this->seen->wrapped = 'SPYJAIL ' . $shellCommand;

                return 'SPYJAIL ' . $shellCommand;
            }

            public function probe(\Closure $run): ?string
            {
                return null;
            }

            public function refusalRemedy(): string
            {
                return 'no remedy — a spy';
            }

            public function describe(string $writableRoot): string
            {
                return 'the spy jail';
            }
        };
    }

    public function testTheComboReachesTheRelayDoorWithTheSandboxAlreadyApplied(): void
    {
        // The host's own answer is dowsed by memo (the 6.j refusal-polarity
        // idiom): the route must still run the sandbox wrap and hand the
        // wrapped string to the relay door, and the engine's refusal must
        // come back as the answer — a refused interactive start costs no
        // spawn and no jail bypass.
        $registry = $this->ibRegistry();
        $seen = new \stdClass();
        $seen->calls = 0;
        $seen->interactive = null;
        $seen->wrapped = null;
        $bash = (new Bash(registry: $registry))->withSandbox($this->ibSpySandbox($seen));
        $memo = new ReflectionProperty(ProcessContainment::class, 'interactiveAvailableMemo');
        $memo->setValue(null, false);

        try {
            $result = $bash->execute([
                'command' => 'printf typed-proof',
                'description' => 'combo spy',
                'run_in_background' => true,
                'interactive' => true,
            ]);
        } finally {
            ProcessContainment::resetInteractiveAvailabilityForTesting();
        }

        self::assertTrue($result->isError());
        self::assertStringContainsString('pty mechanism unavailable', $result->content(), 'the answer is the ENGINE refusal — the route reached startInteractive');
        self::assertSame(1, (int) $seen->calls, 'the sandbox wrap ran exactly once before the hand-off');
        self::assertTrue((bool) $seen->interactive, 'the wrap is told this is an interactive line, exactly as the sync interactive path tells it');
        self::assertStringStartsWith('SPYJAIL bash -c ', (string) $seen->wrapped, 'what gets wrapped is the POSIX-shaped bash -c string the plain path would start — prefix and all');
        self::assertStringContainsString('printf typed-proof', (string) $seen->wrapped);
    }

    public function testTheRouteHandsStartInteractiveTheWrappedVariable(): void
    {
        // The runtime leg above proves the wrap RAN; this scan leg proves the
        // hand-off consumes the WRAPPED value, not the raw pre-wrap string:
        // the reassignment `$cmd = $this->sandbox->wrap(...)` sits BEFORE the
        // interactive branch that passes `$cmd` on. Deleting either half (a
        // route that re-reads $script, or a moved wrap) reddens here.
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/Bash.php');
        $wrapAt = strpos($source, '$cmd = $this->sandbox->wrap($cmd, $this->writableRoot(), $interactive)');
        self::assertSame(1, substr_count($source, '$this->registry->startInteractive('), 'exactly one interactive background hand-off exists — the route is a door, not a menu');
        $routeAt = strpos($source, '$this->registry->startInteractive(');
        self::assertIsInt($wrapAt, 'the sandbox wrap reassignment moved or changed shape — re-anchor');
        self::assertIsInt($routeAt, 'the interactive background route lost its startInteractive call');
        self::assertLessThan($routeAt, $wrapAt, 'the wrap must happen before the hand-off');
        $between = substr($source, $wrapAt + strlen('$cmd = $this->sandbox->wrap($cmd, $this->writableRoot(), $interactive)'), $routeAt - $wrapAt);
        self::assertStringNotContainsString('$cmd =', $between, 'nothing may re-spell $cmd between the wrap and the hand-off — the staged command must carry the jail prefix');
        $handoff = substr($source, $routeAt, 260);
        self::assertStringContainsString("\n                    \$cmd,", $handoff, 'the hand-off argument is the wrapped $cmd variable');
    }

    public function testTheOldContradictionRefusalIsGoneFromTheSource(): void
    {
        // Regression floor for the design's central flip: the sentence that
        // refused the combination may never come back, in either source or
        // answer.
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/Bash.php');
        self::assertStringNotContainsString('cannot both be true', $source);
    }

    public function testTheSchemaAndDescriptionTeachTheTypingChannel(): void
    {
        $bash = new Bash();
        $schema = $bash->inputSchema();

        self::assertStringContainsString('ShellSend', $schema['properties']['interactive']['description'], 'the interactive flag names the combination and its tool');
        self::assertStringContainsString('run_in_background: true', $schema['properties']['interactive']['description']);
        self::assertStringContainsString('ShellSend', $schema['properties']['run_in_background']['description']);
        self::assertStringContainsString('interactive: true', $schema['properties']['run_in_background']['description']);
        self::assertStringContainsString('INTERACTIVE background shell', $bash->description());
        self::assertStringContainsString('ShellSend', $bash->description());
    }

    public function testTheLiveRouteAnswersATrackableInteractiveRow(): void
    {
        $registry = $this->ibRegistry();
        $bash = new Bash(registry: $registry);

        $result = $bash->execute([
            'command' => 'sleep 40',
            'description' => 'live combo',
            'run_in_background' => true,
            'interactive' => true,
        ]);

        self::assertFalse($result->isError(), $result->content());
        $text = $result->content();
        self::assertStringContainsString('Started interactive background shell', $text);
        self::assertStringContainsString(sprintf('(%dx%d)', BackgroundShellRegistry::INTERACTIVE_COLS, BackgroundShellRegistry::INTERACTIVE_ROWS), $text, 'the terminal size is disclosed at spawn');
        self::assertStringContainsString('Type into it with ShellSend', $text);
        self::assertStringContainsString('ShellOutput', $text, 'the read channel is still named — missed bytes are readable');
        self::assertStringContainsString('ShellStop', $text);
        self::assertStringContainsString((string) BackgroundShellRegistry::MAX_LIFETIME_SECONDS, $text, 'the lifetime wall sentence rides the interactive answer too');
        self::assertMatchesRegularExpression('/sh-[0-9a-f]{8}/', $text);

        $id = (string) (preg_match('/sh-[0-9a-f]{8}/', $text, $m) ? $m[0] : '');
        $row = $registry->status($id);
        self::assertIsArray($row);
        self::assertTrue((bool) ($row['interactive'] ?? false), 'the registry row carries the interactive half');
        self::assertSame(BackgroundShellRegistry::INTERACTIVE_COLS, (int) $row['cols']);
        self::assertSame(BackgroundShellRegistry::INTERACTIVE_ROWS, (int) $row['rows']);
        self::assertSame(0, (int) $row['humanInputs']);

        // The typed round trip through this very row is ShellSendTest's live
        // subject; here the route is proven by the trackable row itself.
    }

    public function testAPlainBackgroundAnswerKeepsItsOldFramingByteFreeOfShellSend(): void
    {
        // The design changed only the combination. A plain
        // `run_in_background` launch must not suddenly learn about a tool it
        // cannot type into — its answer stays the read-first framing.
        $registry = $this->ibRegistry();
        $bash = new Bash(registry: $registry);

        $result = $bash->execute([
            'command' => 'sleep 40',
            'description' => 'plain framing',
            'run_in_background' => true,
        ]);

        self::assertFalse($result->isError());
        self::assertStringStartsWith('Started background shell', $result->content());
        self::assertStringNotContainsString('Type into it with ShellSend', $result->content());
        self::assertStringContainsString('capped at', $result->content(), 'the MiB cap sentence belongs to the plain path alone');
    }
}
