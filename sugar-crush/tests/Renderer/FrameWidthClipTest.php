<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Mosaic\Mosaic;

/**
 * Every row of a standalone frame fits the terminal, status bar and overlays
 * included (audit 15b-09).
 *
 * Two defects made narrow frames over-wide. The status bar appended its
 * 49-cell processing hint unconditionally, so the idle bar was 54 cells on
 * every terminal narrower than that, and 36 in flight. And `renderView()`
 * floored the pane width at 20, so the shell was 26 cells wide on any
 * terminal under 26 columns, and every overlay composited onto that backdrop
 * was 26 wide too. Over-wide rows are not cosmetic on this path: candy-core
 * repaints with an absolute `cursorTo()`, so a row the terminal soft-wraps
 * shifts every row below it.
 *
 * Measured with `Width::string()` on `Renderer::render()`, which returns the
 * frame after `scanRoot()` has stripped the zone sentinels, so this is the
 * width the terminal paints.
 */
final class FrameWidthClipTest extends TestCase
{
    use HomeSandboxTrait;

    /** The widths 15b-09 measured, plus the shell's own boundary (26) and a few below it. */
    private const COLS = [1, 4, 6, 7, 8, 12, 16, 20, 25, 26, 30, 40];

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/frame_width_home_' . uniqid('', true),
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
     * A transcript with one row of each kind the renderer builds differently:
     * user and system text, markdown prose, a fence and a table, a reasoning
     * row, a finished and a failed tool call, and a diff.
     *
     * @return list<Message>
     */
    private static function history(): array
    {
        return [
            Message::user('please look at 漢字テスト and the long path /var/lib/some/very/long/directory/name'),
            Message::system('system notice: ' . str_repeat('word ', 12)),
            Message::assistant(
                "Here is **prose** that wraps.\n\n```\n" . str_repeat('全角コード', 6) . "\n```\n\n"
                . "| name | description |\n|---|---|\n| a | " . str_repeat('long ', 8) . "|\n",
                reasoning: 'thinking about it ' . str_repeat('carefully ', 6),
            ),
            Message::assistant('done')->withToolResults([
                ToolResult::ok('Bash', str_repeat('output line ', 10), 'call_ok'),
                ToolResult::error('Bash', 'failed: ' . str_repeat('x', 60), 'call_err'),
                new ToolResult(
                    name: 'Edit',
                    result: 'edited',
                    id: 'call_diff',
                    diff: "--- a/a.php\n+++ b/a.php\n@@ -1 +1 @@\n-" . str_repeat('old ', 10) . "\n+" . str_repeat('new ', 10) . "\n",
                ),
            ]),
        ];
    }

    private static function chat(int $cols, int $rows, string $draft = ''): Chat
    {
        return (new Chat(history: self::history(), inputBuf: $draft, backend: new EchoBackend()))
            ->withSize($cols, $rows);
    }

    /**
     * The app states the status bar and the shell are measured in. The bar's
     * width depends on the state (the idle hint is 49 cells, the in-flight
     * hint 31), so a size sweep over one state would not cover the other.
     *
     * No permission prompt here: constructing a `PermissionRequestMsg` in a
     * new file widens the domain `KeyHelpTest`'s guard-mutation census pins.
     * The prompt's bar cue is swept to the terminal width there instead
     * (`testTheCuesFitTheTerminalInEveryAppState()`).
     *
     * @return array<string, callable(int, int): Chat>
     */
    private static function states(): array
    {
        return [
            'idle' => static fn(int $cols, int $rows): Chat => self::chat($cols, $rows),
            'empty history' => static fn(int $cols, int $rows): Chat
                => (new Chat(backend: new EchoBackend()))->withSize($cols, $rows),
            'in flight, streaming' => static fn(int $cols, int $rows): Chat => (new Chat(
                history: self::history(),
                inFlight: true,
                streamingText: 'partial ' . str_repeat('streamed words ', 8) . "\n\n```\n" . str_repeat('漢', 20),
                backend: new EchoBackend(),
            ))->withSize($cols, $rows),
            'turn submitted' => static function (int $cols, int $rows): Chat {
                [$flight] = self::chat($cols, $rows, 'hello')->update(new KeyMsg(KeyType::Enter));

                return $flight;
            },
            'long draft' => static fn(int $cols, int $rows): Chat
                => self::chat($cols, $rows, str_repeat('word ', 30) . str_repeat('文', 20)),
            'slash popup' => static fn(int $cols, int $rows): Chat => self::chat($cols, $rows, '/'),
            'palette' => static function (int $cols, int $rows): Chat {
                [$open] = self::chat($cols, $rows)->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

                return $open;
            },
            'key reference' => static fn(int $cols, int $rows): Chat => self::chat($cols, $rows)->withKeyHelp(0),
            'prompt history' => static function (int $cols, int $rows): Chat {
                [$open] = self::chat($cols, $rows)->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

                return $open;
            },
        ];
    }

