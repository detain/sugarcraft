<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\StatusLineTickMsg;
use SugarCraft\Crush\Usage;

/**
 * The `statusLine` command's output where it is PAINTED — the live status bar
 * ({@see Renderer::renderStatusBar()}), which is the frame's last line and the
 * one row that must not wrap.
 *
 * The live path is established rather than assumed: `bin/sugarcrush` builds
 * `Bootstrap::app()`, whose `App::view()` calls `Tui\Renderer::renderView()` —
 * and that method sets `$bottom = ''` whenever a `Chat` is hosted, which it
 * always is on a real launch. So `Tui\Renderer::statusBar()` paints nothing on
 * the live path and THIS renderer's bar is the one a user sees.
 */
final class StatusLineSegmentTest extends TestCase
{
    protected function tearDown(): void
    {
        StatusLineCommand::reset();
        self::setMaxScrollOffset(0);

        parent::tearDown();
    }

    private function chat(int $cols = 100): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
        ))->withSize($cols, 30);
    }

    /** The bar as it is painted, sentinels off — the columns it actually occupies. */
    private function bar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat);

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }

    /**
     * {@see bar()} with the clock pinned — the seam
     * {@see Renderer::renderStatusBar()} carries for exactly this, because an
     * age readout that counted from the wall cannot be asserted byte-for-byte.
     *
     * Named distinctly from `bar()` on purpose: `DuplicatedTestHelperDriftTest`
     * walks every same-named helper pair across the suite, and widening an
     * existing helper's signature is the drift it exists to red.
     */
    private function barAt(Chat $chat, int $now): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat, $now);

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }

    /** The raw bar, sentinels INTACT — for asserting on what reaches the scanner. */
    private function rawBar(Chat $chat): string
    {
        return (string) (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat);
    }

    /**
     * `Renderer::$maxScrollOffset` is normally set by `render()`. Set directly
     * so a scroll readout can be produced without a whole frame, which is the
     * only way to test the PRIORITY claim in isolation.
     */
    private static function setMaxScrollOffset(int $max): void
    {
        $property = new \ReflectionProperty(Renderer::class, 'maxScrollOffset');
        $property->setValue(null, $max);
    }

    private static function install(string $command): void
    {
        StatusLineCommand::configure(
            [StatusLineCommand::KEY => ['type' => StatusLineCommand::TYPE_COMMAND, 'command' => $command]],
        );
        StatusLineCommand::refresh();
    }

    // =====================================================================
    // Absent unless asked for
    // =====================================================================

    /**
     * A SESSION THAT SET NO `statusLine` GETS A BYTE-IDENTICAL BAR. This is
     * what lets every width figure asserted in `StatusBarSpendTest` keep
     * holding unqualified — the segment is not "usually empty", it is absent.
     */
    public function testTheBarIsUnchangedWhenNoCommandIsConfigured(): void
    {
        StatusLineCommand::reset();
        $without = $this->bar($this->chat());

        StatusLineCommand::configure([]);

        self::assertSame($without, $this->bar($this->chat()));
    }

    /**
     * And a CONFIGURED command that has not run yet still paints nothing, so a
     * launch never shows a half-built bar between the first frame and the first
     * tick.
     */
    public function testAConfiguredButNotYetRunCommandPaintsNothing(): void
    {
        $without = $this->bar($this->chat());

        StatusLineCommand::configure(
            [StatusLineCommand::KEY => ['type' => 'command', 'command' => 'echo later']],
        );

        self::assertSame($without, $this->bar($this->chat()));
    }

    // =====================================================================
    // Painted, last
    // =====================================================================

    public function testTheCommandsOutputIsAppendedToTheBar(): void
    {
        $without = $this->bar($this->chat());
        self::install('echo on-branch');

        $with = $this->bar($this->chat());

        self::assertStringStartsWith($without, $with);
        self::assertSame($without . ' · on-branch', $with);
    }

    /**
     * BELOW THE SCROLL READOUT, which is the priority claim and the one thing
     * a naive implementation gets wrong: measured BEFORE the prepend, a wide
     * status line takes the columns the scroll offset was about to claim.
     *
     * The fixture is built to DISCRIMINATE between the two orders rather than
     * merely to exercise one, and the arithmetic is stated with its DOMAIN
     * attached because the two numbers below belong to different bars.
     * Measured at 80 columns, PHP 8.3.6, on this class's own two-message
     * fixture: the IDLE bar (no scroll readout) is 75 columns; the same bar
     * built WITH 20 columns reserved for the scroll readout's long form is 54,
     * because the reserve pushes `contextIndicator()` down to a bare `0%`. It
     * is 54 + 20 = 74 of 80 that leaves the 3 columns after ` · ` that a status
     * line could use — not 75, and not the 54 alone. (54 is also the idle bar's
     * width at cols=57, which is how the wrong domain got attached to it.)
     *
     * A 60-column status line fitted FIRST would have taken those columns and
     * more, leaving the scroll readout no room and downgrading it to the
     * 4-column compact form or off the row entirely. So the assertion is on the
     * LONG form still being the one chosen, which is false under the wrong
     * order and true under the right one.
     */
    public function testTheScrollReadoutOutranksTheStatusLine(): void
    {
        self::setMaxScrollOffset(120);
        $chat = $this->chat(80)->withScrollOffset(40);

        $barWithoutStatus = $this->bar($chat);
        self::assertStringStartsWith(
            '↑ 40/120 scrolled · ',
            $barWithoutStatus,
            'the fixture must be scrolled AND wide enough for the long form, or it discriminates nothing',
        );

        self::install('echo ' . str_repeat('x', 60));
        $barWithStatus = $this->bar($chat);

        self::assertStringStartsWith(
            '↑ 40/120 scrolled · ',
            $barWithStatus,
            'the status line took room the scroll readout had already claimed',
        );
        self::assertLessThanOrEqual(80, Width::of($barWithStatus));
        self::assertStringEndsWith('…', $barWithStatus, 'and the status line is the piece that was cut');
    }

    // =====================================================================
    // The row invariant
    // =====================================================================

    /**
     * THE SEGMENT MAY NEVER WIDEN THE BAR PAST THE TERMINAL, at any width and
     * for any output. A wrapped bar makes the frame one physical row taller,
     * which is the absolute-`cursorTo()` row collision `render()`'s tail clip
     * exists to prevent — frame corruption, not a cosmetic overflow.
     *
     * Swept, and stated as a DELTA against the bar the same fixture produces
     * with no status line, because the bar is already over-wide below 36
     * columns on its own. A pre-existing over-run is not licence to deepen it.
     *
     * TWO INSTRUMENTS, because the first one alone was vacuous twice over and
     * both holes were live:
     *
     *  - EVERY BASELINE IS MEASURED WITH NO COMMAND INSTALLED. The previous
     *    revision measured `$baseline` inside the `cols` loop with the PREVIOUS
     *    iteration's command still configured, so the baseline absorbed the
     *    over-run it was supposed to bound and `max($baseline, $cols)` was
     *    self-referential. Measured at db20c568: mutating
     *    `Renderer::withStatusLineCommand()`'s `if ($room < 1)` to
     *    `if ($room < 0)` — a real one-column over-run at cols 57/68/78, where
     *    the idle bar is exactly `cols - 3` and `$room` is therefore 0 —
     *    SURVIVED that form and fails this one. The baselines are taken in
     *    their own pass here, which also drops the sweep from 800 `proc_open()`s
     *    to six.
     *  - AND THE ROW COUNT IS ASSERTED SEPARATELY FROM THE WIDTH, because
     *    `Width::of()` treats LF as zero-width and SUMS across it: a bar that
     *    has already wrapped into two physical rows measures NARROWER than
     *    either instrument's bound, so a width assertion cannot see the very
     *    failure this test's docblock names. Measured at db20c568 with a
     *    `printf "b\377m\nSECOND"` status line, the bar was two physical rows
     *    at 123 of 200 widths and the width assertion failed at none of them.
     *
     * The last two payloads carry a malformed UTF-8 byte for that reason —
     * they are the inputs that used to defeat the runner's collapse outright
     * ({@see \SugarCraft\Crush\Config\StatusLineCommand::oneLine()}).
     */
    public function testTheSegmentNeverWidensTheBarBeyondWhatItAlreadyWasAtAnyWidth(): void
    {
        $outputs = [
            'echo main',
            'echo ' . str_repeat('x', 400),
            'printf "%s" ' . str_repeat('主', 200),
            'printf "e\\314\\201%.0s" 1 2 3 4 5 6 7 8 9 0',
            'printf "b\\377m\\nSECOND"',
            'printf "ok\\377\\rrm -rf /"',
        ];

        $baselines = [];
        StatusLineCommand::reset();
        for ($cols = 1; $cols <= 200; $cols++) {
            $baselines[$cols] = Width::of($this->bar($this->chat($cols)));
        }

        foreach ($outputs as $command) {
            self::install($command);

            for ($cols = 1; $cols <= 200; $cols++) {
                $bar = $this->bar($this->chat($cols));

                $rows = (array) preg_split('/\r\n|\r|\n/', $bar);
                self::assertCount(
                    1,
                    $rows,
                    sprintf('cols=%d command=%s: the bar is %d physical rows', $cols, $command, \count($rows)),
                );

                $width = Width::of($bar);
                self::assertLessThanOrEqual(
                    max($baselines[$cols], $cols),
                    $width,
                    sprintf('cols=%d command=%s: bar grew to %d', $cols, $command, $width),
                );
            }
        }
    }

    /**
     * A long line is CLIPPED WITH AN ELLIPSIS rather than dropped or cut
     * silently, the way {@see \SugarCraft\Crush\Hooks\ScriptHook::clip()}
     * announces its own truncation.
     */
    public function testALongLineIsClippedAndSaysSo(): void
    {
        self::install('echo ' . str_repeat('x', 400));
        $bar = $this->bar($this->chat(120));

        self::assertStringEndsWith('…', $bar);
        self::assertLessThanOrEqual(120, Width::of($bar));
    }

    /**
     * THE CLIP IS GRAPHEME-AWARE. A base character and its combining acute are
     * ONE column and must be cut together; `mb_substr()` on codepoints would
     * leave a naked U+0301 to combine with the ellipsis.
     */
    public function testTheClipCutsWholeGraphemeClustersOnly(): void
    {
        // 200 × "e + COMBINING ACUTE" — 200 columns of two-codepoint clusters.
        self::install('printf "e\\314\\201%.0s" $(seq 1 200)');
        $bar = $this->bar($this->chat(90));

        self::assertLessThanOrEqual(90, Width::of($bar));

        // THE DISCRIMINATOR: the segment is a whole number of COMPLETE
        // clusters. A codepoint-wise cut (`mb_substr()`, which
        // {@see Renderer::clipToWidth()} uses) can stop between the `e` and its
        // mark and leave a bare `e` at the end; a grapheme-wise cut cannot.
        //
        // Measured on the SEGMENT, not on the bar: the bar's own prose
        // ("Enter", "send", "menu", "exit") contributes five bare `e`s, and the
        // first draft of this assertion counted those and failed on a sound
        // clip. An ellipsis directly after an acute is likewise the CORRECT
        // output — the draft before that one asserted its absence.
        $segment = substr($bar, (int) strrpos($bar, ' · ') + \strlen(' · '));

        self::assertSame(
            1,
            preg_match('/\A(?:e\x{0301})+\x{2026}\z/u', $segment),
            'the segment is not a whole number of complete grapheme clusters: ' . bin2hex($segment),
        );
    }

    /**
     * A LINE THAT EXACTLY FILLS THE ROOM IS PAINTED WHOLE. The clip threshold
     * is `>` and not `>=` for this reason, and at db20c568 the two were
     * indistinguishable to every test here — a `>=` costs the last column of a
     * line that fits, replacing it with an ellipsis that announces a truncation
     * that did not happen.
     *
     * `$room` is DERIVED from the bar this fixture actually produces rather
     * than written down, because it is a function of the terminal width and the
     * app state: at 100 columns on this class's two-message fixture the idle bar
     * is 75 and the separator is 3, so the room is 22 — but none of those three
     * numbers belongs in the assertion.
     */
    public function testALineThatExactlyFillsTheRoomKeepsItsLastColumn(): void
    {
        $chat = $this->chat(100);

        StatusLineCommand::reset();
        $room = 100 - Width::of($this->bar($chat)) - Width::of(' · ');
        self::assertGreaterThan(1, $room, 'the fixture must leave room, or this proves nothing');

        self::install('printf "%s" ' . str_repeat('x', $room));
        $bar = $this->bar($chat);

        self::assertStringEndsWith(' · ' . str_repeat('x', $room), $bar);
        self::assertStringEndsNotWith('…', $bar, 'a line that fits was announced as truncated');
        self::assertSame(100, Width::of($bar));
    }

    /**
     * NO ROOM AT ALL MEANS NO SEGMENT. The bar is already at or past the
     * terminal width below 36 columns; the segment must simply not appear
     * rather than being clipped to one column of ellipsis.
     */
    public function testAnAlreadyFullBarGetsNoSegment(): void
    {
        $chat = $this->chat(10);
        $without = $this->bar($chat);

        self::install('echo hello');

        self::assertSame($without, $this->bar($chat));
    }

    // =====================================================================
    // Zone sentinels — the half `Sanitize::untrusted()` does not cover
    // =====================================================================

    /**
     * U+E000 / U+E001 ARE STRIPPED BEFORE THE SEGMENT IS PAINTED. They are
     * well-formed Private-Use codepoints, so the sanitiser in the runner
     * passes them through untouched; unstripped they reach `scanRoot()`'s
     * parser verbatim and either throw the frame's click zones away or
     * register attacker-chosen boxes in the hit-test registry `Chat::zoneAt()`
     * reads. The bar already carries a real `pane:menu` zone, so it is the
     * worst row to admit a forged one on.
     */
    public function testZoneSentinelsInTheCommandsOutputNeverReachTheFrame(): void
    {
        // THE BASELINE IS MEASURED, not written as a literal: the bar carries
        // its own real `pane:menu` sentinel pair, so "contains no U+E000" is
        // false of a correct bar and "contains one" is a figure that decays if
        // the bar ever marks a second region. What must hold is that the
        // SEGMENT adds none.
        StatusLineCommand::reset();
        $clean = $this->rawBar($this->chat(120));
        $opens = preg_match_all('/\x{E000}/u', $clean);
        $closes = preg_match_all('/\x{E001}/u', $clean);
        self::assertGreaterThan(0, $opens, 'the fixture must contain the bar\'s own zone, or this proves nothing');

        self::install('printf "\\356\\200\\200pane:menu\\356\\200\\201hijacked"');
        $raw = $this->rawBar($this->chat(120));

        // The command's bytes did reach the row — otherwise the strip would be
        // indistinguishable from the segment being dropped altogether.
        self::assertStringContainsString('hijacked', $raw);

        self::assertSame($opens, preg_match_all('/\x{E000}/u', $raw), 'the segment contributed an OPEN sentinel');
        self::assertSame($closes, preg_match_all('/\x{E001}/u', $raw), 'the segment contributed a CLOSE sentinel');

        // And nothing anywhere in the Private-Use block, which is also where
        // candy-core's image markers live (`ImageOverlay::MARKER_BASE` is
        // U+E000 and a marker is MARKER_BASE + id).
        $segment = substr($raw, (int) strrpos($raw, ' · ') + \strlen(' · '));
        self::assertSame(
            0,
            preg_match('/[\x{E000}-\x{F8FF}]/u', $segment),
            'a Private-Use codepoint survived into the painted segment: ' . bin2hex($segment),
        );
    }

    // =====================================================================
    // The clock
    // =====================================================================

    /**
     * The tick is declared ONLY while a command is configured. An
     * unconditional one would keep a timer waking the loop and repainting
     * forever on every launch, including the overwhelmingly common one where
     * nobody set the key — the reason `Chat::subscriptions()` returns null
     * rather than an empty `Subscriptions`.
     */
    public function testTheTickIsDeclaredOnlyWhenACommandIsConfigured(): void
    {
        StatusLineCommand::reset();
        self::assertNull($this->chat()->subscriptions());

        StatusLineCommand::configure(
            [StatusLineCommand::KEY => ['type' => 'command', 'command' => 'echo hi']],
        );

        self::assertNotNull($this->chat()->subscriptions());
    }

    /**
     * AND THE TICK CARRIES THE PERIOD AND THE ID IT IS DOCUMENTED TO CARRY.
     * Both were unasserted at db20c568: the period passed to `withTick()` could
     * be changed to 3600.0 and the subscription id to `crush.background-poll`
     * with all 12 tests here staying green — the second being exactly the
     * reconciliation-key collision the constant's own docblock exists to
     * prevent, since the runtime diffs the old subscription set against the new
     * one BY ID.
     *
     * The uniqueness half is asserted over every `*_SUBSCRIPTION` constant
     * `Chat` declares rather than against a written-down list of the other two,
     * so a fourth subscription added later is covered without anyone editing
     * this test.
     */
    public function testTheTickCarriesTheRefreshPeriodUnderAnIdNoOtherSubscriptionUses(): void
    {
        StatusLineCommand::configure(
            [StatusLineCommand::KEY => ['type' => 'command', 'command' => 'echo hi']],
        );

        $subscriptions = $this->chat()->subscriptions();
        self::assertNotNull($subscriptions);

        $all = $subscriptions->all();
        self::assertCount(1, $all, 'the idle fixture declares no other subscription');
        $tick = $all[0];

        self::assertSame(
            StatusLineCommand::REFRESH_SECONDS,
            $tick->params['seconds'] ?? null,
            'the tick period is not the runner\'s own refresh period',
        );
        self::assertInstanceOf(StatusLineTickMsg::class, ($tick->produce)());

        $ids = [];
        foreach ((new \ReflectionClass(Chat::class))->getConstants() as $name => $value) {
            if (str_ends_with($name, '_SUBSCRIPTION')) {
                $ids[$name] = $value;
            }
        }

        self::assertGreaterThanOrEqual(3, \count($ids), 'fewer ids than Chat declares, so uniqueness proves little');
        self::assertContains($tick->id, $ids, 'the tick is not using any declared subscription constant');
        self::assertCount(
            \count($ids),
            array_unique(array_values($ids)),
            'two subscriptions share a reconciliation key: ' . json_encode($ids),
        );
    }

    /**
     * And the Msg it emits is what actually runs the command — the update path,
     * never `view()`, which may not have side effects.
     */
    public function testTheTickMsgIsWhatRunsTheCommand(): void
    {
        StatusLineCommand::configure(
            [StatusLineCommand::KEY => ['type' => 'command', 'command' => 'echo ticked']],
        );
        self::assertSame('', StatusLineCommand::line());

        [$next, $cmd] = $this->chat()->update(new StatusLineTickMsg());

        self::assertSame('ticked', StatusLineCommand::line());
        self::assertInstanceOf(Chat::class, $next);
        self::assertNull($cmd);
    }

    /**
     * PAINTING IS PURE. Rendering the bar a hundred times must not run the
     * command even once — a `proc_open()` per frame is a `proc_open()` per
     * keystroke.
     */
    public function testRenderingNeverRunsTheCommand(): void
    {
        $probe = sys_get_temp_dir() . '/sugarcrush_statusline_pure_' . bin2hex(random_bytes(8));
        StatusLineCommand::configure([StatusLineCommand::KEY => [
            'type' => 'command',
            'command' => 'echo x >> ' . escapeshellarg($probe) . '; echo painted',
        ]]);
        StatusLineCommand::refresh();

        $chat = $this->chat();
        for ($i = 0; $i < 100; $i++) {
            $this->bar($chat);
        }

        self::assertSame(1, substr_count((string) file_get_contents($probe), "\n"));

        unlink($probe);
    }

    // =====================================================================
    // P4.S3 — the cache-health readout (hit rate + age)
    // =====================================================================

    /**
     * A fixed epoch anchor for the fixtures below (2025-10-09T08:53:20Z, a
     * date comfortably in the past). Every age assertion passes an explicit
     * clock to {@see Renderer::cacheIndicator()} through the reflection seam
     * so the byte-exact strings cannot drift with the wall; the anchor keeps
     * even the REAL-clock paths (whole-frame rendering) deterministic in the
     * only part they assert on — the rate, never the age digits.
     */
    private const ANCHOR = 1_760_000_000;

    /**
     * A two-message chat whose newest assistant reply carries a fully
     * reported prompt-side split: input 200 + cacheRead 7800 + creation 0 =
     * prompt 8000, so the rate is 97.5 → 98%, and the report is 42 s old
     * measured at ANCHOR.
     */
    private function cacheChat(int $cols = 100, ?Usage $usage = null, ?int $replyAt = null): Chat
    {
        $replyAt ??= self::ANCHOR - 42;

        return (new Chat(
            history: [
                Message::user('hello', self::ANCHOR - 100),
                Message::assistant('Sure.', $replyAt)->withUsage($usage ?? Usage::new(
                    8100,
                    0.05,
                    200,
                    100,
                    7800,
                    0,
                )),
            ],
            backend: new EchoBackend(),
        ))->withSize($cols, 30);
    }

    /**
     * The cache fixture built through the PRODUCTION billing path: a settled
     * provider reply delivered to `update()`, which is the ONLY route into
     * `Chat::accountUsage()` — spend is tracker-derived, so the `history:`
     * shortcut of {@see cacheChat()} can never light the spend piece and a
     * fixture for the spend+cache assembly arm has to be billed, not seeded.
     *
     * Deliberately distinct in name from `StatusBarSpendTest::billed()`:
     * `DuplicatedTestHelperDriftTest` compares same-named helpers across
     * files, and this one is NOT that helper — it carries history, size and
     * cap too, because the bar under test assembles four pieces, not one.
     */
    private function billedChat(?Usage $usage, ?float $cap, int $cols): Chat
    {
        $chat = (new Chat(
            history: [Message::user('hello', self::ANCHOR - 100)],
            backend: new EchoBackend(),
            maxCostUsd: $cap,
        ))->withSize($cols, 30);

        [$chat] = $chat->update(new AssistantMsg(
            Message::assistant('Sure.', self::ANCHOR - 42)->withUsage($usage),
        ));

        return $chat;
    }

    /** The cache segment exactly as painted, for an explicit room and clock. */
    private function cacheSegment(Chat $chat, int $room = 60, ?float $now = null): string
    {
        return (string) (new \ReflectionMethod(Renderer::class, 'cacheIndicator'))
            ->invoke(null, $chat, $room, $now);
    }

    /**
     * The transcript's identity, not its text: one entry per message, so an
     * appended, PREPENDED, REPLACED or dropped message all move it.
     *
     * @return list<int>
     */
    private function transcriptSignature(Chat $chat): array
    {
        return array_map('spl_object_id', $chat->history);
    }

    /**
     * THE SPEND+CACHE ASSEMBLY ARM, the priority claim between the two
     * optional pieces, and cache-vs-`statusLine` coexistence — none of which
     * the seeded fixtures can reach, because spend reads the tracker and the
     * tracker is fed only by `update()` (see {@see billedChat()}).
     *
     * THREE halves, one fixture family (bucketed Usage `200 + 7800 + 0 = 8000`
     * prompt at 98 %, cap `5.0` so both the cap-form spend AND the cache piece
     * claim columns):
     *
     *  1. at 120 columns both pieces paint, SPEND BEFORE CACHE — the literal
     *     `$context · $spend · $cache · $processing` rebuild, and at 100 the
     *     cache piece is the one that vanishes while the cap-form spend
     *     survives: "fitted below spend" made concrete from both sides.
     *  2. the no-deepening sweep the spend tests use, cols 4..200, asserted as
     *     a DELTA against the same billed chat with the buckets UNREPORTED
     *     (spend identical, cache absent), because the bar is legitimately
     *     over-wide below 36 columns. This is what pins the `- Width::of
     *     ($separator)` in the cache room at `Renderer::renderStatusBar()`:
     *     deleting it inflates the room by 3, a too-wide cache form is chosen,
     *     and THIS sweep is the only test in the file that reddens (MEASURED —
     *     every pre-existing test stayed green under that mutation).
     *  3. a configured `statusLine` command still paints AFTER the cache
     *     piece — the assembled bar reaching `withStatusLineCommand()` with
     *     both optional pieces live.
     */
    public function testABilledSessionPaintsSpendThenCacheAndNeverDeepensTheBarAtAnyWidth(): void
    {
        StatusLineCommand::reset();
        $bucketed = Usage::new(8100, 0.05, 200, 100, 7800, 0);
        $totalOnly = Usage::new(8100, 0.05);

        $bar = $this->barAt($this->billedChat($bucketed, 5.0, 120), self::ANCHOR);
        self::assertStringContainsString('$0.0500 of $5.0000 cap', $bar, 'the cap form of the spend piece must survive next to the cache piece');
        self::assertStringContainsString('98% cache · 42s', $bar, 'the widest cache form must fit at 120');
        self::assertStringContainsString('Enter to send', $bar, 'and the mandatory hint rides along — this is the four-piece bar');
        self::assertLessThan(
            (int) strpos($bar, '98% cache'),
            (int) strpos($bar, '$0.0500'),
            'spend is cache\'s senior: it paints first on the row, before the cache piece',
        );

        $narrow = $this->barAt($this->billedChat($bucketed, 5.0, 100), self::ANCHOR);
        self::assertStringContainsString('$0.0500 of $5.0000 cap', $narrow, 'the senior keeps its columns at 100');
        self::assertStringNotContainsString('98% cache', $narrow, 'the junior is the piece that gives them up');

        for ($cols = 4; $cols <= 200; $cols++) {
            $width = Width::of($this->barAt($this->billedChat($bucketed, 5.0, $cols), self::ANCHOR));
            $baseline = Width::of($this->barAt($this->billedChat($totalOnly, 5.0, $cols), self::ANCHOR));
            self::assertLessThanOrEqual(
                max($baseline, $cols),
                $width,
                sprintf('cols=%d: the cache piece deepened the bar from %d to %d', $cols, $baseline, $width),
            );
        }

        self::install('echo on-branch');
        $wide = $this->barAt($this->billedChat($bucketed, 5.0, 140), self::ANCHOR);
        self::assertStringEndsWith(
            '98% cache · 42s · Enter to send · Ctrl+P menu · /exit or ^C to quit · on-branch',
            $wide,
            'cache, hint, then the status line — the command paints below ALL fitted pieces',
        );
        self::assertLessThanOrEqual(140, Width::of($wide), 'five pieces and still inside the terminal');
    }

    /**
     * THE TWO GUARDS `cacheIndicator()`'s docblock enumerates as "each one
     * pinned by a test" that no test actually touched at review-1: the age
     * clamp at 0 and `formatCacheAge()`'s hour/day rungs. The fixture's
     * `?int $replyAt` knob is what makes each reachable — it was dead until
     * this test, and every expectation below is the docblock formula applied
     * by hand at ANCHOR.
     */
    public function testTheAgeClampsAtZeroAndLaddersUpThroughHoursAndDays(): void
    {
        // A reply stamped 120 s in the FUTURE (the NTP step-back shape the
        // clamp bullet names): floor of a negative is negative, and the clamp
        // must paint the truthful 0, never '-120s'. If the knob were ignored
        // this fixture would render the default '42s' — so the string pins
        // the knob, the clamp, and the sign handling at once.
        self::assertSame(
            '98% cache · 0s',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR + 120), 60, (float) self::ANCHOR),
        );

        // Hour rung: 3630 s → intdiv 3630/3600 = 1. Flooring, not rounding:
        // an age rounded UP would announce an expiry a full 59.5 minutes early.
        self::assertSame(
            '98% cache · 1h',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 3600 - 30), 60, (float) self::ANCHOR),
        );

        // Day rung: 90000 s → intdiv 90000/86400 = 1, and NOT '25h'.
        self::assertSame(
            '98% cache · 1d',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 90000), 60, (float) self::ANCHOR),
        );

        // The six boundary legs — each value sits ADJACENT to a rung threshold,
        // so nudging any `<` boundary reddens exactly one of them (the three
        // rungs above only bound each threshold between sample neighbours).
        self::assertSame(
            '98% cache · 59s',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 59), 60, (float) self::ANCHOR),
            'last second before the minute boundary',
        );
        self::assertSame(
            '98% cache · 1m',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 60), 60, (float) self::ANCHOR),
            'exactly on the minute boundary → intdiv(60,60)=1',
        );
        self::assertSame(
            '98% cache · 59m',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 3599), 60, (float) self::ANCHOR),
            'last minute before the hour boundary → intdiv(3599,60)=59',
        );
        self::assertSame(
            '98% cache · 1h',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 3600), 60, (float) self::ANCHOR),
            'exactly on the hour boundary → intdiv(3600,3600)=1',
        );
        self::assertSame(
            '98% cache · 23h',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 86399), 60, (float) self::ANCHOR),
            'last hour before the day boundary → intdiv(86399,3600)=23',
        );
        self::assertSame(
            '98% cache · 1d',
            $this->cacheSegment($this->cacheChat(replyAt: self::ANCHOR - 86400), 60, (float) self::ANCHOR),
            'exactly on the day boundary → intdiv(86400,86400)=1',
        );
    }

    /**
     * DONE-WHEN HALF A — the snapshot test. Exact rendered bytes, not a
     * non-null shape: 7800/8000 → "98%", age 42 s → "42s", joined in the
     * widest form, and the same bytes land inside the assembled bar between
     * the context readout and the processing hint (never inside the
     * transcript — half B is its own test below).
     */
    public function testTheCacheSegmentRendersTheReportedRateAndAgeIntoTheBar(): void
    {
        $chat = $this->cacheChat();

        self::assertSame(
            '98% cache · 42s',
            $this->cacheSegment($chat, 60, (float) self::ANCHOR),
            'round(7800/8000*100)=98 over prompt = cacheRead+creation+input, age = ANCHOR - (ANCHOR-42)',
        );

        $bar = $this->barAt($chat, self::ANCHOR);
        self::assertSame(1, substr_count($bar, '98% cache · 42s'), 'the segment paints exactly once');
        self::assertLessThan(
            (int) strpos($bar, 'Enter to send'),
            (int) strpos($bar, '98% cache'),
            'the readout belongs to the readouts block, before the processing hint',
        );
    }

    /**
     * THE FORMULA, exercised by moving one bucket — the third mandatory
     * §111 experiment as a standing test. Each expected string below is the
     * docblock formula applied by hand, and the last two are the polarities:
     * a measured MISS renders, an UNREPORTED split renders nothing (null is
     * not a zero — see {@see Usage}).
     */
    public function testChangingOneBucketMovesTheRenderedRateExactlyAsTheFormulaSays(): void
    {
        $now = (float) self::ANCHOR;

        // cacheCreation 0 → 6000: prompt 8000 → 14000; 7800/14000 = 55.71 → 56.
        $withCreation = $this->cacheChat(usage: Usage::new(14100, 0.05, 200, 100, 7800, 6000));
        self::assertSame('56% cache · 42s', $this->cacheSegment($withCreation, 60, $now));

        // cacheRead 7800 → 0 with a reported prompt of 200: a TOTAL MISS, and
        // it renders — this figure is the Phase-3/Phase-10 regression signal.
        $miss = $this->cacheChat(usage: Usage::new(300, 0.01, 200, 100, 0, 0));
        self::assertSame('0% cache · 42s', $this->cacheSegment($miss, 60, $now));

        // Every bucket unreported — what every live provider reports today:
        // no segment at all, never a 0%.
        $silent = $this->cacheChat(usage: Usage::new(300, 0.01));
        self::assertSame('', $this->cacheSegment($silent, 60, $now));

        // A prompt of exactly zero measured tokens has no cache share; 0/0 is
        // not a rate the bar may assert.
        $zeroPrompt = $this->cacheChat(usage: Usage::new(5, 0.0, 0, 0, 0, 0));
        self::assertSame('', $this->cacheSegment($zeroPrompt, 60, $now));
    }

    /**
     * The readout walks back to the NEWEST report that can carry one, and
     * BOTH numbers then belong to that same report — the age is the age of
     * the report shown, not of the newest message on the row. When a newer
     * entry carries its OWN full report it outranks an older one, so the walk
     * direction (newest-first) is what the first leg below pins: a forward
     * walk breaks at the oldest usable report and lands on '50% cache · 1m'
     * instead.
     */
    public function testTheSegmentCarriesTheNewestUsableReportNotTheNewestMessage(): void
    {
        // Older: the full split — 1000 of (1000+0+1000) = 50%.
        $fullReport = Message::assistant('full report', self::ANCHOR - 100)->withUsage(
            Usage::new(2050, 0.02, 1000, 50, 1000, 0),
        );
        // Middle: a total only — says nothing about the cache, skipped.
        $totalOnly = Message::assistant('total only', self::ANCHOR - 1)->withUsage(Usage::new(900, 0.01));
        // Newest: a SECOND full report that stamps over the older one — 300 of
        // (300+700+200) = 25%, age 5s — the mid-session invalidator the
        // docblock's "newest usable" reasoning names as the whole point.
        $invalidated = Message::assistant('invalidated', self::ANCHOR - 5)->withUsage(
            Usage::new(1200, 0.01, 200, 0, 300, 700),
        );

        $withNewer = (new Chat(
            history: [Message::user('hello', self::ANCHOR - 200), $fullReport, $totalOnly, $invalidated],
            backend: new EchoBackend(),
        ))->withSize(100, 30);

        // First leg — pins the reverse walk. The newest usable report wins over
        // the older one: 25% at age 5s, NOT the 50%/1m the forward walk would
        // break at first.
        self::assertSame('25% cache · 5s', $this->cacheSegment($withNewer, 60, (float) self::ANCHOR));

        // Second leg — with the newer report removed (a separate fixture, not a
        // mutation of the first), the walk steps past the total-only entry and
        // lands on the older full one. Age 100 s from the reporting call — NOT
        // 1 s from the newest message: an implementation that took the timestamp
        // from the wrong end of the walk lands on '1s' and fails this string.
        $olderOnly = (new Chat(
            history: [Message::user('hello', self::ANCHOR - 200), $fullReport, $totalOnly],
            backend: new EchoBackend(),
        ))->withSize(100, 30);

        self::assertSame('50% cache · 1m', $this->cacheSegment($olderOnly, 60, (float) self::ANCHOR));
    }

    /**
     * Forms are tried widest-first against the room the row's seniors left,
     * and the piece gives up its columns in the same order the other
     * readouts do — age first, segment entirely last. A fitting failure must
     * NEVER widen the un-wrappable bar by one column, hence no last resort.
     */
    public function testTheCacheSegmentDegradesThenVanishesAsTheRoomNarrows(): void
    {
        $chat = $this->cacheChat();
        $now = (float) self::ANCHOR;

        self::assertSame(15, Width::of('98% cache · 42s'), 'the fixture form, with its width');
        self::assertSame('98% cache · 42s', $this->cacheSegment($chat, 15, $now), 'a form fits at exactly its own width');
        self::assertSame('98% cache', $this->cacheSegment($chat, 14, $now), 'the age is the first thing given up');
        self::assertSame('98% cache', $this->cacheSegment($chat, 9, $now));
        self::assertSame('', $this->cacheSegment($chat, 8, $now), 'no room: no segment, never an overflow');
        self::assertSame('', $this->cacheSegment($chat, 0, $now));
    }

    /**
     * THE HARD CONSTRAINT, string half: the widget renders into the status
     * line LINE and nowhere else in the frame. Claude Code's /context billed
     * ~1.6k tokens per invocation by painting its grid into the conversation;
     * the same bug in this frame would make the readout appear in a content
     * line as well as the bar. Counted over the WHOLE painted frame: exactly
     * one occurrence, and it is on the bar (the frame's last line).
     *
     * The needle is the clock-INDEPENDENT part of the segment: this path runs
     * the real `microtime(true)` (render() has no clock seam), so the AGE
     * digits drift by definition — which is precisely why the assertion is
     * structured on the rate. The liveness guard below is what stops "appears
     * exactly once" degenerating into "never appears at all" (rule 16): the
     * same needle is asserted PRESENT on the bar line first.
     */
    public function testTheReadoutAppearsExactlyOnceInTheFrameAndOnlyOnTheBarLine(): void
    {
        $frame = Renderer::render($this->cacheChat());

        $lines = explode("\n", rtrim($frame, "\n"));
        $bar = (string) end($lines);
        self::assertStringContainsString(
            '98% cache',
            $bar,
            'the widget must actually be live in this fixture, or the count below measures a dead renderer',
        );

        self::assertSame(
            1,
            substr_count($frame, '98% cache'),
            'the readout reached the transcript as well as the bar — the /context per-call tax this step exists to prevent',
        );
    }

    /**
     * DONE-WHEN HALF B, its own test: twelve ticks and their renders add
     * EXACTLY ZERO messages to the session transcript. The instruments are
     * layered, not interchangeable.
     *
     * WHAT THIS SAID (through fix-5): the per-tick pins on the arm's
     * contract — null `Cmd`, same Chat instance — were the FIRST reds, and
     * the lead's E2b artifact showed exactly that (the plant fell to
     * "tick #0 returned a different Chat instance", never to the closing
     * signature line); the signature comparison "can only trail". WHAT IS
     * TRUE NOW (fix-8 A): a per-tick signature comparison runs INSIDE the
     * loop, ahead of both arm-contract pins, so the named zero-transcript
     * claim takes its own first red — MEASURED at fix-8, the same E2b-shape
     * plant now falls to "tick #0 moved the transcript", and deleting the
     * new comparison restores the old fall-to-identity behaviour. WHY THE
     * LAYERING STILL EARNS ITS PLACE: the closing comparison remains the
     * whole-loop belt, the per-tick pins remain the arm-contract claims a
     * same-transcript instance swap (the plant the identity pin alone
     * catches) still reddens, and the AssistantMsg control below is still
     * what fires the signature machinery's positive half (§16.8 rule 16 /
     * RR4-F2).
     *
     * THE LOOP IS THE REAL IDLE LOOP, not a synthetic stand-in for one:
     * `Chat::subscriptions()` arms the status tick only while a `statusLine`
     * command is CONFIGURED, so a command is installed here and the
     * subscriptions guard below proves the fixture actually arms it — with no
     * command configured the tick arm never fires in that session, and
     * "twelve ticks" would be twelve hand-delivered messages nothing on the
     * live path sends.
     *
     * BOTH HALVES OF THE UPDATE RESULT ARE CAPTURED. A `Cmd` is this
     * architecture's normal route from a side effect to a transcript message
     * — a status-path update that quietly returned one would bill the very
     * tax this step exists to prevent, and a test that destructured only the
     * model would not see it. The arm's identity contract (`returns $this,
     * null Cmd`) is pinned per tick as well.
     *
     * THE PLANT ACCOUNT, stated plainly rather than left to the evidence
     * packet: this test's mutation plant (the lead's E2b) was made in the TICK
     * ARM — that arm is the only seam on the status path that can add a
     * message. WHAT THIS SAID (through fix-5): the render-path half of the
     * claim (a string reaching the transcript) was planted and caught ONLY by
     * the sibling frame test
     * {@see testTheReadoutAppearsExactlyOnceInTheFrameAndOnlyOnTheBarLine}.
     * WHAT IS TRUE NOW (fix-8 B): the M9-shape plant reddens BOTH — this test
     * carries its own painted-transcript scan, with the bar line of the same
     * frame as its known-positive half through the same scanner (rule 16: a
     * sibling test is a separately deletable unit, and the hard constraint
     * should not rest solely on one). WHY THE DIVISION STILL EARNS ITS PLACE:
     * MESSAGE-absence still cannot be proved through painting — a paint has no
     * message to grow — so the tick-arm plant remains the model-side evidence
     * and the frame test remains the independent frame-side belt.
     *
     * The control is the half that makes this a test rather than a tautology
     * (§16.8 rule 16, RR4-F2): the SAME signature machinery must notice a
     * real transcript-growing operation — settling a provider reply — by
     * exactly one, in the same test, or an absence assertion here would stay
     * green against a dead instrument.
     */
    public function testPaintingAndTickingTheCacheReadoutAddZeroTranscriptMessages(): void
    {
        $chat = $this->cacheChat();

        // Live-widget guard: what is measured below is absence, so first prove
        // the widget actually fires on this fixture.
        self::assertSame('98% cache · 42s', $this->cacheSegment($chat, 60, (float) self::ANCHOR));

        // Make the ticks LIVE, not hand-fed: the subscription exists only in
        // this configuration (see the docblock), and painting must not run
        // the command — that separation is testRenderingNeverRunsTheCommand.
        self::install('echo hi');
        self::assertNotNull(
            $chat->subscriptions(),
            'the fixture arms no status tick, so the twelve updates below would be a synthetic loop',
        );

        $before = $this->transcriptSignature($chat);
        self::assertCount(2, $before, 'the fixture is two messages; the control below bounds the signature to this domain');

        $next = $chat;
        for ($i = 0; $i < 12; $i++) {
            $this->barAt($next, self::ANCHOR);
            Renderer::render($next);
            $tickTarget = $next;
            [$next, $cmd] = $next->update(new StatusLineTickMsg());
            // Fix-8 A (review-7 M3): the NAMED claim gets its own first red.
            // Before this, a transcript-growing plant fell to the identity pin
            // — "tick #0 returned a different Chat instance" — because the
            // closing signature line sat behind the loop and PHPUnit aborts at
            // the first failure; the zero-transcript assertion itself never
            // reddened. Checking the signature per tick, BEFORE the
            // arm-contract pins, makes the plant fall to the claim it violates.
            // MEASURED: with this line the plant's first red is
            // 'tick #0 moved the transcript'; with it deleted, the plant falls
            // back to the identity pin. The closing comparison below stays as
            // the whole-loop belt — never weaken, layer.
            self::assertSame(
                $before,
                $this->transcriptSignature($next),
                'tick #' . $i . ' moved the transcript — the zero-transcript claim itself, ahead of the arm-contract pins',
            );
            self::assertNull(
                $cmd,
                'tick #' . $i . ' returned a Cmd — the status path\'s normal route into the transcript',
            );
            self::assertSame(
                $tickTarget,
                $next,
                'tick #' . $i . ' returned a different Chat instance; the arm is documented to return $this',
            );
        }

        self::assertSame($before, $this->transcriptSignature($next), 'twelve ticks and their renders moved the transcript');

        // Fix-8 B (review-7 M9): the STRING half of the hard constraint, guarded
        // by THIS test's own scanner. At fix-5 the plant that echoed the needle
        // into painted transcript content reddened EXACTLY the sibling frame
        // test — and §16.8 rule 16 calls a sibling test a separately deletable
        // unit, so the named hard-constraint test has to see the tax itself.
        // The bar line of the SAME frame is the known-positive half through the
        // SAME scanner: without it, the transcript-side zero could be the
        // identical silence of a dead scan. MEASURED: the M9-shape plant now
        // reddens this test AND the frame test (2 reds); with these two lines
        // deleted it falls back to the frame test alone (1 red).
        $frameLines = explode("\n", rtrim(Renderer::render($next), "\n"));
        $barLine = (string) array_pop($frameLines);
        self::assertStringContainsString(
            '98% cache',
            $barLine,
            'the needle is live on the bar line of this very frame — without it the transcript-side zero below could be the silence of a dead scanner (§16.8 rule 16)',
        );
        self::assertStringNotContainsString(
            '98% cache',
            implode("\n", $frameLines),
            'the readout reached painted transcript content — the /context per-call tax, now caught in the zero-transcript test itself, not only in the sibling frame test',
        );

        [$grown] = $next->update(new AssistantMsg(Message::assistant('a settled reply')));
        $grownSignature = $this->transcriptSignature($grown);
        self::assertSame(
            \count($before) + 1,
            \count($grownSignature),
            'the KNOWN-POSITIVE CONTROL failed: a real transcript append does not move this signature, '
            . 'so the zero-transcript assertion above could not notice one either',
        );
        self::assertSame(
            $before,
            \array_slice($grownSignature, 0, \count($before)),
            'the control grew the transcript by APPENDING, not by rewriting what was already there',
        );
    }

    /**
     * THE SAME-COUNT REPLACE SHAPE, which {@see transcriptSignature()}'s
     * docblock claims moves the signature but no test had ever shown. The
     * zero-transcript test's own known-positive control ({@see
     * testPaintingAndTickingTheCacheReadoutAddZeroTranscriptMessages()})
     * exercises APPEND only: a signature that keyed on NOTHING BUT COUNT
     * would sail through every assertion in the file while a future status-
     * path arm rewrote an entry in place — e.g. a tick arm that refreshed a
     * settled reply's Message object — and the absence assertion would
     * measure its own blindness. This is the REPLACE half of rule 16's
     * known-positive bar for that claim.
     *
     * The plant replaces history[1] with a DIFFERENT Message instance at an
     * unchanged count — constructed the way {@see cacheChat()} constructs it
     * (a fresh assistant Message), through the same public Chat constructor,
     * so no private seam is needed: what matters to an identity signature is
     * the instance, and a replace-by-construction is exactly the shape the
     * claim names. PREPEND and DROP stay WITHOUT a control (measured: no
     * plant exists for them anywhere in the file) — a known gap, recorded in
     * the travel ledger; this step adds the REPLACE half only.
     *
     * LOAD-BEARING, measured: under a planted count-only signature
     * (transcriptSignature returning `array_fill(0, count($chat->history), 1)`)
     * and under a planted position-keyed one (`array_keys($chat->history)` —
     * length-sensitive, instance-blind), THIS method is the ONLY test in the
     * file that reddens, both times via the assertNotSame below; the
     * zero-transcript test stayed green under both plants (its ticks never move
     * the transcript, and its append control checks the count plus a strict
     * prefix, both of which a count-only signature passes vacuously). Deleting
     * this method while keeping the count-only plant reverted the red entirely
     * (22/4113 green — the blindness had no other witness).
     * Fix-3 pinned the before side too: the fixture assertCount lands on
     * $before and the SAME-COUNT guard reads \count($before), so a grown
     * cacheChat() reddens the control instead of degrading it to
     * REPLACE+DROP behind a literal-2 expectation.
     */
    public function testAReplacedTranscriptEntryMovesTheSignatureAtAnUnchangedCount(): void
    {
        $chat = $this->cacheChat();
        $before = $this->transcriptSignature($chat);
        self::assertCount(
            2,
            $before,
            'the fixture is two messages; the plant swaps exactly one of them',
        );

        // SAME-COUNT REPLACE plant: history[1] becomes a different Message
        // instance (the entry identity changes; the count does not).
        $replaced = (new Chat(
            history: [
                $chat->history[0],
                Message::assistant('Replaced.', self::ANCHOR - 42),
            ],
            backend: new EchoBackend(),
        ))->withSize(100, 30);
        $after = $this->transcriptSignature($replaced);

        self::assertCount(
            \count($before),
            $after,
            'the plant must be SAME-COUNT or this stops being a REPLACE control',
        );
        self::assertNotSame(
            $before,
            $after,
            'the KNOWN-POSITIVE REPLACE CONTROL failed: swapping one transcript entry for a different instance at an unchanged count did not move this signature, so the zero-transcript test could not notice a status-path arm that rewrote an entry either',
        );
        self::assertSame(
            $before[0],
            $after[0],
            'the untouched first entry must read the same in both signatures — a difference here means the plant disturbed more than the replaced slot',
        );
    }
}
