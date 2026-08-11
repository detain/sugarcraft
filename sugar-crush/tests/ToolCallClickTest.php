<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

/**
 * crush_feat.md section 8 E5 — click a tool call to expand/collapse it.
 *
 * Renderer-side assertions are structural snapshots of the zone registry the
 * root scan produces (the sentinels themselves never survive into the frame,
 * so the registry IS the observable output) plus a byte-level layout check;
 * Chat-side assertions drive `update()` and assert on the returned
 * `[Model, ?Cmd]`.
 *
 * @see Renderer::markToolCalls()
 * @see Chat::toggleToolOutput()
 */
final class ToolCallClickTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    // =========================================================================
    // Renderer: tool-call rows carry click zones
    // =========================================================================

    public function testToolCallRowIsRegisteredAsAToolCallZone(): void
    {
        Renderer::render($this->chatWith([$this->toolMessage('grep', "alpha\nbeta", 'call_1')]));

        $zone = Renderer::scanner()->get('toolcall:call_1');

        self::assertInstanceOf(Zone::class, $zone);
        // Single-row, like every other zone this renderer marks: render()
        // slices the frame to the terminal's height, and a multi-row zone can
        // lose one of its sentinels to that slice and desync the whole scan.
        self::assertSame($zone->startRow, $zone->endRow);
    }

    /**
     * The zone id is the SAME key {@see Chat::expanded()} uses, which is what
     * lets the click reuse §1 E5's expansion map instead of adding a second
     * one. A result with no id is keyed by its tool name.
     */
    public function testAResultWithoutAnIdIsZonedUnderItsToolName(): void
    {
        Renderer::render($this->chatWith([$this->toolMessage('grep', 'alpha', null)]));

        self::assertInstanceOf(Zone::class, Renderer::scanner()->get('toolcall:grep'));
    }

    /**
     * Regression for the layout failure the post-styling marking pass exists
     * to avoid: `Style::render()` measures every line to size the border and
     * pad the short ones, and it counts a sentinel pair as ~29 columns of
     * content. Marking the label before the shell is drawn therefore leaves
     * the tool row's right border far left of every other row's. Marking the
     * finished row instead cannot move anything.
     */
    public function testToolCallZoneMarkingLeavesEveryShellRowTheSameWidth(): void
    {
        $frame = Renderer::render($this->chatWith([
            Message::user('find the bug'),
            $this->toolMessage('grep', "alpha\nbeta", 'call_1'),
        ]));

        // Only the transcript shell — the input box below it is its own,
        // narrower box and would otherwise be compared against it.
        $widths = [];
        $inShell = false;
        foreach (explode("\n", $frame) as $line) {
            $plain = $this->stripSgr($line);
            $inShell = $inShell || str_starts_with($plain, '╭');
            if (!$inShell) {
                continue;
            }
            $widths[] = Width::string($plain);
            if (str_starts_with($plain, '╰')) {
                break;
            }
        }

        self::assertGreaterThan(4, count($widths));
        self::assertCount(1, array_unique($widths), 'every shell row must be the same display width');
    }

    public function testNoToolCallZoneIsMarkedWhenClicksAreDisabled(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        $frame = Renderer::render($this->chatWith([$this->toolMessage('grep', 'alpha', 'call_1')]));

        self::assertStringContainsString('tool: grep', $frame);
        self::assertNull(Renderer::scanner()->get('toolcall:call_1'));
    }

    public function testToolCallZoneMarkersNeverReachTheRenderedFrame(): void
    {
        $frame = Renderer::render($this->chatWith([$this->toolMessage('grep', 'alpha', 'call_1')]));

        self::assertStringNotContainsString(Sentinel::OPEN, $frame);
        self::assertStringNotContainsString(Sentinel::CLOSE, $frame);
        self::assertStringContainsString('tool: grep', $frame);
    }

    /**
     * A tool-call id is whatever the provider (ultimately the model) put in
     * the call, so an id outside Mark's charset would throw from inside
     * `view()` and take the TUI down. The row renders unmarked instead.
     */
    public function testAnIdOutsideTheZoneCharsetIsRenderedUnmarked(): void
    {
        $frame = Renderer::render($this->chatWith([$this->toolMessage('grep', 'alpha', 'call one')]));

        self::assertStringContainsString('tool: grep', $frame);
        foreach (Renderer::scanner()->all() as $zone) {
            self::assertStringStartsNotWith(Renderer::TOOL_CALL_ZONE_PREFIX, $zone->id);
        }
    }

    /**
     * Two id-less results sharing a name collide on one key. Registering it
     * twice would make the scan throw, which clears the registry and costs
     * the frame every OTHER zone too — so the duplicate is dropped, and the
     * status bar's pane zone survives.
     */
    public function testADuplicateToolCallKeyIsRegisteredOnlyOnce(): void
    {
        Renderer::render($this->chatWith([
            $this->toolMessage('grep', 'alpha', null),
            $this->toolMessage('grep', 'beta', null),
        ]));

        $toolZones = array_filter(
            Renderer::scanner()->all(),
            static fn (Zone $zone): bool => str_starts_with($zone->id, Renderer::TOOL_CALL_ZONE_PREFIX),
        );

        self::assertCount(1, $toolZones);
        self::assertInstanceOf(Zone::class, Renderer::scanner()->get('pane:menu'));
    }

    /**
     * Two calls to the same tool render byte-identical label rows, so the row
     * search has to resume past each claim; otherwise both ids would resolve
     * to the first row and clicking the second one would expand the first.
     */
    public function testTwoCallsToTheSameToolClaimTheirOwnRowsInDocumentOrder(): void
    {
        Renderer::render($this->chatWith([
            $this->toolMessage('grep', 'alpha', 'call_1'),
            $this->toolMessage('grep', 'beta', 'call_2'),
        ]));

        $first = Renderer::scanner()->get('toolcall:call_1');
        $second = Renderer::scanner()->get('toolcall:call_2');

        self::assertInstanceOf(Zone::class, $first);
        self::assertInstanceOf(Zone::class, $second);
        self::assertLessThan($second->startRow, $first->startRow);
    }

    // =========================================================================
    // Chat: a completed click on a tool row toggles that call's output
    // =========================================================================

    public function testLeftClickOnAToolRowExpandsItsOutput(): void
    {
        $chat = $this->chatWith([$this->toolMessage('grep', "alpha\nbeta", 'call_1')]);
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('toolcall:call_1');
        self::assertInstanceOf(Zone::class, $zone);

        [$afterPress, $pressCmd] = $chat->update($this->press($zone->startCol, $zone->startRow));
        self::assertNull($pressCmd);
        self::assertFalse($afterPress->isToolOutputExpanded('call_1'), 'press alone must not expand');

        // Released on the press cell, not the row's far edge: sweeping to
        // endCol is a text selection under section 8 E8, not a click.
        [$afterRelease, $releaseCmd] = $afterPress->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($releaseCmd);
        self::assertTrue($afterRelease->isToolOutputExpanded('call_1'));
        self::assertStringContainsString('alpha', Renderer::render($afterRelease));
    }

    public function testClickingAnExpandedToolRowCollapsesItAgain(): void
    {
        $chat = $this->chatWith([$this->toolMessage('grep', "alpha\nbeta", 'call_1')])->toggleToolOutput('call_1');
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('toolcall:call_1');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertFalse($chat->isToolOutputExpanded('call_1'));
        // Collapsing REMOVES the key rather than storing false, so the map
        // stays the size of what is actually open.
        self::assertSame([], $chat->expanded());
    }

    /**
     * Ctrl+O can only name the LAST tool call — Chat has no history cursor to
     * select an earlier one with — so before this step an older call's output
     * was unreachable. A click names the row it landed on.
     */
    public function testClickingAnOlderToolRowExpandsThatCallAndNotTheNewestOne(): void
    {
        $chat = $this->chatWith([
            $this->toolMessage('grep', "alpha\nbeta", 'call_1'),
            $this->toolMessage('bash', "gamma\ndelta", 'call_2'),
        ]);
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('toolcall:call_1');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertTrue($chat->isToolOutputExpanded('call_1'));
        self::assertFalse($chat->isToolOutputExpanded('call_2'));
    }

    public function testPressOnAToolRowAndReleaseElsewhereDoesNotToggle(): void
    {
        // The drag-away / text-selection case: routing through
        // ZoneClickTracker is what keeps this from firing.
        $chat = $this->chatWith([$this->toolMessage('grep', "alpha\nbeta", 'call_1')]);
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('toolcall:call_1');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow + 3));

        self::assertFalse($chat->isToolOutputExpanded('call_1'));
    }

    public function testClickIsIgnoredWhenClicksAreDisabled(): void
    {
        $chat = $this->chatWith([$this->toolMessage('grep', "alpha\nbeta", 'call_1')]);
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('toolcall:call_1');
        self::assertInstanceOf(Zone::class, $zone);

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertFalse($chat->isToolOutputExpanded('call_1'));
    }

    public function testRightButtonOnAToolRowIsIgnored(): void
    {
        $chat = $this->chatWith([$this->toolMessage('grep', "alpha\nbeta", 'call_1')]);
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('toolcall:call_1');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Right, MouseAction::Press));
        [$chat, $cmd] = $chat->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Right, MouseAction::Release));

        self::assertNull($cmd);
        self::assertFalse($chat->isToolOutputExpanded('call_1'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param list<Message> $history
     */
    private function chatWith(array $history): Chat
    {
        return new Chat(history: $history);
    }

    private function toolMessage(string $name, string $body, ?string $id): Message
    {
        return Message::assistant('')->withToolResults([ToolResult::ok($name, $body, $id)]);
    }

    private function press(int $col, int $row): MouseClickMsg
    {
        return new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press);
    }

    private function release(int $col, int $row): MouseReleaseMsg
    {
        return new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release);
    }

    private function stripSgr(string $line): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }
}
