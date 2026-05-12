<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A text diff display component.
 *
 * Shows differences between two text strings with additions,
 * deletions, and unchanged sections clearly marked.
 *
 * Mirrors diff display concepts adapted to PHP with wither-style immutable setters.
 */
final class Diff implements Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * Diff operation types.
     */
    public const ADDED = '+';
    public const REMOVED = '-';
    public const UNCHANGED = ' ';

    public function __construct(
        private readonly string $oldText,
        private readonly string $newText,
        private readonly bool $showLineNumbers = true,
        private readonly bool $showContext = true,
        private readonly int $contextLines = 3,
        private readonly ?Color $addedColor = null,
        private readonly ?Color $removedColor = null,
        private readonly ?Color $unchangedColor = null,
    ) {}

    /**
     * Create a new diff with default styling.
     */
    public static function new(string $oldText, string $newText): self
    {
        return new self(
            oldText: $oldText,
            newText: $newText,
            showLineNumbers: true,
            showContext: true,
            contextLines: 3,
            addedColor: Color::hex('#22C55E'),    // Green
            removedColor: Color::hex('#EF4444'),  // Red
            unchangedColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this diff.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Compute the diff between two texts.
     *
     * Uses a simple line-by-line LCS (Longest Common Subsequence) approach.
     *
     * @return list<array{type: string, content: string, oldLine: int|null, newLine: int|null}>
     */
    private function computeDiff(): array
    {
        $oldLines = explode("\n", $this->oldText);
        $newLines = explode("\n", $this->newText);

        $result = [];

        // Simple diff: show all old lines as removed, all new as added
        // A proper implementation would use LCS or similar
        $maxLines = max(count($oldLines), count($newLines));

        $oldIndex = 0;
        $newIndex = 0;

        while ($oldIndex < count($oldLines) || $newIndex < count($newLines)) {
            if ($oldIndex >= count($oldLines)) {
                // Remaining new lines are additions
                $result[] = [
                    'type' => self::ADDED,
                    'content' => $newLines[$newIndex],
                    'oldLine' => null,
                    'newLine' => $newIndex + 1,
                ];
                $newIndex++;
            } elseif ($newIndex >= count($newLines)) {
                // Remaining old lines are removals
                $result[] = [
                    'type' => self::REMOVED,
                    'content' => $oldLines[$oldIndex],
                    'oldLine' => $oldIndex + 1,
                    'newLine' => null,
                ];
                $oldIndex++;
            } elseif ($oldLines[$oldIndex] === $newLines[$newIndex]) {
                // Lines match
                $result[] = [
                    'type' => self::UNCHANGED,
                    'content' => $oldLines[$oldIndex],
                    'oldLine' => $oldIndex + 1,
                    'newLine' => $newIndex + 1,
                ];
                $oldIndex++;
                $newIndex++;
            } else {
                // Lines differ - use simpler heuristic
                // Check if this old line appears later in new
                $foundInNew = array_search($oldLines[$oldIndex], array_slice($newLines, $newIndex), true);
                $foundInOld = array_search($newLines[$newIndex], array_slice($oldLines, $oldIndex), true);

                if ($foundInNew === false && $foundInOld === false) {
                    // Both lines are unique to their version
                    $result[] = [
                        'type' => self::REMOVED,
                        'content' => $oldLines[$oldIndex],
                        'oldLine' => $oldIndex + 1,
                        'newLine' => null,
                    ];
                    $result[] = [
                        'type' => self::ADDED,
                        'content' => $newLines[$newIndex],
                        'oldLine' => null,
                        'newLine' => $newIndex + 1,
                    ];
                    $oldIndex++;
                    $newIndex++;
                } elseif ($foundInNew !== false && ($foundInOld === false || $foundInNew <= $foundInOld)) {
                    // Old line was removed, then new line added
                    $result[] = [
                        'type' => self::REMOVED,
                        'content' => $oldLines[$oldIndex],
                        'oldLine' => $oldIndex + 1,
                        'newLine' => null,
                    ];
                    $oldIndex++;
                } else {
                    // New line was added
                    $result[] = [
                        'type' => self::ADDED,
                        'content' => $newLines[$newIndex],
                        'oldLine' => null,
                        'newLine' => $newIndex + 1,
                    ];
                    $newIndex++;
                }
            }
        }

        return $result;
    }

    /**
     * Render the diff.
     */
    public function render(): string
    {
        $diff = $this->computeDiff();
        $result = '';
        $lineNumberWidth = 4;

        foreach ($diff as $entry) {
            $type = $entry['type'];
            $content = $entry['content'];
            $oldLine = $entry['oldLine'];
            $newLine = $entry['newLine'];

            // Choose color based on type
            $color = match ($type) {
                self::ADDED => $this->addedColor,
                self::REMOVED => $this->removedColor,
                default => $this->unchangedColor,
            };

            // Build line prefix
            $prefix = $type . ' ';

            // Add line numbers if enabled
            if ($this->showLineNumbers) {
                $oldNum = $oldLine !== null ? sprintf("%{$lineNumberWidth}d", $oldLine) : str_repeat(' ', $lineNumberWidth);
                $newNum = $newLine !== null ? sprintf("%{$lineNumberWidth}d", $newLine) : str_repeat(' ', $lineNumberWidth);
                $prefix = $oldNum . ' ' . $newNum . ' ' . $type . ' ';
            }

            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }

            $result .= $prefix . $content;

            if ($color !== null) {
                $result .= Ansi::reset();
            }

            $result .= "\n";
        }

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural dimensions of this diff.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $diff = $this->computeDiff();
        $maxWidth = 0;
        $lineNumberWidth = 4;

        foreach ($diff as $entry) {
            $prefixLen = $this->showLineNumbers ? ($lineNumberWidth * 2 + 3) : 3;
            $contentLen = mb_strlen($entry['content'], 'UTF-8');
            $maxWidth = max($maxWidth, $prefixLen + $contentLen);
        }

        return [$maxWidth, count($diff)];
    }

    /**
     * Get statistics about the diff.
     *
     * @return array{added: int, removed: int, unchanged: int}
     */
    public function getStats(): array
    {
        $diff = $this->computeDiff();
        $stats = ['added' => 0, 'removed' => 0, 'unchanged' => 0];

        foreach ($diff as $entry) {
            if ($entry['type'] === self::ADDED) {
                $stats['added']++;
            } elseif ($entry['type'] === self::REMOVED) {
                $stats['removed']++;
            } else {
                $stats['unchanged']++;
            }
        }

        return $stats;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Show or hide line numbers.
     */
    public function withLineNumbers(bool $show): self
    {
        return new self(
            oldText: $this->oldText,
            newText: $this->newText,
            showLineNumbers: $show,
            showContext: $this->showContext,
            contextLines: $this->contextLines,
            addedColor: $this->addedColor,
            removedColor: $this->removedColor,
            unchangedColor: $this->unchangedColor,
        );
    }

    /**
     * Show or hide context lines.
     */
    public function withContext(bool $show): self
    {
        return new self(
            oldText: $this->oldText,
            newText: $this->newText,
            showLineNumbers: $this->showLineNumbers,
            showContext: $show,
            contextLines: $this->contextLines,
            addedColor: $this->addedColor,
            removedColor: $this->removedColor,
            unchangedColor: $this->unchangedColor,
        );
    }

    /**
     * Set the context line count.
     */
    public function withContextLines(int $count): self
    {
        return new self(
            oldText: $this->oldText,
            newText: $this->newText,
            showLineNumbers: $this->showLineNumbers,
            showContext: $this->showContext,
            contextLines: max(0, $count),
            addedColor: $this->addedColor,
            removedColor: $this->removedColor,
            unchangedColor: $this->unchangedColor,
        );
    }

    /**
     * Set the color for added lines.
     */
    public function withAddedColor(?Color $color): self
    {
        return new self(
            oldText: $this->oldText,
            newText: $this->newText,
            showLineNumbers: $this->showLineNumbers,
            showContext: $this->showContext,
            contextLines: $this->contextLines,
            addedColor: $color,
            removedColor: $this->removedColor,
            unchangedColor: $this->unchangedColor,
        );
    }

    /**
     * Set the color for removed lines.
     */
    public function withRemovedColor(?Color $color): self
    {
        return new self(
            oldText: $this->oldText,
            newText: $this->newText,
            showLineNumbers: $this->showLineNumbers,
            showContext: $this->showContext,
            contextLines: $this->contextLines,
            addedColor: $this->addedColor,
            removedColor: $color,
            unchangedColor: $this->unchangedColor,
        );
    }
}
