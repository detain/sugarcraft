<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Usage;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Sprinkles\Style;

/**
 * Roadmap 1.B-3: compaction FLAGS the rows it condenses as hidden from the
 * model instead of deleting them, so the scrollback and the saved transcript
 * keep the conversation as it happened, and the transcript marks where the
 * condensed part ends.
 *
 * Before this, every compaction route - `/compact` on the heuristic, `/compact`
 * landing a model's summaries, and the automatic 85% tier - replaced the
 * condensed rows with `[summary] …` lines in the one list the transcript is
 * painted from and saved from: the user lost the text they had been reading,
 * and a resumed session lost it for good.
 *
 * The invariants pinned on every route ({@see assertHiddenNotDeleted()}):
 *  - every row the compaction was handed is still in the history, in order;
 *  - the condensed ones are UI-only now (the model no longer reads them) and
 *    still painted;
 *  - what the model reads in their place is NOT painted (`userVisible` false);
 *  - one boundary row sits between them and the preserved rows;
 *  - the agent-visible rows are the summaries followed by the preserved rows.
 */
final class CompactionHidesNotDeletesTest extends TestCase
{
    /**
     * Five exchanges; with two preserved, three are condensed.
     *
     * @return list<Message>
     */
    private static function history(): array
    {
        $detail = str_repeat('detail ', 60);
        $history = [];
        for ($i = 1; $i <= 5; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("answer {$i} {$detail}");
        }

        return $history;
    }

    private static function chat(?Backend $summaryBackend, array $history, string $draft = '/compact'): Chat
    {
        return new Chat(
            history: $history,
            inputBuf: $draft,
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $summaryBackend,
        );
    }

    private static function summarizer(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            public function __construct(private readonly string $reply) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    /**
     * @param list<Message> $handed    what the compaction was handed
     * @param list<Message> $history   what it left
     * @param int           $condensed how many leading rows of $handed it condensed
     */
    private function assertHiddenNotDeleted(array $handed, array $history, int $condensed): void
    {
        // Every row handed in is still there, in order.
        $at = 0;
        $positions = [];
        foreach ($history as $position => $message) {
            $expected = $handed[$at] ?? null;
            if ($expected !== null && $message->role === $expected->role && $message->content === $expected->content) {
                $positions[$at] = $position;
                $at++;
            }
        }
        $this->assertSame(count($handed), $at, 'no row the compaction was handed is deleted');

        // The condensed rows are hidden from the model and still painted; the
        // rest are as they were.
        foreach ($handed as $i => $original) {
            $kept = $history[$positions[$i]];
            if ($i < $condensed) {
                $this->assertTrue($kept->uiOnly, "condensed row {$i} is hidden from the model");
                $this->assertTrue($kept->userVisible, "condensed row {$i} is still painted");
            } else {
                $this->assertSame($original, $kept, "preserved row {$i} is the very object handed in");
            }
        }

        // Exactly one boundary, directly ahead of the first preserved row.
        $boundaries = array_keys(array_filter($history, static fn(Message $m): bool => Chat::isCompactionBoundary($m)));
        $this->assertCount(1, $boundaries, 'one boundary row per compaction');
        $this->assertSame($positions[$condensed] - 1, $boundaries[0], 'the boundary sits right before the preserved rows');

        // What the model reads in their place sits between them and the
        // boundary, and is not painted.
        $replacements = array_slice($history, $positions[$condensed - 1] + 1, $boundaries[0] - $positions[$condensed - 1] - 1);
        $this->assertNotSame([], $replacements, 'the condensed rows were replaced by something for the model');
        foreach ($replacements as $replacement) {
            $this->assertFalse($replacement->uiOnly, 'the replacement is what the model reads');
            $this->assertFalse($replacement->userVisible, 'and the transcript does not paint it');
        }

        // So the model reads the replacements, then the preserved rows.
        $visible = Message::agentVisible($history);
        $this->assertSame(
            [...$replacements, ...array_slice($handed, $condensed)],
            array_slice($visible, 0, count($replacements) + count($handed) - $condensed),
        );
    }

    public function testTheHeuristicCompactHidesTheCondensedRowsInsteadOfDeletingThem(): void
    {
        $handed = self::history();
        [$next, $cmd] = self::chat(null, $handed)->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'no summarizer: /compact answers synchronously');
        $this->assertHiddenNotDeleted($handed, $next->history, 6);

        // The report counts what the model reads: 10 rows went in, the state
        // block (roadmap 2.5), 3 summaries and 4 preserved rows come out - not
        // the whole list, which grew.
        $report = $next->history[count($next->history) - 1]->content;
        $this->assertStringContainsString('was 10 messages, now 8 messages', $report);
    }

