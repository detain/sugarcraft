<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The frame's HEIGHT floor (backlog E48).
 *
 * `renderView()` clips/pads the content to `$available = max(1, $rows - 1)`
 * lines and then appends the status bar UNCONDITIONALLY. The guard at
 * `$available` sees only `$rows`; what it cannot see is that the frame is
 * `$available + 1` rows, so at a `rows=1` terminal — a size
 * {@see Chat::withSize()} accepts and a `WindowSizeMsg` can report — the frame
 * is two rows, one taller than the screen, and the terminal absorbs that by
 * scrolling a single line.
 *
 * That is the DECIDED floor, not an oversight: the entry recorded two options
 * and E48 took "2 is the floor, say so" over "reserve the bar out of
 * $available", because the bar is the only announcement of a blocking
 * permission prompt and of a hidden keybinding reference
 * (`KEY_HELP_TOO_SMALL` / `KEY_HELP_OVER_PROMPT`) — a frame that dropped the
 * bar to save the row would silently eat keystrokes, which is the worse of the
 * two failures. `renderStatusBar()`'s docblock carries the statement; this
 * file is the pin, because prose with nothing reading it back goes stale.
 */
final class FrameHeightFloorTest extends TestCase
{
    use HomeSandboxTrait;

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        // Constructing a Chat walks the skill trees under HOME; sandbox both
        // spellings so no assertion here depends on this machine (the reason
        // is spelled out in HomeSandboxTrait and in PaneWidthInvariantTest).
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/frame_height_home_' . uniqid('', true),
        );
        Renderer::clearZones();
    }

    protected function tearDown(): void
    {
        Renderer::clearZones();
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
    }

    /**
     * One, two, and ten rows: the frame never collapses under the bar plus a
     * content row, and never overshoots `$rows` once there is room for both.
     */
    public function testTheFrameFloorIsTheStatusBarPlusOneContentRow(): void
    {
        $history = [Message::user('hi'), Message::assistant('a reply of prose words here')];

        // rows=1: the degenerate frame the entry measured — 2 logical rows,
        // not 1, and that is the floor E48 documents rather than fixes away.
        $frame = Renderer::render(new Chat(history: $history, rows: 1, cols: 80));
        self::assertCount(
            2,
            explode("\n", $frame),
            'rows=1 must produce the documented 2-row floor frame (one content row + the bar)',
        );

        // rows=2: the smallest terminal the floor was made FOR still gets
        // exactly its size — the floor is a floor, not an offset.
        self::assertCount(
            2,
            explode("\n", Renderer::render(new Chat(history: $history, rows: 2, cols: 80))),
            'rows=2 must produce a 2-row frame',
        );

        // rows=10: past the floor the frame tracks $rows exactly — short
        // conversations are padded to it and long ones clipped to it, so the
        // bar lands on the true last line and the two cases cannot disagree.
        self::assertCount(
            10,
            explode("\n", Renderer::render(new Chat(history: $history, rows: 10, cols: 80))),
            'rows=10 must produce a 10-row frame',
        );
    }
}
