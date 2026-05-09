<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Assert;

use SugarCraft\Vt\Terminal\Terminal;

/**
 * Cell-grid screen-equality assertion.
 *
 * Feeds the expected and actual byte streams into separate
 * {@see Terminal} instances and compares their final cell grids via
 * {@see \SugarCraft\Vt\Screen\Screen::diff()}. Cell-grid equality is
 * semantically stronger than byte equality — it tolerates ANSI-level
 * reordering (redundant SGR re-emission, equivalent cursor-position
 * sequences, partial vs full repaints) that doesn't change what the
 * user actually sees on screen.
 *
 * Requires `sugarcraft/candy-vt`. If candy-vt isn't installed, fall
 * back to {@see ByteAssertion}.
 *
 * Mirrors charmbracelet/x/vcr Assert/Screen.
 */
final class ScreenAssertion implements Assertion
{
    public function __construct(
        public readonly int $cols = 80,
        public readonly int $rows = 24,
    ) {
        if (!class_exists(Terminal::class)) {
            throw new \RuntimeException(
                'ScreenAssertion requires sugarcraft/candy-vt — install it or use ByteAssertion.',
            );
        }
        if ($cols <= 0 || $rows <= 0) {
            throw new \InvalidArgumentException("ScreenAssertion dimensions must be positive, got {$cols}x{$rows}");
        }
    }

    public function compare(string $expected, string $actual): array
    {
        $expectedTerminal = Terminal::create($this->cols, $this->rows);
        $actualTerminal = Terminal::create($this->cols, $this->rows);

        $expectedTerminal->feed($expected);
        $expectedTerminal->flush();
        $actualTerminal->feed($actual);
        $actualTerminal->flush();

        $expectedScreen = $expectedTerminal->screen();
        $actualScreen = $actualTerminal->screen();

        $changes = $expectedScreen->diff($actualScreen);
        if ($changes === []) {
            return ['ok' => true, 'diff' => ''];
        }

        return ['ok' => false, 'diff' => $this->summarize($changes)];
    }

    /**
     * Compact human-readable diff: count + first few cell coordinates
     * with their expected vs actual graphemes.
     *
     * @param array<array{row:int,col:int,prev:\SugarCraft\Vt\Cell\Cell,next:\SugarCraft\Vt\Cell\Cell}> $changes
     */
    private function summarize(array $changes): string
    {
        $count = count($changes);
        $shown = array_slice($changes, 0, 5);
        $lines = [];
        foreach ($shown as $c) {
            $expected = $this->graphemeOrPlaceholder($c['prev']->grapheme);
            $actual = $this->graphemeOrPlaceholder($c['next']->grapheme);
            $lines[] = sprintf('  (%d,%d) expected %s actual %s', $c['row'], $c['col'], $expected, $actual);
        }
        $more = $count > 5 ? sprintf("\n  … and %d more", $count - 5) : '';
        return sprintf("cell-grid mismatch: %d cell(s) differ\n%s%s", $count, implode("\n", $lines), $more);
    }

    private function graphemeOrPlaceholder(string $g): string
    {
        if ($g === '') {
            return '<empty>';
        }
        if ($g === ' ') {
            return '<space>';
        }
        return "'{$g}'";
    }
}