    public function testTheModelSummaryLandingHidesTheCondensedRowsInsteadOfDeletingThem(): void
    {
        $handed = self::history();
        $chat = self::chat(self::summarizer("1.\nasked: one\n2.\nasked: two\n3.\nasked: three"), $handed);

        [$scheduled, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'the summarizer is asked off the render loop');
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $landing = null;
        $async->promise->then(static function ($msg) use (&$landing): void {
            $landing = $msg;
        });
        $this->assertInstanceOf(HistoryCompactedMsg::class, $landing);

        [$landed] = $scheduled->update($landing);

        $this->assertHiddenNotDeleted($handed, $landed->history, 6);
        $summaries = array_values(array_filter(
            Message::agentVisible($landed->history),
            static fn(Message $m): bool => str_starts_with($m->content, '[summary] '),
        ));
        $this->assertCount(4, $summaries, 'the model reads its own summaries, after the state block (roadmap 2.5)');
        $this->assertStringStartsWith('[summary] Session state (compacted):', $summaries[0]->content);
        $this->assertStringContainsString('one', $summaries[1]->content);
    }

    public function testTheAutomaticTierHidesTheCondensedRowsAndReportsWhatTheModelReads(): void
    {
        $handed = [];
        for ($i = 0; $i < 3; $i++) {
            $handed[] = Message::user("u{$i} " . str_repeat('x', 60_000));
            $handed[] = Message::assistant("a{$i} " . str_repeat('y', 60_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $handed[] = Message::user("q{$i}");
            $handed[] = Message::assistant("r{$i}");
        }
        $chat = new Chat(history: $handed, inputBuf: 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the turn still goes out');

        $this->assertHiddenNotDeleted($handed, array_slice($next->history, 0, -2), 6);
        $this->assertStringContainsString(
            '26 messages -> 24 messages',
            $next->history[count($next->history) - 2]->content,
            'the tier report counts what the model reads, so it still counts down',
        );
    }

    public function testASecondCompactionKeepsTheFirstOnesRowsAndBoundary(): void
    {
        $handed = self::history();
        [$once] = self::chat(null, $handed)->update(new KeyMsg(KeyType::Enter, ''));

        $more = $once->history;
        for ($i = 6; $i <= 8; $i++) {
            $more[] = Message::user("question {$i}");
            $more[] = Message::assistant("answer {$i} " . str_repeat('more ', 60));
        }
        [$twice] = self::chat(null, $more)->update(new KeyMsg(KeyType::Enter, ''));

        // Every original row of both rounds is still in the transcript, in order.
        $contents = array_map(static fn(Message $m): string => $m->content, $twice->history);
        $last = -1;
        foreach (array_slice($more, 0) as $row) {
            if (!$row->userVisible) {
                continue;
            }
            $found = array_search($row->content, array_slice($contents, $last + 1, null, true), true);
            $this->assertNotFalse($found, "`{$row->content}` survived the second compaction");
            $last = $found;
        }
        $this->assertCount(
            2,
            array_filter($twice->history, static fn(Message $m): bool => Chat::isCompactionBoundary($m)),
            'each compaction leaves its own boundary',
        );
        foreach ($twice->history as $row) {
            if (str_starts_with($row->content, 'question 1') || str_starts_with($row->content, 'question 6')) {
                $this->assertTrue($row->uiOnly, "`{$row->content}` stays hidden from the model");
            }
        }
    }

    public function testAHiddenRowKeepsItsIdentityAndStep(): void
    {
        $handed = self::history();
        $handed[0] = $handed[0]->withIdentity('m_s_1', 1);
        $handed[1] = $handed[1]->withIdentity('m_s_2', 2)->withStepId('step-1');

        [$next] = self::chat(null, $handed)->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertTrue($next->history[0]->uiOnly);
        $this->assertSame(['m_s_1', 1], [$next->history[0]->id, $next->history[0]->ref]);
        $this->assertTrue($next->history[1]->uiOnly);
        $this->assertSame(['m_s_2', 2, 'step-1'], [$next->history[1]->id, $next->history[1]->ref, $next->history[1]->stepId]);
    }

    public function testTheHiddenAndReplacementFlagsSurviveASaveAndResume(): void
    {
        [$next] = self::chat(null, self::history())->update(new KeyMsg(KeyType::Enter, ''));

        $resumed = array_map(
            static fn(Message $m): Message => Message::fromArray(json_decode(json_encode($m, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)),
            $next->history,
        );

        $this->assertSame(
            array_map(static fn(Message $m): array => [$m->content, $m->uiOnly, $m->userVisible], $next->history),
            array_map(static fn(Message $m): array => [$m->content, $m->uiOnly, $m->userVisible], $resumed),
        );
        $this->assertCount(1, array_filter($resumed, static fn(Message $m): bool => Chat::isCompactionBoundary($m)));
    }

    public function testTheTranscriptPaintsTheCondensedRowsTheBoundaryAndNotTheSummaries(): void
    {
        [$next] = self::chat(null, self::history())->update(new KeyMsg(KeyType::Enter, ''));

        $theme = Theme::default();
        $painted = $this->renderHistory($next->history, $theme, 60);
        $plain = Ansi::strip($painted);

        $this->assertStringContainsString('question 1', $plain, 'the scrollback keeps the condensed exchange');
        $this->assertStringContainsString('answer 1 detail', $plain);
        $this->assertStringNotContainsString('[summary]', $plain, 'what the model reads instead is not painted');
        $this->assertStringNotContainsString('[exchanged information]', $plain);
        $this->assertStringContainsString('── context compacted · the model reads a summary', $plain);
        $this->assertStringNotContainsString(Chat::COMPACTION_BOUNDARY, $plain, 'the boundary is a rule, not a notice row');

        foreach (explode("\n", $plain) as $line) {
            $this->assertLessThanOrEqual(60, \SugarCraft\Core\Util\Width::string($line), "no painted row is wider than the pane: `{$line}`");
        }

        // Condensed turns wear dim labels, the turns the model still reads the
        // bright ones.
        $bright = Style::new()->foreground($theme->userLabel)->bold()->render('user>');
        $dim = (new \ReflectionMethod(Renderer::class, 'dim'))->invoke(null, $theme)->render('user>');
        if ($bright === $dim) {
            $this->markTestIncomplete('this environment renders no SGR, so the two labels cannot be told apart');
        }
        $this->assertSame(3, substr_count($painted, $dim), 'question 1-3 are condensed');
        $this->assertSame(3, substr_count($painted, $bright), 'question 4-5 and the /compact echo are read by the model or are new');
    }

    public function testATranscriptWithNoCompactionPaintsNoBoundaryAndNoDimLabels(): void
    {
        $theme = Theme::default();
        $painted = $this->renderHistory(self::history(), $theme, 60);

        $this->assertStringNotContainsString('context compacted', Ansi::strip($painted));
        $bright = Style::new()->foreground($theme->userLabel)->bold()->render('user>');
        $this->assertSame(5, substr_count($painted, $bright));
    }

    public function testABoundaryIsRecognisedOnlyAsTheNoticeCompactionWrites(): void
    {
        $this->assertTrue(Chat::isCompactionBoundary(Message::notice(Chat::COMPACTION_BOUNDARY)));
        $this->assertFalse(Chat::isCompactionBoundary(Message::user(Chat::COMPACTION_BOUNDARY)), 'a user quoting it');
        $this->assertFalse(Chat::isCompactionBoundary(Message::system(Chat::COMPACTION_BOUNDARY)), 'an agent-visible row');
        $this->assertFalse(Chat::isCompactionBoundary(Message::notice('Context compacted')));
    }

    /**
     * W2-a handoff: the intra-exchange rescue's copy dropped id, ref, stepId,
     * userVisible and turnTranscript (and, since O-2b, the rowKey token). Compared against Message's constructor,
     * so a field added later fails here until the copy carries it.
     */
    public function testTheTruncationCopyCarriesEveryFieldButContent(): void
    {
        $values = [
            'role' => Role::Assistant,
            'content' => 'the original content',
            'createdAt' => 1_234_567_890,
            'attachments' => [new Attachment('/tmp/data.csv', AttachmentType::File)],
            'toolCalls' => [new ToolCall('bash', ['command' => 'ls'], 'tc1')],
            'toolResults' => [new ToolResult('bash', 'listing', null, 'tc1')],
            'pendingToolCallId' => 'tc1',
            'reasoning' => 'thought',
            'imageBytes' => 'PNG',
            'imageProtocol' => 'kitty',
            'usage' => Usage::new(10, 0.5),
            'lengthStopped' => true,
            'stepsTruncated' => true,
            'pendingToolArguments' => ['command' => 'ls'],
            'uiOnly' => true,
            'loopGuardStoppedBy' => 'bash',
            'attachmentNotice' => 'degraded',
            'pendingToolName' => 'bash',
            'id' => 'm_s_7',
            'ref' => 7,
            'stepId' => 'step-3',
            'userVisible' => false,
            'turnTranscript' => [Message::assistant('row')],
            'rowKey' => new \stdClass(),
        ];
        $parameters = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Message::class, '__construct'))->getParameters(),
        );
        $this->assertSame($parameters, array_keys($values), 'the fixture sets every constructor field - extend it with the new one');

        $original = new Message(...$values);
        $copy = (new \ReflectionMethod(Chat::class, 'messageWithContent'))->invoke(null, $original, 'shorter');

        $this->assertSame('shorter', $copy->content);
        foreach ($parameters as $name) {
            if ($name === 'content') {
                continue;
            }
            // Not a promoted property (it is not public state): read it the
            // way the store does.
            if ($name === 'rowKey') {
                $this->assertSame($original->rowKey(), $copy->rowKey(), 'the copy keeps its row identity');
                continue;
            }
            $this->assertSame($original->{$name}, $copy->{$name}, "the copy keeps {$name}");
        }
    }

    /** @param list<Message> $history */
    private function renderHistory(array $history, Theme $theme, int $width): string
    {
        return (new \ReflectionMethod(Renderer::class, 'renderHistory'))
            ->invoke(null, $history, $theme, $width, [], new ImageLayer(), null, 10);
    }
}