    /** @return iterable<string, array{0: string, 1: int, 2: int}> */
    public static function stateAndSizeProvider(): iterable
    {
        foreach (array_keys(self::states()) as $state) {
            foreach (self::COLS as $cols) {
                foreach ([3, 12, 24] as $rows) {
                    yield "{$state} at {$cols}x{$rows}" => [$state, $cols, $rows];
                }
            }
        }
    }

    /**
     * @dataProvider stateAndSizeProvider
     */
    public function testEveryFrameRowFitsTheTerminal(string $state, int $cols, int $rows): void
    {
        $frame = Renderer::render(self::states()[$state]($cols, $rows));

        foreach (explode("\n", $frame) as $index => $row) {
            self::assertLessThanOrEqual(
                $cols,
                Width::string($row),
                "{$state} at {$cols}x{$rows}: row {$index} is wider than the terminal: "
                . json_encode(preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $row)),
            );
        }
    }

    /**
     * The status bar is shortened in priority order rather than cut, so the
     * pieces that matter survive on a narrow terminal: the `Ctrl+P menu`
     * hint (the palette lists everything else) and the cancel key in flight.
     * Cutting the 54-cell bar at 30 columns would have kept "Enter to send"
     * and lost both.
     */
    public function testTheBarKeepsItsMostUsefulHintsWhenItIsShortened(): void
    {
        $idle = self::lastRow(Renderer::render(self::chat(30, 12)));
        self::assertStringContainsString('Ctrl+P menu', $idle);
        self::assertStringContainsString('quit', $idle);
        self::assertStringNotContainsString('Enter to send', $idle);

        $narrow = self::lastRow(Renderer::render(self::chat(16, 12)));
        self::assertStringContainsString('Ctrl+P menu', $narrow);

        [$flight] = self::chat(30, 12, 'hello')->update(new KeyMsg(KeyType::Enter));
        self::assertStringContainsString('Esc Esc to cancel', self::lastRow(Renderer::render($flight)));
    }

    /**
     * The `pane:menu` click zone around "Ctrl+P menu" survives a shortened
     * bar: the shorter forms keep the marked label whole, so the frame still
     * registers the zone instead of carrying a half-cut sentinel pair that
     * would make the scan throw and drop every zone in the frame.
     */
    public function testTheMenuZoneSurvivesTheShortenedBar(): void
    {
        foreach ([16, 30, 40] as $cols) {
            $frame = Renderer::render(self::chat($cols, 12));
            self::assertStringContainsString('Ctrl+P menu', self::lastRow($frame), "fixture: the hint is on the bar at {$cols} columns");

            $found = false;
            for ($row = 1; $row <= 12 && !$found; $row++) {
                for ($col = 1; $col <= $cols; $col++) {
                    if (Chat::zoneAt($col, $row)?->id === Renderer::PANE_ZONE_PREFIX . 'menu') {
                        $found = true;
                        break;
                    }
                }
            }
            self::assertTrue($found, "the menu hint lost its click zone at {$cols} columns");
        }
    }

    /**
     * A tool-result picture is sized to the pane, not to a fixed 8-cell
     * minimum, now that the pane can be narrower than 8 cells. The reserved
     * marker block must fit inside the pane, or `fitToPane()` would rewrite
     * the cells the runtime paints the picture over.
     */
    public function testTheImageBoxNeverExceedsThePane(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('candy-mosaic decodes images through ext-gd');
        }

        $gd = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($gd);
        $png = (string) ob_get_clean();

        foreach ([7, 8, 10, 12, 20, 26] as $cols) {
            $chat = new Chat(
                history: [Message::assistant('')->withToolResults([new ToolResult(
                    name: 'Doctor',
                    result: 'swatch',
                    id: 'call_img',
                    imageBytes: $png,
                )])],
                rows: 40,
                cols: $cols,
                expanded: ['call_img' => true],
                mosaic: Mosaic::sixel(),
            );

            $view = Renderer::renderView($chat);
            foreach ($view->images as $placement) {
                self::assertLessThanOrEqual(
                    $cols - Renderer::SHELL_CHROME_COLS,
                    $placement->widthCells,
                    "the reserved image box is wider than the pane at {$cols} columns",
                );
            }
            foreach (explode("\n", $view->body) as $row) {
                self::assertLessThanOrEqual($cols, Width::string($row), "over-wide row at {$cols} columns");
            }
        }
    }

    /**
     * The settled-markdown memo is keyed by width. A resize across the old
     * 20-cell floor must re-wrap rather than serve the wider entry.
     */
    public function testAResizeAcrossTheOldFloorReWrapsTheTranscript(): void
    {
        $chat = self::chat(40, 24);
        Renderer::render($chat);
        foreach ([20, 12, 40, 8] as $cols) {
            foreach (explode("\n", Renderer::render($chat->withSize($cols, 24))) as $row) {
                self::assertLessThanOrEqual($cols, Width::string($row), "stale wrap after a resize to {$cols}");
            }
        }
    }

    private static function lastRow(string $frame): string
    {
        $rows = explode("\n", $frame);

        return (string) end($rows);
    }
}
