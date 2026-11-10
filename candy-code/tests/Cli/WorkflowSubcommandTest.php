<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Workflows\WorkflowEngine;

/**
 * W-1: the native headless `candy-code workflow run|status|resume` verb,
 * driven END TO END against the real binary the way RS-DRIVER probe J asks
 * to be answered — exit codes a fleet can gate on.
 *
 * Every leg spawns the shipped bin/candy-code with a temp HOME carrying a
 * user-tier workflow file and CANDY_CODE_BACKEND_CMD pointing at a scripted
 * shell stub: the command-tier backend answers agent and verifier calls
 * WITHOUT provider keys or HTTP (the same offline door
 * NonInteractiveBackendErrorExitTest proves). The run therefore executes the
 * REAL engine — registry load, stage dispatch through the forked pool,
 * verification scanning, pause records — which is what makes the exit-code
 * pins load-bearing rather than tautological.
 *
 * The signal legs skip when the build lacks posix_kill/SIGTERM; every leg is
 * self-bounded with pcntl_alarm so a wedged child fails fast and never hangs
 * the suite (the exit code comes from the bounded drain + proc_close — a
 * single-shot proc_get_status after pipe EOF is racy, the pipe-EOF lesson
 * family).
 *
 * @see docs/WORKFLOWS.md section "Running from the CLI (headless)"
 */
final class WorkflowSubcommandTest extends TestCase
{
    /** Seconds to wait for a workflow child to finish on its own. */
    private const MAX_DRAIN_SECONDS = 120.0;

    /** Seconds to wait for the backend stub to have started a stage. */
    private const STAGE_START_WAIT_SECONDS = 40.0;

    private string $home;

    protected function setUp(): void
    {
        $this->home = \sys_get_temp_dir() . '/wf-sub-cmd-' . \getmypid() . '-' . \uniqid('', true);
        if (!\is_dir($this->home) && !\mkdir($this->home . '/.candy-code/workflows', 0700, true)) {
            self::fail('could not create the temp HOME');
        }
    }

    protected function tearDown(): void
    {
        self::cancelWorkflowVerbAlarm();
        self::removeWorkflowVerbTree($this->home);
    }

    // ------------------------------------------------------------------
    //  End-to-end legs
    // ------------------------------------------------------------------

