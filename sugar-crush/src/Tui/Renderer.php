<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Tui\Components\ChatPane;
use SugarCraft\Crush\Tui\Components\InputPane;
use SugarCraft\Crush\Tui\Components\SkillsPane;
use SugarCraft\Crush\Tui\Components\AgentsPane;
use SugarCraft\Crush\Tui\Components\FilesPane;
use SugarCraft\Crush\Tui\Components\ToolsPane;
use SugarCraft\Crush\Tui\Components\MenuBar;

/**
 * Stateless renderer for the sugar-crush TUI.
 * Composes multiple panes into a full terminal interface.
 * Pure function - given the same App it always produces the same bytes.
 */
final class Renderer
{
    private static ?array $terminalSize = null;

    public static function setSize(int $cols, int $rows): void
    {
        if ($cols > 0 && $rows > 0) {
            self::$terminalSize = ['rows' => $rows, 'cols' => $cols];
        }
    }

    public static function getTerminalSize(): array
    {
        if (self::$terminalSize !== null) {
            return self::$terminalSize;
        }

        try {
            $size = (new Tty(STDOUT))->size();
            if ($size['cols'] > 0 && $size['rows'] > 0) {
                self::$terminalSize = ['rows' => $size['rows'], 'cols' => $size['cols']];
                return self::$terminalSize;
            }
        } catch (\Throwable) {}

        self::$terminalSize = ['rows' => 60, 'cols' => 200];
        return self::$terminalSize;
    }

    public static function resetSizeCache(): void
    {
        self::$terminalSize = null;
    }

    public static function render(App $a): string
    {
        $size = self::getTerminalSize();
        $cols = $size['cols'];
        $rows = $size['rows'];

        // Build panes based on focused pane
        $menuBar = MenuBar::render($a);
        $chatPane = ChatPane::render($a, $cols, $rows);
        $inputPane = InputPane::render($a, $cols);
        $statusBar = self::statusBar($a);

        // Side panes
        $leftPane = self::leftSidebar($a, $cols, $rows);
        $rightPane = self::rightSidebar($a, $cols, $rows);

        // Compose: top bar + left + chat + right + input + status
        $top = $menuBar;
        $middle = Layout::joinHorizontal(Position::TOP, $leftPane, $chatPane, $rightPane);
        $bottom = $inputPane . "\n" . $statusBar;

        return $top . "\n" . $middle . "\n" . $bottom;
    }

    private static function leftSidebar(App $a, int $cols, int $rows): string
    {
        $width = (int) floor($cols / 4);
        $width = max(20, $width);

        if ($a->pane === Pane::Files) {
            return FilesPane::render($a, $width, $rows);
        }

        if ($a->pane === Pane::Tools) {
            return ToolsPane::render($a, $width, $rows);
        }

        return FilesPane::render($a, $width, $rows);
    }

    private static function rightSidebar(App $a, int $cols, int $rows): string
    {
        $width = (int) floor($cols / 4);
        $width = max(20, $width);

        if ($a->pane === Pane::Skills) {
            return SkillsPane::render($a, $width, $rows);
        }

        if ($a->pane === Pane::Agents) {
            return AgentsPane::render($a, $width, $rows);
        }

        return '';
    }

    private static function statusBar(App $a): string
    {
        $provider = Style::new()->foreground(Color::hex('#9ece6a'))->render($a->provider->name());
        $model = Style::new()->foreground(Color::hex('#e0af68'))->render($a->model);
        $pane = $a->pane->label();

        $status = $a->error
            ? Style::new()->foreground(Color::hex('#f7768e'))->bold()->render('error: ' . $a->error)
            : ($a->status
                ? Style::new()->foreground(Color::hex('#9ece6a'))->render($a->status)
                : '');

        return " $provider | $model | [Tab] Switch Pane | $status";
    }
}
