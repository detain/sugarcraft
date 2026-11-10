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
 * Every leg spawns the shipped bin/candy-code with a temp HOME whose
 * .candy-code/config.json selects a `custom` provider pointed at a
 * php -S stub server answering the OpenAI wire in-process. The run
 * therefore executes the REAL engine — registry load, stage dispatch
 * through the forked pool, verification scanning, pause records — which
 * is what makes the exit-code pins load-bearing rather than tautological.
 *
 * Why not CANDY_CODE_BACKEND_CMD: the command tier deliberately carries
 * NO worker provider spec (E641 — LaunchContext::selectedProviderName()
 * answers null on that tier, so Bootstrap::workerProviderSpec() has
 * nothing to hand the forked stage workers, which then refuse
 * pre-dispatch rather than fabricate). The engine legs must therefore
 * ride a real provider-tier selection; the custom type dialing a local
 * 127.0.0.1 stub is the offline spelling that survives with no keys.
 *
 * The stub routes by a PORT-KEYED behaviour table: each leg registers its
 * mode in behavior.json beside the router before the server binds, and the
 * router answers every request of that port accordingly — 'escalate' and
 * 'reject' reply with the verdict texts, 'slow' sleeps mid-request so a
 * SIGTERM provably lands, and the default answers 'STUBGOT ' plus the
 * request's joined user content, which doubles as the --param round-trip
 * oracle. Request CONTENT is never scanned for markers: the CLI embeds a
 * live git-state <turn-context> block inside the user message, so any
 * marker vocabulary present in the repo (it would live in this very file)
 * rides EVERY request and a content scan answers every stage with the
 * first branch.
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

    /** Seconds to wait for the stub server to bind its port. */
    private const STUB_BIND_WAIT_SECONDS = 10.0;

    /** Seconds to wait for the slow stub request to finish being served. */
    private const STUB_SLOW_REQUEST_SECONDS = 60.0;

    private string $home;

    /** @var array{0: resource, 1: string}|null proc handle + log path of the live stub */
    private static ?array $stubServer = null;

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
        self::stopWorkflowVerbStub();
        self::removeWorkflowVerbTree($this->home);
    }

    // ------------------------------------------------------------------
    //  End-to-end legs
    // ------------------------------------------------------------------

    public function testCompletedRunEmitsTheMachineDocumentAndExitsZero(): void
    {
        self::boundWorkflowVerbAlarm();
        self::writeWorkflowVerbDemoFile($this->home);
        self::startWorkflowVerbStub($this->home);

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo', '--format', 'json']);

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
        self::startWorkflowVerbStub($this->home);

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo']);

        self::assertSame(0, $code, 'stderr was: ' . $err);
        self::assertStringContainsString('completed', $out);
        self::assertStringContainsString('verify', $out);
    }

    public function testEscalatedVerificationExitsThreeAndCarriesTheMarker(): void
    {
        self::boundWorkflowVerbAlarm();
        // The escalate mode answers the line-anchored escalation text on
        // every request of this stub port; the scan reads ONLY the
        // verifier's output, so the task call's duplicate answer is inert.
        self::writeWorkflowVerbDemoFile($this->home);
        self::startWorkflowVerbStub($this->home, 'escalate');

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo', '--format', 'json']);

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

    public function testRejectedVerificationExitsOneWithoutTheMarker(): void
    {
        self::boundWorkflowVerbAlarm();
        // The reject mode answers with text matching the verifier slot's own
        // reject PCRE — a rejection, which must exit 1 while the error line
        // carries NO 'escalated: ' marker. Paired with the escalation leg
        // above, this is the whole exit 1-vs-3 split.
        self::writeWorkflowVerbDemoFile($this->home, reject: true);
        self::startWorkflowVerbStub($this->home, 'reject');

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'demo', '--format', 'json']);

        self::assertSame(1, $code, 'a rejected verification must exit 1; stderr: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        $result = $doc['result'] ?? null;
        self::assertSame('failed', $result['status'] ?? null);
        $verifyRow = null;
        foreach ((array) ($result['stages'] ?? []) as $row) {
            if (($row['name'] ?? null) === 'verify') {
                $verifyRow = $row;
            }
        }
        self::assertIsArray($verifyRow);
        self::assertStringStartsNotWith(
            WorkflowEngine::ESCALATION_MARKER,
            (string) ($verifyRow['error'] ?? ''),
            'a plain rejection must not wear the escalation marker the exit-3 predicate keys on',
        );
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
        self::startWorkflowVerbStub($this->home);
        $needle = 'zzcjqx';

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'solo', '--param', 'theme=' . $needle, '--format', 'json']);

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
        // The refusal names the tool and the verb — but not always glued:
        // a flag-tier refusal reads 'candy-code: ... workflow status accepts',
        // so the two halves are asserted separately.
        self::assertStringContainsString('candy-code', $err, 'the refusal carries the tool name');
        self::assertStringContainsString('workflow', $err, 'the refusal carries the verb name');
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
        // The slow mode makes the stub sleep while serving the stage,
        // touching stage-started first — so the SIGTERM provably lands
        // mid-flight.
        self::writeWorkflowVerbSoloFile($this->home);
        self::startWorkflowVerbStub($this->home, 'slow');
        $marker = $this->home . '/stage-started';

        [$code, $out, $err] = self::runWorkflowVerbChild($this->home, ['workflow', 'run', 'solo', '--format', 'json'], $marker);

        self::assertSame(3, $code, 'a cooperative signal-pause exits 3, not 143; stderr: ' . $err);
        $doc = self::decodeSingleWorkflowVerbDocument($out);
        $result = $doc['result'] ?? null;
        self::assertSame('paused', $result['status'] ?? null);
        $pauseFile = $result['pause_file'] ?? null;
        self::assertIsString($pauseFile, 'the document names the pause record');
        self::assertFileExists($pauseFile);
        $id = (string) ($result['workflow'] ?? '');
        self::assertNotSame('', $id);

        // The sleeping request must finish being served BEFORE the resume is
        // dispatched: the stub server serves one request at a time, and the
        // orphaned first worker still holds it. The router then answers the
        // replayed stage instantly (done-marker path) — a resume that
        // provably replays the recorded context, not a fresh run.
        self::awaitWorkflowVerbStubFile($this->home . '/stage-finished');

        [$code2, $out2, $err2] = self::runWorkflowVerbChild($this->home, ['workflow', 'resume', $id, '--format', 'json']);
        self::assertSame(0, $code2, 'resume must complete the paused run; stderr: ' . $err2);
        $doc2 = self::decodeSingleWorkflowVerbDocument($out2);
        self::assertSame('completed', $doc2['result']['status'] ?? null);
        self::assertStringContainsString('resumed fine', (string) ($doc2['result']['stages'][0]['output'] ?? ''));
    }

    // ------------------------------------------------------------------
    //  Fixtures + child plumbing (all static — providers may not touch $this)
    // ------------------------------------------------------------------

    /**
     * One-stage demo: a verification stage whose task gathers and whose
     * verifier reads clean — the verdict texts come from the stub's port
     * mode, never from the prompts. With $reject the verifier slot carries
     * the live reject PCRE the reject mode's answer matches.
     */
    private static function writeWorkflowVerbDemoFile(string $home, bool $reject = false): void
    {
        $yaml = "name: demo\n"
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
            . "        Check the summary\n";
        if ($reject) {
            $yaml .= "      reject: '/WRONG/'\n";
        }
        \file_put_contents($home . '/.candy-code/workflows/demo.yaml', $yaml);
    }

    /** Single stage, no verification — the signal/resume and param choreography target. */
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
     * Point the temp HOME's user config at a provider-tier `custom` backend
     * on the stub port. The whole merged spec rides to forked stage workers
     * (Bootstrap::workerProviderSpec), which build the provider child-side —
     * supportsFunctionCalling stays false so no tools ride the wire and the
     * stub's plain-text answer IS the stage output.
     */
    private static function writeWorkflowVerbConfig(string $home, int $port): void
    {
        \file_put_contents(
            $home . '/.candy-code/config.json',
            (string) \json_encode([
                'provider' => 'wstub',
                'providers' => [
                    'wstub' => [
                        'type' => 'custom',
                        'name' => 'wstub',
                        'baseUrl' => 'http://127.0.0.1:' . $port . '/v1',
                        'model' => 'stub-model',
                        'supportsStreaming' => false,
                        'supportsFunctionCalling' => false,
                    ],
                ],
            ], \JSON_PRETTY_PRINT),
        );
    }

    /**
     * The stub's router: a PORT-KEYED behaviour table. The leg registers its
     * mode in behavior.json (beside the router, under the temp HOME) before
     * the server binds, and every request arriving on that port is answered
     * per mode — no request content is ever scanned. Content matching is
     * unsafe HERE because the CLI embeds a live git-state <turn-context>
     * block inside the user message: any marker vocabulary present in the
     * repo files (it would live in this very file) rides every request, and
     * the first branch would win forever. The default mode echoes every
     * user row joined — the --param round-trip oracle — and every answer rides
     * the OpenAI chat-completions wire shape CustomProvider::parseResponse
     * reads.
     */
    private static function writeWorkflowVerbRouter(string $home): string
    {
        $router = <<<'PHP'
<?php
// Workflow-verb test stub — served by php -S, single request at a time.
$home = __DIR__;
$raw = (string) file_get_contents('php://input');
$decoded = json_decode($raw, true);
$allUsers = '';
if (is_array($decoded) && isset($decoded['messages']) && is_array($decoded['messages'])) {
    foreach ($decoded['messages'] as $message) {
        if (is_array($message) && ($message['role'] ?? null) === 'user') {
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $joined = '';
                foreach ($content as $part) {
                    if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                        $joined .= $part['text'];
                    }
                }
                $content = $joined;
            }
            $allUsers .= ((string) $content) . "\n";
        }
    }
}
$behavior = json_decode((string) @file_get_contents($home . '/behavior.json'), true);
$mode = is_array($behavior) ? (string) ($behavior['mode'] ?? '') : '';
if ($mode === 'slow') {
    if (file_exists($home . '/stage-finished')) {
        $answer = 'resumed fine';
    } else {
        file_put_contents($home . '/stage-started', '1');
        sleep(12);
        touch($home . '/stage-finished');
        $answer = 'slow answer';
    }
} elseif ($mode === 'escalate') {
    $answer = 'ESCALATE: is it round?';
} elseif ($mode === 'reject') {
    $answer = 'WRONG: the tone is off';
} else {
    $answer = 'STUBGOT ' . $allUsers;
}
echo json_encode([
    'id' => 'chatcmpl-workflow-verb-stub',
    'object' => 'chat.completion',
    'created' => 1700000000,
    'model' => 'stub-model',
    'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => $answer],
        'finish_reason' => 'stop',
    ]],
    'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18],
]);
PHP;
        $path = $home . '/router.php';
        if (\file_put_contents($path, $router) === false) {
            self::fail('could not write the stub router');
        }

        return $path;
    }

    /**
     * Bind a throwaway listener to learn a free loopback port. The gap
     * between close and php -S binding it is not race-free — the probe
     * socket cannot be held, since holding it IS the bind — so the starter
     * below re-reserves and retries once when a server dies on arrival.
     */
    private static function reserveWorkflowVerbPort(): int
    {
        $probe = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            self::fail('no loopback port could be reserved for the stub: ' . $error);
        }
        $name = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);
        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);
        if ($port <= 0) {
            self::fail('could not read the reserved stub port from ' . $name);
        }

        return $port;
    }

    /**
     * Start the php -S stub for this temp HOME, registering the port-keyed
     * behaviour mode BEFORE the server binds, and wait until it answers. A
     * reserved-then-released port can be taken before php -S binds it, so a
     * dead-on-arrival server retries once on a fresh port instead of flaking.
     */
    private static function startWorkflowVerbStub(string $home, string $mode = ''): void
    {
        if (\file_put_contents($home . '/behavior.json', (string) \json_encode(['mode' => $mode])) === false) {
            self::fail('could not write the stub behaviour table');
        }
        $router = self::writeWorkflowVerbRouter($home);
        $logPath = $home . '/stub-server.log';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $port = self::reserveWorkflowVerbPort();
            self::writeWorkflowVerbConfig($home, $port);
            $process = \proc_open(
                [\PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
                $pipes,
                $home,
                ['PATH' => \getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home],
            );
            if (!\is_resource($process)) {
                self::fail('could not launch the php -S workflow-verb stub');
            }
            self::$stubServer = [$process, $logPath];

            $deadline = \microtime(true) + self::STUB_BIND_WAIT_SECONDS;
            while (\microtime(true) < $deadline) {
                $socket = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
                if ($socket !== false) {
                    \fclose($socket);

                    return;
                }
                if (!\proc_get_status($process)['running']) {
                    \proc_close($process);
                    self::$stubServer = null;
                    break;
                }
                \usleep(20_000);
            }
            if ($attempt === 1) {
                self::stopWorkflowVerbStub();
                self::fail('the stub server never bound its port within the watch window; log: ' . (string) @\file_get_contents($logPath));
            }
        }
    }

    private static function stopWorkflowVerbStub(): void
    {
        if (self::$stubServer === null) {
            return;
        }
        [$process] = self::$stubServer;
        self::$stubServer = null;
        if (\is_resource($process)) {
            \proc_terminate($process, 9);
            \proc_close($process);
        }
    }

    /**
     * Wait (bounded) for the stub's slow request to finish being served, so
     * the single-threaded server is free again for the resume dispatch.
     */
    private static function awaitWorkflowVerbStubFile(string $path): void
    {
        $deadline = \microtime(true) + self::STUB_SLOW_REQUEST_SECONDS;
        while (!\file_exists($path)) {
            if (\microtime(true) >= $deadline) {
                self::fail('the stub never finished serving the slow request within the bound');
            }
            \usleep(20_000);
        }
    }

    /**
     * Run bin/candy-code with one temp HOME and no inherited CANDY_CODE_*
     * state: the config-tier provider is the only backend the child can
     * see. When $signalAfterMarker is given, the child's SIGTERM is
     * delivered as soon as the marker file appears — the stage is provably
     * mid-flight when the signal lands.
     *
     * @param list<string> $argv
     *
     * @return array{0:int, 1:string, 2:string} exit code, stdout, stderr
     */
    private static function runWorkflowVerbChild(string $home, array $argv, ?string $signalAfterMarker = null): array
    {
        $binary = \dirname(__DIR__, 2) . '/bin/candy-code';
        \assert(\is_file($binary), 'bin/candy-code must exist beside the tests');

        $env = [
            'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $home,
            'TMPDIR' => $home,
            'CANDY_CODE_MCP_DISABLE' => '1',
        ];

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
