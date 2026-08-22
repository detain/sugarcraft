<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\StatusLineTickMsg;

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
     * merely to exercise one. At 80 columns the idle bar is 54 and the scroll
     * readout's long form is 20, so 74 of 80 — the long form fits, and 3
     * columns are left for a status line after the ` · ` separator. A 60-column
     * status line fitted FIRST would put the bar at 117, leaving the scroll
     * readout no room at all and downgrading it (to the 4-column compact form,
     * or off the row entirely). So the assertion is on the LONG form still
     * being the one chosen, which is false under the wrong order and true under
     * the right one.
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
     */
    public function testTheSegmentNeverWidensTheBarBeyondWhatItAlreadyWasAtAnyWidth(): void
    {
        $outputs = [
            'echo main',
            'echo ' . str_repeat('x', 400),
            'printf "%s" ' . str_repeat('主', 200),
            'printf "e\\314\\201%.0s" 1 2 3 4 5 6 7 8 9 0',
        ];

        for ($cols = 1; $cols <= 200; $cols++) {
            $chat = $this->chat($cols);
            $baseline = Width::of($this->bar($chat));

            foreach ($outputs as $command) {
                self::install($command);
                $width = Width::of($this->bar($chat));

                self::assertLessThanOrEqual(
                    max($baseline, $cols),
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
}
