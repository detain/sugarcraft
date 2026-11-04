<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Script;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Support\SuiteSkipRoster;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\Seatbelt;
use SugarCraft\Code\Tools\Script\ScriptBridge;
use SugarCraft\Code\Tools\Script\ScriptRun;
use SugarCraft\Code\Tools\Script\ScriptRunner;

/**
 * The engine half of Code Mode, judged end-to-end against a REAL spawned
 * child — the bridge, the jail seam, the wall clock, and every cap is only
 * worth pinning because a live php process sits on the other end of the
 * socketpair. Every leg here runs UNJAILED (`jail: null`) — legal in tests
 * precisely because the engine is policy-free and the caller-side refusal
 * belongs to C-TOOL-6.d — except the one jailed arm, which skips itself by
 * name on hosts where bubblewrap cannot start ({@see SuiteSkipRoster}).
 *
 * The `$dispatch` closures stand in for the gated dispatcher: the design's
 * law is that the bridge calls WHATEVER gate the embedder injects, so the
 * test asserts the injection point is honored, not that a real PermissionGate
 * exists here.
 */
final class ScriptRunnerTest extends TestCase
{
    private string $laneProjectDir;

    protected function setUp(): void
    {
        $this->laneProjectDir = sys_get_temp_dir() . '/c-tool-6c-project-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->laneProjectDir, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->laneProjectDir) ?: [] as $laneName) {
            if ($laneName !== '.' && $laneName !== '..') {
                @unlink($this->laneProjectDir . '/' . $laneName);
            }
        }
        @rmdir($this->laneProjectDir);
    }

    public function testThreeBridgedToolCallsAreAnsweredInOrderThroughTheInjectedGate(): void
    {
        $seen = [];
        $laneDispatch = static function (string $name, array $arguments) use (&$seen): array {
            $seen[] = [$name, $arguments];

            return ['content' => 'ack:' . $name, 'isError' => false];
        };

        $run = ScriptRunner::run(
            code: 'foreach (["one", "two", "three"] as $n) { tool($n, ["at" => $n]); } return count(tools());',
            chdir: $this->laneProjectDir,
            dispatch: $laneDispatch,
            catalog: static fn (): array => [['name' => 'Alpha', 'signature' => 'one line']],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertNull($run->refusal);
        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertFalse($run->timedOut);
        self::assertSame(
            [['one', ['at' => 'one']], ['two', ['at' => 'two']], ['three', ['at' => 'three']]],
            $seen,
            'the order of the child is the order of dispatch, arguments intact',
        );
        self::assertSame(['one', 'two', 'three'], array_column($run->calls, 'name'));
        self::assertSame([ScriptRun::OUTCOME_OK, ScriptRun::OUTCOME_OK, ScriptRun::OUTCOME_OK], array_column($run->calls, 'outcome'));
        foreach ($run->calls as $laneRow) {
            self::assertGreaterThan(0, $laneRow['bytes'], 'every bridged call books its wire bytes');
        }
        self::assertSame(1, $run->return, 'tools() rode the same bridge as the calls');
    }

    public function testToolsAnswersFromTheInjectedCatalogWithMissingSignaturesNormalised(): void
    {
        $run = ScriptRunner::run(
            code: 'return tools();',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [
                ['name' => 'Read', 'signature' => 'Read a file off disk'],
                ['name' => 'Grep'],
            ],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(
            [['name' => 'Read', 'signature' => 'Read a file off disk'], ['name' => 'Grep', 'signature' => '']],
            $run->return,
        );
        self::assertSame([], $run->calls, 'a list round is not a tool call and books no ledger row');
    }

    public function testTheScriptReturnValueRoundTripsThroughTheBridgeAndAbsentTagsStillRun(): void
    {
        $run = ScriptRunner::run(
            code: 'return ["a" => 1, "b" => ["c" => "x"], "t" => true, "n" => null];',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(['a' => 1, 'b' => ['c' => 'x'], 't' => true, 'n' => null], $run->return);

        // The no-`<?php` staging leg: the runner prepends the tag, so a bare
        // expression body is a legal script.
        $bare = ScriptRunner::run(
            code: 'return 42;',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(42, $bare->return, $bare->stderr);
        self::assertSame(0, $bare->exitCode);
    }

    public function testStdoutAndStderrAreCapturedOnTheirOwnStreams(): void
    {
        $run = ScriptRunner::run(
            code: 'fwrite(STDOUT, "out-1' . "\n" . '"); fwrite(STDERR, "err-1' . "\n" . '"); return null;',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame("out-1\n", $run->stdout);
        self::assertSame("err-1\n", $run->stderr, 'the child never inherits the runner stderr; it answers back through the DTO');
        self::assertNull($run->return);
    }

    public function testTheSixtyFourKibibyteStreamCapsKeepTheTailAndCountTheDrop(): void
    {
        $run = ScriptRunner::run(
            code: 'echo "FIRST-MARKER-6C' . "\n" . '"; for ($i = 0; $i < 9000; $i++) { fwrite(STDOUT, "0123456789abcdef' . "\n" . '"); fwrite(STDERR, "fedcba9876543210' . "\n" . '"); } echo "LAST-MARKER-6C' . "\n" . '";',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 20.0,
        );

        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertSame(65536, strlen($run->stdout));
        self::assertSame(65536, strlen($run->stderr));
        self::assertStringNotContainsString('FIRST-MARKER-6C', $run->stdout, 'the head is what goes');
        self::assertStringNotContainsString('FIRST-MARKER-6C', $run->stderr);
        self::assertStringContainsString("LAST-MARKER-6C\n", $run->stdout, 'the tail is what stays');
        self::assertStringEndsWith("fedcba9876543210\n", $run->stderr, 'the LAST-MARKER echo rode stdout only; the stderr tail is its own last hex line');
        self::assertGreaterThan(0, $run->capBreaches['stdout'] ?? 0);
        self::assertGreaterThan(0, $run->capBreaches['stderr'] ?? 0);
    }

    public function testTheWallClockExpiryTerminatesTheGroupAndNamesTheTimeout(): void
    {
        $run = ScriptRunner::run(
            code: 'echo "tick' . "\n" . '"; while (true) { usleep(20000); }',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 0.6,
        );

        self::assertTrue($run->timedOut);
        self::assertNull($run->refusal, 'an expiry is not a refusal — the run happened, the clock ended it');
        self::assertLessThan(0, $run->exitCode, 'the child died on a signal, not on its own terms');
        self::assertStringContainsString('tick', $run->stdout);
        self::assertStringContainsString('wall clock', implode(' ', $run->notes), 'the expiry is named in the notes');
    }

    public function testTheWallClockPausesWhileABridgedCallIsInFlight(): void
    {
        // 1.2s inside the dispatcher under a 0.6s wall: SCRIPT time is what
        // the ceiling counts, and the gate's work is not script time. The
        // E646 reading is deliberate — this bounds the SCRIPT process, never
        // an LLM request.
        $laneSlowDispatch = static function (string $name, array $arguments): array {
            usleep(1_200_000);

            return ['content' => 'slow-ack', 'isError' => false];
        };

        $run = ScriptRunner::run(
            code: '$r = tool("Slow", []); return $r["content"];',
            chdir: $this->laneProjectDir,
            dispatch: $laneSlowDispatch,
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 0.6,
        );

        self::assertFalse($run->timedOut, 'dispatch time was paused out of the ceiling');
        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertSame('slow-ack', $run->return);
    }

    public function testTheTwoHundredSeventySeventhCallIsRefusedAtTheBridge(): void
    {
        $laneCount = 0;
        $laneDispatch = static function (string $name, array $arguments) use (&$laneCount): array {
            $laneCount++;

            return ['content' => 'ok', 'isError' => false];
        };

        $run = ScriptRunner::run(
            code: '$last = null; for ($i = 0; $i < 257; $i++) { $last = tool("Bee", []); } '
                . 'return ["isError" => $last["isError"], "content" => $last["content"]];',
            chdir: $this->laneProjectDir,
            dispatch: $laneDispatch,
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 30.0,
        );

        self::assertSame(256, $laneCount, 'the gate saw exactly the budget');
        self::assertTrue((bool) ($run->return['isError'] ?? false), 'the 257th got a named refusal frame, not silence');
        self::assertStringContainsString('budget', (string) ($run->return['content'] ?? ''));
        self::assertCount(257, $run->calls);
        self::assertSame(ScriptRun::OUTCOME_DENIED, $run->calls[256]['outcome']);
        self::assertSame(1, $run->capBreaches['calls'] ?? 0);
        self::assertSame(0, $run->exitCode);
    }

    public function testNoOrphanOfTheScriptsOwnSurvivesTheRun(): void
    {
        // The script spawns a thirty-second sleep that holds the inherited
        // stdout pipe open, then loops forever. Two laws ride one fixture:
        // the wall expiry kills the GROUP (not just the leader), and the
        // child's own children cannot outlive run().
        $run = ScriptRunner::run(
            code: '$pp = []; $kid = proc_open(["sleep", "30"], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pp); '
                . 'fwrite(STDOUT, "PIDS " . getmypid() . " " . proc_get_status($kid)["pid"] . "\n"); '
                . 'while (true) { usleep(20000); }',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 0.6,
        );

        self::assertTrue($run->timedOut);

        self::assertMatchesRegularExpression('/PIDS (\d+) (\d+)/', $run->stdout, $run->stderr);
        [, $laneScriptPid, $laneSleepPid] = array_map('intval', $this->lanePidsFrom($run));

        foreach ([$laneScriptPid, $laneSleepPid] as $lanePid) {
            $laneDeadline = microtime(true) + 5.0;
            while (is_dir('/proc/' . $lanePid) && microtime(true) < $laneDeadline) {
                usleep(20_000);
            }
            self::assertDirectoryDoesNotExist('/proc/' . $lanePid, 'pid ' . $lanePid . ' outlived the run');
        }
    }

    public function testGarbageOnTheBridgeAnswersWithErrorAndTouchesNoGate(): void
    {
        $laneHits = 0;
        $laneDispatch = static function (string $name, array $arguments) use (&$laneHits): array {
            $laneHits++;

            return ['content' => 'unused', 'isError' => false];
        };

        $run = ScriptRunner::run(
            code: '$s = fopen("php://fd/3", "r+"); fwrite($s, "!not-json-and-names-no-id!' . "\n" . '"); $answer = json_decode((string) fgets($s), true); '
                . 'return ["echoed" => $answer["error"] ?? null, "idNull" => array_key_exists("id", $answer) && $answer["id"] === null];',
            chdir: $this->laneProjectDir,
            dispatch: $laneDispatch,
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(0, $laneHits, 'a line the bridge cannot decode never reaches the gate');
        self::assertStringContainsString('malformed frame', (string) ($run->return['echoed'] ?? ''));
        self::assertTrue((bool) ($run->return['idNull'] ?? false));
        self::assertSame([], $run->calls);
        self::assertSame(0, $run->exitCode);
    }

    public function testAFrameNamingAnUnknownMethodIsRefusedWithoutDispatch(): void
    {
        $laneHits = 0;
        $laneDispatch = static function (string $name, array $arguments) use (&$laneHits): array {
            $laneHits++;

            return ['content' => 'unused', 'isError' => false];
        };

        $run = ScriptRunner::run(
            code: '$s = fopen("php://fd/3", "r+"); fwrite($s, json_encode(["id" => 7, "method" => "poltergeist"]) . "\n"); $answer = json_decode((string) fgets($s), true); '
                . 'return ["errored" => $answer["error"] ?? null, "id" => $answer["id"] ?? null];',
            chdir: $this->laneProjectDir,
            dispatch: $laneDispatch,
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(0, $laneHits);
        self::assertStringContainsString('unknown method', (string) ($run->return['errored'] ?? ''), 'return=' . json_encode($run->return) . ' exit=' . $run->exitCode . ' stderr=' . substr($run->stderr, 0, 300) . ' notes=' . implode('|', $run->notes));
        self::assertSame(7, $run->return['id'] ?? null, 'the refusal is addressed to the frame that asked');
        self::assertSame(0, $run->exitCode);
    }

    public function testAScriptThatThrowsDiesOnStderrWithExitOneAndNoReturnFrame(): void
    {
        $run = ScriptRunner::run(
            code: 'throw new RuntimeException("boom-inside-lane-6c");',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'unused', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(1, $run->exitCode);
        self::assertStringContainsString('boom-inside-lane-6c', $run->stderr);
        self::assertStringContainsString('RuntimeException', $run->stderr);
        self::assertNull($run->return);
    }

    public function testAMissingWorkingDirectoryIsRefusedBeforeAnySpawn(): void
    {
        $laneRan = false;
        $run = ScriptRunner::run(
            code: 'return tool("Never", []);',
            chdir: $this->laneProjectDir . '-not-there',
            dispatch: static function () use (&$laneRan): array {
                $laneRan = true;

                return ['content' => 'x', 'isError' => false];
            },
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 5.0,
        );

        self::assertStringContainsString('does not exist', (string) $run->refusal);
        self::assertFalse($laneRan);
        self::assertSame(-1, $run->exitCode);
        self::assertSame([], $run->calls);
    }

    public function testAJailBinaryThatDoesNotExistRefusesRatherThanRunUnjailed(): void
    {
        // On this Linux box sandbox-exec cannot resolve, and the engine must
        // read that as "the fence is not there" — running anyway would be
        // the promise the design refuses to make.
        $run = ScriptRunner::run(
            code: 'return 1;',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'x', 'isError' => false],
            catalog: static fn (): array => [],
            jail: Seatbelt::new(),
            wallSeconds: 5.0,
        );

        self::assertNotNull($run->refusal);
        self::assertStringContainsString('seatbelt', (string) $run->refusal, 'the refusal names which jail is missing');
        self::assertStringContainsString('refusing', (string) $run->refusal);
        self::assertFalse($run->timedOut);
        self::assertSame(-1, $run->exitCode);
        self::assertSame('', $run->stdout);
    }

    public function testAReturnFrameOverTheBudgetIsNamedAndDropped(): void
    {
        $run = ScriptRunner::run(
            code: 'return str_repeat("R", 70000);',
            chdir: $this->laneProjectDir,
            dispatch: static fn (): array => ['content' => 'x', 'isError' => false],
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertNull($run->return, 'an over-budget return is dropped whole, never half-trusted');
        $dropped = $run->capBreaches['return'] ?? 0;
        self::assertGreaterThan(0, $dropped, 'the breach is named with its byte count');
        self::assertLessThan(6_000, $dropped, 'the count is the excess over the cap, not the whole frame');
        self::assertMatchesRegularExpression('/return frame .* was dropped/', implode('|', $run->notes));
    }

    public function testAResponseOverTheFrameCapIsClippedAtTheEndAndNamed(): void
    {
        $big = str_repeat('C', ScriptBridge::MAX_BRIDGE_FRAME_BYTES + 600);
        $run = ScriptRunner::run(
            // The child measures what ARRIVED: if the clip were removed the
            // over-cap line would truncate inside the child's fgets and the
            // decode would fail into an error frame instead of this content.
            code: '$a = tool("big", []); return ["bytes" => strlen((string)($a["content"] ?? "")), "tail" => substr((string)($a["content"] ?? ""), -4), "err" => (bool)($a["isError"] ?? false)];',
            chdir: $this->laneProjectDir,
            dispatch: static function () use ($big): array {
                return ['content' => $big, 'isError' => false];
            },
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertIsArray($run->return);
        self::assertFalse(($run->return ?? [])['err'], 'the child received a well-formed frame, not a decode failure');
        $bytes = (int) (($run->return ?? [])['bytes'] ?? 0);
        self::assertLessThanOrEqual(ScriptBridge::MAX_BRIDGE_FRAME_BYTES, $bytes, 'no frame crosses the 1MiB budget');
        self::assertGreaterThan(ScriptBridge::MAX_BRIDGE_FRAME_BYTES - 200, $bytes, 'the clip trims to the cap, it does not collapse the response');
        self::assertSame('cap]', (string) (($run->return ?? [])['tail'] ?? ''), 'the breach is named inside the content itself');
        self::assertGreaterThan(600, $run->capBreaches['response'] ?? 0, 'the dropped tail is counted by name');
        self::assertSame(ScriptRun::OUTCOME_OK, $run->calls[0]['outcome'] ?? null);
    }

    public function testTheJailBlocksProjectWritesAndNetworkButTheBridgeStillAnswers(): void
    {
        if (!SuiteSkipRoster::scriptJailRuns()) {
            self::markTestSkipped(
                'bubblewrap cannot start on this host (no bwrap, or the kernel refuses the user '
                    . 'namespaces it needs), so the jailed arm has nothing to assert',
            );
        }

        $run = ScriptRunner::run(
            code: '$cwd = getcwd(); '
                . '$write = @file_put_contents($cwd . "/lane-jail-probe.txt", "x"); '
                . '$scratch = @file_put_contents(dirname((string) end($GLOBALS["argv"])) . "/lane-scratch-probe.txt", "x"); '
                . '$net = @stream_socket_client("tcp://127.0.0.1:4317", $errno, $errstr, 1.0); '
                . '$ack = tool("Probe", []); '
                . 'return ["writeBlocked" => $write === false, "scratchWritable" => $scratch !== false, '
                . '"netBlocked" => $net === false, "bridgeAck" => $ack["content"], "bridgeError" => $ack["isError"]];',
            chdir: $this->laneProjectDir,
            dispatch: static fn (string $name, array $arguments): array => ['content' => 'jail-ack', 'isError' => false],
            catalog: static fn (): array => [],
            jail: Bubblewrap::new(network: false),
            wallSeconds: 20.0,
        );

        self::assertNull($run->refusal, $run->stderr);
        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertTrue((bool) ($run->return['writeBlocked'] ?? false), 'the project dir must be read-only to the child');
        self::assertTrue((bool) ($run->return['scratchWritable'] ?? false), 'the staged scratch must stay writable');
        self::assertTrue((bool) ($run->return['netBlocked'] ?? false), 'no network means NO loopback either');
        self::assertSame('jail-ack', $run->return['bridgeAck'] ?? null, 'fencing the child must not fence the bridge');
        self::assertFalse((bool) ($run->return['bridgeError'] ?? true));
        self::assertDirectoryDoesNotExist($this->laneProjectDir . '/lane-jail-probe.txt');
    }

    public function testADispatchAnsweringWithNonStringContentArrivesAsANamedErrorNotASilentEmpty(): void
    {
        // PHPD-03 (reviews-1103 G1-L3A): the bridge contract says content IS
        // a string. The belt used to answer a broken shape with '' plus
        // isError:false — a successful-looking empty result; both sides of
        // the bridge now name the mismatch in the voice every other frame
        // defect uses. Production dispatchers hand back
        // ToolResult::content() — typed string — so only a hand-rolled
        // dispatcher can ever stand here; that is exactly what this
        // deliberately misshapen closure is.
        $laneTrap = static fn (string $name, array $arguments): array => ['content' => ['not', 'a', 'string'], 'isError' => false];

        $run = ScriptRunner::run(
            code: '$r = tool("Trap", []); return ["err" => $r["isError"], "text" => $r["content"]];',
            chdir: $this->laneProjectDir,
            dispatch: $laneTrap,
            catalog: static fn (): array => [],
            jail: null,
            wallSeconds: 15.0,
        );

        self::assertNull($run->refusal, $run->stderr);
        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertTrue((bool) ($run->return['err'] ?? false), 'a broken dispatch shape is an error for the script, never an empty success');
        self::assertStringContainsString('tool result content must be a string, got array', (string) ($run->return['text'] ?? ''));
        self::assertStringContainsString('Trap', (string) ($run->return['text'] ?? ''), 'the named row says which call broke');
        self::assertSame([ScriptRun::OUTCOME_ERROR], array_column($run->calls, 'outcome'), 'the ledger books the shape violation as an error outcome');
    }

    /**
     * @return array<int,string> the two pid columns of the fixture's PIDS line
     */
    private function lanePidsFrom(ScriptRun $run): array
    {
        preg_match('/PIDS (\d+) (\d+)/', $run->stdout, $laneMatch);

        return [$laneMatch[0], $laneMatch[1], $laneMatch[2]];
    }
}
