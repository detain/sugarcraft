<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Sessions\StoppableDaemonFixtureTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;

/**
 * Roadmap 4.3-2: `Task(background: true)` — or an agent whose preset says
 * `background: true` — returns `{agent_id}` at once ("DO NOT sleep or poll")
 * and runs as a background session; the host adopts the session the turn's
 * forked child spawned on its next poll and settles it like any `/bg`.
 *
 * The daemons here run with `SUGARCRUSH_BACKEND_CMD` set, which hands the
 * daemon a command backend rather than an engine: a background agent needs the
 * engine, so the session settles Failed at once naming why — the whole
 * spawn → handoff → adopt → reap round trip, with no provider anywhere.
 */
final class TaskToolBackgroundTest extends TestCase
{
    use StoppableDaemonFixtureTrait;
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    /** Scratch temp root for the supervisors. SHORT: socket paths must fit 108 bytes. */
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'posix_getuid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }

        $this->root = sys_get_temp_dir() . '/tb' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700);
        $this->setUpDaemonFixture();
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf not-an-engine');
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        $this->tearDownDaemonFixture();
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testTheSchemaOffersBackground(): void
    {
        $schema = (new TaskTool())->inputSchema();

        $this->assertSame('boolean', $schema['properties']['background']['type'] ?? null);
        $this->assertStringContainsString('do NOT sleep or poll', $schema['properties']['background']['description']);
        $this->assertNotContains('background', $schema['required'], 'optional: the preset decides when the call does not');
        $this->assertStringContainsString('`background`', (new TaskTool())->description());
    }

    public function testABackgroundCallFromAForkedTurnReturnsItsIdAtOnceAndTheHostAdoptsIt(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);
        $task = $this->task($supervisor);
        $out = $this->fixtureHome . '/result.json';

        // The turn child: the call runs in a process forked from the host.
        $pid = $this->forkTracked();
        if ($pid === 0) {
            $result = $task->execute(['id' => 'call_1', 'description' => 'Audit the parser', 'prompt' => 'audit src/Parser', 'agent' => 'coder', 'background' => true]);
            file_put_contents($out, (string) json_encode(['error' => $result->isError(), 'content' => $result->content()]));
            \SugarCraft\Crush\Support\ForkedChild::exitNow();
        }
        pcntl_waitpid($pid, $status);

        $result = json_decode((string) file_get_contents($out), true);
        $this->assertFalse($result['error'], $result['content']);
        $this->assertMatchesRegularExpression('/\A\{"agent_id":"(sess_\d{14}_[0-9a-f]{8})","status":"running"\}\n\n/', $result['content']);
        $this->assertStringContainsString('DO NOT sleep or poll', $result['content']);
        preg_match('/"agent_id":"([^"]+)"/', $result['content'], $m);
        $id = $m[1];

        $record = json_decode((string) file_get_contents($this->indexDir() . '/' . $id . '.json'), true);
        $this->assertSame(getmypid(), $record['owner']['pid'], 'the session is the host\'s, not the turn child\'s');
        $this->assertTrue($record['handoff'], 'and marked for the host to adopt');
        $this->assertContains('agent:coder', $record['tags']);
        $this->assertSame('Audit the parser (@coder)', $record['name']);
        $this->trackDaemon($record);

        // The host's own supervisor — a different instance — adopts it on its
        // next poll; a second poll does not adopt it twice.
        $host = new BackgroundSupervisor(tempRoot: $this->root);
        $this->assertTrue($host->hasActiveSessions(), 'the adopted session arms the host\'s background poll');
        $this->assertSame(BackgroundSessionStatus::Running, $host->getSession($id)?->status);
        $this->assertSame([], $host->adoptHandedOff());
        $this->assertSame([], (new BackgroundSupervisor(tempRoot: $this->root))->adoptHandedOff(), 'adopted once');

        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && $host->getSession($id)?->isActive()) {
            $host->tick();
            usleep(100_000);
        }

        $settled = $host->getSession($id);
        $this->assertSame(BackgroundSessionStatus::Failed, $settled?->status);
        $this->assertStringContainsString('needs the engine backend', (string) $settled->error);
        $this->assertStringContainsString("[Background session {$id} ('Audit the parser (@coder)') failed]", $settled->announcement());
    }

    public function testAPresetThatSaysBackgroundRunsInTheBackgroundAndFalseOverridesIt(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);
        $task = $this->task($supervisor, presetBackground: true, script: [new CompleteResponse(content: 'ran in the foreground')]);

        $background = $task->execute(['id' => 'c1', 'description' => 'd', 'prompt' => 'p', 'agent' => 'coder']);
        $this->assertFalse($background->isError(), $background->content());
        $this->assertStringStartsWith('{"agent_id":"sess_', $background->content(), 'the preset backgrounds it');
        preg_match('/"agent_id":"([^"]+)"/', $background->content(), $m);
        $this->assertNotNull($supervisor->getSession($m[1]), 'spawned in-process, held by the spawning supervisor');
        $this->trackDaemon(json_decode((string) file_get_contents($this->indexDir() . '/' . $m[1] . '.json'), true));

        $foreground = $task->execute(['id' => 'c2', 'description' => 'd', 'prompt' => 'p', 'agent' => 'coder', 'background' => false]);
        $this->assertFalse($foreground->isError(), $foreground->content());
        $this->assertStringContainsString('ran in the foreground', $foreground->content(), '`background: false` wins over the preset');
    }

    public function testWithNoSupervisorABackgroundRequestRunsInTheForegroundAndSaysSo(): void
    {
        $task = $this->task(null, script: [new CompleteResponse(content: 'the report')]);

        $result = $task->execute(['id' => 'c1', 'description' => 'd', 'prompt' => 'p', 'agent' => 'coder', 'background' => true]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringStartsWith('[background was requested, but this launch cannot start background sessions', $result->content());
        $this->assertStringContainsString('the report', $result->content());
    }

    public function testAFailedSpawnIsRefusedNamingTheForegroundRoute(): void
    {
        // A temp root that is a FILE: no private IPC directory can go there.
        $blocked = $this->root . '/blocked';
        file_put_contents($blocked, '');
        $task = $this->task(new BackgroundSupervisor(tempRoot: $blocked));

        $result = $task->execute(['id' => 'c1', 'description' => 'd', 'prompt' => 'p', 'agent' => 'coder', 'background' => true]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('could not be started in the background', $result->content());
        $this->assertStringContainsString('omit `background`', $result->content());
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @param list<CompleteResponse> $script */
    private function task(?BackgroundSupervisor $supervisor, bool $presetBackground = false, array $script = []): TaskTool
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [], toolUniverse: []);
        // RosterAgent's, but with no provider name — the daemon then builds
        // the launch backend, which SUGARCRUSH_BACKEND_CMD makes a command
        // one — and the preset's `background` as given.
        $manager->register(new Agent(
            name: 'coder',
            description: 'test agent coder',
            prompt: 'You are coder.',
            model: 'test-model',
            provider: '',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
            background: $presetBackground,
            inheritsModel: true,
        ));

        $task = (new TaskTool($manager))
            ->withEngine(EngineBackend::new(new ScriptedProvider($script), 'm')->withoutHooks()->withTools([]))
            ->withTranscriptRoot($this->fixtureHome . '/subagents');

        return $supervisor === null ? $task : $task->withBackgroundSupervisor($supervisor, $this->fixtureHome);
    }

    /** @param array<string, mixed> $record */
    private function trackDaemon(array $record): void
    {
        $this->fixtureDaemons[] = ['pid' => (int) $record['pid'], 'startTime' => $record['startTime'] ?? null];
        foreach (['socketPath', 'bufferPath', 'tokenPath'] as $key) {
            $this->fixtureFiles[] = (string) $record[$key];
        }
        $this->fixtureFiles[] = $record['bufferPath'] . '.log';
    }

    private function indexDir(): string
    {
        return $this->root . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . BackgroundSupervisor::INDEX_DIR_SUFFIX;
    }
}