    public function testCompletedRunEmitsTheMachineDocumentAndExitsZero(): void
    {
        self::boundWorkflowVerbAlarm();
        self::writeWorkflowVerbDemoFile($this->home);
        $script = self::writeWorkflowVerbScript($this->home, self::workflowVerbEchoBody([
            'ummarise' => 'first summary',
            'Check' => 'second pass: VERDICT: pass',
        ]));

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo', '--format', 'json'], $script);

        self::assertSame(0, $code, 'a completed run must exit 0; stderr was: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        self::assertSame(1, $doc['schema'] ?? null);
        self::assertSame('workflow', $doc['verb'] ?? null);
        self::assertSame('run', $doc['action'] ?? null);
        $result = $doc['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame('demo', $result['workflow'] ?? null);
        self::assertSame('completed', $result['status'] ?? null);
        self::assertArrayHasKey('session_id', $result, 'the honest null session rides every document');
        self::assertNull($result['session_id']);
        $stages = $result['stages'] ?? null;
        self::assertIsArray($stages);
        self::assertCount(1, $stages, 'the one verification stage is the whole run');
        self::assertSame('verify', $stages[0]['name'] ?? null);
        self::assertSame('completed', $stages[0]['status'] ?? null);
        self::assertIsString($stages[0]['output'] ?? null);
        self::assertSame(2, $stages[0]['attempts'] ?? null, 'task and verifier both dispatched');
        $totals = $result['totals'] ?? null;
        self::assertIsArray($totals);
        foreach (['tokens', 'cost', 'duration_ms'] as $key) {
            self::assertArrayHasKey($key, $totals);
        }
    }

    public function testTextModeRunPrintsStageRowsAndExitsZero(): void
    {
        self::boundWorkflowVerbAlarm();
        self::writeWorkflowVerbDemoFile($this->home);
        $script = self::writeWorkflowVerbScript($this->home, self::workflowVerbEchoBody([
            'ummarise' => 'first summary',
            'Check' => 'second pass: VERDICT: pass',
        ]));

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo'], $script);

        self::assertSame(0, $code, 'stderr was: ' . $err);
        self::assertStringContainsString('completed', $out);
        self::assertStringContainsString('verify', $out);
    }

    public function testEscalatedVerificationExitsThreeAndCarriesTheMarker(): void
    {
        self::boundWorkflowVerbAlarm();
        self::writeWorkflowVerbDemoFile($this->home);
        // 'Check' BEFORE 'ummarise': the verifier call's history still
        // carries the gather prompt, so a gather branch placed first would
        // shadow the verification answer.
        $script = self::writeWorkflowVerbScript($this->home, self::workflowVerbEchoBody([
            'Check' => 'ESCALATE: is the tone right?',
            'ummarise' => 'first summary',
        ]));

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo', '--format', 'json'], $script);

        self::assertSame(3, $code, 'an escalated verification must stop for a person at exit 3; stderr: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        $result = $doc['result'] ?? null;
        self::assertSame('failed', $result['status'] ?? null, 'escalation is a stop, not a completion');
        $verifyRow = null;
        foreach ((array) ($result['stages'] ?? []) as $row) {
            if (($row['name'] ?? null) === 'verify') {
                $verifyRow = $row;
            }
        }
        self::assertIsArray($verifyRow, 'the verify stage must appear in the document');
        self::assertStringStartsWith('escalated: ', (string) ($verifyRow['error'] ?? ''), 'the D4 predicate keys on the public marker');
    }

    public function testFailingBackendExitsOne(): void
    {
        self::boundWorkflowVerbAlarm();
        self::writeWorkflowVerbDemoFile($this->home);
        $script = self::writeWorkflowVerbScript($this->home, "in=\$(cat)\nprintf '%s\\n' boom >&2\nexit 9");

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo', '--format', 'json'], $script);

        self::assertSame(1, $code, 'a failed stage must exit 1; stderr: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        self::assertSame('failed', $doc['result']['status'] ?? null);
    }

    public function testUnknownWorkflowNameIsRefusedWithExitOne(): void
    {
        self::boundWorkflowVerbAlarm();
        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'nope-not-here', '--format', 'json']);

        self::assertSame(1, $code, 'stderr: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        $result = $doc['result'] ?? null;
        self::assertSame('refused', $result['status'] ?? null);
        self::assertStringContainsString('nope-not-here', (string) ($result['refusal'] ?? ''));
    }

    public function testStatusWithoutAnyRecordIsRefusedHonestly(): void
    {
        self::boundWorkflowVerbAlarm();
        [$code, $out] = self::runWorkflowVerbChild($this->home, ['workflow', 'status', 'nothing-here', '--format', 'json']);

        self::assertSame(1, $code);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        self::assertSame('status', $doc['action'] ?? null);
        self::assertSame('refused', $doc['result']['status'] ?? null);
    }

    public function testParamRoundTripReachesTheStagePrompt(): void
    {
        self::boundWorkflowVerbAlarm();
        self::writeWorkflowVerbSoloFile($this->home);
        $needle = 'zzcjqx';
        $body = "in=\$(cat)\n"
            . "case \"\$in\" in\n"
            . "  *{$needle}*) printf '%s\\n' \"themed answer for {$needle}\";;\n"
            . "  *) printf '%s\\n' 'theme never arrived';;\n"
            . "esac\nexit 0\n";
        $script = self::writeWorkflowVerbScript($this->home, $body);

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'solo', '--param', 'theme=' . $needle, '--format', 'json'], $script);

        self::assertSame(0, $code, 'stderr: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        self::assertSame('completed', $doc['result']['status'] ?? null);
        self::assertStringContainsString($needle, (string) ($doc['result']['stages'][0]['output'] ?? ''), 'the pair splitter must feed context into the stage prompt');
    }

    // ------------------------------------------------------------------
    //  Usage legs (exit 2)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function workflowVerbUsageCases(): array
    {
        return [
            'no action' => [['workflow'], 'no action'],
            'unknown action' => [['workflow', 'frobnicate', 'demo'], 'unknown action'],
            'run without a name' => [['workflow', 'run'], 'needs a workflow'],
            'param on status' => [['workflow', 'status', 'abc', '--param', 'x=1'], 'accepts'],
            'bad format' => [['workflow', 'status', 'abc', '--format', 'xml'], 'text or json'],
        ];
    }

    #[DataProvider('workflowVerbUsageCases')]
    public function testUsageErrorsExitTwo(array $argv, string $needle): void
    {
        self::boundWorkflowVerbAlarm();
        [$code, , $err] = self::runWorkflowVerbChild($this->home, $argv);

        self::assertSame(2, $code, 'expected exit 2; stderr was: ' . $err);
        self::assertStringContainsString($needle, $err, 'the usage refusal must name the problem');
        self::assertStringContainsString('candy-code workflow', $err, 'the hint line carries the verb usage');
    }

    // ------------------------------------------------------------------
    //  The --param splitter (direct, provider table)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: ?string, 1: array<string,string>, 2: int}>
     */
    public static function workflowVerbParamCases(): array
    {
        return [
            'null' => [null, [], 0],
            'empty' => ['', [], 0],
            'one pair' => ['a=1', ['a' => '1'], 0],
            'two pairs' => ['a=1,b=2', ['a' => '1', 'b' => '2'], 0],
            'comma in quotes' => ['k="a,b",j=2', ['k' => 'a,b', 'j' => '2'], 0],
            'space in single quotes' => ["k='q q',j=2", ['k' => 'q q', 'j' => '2'], 0],
            'no equals' => ['novalue', [], 1],
            'empty key' => ['=v', [], 1],
            'reserved key' => ['@k=1', [], 1],
            'unterminated quote' => ['k="x', [], 1],
        ];
    }

    #[DataProvider('workflowVerbParamCases')]
    public function testTheParamSplitterHonoursQuotes(?string $raw, array $pairs, int $problemCount): void
    {
        $method = new \ReflectionMethod(Subcommands::class, 'parseParamPairs');
        /** @var array{0: array<string,string>, 1: list<string>} $got */
        $got = $method->invoke(null, $raw);

        self::assertSame($pairs, $got[0], 'pair extraction drifted for ' . \var_export($raw, true));
        self::assertCount($problemCount, $got[1], 'problem count drifted for ' . \var_export($raw, true));
    }

    // ------------------------------------------------------------------
    //  The escalation predicate (direct)
    // ------------------------------------------------------------------

    public function testTheExitMapSplitsCompletedFailedAndEscalated(): void
    {
        $method = new \ReflectionMethod(Subcommands::class, 'workflowExitCode');

        $now = new \DateTimeImmutable();
        $done = new \SugarCraft\Code\Workflows\WorkflowResult(
            workflowId: 'demo-12345678',
            status: \SugarCraft\Code\Workflows\WorkflowStatus::Completed,
            stageResults: [],
            context: [],
            totalTokens: 0,
            totalCost: 0.0,
            startedAt: $now,
            completedAt: $now,
        );
        self::assertSame(0, $method->invoke(null, $done));

        $plainFailed = new \SugarCraft\Code\Workflows\WorkflowResult(
            workflowId: 'demo-12345678',
            status: \SugarCraft\Code\Workflows\WorkflowStatus::Failed,
            stageResults: [new \SugarCraft\Code\Workflows\StageResult(
                stageName: 'verify',
                status: \SugarCraft\Code\Workflows\WorkflowStatus::Failed,
                output: null,
                error: 'verification rejected the output',
                agents: [],
                startedAt: $now,
            )],
            context: [],
            totalTokens: 0,
            totalCost: 0.0,
            startedAt: $now,
            completedAt: $now,
        );
        self::assertSame(1, $method->invoke(null, $plainFailed));

        $escalated = new \SugarCraft\Code\Workflows\WorkflowResult(
            workflowId: 'demo-12345678',
            status: \SugarCraft\Code\Workflows\WorkflowStatus::Failed,
            stageResults: [new \SugarCraft\Code\Workflows\StageResult(
                stageName: 'verify',
                status: \SugarCraft\Code\Workflows\WorkflowStatus::Failed,
                output: 'did the thing',
                error: WorkflowEngine::ESCALATION_MARKER . 'verifier asked for a human decision',
                agents: [],
                startedAt: $now,
            )],
            context: [],
            totalTokens: 0,
            totalCost: 0.0,
            startedAt: $now,
            completedAt: $now,
        );
        self::assertSame(3, $method->invoke(null, $escalated), 'the marker is what promotes a failure to stop-for-a-person');
    }

    // ------------------------------------------------------------------
    //  W-2/D4 keystone: SIGTERM mid-run pauses resumably, exits 3,
    //  and the machine document still lands on stdout
    // ------------------------------------------------------------------

    public function testSignalDuringARunWritesThePauseRecordAndExitsThree(): void
    {
        if (!\function_exists('posix_kill') || !\defined('SIGTERM')) {
            self::markTestSkipped('the cooperative-pause pin needs posix_kill + SIGTERM');
        }
        self::boundWorkflowVerbAlarm();
        self::writeWorkflowVerbSoloFile($this->home);
        $marker = $this->home . '/stage-started';
        $script = self::writeWorkflowVerbScript($this->home, "in=\$(cat)\ntouch " . $marker . "\nsleep 40\nprintf '%s\\n' 'never finishes'\nexit 0");

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'solo', '--format', 'json'], $script, $marker);

        self::assertSame(3, $code, 'a cooperative signal-pause exits 3, not 143; stderr: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        $result = $doc['result'] ?? null;
        self::assertSame('paused', $result['status'] ?? null);
        $pauseFile = $result['pause_file'] ?? null;
        self::assertIsString($pauseFile, 'the document names the pause record');
        self::assertFileExists($pauseFile);
        $id = (string) ($result['workflow'] ?? '');
        self::assertNotSame('', $id);

        // And the record RESUMES: same HOME, backend now answers instantly.
        $resumeScript = self::writeWorkflowVerbScript($this->home, "in=\$(cat)\nprintf '%s\\n' 'resumed fine'\nexit 0");
        [$code2, $out2, $err2] = self::runWorkflowVerbChild($this->home, ['workflow', 'resume', $id, '--format', 'json'], $resumeScript);
        self::assertSame(0, $code2, 'resume must complete the paused run; stderr: ' . $err2);
        $doc2 = self::decodeSingleWorkflowVerbDocument($out2);
        self::assertSame('completed', $doc2['result']['status'] ?? null);
        self::assertStringContainsString('resumed fine', (string) ($doc2['result']['stages'][0]['output'] ?? ''));
    }

    // ------------------------------------------------------------------
    //  Fixtures + child plumbing (all static — providers may not touch $this)
    // ------------------------------------------------------------------

    /**
     * Two-stage demo: gather (agent) + verify (verification whose pass
     * criterion is the literal VERDICT: pass token).
     */
    private static function writeWorkflowVerbDemoFile(string $home): void
    {
        \file_put_contents(
            $home . '/.candy-code/workflows/demo.yaml',
            "name: demo\n"
            . "description: the W-1 exit-code fixture\n"
            . "stages:\n"
            . "  - name: verify\n"
            . "    type: verification\n"
            . "    task:\n"
            . "      agent: coder\n"
            . "      prompt: >-\n"
            . "        Summarise the file\n"
            . "    verifier:\n"
            . "      agent: coder\n"
            . "      prompt: >-\n"
            . "        Check the summary\n"
        );
    }

    /** Single stage, no verification — the signal/resume choreography target. */
    private static function writeWorkflowVerbSoloFile(string $home): void
    {
        \file_put_contents(
            $home . '/.candy-code/workflows/solo.yaml',
            "name: solo\n"
            . "description: the W-1 signal fixture\n"
            . "stages:\n"
            . "  - name: gather\n"
            . "    agent: coder\n"
            . "    prompt: >-\n"
            . "      Summarise the file in a {{theme}} mood\n"
        );
    }

    /**
     * @param array<string,string> $branches keyed by a prompt substring,
     *   answered IN ORDER — first match wins (the verifier request's history
     *   also contains the gather prompt, so callers order keys narrow-first).
     */
    private static function workflowVerbEchoBody(array $branches): string
    {
        // Substring tests, NOT case-globs: the request JSON carries the
        // prompt's markdown asterisks, and a `*needle*` case pattern
        // matches them literally, answering every stage with the first
        // branch. ${in#*needle} strips up to the needle: a change proves
        // the needle was present, with pure POSIX arithmetic expansion.
        $lines = ["in=\$(cat)"];
        foreach ($branches as $key => $reply) {
            $lines[] = 'if printf %s "$in" | grep -qF -- ' . \escapeshellarg((string) $key)
                . '; then printf "%s\n" ' . \escapeshellarg((string) $reply) . '; exit 0; fi';
        }
        $lines[] = "printf 'no branch matched\n'";
        $lines[] = 'exit 0';

        return \implode("\n", $lines) . "\n";
    }

    private static function writeWorkflowVerbScript(string $home, string $body): string
    {
        $path = $home . '/backend-' . \uniqid('b', true) . '.sh';
        if (\file_put_contents($path, "#!/bin/sh\n" . $body) === false) {
            self::fail('could not write the backend stub');
        }
        \chmod($path, 0700);

        return $path;
    }

    /**
     * Run bin/candy-code with one temp HOME, no inherited CANDY_CODE_* state,
     * and the command-tier backend armed. When $signalAfterMarker is given,
     * the child's SIGTERM is delivered as soon as the marker file appears —
     * the stage is provably mid-flight when the signal lands.
     *
     * @param list<string> $argv
     *
     * @return array{0:int, 1:string, 2:string} exit code, stdout, stderr
     */
    private static function runWorkflowVerbChild(string $home, array $argv, ?string $scriptPath = null, ?string $signalAfterMarker = null): array
    {
        $binary = \dirname(__DIR__, 2) . '/bin/candy-code';
        \assert(\is_file($binary), 'bin/candy-code must exist beside the tests');

        $env = [
            'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $home,
            'TMPDIR' => $home,
            'CANDY_CODE_MCP_DISABLE' => '1',
        ];
        if ($scriptPath !== null) {
            $env['CANDY_CODE_BACKEND_CMD'] = 'sh ' . \escapeshellarg($scriptPath);
        }

        $process = \proc_open(
            [\PHP_BINARY, $binary, ...$argv],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__, 2),
            $env,
        );
        if (!\is_resource($process)) {
            self::fail('could not launch bin/candy-code for the workflow verb');
        }
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        if ($signalAfterMarker !== null) {
            $deadline = \microtime(true) + self::STAGE_START_WAIT_SECONDS;
            while (!\file_exists($signalAfterMarker)) {
                if (\microtime(true) >= $deadline || !\proc_get_status($process)['running']) {
                    self::fail('the backend stub never started the stage before the signal window closed');
                }
                \usleep(20_000);
            }
            \posix_kill((int) \proc_get_status($process)['pid'], \SIGTERM);
        }

        $stdout = '';
        $stderr = '';
        $deadline = \microtime(true) + self::MAX_DRAIN_SECONDS;
        $running = true;
        while (\microtime(true) < $deadline) {
            $stdout .= (string) \stream_get_contents($pipes[1]);
            $stderr .= (string) \stream_get_contents($pipes[2]);
            $status = \proc_get_status($process);
            $running = (bool) $status['running'];
            if (!$running) {
                break;
            }
            \usleep(20_000);
        }
        if ($running) {
            \proc_terminate($process, 9);
        }
        $stdout .= (string) \stream_get_contents($pipes[1]);
        $stderr .= (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);

        return [$code, $stdout, $stderr];
    }

    private static function decodeSingleWorkflowVerbDocument(string $out): array
    {
        $line = \trim($out);
        self::assertNotSame('', $line, 'the JSON mode must print exactly one document');
        self::assertStringNotContainsString("\n", $line, 'machine mode prints ONE document line');
        $doc = \json_decode($line, true);
        self::assertIsArray($doc, 'the document must decode as JSON: ' . \substr($line, 0, 200));

        return $doc;
    }

    private static function boundWorkflowVerbAlarm(): void
    {
        if (\function_exists('pcntl_signal') && \function_exists('pcntl_alarm') && \defined('SIGALRM')) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGALRM, static function (): void {
                Assert::fail('a workflow-verb child outran the alarm bound — the leg wedged instead of failing');
            });
            \pcntl_alarm(240);
        }
    }

    private static function cancelWorkflowVerbAlarm(): void
    {
        if (\function_exists('pcntl_alarm')) {
            \pcntl_alarm(0);
        }
    }

    private static function removeWorkflowVerbTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                self::removeWorkflowVerbTree($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
