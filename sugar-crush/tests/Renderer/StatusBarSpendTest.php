<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Usage;

/**
 * The status bar's spend segment (crush_code.md Phase 5 item 7), and the one
 * invariant it must not break: **the bar is the frame's last line and must not
 * wrap.** A wrapped bar makes the frame one physical row taller, which is the
 * absolute-`cursorTo()` row collision `Renderer::render()`'s tail clip exists to
 * prevent. That is frame corruption, not a cosmetic overflow.
 *
 * The widths are ASSERTED here rather than described in `Renderer.php`'s comment,
 * because a prose figure in that comment has gone stale three consecutive times
 * ("73-94", then "54 at every width below 79 and 75 at 79 and above", then a 54
 * floor over a domain its fixture could not reach). A number a test reads back
 * cannot rot quietly.
 */
final class StatusBarSpendTest extends TestCase
{
    /** Widest column swept. Well past any real terminal, and past the widest form. */
    private const MAX_COLS = 200;

    private function chat(int $cols = 100, ?float $cap = null): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            maxCostUsd: $cap,
        ))->withSize($cols, 30);
    }

    /** A chat whose provider has reported $cost of spend, billed the production way. */
    private function billed(Chat $chat, float $cost = 0.0123, int $tokens = 900): Chat
    {
        [$next] = $chat->update(new AssistantMsg(
            Message::assistant('done')->withUsage(Usage::new($tokens, $cost)),
        ));

        return $next;
    }

    /** The bar as it is painted, sentinels off — the columns it actually occupies. */
    private function bar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat);

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }

    private function width(Chat $chat): int
    {
        return Width::of($this->bar($chat));
    }

    // =====================================================================
    // What the bar is, measured
    // =====================================================================

    /**
     * The figure `Renderer::renderStatusBar()`'s comment no longer states: the
     * idle bar's width is a FUNCTION of the terminal width, taking four values,
     * and the widths and their thresholds are read back here.
     *
     * It steps rather than growing continuously because each variable segment
     * picks the widest FORM that fits (see `contextIndicator()`), so the bar
     * jumps when a form becomes affordable.
     */
    public function testTheIdleBarTakesExactlyTheseWidthsAtEveryTerminalWidth(): void
    {
        $steps = [];
        $previous = null;
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $width = $this->width($this->chat($cols));
            if ($width !== $previous) {
                $steps[$cols] = $width;
                $previous = $width;
            }
        }

        $this->assertSame(
            [1 => 54, 62 => 62, 65 => 65, 75 => 75],
            $steps,
            'the idle bar, with no cap and nothing reported: four widths, each starting at the column '
            . 'where the next context-readout form becomes affordable. Monotonic, so it never widens as '
            . 'the terminal narrows',
        );
    }

    /**
     * With no cap and nothing reported the bar carries NO spend segment — which
     * is every offline run, every `$SUGARCRUSH_BACKEND_CMD` run, and every
     * streamed session whose provider sends no usage block. `$` appears nowhere
     * else on the bar, so its absence is the check.
     */
    public function testAnUnreportedUncappedSessionGetsNoSpendSegmentAtAll(): void
    {
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $this->assertStringNotContainsString(
                '$',
                $this->bar($this->chat($cols)),
                "a spend readout appeared at {$cols} columns on a session with nothing to report",
            );
        }
    }

    /**
     * And the corollary that makes the previous test worth having: the same
     * fixture, once billed, DOES get one. Otherwise the assertion above would
     * pass just as well against a segment that never renders.
     */
    public function testTheSameFixtureOnceBilledDoesGetOne(): void
    {
        $this->assertStringContainsString('$0.0123', $this->bar($this->billed($this->chat(120))));
    }

    // =====================================================================
    // The invariant
    // =====================================================================

    /**
     * THE invariant. Over every terminal width and every spend state, adding the
     * spend segment may not push the bar past the terminal — and where the bar
     * was already over (which it is at absurdly narrow widths, a pre-existing
     * property), it may not push it any further over than it already was.
     *
     * Stated as one comparison against the SAME state without the segment, so it
     * holds whatever the rest of the bar happens to measure.
     */
    public function testTheSpendSegmentNeverWidensTheBarBeyondTheTerminalAtAnyWidth(): void
    {
        $appearances = 0;
        foreach ($this->spendStates() as $label => $make) {
            for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
                $withSegment = $this->bar($make($cols));
                $without = $this->bar($this->chat($cols));
                $widthWith = Width::of($withSegment);

                if (str_contains($withSegment, '$')) {
                    ++$appearances;
                }

                $this->assertLessThanOrEqual(
                    max($cols, Width::of($without)),
                    $widthWith,
                    "{$label} at {$cols} columns: the bar is the one line the renderer never truncates, "
                    . 'so the spend segment must be dropped rather than allowed to overflow',
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $appearances,
            'fixture: the segment has to actually render somewhere, or the sweep above proves nothing',
        );
    }

    /**
     * The tighter half of the same claim: whenever the bar WITHOUT the segment
     * fits the terminal, the bar with it fits too. This is what rules out the
     * plausible bug of sizing the segment against the terminal instead of against
     * the room the rest of the row left.
     */
    public function testWhereverTheBarFitsWithoutTheSegmentItFitsWithIt(): void
    {
        foreach ($this->spendStates() as $label => $make) {
            for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
                if ($this->width($this->chat($cols)) > $cols) {
                    continue; // already over before the segment existed
                }
                $this->assertLessThanOrEqual(
                    $cols,
                    $this->width($make($cols)),
                    "{$label} at {$cols} columns: the segment made a fitting bar overflow",
                );
            }
        }
    }

    /**
     * A scrolled transcript's position readout outranks the spend segment: it is
     * transient and only shown while the newest output is off-screen, which makes
     * it the more urgent of the two. Its widest form is reserved before the spend
     * segment is sized, so the segment can never crowd it off the row.
     */
    public function testTheScrollReadoutIsNeverCrowdedOffTheRowByTheSpendSegment(): void
    {
        $long = [];
        for ($i = 0; $i < 60; $i++) {
            $long[] = Message::user("question {$i}");
            $long[] = Message::assistant("answer {$i}");
        }

        $checked = 0;
        for ($cols = 60; $cols <= self::MAX_COLS; $cols++) {
            $chat = (new Chat(history: $long, backend: new EchoBackend(), maxCostUsd: 5.0))
                ->withSize($cols, 20);
            $chat = $this->billed($chat)->withScrollOffset(5);
            $chat->view(); // records this frame's overflow, which the readout reads

            $bar = $this->bar($chat);
            if (!str_contains($bar, '$')) {
                continue;
            }
            ++$checked;
            // The scroll readout is PREPENDED, so it owns the start of the row.
            $this->assertStringStartsWith(
                '↑',
                $bar,
                "at {$cols} columns the spend segment rendered but the scroll readout did not, "
                . 'which is the priority order inverted',
            );
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'fixture: at least one width has to render BOTH readouts, or this test compares nothing',
        );
    }

    // =====================================================================
    // What it says
    // =====================================================================

    /**
     * Widest-first, exactly as `contextIndicator()` does it: the informative form
     * at a wide terminal, a compact one when the row tightens, then nothing.
     * Derived by sweeping rather than by naming three magic column counts.
     */
    public function testTheFormsDegradeWidestFirstAsTheRowTightens(): void
    {
        $seen = [];
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $bar = $this->bar($this->billed($this->chat($cols, cap: 5.0)));
            if (preg_match('/(\$[\d.?]+(?: of \$[\d.]+ cap|\/\$[\d.]+)?)/', $bar, $m) === 1) {
                $seen[$m[1]] = true;
            }
        }

        $this->assertSame(
            ['$0.0123', '$0.0123/$5.0000', '$0.0123 of $5.0000 cap'],
            array_keys($seen),
            'three forms, narrowest reached first as the sweep widens - and no fourth spelling',
        );
    }

    /** With no cap there is only the one form; there is no second figure to compare against. */
    public function testWithNoCapThereIsOnlyTheBareFigure(): void
    {
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $bar = $this->bar($this->billed($this->chat($cols)));
            if (!str_contains($bar, '$')) {
                continue;
            }
            $this->assertStringContainsString('$0.0123', $bar);
            $this->assertStringNotContainsString(' cap', $bar);
            $this->assertSame(1, substr_count($bar, '$'), "two dollar figures at {$cols} columns with no cap");
        }
    }

    /**
     * `$?` — an unreported spend under a cap. The cap is inert in that state
     * (`Chat::spendCapRefusal()` fails open on an unreported session), and a user
     * who typed `/budget 5` is entitled to know the guard they asked for is not
     * measuring anything. `$0.0000 of $5.0000` would have claimed the opposite.
     */
    public function testACapOverAnUnreportedSessionReadsAsUnknownAndNotAsZero(): void
    {
        $bar = $this->bar($this->chat(140, cap: 5.0));

        $this->assertStringContainsString('$? of $5.0000 cap', $bar);
        $this->assertStringNotContainsString('$0.0000', $bar);
    }

    /** And it is replaced by the real figure the moment one arrives. */
    public function testTheUnknownMarkerGivesWayToTheRealFigureOnceOneIsReported(): void
    {
        $chat = $this->chat(140, cap: 5.0);
        $this->assertStringContainsString('$?', $this->bar($chat));

        $bar = $this->bar($this->billed($chat));
        $this->assertStringNotContainsString('$?', $bar);
        $this->assertStringContainsString('$0.0123 of $5.0000 cap', $bar);
    }

    /**
     * A genuinely free provider — real tokens, zero cost — reports `$0.0000`, and
     * that is correct rather than the "unknown" case. The distinction only exists
     * because {@see Usage} keeps null and zero apart.
     */
    public function testAMeasuredFreeProviderShowsZeroRatherThanUnknown(): void
    {
        $bar = $this->bar($this->billed($this->chat(140, cap: 5.0), cost: 0.0, tokens: 900));

        $this->assertStringContainsString('$0.0000 of $5.0000 cap', $bar);
        $this->assertStringNotContainsString('$?', $bar);
    }

    /**
     * The two units stay visibly apart. The context readout is a chars/4 ESTIMATE
     * and wears a `~`; the spend readout is a provider COUNT in dollars and wears
     * a `$`. They are never summed and never share a segment — which is the whole
     * of {@see Usage}'s warning, read back off the rendered row.
     */
    public function testTheEstimateAndTheProviderCountRemainSeparateSegments(): void
    {
        $bar = $this->bar($this->billed($this->chat(160, cap: 5.0)));

        $this->assertMatchesRegularExpression(
            '/~[\d.]+K \/ [\d.]+K context \(\d+%\) · \$[\d.]+ of \$[\d.]+ cap · /u',
            $bar,
            'the ~-prefixed estimate and the $-prefixed count must be adjacent segments, not one figure',
        );
    }

    /**
     * The bar carries `markPane(Pane::Menu)`'s sentinel PAIR, and a cut between
     * the two halves makes `Scan::parse()` throw — costing the WHOLE frame its
     * click zones, not just this row's. So the segment is fitted or dropped, never
     * truncated into the assembled string.
     *
     * Asserted the only way that matters: the frame still registers its zone.
     */
    public function testTheFramesClickZonesSurviveEveryWidthWithTheSegmentPresent(): void
    {
        for ($cols = 40; $cols <= self::MAX_COLS; $cols++) {
            Renderer::scanner()->clear();
            $this->billed($this->chat($cols, cap: 5.0))->view();

            $this->assertNotNull(
                Renderer::scanner()->get('pane:menu'),
                "the status bar's menu click zone was lost at {$cols} columns",
            );
        }
    }

    /**
     * An in-flight turn draws no "Ctrl+P menu" hint and so marks no zone — the
     * narrow bar. The spend segment must fit that one too, and it is the state
     * `KEY_HELP_TOO_SMALL` / `KEY_HELP_OVER_PROMPT` are bounded against, so it is
     * swept explicitly rather than assumed.
     */
    public function testTheInFlightBarTakesTheSegmentToo(): void
    {
        for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
            $chat = $this->billed($this->chat($cols, cap: 5.0));
            [$flight] = $chat->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'x'));
            [$flight] = $flight->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Enter, ''));
            $this->assertTrue($flight->inFlight, 'fixture: the turn must really be in flight');

            $this->assertLessThanOrEqual(
                max($cols, $this->width($chat)),
                $this->width($flight),
                "the in-flight bar overflowed at {$cols} columns",
            );
        }
    }

    /**
     * The claim that keeps this feature out of a NEIGHBOUR's business.
     *
     * `Renderer::KEY_HELP_TOO_SMALL` (33 columns) and `KEY_HELP_OVER_PROMPT` (35)
     * are bounded against the narrowest bar any app state can produce, which
     * `KeyHelpTest::testTheCuesFitTheNarrowestBarAnyAppStateCanProduce()` pins at
     * **36** — a margin of 3 and 1 column respectively, and the second one is
     * load-bearing. That corpus contains no capped or billed state, so it cannot
     * see whether the spend segment eats the margin. This does.
     *
     * The floor holds because the segment is DROPPED at those widths rather than
     * squeezed in: at 1 column the row has no room for anything past the
     * mandatory two pieces. The two cue widths are read off the class rather than
     * written down, so a change to either fails here instead of drifting.
     */
    public function testACappedSessionStillCannotProduceABarNarrowerThanTheKeybindingCues(): void
    {
        $reflection = new \ReflectionClass(Renderer::class);
        $tooSmall = Width::of((string) $reflection->getConstant('KEY_HELP_TOO_SMALL'));
        $overPrompt = Width::of((string) $reflection->getConstant('KEY_HELP_OVER_PROMPT'));

        $narrowest = PHP_INT_MAX;
        foreach ($this->spendStates() as $make) {
            for ($cols = 1; $cols <= self::MAX_COLS; $cols++) {
                $chat = $make($cols);
                [$typed] = $chat->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'x'));
                [$flight] = $typed->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Enter, ''));

                $narrowest = min($narrowest, $this->width($chat), $this->width($flight));
            }
        }

        $this->assertSame(
            36,
            $narrowest,
            'the narrowest bar a capped/billed session can produce - the same floor the uncapped corpus '
            . 'measures, because the spend segment is dropped at those widths rather than squeezed in',
        );
        $this->assertGreaterThan($tooSmall, $narrowest, 'so KEY_HELP_TOO_SMALL still fits the bar it replaces');
        $this->assertGreaterThan($overPrompt, $narrowest, 'and so does KEY_HELP_OVER_PROMPT, on its 1 column');
    }

    /**
     * States the sweeps above range over. Each is built fresh per width so one
     * cannot leak into the next.
     *
     * @return array<string, callable(int): Chat>
     */
    private function spendStates(): array
    {
        return [
            'reported, no cap' => fn(int $cols): Chat => $this->billed($this->chat($cols)),
            'reported, under cap' => fn(int $cols): Chat => $this->billed($this->chat($cols, cap: 5.0)),
            'reported over a large cap' => fn(int $cols): Chat
                => $this->billed($this->chat($cols, cap: 12345.6789), cost: 9876.5432, tokens: 10_000_000),
            'unreported, capped' => fn(int $cols): Chat => $this->chat($cols, cap: 5.0),
        ];
    }
}
