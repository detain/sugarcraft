<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * O-2e: compaction left {@see Chat} for {@see CompactionService} — the
 * heuristic and model routes, the summary request and its parse, the
 * hide-not-delete layout (roadmap 1.B-3) and every sentence the tiers write —
 * and Chat kept only the Msg plumbing. The behaviour through Chat stays pinned
 * by the compaction suites ({@see \SugarCraft\Crush\Tests\Chat\CompactModelSummaryTest},
 * {@see \SugarCraft\Crush\Tests\Chat\AutomaticCompactionModelSummaryTest},
 * {@see \SugarCraft\Crush\Tests\Chat\CompactionHidesNotDeletesTest},
 * {@see \SugarCraft\Crush\Tests\Context\ContextCompactorTest}); these cases pin
 * that what Chat commits IS the service's answer, on every route, so a headless
 * host built on the service compacts exactly as the TUI does.
 */
final class CompactionServiceParityTest extends TestCase
{
    /** Twelve plain exchanges: enough that the heuristic condenses the oldest two. */
    private static function exchanges(int $count = 12): array
    {
        $history = [];
        for ($i = 0; $i < $count; $i++) {
            $history[] = Message::user("question {$i} about the router");
            $history[] = Message::assistant("answer {$i}: the router reads routes.php");
        }

        return $history;
    }

