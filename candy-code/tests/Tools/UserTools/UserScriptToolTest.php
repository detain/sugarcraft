<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\UserTools;

use Closure;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\PathJail;
use SugarCraft\Code\Agents\PathJailConfig;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\UserTools\UserScriptTool;
use SugarCraft\Code\Tools\UserTools\UserToolSpec;

/**
 * Execution semantics of a user-tier script tool (`C-TOOL-6.e`).
 *
 * Tools are built DIRECTLY from a spec with a temp launch root — nothing here
 * touches any real home; the loader surface (and its HOME sandboxing) is
 * pinned in {@see UserToolLoaderTest}. The substitution pins use real `printf`
 * and `sh` runs, exactly like the Bash family's injection pins.
 */
final class UserScriptToolTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/c-tool-6-e-run-' . uniqid('', true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root] as $dir) {
            foreach ((array) scandir($dir) as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($dir . '/' . $entry)) {
                    unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testAnInjectedArgumentStaysOneWordAndStartsNoSecondCommand(): void
    {
        $victim = $this->root . '/victim';
        touch($victim);
        $tool = $this->c6eTool(['printf', '%s', '{{msg}}'], ['msg' => ['type' => 'string']], required: ['msg']);

        $result = $tool->execute(['msg' => '; rm -rf /']);

        self::assertFalse($result->isError());
        self::assertStringContainsString('; rm -rf /', $result->content());
        self::assertFileExists($victim, 'the payload must never reach a second command word');
    }

    public function testTheWholeArgumentMapRidesTheEnvironmentAsJson(): void
    {
        $tool = $this->c6eTool(
            ['sh', '-c', 'echo "$CANDY_CODE_TOOL_INPUT"'],
            ['path' => ['type' => 'string'], 'extra' => ['type' => 'string']],
        );

        $result = $tool->execute(['path' => 'a/b c', 'extra' => 'x']);

        self::assertFalse($result->isError(), $result->content());
        $decoded = json_decode(trim($result->content()), true);
        self::assertIsArray($decoded);
        self::assertSame(['path' => 'a/b c', 'extra' => 'x'], $decoded);
    }

    public function testAnElementThatIsExactlyAnAbsentOptionalPlaceholderIsDropped(): void
    {
        $tool = $this->c6eTool(['echo', 'keep', '{{maybe}}'], [
            'path' => ['type' => 'string'],
            'maybe' => ['type' => 'string'],
        ]);

        $dropped = $tool->execute(['path' => 'p']);
        self::assertFalse($dropped->isError());
        self::assertSame('keep', trim($dropped->content()));

        $kept = $tool->execute(['path' => 'p', 'maybe' => 'here']);
        self::assertFalse($kept->isError());
        self::assertSame('keep here', trim($kept->content()));
    }

    public function testCollectionArgumentsAreRefusedBeforeAnythingRuns(): void
    {
        $marker = $this->root . '/spawned.marker';
        // A collection in ANY position is refused; the touch that WOULD have
        // run first proves the refusal landed before the spawn.
        $tool = $this->c6eTool(['touch', $marker, '{{obj}}'], ['obj' => ['type' => 'string']], required: []);

        $result = $tool->execute(['obj' => ['a', 'b']]);

        self::assertTrue($result->isError());
        self::assertFileDoesNotExist($marker);
    }

    public function testUndeclaredAndMissingArgumentsAreRefused(): void
    {
        $tool = $this->c6eTool(['printf', '%s', '{{path}}'], ['path' => ['type' => 'string']]);

        $undeclared = $tool->execute(['nope' => 'x']);
        self::assertTrue($undeclared->isError());
        self::assertStringContainsString('user__fmt', $undeclared->content());

        $missing = $tool->execute([]);
        self::assertTrue($missing->isError());
        self::assertStringContainsString('is required', $missing->content());
    }

    public function testTheDeclaredTimeoutBreachesAndKillsTheRun(): void
    {
        $longSleep = (string) (20 + 10);
        $tool = $this->c6eTool(['sleep', $longSleep], ['path' => ['type' => 'string']], required: [], timeout: 1);

        $started = microtime(true);
        $result = $tool->execute(['path' => 'p']);
        $wall = microtime(true) - $started;

        self::assertTrue($result->isError());
        self::assertStringContainsString('timed out after 1 seconds', $result->content());
        self::assertStringContainsString('process group was killed', $result->content());
        self::assertLessThan(12.0, $wall, 'the breach must not wait out the slept span');
        // The group is gone: the runCaptured kill ladder is the accounted
        // site; this pin proves THIS tool rides it (no live `sleep $long`
        // child of ours remains by cmdline scan).
        self::assertSame([], $this->c6eMatchingDescendants('sleep ' . $longSleep));
    }

    public function testANonZeroExitIsAnErrorCarryingTheStderrTail(): void
    {
        $tool = $this->c6eTool(['sh', '-c', 'echo boom >&2; exit 3'], ['path' => ['type' => 'string']]);

        $result = $tool->execute(['path' => 'p']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('exited with code 3', $result->content());
        self::assertStringContainsString('boom', $result->content());
    }

    public function testTheCommandRunsInsideTheToolRootAndTheJailWins(): void
    {
        $tool = $this->c6eTool(['pwd'], ['path' => ['type' => 'string']]);
        $plain = $tool->execute(['path' => 'p']);
        self::assertFalse($plain->isError());
        self::assertSame(realpath($this->root), realpath(trim($plain->content())));

        $jailDir = sys_get_temp_dir() . '/c-tool-6-e-jail-' . uniqid('', true);
        mkdir($jailDir, 0o777, true);
        $jailed = $tool->withWorktreeJail(new PathJail($jailDir, new PathJailConfig()));
        $inside = $jailed->execute(['path' => 'p']);
        self::assertFalse($inside->isError());
        self::assertSame(realpath($jailDir), realpath(trim($inside->content())));
        @rmdir($jailDir);
    }

    public function testTheHeartbeatFiresWhileTheToolWaits(): void
    {
        $tool = $this->c6eTool(['sleep', '1.2'], ['path' => ['type' => 'string']], timeout: 30);
        $beats = 0;

        $result = $tool->executeWithHeartbeat(['path' => 'p'], static function () use (&$beats): void {
            $beats++;
        });

        self::assertFalse($result->isError());
        self::assertGreaterThanOrEqual(1, $beats);
    }

    public function testTheSandboxWrapLegRunsThroughTheBackend(): void
    {
        $marker = $this->root . '/wrap.marker';
        $tool = new UserScriptTool(
            $this->c6eSpec(['sh', '-c', 'touch "' . $marker . '"'], ['path' => ['type' => 'string']]),
            root: $this->root,
            sandbox: new class implements SandboxBackend {
                public function name(): string
                {
                    return 'test-cage';
                }

                public function mode(): string
                {
                    return 'test-cage';
                }

                public function resolvedBinary(): string
                {
                    return '/usr/bin/env';
                }

                public function argv(string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): array
                {
                    return [];
                }

                public function wrap(string $shellCommand, string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): string
                {
                    // The wrap is consulted EXACTLY once per call; the echo
                    // is the witness that the wrapped string (not the raw
                    // one) reached the capture path.
                    return 'printf CAGED\\n; ' . $shellCommand;
                }

                public function probe(Closure $run): ?string
                {
                    return null;
                }

                public function refusalRemedy(): string
                {
                    return 'install nothing';
                }

                public function describe(string $writableRoot): string
                {
                    return 'the test cage';
                }
            },
        );

        $result = $tool->execute(['path' => 'p']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('CAGED', $result->content());
        self::assertFileExists($marker, 'the wrapped command still runs its payload');
    }

    public function testAHostWhereTheSandboxCannotStartRefusesWithoutSpawning(): void
    {
        $marker = $this->root . '/refused.marker';
        $tool = new UserScriptTool(
            $this->c6eSpec(['sh', '-c', 'touch "' . $marker . '"'], ['path' => ['type' => 'string']]),
            root: $this->root,
            sandbox: new class implements SandboxBackend {
                public function name(): string
                {
                    return 'test-cage';
                }

                public function mode(): string
                {
                    return 'test-cage';
                }

                public function resolvedBinary(): string
                {
                    return '/usr/bin/env';
                }

                public function argv(string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): array
                {
                    return [];
                }

                public function wrap(string $shellCommand, string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): string
                {
                    self::fail('a backend whose probe refused must never reach wrap');
                }

                public function probe(Closure $run): ?string
                {
                    return 'user namespaces are off';
                }

                public function refusalRemedy(): string
                {
                    return 'set kernel.approcread=0';
                }

                public function describe(string $writableRoot): string
                {
                    return 'the test cage';
                }
            },
        );

        $result = $tool->execute(['path' => 'p']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('cannot start on this host (user namespaces are off)', $result->content());
        self::assertFileDoesNotExist($marker);
    }

    private function c6eTool(array $argv, array $properties, array $required = ['path'], int $timeout = 60): UserScriptTool
    {
        return new UserScriptTool($this->c6eSpec($argv, $properties, $required, $timeout), root: $this->root);
    }

    /**
     * @param array<string, array<string, string>> $properties
     */
    private function c6eSpec(array $argv, array $properties, array $required = ['path'], int $timeout = 60): UserToolSpec
    {
        return new UserToolSpec(
            name: 'fmt',
            description: 'a tool under test',
            inputSchema: ['type' => 'object', 'properties' => $properties, 'required' => $required],
            argv: $argv,
            timeoutSeconds: $timeout,
        );
    }

    /**
     * Our own descendant processes matching a cmdline fragment, via /proc —
     * bounded to this PID's subtree walk of direct children.
     *
     * @return list<string>
     */
    private function c6eMatchingDescendants(string $fragment): array
    {
        $found = [];
        $me = getmypid();
        // /proc only exists on Linux; elsewhere the scan answers honestly
        // empty and the wall-clock pin above carries the breach proof.
        $proc = is_dir('/proc') ? (array) @scandir('/proc') : [];
        foreach ($proc as $pidEntry) {
            if (!ctype_digit($pidEntry)) {
                continue;
            }
            $statFile = '/proc/' . $pidEntry . '/stat';
            $stat = @file_get_contents($statFile);
            if (!is_string($stat)) {
                continue;
            }
            $closeParen = strrpos($stat, ')');
            if ($closeParen === false) {
                continue;
            }
            $fields = preg_split('/\s+/', trim(substr($stat, $closeParen + 1)));
            if (!is_array($fields) || ($fields[3] ?? '') !== (string) $me) {
                continue;
            }
            $cmdline = @file_get_contents('/proc/' . $pidEntry . '/cmdline');
            if (is_string($cmdline) && str_contains($cmdline, $fragment)) {
                $found[] = $cmdline;
            }
        }

        return $found;
    }
}
