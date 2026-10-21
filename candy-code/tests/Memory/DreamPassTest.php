<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Memory;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\SessionPromptMemo;
use SugarCraft\Code\App\DreamPassCompletedMsg;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\CompactionJournal;
use SugarCraft\Code\Memory\DreamMemoryView;
use SugarCraft\Code\Memory\DreamPass;
use SugarCraft\Code\Memory\MemoryHistory;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Memory\MemoryWriter;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\BuiltIn\MemoryTool;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\BuiltIn\Write;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Usage;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Roadmap 5.4-3: the dream pass reads the compaction journal in a
 * restricted-tool engine turn and applies its answer through auto-memory's
 * rules in the parent, versioned and throttled, advancing the journal cursor
 * only when the turn completed.
 */
final class DreamPassTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $root = '';
    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-dream-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/user-home');
        $this->home = $this->sandbox . '/memory';
        $this->root = $this->sandbox . '/root';
        mkdir($this->home, 0700, true);
        mkdir($this->root, 0700, true);
        $this->store = new MemoryStore($this->home);
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
    }

    protected function tearDown(): void
    {
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testNothingIsScheduledWithoutAnEngineAJournalOrWhenSwitchedOff(): void
    {
        $engine = $this->engine([new CompleteResponse(content: '{"operations":[]}')]);

        self::assertNull($this->pass()->call($engine, false, 's'), 'no journal, nothing to dream about');

        $this->journal('c1', 'The API moved under /v2.');
        self::assertNull($this->pass()->call($this->plainBackend(), false, 's'), 'a tool-less backend cannot run the pass');
        self::assertNull($this->pass()->call(null, false, 's'));
        self::assertNull($this->pass()->call($engine, true, 's'), 'never once the spend cap is reached');

        putenv(AutoMemoryConsolidator::ENV_DISABLE . '=1');
        self::assertNull($this->pass()->call($engine, false, 's'), 'auto-memory\'s switch turns the dream off too');
        putenv(AutoMemoryConsolidator::ENV_DISABLE);

        self::assertNull(DreamPass::new(MemoryWriter::new(static fn(): ?MemoryStore => null, ''))->call($engine, false, 's'));
        self::assertInstanceOf(\Closure::class, $this->pass()->call($engine, false, 's'));
    }

    public function testSchedulingClaimsTheThrottleForTheInterval(): void
    {
        $this->journal('c1', 'The API moved under /v2.');
        $engine = $this->engine([new CompleteResponse(content: '{"operations":[]}')]);
        $now = 1_000_000;
        $pass = $this->pass()->withClock(static function () use (&$now): int {
            return $now;
        });

        self::assertNotNull($pass->call($engine, false, 's'));
        self::assertNull($pass->call($engine, false, 's'), 'claimed: no second pass inside the interval');
        $now += DreamPass::INTERVAL_SECONDS - 1;
        self::assertNull($pass->call($engine, false, 's'));
        $now += 1;
        self::assertNotNull($pass->call($engine, false, 's'), 'the interval is up');
        self::assertFileExists(DreamPass::statePath($this->store));
    }

    public function testTheTurnRunsOnTheReadOnlyToolsAndTheAnswerIsAppliedTaggedAndRecorded(): void
    {
        $this->journal('c1', 'Decided: the public API is versioned under /v2; v1 is removed. token sk-ant-abcdefghijklmnopqrstuvwxyz0123456789');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Memory', ['action' => 'view'])]),
            new CompleteResponse(
                content: '{"operations":[{"op":"add","scope":"project","type":"decision","content":"The public API is versioned under /v2; v1 was removed.","tags":["api"]}]}',
                usage: Usage::new(40, 0.004),
            ),
        ]);
        $session = EngineBackend::new($provider, 'm')
            ->withTools([new Read($this->root), new Write($this->root), new Bash($this->root), new MemoryTool(MemoryWriter::new($this->store, ''))])
            ->withRoot($this->root);

        $msg = $this->settled($this->pass()->call($session, false, 'sess-1'));

        self::assertInstanceOf(DreamPassCompletedMsg::class, $msg);
        self::assertSame(['Memory', 'Read'], self::toolNames($provider->requests[0]), 'read-only tools only, the session\'s Read kept');
        self::assertSame(['Read', 'Write', 'Bash', 'Memory'], array_map(static fn(Tool $t): string => $t->name(), $session->tools()), 'the session backend is untouched');

        $prompt = self::lastUserText($provider->requests[0]);
        self::assertStringContainsString('dream pass', $prompt);
        self::assertStringContainsString('versioned under /v2', $prompt);
        self::assertStringNotContainsString('sk-ant-abcdefghijklmnopqrstuvwxyz0123456789', $prompt, 'the journal is redacted again');

        self::assertTrue($msg->advanced);
        self::assertSame(1, $msg->entries);
        self::assertCount(1, $msg->saved);
        self::assertSame(0.004, $msg->usage?->costUsd);
        $note = $this->store->get($msg->saved[0]);
        self::assertNotNull($note);
        self::assertContains(AutoMemoryConsolidator::TAG, $note->tags());
        self::assertContains(DreamPass::TAG, $note->tags());
        self::assertStringStartsWith('Dream pass saved 1 note from 1 compaction journal entry', (string) DreamPass::notice($msg));

        if (MemoryHistory::available()) {
            self::assertNotNull($msg->commit);
            $subjects = array_map(static fn($r): string => $r->subject, MemoryHistory::new($this->home)->log());
            self::assertStringStartsWith(DreamPass::COMMIT_SUBJECT . ' — saved 1, updated 0, removed 0 from 1 journal entry', $subjects[0]);
            $this->journal('c2', 'A later summary.');
            self::assertNull(MemoryHistory::new($this->home)->commit('memory: probe'), 'the journal and the cursor are not versioned');
        }
    }

    public function testACompletedPassMovesTheCursorAndOnlyNewEntriesAreShownNext(): void
    {
        $this->journal('c1', 'First summary.');
        $provider = new ScriptedProvider([new CompleteResponse(content: '{"operations":[]}')]);
        $engine = EngineBackend::new($provider, 'm')->withRoot($this->root);
        $pass = $this->pass()->withInterval(0);

        self::assertTrue($this->settled($pass->call($engine, false, 's'))->advanced);
        $state = json_decode((string) file_get_contents(DreamPass::statePath($this->store)), true);
        self::assertSame(0, $state['offset'] ?? null, 'a pass that folded everything restarts the byte cursor at the top of the tail');
        self::assertArrayNotHasKey('cursor', $state, 'the count cursor is retired (ISS-071)');
        self::assertArrayNotHasKey('size', $state);
        self::assertSame([], CompactionJournal::forStore($this->store)->entries(), 'the folded prefix leaves the journal');
        self::assertNull($pass->call($engine, false, 's'), 'an unchanged journal is not dreamed over again');

        $this->journal('c2', 'Second summary.');
        $this->settled($pass->call($engine, false, 's'));
        $prompt = self::lastUserText($provider->requests[1]);
        self::assertStringContainsString('Second summary.', $prompt);
        self::assertStringNotContainsString('First summary.', $prompt);
    }

    public function testPreOffsetStateFilesBecomeByteCursorsByIdentity(): void
    {
        $this->journal('c1', 'First summary.');
        $this->journal('c2', 'Second summary.');
        $journal = CompactionJournal::forStore($this->store);
        $scan = $journal->scanFrom();
        $first = $journal->entries()[0];
        $identity = $first['at'] . '|' . $first['compaction'];
        $pass = DreamPass::new(MemoryWriter::new($this->store, $this->root));

        [$taken, $start, $end] = $pass->pending($journal, ['cursor' => 1, 'last' => $identity]);
        self::assertSame(['c2'], array_column($taken, 'compaction'), 'the legacy count converts at the entry it names');
        self::assertSame($scan['ends'][0], $start);
        self::assertSame($scan['size'], $end);

        [$taken] = $pass->pending($journal, ['cursor' => 1]);
        self::assertSame(['c2'], array_column($taken, 'compaction'), 'a count alone still converts against the scan');

        [$taken] = $pass->pending($journal, ['cursor' => 1, 'last' => 'gone|never-was']);
        self::assertSame(['c1', 'c2'], array_column($taken, 'compaction'), 'an identity that matches nothing re-reads, it never skips forward');

        [$taken] = $pass->pending($journal, ['offset' => $scan['ends'][0]]);
        self::assertSame(['c2'], array_column($taken, 'compaction'), 'an aligned byte cursor reads from the byte');

        [$taken, $start] = $pass->pending($journal, ['offset' => 5, 'last' => $identity]);
        self::assertSame(['c2'], array_column($taken, 'compaction'), 'a cursor knocked off its line by a hand edit re-synchronises by identity');
        self::assertSame($scan['ends'][0], $start);
    }

    public function testAnUnansweredOrTruncatedPassLeavesTheCursorForNextTime(): void    {
        $this->journal('c1', 'Only summary.');
        $provider = new ScriptedProvider([new CompleteResponse(content: 'I looked around and memory seems fine.')]);
        $engine = EngineBackend::new($provider, 'm')->withRoot($this->root);
        $pass = $this->pass()->withInterval(0);

        $msg = $this->settled($pass->call($engine, false, 's'));
        self::assertFalse($msg->advanced, 'no JSON is not a completed pass');
        self::assertNull(DreamPass::notice($msg));

        $again = $pass->call($engine, false, 's');
        self::assertNotNull($again, 'the same entries are offered again');
        $this->settled($again);
        self::assertStringContainsString('Only summary.', self::lastUserText($provider->requests[1]));

        [$entries, $start, $end] = $pass->pending(CompactionJournal::forStore($this->store), []);
        $cut = $pass->settle(Message::assistant('{"operations":[]}')->withStepsTruncated(true), $this->store, $entries, $end, 's');
        self::assertFalse($cut->advanced, 'a turn the step ceiling ended is not complete');
    }

    public function testAFailedTurnReportsTheErrorAndMovesNothing(): void
    {
        $this->journal('c1', 'Only summary.');
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm');
        $pass = $this->pass()->withInterval(0)->withRunner(static fn(): PromiseInterface => reject(new \RuntimeException('provider down')));

        $msg = $this->settled($pass->call($engine, false, 's'));

        self::assertSame('provider down', $msg->error);
        self::assertFalse($msg->advanced);
        self::assertNotNull($pass->call($engine, false, 's'), 'retried once the interval allows');
    }

    public function testThePassMayChangeOnlyAutoMemoryNotes(): void
    {
        $mine = $this->store->add('Deploys go through the staging box first.', 'project');
        $auto = $this->store->add('An old auto note.', 'project', [AutoMemoryConsolidator::TAG]);
        $this->journal('c1', 'Some summary.');
        $reply = '{"operations":[{"op":"delete","id":"' . $mine . '"},{"op":"update","id":"' . $auto . '","content":"A refreshed auto note."}]}';
        $engine = EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: $reply)]), 'm')->withRoot($this->root);

        $msg = $this->settled($this->pass()->call($engine, false, 's'));

        self::assertSame([$auto], $msg->updated);
        self::assertSame([], $msg->deleted);
        self::assertSame(['not_auto' => 1], $msg->skipped);
        self::assertNotNull($this->store->get($mine), 'a note the user wrote is never removed by the dream');
        self::assertStringContainsString('refreshed', (string) $this->store->get($auto)?->content());
    }

    public function testTheMemoryViewOnlyReads(): void
    {
        $id = $this->store->add('A note.', 'user');
        $view = new DreamMemoryView(MemoryWriter::new($this->store, ''));

        self::assertSame('Memory', $view->name());
        self::assertStringContainsString('A note.', $view->execute(['action' => 'view', 'id' => $id])->content());
        $save = $view->execute(['action' => 'save', 'content' => 'sneaky']);
        self::assertTrue($save->isError());
        self::assertCount(1, $this->store->list('user'), 'nothing was saved');
    }

    public function testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged(): void
    {
        $this->journal('c1', 'The build uses make release.');
        $reply = '{"operations":[{"op":"add","content":"Releases are built with make release."}]}';
        $engine = EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: $reply, usage: Usage::new(10, 0.003))]), 'm')->withRoot($this->root);
        $chat = new Chat(backend: $engine, history: [Message::user('hi')], inFlight: true, memoryStore: $this->store, projectRoot: $this->root);

        [$settled, $cmd] = $chat->update(new AssistantMsg(Message::assistant('hello')));

        // Not invoked: the factory forks the engine's turn child. The claimed
        // throttle is the proof the settle went through DreamPass::call().
        self::assertInstanceOf(\Closure::class, $cmd, 'the dream is the one Cmd this settle schedules');
        self::assertFileExists(DreamPass::statePath($this->store));

        mkdir($this->sandbox . '/other', 0700);
        $other = new MemoryStore($this->sandbox . '/other');
        CompactionJournal::forStore($other)->append('c1', ['r1' => 'A summary.'], ['manual']);
        $echo = new Chat(history: [Message::user('hi')], inFlight: true, memoryStore: $other, projectRoot: $this->root);
        [, $none] = $echo->update(new AssistantMsg(Message::assistant('hello')));
        self::assertNull($none, 'a backend that is not an engine schedules no pass');
        self::assertFileDoesNotExist(DreamPass::statePath($other));

        $pass = DreamPass::new(MemoryWriter::new($this->store, $this->root))->withInterval(0);
        [$entries, $start, $end] = $pass->pending(CompactionJournal::forStore($this->store), []);
        $landed = $pass->settle(Message::assistant($reply)->withUsage(Usage::new(10, 0.003)), $this->store, $entries, $end, 's');

        [$shown, $none] = $settled->update($landed);
        self::assertNull($none);
        $last = $shown->history[\count($shown->history) - 1];
        self::assertTrue($last->uiOnly, 'the notice is display-only');
        self::assertStringStartsWith('Dream pass saved 1 note', $last->content);
        self::assertEqualsWithDelta(0.003, $shown->spentUsd(), 1e-9);

        [$quiet] = $settled->update(new DreamPassCompletedMsg(usage: Usage::new(5, 0.001)));
        self::assertCount(\count($settled->history), $quiet->history, 'a pass that changed nothing says nothing');
    }

    public function testARestrictedDreamEngineHoldsItsOwnPromptMemo(): void
    {
        // ISS-021: every clone of a session backend shares its one prompt
        // memo, so the dream's fabricated history used to be observed against
        // the session — and a background turn's prime wrote into the session
        // bucket. restrict() now swaps in a memo of its own; ordinary clones
        // keep sharing the owner's.
        $backend = $this->engine([new CompleteResponse(content: 'z')])
            ->withSessionId('S1');

        self::assertSame(
            $backend->sessionPromptMemo(),
            $backend->withSessionId('S2')->sessionPromptMemo(),
            'an ordinary clone still shares the owner\'s memo — it must warm the session, not re-read it',
        );

        $restricted = DreamPass::new(MemoryWriter::new($this->store, ''))->restrict($backend);

        self::assertNotSame($backend->sessionPromptMemo(), $restricted->sessionPromptMemo(), 'the dream turn observes and primes a memo of its own');

        $session = $backend->sessionPromptMemo();
        $session->remember('S1', 'layers', static fn (): string => 'frozen');
        $session->observeTurn('S1', ['u:a']);

        $restricted->sessionPromptMemo()->observeTurn(null, ['u:dream-prompt']);
        $restricted->sessionPromptMemo()->remember('', 'dream-layer', static fn (): string => 'background');

        self::assertTrue($session->has('S1', 'layers'), 'the dream\'s history is no refresh point for the session');
        self::assertSame('frozen', $session->remember('S1', 'layers', static fn (): string => 'rebuilt'), 'and its layers were not replaced');
    }

    private function pass(): DreamPass
    {
        // In-process: the real restricted engine, run synchronously.
        return DreamPass::new(MemoryWriter::new($this->store, ''))
            ->withRunner(static fn(EngineBackend $backend, array $prompt): PromiseInterface => resolve($backend->complete($prompt)));
    }

    /** @param list<CompleteResponse> $script */
    private function engine(array $script): EngineBackend
    {
        return EngineBackend::new(new ScriptedProvider($script), 'm')->withRoot($this->root);
    }

    private function journal(string $id, string $record): void
    {
        self::assertTrue(CompactionJournal::forStore($this->store)->append($id, ['r1' => $record], ['manual']));
    }

    private function settled(?\Closure $factory): DreamPassCompletedMsg
    {
        self::assertNotNull($factory);
        $resolved = null;
        $factory()->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });
        self::assertInstanceOf(DreamPassCompletedMsg::class, $resolved);

        return $resolved;
    }

    /** @return list<string> */
    private static function toolNames(CompleteRequest $request): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name(), $request->tools ?? []);
    }

    private static function lastUserText(CompleteRequest $request): string
    {
        $text = '';
        foreach (\SugarCraft\Code\Context\TurnContextBlock::strip($request->messages) as $message) {
            if ($message->role() === 'user') {
                $text = $message->content();
            }
        }

        return $text;
    }

    private function plainBackend(): Backend
    {
        return new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('{}');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return resolve(Message::assistant('{}'));
            }
        };
    }
}