    /**
     * Over the 85% tier of an 88,000-token window and, once the older exchanges
     * are condensed, under the 95% tier — the shape the parked route needs.
     */
    private static function overTheTier(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 52_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 52_000));
        }

        return [...$history, ...self::exchanges(10)];
    }

    /** @param list<Message> $history @return list<array{string,string,bool,bool}> */
    private static function shape(array $history): array
    {
        return array_map(
            static fn (Message $m): array => [$m->role->value, $m->content, $m->uiOnly, $m->userVisible],
            array_values($history),
        );
    }

    private static function compactorOf(Chat $chat): ContextCompactor
    {
        return (new \ReflectionProperty(Chat::class, 'compactor'))->getValue($chat);
    }

    private static function window(int $tokens): Backend
    {
        return new class ($tokens) implements Backend, ReportsContextWindow {
            public function __construct(private readonly int $window)
            {
            }

            public function contextWindow(): int
            {
                return $this->window;
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('ok');
            }

            public function completeAsync(
                array $history,
                callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };
    }

    private static function replyingSummaryBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(
                array $history,
                callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    /** Drive a Cmd built by Cmd::promise() and hand back the Msg it resolves to. */
    private function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(static function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    public function testChatReadsTheServiceItsWorkspaceRegistered(): void
    {
        $service = CompactionService::new();
        $chat = new Chat(workspace: WorkspaceContext::new()->withService(CompactionService::class, $service));
        $accessor = new \ReflectionMethod(Chat::class, 'compactionService');

        self::assertSame($service, $accessor->invoke($chat));
        self::assertInstanceOf(CompactionService::class, $accessor->invoke(new Chat()), 'no workspace still compacts');
    }

    /**
     * Chat still names the constants tests, docs and its own unmoved code read;
     * each must be the service's value, or Chat and a headless host would write
     * — and recognise — different rows.
     */
    public function testChatsNamedConstantsAreTheServicesValues(): void
    {
        $chat = new \ReflectionClass(Chat::class);
        foreach ([
            'PARK_NOTICE_PREFIX', 'CONTEXT_REMINDER_PREFIX', 'BLOCKED_TURN_PREFIX', 'COMPACT_SUMMARY_PROMPT',
            'SUMMARY_LINE_MAX_CHARS', 'SUMMARY_FACETS', 'SUMMARY_FACET_PATTERN', 'SUMMARY_FACET_NONE',
            'COMPACTION_BOUNDARY',
        ] as $name) {
            self::assertSame(
                constant(CompactionService::class . '::' . $name),
                $chat->getConstant($name),
                "Chat::{$name} drifted from CompactionService::{$name}",
            );
        }
    }

    /**
     * `/compact` typed into Chat with no model to ask commits exactly the
     * service's heuristic compaction, echo and report included — and the rows
     * are laid out hide-not-delete: every original row is still there in its
     * place, the condensed ones flagged UI-only, with one boundary between them
     * and the preserved tail.
     */
    public function testSlashCompactCommitsTheServicesHideNotDeleteLayout(): void
    {
        $history = self::exchanges();
        $chat = new Chat(history: $history, inputBuf: '/compact');

        [$compacted] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $expected = CompactionService::new()->compactedHistory(
            self::compactorOf($chat),
            '/compact',
            $history,
            [],
            static fn (): int => 0,
            static fn (array $rows): int => 0,
        );
        self::assertSame(self::shape($expected), self::shape($compacted->history));

        $boundaries = array_values(array_filter($compacted->history, CompactionService::isCompactionBoundary(...)));
        self::assertCount(1, $boundaries, 'one boundary row between the condensed and the preserved rows');
        self::assertTrue(Chat::isCompactionBoundary($boundaries[0]), 'Chat and the service recognise the same row');

        $originals = array_values(array_filter(
            $compacted->history,
            static fn (Message $m): bool => $m->userVisible && !CompactionService::isCompactionBoundary($m),
        ));
        self::assertSame(
            array_map(static fn (Message $m): string => $m->content, $history),
            array_slice(array_map(static fn (Message $m): string => $m->content, $originals), 0, count($history)),
            'nothing the user wrote or read was deleted',
        );
        self::assertTrue($originals[0]->uiOnly, 'a condensed row is hidden from the model, not removed');
        self::assertFalse($originals[count($history) - 1]->uiOnly, 'a preserved row still reaches the model');
    }

    /**
     * The 85% tier's parked route: Chat echoes the prompt behind the service's
     * park notice, sends the service's summary request, and the landing commits
     * the service's compaction of the history it parked (the tier report
     * included) before the turn goes out.
     */
    public function testTheParkedRouteIsTheServicesRequestNoticeAndLanding(): void
    {
        $reply = "1.\nasked: condensed exchange one\n2.\nasked: condensed exchange two";
        $chat = new Chat(
            history: self::overTheTier(),
            inputBuf: 'what changed in the router?',
            backend: self::window(88_000),
            summaryBackend: self::replyingSummaryBackend($reply),
        );
        $service = CompactionService::new();

        [$parked, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNotNull($cmd, 'the tier parks the turn behind a summarization');
        self::assertTrue($parked->inFlight);
        $notice = $parked->history[count($parked->history) - 2];
        self::assertSame(Role::System, $notice->role);
        self::assertStringStartsWith(CompactionService::PARK_NOTICE_PREFIX, $notice->content);
        self::assertMatchesRegularExpression('/^.*~(\d+) estimated tokens of a 88000-token .* Summarising (\d+) earlier/', $notice->content);
        preg_match('/~(\d+) estimated tokens of a 88000-token context window\. Summarising (\d+) earlier/', $notice->content, $m);
        self::assertSame($service->parkNotice((int) $m[1], 88_000, (int) $m[2]), $notice->content);

        $landing = $this->resolve($cmd);
        self::assertInstanceOf(HistoryCompactedMsg::class, $landing);
        self::assertSame('what changed in the router?', $landing->parkedSubmission);
        self::assertNotSame([], $landing->summaries, 'the records parse into summaries');

        $expected = $service->compactedHistory(
            self::compactorOf($parked),
            '',
            $parked->history,
            $landing->summaries,
            $parked->contextTokenLimit(...),
            (new \ReflectionMethod(Chat::class, 'estimateTokenCount'))->getClosure($parked),
            '',
            true,
        );
        [$landed] = $parked->update($landing);

        self::assertSame(
            self::shape($expected),
            array_slice(self::shape($landed->history), 0, count($expected)),
            'the landing commits the service\'s compaction before the turn\'s own rows',
        );
        self::assertCount(1, array_filter($landed->history, CompactionService::isCompactionBoundary(...)));
    }

    public function testTheSummaryRequestResolvesToTheServicesParseOfTheReply(): void
    {
        $service = CompactionService::new();
        $compactor = self::compactorOf(new Chat());
        $reply = "1.\nasked: rename the route\ndid: renamed it\nfiles: routes.php";

        self::assertNull($service->buildSummarizationRequest(null, $compactor, self::exchanges(), null), 'offline: no request');

        $request = $service->buildSummarizationRequest(self::replyingSummaryBackend($reply), $compactor, self::exchanges(), 'parked');
        self::assertNotNull($request);
        self::assertSame(2, $request['count']);

        $msg = null;
        ($request['promise'])()->then(static function (HistoryCompactedMsg $landed) use (&$msg): void {
            $msg = $landed;
        });
        self::assertInstanceOf(HistoryCompactedMsg::class, $msg);
        self::assertSame($request['id'], $msg->compactionId);
        self::assertSame('parked', $msg->parkedSubmission);
        self::assertCount(1, $msg->summaries);
        self::assertSame(
            'asked: rename the route | did: renamed it | files: routes.php | decided: none | corrected: none | error: none',
            array_values($msg->summaries)[0],
        );

        $chatRequest = (new \ReflectionMethod(Chat::class, 'buildSummarizationRequest'))
            ->invoke(new Chat(summaryBackend: self::replyingSummaryBackend($reply)), self::exchanges(), null);
        self::assertSame(2, $chatRequest['count'], 'Chat offers the same exchanges');
        self::assertInstanceOf(\Closure::class, $chatRequest['cmd'], 'and wraps the request in a Cmd');
    }

    /**
     * The blocking tier's refusal and the thrash breaker commit the service's
     * rows, and the breaker's run moves exactly as the service counts it.
     */
    public function testTheRefusalsAndTheBreakerAreTheServicesWords(): void
    {
        $service = CompactionService::new();
        $history = self::exchanges(2);
        $chat = new Chat(history: $history, consecutiveRefillCompactions: 2);

        [$refused] = (new \ReflectionMethod(Chat::class, 'foregroundBlockedResponse'))
            ->invoke($chat, 'retry please', $history, 91_000, 88_000);
        self::assertSame(
            self::shape($service->foregroundBlockedRows('retry please', $history, 91_000, 88_000)),
            self::shape($refused->history),
        );
        self::assertSame(
            self::shape([...$history, $service->foregroundBlockedRows('', [], 1, 2)[0]]),
            self::shape($service->foregroundBlockedRows('', $history, 1, 2)),
            'an echo already in the transcript is not written twice — not even as an empty user row',
        );

        [$broken] = (new \ReflectionMethod(Chat::class, 'thrashBreakerRefusal'))->invoke($chat);
        self::assertSame($service->thrashBreakerNotice(), $broken->history[count($broken->history) - 1]->content);
        self::assertStringContainsString((string) IdleCompactionPolicy::REFILL_LIMIT . ' times in a row', $service->thrashBreakerNotice());

        $outcome = new \ReflectionMethod(Chat::class, 'withCompactionOutcome');
        $run = new \ReflectionProperty(Chat::class, 'consecutiveRefillCompactions');
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$over, $sent]) {
            self::assertSame(
                $service->refillCount(2, $over, $sent),
                $run->getValue($outcome->invoke($chat, $over, $sent)),
                'over=' . var_export($over, true) . ' sent=' . var_export($sent, true),
            );
        }
        self::assertSame([0, 0, 3, 2], [
            $service->refillCount(2, false, false),
            $service->refillCount(2, false, true),
            $service->refillCount(2, true, false),
            $service->refillCount(2, true, true),
        ]);
    }

    /**
     * Both spend-cap answers open with the one shared sentence, and the parked
     * submission's own rows — the prompt and the notes bracketed by the park
     * notice — are what the pre-turn checkpoint drops, nothing else.
     */
    public function testTheCapNoticesShareTheirSentenceAndTheCheckpointDropsOnlyTheParkedRows(): void
    {
        $service = CompactionService::new();
        $shared = $service->spendCapCompactionNotice(1.5, 1.0, '');

        self::assertStringStartsWith($shared, $service->compactCommandCapNotice(1.5, 1.0));
        self::assertStringStartsWith($shared, $service->parkedTurnCapNotice(1.5, 1.0));
        self::assertStringContainsString('/budget 3.00 and run /compact again', $service->compactCommandCapNotice(1.5, 1.0));
        self::assertStringNotContainsString('/compact', $service->parkedTurnCapNotice(1.5, 1.0));

        $before = self::exchanges(1);
        $park = Message::notice($service->parkNotice(80_000, 88_000, 3));
        $note = Message::notice('hook note');
        $report = Message::notice('report');
        $parkedHistory = [...$before, $park, $note, Message::user('the prompt'), $report];

        self::assertSame(
            self::shape([...$before, $park, $report]),
            self::shape(CompactionService::withoutParkedSubmission($parkedHistory, 'the prompt')),
        );
        self::assertSame(
            self::shape(CompactionService::withoutParkedSubmission($parkedHistory, 'the prompt')),
            self::shape((new \ReflectionMethod(Chat::class, 'withoutParkedSubmission'))->invoke(null, $parkedHistory, 'the prompt')),
        );
    }
}
