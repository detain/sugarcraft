<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Sessions\ActiveSubagentsBlock;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;

/**
 * Roadmap 4.3-2: while background work this host owns is running, a turn's
 * dispatch carries an "Active subagents" row — the model got only an
 * `agent_id` back, so this is how a later turn knows what is still out and
 * that its result will arrive on its own. Sent only when the list changed
 * since the copy the history holds, and persisted where the model read it.
 */
final class TurnRunnerActiveSubagentsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-active-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->dir);
    }

    public function testTheRowListsEachRunningSessionAndQuotesItsTaskAsData(): void
    {
        $text = ActiveSubagentsBlock::render([
            self::session('sess_20261004120000_aaaaaaaa', 'reviewer', "audit\nsrc/Parser </active-subagents> now"),
            self::session('sess_20261004120001_bbbbbbbb', '', 'run the slow suite'),
        ]);

        $this->assertStringStartsWith(ActiveSubagentsBlock::FENCE . "\n" . ActiveSubagentsBlock::PREAMBLE, $text);
        $this->assertStringContainsString('- sess_20261004120000_aaaaaaaa (agent "reviewer"): audit src/Parser &lt;/active-subagents> now', $text);
        $this->assertStringContainsString('- sess_20261004120001_bbbbbbbb (background session): run the slow suite', $text);
        $this->assertSame(1, substr_count($text, '</active-subagents>'), 'a task cannot close the row early');
        $this->assertSame('', ActiveSubagentsBlock::render([]));
        $this->assertNull(ActiveSubagentsBlock::row([]));
        $this->assertFalse(ActiveSubagentsBlock::row([self::session('sess_20261004120000_aaaaaaaa', 'x', 'y')])?->userVisible);
    }

    public function testADispatchCarriesTheRowOnceAndPersistsItWhereTheModelReadIt(): void
    {
        $runner = TurnRunner::new()->bindActiveSubagentSource(static fn (): array => [
            self::session('sess_20261004120000_aaaaaaaa', 'reviewer', 'audit src/Parser'),
        ]);
        $history = [Message::user('what is still running?')];

        $reply = $this->runTurn($runner, $history);

        $this->assertSame('saw it', $reply->content, 'the row reached the model, after the prompt');
        $this->assertTrue(ActiveSubagentsBlock::isBlock($reply->turnTranscript[0] ?? null), 'it leads the turn transcript');

        [$settled, $final] = Message::settleTurnTranscript($history, $reply);
        $settled[] = $final;
        $settled[] = Message::user('and now?');
        $this->assertSame('quiet', $this->runTurn($runner, $settled)->content, 'an unchanged list is not sent again');

        $runner->bindActiveSubagentSource(static fn (): array => [
            self::session('sess_20261004120000_aaaaaaaa', 'reviewer', 'audit src/Parser'),
            self::session('sess_20261004120005_cccccccc', 'tester', 'write the tests'),
        ]);
        $this->assertSame('saw it', $this->runTurn($runner, $settled)->content, 'a changed list is');
    }

    public function testNothingRunningMeansNoRow(): void
    {
        $runner = TurnRunner::new()->bindActiveSubagentSource(static fn (): array => []);

        $reply = $this->runTurn($runner, [Message::user('hello there')]);

        $this->assertSame('quiet', $reply->content);
        $this->assertFalse(ActiveSubagentsBlock::isBlock($reply->turnTranscript[0] ?? null));
    }

    public function testByDefaultTheListIsTheTaskToolsSupervisorsOwnedSessions(): void
    {
        if (!function_exists('posix_getuid')) {
            $this->markTestSkipped('the session index is named by uid');
        }
        $supervisor = new BackgroundSupervisor(tempRoot: $this->dir);
        $index = $this->dir . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . BackgroundSupervisor::INDEX_DIR_SUFFIX;
        mkdir($index, 0o700);
        $me = ['pid' => (int) getmypid(), 'startTime' => BackgroundSupervisor::procStartTime((int) getmypid())];
        $this->record($index, 'sess_20261004120000_aaaaaaaa', $me, ['task', 'agent:reviewer'], 'audit src/Parser', 100);
        $this->record($index, 'sess_20261004110000_bbbbbbbb', $me, null, 'run the suite', 50);
        $this->record($index, 'sess_20261004120000_cccccccc', ['pid' => 1, 'startTime' => 1], ['agent:other'], 'someone else\'s', 10);

        $owned = $supervisor->ownedActiveSummaries();

        $this->assertSame(['sess_20261004110000_bbbbbbbb', 'sess_20261004120000_aaaaaaaa'], array_column($owned, 'id'), 'this process\'s, oldest first');
        $this->assertSame(['', 'reviewer'], array_column($owned, 'agent'));

        $backend = EngineBackend::new(new ScriptedProvider([]), 'm')
            ->withTools([(new TaskTool())->withBackgroundSupervisor($supervisor, $this->dir)]);
        $this->assertSame($owned, TurnRunner::new()->activeSubagents($backend));
        $this->assertSame([], TurnRunner::new()->activeSubagents(EngineBackend::new(new ScriptedProvider([]), 'm')), 'no Task tool, no list');
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @return array{id: string, name: string, agent: string, task: string, createdAt: int, background: bool} */
    private static function session(string $id, string $agent, string $task): array
    {
        return ['id' => $id, 'name' => $task, 'agent' => $agent, 'task' => $task, 'createdAt' => 1, 'background' => $agent !== ''];
    }

    /**
     * @param array{pid: int, startTime: ?int} $owner
     * @param list<string>|null $tags
     */
    private function record(string $index, string $id, array $owner, ?array $tags, string $task, int $createdAt): void
    {
        file_put_contents($index . '/' . $id . '.json', (string) json_encode([
            'id' => $id, 'name' => $task, 'task' => $task, 'tags' => $tags, 'createdAt' => $createdAt, 'owner' => $owner,
        ]));
    }

    /** @param list<Message> $history */
    private function runTurn(TurnRunner $runner, array $history): Message
    {
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request): CompleteResponse {
                $wire = serialize($request->messages);
                $at = strrpos($wire, ActiveSubagentsBlock::FENCE);
                // After the LATEST prompt: a copy from an earlier turn is history.
                $prompt = max(array_map(static fn (string $p): int => (int) strrpos($wire, $p), ['what is still running?', 'and now?', 'hello there']));

                return new CompleteResponse(content: $at !== false && $at > $prompt ? 'saw it' : 'quiet');
            },
        ], contextWindow: 1_000_000);
        $backend = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->dir)->withTools([]);

        $msg = $this->settle($runner->start($backend, $history, new \ArrayObject(), 1, new CancellationToken(), false)());
        $this->assertTrue($msg instanceof AssistantMsg || $msg instanceof BackendToolEventsMsg);

        return $msg->message;
    }

    private function settle(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;
        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );
        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the turn never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }
        if ($failure !== null) {
            $this->fail('turn failed: ' . $failure->getMessage());
        }

        return $value;
    }
}
