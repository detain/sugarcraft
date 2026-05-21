<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Syntax highlighting for text input.
 *
 * Currently a no-op stub. Full implementation will consume sugar-glow's
 * highlighter (step 10.24) to tokenize and style source code input.
 *
 * @see https://github.com/sugarcraft/sugar-glow
 * @see step 10.24
 */
final readonly class Highlight
{
    /**
     * Highlight a text string, returning styled spans.
     *
     * Currently returns a single unstyled span covering the entire text.
     * When sugar-glow is integrated (step 10.24), this will tokenize and
     * apply syntax styling.
     *
     * @return list<array{text: string, style: string}> List of text+style spans.
     */
    public function highlight(string $text): array
    {
        return [['text' => $text, 'style' => '']];
    }
}
