<?php

/**
 * C-PRV-3.c: the `ultrathink` keyword lifts ONE dispatch to the top tier.
 *
 * Typing the word as a whole word outside code fences asks
 * {@see \SugarCraft\Code\Providers\EffortKeyword::present()}, and
 * {@see TurnRunner::start()} acts on the answer at the seam every turn —
 * served or chat — already walks through: the backend clone the thunk
 * captures goes out at the top {@see \SugarCraft\Code\Providers\EffortBudget}
 * level, the caller's configured tier survives untouched, and the turn's
 * tool steps keep the lift because they run on that one clone. A pair the
 * effort gate refuses gets a single runtime-notice row instead — through
 * the drain owner's sink only, once per turn. The word itself stays in the
 * prompt text (Claude Code parity: the model still reads what it was asked).
 *
 * Pins:
 * - keyword turn: the dispatched backend reads `max`, and the caller's
 *   backend — and the next turn's dispatch over a fresh typed row — read
 *   the configured tier (nothing persisted);
 * - continuations (tool rows appended after the typed row) re-derive the
 *   same lift from the same latest typed row;
 * - non-shaper: exactly one notice row naming provider and model, and no
 *   second one on the continuation re-entry;
 * - the word inside an inline span, inside a fence, or grown into a longer
 *   word moves nothing;
 * - neither an injected row (uiOnly) nor a hidden one (userVisible false)
 *   rules the dispatch — the walk stops at the latest VISIBLE typed row.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\EffortKeyword;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

final class UltrathinkTurnTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/crush-prv3c-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o700, true);
        RuntimeNoticeSink::arm();
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        if (\is_dir($this->dir)) {
            \exec('rm -rf ' . \escapeshellarg($this->dir));
        }
    }

    // ── dispatch pins ──────────────────────────────────────────────────

    public function testTheKeywordTurnDispatchesAtTheTopTierAndPersistsNothing(): void
    {
        $backend = $this->shaperBackend();
        self::assertNull($backend->reasoningEffort(), 'the fixture starts unlifted');

        $thunk = TurnRunner::new()->start(
            $backend,
            [Message::user('ultrathink about the flaky deploy')],
            new \ArrayObject(),
            1,
            new CancellationToken(),
            false,
            notices: RuntimeNoticeSink::current(),
        );

        self::assertSame('max', self::dispatched($thunk)->reasoningEffort(), 'the clone the turn runs on reads the top tier');
        self::assertNull($backend->reasoningEffort(), 'the caller backend is untouched — no persistence');
        self::assertFalse(RuntimeNoticeSink::current()->hasPending(), 'a pair that shapes needs no notice');
    }

    public function testTheNextTurnStartsFromTheConfiguredTierAgain(): void
    {
        $backend = $this->shaperBackend();
        $history = [
            Message::user('ultrathink about the flaky deploy'),
            Message::assistant('here is the plan'),
            Message::user('thanks'),
        ];

        $thunk = TurnRunner::new()->start($backend, $history, new \ArrayObject(), 2, new CancellationToken(), false);

        self::assertNull(self::dispatched($thunk)->reasoningEffort(), 'the latest typed row carries no keyword; the saved tier (unset) rules');
    }

    public function testToolContinuationsOfTheSameTurnKeepTheTier(): void
    {
        $backend = $this->shaperBackend();
        // The shape a continuation re-dispatch carries: the typed row is no
        // longer the newest row, but it is still the latest TYPED one — the
        // step rows above it are tool bookkeeping, not fresh asks.
        $history = [
            Message::user('ultrathink about the flaky deploy'),
            new Message(
                role: Role::Assistant,
                content: 'running a command',
                createdAt: \time(),
                stepId: 'step-1',
            ),
        ];

        $thunk = TurnRunner::new()->start($backend, $history, new \ArrayObject(), 1, new CancellationToken(), false);

        self::assertSame('max', self::dispatched($thunk)->reasoningEffort(), 'the same typed row still rules the whole turn');
    }

    public function testANonShaperGetsOneNoticeRowAndNoLift(): void
    {
        $backend = EngineBackend::new(new ScriptedProvider([]), 'm')
            ->withoutHooks()
            ->withRoot($this->dir)
            ->withTools([]);

        $thunk = TurnRunner::new()->start(
            $backend,
            [Message::user('ultrathink this please')],
            new \ArrayObject(),
            1,
            new CancellationToken(),
            false,
            notices: RuntimeNoticeSink::current(),
        );

        self::assertNull(self::dispatched($thunk)->reasoningEffort(), 'a pair that shapes nothing stays at the normal tier');
        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows, 'exactly one notice row');
        self::assertStringContainsString('ultrathink: scripted/m', $rows[0], 'the row names the pair that lacks the control');
        self::assertStringContainsString('sent at the normal tier', $rows[0]);

        // The continuation re-entry (keyword row no longer last) must not
        // repeat the row for the same ask.
        $history = [
            Message::user('ultrathink this please'),
            new Message(role: Role::Assistant, content: 'working', createdAt: \time(), stepId: 'step-1'),
        ];
        TurnRunner::new()->start(
            $backend,
            $history,
            new \ArrayObject(),
            1,
            new CancellationToken(),
            false,
            notices: RuntimeNoticeSink::current(),
        );
        self::assertFalse(RuntimeNoticeSink::current()->hasPending(), 'one notice per turn, not one per dispatch');
    }

    public function testTheQuotedWordMovesNothing(): void
    {
        $backend = $this->shaperBackend();

        $thunk = TurnRunner::new()->start(
            $backend,
            [Message::user('the docs say to type `ultrathink` for a deeper answer')],
            new \ArrayObject(),
            1,
            new CancellationToken(),
            false,
            notices: RuntimeNoticeSink::current(),
        );

        self::assertNull(self::dispatched($thunk)->reasoningEffort(), 'an inline span is quoted material, not an ask');
        self::assertFalse(RuntimeNoticeSink::current()->hasPending(), 'and it earns no notice either');
    }

    public function testHiddenAndInjectedRowsNeverRuleTheDispatch(): void
    {
        $backend = $this->shaperBackend();
        // C-PRV-3.c review MINOR-2: the walk's two exclusion legs, pinned
        // one row per leg. An injected row (uiOnly — hidden from the model)
        // and a hidden row (userVisible false — reaches the model but is
        // never painted) are not typed asks. Each carries the keyword and
        // sits above the clean visible ask, so dropping either leg lets it
        // win the newest-row race and lift this dispatch: the leg removal
        // reddens the tier assert below.
        $history = [
            Message::user('what does the deploy log say'),
            new Message(
                role: Role::User,
                content: 'ultrathink inside injected context',
                createdAt: \time(),
                uiOnly: true,
            ),
            new Message(
                role: Role::User,
                content: 'ultrathink inside hidden context',
                createdAt: \time(),
                userVisible: false,
            ),
        ];

        $thunk = TurnRunner::new()->start(
            $backend,
            $history,
            new \ArrayObject(),
            1,
            new CancellationToken(),
            false,
            notices: RuntimeNoticeSink::current(),
        );

        self::assertNull(self::dispatched($thunk)->reasoningEffort(), 'the walk skips both rows and lands on the clean ask — no lift');
        self::assertFalse(RuntimeNoticeSink::current()->hasPending(), 'and a shaper pair earns no notice either way');
    }

    // ── detector pins ──────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function textShapes(): array
    {
        return [
            'plain ask' => ['ultrathink about this', true],
            'upper case' => ['ULTRATHINK now', true],
            'mid sentence' => ['please, ultrathink, and answer', true],
            'grown word' => ['ultrathinking is not the keyword', false],
            'spelled near' => ['ultra think about it', false],
            'inline span' => ['type `ultrathink` to ask', false],
            'double inline span' => ['docs mention ``ultrathink`` here', false],
            'fenced block' => ["before prose\n```\nultrathink\n```\nafter prose", false],
            'tilde fence' => ["~~~\nultrathink\n~~~", false],
            'unpaired fence swallows' => ["```sh\nultrathink", false],
            'word after a closed fence' => ["```\nultrathink\n```\nbut now ultrathink for real", true],
            'unpaired backtick keeps prose' => ['a stray ` then ultrathink here', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('textShapes')]
    public function testTheDetectorReadsWordsFencesAndSpans(string $text, bool $expected): void
    {
        self::assertSame($expected, EffortKeyword::present($text), \json_encode($text));
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function shaperBackend(): EngineBackend
    {
        return EngineBackend::new(
            new OpenAIProvider($this->createMock(ClientContract::class), 'o3'),
            'o3',
        )
            ->withoutHooks()
            ->withRoot($this->dir)
            ->withTools([]);
    }

    /**
     * The backend the thunk was built to run on. The lift is applied at
     * start() before the closure is created, so the captured copy is the
     * dispatch — the same object the step loop feeds every request.
     */
    private static function dispatched(\Closure $thunk): EngineBackend
    {
        $backend = (new \ReflectionFunction($thunk))->getStaticVariables()['backend'];
        self::assertInstanceOf(EngineBackend::class, $backend);

        return $backend;
    }
}
