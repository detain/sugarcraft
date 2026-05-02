<?php

declare(strict_types=1);

namespace CandyCore\Bits\Help;

use CandyCore\Bits\Key\Binding;
use CandyCore\Bits\Key\KeyMap;
use CandyCore\Core\Util\Width;

/**
 * Renders a {@see KeyMap} as either a short, single-line summary or a
 * multi-column full-help block.
 *
 * ```php
 * $help = new Help();
 * echo $help->shortView($keymap);  // "↑/k up • ↓/j down • q quit"
 * echo $help->fullView($keymap);   // multi-column block with aligned key columns
 * ```
 *
 * The component is immutable — separators are configurable through
 * `with*()` factories that return new instances.
 */
final class Help
{
    public function __construct(
        public readonly string $separator = ' • ',
        public readonly string $keyDescGap = ' ',
        public readonly string $columnGap  = '    ',
    ) {}

    public function withSeparator(string $s): self
    {
        return new self($s, $this->keyDescGap, $this->columnGap);
    }

    /** Render the inline (short) help row from `KeyMap::shortHelp()`. */
    public function shortView(KeyMap $map): string
    {
        $parts = [];
        foreach ($map->shortHelp() as $b) {
            $entry = $this->renderBinding($b);
            if ($entry !== '') {
                $parts[] = $entry;
            }
        }
        return implode($this->separator, $parts);
    }

    /** Render the multi-column full help block from `KeyMap::fullHelp()`. */
    public function fullView(KeyMap $map): string
    {
        $columns = $map->fullHelp();
        if ($columns === []) {
            return '';
        }

        // Build per-column lines; pad to equal height.
        /** @var list<list<string>> $colLines */
        $colLines = [];
        $maxRows = 0;
        foreach ($columns as $col) {
            $lines = [];
            foreach ($col as $b) {
                $entry = $this->renderBinding($b);
                if ($entry !== '') {
                    $lines[] = $entry;
                }
            }
            $colLines[] = $lines;
            $maxRows = max($maxRows, count($lines));
        }

        // Right-pad each column to its max line width.
        foreach ($colLines as $idx => $lines) {
            $width = 0;
            foreach ($lines as $l) {
                $width = max($width, Width::string($l));
            }
            $padded = [];
            for ($i = 0; $i < $maxRows; $i++) {
                $line = $lines[$i] ?? '';
                $w    = Width::string($line);
                $padded[] = $line . str_repeat(' ', max(0, $width - $w));
            }
            $colLines[$idx] = $padded;
        }

        // Stitch columns together row by row.
        $out = [];
        for ($i = 0; $i < $maxRows; $i++) {
            $row = [];
            foreach ($colLines as $col) {
                $row[] = $col[$i];
            }
            $out[] = rtrim(implode($this->columnGap, $row));
        }
        return implode("\n", $out);
    }

    private function renderBinding(Binding $b): string
    {
        if ($b->disabled || $b->help->key === '' && $b->help->desc === '') {
            return '';
        }
        return $b->help->key . $this->keyDescGap . $b->help->desc;
    }
}
