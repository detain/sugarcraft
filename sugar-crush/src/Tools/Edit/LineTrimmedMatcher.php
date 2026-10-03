<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Stage 3: the same LINES, each compared with its leading and trailing
 * whitespace ignored — the shape of an `old_string` whose indentation is off by
 * a different amount on different lines, or that carries trailing spaces the
 * file does not. The match is always whole lines, and `new_string` is placed at
 * the file's indentation through {@see IndentShift}.
 */
final class LineTrimmedMatcher implements MatchStage
{
    public function name(): string
    {
        return EditMatcher::STAGE_LINE_TRIMMED;
    }

    public function find(string $content, string $old, string $new): array
    {
        return LineWindows::find(
            $content,
            $old,
            $new,
            static fn (string $line): string => trim($line),
            'old_string matched with each line\'s leading and trailing whitespace ignored',
        );
    }
}
