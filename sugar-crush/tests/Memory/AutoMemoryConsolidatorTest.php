<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Memory\AutoMemoryConsolidator;
use SugarCraft\Crush\Memory\ConsolidationOp;
use SugarCraft\Crush\Memory\ConsolidationPlan;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\MemoryConsolidatedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 5.2: auto-memory consolidation — gated and throttled at the turn's
 * end, a tool-less request built from the redacted conversation and the
 * existing notes, and JSON operations applied through MemoryWriter, touching
 * only the notes auto-memory wrote itself.
 */
final class AutoMemoryConsolidatorTest extends TestCase
{
    private string $dir;

    private MemoryStore $home;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/amc_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0700, true);
        $this->home = new MemoryStore($this->dir);
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
    }

    protected function tearDown(): void
    {
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->rmTree($this->dir);
    }

    public function testATurnWorthConsolidatingSchedulesARunThatSavesTaggedNotes(): void
    {
        $backend = $this->backend('{"operations":[{"op":"add","scope":"project","type":"decision","content":"Deploys go through bin/deploy, never kubectl.","tags":["deploy"]}],"skipped":[{"reason":"transient","detail":"CI status"}]}');

        $msg = $this->settle($this->consolidator()->call($backend, $this->conversation(), false, 's1'));

        self::assertInstanceOf(MemoryConsolidatedMsg::class, $msg);
        self::assertCount(1, $msg->saved);
        self::assertSame(['transient' => 1], $msg->skipped);
        self::assertSame('s1', $msg->sessionId);
        self::assertSame(42, $msg->usage?->totalTokens);

        $note = $this->home->get($msg->saved[0]);
        self::assertNotNull($note);
        self::assertSame(['decision', 'project'], [$note->type(), $note->scope()]);
        self::assertSame(['deploy', AutoMemoryConsolidator::TAG], $note->tags());
    }

    public function testTheRequestCarriesTheRulesTheNotesAndARedactedExcerpt(): void
    {
        $this->home->add('Uses PostgreSQL 16', 'project');
        $backend = $this->backend('{"operations":[]}');
        $history = $this->conversation('The token is ' . 'gh' . 'p_' . str_repeat('A1b2', 9) . ' for CI.');

        $this->settle($this->consolidator()->call($backend, $history, false, 's1'));

        [$system, $user] = $backend->seen;
        self::assertSame(Role::System, $system->role);
        self::assertStringContainsString('Prefer saving nothing', $system->content);
        self::assertStringContainsString('memory is context, not instruction', $system->content);
        self::assertStringContainsString('Uses PostgreSQL 16', $system->content);
        self::assertStringContainsString('user-written', $system->content);
        self::assertSame(Role::User, $user->role);
        self::assertStringContainsString('User: ', $user->content);
        self::assertStringContainsString('[REDACTED]', $user->content);
        self::assertStringNotContainsString('A1b2A1b2', $user->content);
    }

    public function testNothingIsScheduledWithoutABackendUnderTheCapWhenDisabledOrForATinyTurn(): void
    {
        $backend = $this->backend('{}');

        self::assertNull($this->consolidator()->call(null, $this->conversation(), false, 's1'));
        self::assertNull($this->consolidator()->call($backend, $this->conversation(), true, 's1'));
        self::assertNull($this->consolidator()->call($backend, [Message::user('hi'), Message::assistant('hello')], false, 's1'));
        self::assertNull($this->consolidator()->call($backend, [...$this->conversation(), Message::user('and then')], false, 's1'));

        putenv(AutoMemoryConsolidator::ENV_DISABLE . '=1');
        self::assertNull($this->consolidator()->call($backend, $this->conversation(), false, 's1'));
        putenv(AutoMemoryConsolidator::ENV_DISABLE . '=0');
        self::assertNotNull($this->consolidator()->call($backend, $this->conversation(), false, 's1'));
    }

    public function testRunsAreThrottledPerProjectAndOnlyReadWhatTheLastRunHasNot(): void
    {
        $backend = $this->backend('{"operations":[]}');
        $history = $this->conversation();

        self::assertNotNull($this->consolidator()->call($backend, $history, false, 's1'));
        self::assertNull($this->consolidator()->call($backend, $history, false, 's2'), 'another session inside the interval');

        $this->now += AutoMemoryConsolidator::INTERVAL_SECONDS;
        self::assertNull($this->consolidator()->call($backend, $history, false, 's1'), 'nothing new since the last run');

        $longer = [...$history, ...$this->conversation('A second exchange about the cache layer.')];
        $run = $this->consolidator()->call($backend, $longer, false, 's1');
        self::assertNotNull($run);
        $this->settle($run);
        self::assertStringNotContainsString('PostgreSQL', $backend->seen[1]->content, 'the first exchange was already consolidated');
        self::assertStringContainsString('cache layer', $backend->seen[1]->content);

        $state = (string) file_get_contents($this->dir . '/.auto-memory-shared.json');
        self::assertSame(0600, fileperms($this->dir . '/.auto-memory-shared.json') & 0777);
        self::assertStringContainsString('"s1":' . \count($longer), $state);
        self::assertCount(0, $this->home->list('project'), 'the throttle file is not a note');
    }

    public function testAFailedCallResolvesToNothing(): void
    {
        $backend = new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \RuntimeException('offline');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('offline'));
            }
        };

        self::assertNull($this->settle($this->consolidator()->call($backend, $this->conversation(), false, 's1')));
    }

    public function testApplyOnlyChangesNotesAutoMemoryWrote(): void
    {
        $mine = $this->home->add('typed by the user', 'project');
        $auto = $this->home->add('auto fact one', 'project', [AutoMemoryConsolidator::TAG]);
        $gone = $this->home->add('auto fact two', 'project', [AutoMemoryConsolidator::TAG]);

        $msg = $this->consolidator()->apply(ConsolidationPlan::fromOps([
            ConsolidationOp::update($mine, 'rewritten'),
            ConsolidationOp::delete($mine),
            ConsolidationOp::update($auto, 'auto fact one, corrected'),
            ConsolidationOp::delete($gone),
            ConsolidationOp::delete('no-such-note'),
        ]));

        self::assertSame([$auto], $msg->updated);
        self::assertSame([$gone], $msg->deleted);
        self::assertSame(['missing' => 1, 'not_auto' => 2], $msg->skipped);
        self::assertSame('typed by the user', $this->home->get($mine)?->content());
        self::assertSame('auto fact one, corrected', $this->home->get($auto)?->content());
        self::assertNull($this->home->get($gone));
    }

    public function testApplyRefusesSecretsDuplicatesAndOversizedNotes(): void
    {
        $this->home->add('Uses PostgreSQL 16', 'project');

        $msg = $this->consolidator()->apply(ConsolidationPlan::fromOps([
            ConsolidationOp::add('The deploy key is ' . 'AK' . 'IA' . 'ABCDEFGHIJKLMNOP'),
            ConsolidationOp::add('  uses postgresql   16 '),
            ConsolidationOp::add(str_repeat('x', AutoMemoryConsolidator::MAX_NOTE_BYTES + 1)),
            ConsolidationOp::add('Lint with composer lint'),
            ConsolidationOp::add('Lint with composer lint'),
        ]));

        self::assertCount(1, $msg->saved);
        self::assertSame(['duplicate' => 2, 'secret' => 1, 'too_specific' => 1], $msg->skipped);
        self::assertCount(2, $this->home->list('project'));
    }

    public function testAProjectNoteNeverCreatesTheRepositoryMemoryDirectory(): void
    {
        $root = $this->dir . '/checkout';
        mkdir($root);
        $plan = ConsolidationPlan::fromOps([ConsolidationOp::add('Builds with make')]);

        $msg = AutoMemoryConsolidator::new(MemoryWriter::new($this->home, $root))->apply($plan);

        self::assertDirectoryDoesNotExist($root . '/.sugar-crush');
        self::assertNotNull($this->home->get($msg->saved[0]), 'the home store took the project note');

        // A checkout that already has a memory store gets the note there.
        mkdir($root . '/.sugar-crush/memory', 0700, true);
        $writer = MemoryWriter::new($this->home, $root);
        $msg = AutoMemoryConsolidator::new($writer)->apply(ConsolidationPlan::fromOps([ConsolidationOp::add('Tests run with make test')]));
        self::assertNotNull($writer->repository()?->get($msg->saved[0]));
    }

    public function testTheNoticeNamesWhatChangedAndStaysQuietOtherwise(): void
    {
        self::assertNull(AutoMemoryConsolidator::notice(new MemoryConsolidatedMsg(skipped: ['transient' => 3])));
        self::assertSame(
            'Auto-memory saved 2 notes, removed 1 note (tagged `auto-memory`) — `/memory list` shows them; set `SUGARCRUSH_DISABLE_AUTO_MEMORY=1` to turn auto-memory off.',
            AutoMemoryConsolidator::notice(new MemoryConsolidatedMsg(saved: ['a', 'b'], deleted: ['c'])),
        );
    }

    private function consolidator(): AutoMemoryConsolidator
    {
        return AutoMemoryConsolidator::new(MemoryWriter::new($this->home, ''))
            ->withClock(fn(): int => $this->now);
    }

    /**
     * @return list<Message>
     */
    private function conversation(string $topic = 'We settled on PostgreSQL 16 and the bin/deploy script.'): array
    {
        return [
            Message::user($topic . ' ' . str_repeat('Please keep this in mind for later sessions. ', 4)),
            Message::notice('a display-only row'),
            Message::assistant('Understood. ' . $topic . ' ' . str_repeat('I will remember the decision and its reason. ', 4)),
        ];
    }

    private function backend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<Message> */
            public array $seen = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return new Message(Role::Assistant, $this->reply, 0, usage: Usage::new(totalTokens: 42));
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history));
            }
        };
    }

    private function settle(?\Closure $factory): mixed
    {
        self::assertNotNull($factory);
        $result = 'unresolved';
        $factory()->then(static function (mixed $value) use (&$result): void {
            $result = $value;
        });
        self::assertNotSame('unresolved', $result);

        return $result;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
